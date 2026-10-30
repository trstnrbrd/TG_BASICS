<?php
/* =========================================================================
 * Account links sent by email (activation, password reset, email change)
 * and the authenticator app's sign-in data
 * ========================================================================= */

/* ---- Account activation (Manage Users invite) ---- */

/** The invited account an activation link belongs to [user_id, full_name, email, is_active], or null. */
function user_find_by_activation_token(mysqli $conn, string $token): ?array
{
    return dal_one($conn, "SELECT user_id, full_name, email, is_active FROM users WHERE activation_token = ?", 's', [$token]);
}

/** Sets the invited user's own password, turns the account on and uses up the link. */
function user_activate(mysqli $conn, int $user_id, string $password_hash): void
{
    dal_exec($conn, "UPDATE users SET password = ?, is_active = 1, activation_token = NULL WHERE user_id = ?", 'si', [$password_hash, $user_id]);
}

/* ---- Forgot / reset password ---- */

/** The active account with this email [user_id, full_name, email], or null. */
function user_find_active_by_email(mysqli $conn, string $email): ?array
{
    return dal_one($conn, "SELECT user_id, full_name, email FROM users WHERE email = ? AND is_active = 1", 's', [$email]);
}

/** Starts a password reset: cancels the user's earlier unused links and stores the new one until $expires_at. */
function password_reset_issue(mysqli $conn, int $user_id, string $token, string $expires_at): void
{
    dal_exec($conn, "UPDATE password_resets SET used = 1 WHERE user_id = ? AND used = 0", 'i', [$user_id]);
    dal_exec($conn, "INSERT INTO password_resets (user_id, token, expires_at) VALUES (?, ?, ?)", 'iss', [$user_id, $token, $expires_at]);
}

/** A reset link with its account [id, user_id, expires_at, used, full_name, is_hidden], or null. */
function password_reset_find(mysqli $conn, string $token): ?array
{
    return dal_one($conn, "
        SELECT pr.id, pr.user_id, pr.expires_at, pr.used, u.full_name, u.is_hidden
        FROM password_resets pr JOIN users u ON u.user_id = pr.user_id
        WHERE pr.token = ?", 's', [$token]);
}

/**
 * Saves the new password (lifting any sign-in lock) and uses up the link.
 * Returns false, changing nothing, if the link was used meanwhile (two tabs).
 * Call inside db_transaction().
 */
function password_reset_complete(mysqli $conn, int $reset_id, int $user_id, string $password_hash): bool
{
    if (dal_exec($conn, "UPDATE password_resets SET used = 1 WHERE id = ? AND used = 0", 'i', [$reset_id]) !== 1) return false;
    dal_exec($conn, "UPDATE users SET password = ?, failed_attempts = 0, locked_until = NULL WHERE user_id = ?", 'si', [$password_hash, $user_id]);
    return true;
}

/* ---- Email change (Settings > My Account) ---- */

/** A still-valid email change link with its account [id, user_id, new_email, full_name, is_hidden], or null. */
function email_change_find(mysqli $conn, string $token): ?array
{
    return dal_one($conn, "
        SELECT v.id, v.user_id, v.new_email, u.full_name, u.is_hidden
        FROM email_verifications v JOIN users u ON u.user_id = v.user_id
        WHERE v.token = ? AND v.used = 0 AND v.expires_at > NOW()
        LIMIT 1", 's', [$token]);
}

/** Saves the verified new email and uses up the link. Call inside db_transaction(). */
function email_change_complete(mysqli $conn, int $verification_id, int $user_id, string $new_email): void
{
    dal_exec($conn, "UPDATE users SET email = ? WHERE user_id = ?", 'si', [$new_email, $user_id]);
    dal_exec($conn, "UPDATE email_verifications SET used = 1 WHERE id = ?", 'i', [$verification_id]);
}

/* ---- Authenticator app sign-in ---- */

/** The user's authenticator secret and hashed recovery codes (JSON) [totp_secret, totp_recovery_codes], or null. */
function account_totp(mysqli $conn, int $user_id): ?array
{
    return dal_one($conn, "SELECT totp_secret, totp_recovery_codes FROM users WHERE user_id = ?", 'i', [$user_id]);
}
