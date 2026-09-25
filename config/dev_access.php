<?php
/*
 * The developer account (users.is_hidden = 1): the developer's own super-admin login, for maintenance.
 * Owner's decision, 2026-09-25:
 *   - It is listed in Manage Users as "Developer" and is protected like the owner's account (no one switches it off).
 *   - It sees NO business data: no clients, vehicles, policies, claims, repairs, billing, quotations, reports,
 *     dashboard, activity log or global search (all of those carry client names). It opens only Settings — its own
 *     account, design, and the system settings, where the email app password stays hidden and the database backup
 *     is not offered.
 *   - It must use an authenticator app, and cannot turn it off.
 * config/session.php enforces this on every request with dev_may_open(); the pages add is_developer() checks for
 * the owner-only parts of Settings and as a second line of defence (backup, Manage Users changes).
 */

/** Where the developer account lands (and is sent back to): the only page it may open. */
const DEV_HOME = 'modules/admin/settings.php';

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

/**
 * May the developer account open this script ($_SERVER['SCRIPT_NAME'])? Sign-in pages, the landing page and the
 * public client pages are not part of the signed-in app and stay open; inside modules/ and ajax/ only Settings does.
 */
function dev_may_open(string $script): bool
{
    $script = str_replace('\\', '/', $script);
    if (!preg_match('#/(modules|ajax)/#', $script)) return true;
    if (preg_match('#/modules/public/#', $script)) return true;
    return (bool)preg_match('#/' . preg_quote(DEV_HOME, '#') . '$#', $script);
}
