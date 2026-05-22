<?php
require_once __DIR__ . "/../../config/session.php";
require_once '../../config/db.php';
require_once '../../config/validators.php';

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'super_admin'])) {
    header("Location: ../../auth/login.php");
    exit;
}

$full_name_user = $_SESSION['full_name'];
$initials       = substr(implode('', array_map(fn($w) => strtoupper($w[0]), explode(' ', $full_name_user))), 0, 2);

$errors  = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $full_name      = strtoupper(san_str($_POST['full_name'] ?? '', MAX_NAME));
    $contact_number = san_str($_POST['contact_number'] ?? '', MAX_PHONE);
    $email          = san_str($_POST['email'] ?? '', MAX_EMAIL);
    $address        = san_str($_POST['address'] ?? '', MAX_ADDRESS);
    $plate_number   = strtoupper(san_str($_POST['plate_number'] ?? '', MAX_PLATE));
    $make           = san_str($_POST['make'] ?? '', MAX_MAKE_MODEL);
    $model          = san_str($_POST['model'] ?? '', MAX_MAKE_MODEL);
    $year_model     = san_int($_POST['year_model'] ?? 0, 1960, (int)date('Y') + 1);
    $color          = san_str($_POST['color'] ?? '', MAX_COLOR);
    $motor_number   = san_str($_POST['motor_number'] ?? '', MAX_MOTOR_SN);
    $serial_number  = san_str($_POST['serial_number'] ?? '', MAX_MOTOR_SN);

    if ($full_name === '')                          $errors[] = 'Full name is required.';
    elseif (!validate_name($full_name))             $errors[] = 'Full name contains invalid characters.';
    if ($contact_number === '')                     $errors[] = 'Contact number is required.';
    elseif (!validate_phone($contact_number))       $errors[] = 'Contact number must be a valid PH mobile number (09XXXXXXXXX).';
    if ($email !== '' && !validate_email($email))   $errors[] = 'Please enter a valid email address.';
    if ($address === '')                            $errors[] = 'Address is required.';
    if ($plate_number === '')                       $errors[] = 'Plate number is required.';
    elseif (!validate_plate($plate_number))         $errors[] = 'Plate number contains invalid characters.';
    if ($make === '')                               $errors[] = 'Vehicle make is required.';
    if ($model === '')                              $errors[] = 'Vehicle model is required.';
    if ($year_model === 0)                          $errors[] = 'Year model must be a valid year (1960–' . ((int)date('Y') + 1) . ').';
    if ($motor_number === '')                       $errors[] = 'Engine number is required.';
    if ($serial_number === '')                      $errors[] = 'Chassis number is required.';

    if ($plate_number !== '') {
        $check = $conn->prepare("SELECT vehicle_id FROM vehicles WHERE plate_number = ?");
        $check->bind_param('s', $plate_number);
        $check->execute();
        if ($check->get_result()->num_rows > 0)
            $errors[] = 'Plate number ' . $plate_number . ' already exists in the system.';
    }

    if (empty($errors)) {
        $ins_client = $conn->prepare("INSERT INTO clients (full_name, contact_number, email, address) VALUES (?, ?, ?, ?)");
        $ins_client->bind_param('ssss', $full_name, $contact_number, $email, $address);
        $ins_client->execute();
        $client_id = $conn->insert_id;

        $ins_vehicle = $conn->prepare("INSERT INTO vehicles (client_id, plate_number, make, model, year_model, color, motor_number, serial_number) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $ins_vehicle->bind_param('isssssss', $client_id, $plate_number, $make, $model, $year_model, $color, $motor_number, $serial_number);
        $ins_vehicle->execute();

        // Audit log
        $uid = $_SESSION['user_id'];
        $log = $conn->prepare("INSERT INTO audit_logs (user_id, action, description) VALUES (?, 'CLIENT_ADDED', ?)");
        $desc = ($_SESSION['full_name'] ?? 'Unknown') . ' added client "' . $full_name . '" with vehicle ' . ($plate_number ?: 'no plate') . ' (' . $make . ' ' . $model . ').';
        $log->bind_param('is', $uid, $desc);
        $log->execute();

        header("Location: client_list.php?success=" . urlencode($full_name . ' has been added successfully.'));
        exit;
    }
}

$page_title  = 'Add Client';
$active_page = 'clients';
$base_path   = '../../';
require_once '../../includes/header.php';
require_once '../../includes/navbar.php';
?>

<div class="main">

<?php
$topbar_title      = 'Add Client';
$topbar_breadcrumb = ['Records', 'Add Client'];
require_once '../../includes/topbar.php';
?>

  <div class="content">

    <a href="client_list.php" class="back-link"><?= icon('arrow-left', 14) ?> Back to Client Records</a>


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

    <!-- OCR MODAL -->
    <div id="ocr-modal" style="display:none;position:fixed;inset:0;z-index:1000;background:rgba(0,0,0,0.55);backdrop-filter:blur(2px);align-items:center;justify-content:center;padding:1rem;" onclick="if(event.target===this)ocrModalClose()">
      <div style="background:var(--bg-2);border:1px solid var(--border);border-radius:16px;width:100%;max-width:480px;box-shadow:var(--shadow-lg);animation:ocr-modal-in 0.18s ease;">
        <div style="display:flex;align-items:center;justify-content:space-between;padding:1rem 1.25rem;border-bottom:1px solid var(--border);">
          <div style="display:flex;align-items:center;gap:0.6rem;">
            <span style="color:var(--gold-bright);"><?= icon('magnifying-glass', 16) ?></span>
            <span style="font-weight:700;font-size:0.9rem;color:var(--text-primary);">Scan OR / CR / Policy</span>
            <span style="font-size:0.65rem;font-weight:700;color:var(--gold-bright);background:var(--gold-pale);border:1px solid var(--gold-bright);border-radius:6px;padding:0.1rem 0.4rem;">OCR</span>
          </div>
          <button type="button" onclick="ocrModalClose()" style="background:none;border:none;cursor:pointer;color:var(--text-muted);padding:0.25rem;"><?= icon('x-mark', 16) ?></button>
        </div>
        <div style="padding:1.25rem;">
          <div id="ocr-upload-area" style="border:2px dashed var(--border);border-radius:12px;padding:1.5rem;text-align:center;cursor:pointer;transition:border-color 0.15s;" onclick="document.getElementById('ocr-file-input').click()">
            <div id="ocr-idle">
              <div style="color:var(--text-muted);margin-bottom:0.4rem;"><?= icon('camera', 24) ?></div>
              <div style="font-size:0.85rem;font-weight:700;color:var(--text-primary);margin-bottom:0.2rem;">Tap to take a photo or upload</div>
              <div style="font-size:0.72rem;color:var(--text-muted);">OR/CR or PhilBritish policy · JPG, PNG, WEBP, PDF</div>
            </div>
            <div id="ocr-preview" style="display:none;">
              <img id="ocr-img" style="max-width:100%;max-height:200px;border-radius:8px;object-fit:contain;" alt="OR/CR"/>
              <div id="ocr-pdf-preview" style="display:none;padding:1.5rem;text-align:center;">
                <div style="color:var(--text-muted);"><?= icon('document-text', 32) ?></div>
                <div id="ocr-pdf-name" style="font-size:0.8rem;font-weight:600;margin-top:0.4rem;color:var(--text-primary);word-break:break-all;"></div>
                <div style="font-size:0.7rem;color:var(--text-muted);margin-top:0.2rem;">PDF document ready to scan</div>
              </div>
            </div>
          </div>
          <input type="file" id="ocr-file-input" accept="image/*,application/pdf" style="display:none;"/>
          <div id="ocr-progress" style="display:none;margin-top:1rem;">
            <div style="display:flex;align-items:center;gap:0.75rem;margin-bottom:0.5rem;">
              <div style="width:16px;height:16px;border:2px solid var(--border);border-top-color:var(--gold-bright);border-radius:50%;animation:spin 0.7s linear infinite;flex-shrink:0;"></div>
              <span id="ocr-status-text" style="font-size:0.78rem;color:var(--text-muted);">Reading document…</span>
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
    <script>
    function ocrModalOpen()  { var m = document.getElementById("ocr-modal"); if (m) m.style.display = "flex"; }
    function ocrModalClose() { var m = document.getElementById("ocr-modal"); if (m) m.style.display = "none"; }
    function ocrClear() {
      var fi = document.getElementById("ocr-file-input");
      var img = document.getElementById("ocr-img");
      if (fi) fi.value = "";
      if (img) { img.src = ""; img.style.display = "block"; }
      var pp = document.getElementById("ocr-pdf-preview"); if (pp) pp.style.display = "none";
      var idle = document.getElementById("ocr-idle"); if (idle) idle.style.display = "block";
      var prev = document.getElementById("ocr-preview"); if (prev) prev.style.display = "none";
      var cb = document.getElementById("ocr-clear-btn"); if (cb) cb.style.display = "none";
      var prog = document.getElementById("ocr-progress"); if (prog) prog.style.display = "none";
      var res = document.getElementById("ocr-result-notice"); if (res) res.style.display = "none";
      var err = document.getElementById("ocr-error-notice"); if (err) err.style.display = "none";
      document.querySelectorAll(".ocr-filled").forEach(function(el) { el.classList.remove("ocr-filled"); });
    }
    </script>

    <form method="POST" action="">
      <?= csrf_field() ?>
      <div class="card">
        <div class="card-header">
          <div class="card-icon"><?= icon('user', 16) ?></div>
          <div>
            <div class="card-title">Client Information</div>
            <div class="card-sub">Fields marked <span style="color:var(--gold-bright);">*</span> are required</div>
          </div>
          <button type="button" onclick="ocrModalOpen()" class="btn-ghost" style="margin-left:auto;font-size:0.78rem;padding:0.45rem 1rem;">
            <?= icon('camera', 13) ?> Scan Document
          </button>
        </div>
        <div style="padding:1.5rem;">

          <div class="field-section">Personal Details</div>
          <div class="form-grid" style="margin-bottom:1rem;">
            <div class="field">
              <label class="field-label">Full Name <span class="req">*</span></label>
              <input type="text" name="full_name" class="field-input"
                placeholder="FIRST MIDDLE LAST"
                value="<?= htmlspecialchars($_POST['full_name'] ?? '') ?>"
                style="text-transform:uppercase;"
                autofocus/>
            </div>
            <div class="field">
              <label class="field-label">Contact Number <span class="req">*</span></label>
              <input type="text" name="contact_number" class="field-input"
                placeholder="09*********"
                value="<?= htmlspecialchars($_POST['contact_number'] ?? '') ?>"/>
            </div>
            <div class="field">
              <label class="field-label">Email Address</label>
              <input type="email" name="email" class="field-input"
                placeholder="username@email.com"
                value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"/>
            </div>
            <div class="field">
              <label class="field-label">Address <span class="req">*</span></label>
              <input type="text" name="address" class="field-input"
                placeholder="San Roque, Pandi, Bulacan"
                value="<?= htmlspecialchars($_POST['address'] ?? '') ?>"/>
            </div>
          </div>

          <div class="field-section">Vehicle Details</div>
          <div class="form-grid-3" style="margin-bottom:1rem;">

            <!-- Row 1: Plate | Make | Model -->
            <div class="field">
              <label class="field-label">Plate Number <span class="req">*</span></label>
              <input type="text" name="plate_number" class="field-input"
                placeholder="ABC 1234"
                value="<?= htmlspecialchars($_POST['plate_number'] ?? '') ?>"
                style="text-transform:uppercase;"/>
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

            <!-- Row 2: Year | Color (spans 2 cols) -->
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
          <button type="button" class="btn-ghost" id="clear-form-btn"><?= icon('x-mark', 14) ?> Clear</button>
          <button type="submit" class="btn-primary"><?= icon('floppy-disk', 14) ?> Save Client</button>
        </div>
      </div>
    </form>

  </div>
</div>

<?php
$footer_scripts = '';
$footer_extra_scripts = <<<'ADDCLIENT_SCRIPT'
<script>
(function() {
  var fileInput = document.getElementById("ocr-file-input");
  var idleEl    = document.getElementById("ocr-idle");
  var previewEl = document.getElementById("ocr-preview");
  var imgEl     = document.getElementById("ocr-img");
  var progressEl= document.getElementById("ocr-progress");
  var statusEl  = document.getElementById("ocr-status-text");
  var barEl     = document.getElementById("ocr-bar");
  var resultEl  = document.getElementById("ocr-result-notice");
  var errorEl   = document.getElementById("ocr-error-notice");
  var clearBtn  = document.getElementById("ocr-clear-btn");
  var filledEl  = document.getElementById("ocr-filled-fields");

  if (fileInput) {
    fileInput.addEventListener("change", function() {
      if (!this.files || !this.files[0]) return;
      var file = this.files[0];
      var isPdf = file.type === "application/pdf";
      idleEl.style.display    = "none";
      previewEl.style.display = "block";
      clearBtn.style.display  = "inline-flex";
      resultEl.style.display  = "none";
      errorEl.style.display   = "none";
      if (isPdf) {
        imgEl.style.display = "none";
        document.getElementById("ocr-pdf-preview").style.display = "block";
        document.getElementById("ocr-pdf-name").textContent = file.name;
      } else {
        imgEl.style.display = "block";
        document.getElementById("ocr-pdf-preview").style.display = "none";
        imgEl.src = URL.createObjectURL(file);
      }
      runOCR(file);
    });
  }

  function fillField(selector, value) {
    if (!value) return false;
    var el = document.querySelector(selector);
    if (!el || el.value) return false;
    el.value = value.trim();
    el.classList.add("ocr-filled");
    el.dispatchEvent(new Event("input", { bubbles: true }));
    return true;
  }

  function compact(str, max) {
    return str.trim().replace(/\s+/g, "").slice(0, max);
  }

  function parseText(text) {
    var filled = [];
    var upper  = text.toUpperCase();
    var lines  = upper.split(/\r?\n/);

    var isPolicy = /MOTOR\s+CAR\s+POLICY\s+SCHEDULE|PHILBRITISH|POLICY\s+NO\s*\.?\s*:\s*P-BLC/i.test(text);
    if (isPolicy) {
      console.log("[OCR-POLICY] raw text:\n", text);

      // MAKE : 2022 SUZUKI...  OR  MAKE\t:\t2022 SUZUKI...
      var makeLineM = upper.match(/^MAKE[ \t]*:[ \t]*(\d{4})\s+([A-Z]+)\s+([A-Z0-9 ]+)/m);
      if (makeLineM) {
        var yr = makeLineM[1];
        var mk = makeLineM[2];
        var modelShort = makeLineM[3].trim().split(/\s+/).slice(0, 2).join(" ");
        fillField("[name=year_model]", yr)   && filled.push("Year Model");
        fillField("[name=make]", mk[0] + mk.slice(1).toLowerCase()) && filled.push("Make");
        fillField("[name=model]", modelShort) && filled.push("Model");
      } else { console.log("[OCR] MAKE line not matched"); }

      var insuredM = upper.match(/INSURED\s*:[ \t]*(.{3,120})/);
      if (insuredM) {
        var lineVal = insuredM[1].replace(/\s*:\s*PHP.*$/i, "").trim();
        lineVal = lineVal.replace(/\s+/g, " ").split(/\t/)[0].trim();
        var lastColon = lineVal.lastIndexOf(":");
        if (lastColon !== -1) lineVal = lineVal.slice(lastColon + 1).trim();
        var insuredIdx = upper.indexOf(insuredM[0]);
        var afterInsured = upper.slice(insuredIdx + insuredM[0].length);
        var skipKeywords = /^(PREMIUM|DOCUMENTARY|ADDRESS|AGENT|TOTAL|FROM|TO|PRODUCT|LINE|POLICY|SUB|MAKE|PLATE|MOTOR|SERIAL|COLOR|OTHER|VALUE|LOCAL|HVDC|PHP)/;
        var nameCont = "";
        var linesAfter = afterInsured.split(/\r?\n/).slice(0, 5);
        for (var i = 0; i < linesAfter.length; i++) {
          var t = linesAfter[i].trim().split(/\t/)[0].trim();
          if (t.length >= 2 && t.length <= 30 && /^[A-Z ]+$/.test(t) && !skipKeywords.test(t)) {
            nameCont = t;
            break;
          }
        }
        var fullName = (lineVal + (nameCont ? " " + nameCont : "")).replace(/\s+/g, " ").trim();
        console.log("[OCR] name built:", fullName);
        if (fullName.length >= 3) fillField("[name=full_name]", fullName) && filled.push("Full Name");
      } else { console.log("[OCR] INSURED not matched"); }

      // Address: PDF layout "Address\t: 32F GT TOWER...\tValue Added Tax\t..."
      // Continuation line "HVDC SALCEDO VILLAGE, MAKATI CITY\t..." may follow
      var addrM = upper.match(/^ADDRESS[ \t]*:[ \t]*(.{5,120})/m);
      if (addrM) {
        var addrRaw = addrM[1].trim();
        // Strip tab-separated columns on same line (stop at first tab)
        addrRaw = addrRaw.split(/\t/)[0].trim();
        // Strip peso amounts and keyword columns
        addrRaw = addrRaw.replace(/\s*\d[\d,]*\.\d{2}.*$/, "").trim();
        addrRaw = addrRaw.replace(/\s*(?:VALUE ADDED|DOCUMENTARY|LOCAL GOV|PREMIUM|TOTAL|ISSUE DATE).*$/i, "").trim();
        // Try to grab continuation line (next non-empty line before a known keyword)
        var addrIdx = upper.indexOf(addrM[0]);
        var afterAddr = upper.slice(addrIdx + addrM[0].length);
        var addrSkip = /^(VALUE|DOCUMENTARY|LOCAL|OTHER|TOTAL|AGENT|PREMIUM|ADDRESS|MAKE|PLATE|MOTOR|SERIAL|COLOR|MORTGAGEE|INSURED|SCHEDULED)/;
        var addrLines = afterAddr.split(/\r?\n/).slice(0, 3);
        for (var ai = 0; ai < addrLines.length; ai++) {
          var al = addrLines[ai].split(/\t/)[0].trim();
          if (al.length >= 5 && !addrSkip.test(al) && !/^\d[\d,]*\.\d{2}/.test(al)) {
            addrRaw = addrRaw + ", " + al;
            break;
          }
        }
        if (addrRaw.length >= 8) fillField("[name=address]", addrRaw) && filled.push("Address");
      }

      // PLATE NO line: split by tab, grab first token that looks like a plate (4-8 alphanum)
      // PDF: "PLATE NO.\tNIC3436\tMV FILE NO."  Image: "PLATE NO.\t: NIC3436"
      var pPlateRaw = null;
      var pPlateLineIdx = upper.indexOf("PLATE NO");
      if (pPlateLineIdx !== -1) {
        var pPlateLineEnd = upper.indexOf("\n", pPlateLineIdx);
        if (pPlateLineEnd === -1) pPlateLineEnd = upper.length;
        var plateLine = upper.slice(pPlateLineIdx, pPlateLineEnd);
        var plateTokens = plateLine.split(/\t/);
        for (var pi = 1; pi < plateTokens.length; pi++) {
          var pt = plateTokens[pi].replace(/^:\s*/, "").trim(); // strip leading colon
          if (/^[A-Z0-9]{4,8}$/.test(pt)) { pPlateRaw = pt; break; }
        }
      }
      if (pPlateRaw) {
        var carM = pPlateRaw.match(/^([A-Z]{2,3})(\d{3,4})$/);
        var moM  = pPlateRaw.match(/^(\d{3})([A-Z]{2,3})$/);
        var fmt  = carM ? carM[1]+" "+carM[2] : (moM ? moM[1]+" "+moM[2] : pPlateRaw);
        fillField("[name=plate_number]", fmt) && filled.push("Plate Number");
      } else console.log("[OCR] PLATE NO not matched");

      // MOTOR NO line: split by tab, grab first token that is 6-20 alphanum
      // PDF: "MOTOR NO.\tK12MP4273382\t"  Image: "MOTOR NO.\t: K12MP4273382"
      var motorVal = null;
      var motorLineIdx = upper.indexOf("MOTOR NO");
      if (motorLineIdx !== -1) {
        var motorLineEnd = upper.indexOf("\n", motorLineIdx);
        if (motorLineEnd === -1) motorLineEnd = upper.length;
        var motorLine = upper.slice(motorLineIdx, motorLineEnd);
        var motorTokens = motorLine.split(/\t/);
        for (var moi = 1; moi < motorTokens.length; moi++) {
          var mt = motorTokens[moi].replace(/^:\s*/, "").trim();
          if (/^[A-Z0-9]{6,20}$/.test(mt)) { motorVal = mt; break; }
        }
      }
      if (motorVal) fillField("[name=motor_number]", motorVal) && filled.push("Engine Number");
      else console.log("[OCR] MOTOR NO not matched");

      // Find SERIAL NO line then grab the first token that looks like a VIN (10-20 alphanum, no spaces)
      var serialVal = null;
      var serialLineIdx = upper.indexOf("SERIAL NO");
      if (serialLineIdx !== -1) {
        // Get the full line containing SERIAL NO
        var serialLineEnd = upper.indexOf("\n", serialLineIdx);
        if (serialLineEnd === -1) serialLineEnd = upper.length;
        var serialLine = upper.slice(serialLineIdx, serialLineEnd);
        // Split by tab, find first token that is 10-20 pure alphanum (a VIN)
        var serialTokens = serialLine.split(/\t/);
        for (var si = 1; si < serialTokens.length; si++) {
          var st = serialTokens[si].trim();
          if (/^[A-Z0-9]{10,20}$/.test(st)) { serialVal = st; break; }
        }
        // Fallback: value on next line (image scan layout)
        if (!serialVal) {
          var afterSerial = upper.slice(serialLineIdx);
          var nextM = afterSerial.match(/SERIAL[^\r\n]*[\r\n]+:?[ \t]*([A-Z0-9][A-Z0-9 ]{8,})/);
          if (nextM) serialVal = nextM[1].replace(/\s+/g, "").trim();
        }
      }
      if (serialVal && serialVal.length >= 10) {
        fillField("[name=serial_number]", serialVal) && filled.push("Chassis Number");
      } else console.log("[OCR] SERIAL NO not matched, val:", serialVal);

      var pColorM = upper.match(/^COLOR\s*:?[ \t]+([A-Z][A-Z]+)(?:\s{2,}|\t|$)/m);
      if (pColorM) {
        var c = pColorM[1].trim();
        fillField("[name=color]", c[0]+c.slice(1).toLowerCase()) && filled.push("Color");
      } else console.log("[OCR] COLOR not matched");

      console.log("[OCR] filled:", filled);
      return filled;
    }

    var nameVal = null;
    var orNameM = text.match(/RECEIVED\s+FROM[^\r\n]*[\r\n]+([A-Z][A-Z\s.,]{3,60})/i);
    if (orNameM) {
      var raw = orNameM[1].trim().replace(/\s+/g, " ").split("\t")[0].trim();
      var commaM = raw.match(/^([A-Z][A-Z\s]+),\s*([A-Z][A-Z\s]+)$/i);
      nameVal = commaM ? (commaM[2].trim() + " " + commaM[1].trim()) : raw;
    } else {
      var crNameM = text.match(/COMPLETE\s+OWNERS?\s+NAME[^\r\n]*[\r\n]+([A-Z][A-Z\s.,]{3,60})/i);
      if (crNameM) {
        nameVal = crNameM[1].trim().replace(/\s+/g, " ").split("\t")[0].trim()
                   .replace(/^(?:[A-Z]{1,3}[.\s]+)+/i, "").trim();
      }
    }
    if (nameVal && nameVal.length >= 5 && fillField("[name=full_name]", nameVal)) filled.push("Full Name");

    var addrVal = null;
    var orAddrM = text.match(/ADDRESS\s*\([^)]*\)\t([^\t\r\n]{10,120})/i);
    if (orAddrM) {
      var firstPart = orAddrM[1].trim();
      var afterIdx = text.indexOf(orAddrM[1]) + orAddrM[1].length;
      var nextLine = text.slice(afterIdx).match(/[\r\n]+([^\t\r\n]{3,60})/);
      var secondPart = nextLine ? nextLine[1].trim() : "";
      addrVal = (firstPart + (secondPart ? ", " + secondPart : "")).replace(/\s+/g, " ").trim();
    } else {
      var crAddrM = text.match(/COMPLETE\s+A(?:DD)?RESS[^\r\n]*[\r\n]+([^\r\n]{10,120})/i);
      if (crAddrM) addrVal = crAddrM[1].trim().replace(/\s+/g, " ").split("\t")[0].trim();
    }
    if (addrVal && fillField("[name=address]", addrVal)) filled.push("Address");

    var contactM = text.match(/\b(09\d{9})\b/);
    if (contactM && fillField("[name=contact_number]", contactM[1])) filled.push("Contact Number");

    var plateVal = null, engineValFromLine = null;
    for (var li = 0; li < lines.length; li++) {
      var tokens = lines[li].trim().split(/\t+/);
      if (tokens[0] && /^\d{4}-\d+$/.test(tokens[0].trim())) {
        if (tokens[1]) {
          var rawT = tokens[1].trim().replace(/\s+/g, "");
          var carM2  = rawT.match(/^([A-Z]{2,3})(\d{3,4})$/);
          var motoM2 = rawT.match(/^(\d{3})([A-Z]{2,3})$/);
          if (carM2)       plateVal = carM2[1] + " " + carM2[2];
          else if (motoM2) plateVal = motoM2[1] + " " + motoM2[2];
          else if (/^[A-Z0-9]{5,8}$/.test(rawT)) plateVal = rawT;
        }
        if (tokens[2]) {
          var eng = tokens[2].trim().replace(/\s+/g, "");
          if (/^[A-Z0-9]{6,20}$/.test(eng)) engineValFromLine = eng;
        }
        break;
      }
    }
    if (!plateVal) {
      var plateLabelM = upper.match(/PLATE\s*NO[.:*\s\t]+([A-Z0-9]{5,8})/);
      if (plateLabelM) {
        var rawP = plateLabelM[1].trim();
        var carMP  = rawP.match(/^([A-Z]{2,3})(\d{3,4})$/);
        var motoMP = rawP.match(/^(\d{3})([A-Z]{2,3})$/);
        if (carMP)       plateVal = carMP[1] + " " + carMP[2];
        else if (motoMP) plateVal = motoMP[1] + " " + motoMP[2];
        else plateVal = rawP;
      }
    }
    if (plateVal && fillField("[name=plate_number]", plateVal)) filled.push("Plate Number");

    if (!filled.includes("Make")) {
      if (!upper.match(/^MAKE\s*:/m)) {
        var makes = ["TOYOTA","HONDA","MITSUBISHI","FORD","NISSAN","HYUNDAI","KIA","SUZUKI","ISUZU","MAZDA","CHEVROLET","SUBARU","BMW","MERCEDES","VOLKSWAGEN","JEEP","LEXUS","DODGE","YAMAHA","KAWASAKI","DUCATI","BAJAJ","TVS","KYMCO"];
        for (var mi = 0; mi < makes.length; mi++) {
          if (upper.includes(makes[mi])) {
            if (fillField("[name=make]", makes[mi][0] + makes[mi].slice(1).toLowerCase())) { filled.push("Make"); break; }
          }
        }
      }
    }

    var seriesM = upper.match(/SERIES[.:\s\t]+([A-Z0-9]{3,20})/);
    if (seriesM && fillField("[name=model]", seriesM[1])) filled.push("Model");

    var yearM = upper.match(/YEAR\s*MODEL[.:\s\t]*(\d{4})/);
    if (yearM) {
      if (fillField("[name=year_model]", yearM[1])) filled.push("Year Model");
    } else {
      var anyYear = upper.match(/\b(19[6-9]\d|20[0-3]\d)\b/);
      if (anyYear && fillField("[name=year_model]", anyYear[1])) filled.push("Year Model");
    }

    var colorM = upper.match(/\bCOLOR\s*[:\s\t]+([A-Z]{3,20})/);
    if (colorM) {
      var colorVal = colorM[1][0] + colorM[1].slice(1).toLowerCase();
      if (fillField("[name=color]", colorVal)) filled.push("Color");
    }

    if (engineValFromLine && fillField("[name=motor_number]", engineValFromLine)) {
      filled.push("Engine Number");
    } else {
      var engM = upper.match(/ENGINE\s*NO[.:\s\t]+([A-Z0-9][A-Z0-9 ]{5,25})/);
      if (engM) {
        var engVal = compact(engM[1].split(/[^A-Z0-9 ]/)[0], 20);
        if (engVal.length >= 6 && fillField("[name=motor_number]", engVal)) filled.push("Engine Number");
      }
    }

    var chassisM = upper.match(/CHASSIS\s*NO[.:\s\t]+([A-Z0-9][A-Z0-9 ]{9,25})/);
    if (chassisM) {
      var chassisVal = compact(chassisM[1].split(/[^A-Z0-9 ]/)[0], 17);
      if (chassisVal.length >= 10 && fillField("[name=serial_number]", chassisVal)) filled.push("Chassis Number");
    }
    if (!filled.includes("Chassis Number")) {
      var vin = upper.match(/\b([A-HJ-NPR-Z0-9]{17})\b/);
      if (vin && fillField("[name=serial_number]", vin[1])) filled.push("Chassis Number");
    }

    return filled;
  }

  function runOCR(file) {
    progressEl.style.display = "block";
    barEl.style.width = "20%";
    statusEl.textContent = "Uploading image…";
    var formData = new FormData();
    formData.append("image", file);
    barEl.style.width = "50%";
    statusEl.textContent = "Reading document…";
    fetch("ocr_scan.php", { method: "POST", body: formData })
      .then(function(res) { return res.json(); })
      .then(function(data) {
        barEl.style.width = "100%";
        progressEl.style.display = "none";
        if (data.error) {
          document.getElementById("ocr-error-msg").textContent = data.error;
          errorEl.style.display = "block";
          return;
        }
        var filled = parseText(data.text);
        if (filled.length > 0) {
          filledEl.textContent = "Auto-filled: " + filled.join(", ") + ". Please verify before saving.";
          resultEl.style.display = "block";
        } else {
          document.getElementById("ocr-error-msg").textContent = "Text was read but no matching fields found. Please fill in manually.";
          errorEl.style.display = "block";
        }
      })
      .catch(function(err) {
        progressEl.style.display = "none";
        document.getElementById("ocr-error-msg").textContent = "OCR failed: " + err.message;
        errorEl.style.display = "block";
      });
  }

  var clearFormBtn = document.getElementById("clear-form-btn");
  if (clearFormBtn) {
    clearFormBtn.addEventListener("click", function() {
      Swal.fire({
        icon: "warning",
        title: "Clear all fields?",
        text: "This will reset the entire form.",
        confirmButtonText: "Yes, Clear",
        cancelButtonText: "Cancel",
        showCancelButton: true,
        confirmButtonColor: "#B8860B",
        cancelButtonColor: "#6c757d",
        reverseButtons: true
      }).then(function(result) {
        if (result.isConfirmed) {
          var fieldNames = ["full_name","contact_number","email","address","plate_number","make","model","year_model","color","motor_number","serial_number"];
          fieldNames.forEach(function(name) {
            var el = document.querySelector("[name=" + name + "]");
            if (el) { el.value = ""; el.classList.remove("ocr-filled"); }
          });
          document.querySelectorAll(".ocr-filled").forEach(function(el) { el.classList.remove("ocr-filled"); });
        }
      });
    });
  }

  var theForm = document.querySelector("form");
  if (theForm) {
    theForm.addEventListener("submit", function(e) {
      e.preventDefault();
      var form = this;
      var name    = (document.querySelector("[name=full_name]")      || {}).value || "—";
      var plate   = (document.querySelector("[name=plate_number]")   || {}).value || "—";
      var vmake   = (document.querySelector("[name=make]")           || {}).value || "—";
      var vmodel  = (document.querySelector("[name=model]")          || {}).value || "—";
      var year    = (document.querySelector("[name=year_model]")     || {}).value || "—";
      var chassis = (document.querySelector("[name=serial_number]")  || {}).value || "—";
      Swal.fire({
        icon: "question",
        title: "Confirm Client Details",
        html:
          "<table style=\"width:100%;font-size:0.82rem;text-align:left;border-collapse:collapse;\">" +
          "<tr><td style=\"padding:0.3rem 0.5rem;color:var(--text-muted);width:45%;\">Full Name</td><td style=\"padding:0.3rem 0.5rem;font-weight:700;\">" + name + "</td></tr>" +
          "<tr style=\"background:rgba(0,0,0,0.03);\"><td style=\"padding:0.3rem 0.5rem;color:var(--text-muted);\">Plate Number</td><td style=\"padding:0.3rem 0.5rem;font-weight:700;\">" + plate + "</td></tr>" +
          "<tr><td style=\"padding:0.3rem 0.5rem;color:var(--text-muted);\">Vehicle</td><td style=\"padding:0.3rem 0.5rem;font-weight:700;\">" + year + " " + vmake + " " + vmodel + "</td></tr>" +
          "<tr style=\"background:rgba(0,0,0,0.03);\"><td style=\"padding:0.3rem 0.5rem;color:var(--text-muted);\">Chassis No.</td><td style=\"padding:0.3rem 0.5rem;font-weight:700;font-family:monospace;\">" + chassis + "</td></tr>" +
          "</table>",
        confirmButtonText: "Yes, Save Client",
        cancelButtonText: "Review Again",
        showCancelButton: true,
        confirmButtonColor: "#B8860B",
        cancelButtonColor: "#6c757d",
        reverseButtons: true
      }).then(function(result) {
        if (result.isConfirmed) { form.submit(); }
      });
    });
  }

})();
</script>
ADDCLIENT_SCRIPT;
require_once '../../includes/footer.php';
?>