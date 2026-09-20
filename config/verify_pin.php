<?php
require_once __DIR__ . '/session.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/rate_limit.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['ok' => false, 'error' => 'Unauthorized.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'Invalid request.']);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true);
$pin  = $body['pin'] ?? '';

if (!$pin) {
    echo json_encode(['ok' => false, 'error' => 'PIN is required.']);
    exit;
}

$cutoff = date('Y-m-d H:i:s', time() - RL_WINDOW_SECS);
$prune  = $conn->prepare("DELETE FROM rate_limit_attempts WHERE attempted_at < ?");
$prune->bind_param('s', $cutoff);
$prune->execute();
$prune->close();

if (rl_count($conn, 'verify_pin') >= RL_MAX_ATTEMPTS) {
    http_response_code(429);
    echo json_encode(['ok' => false, 'error' => 'Too many attempts. Please wait a few minutes before trying again.']);
    exit;
}

$user_id = $_SESSION['user_id'];
$stmt = $conn->prepare("SELECT transaction_pin FROM users WHERE user_id = ?");
$stmt->bind_param('i', $user_id);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();

if (empty($row['transaction_pin'])) {
    // No PIN set — allow through
    echo json_encode(['ok' => true, 'no_pin' => true]);
    exit;
}

if (password_verify($pin, $row['transaction_pin'])) {
    rate_limit_clear($conn, 'verify_pin');
    echo json_encode(['ok' => true]);
} else {
    rate_limit_record($conn, 'verify_pin');
    echo json_encode(['ok' => false, 'error' => 'Incorrect PIN.']);
}
