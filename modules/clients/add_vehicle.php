<?php
require_once __DIR__ . "/../../config/session.php";
require_once '../../config/db.php';
require_once '../../config/validators.php';

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'super_admin'])) {
    header("Location: ../../auth/login.php");
    exit;
}

$client_id = isset($_GET['client_id']) ? (int)$_GET['client_id'] : 0;
if ($client_id === 0) {
    header("Location: client_list.php");
    exit;
}

// Load client
$stmt = $conn->prepare("SELECT * FROM clients WHERE client_id = ?");
$stmt->bind_param('i', $client_id);
$stmt->execute();
$client = $stmt->get_result()->fetch_assoc();

if (!$client) {
    header("Location: client_list.php?error=Client not found.");
    exit;
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $plate_number  = strtoupper(san_str($_POST['plate_number'] ?? '', MAX_PLATE));
    $make          = san_str($_POST['make'] ?? '', MAX_MAKE_MODEL);
    $model         = san_str($_POST['model'] ?? '', MAX_MAKE_MODEL);
    $year_model    = san_int($_POST['year_model'] ?? 0, 1960, (int)date('Y') + 1);
    $color         = san_str($_POST['color'] ?? '', MAX_COLOR);
    $motor_number  = strtoupper(san_str($_POST['motor_number'] ?? '', MAX_MOTOR_SN));
    $serial_number = strtoupper(san_str($_POST['serial_number'] ?? '', MAX_MOTOR_SN));

    if ($plate_number === '')  $errors[] = 'Plate number is required.';
    elseif (!validate_plate($plate_number)) $errors[] = 'Plate number contains invalid characters.';
    if ($make === '')          $errors[] = 'Vehicle make is required.';
    if ($model === '')         $errors[] = 'Vehicle model is required.';
    if ($year_model === 0)     $errors[] = 'Year model must be a valid year (1960–' . ((int)date('Y') + 1) . ').';
    if ($motor_number === '')  $errors[] = 'Engine number is required.';
    if ($serial_number === '') $errors[] = 'Chassis number is required.';

    if ($plate_number !== '') {
        $check = $conn->prepare("SELECT vehicle_id FROM vehicles WHERE plate_number = ?");
        $check->bind_param('s', $plate_number);
        $check->execute();
        if ($check->get_result()->num_rows > 0)
            $errors[] = 'Plate number ' . $plate_number . ' already exists in the system.';
    }

    if (empty($errors)) {
        $ins = $conn->prepare("INSERT INTO vehicles (client_id, plate_number, make, model, year_model, color, motor_number, serial_number) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $ins->bind_param('isssssss', $client_id, $plate_number, $make, $model, $year_model, $color, $motor_number, $serial_number);
        if ($ins->execute()) {
            // Audit log
            $uid = $_SESSION['user_id'];
            $log = $conn->prepare("INSERT INTO audit_logs (user_id, action, description) VALUES (?, 'VEHICLE_ADDED', ?)");
            $desc = ($_SESSION['full_name'] ?? 'Unknown') . ' added vehicle ' . ($plate_number ?: 'no plate') . ' (' . $make . ' ' . $model . ') to client ID ' . $client_id . '.';
            $log->bind_param('is', $uid, $desc);
            $log->execute();

            header("Location: view_client.php?id=" . $client_id . "&success=Vehicle added successfully.");
            exit;
        } else {
            $errors[] = 'Database error. Please try again.';
        }
    }
}

$page_title  = 'Add Vehicle';
$active_page = 'clients';
$base_path   = '../../';
require_once '../../includes/header.php';
require_once '../../includes/navbar.php';
?>

<div class="main">

<?php
$topbar_title      = 'Add Vehicle';
$topbar_breadcrumb = ['Records', 'Clients', 'Add Vehicle'];
require_once '../../includes/topbar.php';
?>

  <div class="content">

    <a href="view_client.php?id=<?= $client_id ?>" class="back-link"><?= icon('arrow-left', 14) ?> Back to Client Profile</a>


    <?php if (!empty($errors)): ?>
    <div class="alert alert-danger">
      <div>
        <div style="font-weight:700;margin-bottom:0.35rem;">Please fix the following:</div>
        <?php foreach ($errors as $e): ?>
        <div style="font-size:0.78rem;">&#8226; <?= htmlspecialchars($e) ?></div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

    <!-- CLIENT SUMMARY -->
    <div class="card" style="margin-bottom:1.25rem;">
      <div class="card-header">
        <div class="card-icon"><?= icon('user', 16) ?></div>
        <div>
          <div class="card-title"><?= htmlspecialchars($client['full_name']) ?></div>
          <div class="card-sub"> <?= htmlspecialchars($client['contact_number']) ?> &nbsp; <?= htmlspecialchars($client['address']) ?></div>
        </div>
      </div>
    </div>

    <!-- OCR MODAL -->
    <div id="ocr-modal" style="display:none;position:fixed;inset:0;z-index:1000;background:rgba(0,0,0,0.55);backdrop-filter:blur(2px);align-items:center;justify-content:center;padding:1rem;" onclick="if(event.target===this)ocrModalClose()">
      <div style="background:var(--bg-2);border:1px solid var(--border);border-radius:16px;width:100%;max-width:480px;box-shadow:var(--shadow-lg);animation:ocr-modal-in 0.18s ease;">
        <div style="display:flex;align-items:center;justify-content:space-between;padding:1rem 1.25rem;border-bottom:1px solid var(--border);">
          <div style="display:flex;align-items:center;gap:0.6rem;">
            <span style="color:var(--gold-bright);"><?= icon('magnifying-glass', 16) ?></span>
            <span style="font-weight:700;font-size:0.9rem;color:var(--text-primary);">Scan OR / CR</span>
            <span style="font-size:0.65rem;font-weight:700;color:var(--gold-bright);background:var(--gold-pale);border:1px solid var(--gold-bright);border-radius:6px;padding:0.1rem 0.4rem;">OCR</span>
          </div>
          <button type="button" onclick="ocrModalClose()" style="background:none;border:none;cursor:pointer;color:var(--text-muted);padding:0.25rem;"><?= icon('x-mark', 16) ?></button>
        </div>
        <div style="padding:1.25rem;">
          <div id="ocr-upload-area" style="border:2px dashed var(--border);border-radius:12px;padding:1.5rem;text-align:center;cursor:pointer;transition:border-color 0.15s;" onclick="document.getElementById('ocr-file-input').click()">
            <div id="ocr-idle">
              <div style="color:var(--text-muted);margin-bottom:0.4rem;"><?= icon('camera', 24) ?></div>
              <div style="font-size:0.85rem;font-weight:700;color:var(--text-primary);margin-bottom:0.2rem;">Tap to take a photo or upload</div>
              <div style="font-size:0.72rem;color:var(--text-muted);">JPG, PNG, WEBP, PDF · Works best on flat, clear documents</div>
            </div>
            <div id="ocr-preview" style="display:none;">
              <img id="ocr-img" style="max-width:100%;max-height:200px;border-radius:8px;object-fit:contain;" alt="OR/CR"/>
            </div>
          </div>
          <input type="file" id="ocr-file-input" accept="image/*,application/pdf" style="display:none;"/>

          <div id="ocr-progress" style="display:none;margin-top:1rem;">
            <div style="display:flex;align-items:center;gap:0.75rem;margin-bottom:0.5rem;">
              <div style="width:16px;height:16px;border:2px solid var(--border);border-top-color:var(--gold-bright);border-radius:50%;animation:spin 0.7s linear infinite;flex-shrink:0;"></div>
              <span id="ocr-status-text" style="font-size:0.78rem;color:var(--text-muted);">Enhancing image…</span>
            </div>
            <div style="height:4px;background:var(--border);border-radius:4px;overflow:hidden;">
              <div id="ocr-bar" style="height:100%;background:var(--gold-bright);width:0%;transition:width 0.3s;border-radius:4px;"></div>
            </div>
          </div>

          <div id="ocr-result-notice" style="display:none;margin-top:0.75rem;" class="alert alert-info">
            <?= icon('check-circle', 13) ?> <span id="ocr-filled-fields">Fields auto-filled.</span> Review before saving.
          </div>
          <div id="ocr-error-notice" style="display:none;margin-top:0.75rem;" class="alert alert-warning">
            <?= icon('exclamation-triangle', 13) ?> <span id="ocr-error-msg">Could not extract text. Fill in manually.</span>
          </div>

          <div style="display:flex;gap:0.5rem;margin-top:0.75rem;flex-wrap:wrap;">
            <button type="button" onclick="document.getElementById('ocr-file-input').click()" class="btn-primary" style="font-size:0.78rem;padding:0.4rem 0.9rem;">
              <?= icon('camera', 12) ?> Retake / Choose
            </button>
            <button type="button" id="ocr-clear-btn" onclick="ocrClear()" class="btn-ghost" style="font-size:0.78rem;padding:0.4rem 0.9rem;display:none;">
              <?= icon('x-mark', 12) ?> Clear
            </button>
            <button type="button" onclick="ocrModalClose()" class="btn-ghost" style="font-size:0.78rem;padding:0.4rem 0.9rem;margin-left:auto;">
              Done
            </button>
          </div>
        </div>
      </div>
    </div>
    <style>
    @keyframes spin { to { transform: rotate(360deg); } }
    @keyframes ocr-modal-in { from { opacity:0; transform:translateY(12px); } to { opacity:1; transform:translateY(0); } }
    #ocr-upload-area:hover { border-color: var(--gold-bright); }
    .ocr-filled { background: rgba(212,160,23,0.08) !important; border-color: var(--gold-bright) !important; }
    </style>

    <form method="POST" action="">
      <?= csrf_field() ?>
      <div class="card">
        <div class="card-header">
          <div class="card-icon"><?= icon('vehicle', 16) ?></div>
          <div>
            <div class="card-title">Vehicle Details</div>
            <div class="card-sub">Fields marked <span style="color:var(--gold-bright);">*</span> are required</div>
          </div>
          <button type="button" onclick="ocrModalOpen()" class="btn-ghost" style="margin-left:auto;font-size:0.78rem;padding:0.45rem 1rem;">
            <?= icon('camera', 13) ?> Scan Document
          </button>
        </div>
        <div style="padding:1.5rem;">
          <div class="form-grid-3">

            <!-- Row 1: Plate | Make | Model -->
            <div class="field">
              <label class="field-label">Plate Number <span class="req">*</span></label>
              <input type="text" name="plate_number" class="field-input"
                placeholder="ABC 1234"
                value="<?= htmlspecialchars($_POST['plate_number'] ?? '') ?>"
                style="text-transform:uppercase;" autofocus/>
            </div>
            <div class="field">
              <label class="field-label">Make <span class="req">*</span></label>
              <input type="text" name="make" class="field-input"
                placeholder="Toyota / Honda / Mitsubishi"
                value="<?= htmlspecialchars($_POST['make'] ?? '') ?>"/>
            </div>
            <div class="field">
              <label class="field-label">Model <span class="req">*</span></label>
              <input type="text" name="model" class="field-input"
                placeholder="Innova / Civic / L300"
                value="<?= htmlspecialchars($_POST['model'] ?? '') ?>"/>
            </div>

            <!-- Row 2: Year | Color (2-col) -->
            <div class="field">
              <label class="field-label">Year Model <span class="req">*</span></label>
              <input type="number" name="year_model" class="field-input"
                min="1990" max="<?= date('Y') + 1 ?>"
                placeholder="YYYY"
                value="<?= htmlspecialchars($_POST['year_model'] ?? '') ?>"/>
            </div>
            <div class="field span-2">
              <label class="field-label">Color</label>
              <input type="text" name="color" class="field-input"
                placeholder="Pearl White / Black / Silver"
                value="<?= htmlspecialchars($_POST['color'] ?? '') ?>"/>
            </div>

            <!-- Row 3: Engine Number (full width) -->
            <div class="field span-3">
              <label class="field-label">Engine Number <span class="req">*</span></label>
              <input type="text" name="motor_number" class="field-input"
                placeholder="Alphanumeric, from OR-CR"
                value="<?= htmlspecialchars($_POST['motor_number'] ?? '') ?>"
                style="text-transform:uppercase;"/>
              <div class="field-hint">Found on the vehicle registration / OR-CR. Required for insurance eligibility.</div>
            </div>

            <!-- Row 4: Chassis Number (full width) -->
            <div class="field span-3">
              <label class="field-label">Chassis Number <span class="req">*</span></label>
              <input type="text" name="serial_number" class="field-input"
                placeholder="17-character VIN"
                value="<?= htmlspecialchars($_POST['serial_number'] ?? '') ?>"
                style="text-transform:uppercase;"/>
              <div class="field-hint">17-character VIN / chassis number from the OR-CR. Required for policy creation.</div>
            </div>

          </div>
        </div>
        <div class="form-actions">
          <a href="view_client.php?id=<?= $client_id ?>" class="btn-ghost"><?= icon('arrow-left', 14) ?> Cancel</a>
          <button type="submit" class="btn-primary"><?= icon('floppy-disk', 14) ?> Save Vehicle</button>
        </div>
      </div>
    </form>

  </div>
</div>

<script>
(function() {
  const fileInput  = document.getElementById("ocr-file-input");
  const idleEl     = document.getElementById("ocr-idle");
  const previewEl  = document.getElementById("ocr-preview");
  const imgEl      = document.getElementById("ocr-img");
  const progressEl = document.getElementById("ocr-progress");
  const statusEl   = document.getElementById("ocr-status-text");
  const barEl      = document.getElementById("ocr-bar");
  const resultEl   = document.getElementById("ocr-result-notice");
  const errorEl    = document.getElementById("ocr-error-notice");
  const clearBtn   = document.getElementById("ocr-clear-btn");
  const filledEl   = document.getElementById("ocr-filled-fields");

  fileInput.addEventListener("change", function() {
    if (!this.files || !this.files[0]) return;
    const file = this.files[0];
    imgEl.src = URL.createObjectURL(file);
    idleEl.style.display    = "none";
    previewEl.style.display = "block";
    clearBtn.style.display  = "inline-flex";
    resultEl.style.display  = "none";
    errorEl.style.display   = "none";
    runOCR(file);
  });

  window.ocrModalOpen = function() {
    const modal = document.getElementById("ocr-modal");
    modal.style.display = "flex";
    document.body.style.overflow = "hidden";
  };
  window.ocrModalClose = function() {
    const modal = document.getElementById("ocr-modal");
    modal.style.display = "none";
    document.body.style.overflow = "";
  };

  window.ocrClear = function() {
    fileInput.value          = "";
    imgEl.src                = "";
    idleEl.style.display     = "block";
    previewEl.style.display  = "none";
    clearBtn.style.display   = "none";
    progressEl.style.display = "none";
    resultEl.style.display   = "none";
    errorEl.style.display    = "none";
    document.querySelectorAll(".ocr-filled").forEach(el => el.classList.remove("ocr-filled"));
  };

  function fillField(selector, value) {
    if (!value) return false;
    const el = document.querySelector(selector);
    if (!el || el.value) return false;
    el.value = value.trim();
    el.classList.add("ocr-filled");
    el.dispatchEvent(new Event("input", { bubbles: true }));
    return true;
  }

  // Grab raw text after a label, tolerating spaces/newlines between label and value
  function afterLabel(upper, labelRe) {
    const m = upper.match(new RegExp(labelRe + "[.:\\s]*([A-Z0-9][A-Z0-9 \\-]{0,35})", "s"));
    return m ? m[1].trim().replace(/\s+/g, " ") : null;
  }

  // Strip spaces then truncate — cleans up OCR spacing artefacts inside numbers
  function compact(str, max) {
    return str.trim().replace(/\s+/g, "").slice(0, max);
  }

  function parseText(text) {
    const filled = [];
    const upper  = text.toUpperCase();

    // ── Plate + Engine from CR values line ──
    // CR OCR: headers and values on separate tab-delimited lines.
    // Values line pattern: "0301-xxx\t945RJC\tJA46E7357915\tMH1JA4672MK358119"
    // Token 0: MV FILE NO (digits+dashes), Token 1: PLATE, Token 2: ENGINE, Token 3: CHASSIS
    let plateVal = null, engineValFromLine = null;
    // Find the line containing the MV file number pattern
    const lines = upper.split("\n");
    for (let i = 0; i < lines.length; i++) {
      const tokens = lines[i].trim().split(/\t+/);
      // MV FILE NO value looks like "0301-00001392305"
      if (tokens[0] && /^\d{4}-\d+$/.test(tokens[0].trim())) {
        // Token 1 = PLATE
        if (tokens[1]) {
          const raw = tokens[1].trim().replace(/\s+/g, "");
          const carM  = raw.match(/^([A-Z]{2,3})(\d{3,4})$/);
          const motoM = raw.match(/^(\d{3})([A-Z]{2,3})$/);
          if (carM)       plateVal = carM[1] + " " + carM[2];
          else if (motoM) plateVal = motoM[1] + " " + motoM[2];
          else if (/^[A-Z0-9]{5,8}$/.test(raw)) plateVal = raw;
        }
        // Token 2 = ENGINE (between plate and chassis)
        if (tokens[2]) {
          const eng = tokens[2].trim().replace(/\s+/g, "");
          if (/^[A-Z0-9]{6,20}$/.test(eng)) engineValFromLine = eng;
        }
        break;
      }
    }
    // Fallback: label-anchored PLATE NO
    if (!plateVal) {
      const plateLabelM = upper.match(/PLATE\s*NO[.:\s\t]+([A-Z0-9]{5,8})/);
      if (plateLabelM) {
        const raw = plateLabelM[1].trim();
        const carM  = raw.match(/^([A-Z]{2,3})(\d{3,4})$/);
        const motoM = raw.match(/^(\d{3})([A-Z]{2,3})$/);
        if (carM)       plateVal = carM[1] + " " + carM[2];
        else if (motoM) plateVal = motoM[1] + " " + motoM[2];
        else plateVal = raw;
      }
    }
    if (plateVal && fillField("[name=plate_number]", plateVal)) filled.push("Plate Number");

    // ── Make ──
    // On CR the MAKE cell value ("Honda") appears AFTER the label on the next line.
    // OCR table reading often outputs cells left-to-right, so "Honda" may appear
    // far from the "MAKE" label. Scan whole text for known brands — most reliable approach.
    const makes = ["TOYOTA","HONDA","MITSUBISHI","FORD","NISSAN","HYUNDAI","KIA","SUZUKI","ISUZU","MAZDA","CHEVROLET","SUBARU","BMW","MERCEDES","VOLKSWAGEN","JEEP","LEXUS","DODGE","YAMAHA","KAWASAKI","DUCATI","BAJAJ","TVS","KYMCO"];
    for (const mk of makes) {
      if (upper.includes(mk)) {
        if (fillField("[name=make]", mk[0] + mk.slice(1).toLowerCase())) { filled.push("Make"); break; }
      }
    }

    // ── Model (Series on CR) ──
    // CR layout: "Honda  ACB125CBFM  MOTORCYCLE  2021" — series is token after make
    const seriesM = upper.match(/SERIES[.:\s\t]+([A-Z0-9]{3,20})/);
    if (seriesM) {
      if (fillField("[name=model]", seriesM[1])) filled.push("Model");
    } else {
      // Fallback: value after make on same line
      for (const mk of makes) {
        const mkLineM = upper.match(new RegExp(mk + "\\s*\t+([A-Z0-9]{3,20})"));
        if (mkLineM) {
          if (fillField("[name=model]", mkLineM[1])) filled.push("Model");
          break;
        }
      }
    }

    // ── Year Model ──
    // "YEAR MODEL" label + value, or any 4-digit year 1960-2035
    const yearArea = afterLabel(upper, "YEAR\\s*MODEL");
    let yearVal = yearArea ? yearArea.match(/\b(19\d{2}|20[0-3]\d)\b/) : null;
    if (!yearVal) yearVal = upper.match(/\b(19[6-9]\d|20[0-3]\d)\b/);
    if (yearVal && fillField("[name=year_model]", yearVal[1])) filled.push("Year Model");

    // ── Color ──
    // CR doesn't have a dedicated COLOR cell — skip guessing from body text
    const colors = ["WHITE","BLACK","SILVER","GRAY","GREY","RED","BLUE","GREEN","YELLOW","ORANGE","BROWN","MAROON","GOLD","BEIGE","PEARL"];
    const colorArea = afterLabel(upper, "(?:BODY\\s*)?COLOR");
    if (colorArea) {
      for (const col of colors) {
        if (colorArea.toUpperCase().includes(col)) {
          if (fillField("[name=color]", col[0] + col.slice(1).toLowerCase())) { filled.push("Color"); break; }
        }
      }
    }

    // ── Engine Number ──
    // CR OCR often drops the ENGINE NO label — value sits between PLATE NO and CHASSIS NO
    // Use positional value extracted above, fallback to label-anchored
    let engFilled = false;
    if (engineValFromLine && fillField("[name=motor_number]", engineValFromLine)) {
      filled.push("Engine Number"); engFilled = true;
    }
    if (!engFilled) {
      const engM = upper.match(/ENGINE\s*NO[.:\s\t]+([A-Z0-9][A-Z0-9 ]{5,25})/);
      if (engM) {
        const engVal = compact(engM[1].split(/[^A-Z0-9 ]/)[0], 20);
        if (engVal.length >= 6 && fillField("[name=motor_number]", engVal)) filled.push("Engine Number");
      }
    }

    // ── Chassis Number ──
    // Label: "CHASSIS NO." — value e.g. "MH1JA4672MK358119" (17 chars)
    const chassisM = upper.match(/CHASSIS\s*NO[.:\s]*([A-Z0-9][A-Z0-9 ]{9,25})/s);
    if (chassisM) {
      const raw       = chassisM[1].split(/[^A-Z0-9 ]/)[0];
      const chassisVal = compact(raw, 17);
      if (chassisVal.length >= 10 && fillField("[name=serial_number]", chassisVal)) filled.push("Chassis Number");
    }
    // Fallback: standalone 17-char VIN block (excludes I, O, Q per ISO 3779)
    if (!filled.includes("Chassis Number")) {
      const vin = upper.match(/\b([A-HJ-NPR-Z0-9]{17})\b/);
      if (vin && fillField("[name=serial_number]", vin[1])) filled.push("Chassis Number");
    }

    return filled;
  }

  async function runOCR(file) {
    progressEl.style.display = "block";
    barEl.style.width = "20%";
    statusEl.textContent = "Uploading image…";
    try {
      const formData = new FormData();
      formData.append("image", file);

      barEl.style.width = "50%";
      statusEl.textContent = "Reading document…";
      const res = await fetch("ocr_scan.php", { method: "POST", body: formData });
      const data = await res.json();

      barEl.style.width = "100%";
      progressEl.style.display = "none";

      if (data.error) {
        document.getElementById("ocr-error-msg").textContent = data.error;
        errorEl.style.display = "block";
        return;
      }

      const filled = parseText(data.text);
      if (filled.length > 0) {
        filledEl.textContent = "Auto-filled: " + filled.join(", ") + ". Please verify before saving.";
        resultEl.style.display = "block";
      } else {
        document.getElementById("ocr-error-msg").textContent = "Text was read but no matching fields found. Please fill in manually.";
        errorEl.style.display = "block";
      }
    } catch(err) {
      progressEl.style.display = "none";
      document.getElementById("ocr-error-msg").textContent = "OCR failed: " + err.message;
      errorEl.style.display = "block";
    }
  }
})();
</script>

<?php require_once '../../includes/footer.php'; ?>