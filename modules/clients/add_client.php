<?php
session_start();
require_once '../../config/db.php';

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'super_admin'])) {
    header("Location: ../../auth/login.php");
    exit;
}

$full_name_user = $_SESSION['full_name'];
$initials       = substr(implode('', array_map(fn($w) => strtoupper($w[0]), explode(' ', $full_name_user))), 0, 2);

$errors  = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $full_name      = trim($_POST['full_name'] ?? '');
    $contact_number = trim($_POST['contact_number'] ?? '');
    $email          = trim($_POST['email'] ?? '');
    $address        = trim($_POST['address'] ?? '');
    $plate_number   = strtoupper(trim($_POST['plate_number'] ?? ''));
    $make           = trim($_POST['make'] ?? '');
    $model          = trim($_POST['model'] ?? '');
    $year_model     = trim($_POST['year_model'] ?? '');
    $color          = trim($_POST['color'] ?? '');
    $motor_number   = trim($_POST['motor_number'] ?? '');
    $serial_number  = trim($_POST['serial_number'] ?? '');

    if ($full_name === '')      $errors[] = 'Full name is required.';
    if ($contact_number === '') $errors[] = 'Contact number is required.';
    if ($address === '')        $errors[] = 'Address is required.';
    if ($plate_number === '')   $errors[] = 'Plate number is required.';
    if ($make === '')           $errors[] = 'Vehicle make is required.';
    if ($model === '')          $errors[] = 'Vehicle model is required.';
    if ($year_model === '')     $errors[] = 'Year model is required.';
    if ($year_model !== '' && (!is_numeric($year_model) || $year_model < 1990 || $year_model > (int)date('Y') + 1))
        $errors[] = 'Year model must be a valid year.';

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

    <div class="page-header">
      <div class="page-header-title"><?= icon('user', 18) ?> Add New Client</div>
      <div class="page-header-sub">Fill in the client details and their first vehicle. Additional vehicles can be added later.</div>
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

          <div class="field-section">Personal Details</div>
          <div class="form-grid" style="margin-bottom:1rem;">
            <div class="field">
              <label class="field-label">Full Name <span class="req">*</span></label>
              <input type="text" name="full_name" class="field-input"
                placeholder="e.g. Juan dela Cruz"
                value="<?= htmlspecialchars($_POST['full_name'] ?? '') ?>"/>
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
          <div class="form-grid" style="margin-bottom:1rem;">
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
                placeholder="Toyota"
                value="<?= htmlspecialchars($_POST['make'] ?? '') ?>"/>
            </div>
            <div class="field">
              <label class="field-label">Model <span class="req">*</span></label>
              <input type="text" name="model" class="field-input"
                placeholder="Innova"
                value="<?= htmlspecialchars($_POST['model'] ?? '') ?>"/>
            </div>
            <div class="field">
              <label class="field-label">Year Model <span class="req">*</span></label>
              <input type="number" name="year_model" class="field-input"
               min="1990" max="<?= date('Y') + 1 ?>"
                value="<?= htmlspecialchars($_POST['year_model'] ?? '') ?>"/>
            </div>
            <div class="field">
              <label class="field-label">Color</label>
              <input type="text" name="color" class="field-input"
                placeholder="e.g. Pearl White"
                value="<?= htmlspecialchars($_POST['color'] ?? '') ?>"/>
            </div>
            <div class="field">
              <label class="field-label">Engine Number</label>
              <input type="text" name="motor_number" class="field-input"
                value="<?= htmlspecialchars($_POST['motor_number'] ?? '') ?>"/>
            </div>
            <div class="field span-2">
              <label class="field-label">Chassis Number</label>
              <input type="text" name="serial_number" class="field-input"
                value="<?= htmlspecialchars($_POST['serial_number'] ?? '') ?>"/>
            </div>
          </div>

        </div>
        <div class="form-actions">
          <a href="client_list.php" class="btn-ghost"><?= icon('arrow-left', 14) ?> Cancel</a>
          <button type="submit" class="btn-primary"><?= icon('floppy-disk', 14) ?> Save Client</button>
        </div>
      </div>
    </form>

  </div>
</div>

<?php require_once '../../includes/footer.php'; ?>