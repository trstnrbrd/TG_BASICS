<?php
/**
 * Daily trigger for the renewal reminder emails, called once a day by an outside cron service:
 *   https://<host>/TG-BASICS/cron/send_renewal_reminders.php?key=<cron_secret_key>
 * The key comes from system_settings, not from this file, since this file is public on GitHub.
 * No one is signed in here. The actual email logic is in config/renewal_reminders.php.
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

echo !empty($result['skipped'])
    ? "OK - another reminder run was already in progress, nothing sent by this one\n"
    : 'OK - ' . $result['emails_sent'] . ' email(s) sent for ' . $result['policies'] . " urgent policy/policies\n";
