<?php
/* =========================================================================
 * System plumbing: settings, sign-in rate limits, the session's account
 * check, MySQL session time zone, expired-token cleanup
 * ========================================================================= */

/* ---- Settings (key-value table; config/settings.php caches it per request) ---- */

/** Every setting: key => value. */
function settings_all(mysqli $conn): array
{
    $out = [];
    foreach (dal_all($conn, "SELECT setting_key, setting_value FROM system_settings") as $r) $out[$r['setting_key']] = $r['setting_value'];
    return $out;
}

/** One setting read straight from the table (not the per-request cache), or null when unset. */
function setting_read_fresh(mysqli $conn, string $key): ?string
{
    $v = dal_value($conn, "SELECT setting_value FROM system_settings WHERE setting_key = ?", 's', [$key]);
    return $v === null ? null : (string)$v;
}

/** Saves one setting (adds it or replaces its value). */
function setting_save(mysqli $conn, string $key, string $value): bool
{
    dal_exec($conn, "INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?)
                     ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)", 'ss', [$key, $value]);
    return true;
}

/* ---- Rate limits (config/rate_limit.php) ---- */

/** Failed attempts of $who on $endpoint since $since (Y-m-d H:i:s). */
function rate_limit_attempts(mysqli $conn, string $who, string $endpoint, string $since): int
{
    return (int)dal_value($conn, "SELECT COUNT(*) FROM rate_limit_attempts WHERE ip = ? AND endpoint = ? AND attempted_at >= ?",
                          'sss', [$who, $endpoint, $since]);
}

/** Forgets every attempt older than $before. */
function rate_limit_prune(mysqli $conn, string $before): void
{
    dal_exec($conn, "DELETE FROM rate_limit_attempts WHERE attempted_at < ?", 's', [$before]);
}

/** Deletes every attempt older than $days days, whoever made it. Returns how many went. */
function rate_limit_purge(mysqli $conn, int $days): int
{
    return dal_exec($conn, "DELETE FROM rate_limit_attempts WHERE attempted_at < DATE_SUB(NOW(), INTERVAL ? DAY)", 'i', [$days]);
}


/** Records one failed attempt. */
function rate_limit_attempt_add(mysqli $conn, string $who, string $endpoint, string $at): void
{
    dal_exec($conn, "INSERT INTO rate_limit_attempts (ip, endpoint, attempted_at) VALUES (?, ?, ?)", 'sss', [$who, $endpoint, $at]);
}

/** Forgets the attempts of $who on $endpoint (after a success). */
function rate_limit_attempts_clear(mysqli $conn, string $who, string $endpoint): void
{
    dal_exec($conn, "DELETE FROM rate_limit_attempts WHERE ip = ? AND endpoint = ?", 'ss', [$who, $endpoint]);
}

/* ---- Session (config/session.php) ---- */

/** Makes MySQL use Philippine time (+08:00) for NOW(), CURDATE() and friends on this connection. */
function db_use_ph_time(mysqli $conn): void
{
    dal_exec($conn, "SET time_zone = '+08:00'");
}

/** The signed-in account's role and active flag (the session re-checks them every minute), or null. */
function user_role_state(mysqli $conn, int $user_id): ?array
{
    return dal_one($conn, "SELECT role, is_active FROM users WHERE user_id = ?", 'i', [$user_id]);
}

/** What the top bar shows about the user: profile_photo, first_name, last_name, email; or null. */
function account_topbar(mysqli $conn, int $user_id): ?array
{
    return dal_one($conn, "SELECT profile_photo, first_name, last_name, email FROM users WHERE user_id = ?", 'i', [$user_id]);
}

/** Whether the user has the authenticator app turned on. */
function account_totp_enabled(mysqli $conn, int $user_id): bool
{
    return (int)(dal_value($conn, "SELECT totp_enabled FROM users WHERE user_id = ?", 'i', [$user_id]) ?? 0) === 1;
}
/* ---- Expired token cleanup (config/data_purge.php) ---- */

/** The tables whose rows carry an expires_at and can be cleaned up. Table names cannot be bound, so only these. */
const TOKEN_TABLES = ['two_factor_codes', 'password_resets', 'email_verifications'];

/** Deletes the expired rows of one of TOKEN_TABLES. Returns how many went. */
function tokens_delete_expired(mysqli $conn, string $table): int
{
    if (!in_array($table, TOKEN_TABLES, true)) throw new InvalidArgumentException("Not a token table: $table");
    return dal_exec($conn, "DELETE FROM `$table` WHERE expires_at < NOW()");
}
