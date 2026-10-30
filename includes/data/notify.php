<?php
/* =========================================================================
 * Claim notification recipients (Settings > System Settings, up to 5):
 * each is a linked user (the email follows their account) or a typed email
 * ========================================================================= */

/** The saved recipients, in order, with the linked user's name and email (developer's masked). */
function claim_notify_recipients(mysqli $conn): array
{
    return dal_all($conn, "
        SELECT r.user_id, r.email AS raw_email,
               CASE WHEN u.is_hidden = 1 THEN 'Developer' ELSE u.username  END AS username,
               CASE WHEN u.is_hidden = 1 THEN 'Developer' ELSE u.full_name END AS full_name,
               u.email AS user_email
        FROM claim_notify_recipients r
        LEFT JOIN users u ON r.user_id = u.user_id
        ORDER BY r.id ASC");
}

/** The addresses to email: a linked user's current email, otherwise the typed one (blanks skipped). */
function claim_notify_emails(mysqli $conn): array
{
    $rows = dal_all($conn, "SELECT COALESCE(u.email, r.email) AS resolved_email
                            FROM claim_notify_recipients r LEFT JOIN users u ON r.user_id = u.user_id
                            ORDER BY r.id ASC");
    return array_values(array_filter(array_column($rows, 'resolved_email')));
}

/** Users who can be linked as a recipient: listed accounts with an email on file, by name. */
function claim_notify_pickable_users(mysqli $conn): array
{
    return dal_all($conn, "SELECT user_id, username, full_name FROM users
                           WHERE is_hidden = 0 AND email IS NOT NULL AND email != '' ORDER BY full_name ASC");
}

/**
 * Replaces the whole recipient list. $recipients: [['user_id' => int|null,
 * 'email' => string|null], ...]. Call inside db_transaction() so the list is
 * never left empty halfway.
 */
function claim_notify_recipients_replace(mysqli $conn, array $recipients): void
{
    dal_exec($conn, "DELETE FROM claim_notify_recipients");
    foreach ($recipients as $r) {
        dal_exec($conn, "INSERT INTO claim_notify_recipients (user_id, email) VALUES (?, ?)", 'is', [$r['user_id'], $r['email']]);
    }
}
