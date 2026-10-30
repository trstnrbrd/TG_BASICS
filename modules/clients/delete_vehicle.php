<?php
require_once __DIR__ . "/../../config/session.php";
require_once __DIR__ . '/../../includes/data_access.php';
require_once '../../config/db.php';
require_once '../../config/validators.php';
require_once '../../config/access.php';
require_once '../../config/transaction_pin.php';

require_role(['admin', 'super_admin']);

// POST-only — no direct URL access
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: client_list.php");
    exit;
}

csrf_verify();
$vehicle_id = (int)($_POST['vehicle_id'] ?? 0);
if ($vehicle_id === 0) {
    header("Location: client_list.php");
    exit;
}

// Load vehicle + client name before deleting
$vehicle = vehicle_find($conn, $vehicle_id);

if (!$vehicle) {
    header("Location: client_list.php?error=Vehicle not found.");
    exit;
}

if (!pin_consume($conn)) {
    header("Location: view_client.php?id=" . (int)$vehicle['client_id'] . "&error=" . urlencode(PIN_REQUIRED_MSG));
    exit;
}

// Only the Owner, the admin who encoded this client, or its insurance agent may change its vehicles (config/access.php)
if (!client_editable($conn, (int)$vehicle['client_id'])) {
    header("Location: view_client.php?id=" . (int)$vehicle['client_id'] . "&error=" . urlencode('Only the Owner, the admin who added this client, or its insurance agent can unregister its vehicles.'));
    exit;
}

$client_id = $vehicle['client_id'];

// Repair jobs (with their quotations and e-receipts) and claims would be wiped or orphaned along with the vehicle
$blockers = vehicle_unregister_blockers($conn, $vehicle_id);
if ($blockers !== '') {
    header("Location: view_client.php?id=" . $client_id . "&error=" . urlencode('Vehicle ' . $vehicle['plate_number'] . ' cannot be unregistered — it still has ' . $blockers . ' on record.'));
    exit;
}

// Delete vehicle — CASCADE removes linked policies automatically; the delete and its audit entry are saved together
$deleted = db_transaction($conn, function () use ($conn, $vehicle_id, $vehicle) {
    if (!vehicle_delete($conn, $vehicle_id)) return false;
    $desc = ($_SESSION['full_name'] ?? 'Unknown') . ' unregistered vehicle ' . $vehicle['plate_number'] . ' (' . $vehicle['make'] . ' ' . $vehicle['model'] . ') from client "' . $vehicle['client_name'] . '".';
    audit_log_add($conn, $_SESSION['user_id'], 'VEHICLE_UNREGISTERED', $desc);
    return true;
});

if ($deleted) {
    header("Location: view_client.php?id=" . $client_id . "&success=" . urlencode('Vehicle ' . $vehicle['plate_number'] . ' has been unregistered.'));
    exit;
} else {
    header("Location: view_client.php?id=" . $client_id . "&error=Failed to unregister vehicle. Please try again.");
    exit;
}
