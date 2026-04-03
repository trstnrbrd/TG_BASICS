<?php
require_once __DIR__ . "/../../config/session.php";
require_once '../../config/db.php';

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
          <div class="form-grid-3">

            <!-- Row 1: Plate | Make | Model -->
            <div class="field">
              <label class="field-label">Plate Number <span class="req">*</span></label>
              <input type="text" name="plate_number" class="field-input"
                placeholder="e.g. ABC 1234"
                value="<?= htmlspecialchars($_POST['plate_number'] ?? '') ?>"
                style="text-transform:uppercase;" autofocus/>
            </div>
            <div class="field">
              <label class="field-label">Make <span class="req">*</span></label>
              <input type="text" name="make" class="field-input"
                placeholder="e.g. Toyota"
                value="<?= htmlspecialchars($_POST['make'] ?? '') ?>"/>
            </div>
            <div class="field">
              <label class="field-label">Model <span class="req">*</span></label>
              <input type="text" name="model" class="field-input"
                placeholder="e.g. Innova"
                value="<?= htmlspecialchars($_POST['model'] ?? '') ?>"/>
            </div>

            <!-- Row 2: Year | Color (2-col) -->
            <div class="field">
              <label class="field-label">Year Model <span class="req">*</span></label>
              <input type="number" name="year_model" class="field-input"
                min="1990" max="<?= date('Y') + 1 ?>"
                placeholder="e.g. 2020"
                value="<?= htmlspecialchars($_POST['year_model'] ?? '') ?>"/>
            </div>
            <div class="field span-2">
              <label class="field-label">Color</label>
              <input type="text" name="color" class="field-input"
                placeholder="e.g. Pearl White"
                value="<?= htmlspecialchars($_POST['color'] ?? '') ?>"/>
            </div>

            <!-- Row 3: Engine Number (full width) -->
            <div class="field span-3">
              <label class="field-label">Engine Number <span class="req">*</span></label>
              <input type="text" name="motor_number" class="field-input"
                placeholder="e.g. 2TR1234567"
                value="<?= htmlspecialchars($_POST['motor_number'] ?? '') ?>"
                style="text-transform:uppercase;"/>
              <div class="field-hint">Found on the vehicle registration / OR-CR. Required for insurance eligibility.</div>
            </div>

            <!-- Row 4: Chassis Number (full width) -->
            <div class="field span-3">
              <label class="field-label">Chassis Number <span class="req">*</span></label>
              <input type="text" name="serial_number" class="field-input"
                placeholder="e.g. MHF11KH40P0123456"
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

<?php require_once '../../includes/footer.php'; ?>