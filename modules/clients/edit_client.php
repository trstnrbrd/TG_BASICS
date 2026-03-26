<?php
session_start();
require_once '../../config/db.php';

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'super_admin'])) {
    header("Location: ../../auth/login.php");
    exit;
}

$client_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
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

$errors  = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $full_name      = trim($_POST['full_name'] ?? '');
    $contact_number = trim($_POST['contact_number'] ?? '');
    $email          = trim($_POST['email'] ?? '');
    $address        = trim($_POST['address'] ?? '');

    if ($full_name === '')      $errors[] = 'Full name is required.';
    if ($contact_number === '') $errors[] = 'Contact number is required.';
    if ($address === '')        $errors[] = 'Address is required.';

    if (empty($errors)) {
        $upd = $conn->prepare("UPDATE clients SET full_name = ?, contact_number = ?, email = ?, address = ? WHERE client_id = ?");
        $upd->bind_param('ssssi', $full_name, $contact_number, $email, $address, $client_id);
        if ($upd->execute()) {
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
    $client['address']        = $address;
}

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

    <div class="page-header">
      <div class="page-header-title"><?= icon('pencil', 18) ?> Edit Client Information</div>
      <div class="page-header-sub">Update the personal details of <?= htmlspecialchars($client['full_name']) ?>.</div>
    </div>

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
      <div class="card">
        <div class="card-header">
          <div class="card-icon"><?= icon('user', 16) ?></div>
          <div>
            <div class="card-title">Client Information</div>
            <div class="card-sub">Fields marked <span style="color:var(--gold-bright);">*</span> are required</div>
          </div>
        </div>
        <div style="padding:1.5rem;">
          <div class="form-grid">
            <div class="field">
              <label class="field-label">Full Name <span class="req">*</span></label>
              <input type="text" name="full_name" class="field-input"
                placeholder="e.g. Juan dela Cruz"
                value="<?= htmlspecialchars($client['full_name']) ?>"/>
            </div>
            <div class="field">
              <label class="field-label">Contact Number <span class="req">*</span></label>
              <input type="text" name="contact_number" class="field-input"
                placeholder="e.g. 09xxxxxxxxx"
                value="<?= htmlspecialchars($client['contact_number']) ?>"/>
            </div>
            <div class="field">
              <label class="field-label">Email Address</label>
              <input type="email" name="email" class="field-input"
                placeholder="e.g. juan@email.com"
                value="<?= htmlspecialchars($client['email'] ?? '') ?>"/>
            </div>
            <div class="field">
              <label class="field-label">Address <span class="req">*</span></label>
              <input type="text" name="address" class="field-input"
                placeholder="e.g. San Roque, Pandi, Bulacan"
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