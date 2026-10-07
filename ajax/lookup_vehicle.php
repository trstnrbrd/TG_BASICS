<?php
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/api.php';

require_role_json(['admin', 'super_admin'], 'Unauthorized.', 403);

$plate = trim($_GET['plate'] ?? '');
if ($plate === '') api_error('No plate number provided.', 400);

// Fetch vehicle + client
$stmt = $conn->prepare("
    SELECT c.client_id, c.full_name, c.contact_number, c.address,
           v.vehicle_id, v.plate_number, v.make, v.model,
           v.year_model, v.color, v.motor_number, v.serial_number
    FROM vehicles v
    INNER JOIN clients c ON v.client_id = c.client_id
    WHERE v.plate_number = ? AND c.deleted_at IS NULL
    LIMIT 1
");
$stmt->bind_param('s', $plate);
$stmt->execute();
$vehicle = $stmt->get_result()->fetch_assoc();

if (!$vehicle) {
    api_error('No vehicle found with plate number "' . htmlspecialchars($plate) . '".', 404);
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

api_success([
    'vehicle'     => $vehicle,
    'last_policy' => $last_policy ?: null,
]);
