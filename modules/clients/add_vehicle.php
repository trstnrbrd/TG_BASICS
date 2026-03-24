<?php
session_start();
require_once '../../config/db.php';

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'super_admin'])) {
    header("Location: ../../login.php");
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
    $plate_number  = strtoupper(trim($_POST['plate_number'] ?? ''));
    $make          = trim($_POST['make'] ?? '');
    $model         = trim($_POST['model'] ?? '');
    $year_model    = trim($_POST['year_model'] ?? '');
    $color         = trim($_POST['color'] ?? '');
    $motor_number  = trim($_POST['motor_number'] ?? '');
    $serial_number = trim($_POST['serial_number'] ?? '');

    if ($plate_number === '')  $errors[] = 'Plate number is required.';
    if ($make === '')          $errors[] = 'Vehicle make is required.';
    if ($model === '')         $errors[] = 'Vehicle model is required.';
    if ($year_model === '')    $errors[] = 'Year model is required.';
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
        $ins = $conn->prepare("INSERT INTO vehicles (client_id, plate_number, make, model, year_model, color, motor_number, serial_number) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $ins->bind_param('isssssss', $client_id, $plate_number, $make, $model, $year_model, $color, $motor_number, $serial_number);
        if ($ins->execute()) {
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

    <div class="page-header">
      <div class="page-header-title"><?= icon('vehicle', 18) ?> Add New Vehicle</div>
      <div class="page-header-sub">Adding a vehicle for <?= htmlspecialchars($client['full_name']) ?>.</div>
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

    <form method="POST" action="">
      <div class="card">
        <div class="card-header">
          <div class="card-icon"><?= icon('vehicle', 16) ?></div>
          <div>
            <div class="card-title">Vehicle Details</div>
            <div class="card-sub">Fields marked <span style="color:var(--gold-bright);">*</span> are required</div>
          </div>
        </div>
        <div style="padding:1.5rem;">
          <div class="form-grid">
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
                placeholder="Pearl White"
                value="<?= htmlspecialchars($_POST['color'] ?? '') ?>"/>
            </div>
            <div class="field">
              <label class="field-label">Engine Number</label>
              <input type="text" name="motor_number" class="field-input"
                "
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
          <a href="view_client.php?id=<?= $client_id ?>" class="btn-ghost"><?= icon('arrow-left', 14) ?> Cancel</a>
          <button type="submit" class="btn-primary"><?= icon('floppy-disk', 14) ?> Save Vehicle</button>
        </div>
      </div>
    </form>

  </div>
</div>

<?php require_once '../../includes/footer.php'; ?>