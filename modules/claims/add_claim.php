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
            VALUES (?, ?, ?, ?, ?, 'document_collection', ?)
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

      <form method="POST" action="">
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

<script>
// PHP-injected vars for add_claim.js
const savedClient = '<?= (int)($_POST['client_id'] ?? 0) ?>';
const savedPolicy = '<?= (int)($_POST['policy_id'] ?? 0) ?>';
</script>
<script src="../../assets/js/shared/add_claim.js"></script>

<?php require_once '../../includes/footer.php'; ?>
