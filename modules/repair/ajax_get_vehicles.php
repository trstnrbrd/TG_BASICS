<?php
require_once __DIR__ . "/../../config/session.php";
require_once '../../config/db.php';
require_once '../../config/access.php';

header('Content-Type: application/json');
if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    echo json_encode([]);
    exit;
}

$client_id = (int)($_GET['client_id'] ?? 0);
// Only clients this user may work with (mechanics: walk-in only, admins: their own) — anything else looks empty
if (!client_in_scope($conn, $client_id)) { echo json_encode([]); exit; }

$stmt = $conn->prepare("
    SELECT vehicle_id, plate_number, make, model, year_model, color
    FROM vehicles
    WHERE client_id = ?
    ORDER BY plate_number ASC
");
$stmt->bind_param('i', $client_id);
$stmt->execute();
echo json_encode($stmt->get_result()->fetch_all(MYSQLI_ASSOC));
