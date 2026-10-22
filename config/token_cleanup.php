<?php
/**
 * Deletes expired one-time codes and links: 2FA codes, password reset links, email change links.
 * They can't be used anymore, so keeping them only makes the tables grow. Runs daily (cron).
 */

/** Returns how many rows were deleted from each table. */
function cleanup_expired_tokens(mysqli $conn): array
{
    $deleted = [];
    foreach (['two_factor_codes', 'password_resets', 'email_verifications'] as $table) {
        $conn->query("DELETE FROM `$table` WHERE expires_at < NOW()");
        $deleted[$table] = $conn->affected_rows;
    }
    return $deleted;
}
