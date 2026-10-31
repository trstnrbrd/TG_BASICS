<?php
/**
 * Backup for the daily cron job, in case it didn't run today. The dashboard calls this in the background
 * after the page loads, so the page doesn't wait for emails to send. Each job only does its work once
 * a day (or once a week), so calling this many times is safe.
 */
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/validators.php';
require_once __DIR__ . '/../config/settings.php';
require_once __DIR__ . '/../config/mailer.php';
require_once __DIR__ . '/../config/renewal_reminders.php';
require_once __DIR__ . '/../config/overdue_reminders.php';
require_once __DIR__ . '/../config/data_purge.php';
require_once __DIR__ . '/../includes/api.php';

require_role_json(['admin', 'super_admin']);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') api_error('Invalid request.', 400);
csrf_verify_json();

session_write_close();
@set_time_limit(120);
api_success([
    'renewal' => send_renewal_reminders($conn),
    'overdue' => send_overdue_reminders($conn),
    'purge'   => purge_old_data($conn),

]);
