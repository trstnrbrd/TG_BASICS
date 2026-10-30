<?php
/**
 * Daily email: "these policies expire within 7 days".
 * Called by the cron job, and by the admin dashboard as a backup.
 * Each admin gets their own clients' policies. The owner gets all of them.
 */

require_once __DIR__ . '/access.php';
require_once __DIR__ . '/../includes/data_access.php';
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/app_url.php';


/** Only one run at a time, so the cron and the dashboard can't send the same email twice. */
function send_renewal_reminders(mysqli $conn): array
{
    if (!db_lock_get($conn, 'tgb_renewal_reminders', 0)) return ['emails_sent' => 0, 'policies' => 0, 'skipped' => true];
    try {
        return renewal_reminders_run($conn);
    } finally {
        db_lock_release($conn, 'tgb_renewal_reminders');
    }
}

function renewal_reminders_run(mysqli $conn): array
{
    $urg_days = (int)getSetting($conn, 'renewal_urgent_days', '7');

    // Active, not renewed, expiring soon, and not emailed yet today
    $policies = policies_urgent_unreminded($conn, $urg_days);
    if (!$policies) return ['emails_sent' => 0, 'policies' => 0];

    // Mark them as reminded BEFORE sending, so another run won't send them again
    policies_stamp_urgent_reminder($conn, array_column($policies, 'policy_id'));

    // Group by agent (0 = no agent, only the owner sees those)
    $by_agent = [];
    foreach ($policies as $p) $by_agent[(int)($p['agent_id'] ?? 0)][] = $p;

    $link        = app_url('modules/renewal/renewal_list.php?filter=urgent&agent=all');
    $emails_sent = 0;

    foreach (insurance_agents($conn) as $uid => $agent) {
        $list = $agent['role'] === 'super_admin' ? $policies : ($by_agent[$uid] ?? []);
        if (!$list) continue;                                   // nothing urgent for this user

        $row = user_email_name($conn, (int)$uid);
        if (empty($row['email'])) continue;                     // no email address

        if (sendRenewalReminderEmail($row['email'], $row['full_name'], $list, $link)) $emails_sent++;
    }

    // Write the result to the Activity Log (no user: the cron is not signed in)
    $n    = count($policies);
    $desc = $emails_sent === 0
          ? 'Renewal reminders could not be sent for ' . $n . ' urgent polic' . ($n === 1 ? 'y' : 'ies')
            . ' — no email went out. Check the SMTP settings in System Settings.'
          : 'Renewal reminders sent: ' . $emails_sent . ' email' . ($emails_sent === 1 ? '' : 's')
            . ' covering ' . $n . ' urgent polic' . ($n === 1 ? 'y' : 'ies') . '.';
    audit_log_add($conn, null, 'RENEWAL_REMINDERS_SENT', $desc);

    return ['emails_sent' => $emails_sent, 'policies' => $n];
}
