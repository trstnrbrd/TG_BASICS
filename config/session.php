<?php
date_default_timezone_set('Asia/Manila');

// MySQL's NOW()/CURRENT_TIMESTAMP depend on the session timezone, which
// normally comes from config/db.php — gitignored, so set it here too.
if (!isset($conn)) {
    require_once __DIR__ . '/db.php';
}
$conn->query("SET time_zone = '+08:00'");

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,              // Cookie expires when browser closes
        'path'     => '/',
        'domain'   => '',
        'secure'   => false,          // Set to true when deployed over HTTPS
        'httponly' => true,           // JavaScript cannot read the session cookie
        'samesite' => 'Strict',       // Cookie not sent on cross-site requests
    ]);
    session_start();
}

// Rotate session ID every 30 minutes to prevent session fixation.
if (!isset($_SESSION['_last_regen'])) {
    $_SESSION['_last_regen'] = time();
} elseif (time() - $_SESSION['_last_regen'] > 1800) {
    session_regenerate_id(true); // true = delete old session file
    $_SESSION['_last_regen'] = time();
}

// Update last_active on first visit, then at most once per minute.
if (isset($_SESSION['user_id'])) {
    $never_stamped = !isset($_SESSION['_last_active_update']);
    $due_for_update = isset($_SESSION['_last_active_update']) && time() - $_SESSION['_last_active_update'] > 60;
    if ($never_stamped || $due_for_update) {
        $_SESSION['_last_active_update'] = time();
        if (!isset($conn)) {
            require_once __DIR__ . '/db.php';
        }
        $uid = (int)$_SESSION['user_id'];
        $stmt = $conn->prepare("UPDATE users SET last_active = NOW() WHERE user_id = ?");
        $stmt->bind_param('i', $uid);
        $stmt->execute();
        $stmt->close();
    }
}
