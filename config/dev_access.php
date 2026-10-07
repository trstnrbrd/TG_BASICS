<?php
/*
 * The developer account (users.is_hidden = 1): the developer's own super-admin login, for maintenance.
 * Owner's decision, 2026-09-25, changed 2026-10-07 (owner agreed, so the developer can debug every module):
 *   - No one can switch it off or delete it, and it is not listed in Manage Users (owner's approval 2026-10-07).
 *   - It has full access to every module, the same as the super admin, for maintenance only (same approval).
 *   - Everything it does is still written to audit_logs, but not listed in the Activity Log views; never its real name.
 *   - It must use an authenticator app, and cannot turn it off.
 *   - Owner-only parts of Settings stay hidden from it: the email app password is not shown or overwritten, and
 *     the database backup is not offered. Manage Users changes are refused to it as well.
 * The pages add is_developer() checks for those parts.
 */

/** True for a request made by the developer account. */
function is_developer(): bool
{
    return !empty($_SESSION['is_hidden']);
}

function dev_has_authenticator(mysqli $conn, int $user_id): bool
{
    $st = $conn->prepare('SELECT totp_enabled FROM users WHERE user_id = ?');
    $st->bind_param('i', $user_id);
    $st->execute();
    return (int)($st->get_result()->fetch_row()[0] ?? 0) === 1;
}
