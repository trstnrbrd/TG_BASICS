<?php
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/api.php';

if (!isset($_SESSION['user_id'])) api_error('Not signed in.', 401);

$uid = (int)($_GET['id'] ?? 0);
if (!$uid) api_error('No user id given.', 400);

$own_id = (int)$_SESSION['user_id'];
$stmt = $conn->prepare("
    SELECT user_id, full_name, username, role, email, profile_photo, last_active,
           (last_active IS NOT NULL AND last_active >= NOW() - INTERVAL 5 MINUTE) AS is_online
    FROM users
    WHERE user_id = ? AND is_active = 1 AND (is_hidden = 0 OR user_id = ?)
");
$stmt->bind_param('ii', $uid, $own_id);
$stmt->execute();
$u = $stmt->get_result()->fetch_assoc();

if (!$u) api_error('User not found.', 404);

$role_labels = [
    'super_admin' => 'Owner',
    'admin'       => 'Admin',
    'mechanic'    => 'Mechanic',
];

api_success([
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
