<?php
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../includes/data_access.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/api.php';

require_role_json(['admin', 'super_admin'], 'Unauthorized.', 403);

$plate = trim($_GET['plate'] ?? '');
if ($plate === '') api_error('No plate number provided.', 400);

// Fetch vehicle + client
$vehicle = policy_vehicle_by_plate($conn, $plate);

if (!$vehicle) {
    api_error('No vehicle found with plate number "' . htmlspecialchars($plate) . '".', 404);
}

// Fetch last policy for this vehicle
$last_policy = vehicle_last_policy_terms($conn, (int)$vehicle['vehicle_id']);

api_success([
    'vehicle'     => $vehicle,
    'last_policy' => $last_policy ?: null,
]);
