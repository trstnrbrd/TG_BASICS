<?php
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/rate_limit.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['ok' => false, 'error' => 'Unauthorized.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'Invalid request.']);
    exit;
}

$user_id = $_SESSION['user_id'];

// Attempts are counted per account (shared by every PIN-check endpoint), not per IP.
$rl_key = 'uid:' . (int)$user_id;

// If no pin sent, this is just an existence check — don't count it as an attempt.
$pin = $_POST['pin'] ?? '';

if ($pin !== '' && rate_limit_blocked($conn, 'verify_pin', $rl_key)) {
    http_response_code(429);
    echo json_encode(['ok' => false, 'error' => 'Too many attempts. Please wait a few minutes before trying again.']);
    exit;
}

$stmt = $conn->prepare("SELECT transaction_pin FROM users WHERE user_id = ?");
$stmt->bind_param('i', $user_id);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();

if (empty($row['transaction_pin'])) {
    echo json_encode(['ok' => true, 'no_pin' => true]);
    exit;
}

if ($pin === '') {
    echo json_encode(['ok' => true, 'has_pin' => true]);
    exit;
}

if (password_verify($pin, $row['transaction_pin'])) {
    rate_limit_clear($conn, 'verify_pin', $rl_key);
    echo json_encode(['ok' => true]);
} else {
    rate_limit_record($conn, 'verify_pin', $rl_key);
    echo json_encode(['ok' => false, 'error' => 'Incorrect PIN.']);
}
