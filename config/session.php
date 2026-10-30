<?php
/*
 * Loaded by every page. Starts the session, sets the timezone, adds security headers,
 * and defines the role checks (require_role, is_mechanic, ...).
 */
date_default_timezone_set('Asia/Manila');

// Sign the user out after 1 hour of no activity
if (!defined('SESSION_IDLE_SECONDS')) define('SESSION_IDLE_SECONDS', 3600);

// Use Philippine time in MySQL too
if (!isset($conn)) {
    require_once __DIR__ . '/db.php';
}
require_once __DIR__ . '/../includes/data_access.php';
db_use_ph_time($conn);

require_once __DIR__ . '/app_url.php';

// Live updates: after a save, every open page refreshes itself (includes/realtime.php)
require_once __DIR__ . '/../includes/realtime.php';
realtime_watch_request($conn);

// HTTPS on the live site, plain HTTP on local XAMPP
$_is_https = app_is_https();
$_is_local = (bool)preg_match('/^(localhost|127\.0\.0\.1|\[::1\])(:\d+)?$/', $_SERVER['HTTP_HOST'] ?? '');

// Live site: hide PHP errors from users (log them instead) and show a simple error page with a reference code
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
        'lifetime' => 0,              // deleted when the browser closes
        'path'     => '/',
        'domain'   => '',
        'secure'   => $_is_https,     // only sent over HTTPS
        'httponly' => true,           // JavaScript cannot read it
        'samesite' => 'Strict',       // not sent from other websites (blocks CSRF)
    ]);
    session_start();
}

// Change the session ID every 30 minutes (prevents session hijacking)
if (!isset($_SESSION['_last_regen'])) {
    $_SESSION['_last_regen'] = time();
} elseif (time() - $_SESSION['_last_regen'] > 1800) {
    session_regenerate_id(true); // true = delete the old session
    $_SESSION['_last_regen'] = time();
}

// A request the user did not make: the live-update poll (ajax/live.php) or a page reloading itself because
// someone else saved (assets/js/shared/realtime.js sets the tg_live_reload cookie just before). It must not count
// as activity, or a shop computer left open would never be signed out while others keep working.
$_tg_passive = defined('TG_PASSIVE_REQUEST') || ($_COOKIE['tg_live_reload'] ?? '') === '1';
if (isset($_COOKIE['tg_live_reload']) && !headers_sent()) setcookie('tg_live_reload', '', ['expires' => 1, 'path' => '/', 'samesite' => 'Strict']);

// Idle too long -> clear the session, so the user has to sign in again
if (isset($_SESSION['user_id'])) {
    if (isset($_SESSION['_last_seen']) && time() - $_SESSION['_last_seen'] > SESSION_IDLE_SECONDS) {
        $_SESSION = ['login_notice' => 'You were signed out after a long period of inactivity. Please sign in again.'];
    } elseif (!$_tg_passive || !isset($_SESSION['_last_seen'])) {
        $_SESSION['_last_seen'] = time();
    }
}

// Every minute: check the account is still active with the same role, or sign it out.
// So a deactivated user is kicked out right away, not only when they log out.
if (isset($_SESSION['user_id'])) {
    $never_stamped = !isset($_SESSION['_last_active_update']);
    $due_for_update = isset($_SESSION['_last_active_update']) && time() - $_SESSION['_last_active_update'] > 60;
    if ($never_stamped || $due_for_update) {
        $_SESSION['_last_active_update'] = time();
        if (!isset($conn)) {
            require_once __DIR__ . '/db.php';
        }
        $uid  = (int)$_SESSION['user_id'];
        $acct = user_role_state($conn, $uid);
        if (!$acct || !(int)$acct['is_active'] || $acct['role'] !== ($_SESSION['role'] ?? null)) {
            $_SESSION = ['login_notice' => 'Your account is no longer active or its access was changed. Please sign in again.'];
        } elseif (!$_tg_passive) {   // "online" means the person, not a tab refreshing itself
            user_mark_signed_in($conn, $uid);
        }
    }
}

if (!headers_sent()) {
    header('X-Frame-Options: SAMEORIGIN');                       // other sites can't put our pages in a frame
    header('X-Content-Type-Options: nosniff');                   // browser must trust our file types
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(self), microphone=(), geolocation=()');
    if ($_is_https) header('Strict-Transport-Security: max-age=15552000');   // always use HTTPS
    if (isset($_SESSION['user_id'])) {
        // Don't cache signed-in pages, so Back after logout can't show client data
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
    }
}

/** Page guard: only these roles may open the page; everyone else goes to the sign-in page. */
function require_role(array $roles): void
{
    if (isset($_SESSION['user_id']) && in_array($_SESSION['role'] ?? '', $roles, true)) return;
    header('Location: ' . login_url());
    exit;
}

/** Link to the sign-in page from the current folder. */
function login_url(): string
{
    $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
    if (!preg_match('#/tg-basics/(.*)$#i', $script, $m)) return '/auth/login.php';
    return str_repeat('../', substr_count($m[1], '/')) . 'auth/login.php';
}

/** Same as require_role(), but answers with a JSON error (for AJAX). */
function require_role_json(array $roles, string $message = 'Unauthorized.', int $status = 401): void
{
    if (isset($_SESSION['user_id']) && in_array($_SESSION['role'] ?? '', $roles, true)) return;
    api_error($message, $status);
}

// Role checks
function is_super_admin(): bool { return ($_SESSION['role'] ?? '') === 'super_admin'; }
function is_admin(): bool       { return ($_SESSION['role'] ?? '') === 'admin'; }
function is_mechanic(): bool    { return ($_SESSION['role'] ?? '') === 'mechanic'; }
/** Admin or owner (not a mechanic) */
function is_staff(): bool       { return in_array($_SESSION['role'] ?? '', ['admin', 'super_admin'], true); }
