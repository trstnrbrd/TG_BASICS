<?php
/**
 * Deletes data that has outlived its use, so the tables don't grow forever: expired one-time codes and
 * links (2FA codes, password reset links, email change links), old failed-login records, and clients
 * whose time in Recently Deleted is up. Runs daily (cron).
 */

require_once __DIR__ . '/../includes/data_access.php';
require_once __DIR__ . '/../includes/transaction.php';

/** Failed-login records are only useful for the rate-limit window (minutes); a day is already generous. */
const PURGE_RATE_LIMIT_DAYS = 1;

/** How long a deleted client stays in Recently Deleted, where it can still be restored. */
const PURGE_DELETED_CLIENTS_DAYS = 30;

/** Returns how many rows were deleted from each table. */
function purge_old_data(mysqli $conn): array
{
    return purge_expired_rows($conn) + ['clients' => purge_clients($conn, clients_purgeable_ids($conn, PURGE_DELETED_CLIENTS_DAYS))];
}

/** The rows nobody can use anymore: one-time codes and links, failed-login records. */
function purge_expired_rows(mysqli $conn): array
{
    $deleted = [];
    foreach (TOKEN_TABLES as $table) $deleted[$table] = tokens_delete_expired($conn, $table);
    $deleted['rate_limit_attempts'] = rate_limit_purge($conn, PURGE_RATE_LIMIT_DAYS);
    return $deleted;
}

/** Removes these clients for good (each only if still past its time), then their files. Returns how many went. */
function purge_clients(mysqli $conn, array $client_ids): int
{
    $purged = db_transaction($conn, function () use ($conn, $client_ids) {
        $purged = [];
        foreach ($client_ids as $id) {
            if (client_purge($conn, $id, PURGE_DELETED_CLIENTS_DAYS)) $purged[] = $id;
        }
        // A count only: the point of purging is that their personal data is gone
        if ($purged) audit_log_add($conn, null, 'CLIENTS_PURGED', count($purged) . ' deleted client(s) removed for good after ' . PURGE_DELETED_CLIENTS_DAYS . ' days in Recently Deleted.');
        return $purged;
    });
    foreach ($purged as $id) purge_client_files($id);
    return count($purged);
}

/** Deletes a purged client's uploaded documents. */
function purge_client_files(int $client_id): void
{
    $dir = __DIR__ . '/../uploads/client_docs/' . $client_id;
    if (!is_dir($dir)) return;
    foreach (glob($dir . '/*') ?: [] as $file) if (is_file($file)) unlink($file);
    @rmdir($dir);
}

/** Whole days until a client deleted at $deleted_at is purged; 0 = at the next daily run. */
function client_purge_days_left(string $deleted_at): int
{
    $seconds = strtotime($deleted_at) + PURGE_DELETED_CLIENTS_DAYS * 86400 - time();
    return max(0, (int)ceil($seconds / 86400));
}
