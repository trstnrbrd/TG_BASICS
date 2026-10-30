<?php
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../includes/data_access.php';
require_once '../../config/db.php';
require_once '../../config/validators.php';
require_once '../../config/settings.php';
require_once '../../config/rate_limit.php';
require_once '../../includes/db_backup.php';
require_once '../../includes/full_backup.php';
require_once '../../config/dev_access.php';
require_once '../../includes/api.php';

// owner only, not even the developer account
if (isset($_SESSION['user_id']) && is_developer()) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') api_error('Only the owner can download a database backup.', 403);
    header('Location: settings.php');
    exit;
}
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'super_admin') {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') api_error('Only the Owner can download a database backup.', 403);
    require_role(['super_admin']);
}
$uid = (int)$_SESSION['user_id'];

// ── Step 1: confirm the password, get a one-time link good for 60 seconds ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify_json();
    $rl_key = 'uid:' . $uid;
    if (rate_limit_blocked($conn, 'db_backup', $rl_key)) {
        api_error('Too many attempts. Please wait a few minutes before trying again.', 429);
    }
    $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
    $st = $conn->prepare('SELECT password FROM users WHERE user_id = ? AND is_active = 1');
    $st->bind_param('i', $uid);
    $st->execute();
    $hash = $st->get_result()->fetch_row()[0] ?? '';
    if ($password === '' || $hash === '' || !password_verify($password, $hash)) {
        rate_limit_record($conn, 'db_backup', $rl_key);
        api_error('Incorrect password.', 403);
    }
    rate_limit_clear($conn, 'db_backup', $rl_key);
    // 'db' = make a records-only backup now; 'full' = the latest weekly backup (records + uploaded files)
    $mode = ($_POST['mode'] ?? '') === 'full' ? 'full' : 'db';
    if ($mode === 'full' && !full_backup_latest()) api_error('No weekly backup has been made yet.', 404);
    $token = bin2hex(random_bytes(16));
    $_SESSION['db_backup_token'] = ['token' => $token, 'expires' => time() + 60, 'mode' => $mode];
    api_success(['url' => 'backup_database.php?token=' . $token]);
}

// ── Step 2: the download ──
$grant = $_SESSION['db_backup_token'] ?? null;
unset($_SESSION['db_backup_token']);
$given = is_string($_GET['token'] ?? null) ? $_GET['token'] : '';
if (!is_array($grant) || $given === '' || !hash_equals($grant['token'], $given) || time() > $grant['expires']) {
    header('Location: settings.php?backup=expired#db-backup');
    exit;
}
session_write_close();
@set_time_limit(600);
while (ob_get_level() > 0) ob_end_clean();

// The latest weekly backup: send the saved file as it is
if (($grant['mode'] ?? 'db') === 'full') {
    $latest = full_backup_latest();
    if (!$latest) { header('Location: settings.php?backup=expired#db-backup'); exit; }
    header('Content-Type: application/gzip');
    header('Content-Disposition: attachment; filename="' . $latest['name'] . '"');
    header('Content-Length: ' . $latest['size']);
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    readfile($latest['path']);
    flush();

    setSetting($conn, 'backup_last_at', date('Y-m-d H:i:s'));
    setSetting($conn, 'backup_last_by', (string)$uid);
    $desc = ($_SESSION['full_name'] ?? 'Unknown') . ' downloaded the weekly full backup of ' . date('M j, Y', strtotime($latest['at'])) . ' (' . full_backup_size_label($latest['size']) . ').';
    audit_log_add($conn, $uid, 'DATABASE_BACKUP', $desc);
    exit;
}
@ini_set('zlib.output_compression', '0');

// compressed (.sql.gz) when possible
$gzip = function_exists('deflate_init');
$ctx  = $gzip ? deflate_init(ZLIB_ENCODING_GZIP, ['level' => 6]) : null;
$out  = $gzip ? function (string $s) use ($ctx) { echo deflate_add($ctx, $s, ZLIB_NO_FLUSH); }
              : function (string $s) { echo $s; };
header('Content-Type: ' . ($gzip ? 'application/gzip' : 'application/octet-stream'));
header('Content-Disposition: attachment; filename="tg-basics-backup-' . date('Y-m-d-His') . ($gzip ? '.sql.gz' : '.sql') . '"');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

try {
    $stats = db_backup_write($conn, $out);
} catch (Throwable $e) {
    error_log('[TG-BASICS] database backup failed: ' . $e->getMessage());
    // mark the file as broken, since the download already started
    $out("\n-- BACKUP FAILED: this file is incomplete and cannot be used. Please download a new backup.\n");
    if ($gzip) echo deflate_add($ctx, '', ZLIB_FINISH);
    exit;
}
if ($gzip) echo deflate_add($ctx, '', ZLIB_FINISH);
flush();

setSetting($conn, 'backup_last_at', date('Y-m-d H:i:s'));
setSetting($conn, 'backup_last_by', (string)$uid);
$desc = ($_SESSION['full_name'] ?? 'Unknown') . ' downloaded a database backup (' . $stats['tables'] . ' tables, ' . number_format($stats['rows']) . ' rows).';
audit_log_add($conn, $uid, 'DATABASE_BACKUP', $desc);
