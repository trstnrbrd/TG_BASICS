<?php
// ── TIMEZONE ──
// PHP has no default timezone of its own — without this, date()/time() fall
// back to whatever the server happens to be configured with (UTC on
// InfinityFree, which is why production timestamps were 8 hours off).
date_default_timezone_set('Asia/Manila');

// ── MYSQL TIMEZONE ──
// MySQL's own NOW()/CURRENT_TIMESTAMP (used by column defaults and queries
// across the app) run on MySQL's session timezone, which is separate from
// PHP's and normally comes from config/db.php. That file is gitignored
// (holds DB credentials) and never reaches production through deploy, so
// its timezone line can silently go missing there. Setting it here instead
// means every request that loads this file guarantees it, deployed or not.
if (!isset($conn)) {
    require_once __DIR__ . '/db.php';
}
$conn->query("SET time_zone = '+08:00'");

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
// Update last_active on first visit (if never set) or every 60 seconds.
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
