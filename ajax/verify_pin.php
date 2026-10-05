<?php
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/rate_limit.php';
require_once __DIR__ . '/../includes/api.php';

if (!isset($_SESSION['user_id'])) api_error('Unauthorized.', 401);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') api_error('Invalid request.', 400);

$user_id = $_SESSION['user_id'];

// Attempts are counted per account (shared by every PIN-check endpoint), not per IP.
$rl_key = 'uid:' . (int)$user_id;

// If no pin sent, this is just an existence check — don't count it as an attempt.
$pin = $_POST['pin'] ?? '';

if ($pin !== '' && rate_limit_blocked($conn, 'verify_pin', $rl_key)) {
    api_error('Too many attempts. Please wait a few minutes before trying again.', 429);
}

$stmt = $conn->prepare("SELECT transaction_pin FROM users WHERE user_id = ?");
$stmt->bind_param('i', $user_id);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();

if (empty($row['transaction_pin'])) api_success(['no_pin' => true]);

if ($pin === '') api_success(['has_pin' => true]);

if (password_verify($pin, $row['transaction_pin'])) {
    rate_limit_clear($conn, 'verify_pin', $rl_key);
    api_success();
}

rate_limit_record($conn, 'verify_pin', $rl_key);
api_error('Incorrect PIN.', 403);
