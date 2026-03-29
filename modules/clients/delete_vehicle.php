<?php
session_start();
require_once '../../config/db.php';

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'super_admin'])) {
    header("Location: ../../auth/login.php");
    exit;
}

// POST-only — no direct URL access
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: client_list.php");
    exit;
}

$vehicle_id = (int)($_POST['vehicle_id'] ?? 0);
if ($vehicle_id === 0) {
    header("Location: client_list.php");
    exit;
}

// Load vehicle + client name before deleting
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

// Delete vehicle — CASCADE removes linked policies automatically
$del = $conn->prepare("DELETE FROM vehicles WHERE vehicle_id = ?");
$del->bind_param('i', $vehicle_id);

if ($del->execute() && $del->affected_rows > 0) {
    $uid  = $_SESSION['user_id'];
    $log  = $conn->prepare("INSERT INTO audit_logs (user_id, action, description) VALUES (?, 'VEHICLE_DELETED', ?)");
    $desc = ($_SESSION['full_name'] ?? 'Unknown') . ' deleted vehicle ' . $vehicle['plate_number'] . ' (' . $vehicle['make'] . ' ' . $vehicle['model'] . ') from client "' . $vehicle['client_name'] . '".';
    $log->bind_param('is', $uid, $desc);
    $log->execute();

    header("Location: view_client.php?id=" . $client_id . "&success=" . urlencode('Vehicle ' . $vehicle['plate_number'] . ' and its associated policies have been deleted.'));
    exit;
} else {
    header("Location: view_client.php?id=" . $client_id . "&error=Failed to delete vehicle. Please try again.");
    exit;
}
