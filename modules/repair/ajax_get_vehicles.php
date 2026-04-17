<?php
require_once __DIR__ . "/../../config/session.php";
require_once '../../config/db.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    echo json_encode([]);
    exit;
}

header('Content-Type: application/json');
$client_id = (int)($_GET['client_id'] ?? 0);
if ($client_id === 0) { echo json_encode([]); exit; }

$stmt = $conn->prepare("
    SELECT vehicle_id, plate_number, make, model, year_model, color
    FROM vehicles
    WHERE client_id = ?
    ORDER BY plate_number ASC
");
$stmt->bind_param('i', $client_id);
$stmt->execute();
echo json_encode($stmt->get_result()->fetch_all(MYSQLI_ASSOC));
