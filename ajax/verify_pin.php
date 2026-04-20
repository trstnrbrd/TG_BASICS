<?php
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/db.php';

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

$stmt = $conn->prepare("SELECT transaction_pin FROM users WHERE user_id = ?");
$stmt->bind_param('i', $user_id);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();

if (empty($row['transaction_pin'])) {
    echo json_encode(['ok' => true, 'no_pin' => true]);
    exit;
}

// If no pin sent, just checking if PIN exists
$pin = $_POST['pin'] ?? '';
if ($pin === '') {
    echo json_encode(['ok' => true, 'has_pin' => true]);
    exit;
}

if (password_verify($pin, $row['transaction_pin'])) {
    echo json_encode(['ok' => true]);
} else {
    echo json_encode(['ok' => false, 'error' => 'Incorrect PIN.']);
}
