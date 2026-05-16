<?php
require_once __DIR__ . '/session.php';
require_once __DIR__ . '/db.php';

header('Content-Type: application/json');

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

$user_id = $_SESSION['user_id'] ?? 0;
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
    echo json_encode(['ok' => true]);
} else {
    echo json_encode(['ok' => false, 'error' => 'Incorrect PIN.']);
}
