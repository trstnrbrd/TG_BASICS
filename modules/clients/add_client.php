<?php
require_once __DIR__ . "/../../config/session.php";
require_once '../../config/db.php';
require_once '../../config/validators.php';
require_once '../../config/access.php';

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'super_admin'])) {
    header("Location: ../../auth/login.php");
    exit;
}

// Same-name check (AJAX, when the Full Name field is left): a warning, never a block — see same_name_clients()
if (isset($_GET['check_name'])) {
    header('Content-Type: application/json');
    $name = is_string($_GET['check_name']) ? san_str($_GET['check_name'], MAX_NAME) : '';
    echo json_encode(['matches' => same_name_clients($conn, $name)]);
    exit;
}

$full_name_user = $_SESSION['full_name'];
$initials       = substr(implode('', array_map(fn($w) => strtoupper($w[0]), explode(' ', $full_name_user))), 0, 2);

// Insurance agent = whose client this is; it can differ from whoever encodes it ("added by").
// Defaults to the person encoding, when they are an agent themselves.
$agents           = insurance_agents($conn);
$default_agent_id = isset($agents[(int)$_SESSION['user_id']]) ? (int)$_SESSION['user_id'] : 0;

$errors      = [];
$fieldErrors = [];
$success     = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $full_name      = strtoupper(san_str($_POST['full_name'] ?? '', MAX_NAME));
    $contact_number = san_str($_POST['contact_number'] ?? '', MAX_PHONE);
    $email          = san_str($_POST['email'] ?? '', MAX_EMAIL);
    $fb_raw         = is_string($_POST['facebook_name'] ?? null) ? trim($_POST['facebook_name']) : '';
    $facebook_name  = san_str($fb_raw, MAX_FACEBOOK);   // optional (owner's request, 2026-09-24)
    $address        = san_str($_POST['address'] ?? '', MAX_ADDRESS);
    $plate_number   = strtoupper(san_str($_POST['plate_number'] ?? '', MAX_PLATE));
    $make           = san_str($_POST['make'] ?? '', MAX_MAKE_MODEL);
    $model          = san_str($_POST['model'] ?? '', MAX_MAKE_MODEL);
    $year_model     = san_int($_POST['year_model'] ?? 0, 1960, (int)date('Y') + 1);
    $color          = san_str($_POST['color'] ?? '', MAX_COLOR);
    $motor_number   = san_str($_POST['motor_number'] ?? '', MAX_MOTOR_SN);
    $serial_number  = san_str($_POST['serial_number'] ?? '', MAX_MOTOR_SN);
    $consent_signed = isset($_POST['consent_signed']) && $_POST['consent_signed'] === '1';
    $agent_id       = san_int($_POST['agent_id'] ?? 0, 1);
    // "Save & Check Eligibility" button: after saving, go straight to the eligibility check for the new vehicle
    $then_policy    = ($_POST['after_save'] ?? '') === 'policy';

    $addError = function(string $field, string $msg) use (&$errors, &$fieldErrors) {
        $errors[] = $msg;
        if (!isset($fieldErrors[$field])) $fieldErrors[$field] = $msg;
    };

    // Contact, engine and chassis numbers are optional (owner's request, 2026-09-24) — a contact number
    // that IS given must still be a valid PH mobile number.
    if ($agent_id === 0)                            $addError('agent_id', 'Please select the insurance agent.');
    elseif (!isset($agents[$agent_id]))             $addError('agent_id', 'The selected insurance agent is not available. Please choose another.');
    if ($full_name === '')                          $addError('full_name', 'Full name is required.');
    elseif (!validate_name($full_name))             $addError('full_name', 'Full name contains invalid characters.');
    if ($contact_number !== '' && !validate_phone($contact_number)) $addError('contact_number', 'Contact number must be a valid PH mobile number (09XXXXXXXXX).');
    if ($email !== '' && !validate_email($email))   $addError('email', 'Please enter a valid email address.');
    if (mb_strlen($fb_raw) > MAX_FACEBOOK)          $addError('facebook_name', 'Facebook name is too long (max ' . MAX_FACEBOOK . ' characters).');
    if ($address === '')                            $addError('address', 'Address is required.');
    if ($plate_number === '')                       $addError('plate_number', 'Plate number is required.');
    elseif (!validate_plate($plate_number))         $addError('plate_number', 'Plate number contains invalid characters.');
    if ($make === '')                               $addError('make', 'Vehicle make is required.');
    if ($model === '')                              $addError('model', 'Vehicle model is required.');
    if ($year_model === 0)                          $addError('year_model', 'Year model must be a valid year (1960–' . ((int)date('Y') + 1) . ').');
    if (!$consent_signed)                           $addError('consent_signed', 'Please confirm that the client has signed the printed Data Privacy Consent Form.');

    // The plate-uniqueness check and the insert used to be two separate, unsynchronized queries — two people
    // (or one impatient double-click) submitting the same plate at the same instant could both pass the
    // check before either had inserted, creating two clients with the same vehicle. A MySQL named lock
    // scoped to this exact plate number now holds the check AND the insert together, so only one submission
    // for a given plate is ever "in the check" at a time — see with_named_lock() for the confirmed repro.
    if (empty($errors) && $plate_number !== '') {
        $lock = with_named_lock($conn, 'plate_' . $plate_number, function () use ($conn, $plate_number, $full_name, $contact_number, $email, $facebook_name, $address, $make, $model, $year_model, $color, $motor_number, $serial_number, $agent_id) {
            $check = $conn->prepare("SELECT v.vehicle_id FROM vehicles v INNER JOIN clients c ON v.client_id = c.client_id WHERE v.plate_number = ? AND c.deleted_at IS NULL");
            $check->bind_param('s', $plate_number);
            $check->execute();
            if ($check->get_result()->num_rows > 0) return null;   // duplicate — caller adds the field error

            $created_by = (int)$_SESSION['user_id'];
            $ins_client = $conn->prepare("INSERT INTO clients (full_name, contact_number, email, facebook_name, address, created_by, agent_id, consent_signed_at, consent_recorded_by) VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), ?)");
            $ins_client->bind_param('sssssiii', $full_name, $contact_number, $email, $facebook_name, $address, $created_by, $agent_id, $created_by);
            $ins_client->execute();
            $client_id = $conn->insert_id;

            $tok = $conn->prepare("UPDATE clients SET public_token = SHA2(CONCAT(?, UUID(), 'tgbasics'), 256) WHERE client_id = ?");
            $tok->bind_param('ii', $client_id, $client_id);
            $tok->execute();

            $ins_vehicle = $conn->prepare("INSERT INTO vehicles (client_id, plate_number, make, model, year_model, color, motor_number, serial_number) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $ins_vehicle->bind_param('isssssss', $client_id, $plate_number, $make, $model, $year_model, $color, $motor_number, $serial_number);
            $ins_vehicle->execute();

            return ['client_id' => $client_id, 'vehicle_id' => $conn->insert_id];
        });

        if (!$lock['locked']) {
            $addError('plate_number', 'The system is busy processing this plate number. Please try again in a moment.');
        } elseif ($lock['result'] === null) {
            $addError('plate_number', 'Plate number ' . $plate_number . ' already exists in the system.');
        } else {
            $client_id  = $lock['result']['client_id'];
            $vehicle_id = $lock['result']['vehicle_id'];

            // Audit log
            $uid = $_SESSION['user_id'];
            $log = $conn->prepare("INSERT INTO audit_logs (user_id, action, description) VALUES (?, 'CLIENT_ADDED', ?)");
            $desc = ($_SESSION['full_name'] ?? 'Unknown') . ' added client "' . $full_name . '" with vehicle ' . ($plate_number ?: 'no plate') . ' (' . $make . ' ' . $model . ')'
                  . ($agent_id !== (int)$uid ? ' for insurance agent ' . $agents[$agent_id]['full_name'] : '') . '.';
            $log->bind_param('is', $uid, $desc);
            $log->execute();

            if ($then_policy) {
                header("Location: ../insurance/eligibility_check.php?vehicle_id=" . (int)$vehicle_id . "&added=1");
                exit;
            }
            // The list opens on the user's own clients — when this client is another agent's, open that agent's
            // list instead so the new record is actually in view
            $list_agent = $agent_id !== (int)$_SESSION['user_id'] ? 'agent=' . $agent_id . '&' : '';
            header("Location: client_list.php?" . $list_agent . "success=" . urlencode($full_name . ' has been added successfully.'));
            exit;
        }
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



    <!-- OCR MODAL -->
    <div id="ocr-modal" style="display:none;position:fixed;inset:0;z-index:1000;background:rgba(0,0,0,0.55);backdrop-filter:blur(2px);align-items:center;justify-content:center;padding:1rem;" onclick="if(event.target===this)ocrModalClose()">
      <div style="background:var(--bg-2);border:1px solid var(--border);border-radius:16px;width:100%;max-width:480px;box-shadow:var(--shadow-lg);animation:ocr-modal-in 0.18s ease;">
        <div style="display:flex;align-items:center;justify-content:space-between;padding:1rem 1.25rem;border-bottom:1px solid var(--border);">
          <div style="display:flex;align-items:center;gap:0.6rem;">
            <span style="color:var(--gold-bright);"><?= icon('magnifying-glass', 16) ?></span>
            <span style="font-weight:700;font-size:0.9rem;color:var(--text-primary);">Scan OR / CR / Policy</span>
            <span style="font-size:0.65rem;font-weight:700;color:var(--gold-bright);background:var(--gold-pale);border:1px solid var(--gold-bright);border-radius:6px;padding:0.1rem 0.4rem;">OCR</span>
          </div>
          <button type="button" onclick="ocrModalClose()" aria-label="Close" style="background:none;border:none;cursor:pointer;color:var(--text-muted);padding:0.25rem;"><?= icon('x-mark', 16) ?></button>
        </div>
        <div style="padding:1.25rem;">
          <div id="ocr-upload-area" role="button" tabindex="0" style="border:2px dashed var(--border);border-radius:12px;padding:1.5rem;text-align:center;cursor:pointer;transition:border-color 0.15s;" onclick="document.getElementById('ocr-file-input').click()" onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();document.getElementById('ocr-file-input').click();}">
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
    <!-- PRIVACY NOTICE MODAL -->
    <div id="privacy-modal" style="display:none;position:fixed;inset:0;z-index:1000;background:rgba(0,0,0,0.55);backdrop-filter:blur(2px);align-items:center;justify-content:center;padding:1rem;" onclick="if(event.target===this)privacyModalClose()">
      <div style="background:var(--bg-2);border:1px solid var(--border);border-radius:16px;width:100%;max-width:640px;height:85vh;max-height:720px;box-shadow:var(--shadow-lg);animation:ocr-modal-in 0.18s ease;display:flex;flex-direction:column;">
        <div style="display:flex;align-items:center;justify-content:space-between;padding:1rem 1.25rem;border-bottom:1px solid var(--border);flex-shrink:0;">
          <div style="display:flex;align-items:center;gap:0.6rem;">
            <span style="color:var(--gold-bright);"><?= icon('shield-check', 16) ?></span>
            <span style="font-weight:700;font-size:0.9rem;color:var(--text-primary);">Privacy Notice</span>
          </div>
          <button type="button" onclick="privacyModalClose()" aria-label="Close" style="background:none;border:none;cursor:pointer;color:var(--text-muted);padding:0.25rem;"><?= icon('x-mark', 16) ?></button>
        </div>
        <iframe id="privacy-modal-iframe" src="" style="flex:1;width:100%;border:none;border-radius:0 0 16px 16px;background:#fff;"></iframe>
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
    var _privacyIframeLoaded = false;
    function privacyModalOpen() {
      var m = document.getElementById("privacy-modal");
      var f = document.getElementById("privacy-modal-iframe");
      if (f && !_privacyIframeLoaded) { f.src = "../public/privacy_notice.php"; _privacyIframeLoaded = true; }
      if (m) m.style.display = "flex";
    }
    function privacyModalClose() { var m = document.getElementById("privacy-modal"); if (m) m.style.display = "none"; }
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

          <div class="field-section">Insurance Agent</div>
          <div class="form-grid" style="margin-bottom:1rem;">
            <div class="field">
              <label class="field-label" for="agent_id">Insurance Agent <span class="req">*</span></label>
              <?php $sel_agent = isset($_POST['agent_id']) ? (int)$_POST['agent_id'] : $default_agent_id; ?>
              <select name="agent_id" id="agent_id" class="field-select" data-default="<?= $default_agent_id ?: '' ?>">
                <option value="" disabled <?= isset($agents[$sel_agent]) ? '' : 'selected' ?>>— Select insurance agent —</option>
                <?php foreach ($agents as $aid => $ag): ?>
                <option value="<?= $aid ?>" <?= $sel_agent === $aid ? 'selected' : '' ?>><?= htmlspecialchars(agent_option_label($ag)) ?></option>
                <?php endforeach; ?>
              </select>
              <div class="field-hint">Whose client this is. You can add a client for another agent — you are still recorded as the one who added it.</div>
            </div>
          </div>

          <div class="field-section">Personal Details</div>
          <div class="form-grid" style="margin-bottom:1rem;">
            <div class="field">
              <label class="field-label">Full Name <span class="req">*</span></label>
              <input type="text" name="full_name" class="field-input"
                placeholder="FIRST MIDDLE LAST"
                value="<?= htmlspecialchars($_POST['full_name'] ?? '') ?>"
                style="text-transform:uppercase;"
                autofocus/>
              <div id="dup-name-warning" role="status" hidden
                style="margin-top:0.4rem;padding:0.5rem 0.7rem;border-radius:8px;background:var(--warning-bg);border:1px solid var(--warning-border, var(--border));color:var(--warning);font-size:0.74rem;line-height:1.5;"></div>
            </div>
            <div class="field">
              <label class="field-label">Contact Number</label>
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
              <label class="field-label">Facebook Name</label>
              <input type="text" name="facebook_name" class="field-input" maxlength="<?= MAX_FACEBOOK ?>"
                placeholder="Juan Dela Cruz"
                value="<?= htmlspecialchars(is_string($_POST['facebook_name'] ?? null) ? $_POST['facebook_name'] : '') ?>"/>
              <div class="field-hint">Optional — the name on the client's Facebook account, to message them there.</div>
            </div>
            <div class="field span-2">
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
              <label class="field-label">Engine Number</label>
              <input type="text" name="motor_number" class="field-input"
                placeholder="Alphanumeric, from OR-CR"
                value="<?= htmlspecialchars($_POST['motor_number'] ?? '') ?>"
                style="text-transform:uppercase;"/>
              <div class="field-hint">Found on the vehicle registration / OR-CR. Optional — can be added later from the vehicle's Edit page.</div>
            </div>

            <!-- Row 4: Chassis Number (full width) -->
            <div class="field span-3">
              <label class="field-label">Chassis Number</label>
              <input type="text" name="serial_number" class="field-input"
                placeholder="17-character VIN"
                value="<?= htmlspecialchars($_POST['serial_number'] ?? '') ?>"
                style="text-transform:uppercase;"/>
              <div class="field-hint">17-character VIN / chassis number from the OR-CR. Optional — can be added later.</div>
            </div>

          </div>

          <div class="field-section">Data Privacy Consent</div>
          <div class="field" style="margin-bottom:0;">
            <label style="display:flex;align-items:flex-start;gap:0.6rem;cursor:pointer;font-size:0.82rem;color:var(--text-secondary);line-height:1.5;">
              <input type="checkbox" name="consent_signed" value="1" id="consent-signed-checkbox" style="margin-top:0.2rem;width:16px;height:16px;flex-shrink:0;accent-color:var(--gold-bright);"/>
              <span>The client has signed the printed Data Privacy Consent Form on file, authorizing TG Customworks &amp; Basic Car Insurance to collect and process their personal data in accordance with the <a href="#" onclick="event.preventDefault(); privacyModalOpen();" style="color:var(--gold-bright);font-weight:600;">Privacy Notice</a> (RA 10173). <span class="req">*</span></span>
            </label>
          </div>

        </div>
        <div class="form-actions">
          <input type="hidden" name="after_save" id="after-save" value=""/>
          <button type="button" class="btn-ghost" id="clear-form-btn"><?= icon('x-mark', 14) ?> Clear</button>
          <button type="submit" class="btn-primary" data-after-save=""><?= icon('floppy-disk', 14) ?> Save Client</button>
          <button type="submit" class="btn-gold" data-after-save="policy" title="Save this client, then check the vehicle's insurance eligibility and create the policy"><?= icon('shield-check', 14) ?> Save &amp; Check Eligibility</button>
        </div>
      </div>
    </form>

  </div>
</div>

<script>var _ACServerErrors = <?= json_encode($fieldErrors) ?>;</script>

<?php
$footer_scripts = '';
$footer_extra_scripts = <<<'ADDCLIENT_SCRIPT'
<script>
var _EXCL_ICON = '<svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>';

function showFieldError(el, message) {
  if (!el) return;
  el.classList.add('is-error');
  var wrap = el.closest('.field');
  if (!wrap) return;
  var old = wrap.querySelector('.field-error-msg');
  if (old) old.remove();
  var d = document.createElement('div');
  d.className = 'field-error-msg';
  d.innerHTML = _EXCL_ICON + '<span>' + message + '</span>';
  wrap.appendChild(d);
}

function clearFieldError(el) {
  if (!el) return;
  el.classList.remove('is-error');
  var wrap = el.closest('.field');
  if (!wrap) return;
  var m = wrap.querySelector('.field-error-msg');
  if (m) m.remove();
}

function validateAddClientForm() {
  // Contact, engine and chassis numbers are optional (a contact number that is given is still checked below)
  var required = [
    { name: 'agent_id',       msg: 'Please select the insurance agent.' },
    { name: 'full_name',      msg: 'Full name is required.' },
    { name: 'address',        msg: 'Address is required.' },
    { name: 'plate_number',   msg: 'Plate number is required.' },
    { name: 'make',           msg: 'Vehicle make is required.' },
    { name: 'model',          msg: 'Vehicle model is required.' },
    { name: 'year_model',     msg: 'Year model is required.' }
  ];
  var ok = true;
  var firstErrEl = null;

  required.forEach(function(f) {
    var el = document.querySelector('[name="' + f.name + '"]');
    if (!el) return;
    clearFieldError(el);
    if (!el.value.trim()) {
      showFieldError(el, f.msg);
      if (!firstErrEl) firstErrEl = el;
      ok = false;
    }
  });

  var phoneEl = document.querySelector('[name="contact_number"]');
  if (phoneEl && phoneEl.value.trim() && !/^09\d{9}$/.test(phoneEl.value.trim())) {
    clearFieldError(phoneEl);
    showFieldError(phoneEl, 'Contact number must be a valid PH mobile number (09XXXXXXXXX).');
    if (!firstErrEl) firstErrEl = phoneEl;
    ok = false;
  }

  var emailEl = document.querySelector('[name="email"]');
  if (emailEl) {
    clearFieldError(emailEl);
    var ev = emailEl.value.trim();
    if (ev && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(ev)) {
      showFieldError(emailEl, 'Please enter a valid email address.');
      if (!firstErrEl) firstErrEl = emailEl;
      ok = false;
    }
  }

  var yearEl = document.querySelector('[name="year_model"]');
  if (yearEl && yearEl.value.trim()) {
    var yr = parseInt(yearEl.value.trim());
    var curYear = new Date().getFullYear();
    if (isNaN(yr) || yr < 1960 || yr > curYear + 1) {
      clearFieldError(yearEl);
      showFieldError(yearEl, 'Year model must be between 1960 and ' + (curYear + 1) + '.');
      if (!firstErrEl) firstErrEl = yearEl;
      ok = false;
    }
  }

  var consentEl = document.getElementById('consent-signed-checkbox');
  if (consentEl && !consentEl.checked) {
    var consentWrap = consentEl.closest('.field');
    if (consentWrap) {
      var oldMsg = consentWrap.querySelector('.field-error-msg');
      if (oldMsg) oldMsg.remove();
      var msg = document.createElement('div');
      msg.className = 'field-error-msg';
      msg.innerHTML = _EXCL_ICON + '<span>Please confirm that the client has signed the printed Data Privacy Consent Form.</span>';
      consentWrap.appendChild(msg);
    }
    if (!firstErrEl) firstErrEl = consentEl;
    ok = false;
  }

  if (firstErrEl) firstErrEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
  return ok;
}

(function() {
  // Apply server-side errors on page load
  document.addEventListener('DOMContentLoaded', function() {
    if (typeof _ACServerErrors === 'object') {
      var first = null;
      Object.keys(_ACServerErrors).forEach(function(name) {
        var el = document.querySelector('[name="' + name + '"]');
        if (el) {
          showFieldError(el, _ACServerErrors[name]);
          if (!first) first = el;
        }
      });
      if (first) first.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }
  });

  // Clear error on input/change
  document.querySelectorAll('.field-input, .field-select').forEach(function(el) {
    el.addEventListener('input', function() { clearFieldError(this); });
    el.addEventListener('change', function() { clearFieldError(this); });
  });
  var consentCb = document.getElementById('consent-signed-checkbox');
  if (consentCb) consentCb.addEventListener('change', function() { clearFieldError(this); });
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
          var fieldNames = ["full_name","contact_number","email","facebook_name","address","plate_number","make","model","year_model","color","motor_number","serial_number"];
          fieldNames.forEach(function(name) {
            var el = document.querySelector("[name=" + name + "]");
            if (el) { el.value = ""; el.classList.remove("ocr-filled"); clearFieldError(el); }
          });
          document.querySelectorAll(".ocr-filled").forEach(function(el) { el.classList.remove("ocr-filled"); });
          var consentReset = document.getElementById("consent-signed-checkbox");
          if (consentReset) { consentReset.checked = false; clearFieldError(consentReset); }
          var agentReset = document.getElementById("agent_id");
          if (agentReset) { agentReset.value = agentReset.dataset.default || ""; clearFieldError(agentReset); }
        }
      });
    });
  }

  var theForm = document.querySelector("form");
  // Same-name warning: when the Full Name field is left, list existing clients with exactly that name (a
  // warning only — two people can share a name). Built with textContent, so names can only ever be text.
  var dupMatches = [];
  var nameEl = document.querySelector("[name=full_name]");
  var dupBox = document.getElementById("dup-name-warning");
  var lastChecked = null, pendingCheck = Promise.resolve();
  // Returns a promise, so Save can wait for the answer before showing its confirmation
  function checkSameName() {
    var name = nameEl.value.trim().replace(/\s+/g, " ").toUpperCase();
    if (name === lastChecked) return pendingCheck;
    lastChecked = name;
    if (!name) { dupMatches = []; dupBox.hidden = true; return (pendingCheck = Promise.resolve()); }
    return (pendingCheck = fetch("add_client.php?check_name=" + encodeURIComponent(name), { credentials: "same-origin" })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (nameEl.value.trim().replace(/\s+/g, " ").toUpperCase() !== name) return;   // typed on meanwhile
        dupMatches = (d && d.matches) || [];
        dupBox.textContent = "";
        if (!dupMatches.length) { dupBox.hidden = true; return; }
        var head = document.createElement("strong");
        head.textContent = dupMatches.length === 1 ? "A client with this name already exists: " : dupMatches.length + " clients with this name already exist: ";
        dupBox.appendChild(head);
        dupMatches.forEach(function (m, i) {
          if (i) dupBox.appendChild(document.createTextNode(", "));
          var a = document.createElement("a");
          a.href = "view_client.php?id=" + encodeURIComponent(m.client_id);
          a.target = "_blank"; a.rel = "noopener";
          a.style.color = "inherit"; a.style.fontWeight = "700";
          a.textContent = m.full_name + (m.plates ? " (" + m.plates + ")" : "");
          dupBox.appendChild(a);
        });
        dupBox.appendChild(document.createTextNode(". Make sure this is a different person before saving."));
        dupBox.hidden = false;
      })
      .catch(function () { /* the check is only a helper — saving still works without it */ }));
  }
  if (nameEl && dupBox) {
    nameEl.addEventListener("blur", checkSameName);
    nameEl.addEventListener("change", checkSameName);
    if (nameEl.value.trim()) checkSameName();   // form shown again after an error
  }

  if (theForm) {
    // Which save button was pressed — form.submit() below does not send the button itself, so it is copied
    // into a hidden field (Enter in a text field "clicks" the first one, the plain Save Client)
    var afterSave = document.getElementById("after-save");
    document.querySelectorAll("[data-after-save]").forEach(function(btn) {
      btn.addEventListener("click", function() { afterSave.value = btn.dataset.afterSave; });
    });
    theForm.addEventListener("submit", function(e) {
      e.preventDefault();
      if (!validateAddClientForm()) return;
      var form = this;
      var toPolicy = afterSave.value === "policy";
      // Typed (or OCR-filled) values go into the dialog's HTML — escape them so they can only ever be text
      var esc = function (s) { return String(s).replace(/[&<>"']/g, function (c) { return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c]; }); };
      var val = function (n) { var el = document.querySelector("[name=" + n + "]"); return esc((el && el.value.trim()) || "—"); };
      var agentSel = document.getElementById("agent_id");
      var agent    = esc(agentSel && agentSel.selectedIndex > 0 ? agentSel.options[agentSel.selectedIndex].text : "—");
      (nameEl && dupBox ? checkSameName() : Promise.resolve()).then(function () { Swal.fire({
        icon: "question",
        title: "Confirm Client Details",
        html:
          "<table style=\"width:100%;font-size:0.82rem;text-align:left;border-collapse:collapse;\">" +
          "<tr><td style=\"padding:0.3rem 0.5rem;color:var(--text-muted);width:45%;\">Full Name</td><td style=\"padding:0.3rem 0.5rem;font-weight:700;\">" + val("full_name") + "</td></tr>" +
          (document.querySelector("[name=facebook_name]").value.trim()
            ? "<tr><td style=\"padding:0.3rem 0.5rem;color:var(--text-muted);\">Facebook Name</td><td style=\"padding:0.3rem 0.5rem;font-weight:700;\">" + val("facebook_name") + "</td></tr>" : "") +
          "<tr style=\"background:rgba(0,0,0,0.03);\"><td style=\"padding:0.3rem 0.5rem;color:var(--text-muted);\">Insurance Agent</td><td style=\"padding:0.3rem 0.5rem;font-weight:700;\">" + agent + "</td></tr>" +
          "<tr><td style=\"padding:0.3rem 0.5rem;color:var(--text-muted);\">Plate Number</td><td style=\"padding:0.3rem 0.5rem;font-weight:700;\">" + val("plate_number") + "</td></tr>" +
          "<tr style=\"background:rgba(0,0,0,0.03);\"><td style=\"padding:0.3rem 0.5rem;color:var(--text-muted);\">Vehicle</td><td style=\"padding:0.3rem 0.5rem;font-weight:700;\">" + val("year_model") + " " + val("make") + " " + val("model") + "</td></tr>" +
          "<tr><td style=\"padding:0.3rem 0.5rem;color:var(--text-muted);\">Chassis No.</td><td style=\"padding:0.3rem 0.5rem;font-weight:700;font-family:monospace;\">" + val("serial_number") + "</td></tr>" +
          "</table>" +
          (dupMatches.length
            ? "<p style=\"font-size:0.78rem;color:var(--warning);background:var(--warning-bg);border-radius:8px;padding:0.5rem 0.7rem;margin:0.8rem 0 0;text-align:left;\"><strong>Same name already on file:</strong> " +
              dupMatches.map(function (m) { return esc(m.full_name + (m.plates ? " (" + m.plates + ")" : "")); }).join(", ") + ". Save only if this is a different person.</p>"
            : "") +
          (toPolicy ? "<p style=\"font-size:0.78rem;color:var(--text-muted);margin:0.8rem 0 0;\">After saving, you will go straight to the Eligibility Check for this vehicle.</p>" : ""),
        confirmButtonText: toPolicy ? "Yes, Save &amp; Check Eligibility" : "Yes, Save Client",
        cancelButtonText: "Review Again",
        showCancelButton: true,
        confirmButtonColor: "#B8860B",
        cancelButtonColor: "#6c757d",
        reverseButtons: true
      }).then(function(result) {
        if (result.isConfirmed) { form.submit(); }
      }); });
    });
  }

})();
</script>
ADDCLIENT_SCRIPT;
require_once '../../includes/footer.php';
?>