<?php
date_default_timezone_set('Asia/Manila');

// A signed-in session ends after this much inactivity (shared shop computers / phones left open).
if (!defined('SESSION_IDLE_SECONDS')) define('SESSION_IDLE_SECONDS', 3600);

// MySQL's NOW()/CURRENT_TIMESTAMP depend on the session timezone, which
// normally comes from config/db.php — gitignored, so set it here too.
if (!isset($conn)) {
    require_once __DIR__ . '/db.php';
}
$conn->query("SET time_zone = '+08:00'");

// Auto-detect HTTPS so the cookie is marked secure in production without
// breaking local XAMPP (plain HTTP) — checks both the direct HTTPS flag
// and the forwarded-proto header used by shared hosts that proxy SSL.
$_is_https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
$_is_local = (bool)preg_match('/^(localhost|127\.0\.0\.1|\[::1\])(:\d+)?$/', $_SERVER['HTTP_HOST'] ?? '');

// Production only: never show PHP errors to visitors (they reveal file paths and SQL), log them instead,
// and turn any uncaught exception (e.g. a database constraint error) into a plain error page
// instead of a raw fatal error. Local XAMPP keeps the normal developer error output.
if (!$_is_local) {
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
    set_exception_handler(function (Throwable $e) {
        $ref = 'E' . date('ymdHis') . substr(bin2hex(random_bytes(2)), 0, 3);
        error_log('[TG-BASICS ' . $ref . '] ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
        if (!headers_sent()) http_response_code(500);
        $wantsJson = stripos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false;
        foreach (headers_list() as $h) if (stripos($h, 'content-type: application/json') === 0) $wantsJson = true;
        if ($wantsJson) {
            echo json_encode(['ok' => false, 'error' => 'Something went wrong. Please try again. (Ref ' . $ref . ')', 'msg' => 'Something went wrong. Please try again. (Ref ' . $ref . ')']);
        } else {
            echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Something went wrong | TG-BASICS</title></head>'
               . '<body style="margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;background:#1C1A17;font-family:system-ui,Segoe UI,Arial,sans-serif;color:#E8E2D8;">'
               . '<div style="max-width:420px;padding:2rem;text-align:center;"><div style="font-size:1.4rem;font-weight:800;color:#D4A017;margin-bottom:.6rem;">Something went wrong</div>'
               . '<p style="line-height:1.6;color:#B9B09F;">The action could not be completed. Please go back and try again. If it keeps happening, tell your administrator and quote this reference:</p>'
               . '<p style="font-family:monospace;color:#D4A017;">' . htmlspecialchars($ref) . '</p>'
               . '<p><a href="javascript:history.back()" style="color:#D4A017;">&larr; Go back</a></p></div></body></html>';
        }
        exit;
    });
}

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,              // Cookie expires when browser closes
        'path'     => '/',
        'domain'   => '',
        'secure'   => $_is_https,     // true automatically once served over HTTPS
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

// Idle timeout: a signed-in session with no request for SESSION_IDLE_SECONDS is ended. Clearing the
// session data (not just the flag) means every page's own login guard then sends the user to sign in.
if (isset($_SESSION['user_id'])) {
    if (isset($_SESSION['_last_seen']) && time() - $_SESSION['_last_seen'] > SESSION_IDLE_SECONDS) {
        $_SESSION = ['login_notice' => 'You were signed out after a long period of inactivity. Please sign in again.'];
    } else {
        $_SESSION['_last_seen'] = time();
    }
}

// Once per minute (and on the first request after sign-in): make sure the account still exists, is still
// active and still has the same role — otherwise sign it out. Without this, deleting/deactivating a user or
// changing their role had no effect until they happened to log out. Also refreshes last_active.
if (isset($_SESSION['user_id'])) {
    $never_stamped = !isset($_SESSION['_last_active_update']);
    $due_for_update = isset($_SESSION['_last_active_update']) && time() - $_SESSION['_last_active_update'] > 60;
    if ($never_stamped || $due_for_update) {
        $_SESSION['_last_active_update'] = time();
        if (!isset($conn)) {
            require_once __DIR__ . '/db.php';
        }
        $uid = (int)$_SESSION['user_id'];
        $stmt = $conn->prepare("SELECT role, is_active FROM users WHERE user_id = ?");
        $stmt->bind_param('i', $uid);
        $stmt->execute();
        $acct = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$acct || !(int)$acct['is_active'] || $acct['role'] !== ($_SESSION['role'] ?? null)) {
            $_SESSION = ['login_notice' => 'Your account is no longer active or its access was changed. Please sign in again.'];
        } else {
            $stmt = $conn->prepare("UPDATE users SET last_active = NOW() WHERE user_id = ?");
            $stmt->bind_param('i', $uid);
            $stmt->execute();
            $stmt->close();
        }
    }
}

// Security headers for every page that starts a session.
if (!headers_sent()) {
    header('X-Frame-Options: SAMEORIGIN');                       // no embedding in other sites (clickjacking)
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(self), microphone=(), geolocation=()');
    if ($_is_https) header('Strict-Transport-Security: max-age=15552000');
    if (isset($_SESSION['user_id'])) {
        // Signed-in pages hold client/insurance data: don't let the browser keep them, so the Back button
        // after logout can't show them again on a shared computer.
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
    }
}
