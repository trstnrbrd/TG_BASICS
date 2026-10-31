<?php
/**
 * Daily cron job, called once a day by GitHub Actions:
 *   https://<host>/TG-BASICS/cron/send_renewal_reminders.php?key=<cron_secret_key>
 * Runs: renewal reminders (daily), overdue payment reminders (weekly), expired token cleanup (daily).
 * The key comes from system_settings, not from this file, since this file is public on GitHub.
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/settings.php';
require_once __DIR__ . '/../config/mailer.php';
require_once __DIR__ . '/../config/renewal_reminders.php';
require_once __DIR__ . '/../config/overdue_reminders.php';
require_once __DIR__ . '/../config/data_purge.php';


header('Content-Type: text/plain; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');

$given  = (string)($_GET['key'] ?? '');
$secret = (string)getSetting($conn, 'cron_secret_key', '');

if ($secret === '' || $given === '' || !hash_equals($secret, $given)) {
    http_response_code(403);
    echo "Forbidden\n";
    exit;
}

// 1. Renewal reminders (the first line must start with "OK -", the GitHub workflow checks it)
$result = send_renewal_reminders($conn);
echo !empty($result['skipped'])
    ? "OK - another reminder run was already in progress, nothing sent by this one\n"
    : 'OK - ' . $result['emails_sent'] . ' email(s) sent for ' . $result['policies'] . " urgent policy/policies\n";

// 2. Overdue payment reminders (sends only once every 7 days)
$od = send_overdue_reminders($conn);
echo !empty($od['skipped'])  ? "Overdue: another run was already in progress\n"
   : (!empty($od['not_due']) ? "Overdue: already sent this week\n"
   : 'Overdue: ' . $od['emails_sent'] . ' email(s) sent for ' . $od['policies'] . " policy/policies\n");

// 3. Purge expired 2FA codes and links, and old failed-login records
$del = purge_old_data($conn);
echo 'Purge: ' . array_sum($del) . " old row(s) deleted\n";

