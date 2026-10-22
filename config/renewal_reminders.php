<?php
/**
 * config/renewal_reminders.php — the once-a-day "a policy is about to expire, tell the people who handle it"
 * job. Only URGENT policies are included (renewal_urgent_days, 7 by default); the 30-day "Expiring" bucket is
 * deliberately left to the dashboard and Renewal Tracking, so the mailbox only fills up when a renewal is
 * genuinely close.
 *
 * It runs from two places and is safe to call from both, any number of times a day:
 *   - cron/send_renewal_reminders.php — the real trigger, called once a day by an outside cron service.
 *   - ajax/renewal_reminders.php — a same-day safety net for the days that trigger does not fire, called in the
 *     background by the admin dashboard after the page has loaded (so the page never waits on the mail server).
 * A policy is emailed at most once per calendar day because the send stamps
 * insurance_policies.last_urgent_reminder_sent; the second caller of the day finds nothing left to send.
 *
 * Who gets what (same scoping the Renewal Tracking page uses, so nobody is emailed about clients they
 * cannot open):
 *   - each Admin — only the policies of clients they are the insurance agent of
 *   - the Owner (super admin) — every urgent policy, including clients with no agent assigned
 *   - Mechanics — never, renewals are not part of that role
 * The hidden maintenance account is skipped too: insurance_agents() already leaves it out.
 */

require_once __DIR__ . '/access.php';
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/app_url.php';


/**
 * The cron call and a dashboard load can land in the same second (the cron fires at 08:00, when the office opens
 * the dashboard). Only one run at a time is let through — a MySQL named lock that is not waited for — and that run
 * stamps the policies as reminded BEFORE the slow SMTP sends, so a run right after it finds nothing left to send.
 * Returns ['emails_sent' => n, 'policies' => n, 'skipped' => true when another run was already going].
 */
function send_renewal_reminders(mysqli $conn): array
{
    $lock = $conn->query("SELECT GET_LOCK('tgb_renewal_reminders', 0)")->fetch_row()[0] ?? 0;
    if ((int)$lock !== 1) return ['emails_sent' => 0, 'policies' => 0, 'skipped' => true];
    try {
        return renewal_reminders_run($conn);
    } finally {
        $conn->query("SELECT RELEASE_LOCK('tgb_renewal_reminders')")->free();
    }
}

function renewal_reminders_run(mysqli $conn): array
{
    $urg_days = (int)getSetting($conn, 'renewal_urgent_days', '7');

    // Not yet expired, not already replaced by a renewal, inside the urgent window, not emailed yet today.
    $stmt = $conn->prepare("
        SELECT p.policy_id, p.policy_number, p.insurance_company, p.policy_end,
               DATEDIFF(p.policy_end, CURDATE()) AS days_left,
               c.full_name AS client_name, c.agent_id,
               v.plate_number
        FROM insurance_policies p
        INNER JOIN clients  c ON c.client_id  = p.client_id
        INNER JOIN vehicles v ON v.vehicle_id = p.vehicle_id
        WHERE p.is_renewed = 0
          AND c.deleted_at IS NULL
          AND p.policy_end >= CURDATE()
          AND DATEDIFF(p.policy_end, CURDATE()) <= ?
          AND (p.last_urgent_reminder_sent IS NULL OR p.last_urgent_reminder_sent < CURDATE())
        ORDER BY p.policy_end ASC, c.full_name ASC
    ");
    $stmt->bind_param('i', $urg_days);
    $stmt->execute();
    $res = $stmt->get_result();

    $policies = [];
    while ($row = $res->fetch_assoc()) $policies[] = $row;
    if (!$policies) return ['emails_sent' => 0, 'policies' => 0];

    // Stamp every policy first, even if a send below fails: a mail server that is down today should not turn
    // tomorrow's one reminder into two, and a second run starting while these emails go out must find nothing.
    // The policy stays urgent and is picked up again the next day.
    $ids = implode(',', array_map('intval', array_column($policies, 'policy_id')));
    $conn->query("UPDATE insurance_policies SET last_urgent_reminder_sent = CURDATE() WHERE policy_id IN ($ids)");

    // Bucket by agent. A client with no agent lands in bucket 0, which no real user_id ever matches, so
    // those policies reach the Owner only — the same place "Unassigned" clients show up in the filters.
    $by_agent = [];
    foreach ($policies as $p) $by_agent[(int)($p['agent_id'] ?? 0)][] = $p;

    $link        = app_url('modules/renewal/renewal_list.php?filter=urgent&agent=all');
    $emails_sent = 0;

    foreach (insurance_agents($conn) as $uid => $agent) {
        $list = $agent['role'] === 'super_admin' ? $policies : ($by_agent[$uid] ?? []);
        if (!$list) continue;                                   // nothing of theirs is urgent today

        $u = $conn->prepare("SELECT email, full_name FROM users WHERE user_id = ?");
        $u->bind_param('i', $uid);
        $u->execute();
        $row = $u->get_result()->fetch_assoc();
        if (empty($row['email'])) continue;                     // no address on file, nothing to send to

        if (sendRenewalReminderEmail($row['email'], $row['full_name'], $list, $link)) $emails_sent++;
    }

    // No session behind this one (the cron caller is anonymous), so the entry is logged with no user.
    // A run that reached nobody is spelled out rather than left as a quiet "0 emails", because the usual
    // cause is the SMTP settings, and the Activity Log is where someone would go looking for it.
    $n    = count($policies);
    $desc = $emails_sent === 0
          ? 'Renewal reminders could not be sent for ' . $n . ' urgent polic' . ($n === 1 ? 'y' : 'ies')
            . ' — no email went out. Check the SMTP settings in System Settings.'
          : 'Renewal reminders sent: ' . $emails_sent . ' email' . ($emails_sent === 1 ? '' : 's')
            . ' covering ' . $n . ' urgent polic' . ($n === 1 ? 'y' : 'ies') . '.';
    $log  = $conn->prepare("INSERT INTO audit_logs (user_id, action, description) VALUES (NULL, 'RENEWAL_REMINDERS_SENT', ?)");
    $log->bind_param('s', $desc);
    $log->execute();

    return ['emails_sent' => $emails_sent, 'policies' => $n];
}
