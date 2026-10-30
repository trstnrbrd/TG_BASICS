<?php
/*
 * The developer account (users.is_hidden = 1) is a hidden maintenance login:
 *   - full access to every module, same as the owner
 *   - not shown in Manage Users or the Activity Log, and its real name is never shown
 *   - cannot see the SMTP password, download a database backup, or change user accounts
 *   - must have an authenticator app on
 */

require_once __DIR__ . '/../includes/data_access.php';

/** True if the current user is the developer account. */
function is_developer(): bool
{
    return !empty($_SESSION['is_hidden']);
}

/** Does this account have the authenticator app turned on? */
function dev_has_authenticator(mysqli $conn, int $user_id): bool
{
    return account_totp_enabled($conn, $user_id);
}
