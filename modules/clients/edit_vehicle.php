<?php
session_start();
require_once '../../config/db.php';

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'super_admin'])) {
    header("Location: ../../auth/login.php");
    exit;
}

$vehicle_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($vehicle_id === 0) {
    header("Location: client_list.php");
    exit;
}

// Load vehicle + client name
$stmt = $conn->prepare("
    SELECT v.*, c.full_name AS client_name
    FROM vehicles v
    INNER JOIN clients c ON v.client_id = c.client_id
    WHERE v.vehicle_id = ?
");
$stmt->bind_param('i', $vehicle_id);
$stmt->execute();
$vehicle = $stmt->get_result()->fetch_assoc();

if (!$vehicle) {
    header("Location: client_list.php?error=Vehicle not found.");
    exit;
}

$client_id = $vehicle['client_id'];
$errors    = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $plate_number  = strtoupper(trim($_POST['plate_number'] ?? ''));
    $make          = trim($_POST['make'] ?? '');
    $model         = trim($_POST['model'] ?? '');
    $year_model    = trim($_POST['year_model'] ?? '');
    $color         = trim($_POST['color'] ?? '');
    $motor_number  = trim($_POST['motor_number'] ?? '');
    $serial_number = trim($_POST['serial_number'] ?? '');

    if ($plate_number === '') $errors[] = 'Plate number is required.';
    if ($make === '')         $errors[] = 'Vehicle make is required.';
    if ($model === '')        $errors[] = 'Vehicle model is required.';
    if ($year_model === '')   $errors[] = 'Year model is required.';
    if ($year_model !== '' && (!is_numeric($year_model) || $year_model < 1990 || $year_model > (int)date('Y') + 1))
        $errors[] = 'Year model must be between 1990 and ' . ((int)date('Y') + 1) . '.';

    // Duplicate plate check — exclude current vehicle
    if ($plate_number !== '') {
        $check = $conn->prepare("SELECT vehicle_id FROM vehicles WHERE plate_number = ? AND vehicle_id != ?");
        $check->bind_param('si', $plate_number, $vehicle_id);
        $check->execute();
        if ($check->get_result()->num_rows > 0)
            $errors[] = 'Plate number ' . $plate_number . ' already exists in the system.';
    }

    if (empty($errors)) {
        $upd = $conn->prepare("
            UPDATE vehicles
            SET plate_number = ?, make = ?, model = ?, year_model = ?,
                color = ?, motor_number = ?, serial_number = ?
            WHERE vehicle_id = ?
        ");
        $upd->bind_param('sssssssi', $plate_number, $make, $model, $year_model, $color, $motor_number, $serial_number, $vehicle_id);

        if ($upd->execute()) {
            $uid  = $_SESSION['user_id'];
            $log  = $conn->prepare("INSERT INTO audit_logs (user_id, action, description) VALUES (?, 'VEHICLE_UPDATED', ?)");
            $desc = ($_SESSION['full_name'] ?? 'Unknown') . ' updated vehicle ' . $plate_number . ' (' . $make . ' ' . $model . ') for client "' . $vehicle['client_name'] . '".';
            $log->bind_param('is', $uid, $desc);
            $log->execute();

            header("Location: view_client.php?id=" . $client_id . "&success=" . urlencode('Vehicle ' . $plate_number . ' updated successfully.'));
            exit;
        } else {
            $errors[] = 'Database error. Please try again.';
        }
    }

    // Repopulate fields on error
    $vehicle['plate_number']  = $plate_number;
    $vehicle['make']          = $make;
    $vehicle['model']         = $model;
    $vehicle['year_model']    = $year_model;
    $vehicle['color']         = $color;
    $vehicle['motor_number']  = $motor_number;
    $vehicle['serial_number'] = $serial_number;
}

$page_title  = 'Edit Vehicle';
$active_page = 'clients';
$base_path   = '../../';
require_once '../../includes/header.php';
require_once '../../includes/navbar.php';
?>

<div class="main">

<?php
$topbar_title      = 'Edit Vehicle';
$topbar_breadcrumb = ['Records', 'Clients', htmlspecialchars($vehicle['client_name']), 'Edit Vehicle'];
require_once '../../includes/topbar.php';
?>

  <div class="content">

    <a href="view_client.php?id=<?= $client_id ?>" class="back-link"><?= icon('arrow-left', 14) ?> Back to Client Profile</a>

    <div class="page-header">
      <div class="page-header-title"><?= icon('pencil', 18) ?> Edit Vehicle</div>
      <div class="page-header-sub">Update vehicle details for <?= htmlspecialchars($vehicle['client_name']) ?>.</div>
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
                value="<?= htmlspecialchars($vehicle['plate_number']) ?>"
                style="text-transform:uppercase;"/>
            </div>
            <div class="field">
              <label class="field-label">Make <span class="req">*</span></label>
              <input type="text" name="make" class="field-input"
                placeholder="Toyota"
                value="<?= htmlspecialchars($vehicle['make']) ?>"/>
            </div>
            <div class="field">
              <label class="field-label">Model <span class="req">*</span></label>
              <input type="text" name="model" class="field-input"
                placeholder="Innova"
                value="<?= htmlspecialchars($vehicle['model']) ?>"/>
            </div>
            <div class="field">
              <label class="field-label">Year Model <span class="req">*</span></label>
              <input type="number" name="year_model" class="field-input"
                min="1990" max="<?= date('Y') + 1 ?>"
                value="<?= htmlspecialchars($vehicle['year_model']) ?>"/>
            </div>
            <div class="field">
              <label class="field-label">Color</label>
              <input type="text" name="color" class="field-input"
                placeholder="e.g. Pearl White"
                value="<?= htmlspecialchars($vehicle['color'] ?? '') ?>"/>
            </div>
            <div class="field">
              <label class="field-label">Engine Number</label>
              <input type="text" name="motor_number" class="field-input"
                value="<?= htmlspecialchars($vehicle['motor_number'] ?? '') ?>"/>
            </div>
            <div class="field span-2">
              <label class="field-label">Chassis Number</label>
              <input type="text" name="serial_number" class="field-input"
                value="<?= htmlspecialchars($vehicle['serial_number'] ?? '') ?>"/>
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
