<?php
/**
 * Deletes data that has outlived its use, so the tables don't grow forever: expired one-time codes and
 * links (2FA codes, password reset links, email change links), old failed-login records, clients whose
 * time in Recently Deleted is up, old cancelled quotations, and old sign-in / sign-out log entries
 * (owner's approval for the last two, 2026-10-31). Runs daily (cron).
 */

require_once __DIR__ . '/../includes/data_access.php';
require_once __DIR__ . '/../includes/transaction.php';

/** Failed-login records are only useful for the rate-limit window (minutes); a day is already generous. */
const PURGE_RATE_LIMIT_DAYS = 1;

/** How long a deleted client stays in Recently Deleted, where it can still be restored. */
const PURGE_DELETED_CLIENTS_DAYS = 30;

/** A cancelled quotation with no receipt is kept this long after it was cancelled. */
const PURGE_CANCELLED_QUOTATIONS_DAYS = 365;

/** LOGIN and LOGOUT entries in the Activity Log are kept this long. Every other entry stays forever. */
const PURGE_SIGN_IN_LOGS_DAYS = 365;

/** Returns how many rows were deleted from each table. */
function purge_old_data(mysqli $conn): array
{
    return purge_expired_rows($conn) + [
        'clients'      => purge_clients($conn, clients_purgeable_ids($conn, PURGE_DELETED_CLIENTS_DAYS)),
        'quotations'   => purge_quotations($conn, quotations_purgeable_ids($conn, PURGE_CANCELLED_QUOTATIONS_DAYS)),
        'sign_in_logs' => purge_sign_in_logs($conn),
    ];
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

/** Removes these cancelled quotations for good (each only if it still qualifies). Returns how many went. */
function purge_quotations(mysqli $conn, array $quotation_ids): int
{
    return db_transaction($conn, function () use ($conn, $quotation_ids) {
        $n = 0;
        foreach ($quotation_ids as $id) if (quotation_purge($conn, $id, PURGE_CANCELLED_QUOTATIONS_DAYS)) $n++;
        if ($n) audit_log_add($conn, null, 'QUOTATIONS_PURGED', $n . ' cancelled quotation(s) removed for good after ' . PURGE_CANCELLED_QUOTATIONS_DAYS . ' days.');
        return $n;
    });
}

/** Removes old LOGIN and LOGOUT entries and notes how many went. */
function purge_sign_in_logs(mysqli $conn): int
{
    return db_transaction($conn, function () use ($conn) {
        $n = audit_logs_purge_sign_ins($conn, PURGE_SIGN_IN_LOGS_DAYS);
        if ($n) audit_log_add($conn, null, 'SIGN_IN_LOGS_PURGED', $n . ' sign-in / sign-out log entries removed for good after ' . PURGE_SIGN_IN_LOGS_DAYS . ' days.');
        return $n;
    });
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
