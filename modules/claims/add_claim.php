<?php
require_once __DIR__ . "/../../config/session.php";
require_once '../../config/db.php';
require_once '../../config/validators.php';
require_once '../../includes/icons.php';

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'super_admin'])) {
    header("Location: ../../auth/login.php");
    exit;
}

$errors  = [];
$success = false;

// AJAX: search clients by name
if (isset($_GET['ajax_clients']) && isset($_GET['q'])) {
    header('Content-Type: application/json');
    $q    = '%' . san_str($_GET['q'], 100) . '%';
    $stmt = $conn->prepare("SELECT client_id, full_name FROM clients WHERE full_name LIKE ? ORDER BY full_name ASC LIMIT 20");
    $stmt->bind_param('s', $q);
    $stmt->execute();
    echo json_encode($stmt->get_result()->fetch_all(MYSQLI_ASSOC));
    exit;
}

// AJAX: load policies for a selected client
if (isset($_GET['ajax_policies']) && isset($_GET['client_id'])) {
    header('Content-Type: application/json');
    $cid  = (int)$_GET['client_id'];
    $stmt = $conn->prepare("
        SELECT ip.policy_id, ip.policy_number, ip.coverage_type, ip.policy_end,
               v.plate_number, v.make, v.model
        FROM insurance_policies ip
        LEFT JOIN vehicles v ON ip.vehicle_id = v.vehicle_id
        WHERE ip.client_id = ? AND ip.policy_end >= CURDATE()
        ORDER BY ip.policy_end ASC
    ");
    $stmt->bind_param('i', $cid);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    echo json_encode($rows);
    exit;
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $client_id    = (int)($_POST['client_id'] ?? 0);
    $policy_id    = (int)($_POST['policy_id'] ?? 0);
    $claim_type   = san_enum($_POST['claim_type'] ?? '', ALLOWED_CLAIM_TYPES);
    $incident_date = san_str($_POST['incident_date'] ?? '', 10);
    $description  = san_str($_POST['description'] ?? '', MAX_TEXT);

    if (!$client_id)            $errors[] = 'Please select a client.';
    if (!$policy_id)            $errors[] = 'Please select a policy.';
    if ($claim_type === '')     $errors[] = 'Please select a valid claim type.';
    if ($incident_date === '')  $errors[] = 'Incident date is required.';
    elseif (!validate_date($incident_date) || $incident_date > date('Y-m-d'))
                                $errors[] = 'Incident date must be a valid past date.';
    if ($description === '')    $errors[] = 'Incident description is required.';

    if (empty($errors)) {
        $stmt = $conn->prepare("
            INSERT INTO claims (policy_id, client_id, claim_type, incident_date, description, status, created_by)
            VALUES (?, ?, ?, ?, ?, 'compiling', ?)
        ");
        $stmt->bind_param('iisssi', $policy_id, $client_id, $claim_type, $incident_date, $description, $_SESSION['user_id']);
        $stmt->execute();
        $new_id = $conn->insert_id;

        $log  = $conn->prepare("INSERT INTO audit_logs (user_id, action, description) VALUES (?, 'CLAIM_FILED', ?)");
        $desc = ($_SESSION['full_name'] ?? 'Unknown') . ' filed a new ' . $claim_type . ' claim.';
        $log->bind_param('is', $_SESSION['user_id'], $desc);
        $log->execute();

        header("Location: view_claim.php?id=$new_id&success=" . urlencode('Claim filed successfully.'));
        exit;
    }
}

// Pre-load client from GET param (coming from view_client.php)
$prefill_client_id   = 0;
$prefill_client_name = '';
if (isset($_GET['client_id']) && !$_POST) {
    $prefill_client_id = (int)$_GET['client_id'];
    if ($prefill_client_id > 0) {
        $pfc = $conn->prepare("SELECT client_id, full_name FROM clients WHERE client_id = ?");
        $pfc->bind_param('i', $prefill_client_id);
        $pfc->execute();
        $pfc_row = $pfc->get_result()->fetch_assoc();
        if ($pfc_row) {
            $prefill_client_id   = (int)$pfc_row['client_id'];
            $prefill_client_name = $pfc_row['full_name'];
        } else {
            $prefill_client_id = 0;
        }
    }
}

$page_title  = 'File New Claim';
$active_page = 'claims';
$base_path   = '../../';
require_once '../../includes/header.php';
require_once '../../includes/navbar.php';
?>

<div class="main">

<?php
$topbar_title      = 'File New Claim';
$topbar_breadcrumb = ['Insurance', 'Claims', 'File New Claim'];
require_once '../../includes/topbar.php';
?>

  <div class="content">
    <div>

      <?php if (!empty($errors)): ?>
      <div class="alert-error" style="margin-bottom:1.25rem;">
        <?= icon('exclamation-triangle', 14) ?>
        <div><?php foreach ($errors as $e) echo '<div>' . htmlspecialchars($e) . '</div>'; ?></div>
      </div>
      <?php endif; ?>

      <!-- OCR MODAL -->
      <div id="ocr-modal" style="display:none;position:fixed;inset:0;z-index:1000;background:rgba(0,0,0,0.55);backdrop-filter:blur(2px);align-items:center;justify-content:center;padding:1rem;" onclick="if(event.target===this)ocrModalClose()">
        <div style="background:var(--bg-2);border:1px solid var(--border);border-radius:16px;width:100%;max-width:480px;box-shadow:var(--shadow-lg);animation:ocr-modal-in 0.18s ease;">
          <div style="display:flex;align-items:center;justify-content:space-between;padding:1rem 1.25rem;border-bottom:1px solid var(--border);">
            <div style="display:flex;align-items:center;gap:0.6rem;">
              <span style="color:var(--gold-bright);"><?= icon('magnifying-glass', 16) ?></span>
              <span style="font-weight:700;font-size:0.9rem;color:var(--text-primary);">Scan OR / CR Document</span>
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
                <img id="ocr-img" style="max-width:100%;max-height:200px;border-radius:8px;object-fit:contain;" alt="Document"/>
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
              <?= icon('check-circle', 13) ?> <span id="ocr-filled-fields">Fields auto-filled from document scan.</span> Review before filing.
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
        <div class="card" style="margin-bottom:1.25rem;overflow:visible;">
          <div class="card-header">
            <div class="card-icon"><?= icon('user', 16) ?></div>
            <div>
              <div class="card-title">Client & Policy</div>
              <div class="card-sub">Select the client and the policy to file against</div>
            </div>
          </div>
          <div class="card-body" style="overflow:visible;">
            <div class="form-grid" style="gap:1rem;overflow:visible;">

              <div class="field" style="position:relative;">
                <label class="field-label">Client <span style="color:var(--danger);">*</span></label>
                <input type="text" id="client_search" class="field-input" autocomplete="off"
                  placeholder="Type to search client..."
                  value="<?= htmlspecialchars($_POST['client_name_display'] ?? '') ?>"/>
                <input type="hidden" name="client_id" id="client_id" value="<?= (int)($_POST['client_id'] ?? 0) ?>"/>
                <input type="hidden" name="client_name_display" id="client_name_display" value="<?= htmlspecialchars($_POST['client_name_display'] ?? '') ?>"/>
                <div id="client_dropdown" style="display:none;position:absolute;top:100%;left:0;right:0;z-index:100;background:var(--bg-3);border:1px solid var(--gold-bright);border-radius:9px;box-shadow:var(--shadow-md);max-height:220px;overflow-y:auto;margin-top:2px;"></div>
              </div>

              <div class="field">
                <label class="field-label">Policy <span style="color:var(--danger);">*</span></label>
                <select name="policy_id" id="policy_id" class="field-input" required>
                  <option value="">— Select client first —</option>
                </select>
                <span class="field-hint">Only active (non-expired) policies are shown.</span>
              </div>

            </div>
          </div>
        </div>

        <div class="card" style="margin-bottom:1.25rem;">
          <div class="card-header">
            <div class="card-icon"><?= icon('clipboard-list', 16) ?></div>
            <div>
              <div class="card-title">Claim Details</div>
              <div class="card-sub">Incident information</div>
            </div>
            <button type="button" onclick="ocrModalOpen()" class="btn-ghost" style="margin-left:auto;font-size:0.78rem;padding:0.45rem 1rem;">
              <?= icon('camera', 13) ?> Scan Document
            </button>
          </div>
          <div class="card-body">
            <div class="form-grid" style="gap:1rem;">

              <div class="field">
                <label class="field-label">Claim Type <span style="color:var(--danger);">*</span></label>
                <select name="claim_type" class="field-input" required>
                  <option value="">— Select Type —</option>
                  <option value="claims" <?= (($_POST['claim_type'] ?? '') === 'claims') ? 'selected' : '' ?>>Claims</option>
                  <option value="repair" <?= (($_POST['claim_type'] ?? '') === 'repair') ? 'selected' : '' ?>>Repair</option>
                </select>
              </div>

              <div class="field">
                <label class="field-label">Incident Date <span style="color:var(--danger);">*</span></label>
                <input type="date" name="incident_date" class="field-input"
                  value="<?= htmlspecialchars($_POST['incident_date'] ?? '') ?>"
                  max="<?= date('Y-m-d') ?>" required/>
              </div>

              <div class="field span-2">
                <label class="field-label">Incident Description <span style="color:var(--danger);">*</span></label>
                <textarea name="description" class="field-input" rows="4"
                  placeholder="Briefly describe what happened..."
                  required><?= htmlspecialchars($_POST['description'] ?? '') ?></textarea>
              </div>

            </div>
          </div>
        </div>

        <div style="display:flex;gap:0.75rem;">
          <button type="submit" class="btn-primary"><?= icon('clipboard-list', 14) ?> File Claim</button>
          <a href="claims_list.php" class="btn-ghost"><?= icon('x-mark', 14) ?> Cancel</a>
        </div>
      </form>

    </div>
  </div>
</div>

<?php
$footer_extra_scripts = '';
$footer_scripts = '
  window.ocrModalOpen = function() {
    var m = document.getElementById("ocr-modal");
    m.style.display = "flex";
  };
  window.ocrModalClose = function() {
    var m = document.getElementById("ocr-modal");
    m.style.display = "none";
  };

  (function() {
    var fileInput  = document.getElementById("ocr-file-input");
    var idleEl     = document.getElementById("ocr-idle");
    var previewEl  = document.getElementById("ocr-preview");
    var imgEl      = document.getElementById("ocr-img");
    var progressEl = document.getElementById("ocr-progress");
    var statusEl   = document.getElementById("ocr-status-text");
    var barEl      = document.getElementById("ocr-bar");
    var resultEl   = document.getElementById("ocr-result-notice");
    var errorEl    = document.getElementById("ocr-error-notice");
    var clearBtn   = document.getElementById("ocr-clear-btn");
    var filledEl   = document.getElementById("ocr-filled-fields");

    fileInput.addEventListener("change", function() {
      if (!this.files || !this.files[0]) return;
      var file = this.files[0];
      imgEl.src = URL.createObjectURL(file);
      idleEl.style.display    = "none";
      previewEl.style.display = "block";
      clearBtn.style.display  = "inline-flex";
      resultEl.style.display  = "none";
      errorEl.style.display   = "none";
      runOCR(file);
    });

    window.ocrClear = function() {
      fileInput.value          = "";
      imgEl.src                = "";
      idleEl.style.display     = "block";
      previewEl.style.display  = "none";
      clearBtn.style.display   = "none";
      progressEl.style.display = "none";
      resultEl.style.display   = "none";
      errorEl.style.display    = "none";
      document.querySelectorAll(".ocr-filled").forEach(function(el) { el.classList.remove("ocr-filled"); });
    };

    function parseText(text) {
      var filled = [];

      var plateNearLabel = text.match(/PLATE\s*NO[.\s:]*([A-Z]{2,3}\s*\d{3,4})/i);
      var plateMatch = plateNearLabel || text.match(/\b([A-Z]{3})\s*(\d{3,4})\b/);
      if (plateMatch) {
        var plate = plateNearLabel ? plateNearLabel[1].trim() : (plateMatch[1] + " " + plateMatch[2]);
        var searchEl = document.getElementById("client_search");
        if (searchEl && !searchEl.value) {
          searchEl.value = plate;
          searchEl.classList.add("ocr-filled");
          searchEl.dispatchEvent(new Event("input", { bubbles: true }));
          filled.push("Plate Number (search triggered)");
        }
      }

      var dRe = /\b(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{4})\b/;
      var dMatch = text.match(dRe);
      if (dMatch) {
        var iso = dMatch[3] + "-" + dMatch[1].padStart(2,"0") + "-" + dMatch[2].padStart(2,"0");
        if (!isNaN(Date.parse(iso))) {
          var incidentEl = document.querySelector("[name=incident_date]");
          if (incidentEl && !incidentEl.value) {
            incidentEl.value = iso;
            incidentEl.classList.add("ocr-filled");
            filled.push("Incident Date");
          }
        }
      }

      return filled;
    }

    async function runOCR(file) {
      progressEl.style.display = "block";
      barEl.style.width = "20%";
      statusEl.textContent = "Uploading image…";
      try {
        var formData = new FormData();
        formData.append("image", file);

        barEl.style.width = "50%";
        statusEl.textContent = "Reading document…";
        var res = await fetch("ocr_scan.php", { method: "POST", body: formData });
        var data = await res.json();

        barEl.style.width = "100%";
        progressEl.style.display = "none";

        if (data.error) {
          document.getElementById("ocr-error-msg").textContent = data.error;
          errorEl.style.display = "block";
          return;
        }

        var filled = parseText(data.text);
        if (filled.length > 0) {
          filledEl.textContent = "Detected: " + filled.join(", ") + ". Please verify before filing.";
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
';
require_once '../../includes/footer.php';
?>
<script>
const savedClient       = '<?= (int)($_POST['client_id'] ?? $prefill_client_id) ?>';
const savedPolicy       = '<?= (int)($_POST['policy_id'] ?? 0) ?>';
const prefillClientId   = <?= $prefill_client_id ?>;
const prefillClientName = <?= json_encode($prefill_client_name) ?>;
</script>
<script src="../../assets/js/shared/add_claim.js"></script>
