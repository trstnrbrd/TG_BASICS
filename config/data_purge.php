<?php
/**
 * Deletes data that has outlived its use, so the tables don't grow forever: expired one-time codes and
 * links (2FA codes, password reset links, email change links) and old failed-login records.
 * Runs daily (cron).
 */

require_once __DIR__ . '/../includes/data_access.php';

/** Failed-login records are only useful for the rate-limit window (minutes); a day is already generous. */
const PURGE_RATE_LIMIT_DAYS = 1;

/** Returns how many rows were deleted from each table. */
function purge_old_data(mysqli $conn): array
{
    $deleted = [];
    foreach (TOKEN_TABLES as $table) $deleted[$table] = tokens_delete_expired($conn, $table);
    $deleted['rate_limit_attempts'] = rate_limit_purge($conn, PURGE_RATE_LIMIT_DAYS);
    return $deleted;
}
