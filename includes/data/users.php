<?php
/* =========================================================================
 * Users: sign-in
 * ========================================================================= */

/* ---- Manage Users (Owner only) ---- */

/** Every account for Manage Users (developer's left out), Owner first, with online flag and account status. */
function users_manage_list(mysqli $conn): array
{
    return dal_all($conn, "
        SELECT user_id, full_name, username, role, email, is_active, created_at, last_active, profile_photo,
               (last_active IS NOT NULL AND last_active >= NOW() - INTERVAL 5 MINUTE) AS is_online,
               CASE WHEN is_active = 1 THEN 'active' WHEN activation_token IS NULL THEN 'deactivated' ELSE 'pending' END AS acct_status,
               is_hidden
        FROM users
        WHERE is_hidden = 0
        ORDER BY FIELD(role, 'super_admin', 'admin', 'mechanic'), full_name ASC");
}

/** An account Manage Users may act on (never the developer account), or null. */
function user_find_manageable(mysqli $conn, int $user_id): ?array
{
    return dal_one($conn, "SELECT user_id, full_name, role, is_active, activation_token FROM users WHERE user_id = ? AND is_hidden = 0", 'i', [$user_id]);
}

/** Whether a username or an email address is already used by any account. */
function user_username_or_email_taken(mysqli $conn, string $username, string $email): bool
{
    return dal_value($conn, "SELECT 1 FROM users WHERE username = ? OR email = ? LIMIT 1", 'ss', [$username, $email]) !== null;
}

/**
 * Creates an invited account: inactive until the person opens the
 * activation link ($token) and sets their own password ($temp_password_hash
 * is a random placeholder). Returns the new user id.
 */
function user_invite(mysqli $conn, string $first_name, string $last_name, string $username, string $temp_password_hash,
                     string $role, string $email, string $token): int
{
    dal_exec($conn, "INSERT INTO users (first_name, last_name, username, password, role, email, is_active, activation_token)
                     VALUES (?, ?, ?, ?, ?, ?, 0, ?)", 'sssssss', [$first_name, $last_name, $username, $temp_password_hash, $role, $email, $token]);
    return (int)$conn->insert_id;
}

/** Deactivates or reactivates an account (never the Owner's); also clears any sign-in lock. */
function user_set_active(mysqli $conn, int $user_id, bool $active): void
{
    dal_exec($conn, "UPDATE users SET is_active = ?, failed_attempts = 0, locked_until = NULL WHERE user_id = ? AND role != 'super_admin'",
             'ii', [$active ? 1 : 0, $user_id]);
}

/** Deletes an account (never the Owner's). Returns false if nothing was deleted. */
function user_delete(mysqli $conn, int $user_id): bool
{
    return dal_exec($conn, "DELETE FROM users WHERE user_id = ? AND role != 'super_admin'", 'i', [$user_id]) > 0;
}

/** The password hash of an active account ('' if none), for re-asking the password before a sensitive action. */
function user_password_hash(mysqli $conn, int $user_id): string
{
    return (string)(dal_value($conn, "SELECT password FROM users WHERE user_id = ? AND is_active = 1", 'i', [$user_id]) ?? '');
}

/** user_id => username for the given accounts. */
function users_usernames(mysqli $conn, array $user_ids): array
{
    $ids = array_values(array_map('intval', $user_ids));
    if (!$ids) return [];
    $out = [];
    foreach (dal_all($conn, "SELECT user_id, username FROM users WHERE user_id IN (" . dal_in($ids) . ")", str_repeat('i', count($ids)), $ids) as $u) {
        $out[(int)$u['user_id']] = $u['username'];
    }
    return $out;
}

/**
 * The profile card of an active user (opened from the team list), or null.
 * The developer account is only ever shown to itself ($viewer_id).
 */
function user_profile_card(mysqli $conn, int $user_id, int $viewer_id): ?array
{
    return dal_one($conn, "
        SELECT user_id, full_name, username, role, email, profile_photo, last_active,
               (last_active IS NOT NULL AND last_active >= NOW() - INTERVAL 5 MINUTE) AS is_online
        FROM users
        WHERE user_id = ? AND is_active = 1 AND (is_hidden = 0 OR user_id = ?)", 'ii', [$user_id, $viewer_id]);
}

/** Every user except the developer account, by name: [user_id, full_name] (filter dropdowns). */
function users_listed(mysqli $conn): array
{
    return dal_all($conn, "SELECT user_id, full_name FROM users WHERE is_hidden = 0 ORDER BY full_name ASC");
}

/**
 * The active team (developer account left out), Owner first, then admins,
 * then mechanics; is_online = active in the last 5 minutes.
 */
function users_team(mysqli $conn): array
{
    return dal_all($conn, "
        SELECT user_id, full_name, username, role, profile_photo, last_active,
               (last_active IS NOT NULL AND last_active >= NOW() - INTERVAL 5 MINUTE) AS is_online
        FROM users
        WHERE is_active = 1 AND is_hidden = 0
        ORDER BY FIELD(role, 'super_admin', 'admin', 'mechanic'), full_name ASC");
}

/**
 * The account a sign-in attempt names, with everything the sign-in needs
 * (password hash, lockout state, 2FA switches), or null if there is none.
 */
function user_find_for_login(mysqli $conn, string $username): ?array
{
    $stmt = $conn->prepare("SELECT user_id, username, password, role, full_name, is_active, activation_token, is_hidden,
                                   two_factor_enabled, totp_enabled, email, failed_attempts, locked_until
                            FROM users WHERE username = ?");
    $stmt->bind_param('s', $username);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

/**
 * Stores a failed sign-in count. $locked_until (Y-m-d H:i:s) locks the
 * account until then; null leaves the current lock value as it is.
 */
function user_record_failed_login(mysqli $conn, string $username, int $attempts, ?string $locked_until = null): void
{
    $stmt = $conn->prepare("UPDATE users SET failed_attempts = ?, locked_until = COALESCE(?, locked_until) WHERE username = ?");
    $stmt->bind_param('iss', $attempts, $locked_until, $username);
    $stmt->execute();
    $stmt->close();
}

/** Resets the failed sign-in count and lifts any lock (correct password). */
function user_clear_failed_logins(mysqli $conn, string $username): void
{
    $stmt = $conn->prepare("UPDATE users SET failed_attempts = 0, locked_until = NULL WHERE username = ?");
    $stmt->bind_param('s', $username);
    $stmt->execute();
    $stmt->close();
}

/** Marks the user as signed in now (the "online" indicator and idle sign-out read last_active). */
function user_mark_signed_in(mysqli $conn, int $user_id): void
{
    $stmt = $conn->prepare("UPDATE users SET last_active = NOW() WHERE user_id = ?");
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $stmt->close();
}

/** Marks the user as signed out (no longer shown online). */
function user_mark_signed_out(mysqli $conn, int $user_id): void
{
    $stmt = $conn->prepare("UPDATE users SET last_active = NULL WHERE user_id = ?");
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $stmt->close();
}

/**
 * Creates a new 6-digit email sign-in code for the user, valid 10 minutes,
 * and cancels any older unused one. Returns the code (to be emailed).
 * The expiry uses MySQL's NOW() so PHP and MySQL clocks cannot disagree.
 */
function two_factor_code_issue(mysqli $conn, int $user_id): string
{
    $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);

    $inv = $conn->prepare("UPDATE two_factor_codes SET used = 1 WHERE user_id = ? AND used = 0");
    $inv->bind_param('i', $user_id);
    $inv->execute();
    $inv->close();

    $ins = $conn->prepare("INSERT INTO two_factor_codes (user_id, code, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 10 MINUTE))");
    $ins->bind_param('is', $user_id, $code);
    $ins->execute();
    $ins->close();

    return $code;
}

/**
 * Checks an email sign-in code. A correct, unused, unexpired code is marked
 * used (it works once) and true is returned; anything else returns false.
 */
function two_factor_code_consume(mysqli $conn, int $user_id, string $code): bool
{
    $stmt = $conn->prepare("SELECT id FROM two_factor_codes WHERE user_id = ? AND code = ? AND used = 0 AND expires_at > NOW() LIMIT 1");
    $stmt->bind_param('is', $user_id, $code);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) return false;

    $mark = $conn->prepare("UPDATE two_factor_codes SET used = 1 WHERE id = ? AND used = 0");
    $mark->bind_param('i', $row['id']);
    $mark->execute();
    $used = $mark->affected_rows === 1;
    $mark->close();
    return $used;
}
