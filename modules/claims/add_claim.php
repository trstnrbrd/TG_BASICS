<?php
require_once __DIR__ . "/../../config/session.php";
require_once '../../config/db.php';
require_once '../../includes/icons.php';

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'super_admin'])) {
    header("Location: ../../auth/login.php");
    exit;
}

$errors  = [];
$success = false;

// Load all clients for dropdown
$clients_res = $conn->query("SELECT client_id, full_name FROM clients ORDER BY full_name ASC");
$clients = [];
while ($c = $clients_res->fetch_assoc()) $clients[] = $c;

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
    $claim_type   = trim($_POST['claim_type'] ?? '');
    $incident_date = trim($_POST['incident_date'] ?? '');
    $description  = trim($_POST['description'] ?? '');

    if (!$client_id)      $errors[] = 'Please select a client.';
    if (!$policy_id)      $errors[] = 'Please select a policy.';
    if (!$claim_type)     $errors[] = 'Please select a claim type.';
    if (!$incident_date)  $errors[] = 'Incident date is required.';
    if (!$description)    $errors[] = 'Incident description is required.';

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
        <div class="card" style="margin-bottom:1.25rem;">
          <div class="card-header">
            <div class="card-icon"><?= icon('user', 16) ?></div>
            <div>
              <div class="card-title">Client & Policy</div>
              <div class="card-sub">Select the client and the policy to file against</div>
            </div>
          </div>
          <div class="card-body">
            <div class="form-grid" style="gap:1rem;">

              <div class="field">
                <label class="field-label">Client <span style="color:var(--danger);">*</span></label>
                <select name="client_id" id="client_id" class="field-input" required>
                  <option value="">— Select Client —</option>
                  <?php foreach ($clients as $c): ?>
                  <option value="<?= $c['client_id'] ?>" <?= (($_POST['client_id'] ?? '') == $c['client_id']) ? 'selected' : '' ?>>
                    <?= htmlspecialchars($c['full_name']) ?>
                  </option>
                  <?php endforeach; ?>
                </select>
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
                  <option value="minor" <?= (($_POST['claim_type'] ?? '') === 'minor') ? 'selected' : '' ?>>Minor</option>
                  <option value="major" <?= (($_POST['claim_type'] ?? '') === 'major') ? 'selected' : '' ?>>Major / 3rd Party</option>
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
