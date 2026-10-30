<?php
require_once __DIR__ . "/../../config/session.php";
require_once __DIR__ . '/../../includes/data_access.php';
require_once '../../config/db.php';
require_once '../../config/access.php';
require_once __DIR__ . '/../../includes/api.php';

if (!isset($_SESSION['user_id'])) api_error('Not signed in.', 401);

$client_id = (int)($_GET['client_id'] ?? 0);
// Only clients this user may work with (mechanics: walk-in only, admins: their own) — anything else looks empty
if (!client_in_scope($conn, $client_id)) api_success(['vehicles' => []]);

api_success(['vehicles' => client_vehicles_by_plate($conn, $client_id)]);
