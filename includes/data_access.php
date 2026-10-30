<?php
/**
 * Data Access Layer: the SQL of the system's key operations, kept in one
 * place. Pages call these functions by name instead of writing the query
 * themselves.
 *
 * Every query here is a prepared statement (values are bound, never joined
 * into the SQL), which is what protects against SQL injection. The host
 * (InfinityFree) does not allow CREATE PROCEDURE, so these functions stand in
 * for database stored procedures; if the system moves to a host that does,
 * only the body of each function needs to change to a CALL.
 *
 * These functions do not begin or commit transactions. A save that writes
 * more than one row still wraps its calls in db_transaction().
 */

/* =========================================================================
 * Audit log
 * ========================================================================= */

/**
 * Records one entry in the activity log.
 * $user_id is null for entries written by a scheduled job (no one signed in).
 */
function audit_log_add(mysqli $conn, ?int $user_id, string $action, string $description): bool
{
    $stmt = $conn->prepare("INSERT INTO audit_logs (user_id, action, description) VALUES (?, ?, ?)");
    $stmt->bind_param('iss', $user_id, $action, $description);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

/* =========================================================================
 * Users: sign-in
 * ========================================================================= */

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
