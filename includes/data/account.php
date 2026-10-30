<?php
/* =========================================================================
 * My account (Settings): the signed-in user's own profile, password,
 * username, two-factor sign-in, transaction PIN and theme
 * ========================================================================= */

/** Everything the Settings page shows about the user's own account, or null. */
function account_get(mysqli $conn, int $user_id): ?array
{
    return dal_one($conn, "SELECT first_name, last_name, full_name, username, username_changed_at, email, two_factor_enabled, totp_enabled,
                                  profile_photo, theme, transaction_pin
                           FROM users WHERE user_id = ?", 'i', [$user_id]);
}

/** The account's email address ('' when none). */
function account_email(mysqli $conn, int $user_id): string
{
    return (string)(dal_value($conn, "SELECT email FROM users WHERE user_id = ?", 'i', [$user_id]) ?? '');
}

/** The account's password hash ('' when there is no such account). */
function account_password_hash(mysqli $conn, int $user_id): string
{
    return (string)(dal_value($conn, "SELECT password FROM users WHERE user_id = ?", 'i', [$user_id]) ?? '');
}

/** Stores a new password hash. */
function account_set_password(mysqli $conn, int $user_id, string $password_hash): void
{
    dal_exec($conn, "UPDATE users SET password = ? WHERE user_id = ?", 'si', [$password_hash, $user_id]);
}

/** Saves the user's name, and the email too when $email is given (null = keep it; a new one goes through verification). */
function account_set_name(mysqli $conn, int $user_id, string $first_name, string $last_name, ?string $email = null): void
{
    if ($email === null) {
        dal_exec($conn, "UPDATE users SET first_name = ?, last_name = ? WHERE user_id = ?", 'ssi', [$first_name, $last_name, $user_id]);
    } else {
        dal_exec($conn, "UPDATE users SET first_name = ?, last_name = ?, email = ? WHERE user_id = ?", 'sssi', [$first_name, $last_name, $email, $user_id]);
    }
}

/** Whether another account already uses this email address. */
function account_email_taken(mysqli $conn, string $email, int $user_id): bool
{
    return dal_value($conn, "SELECT 1 FROM users WHERE email = ? AND user_id != ? LIMIT 1", 'si', [$email, $user_id]) !== null;
}

/**
 * Starts an email change: cancels any earlier pending one and stores the
 * new address with its link token, valid 24 hours (MySQL's NOW(), so the
 * PHP and MySQL clocks cannot disagree).
 */
function account_email_change_request(mysqli $conn, int $user_id, string $new_email, string $token): void
{
    dal_exec($conn, "UPDATE email_verifications SET used = 1 WHERE user_id = ? AND used = 0", 'i', [$user_id]);
    dal_exec($conn, "INSERT INTO email_verifications (user_id, new_email, token, expires_at) VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL 24 HOUR))",
             'iss', [$user_id, $new_email, $token]);
}

/** The new email address still waiting for verification, or null. */
function account_email_change_pending(mysqli $conn, int $user_id): ?string
{
    $e = dal_value($conn, "SELECT new_email FROM email_verifications WHERE user_id = ? AND used = 0 AND expires_at > NOW()
                           ORDER BY created_at DESC LIMIT 1", 'i', [$user_id]);
    return $e === null ? null : (string)$e;
}

/** The current profile photo file name ('' when none). */
function account_photo(mysqli $conn, int $user_id): string
{
    return (string)(dal_value($conn, "SELECT profile_photo FROM users WHERE user_id = ?", 'i', [$user_id]) ?? '');
}

/** Sets the profile photo file name (null removes it). */
function account_set_photo(mysqli $conn, int $user_id, ?string $file_name): void
{
    dal_exec($conn, "UPDATE users SET profile_photo = ? WHERE user_id = ?", 'si', [$file_name, $user_id]);
}

/** Password hash, username and last username change, for the Change Username check, or null. */
function account_username_state(mysqli $conn, int $user_id): ?array
{
    return dal_one($conn, "SELECT password, username, username_changed_at FROM users WHERE user_id = ?", 'i', [$user_id]);
}

/** Whether another account already uses this username. */
function account_username_taken(mysqli $conn, string $username, int $user_id): bool
{
    return dal_value($conn, "SELECT 1 FROM users WHERE username = ? AND user_id != ? LIMIT 1", 'si', [$username, $user_id]) !== null;
}

/** Saves a new username and when it was changed (the 60-day limit counts from it). */
function account_set_username(mysqli $conn, int $user_id, string $username, string $changed_at): void
{
    dal_exec($conn, "UPDATE users SET username = ?, username_changed_at = ? WHERE user_id = ?", 'ssi', [$username, $changed_at, $user_id]);
}

/** Turns the email sign-in code on or off. */
function account_set_email_2fa(mysqli $conn, int $user_id, bool $enabled): void
{
    dal_exec($conn, "UPDATE users SET two_factor_enabled = ? WHERE user_id = ?", 'ii', [$enabled ? 1 : 0, $user_id]);
}

/** Turns the authenticator app on (it replaces the email code). $recovery_codes_json: the hashed recovery codes. */
function account_enable_totp(mysqli $conn, int $user_id, string $secret, string $recovery_codes_json): void
{
    dal_exec($conn, "UPDATE users SET totp_secret = ?, totp_enabled = 1, totp_recovery_codes = ?, two_factor_enabled = 0 WHERE user_id = ?",
             'ssi', [$secret, $recovery_codes_json, $user_id]);
}

/** Turns the authenticator app off and forgets its secret and recovery codes. */
function account_disable_totp(mysqli $conn, int $user_id): void
{
    dal_exec($conn, "UPDATE users SET totp_secret = NULL, totp_enabled = 0, totp_recovery_codes = NULL WHERE user_id = ?", 'i', [$user_id]);
}

/** Replaces the (hashed) authenticator recovery codes. */
function account_set_recovery_codes(mysqli $conn, int $user_id, string $recovery_codes_json): void
{
    dal_exec($conn, "UPDATE users SET totp_recovery_codes = ? WHERE user_id = ?", 'si', [$recovery_codes_json, $user_id]);
}

/** The hashed Transaction PIN, or null when none is set. */
function account_pin_hash(mysqli $conn, int $user_id): ?string
{
    $h = dal_value($conn, "SELECT transaction_pin FROM users WHERE user_id = ?", 'i', [$user_id]);
    return $h === null || $h === '' ? null : (string)$h;
}

/** Sets the hashed Transaction PIN (null removes it). */
function account_set_pin(mysqli $conn, int $user_id, ?string $pin_hash): void
{
    dal_exec($conn, "UPDATE users SET transaction_pin = ? WHERE user_id = ?", 'si', [$pin_hash, $user_id]);
}

/** Saves the user's theme. */
function account_set_theme(mysqli $conn, int $user_id, string $theme): void
{
    dal_exec($conn, "UPDATE users SET theme = ? WHERE user_id = ?", 'si', [$theme, $user_id]);
}
