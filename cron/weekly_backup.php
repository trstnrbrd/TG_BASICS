<?php
/**
 * Weekly trigger for the full backup (database + uploaded files), called once a week by GitHub Actions:
 *   https://<host>/TG-BASICS/cron/weekly_backup.php?key=<cron_secret_key>
 * Same secret key as the daily cron. No one is signed in here. The logic is in includes/full_backup.php.
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/settings.php';
require_once __DIR__ . '/../config/app_url.php';
require_once __DIR__ . '/../config/mailer.php';
require_once __DIR__ . '/../includes/full_backup.php';

header('Content-Type: text/plain; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');

$given  = (string)($_GET['key'] ?? '');
$secret = (string)getSetting($conn, 'cron_secret_key', '');

if ($secret === '' || $given === '' || !hash_equals($secret, $given)) {
    http_response_code(403);
    echo "Forbidden\n";
    exit;
}

@set_time_limit(300);
try {
    $r = full_backup_weekly_run($conn);
} catch (Throwable $e) {
    error_log('[TG-BASICS] weekly backup failed: ' . $e->getMessage());
    http_response_code(500);
    echo "FAILED - " . $e->getMessage() . "\n";
    exit;
}

echo !empty($r['skipped'])
    ? "OK - another backup was already running, nothing done by this one\n"
    : 'OK - ' . $r['file'] . ' (' . full_backup_size_label($r['size']) . ', ' . $r['rows'] . ' rows, '
      . $r['files'] . ' files), ' . $r['emails_sent'] . " email(s) sent\n";
