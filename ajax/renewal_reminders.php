<?php
/**
 * Same-day safety net for the renewal reminder emails (config/renewal_reminders.php), for the days the daily
 * cron trigger does not fire. The admin dashboard calls this in the background once the page has loaded, so
 * the page itself never waits on the mail server. Safe to call any number of times: a policy is emailed at most
 * once a day, and a run already in progress makes this one return straight away.
 */
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/validators.php';
require_once __DIR__ . '/../config/settings.php';
require_once __DIR__ . '/../config/mailer.php';
require_once __DIR__ . '/../config/renewal_reminders.php';
require_once __DIR__ . '/../includes/api.php';

require_role_json(['admin', 'super_admin']);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') api_error('Invalid request.', 400);
csrf_verify_json();

session_write_close();   // sending can take a few seconds — don't hold up the user's next page meanwhile
@set_time_limit(120);
api_success(send_renewal_reminders($conn));
