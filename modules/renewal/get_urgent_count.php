<?php
require_once __DIR__ . "/../../config/session.php";
require_once '../../config/db.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['count' => 0]);
    exit;
}

$result = $conn->query("
    SELECT COUNT(*) as c 
    FROM insurance_policies 
    WHERE policy_end >= CURDATE() 
    AND DATEDIFF(policy_end, CURDATE()) <= 7
");

$count = $result->fetch_assoc()['c'];
echo json_encode(['count' => (int)$count]);