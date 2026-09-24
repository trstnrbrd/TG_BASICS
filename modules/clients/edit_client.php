<?php
require_once __DIR__ . "/../../config/session.php";
require_once '../../config/db.php';
require_once '../../config/validators.php';
require_once '../../config/access.php';

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'super_admin'])) {
    header("Location: ../../auth/login.php");
    exit;
}

$client_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($client_id === 0) {
    header("Location: client_list.php");
    exit;
}

// Only the Owner, the admin who encoded this client, or its insurance agent may change the record
// (any admin can still view it). See config/access.php.
if (!client_editable($conn, $client_id)) {
    header("Location: view_client.php?id=" . $client_id);
    exit;
}

// Load client (exclude soft-deleted) with its current insurance agent
$stmt = $conn->prepare("
    SELECT c.*,
           CASE WHEN ag.is_hidden = 1 THEN 'System Administrator' ELSE ag.full_name END AS agent_name
    FROM clients c
    LEFT JOIN users ag ON ag.user_id = c.agent_id
    WHERE c.client_id = ? AND c.deleted_at IS NULL
");
$stmt->bind_param('i', $client_id);
$stmt->execute();
$client = $stmt->get_result()->fetch_assoc();

if (!$client) {
    header("Location: client_list.php?error=Client not found.");
    exit;
}

// Reassigning a client to another agent moves who may edit its policies and payments, so only the
// Owner can do it here — otherwise an encoder could hand a client (and its payments) to themselves.
$is_super      = $_SESSION['role'] === 'super_admin';
$agents        = $is_super ? insurance_agents($conn) : [];
$cur_agent_id  = $client['agent_id'] !== null ? (int)$client['agent_id'] : 0;

$errors  = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $full_name      = strtoupper(san_str($_POST['full_name'] ?? '', MAX_NAME));
    $contact_number = san_str($_POST['contact_number'] ?? '', MAX_PHONE);
    $email          = san_str($_POST['email'] ?? '', MAX_EMAIL);
    $fb_raw         = is_string($_POST['facebook_name'] ?? null) ? trim($_POST['facebook_name']) : '';
    $facebook_name  = san_str($fb_raw, MAX_FACEBOOK);   // optional
    $address        = san_str($_POST['address'] ?? '', MAX_ADDRESS);
    $agent_id       = $is_super ? san_int($_POST['agent_id'] ?? 0, 1) : $cur_agent_id;

    if ($is_super && $agent_id === 0)                                              $errors[] = 'Please select the insurance agent.';
    elseif ($is_super && !isset($agents[$agent_id]) && $agent_id !== $cur_agent_id) $errors[] = 'The selected insurance agent is not available. Please choose another.';
    if ($full_name === '')                          $errors[] = 'Full name is required.';
    elseif (!validate_name($full_name))             $errors[] = 'Full name contains invalid characters.';
    // Contact number is optional (owner's request, 2026-09-24); one that is given must still be valid
    if ($contact_number !== '' && !validate_phone($contact_number)) $errors[] = 'Contact number must be a valid PH mobile number (09XXXXXXXXX).';
    if ($email !== '' && !validate_email($email))   $errors[] = 'Please enter a valid email address.';
    if (mb_strlen($fb_raw) > MAX_FACEBOOK)          $errors[] = 'Facebook name is too long (max ' . MAX_FACEBOOK . ' characters).';
    if ($address === '')                            $errors[] = 'Address is required.';

    if (empty($errors)) {
        $agent_param = $agent_id > 0 ? $agent_id : null;
        $upd = $conn->prepare("UPDATE clients SET full_name = ?, contact_number = ?, email = ?, facebook_name = ?, address = ?, agent_id = ? WHERE client_id = ?");
        $upd->bind_param('sssssii', $full_name, $contact_number, $email, $facebook_name, $address, $agent_param, $client_id);
        if ($upd->execute()) {
            // Audit log
            $uid = $_SESSION['user_id'];
            $log = $conn->prepare("INSERT INTO audit_logs (user_id, action, description) VALUES (?, 'CLIENT_UPDATED', ?)");
            $desc = ($_SESSION['full_name'] ?? 'Unknown') . ' updated client "' . $full_name . '" (ID ' . $client_id . ').';
            if ($agent_id !== $cur_agent_id) {
                $desc .= ' Insurance agent changed from ' . ($client['agent_name'] ?? 'Unassigned') . ' to ' . $agents[$agent_id]['full_name'] . '.';
            }
            $log->bind_param('is', $uid, $desc);
            $log->execute();

            header("Location: view_client.php?id=" . $client_id . "&success=Client updated successfully.");
            exit;
        } else {
            $errors[] = 'Database error. Please try again.';
        }
    }

    // Re-populate with submitted values on error
    $client['full_name']      = $full_name;
    $client['contact_number'] = $contact_number;
    $client['email']          = $email;
    $client['facebook_name']  = $fb_raw;
    $client['address']        = $address;
    $sel_agent_id             = $agent_id;
}
$sel_agent_id = $sel_agent_id ?? $cur_agent_id;

$page_title  = 'Edit Client';
$active_page = 'clients';
$base_path   = '../../';
require_once '../../includes/header.php';
require_once '../../includes/navbar.php';
?>

<div class="main">

<?php
$topbar_title      = 'Edit Client';
$topbar_breadcrumb = ['Records', 'Clients', 'Edit'];
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

    <form method="POST" action="">
      <?= csrf_field() ?>
      <div class="card">
        <div class="card-header">
          <div class="card-icon"><?= icon('user', 16) ?></div>
          <div>
            <div class="card-title">Client Information</div>
            <div class="card-sub">Fields marked <span style="color:var(--gold-bright);">*</span> are required</div>
          </div>
        </div>
        <div style="padding:1.5rem;">
          <div class="form-grid" style="margin-bottom:1rem;">
            <div class="field">
              <label class="field-label" for="agent_id">Insurance Agent<?= $is_super ? ' <span class="req">*</span>' : '' ?></label>
              <?php if ($is_super): ?>
              <select name="agent_id" id="agent_id" class="field-select">
                <option value="" disabled <?= $sel_agent_id === 0 ? 'selected' : '' ?>>— Select insurance agent —</option>
                <?php foreach ($agents as $aid => $ag): ?>
                <option value="<?= $aid ?>" <?= $sel_agent_id === $aid ? 'selected' : '' ?>><?= htmlspecialchars(agent_option_label($ag)) ?></option>
                <?php endforeach; ?>
                <?php if ($cur_agent_id > 0 && !isset($agents[$cur_agent_id])): /* current agent is no longer active — keep them selectable so saving other fields doesn't silently reassign */ ?>
                <option value="<?= $cur_agent_id ?>" <?= $sel_agent_id === $cur_agent_id ? 'selected' : '' ?>><?= htmlspecialchars(($client['agent_name'] ?? 'Unknown') . ' (inactive)') ?></option>
                <?php endif; ?>
              </select>
              <div class="field-hint">Whose client this is. Only the insurance agent (and you, as Owner) can update this client's policy payments.</div>
              <?php else: ?>
              <input type="text" class="field-input" value="<?= htmlspecialchars($client['agent_name'] ?? 'Unassigned') ?>" disabled/>
              <div class="field-hint">Only the Owner can reassign a client to another agent.</div>
              <?php endif; ?>
            </div>
          </div>
          <div class="form-grid">
            <div class="field">
              <label class="field-label">Full Name <span class="req">*</span></label>
              <input type="text" name="full_name" class="field-input"
                placeholder="FIRST MIDDLE LAST"
                value="<?= htmlspecialchars($client['full_name']) ?>"
                style="text-transform:uppercase;"/>
            </div>
            <div class="field">
              <label class="field-label">Contact Number</label>
              <input type="text" name="contact_number" class="field-input"
                placeholder="09XXXXXXXXX"
                value="<?= htmlspecialchars($client['contact_number'] ?? '') ?>"/>
            </div>
            <div class="field">
              <label class="field-label">Email Address</label>
              <input type="email" name="email" class="field-input"
                placeholder="name@email.com"
                value="<?= htmlspecialchars($client['email'] ?? '') ?>"/>
            </div>
            <div class="field">
              <label class="field-label">Facebook Name</label>
              <input type="text" name="facebook_name" class="field-input" maxlength="<?= MAX_FACEBOOK ?>"
                placeholder="Juan Dela Cruz"
                value="<?= htmlspecialchars($client['facebook_name'] ?? '') ?>"/>
              <div class="field-hint">Optional — the name on the client's Facebook account.</div>
            </div>
            <div class="field span-2">
              <label class="field-label">Address <span class="req">*</span></label>
              <input type="text" name="address" class="field-input"
                placeholder="Street, Barangay, Municipality, Province"
                value="<?= htmlspecialchars($client['address']) ?>"/>
            </div>
          </div>
        </div>
        <div class="form-actions">
          <a href="view_client.php?id=<?= $client_id ?>" class="btn-ghost"><?= icon('arrow-left', 14) ?> Cancel</a>
          <button type="submit" class="btn-primary"><?= icon('floppy-disk', 14) ?> Save Changes</button>
        </div>
      </div>
    </form>

  </div>
</div>

<?php require_once '../../includes/footer.php'; ?>