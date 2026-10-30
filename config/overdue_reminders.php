<?php
/**
 * Weekly email: "these policies have unpaid installments past their due date".
 * Called every day by the cron job, but only sends once every 7 days.
 * Each admin gets their own clients' policies. The owner gets all of them.
 */

require_once __DIR__ . '/access.php';
require_once __DIR__ . '/../includes/data_access.php';
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/app_url.php';

const OVERDUE_REMINDER_EVERY_DAYS = 7;

/** Only one run at a time, so the cron and the dashboard can't send the same email twice. */
function send_overdue_reminders(mysqli $conn): array
{
    if (!db_lock_get($conn, 'tgb_overdue_reminders', 0)) return ['emails_sent' => 0, 'policies' => 0, 'skipped' => true];
    try {
        return overdue_reminders_run($conn);
    } finally {
        db_lock_release($conn, 'tgb_overdue_reminders');
    }
}

/** Policies where the installments already due are not fully paid. */
function overdue_policies(mysqli $conn): array
{
    return policies_overdue_unpaid($conn);
}

function overdue_reminders_run(mysqli $conn): array
{
    // Sent less than 7 days ago -> nothing to do yet.
    // Read straight from the database: getSetting() keeps the value from the start of the request.
    $last = (string)(setting_read_fresh($conn, 'overdue_reminders_last_sent') ?? '');
    if ($last !== '' && strtotime($last) > strtotime('-' . OVERDUE_REMINDER_EVERY_DAYS . ' days')) {
        return ['emails_sent' => 0, 'policies' => 0, 'not_due' => true];
    }

    $policies = overdue_policies($conn);

    // Mark this week as done BEFORE sending, so another run won't send again
    setSetting($conn, 'overdue_reminders_last_sent', date('Y-m-d H:i:s'));
    if (!$policies) return ['emails_sent' => 0, 'policies' => 0];

    // Show them as "Overdue" in the lists too (same rule as opening the policy page)
    policies_mark_overdue($conn, array_column($policies, 'policy_id'));

    // Group by agent (0 = no agent, only the owner sees those)
    $by_agent = [];
    foreach ($policies as $p) $by_agent[(int)($p['agent_id'] ?? 0)][] = $p;

    $base        = app_url('modules/renewal/view_policy.php?id=');
    $emails_sent = 0;

    foreach (insurance_agents($conn) as $uid => $agent) {
        $list = $agent['role'] === 'super_admin' ? $policies : ($by_agent[$uid] ?? []);
        if (!$list) continue;                                   // nothing overdue for this user

        $row = user_email_name($conn, (int)$uid);
        if (empty($row['email'])) continue;                     // no email address

        if (sendOverduePaymentEmail($row['email'], $row['full_name'], $list, $base)) $emails_sent++;
    }

    // Write the result to the Activity Log (no user: the cron is not signed in)
    $n    = count($policies);
    $desc = $emails_sent === 0
          ? 'Overdue payment reminders could not be sent for ' . $n . ' polic' . ($n === 1 ? 'y' : 'ies')
            . ' — no email went out. Check the SMTP settings in System Settings.'
          : 'Overdue payment reminders sent: ' . $emails_sent . ' email' . ($emails_sent === 1 ? '' : 's')
            . ' covering ' . $n . ' polic' . ($n === 1 ? 'y' : 'ies') . '.';
    audit_log_add($conn, null, 'OVERDUE_REMINDERS_SENT', $desc);

    return ['emails_sent' => $emails_sent, 'policies' => $n];
}
