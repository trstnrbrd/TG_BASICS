<?php
// ── SECURE SESSION CONFIGURATION ──
// Sets HttpOnly + SameSite=Strict cookie flags before session starts.
// Included by every page instead of calling session_start() directly.

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

// ── SESSION REGENERATION ──
// Regenerate session ID every 30 minutes to prevent session fixation attacks.
// The old session ID becomes invalid after regeneration.
if (!isset($_SESSION['_last_regen'])) {
    $_SESSION['_last_regen'] = time();
} elseif (time() - $_SESSION['_last_regen'] > 1800) {
    session_regenerate_id(true); // true = delete old session file
    $_SESSION['_last_regen'] = time();
}

// ── LAST ACTIVE TRACKING ──
// Update last_active every 60 seconds to avoid a DB write on every single page load.
if (isset($_SESSION['user_id']) && (!isset($_SESSION['_last_active_update']) || time() - $_SESSION['_last_active_update'] > 60)) {
    $_SESSION['_last_active_update'] = time();
    // Lazy-load DB connection if not already available
    if (!isset($conn)) {
        require_once __DIR__ . '/db.php';
    }
    $conn->query("UPDATE users SET last_active = NOW() WHERE user_id = " . (int)$_SESSION['user_id']);
}
