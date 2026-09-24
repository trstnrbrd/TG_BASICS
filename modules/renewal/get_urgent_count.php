<?php
require_once __DIR__ . "/../../config/session.php";
require_once '../../config/db.php';
require_once '../../config/settings.php';
require_once '../../config/access.php';

header('Content-Type: application/json');
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['count' => 0]);
    exit;
}

// Sidebar badge = the urgent policies this user is responsible for, with the same window setting as Renewal
// Tracking: the Owner counts all of them; an admin only their own clients' (the page's "My Clients" view —
// the other agents they can browse there are not their follow-ups); anyone else nothing.
$urg_days = (int)getSetting($conn, 'renewal_urgent_days', '7');
$scope    = renewal_scope_sql('c');

$result = $conn->query("
    SELECT COUNT(*) as c
    FROM insurance_policies p
    INNER JOIN clients c ON c.client_id = p.client_id
    WHERE p.is_renewed = 0
    AND p.policy_end >= CURDATE()
    AND DATEDIFF(p.policy_end, CURDATE()) <= $urg_days
    AND $scope
");

$count = $result->fetch_assoc()['c'];
echo json_encode(['count' => (int)$count]);
