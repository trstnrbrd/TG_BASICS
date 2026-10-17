<?php
/**
 * cron/send_renewal_reminders.php — the daily trigger for the urgent-renewal reminder emails.
 *
 * This is not a page anyone signs in to: an outside cron service (cron-job.org and the like — InfinityFree
 * disabled its own cron feature in 2023) fetches this URL once a day, and that request is what makes the
 * reminders go out even when nobody has opened the app. The work itself lives in
 * config/renewal_reminders.php, which modules/admin/dashboard_admin.php also calls as a safety net, so the
 * two paths can never drift apart.
 *
 *   https://<host>/TG-BASICS/cron/send_renewal_reminders.php?key=<cron_secret_key>
 *
 * The key is the cron_secret_key row in system_settings, never a hardcoded string here — this file is
 * tracked by git and the repository is public. A wrong or missing key gets a flat 403 with no hint about
 * why, so the endpoint gives nothing away to anyone who finds the URL. hash_equals() compares in constant
 * time, so the response time cannot be used to guess the key character by character either.
 *
 * Reply is plain text, short, and ends in a line the cron service can show in its own log.
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/settings.php';
require_once __DIR__ . '/../config/mailer.php';
require_once __DIR__ . '/../config/renewal_reminders.php';

header('Content-Type: text/plain; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');

$given  = (string)($_GET['key'] ?? '');
$secret = (string)getSetting($conn, 'cron_secret_key', '');

if ($secret === '' || $given === '' || !hash_equals($secret, $given)) {
    http_response_code(403);
    echo "Forbidden\n";
    exit;
}

$result = send_renewal_reminders($conn);

echo 'OK - ' . $result['emails_sent'] . ' email(s) sent for '
   . $result['policies'] . " urgent policy/policies\n";
