<?php
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/db.php';

if (!isset($_SESSION['user_id'])) { http_response_code(401); exit; }

header('Content-Type: application/json');

$uid = (int)($_GET['id'] ?? 0);
if (!$uid) { echo json_encode(['ok' => false]); exit; }

$stmt = $conn->prepare("
    SELECT user_id, full_name, username, role, email, profile_photo, last_active,
           (last_active IS NOT NULL AND last_active >= NOW() - INTERVAL 5 MINUTE) AS is_online
    FROM users
    WHERE user_id = ? AND is_active = 1
");
$stmt->bind_param('i', $uid);
$stmt->execute();
$u = $stmt->get_result()->fetch_assoc();

if (!$u) { echo json_encode(['ok' => false]); exit; }

$role_labels = [
    'super_admin' => 'Owner',
    'admin'       => 'Admin',
    'mechanic'    => 'Mechanic',
];

echo json_encode([
    'ok'           => true,
    'user_id'      => (int)$u['user_id'],
    'full_name'    => $u['full_name'],
    'username'     => $u['username'],
    'role'         => $u['role'],
    'role_label'   => $role_labels[$u['role']] ?? $u['role'],
    'email'        => $u['email'],
    'photo'        => $u['profile_photo'] ? 'uploads/avatars/' . $u['profile_photo'] : '',
    'is_online'    => (bool)$u['is_online'],
    'last_active'  => $u['last_active'],
]);
