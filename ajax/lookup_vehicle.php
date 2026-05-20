<?php
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/db.php';

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'super_admin'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

header('Content-Type: application/json');

$plate = trim($_GET['plate'] ?? '');
if ($plate === '') {
    echo json_encode(['error' => 'No plate number provided.']);
    exit;
}

// Fetch vehicle + client
$stmt = $conn->prepare("
    SELECT c.client_id, c.full_name, c.contact_number, c.address,
           v.vehicle_id, v.plate_number, v.make, v.model,
           v.year_model, v.color, v.motor_number, v.serial_number
    FROM vehicles v
    INNER JOIN clients c ON v.client_id = c.client_id
    WHERE v.plate_number = ?
    LIMIT 1
");
$stmt->bind_param('s', $plate);
$stmt->execute();
$vehicle = $stmt->get_result()->fetch_assoc();

if (!$vehicle) {
    echo json_encode(['error' => 'No vehicle found with plate number "' . htmlspecialchars($plate) . '".']);
    exit;
}

// Fetch last policy for this vehicle
$ps = $conn->prepare("
    SELECT coverage_type, sum_insured, total_premium, markup,
           participation_fee, payment_terms, mortgagee
    FROM insurance_policies
    WHERE vehicle_id = ?
    ORDER BY policy_end DESC
    LIMIT 1
");
$ps->bind_param('i', $vehicle['vehicle_id']);
$ps->execute();
$last_policy = $ps->get_result()->fetch_assoc();

echo json_encode([
    'vehicle'     => $vehicle,
    'last_policy' => $last_policy ?: null,
]);
