<?php
/**
 * Deletes expired one-time codes and links: 2FA codes, password reset links, email change links.
 * They can't be used anymore, so keeping them only makes the tables grow. Runs daily (cron).
 */

require_once __DIR__ . '/../includes/data_access.php';

/** Returns how many rows were deleted from each table. */
function cleanup_expired_tokens(mysqli $conn): array
{
    $deleted = [];
    foreach (TOKEN_TABLES as $table) $deleted[$table] = tokens_delete_expired($conn, $table);
    return $deleted;
}
