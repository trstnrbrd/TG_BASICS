<?php
require_once __DIR__ . '/../../config/session.php';
require_once '../../config/db.php';
require_once '../../config/validators.php';
require_once '../../config/settings.php';
require_once '../../config/rate_limit.php';
require_once '../../includes/db_backup.php';
require_once '../../config/dev_access.php';

// Owner only: the file holds every client's data and every account's password hash
if (isset($_SESSION['user_id']) && is_developer()) {
    // The developer account is a super admin too, but never takes the whole database home
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => 'Only the owner can download a database backup.']);
    } else {
        header('Location: settings.php');
    }
    exit;
}
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'super_admin') {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => 'Only the Owner can download a database backup.']);
        exit;
    }
    header("Location: ../../auth/login.php");
    exit;
}
$uid = (int)$_SESSION['user_id'];

// ── Step 1 (AJAX from Settings): confirm the password, get a one-time download link good for 60 seconds ──
// Asked again even though the Owner is signed in, so a computer left logged in cannot be used to walk off
// with the whole database.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    csrf_verify_json();
    $rl_key = 'uid:' . $uid;
    if (rate_limit_blocked($conn, 'db_backup', $rl_key)) {
        http_response_code(429);
        echo json_encode(['ok' => false, 'error' => 'Too many attempts. Please wait a few minutes before trying again.']);
        exit;
    }
    $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
    $st = $conn->prepare('SELECT password FROM users WHERE user_id = ? AND is_active = 1');
    $st->bind_param('i', $uid);
    $st->execute();
    $hash = $st->get_result()->fetch_row()[0] ?? '';
    if ($password === '' || $hash === '' || !password_verify($password, $hash)) {
        rate_limit_record($conn, 'db_backup', $rl_key);
        echo json_encode(['ok' => false, 'error' => 'Incorrect password.']);
        exit;
    }
    rate_limit_clear($conn, 'db_backup', $rl_key);
    $token = bin2hex(random_bytes(16));
    $_SESSION['db_backup_token'] = ['token' => $token, 'expires' => time() + 60];
    echo json_encode(['ok' => true, 'url' => 'backup_database.php?token=' . $token]);
    exit;
}

// ── Step 2: the download ──
$grant = $_SESSION['db_backup_token'] ?? null;
unset($_SESSION['db_backup_token']);   // one use only
$given = is_string($_GET['token'] ?? null) ? $_GET['token'] : '';
if (!is_array($grant) || $given === '' || !hash_equals($grant['token'], $given) || time() > $grant['expires']) {
    header('Location: settings.php?backup=expired#db-backup');
    exit;
}
session_write_close();   // a big database takes a while — don't hold up the Owner's other tabs meanwhile
@set_time_limit(600);
while (ob_get_level() > 0) ob_end_clean();
@ini_set('zlib.output_compression', '0');

// Compressed (.sql.gz, about a tenth of the size) when the server has zlib; phpMyAdmin imports both
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
    // The download has already started, so say so inside the file — a broken backup must not pass for a good one
    $out("\n-- BACKUP FAILED: this file is incomplete and cannot be used. Please download a new backup.\n");
    if ($gzip) echo deflate_add($ctx, '', ZLIB_FINISH);
    exit;
}
if ($gzip) echo deflate_add($ctx, '', ZLIB_FINISH);
flush();

setSetting($conn, 'backup_last_at', date('Y-m-d H:i:s'));
setSetting($conn, 'backup_last_by', (string)$uid);
$desc = ($_SESSION['full_name'] ?? 'Unknown') . ' downloaded a database backup (' . $stats['tables'] . ' tables, ' . number_format($stats['rows']) . ' rows).';
$log  = $conn->prepare("INSERT INTO audit_logs (user_id, action, description) VALUES (?, 'DATABASE_BACKUP', ?)");
$log->bind_param('is', $uid, $desc);
$log->execute();
