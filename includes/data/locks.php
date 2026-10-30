<?php
/* =========================================================================
 * MySQL named locks (GET_LOCK / RELEASE_LOCK), several in one round trip.
 * Used by Import Clients to lock a whole batch of plates at once; the
 * single-lock helpers are with_named_lock() and friends (config/validators.php).
 * ========================================================================= */

/**
 * Tries to take every lock in $names (waiting up to $wait_seconds for each).
 * Returns, in the same order and keys, true for each lock taken.
 */
function db_locks_get(mysqli $conn, array $names, int $wait_seconds = 5): array
{
    if (!$names) return [];
    // each column gets its own alias: unnamed, they would all be "GET_LOCK(?, ?)" and overwrite each other in the row
    $params = []; $cols = [];
    foreach (array_values($names) as $n => $name) { array_push($params, $name, $wait_seconds); $cols[] = "GET_LOCK(?, ?) AS l$n"; }
    $row = dal_one($conn, 'SELECT ' . implode(', ', $cols), str_repeat('si', count($names)), $params);
    $got = array_values($row ?? []);
    $out = [];
    foreach (array_keys($names) as $n => $key) $out[$key] = (int)($got[$n] ?? 0) === 1;
    return $out;
}

/** Takes one lock (waiting up to $wait_seconds; 0 = do not wait). True if taken. */
function db_lock_get(mysqli $conn, string $name, int $wait_seconds = 5): bool
{
    return (int)dal_value($conn, 'SELECT GET_LOCK(?, ?)', 'si', [$name, $wait_seconds]) === 1;
}

/** Releases one lock (if this connection holds it). */
function db_lock_release(mysqli $conn, string $name): void
{
    dal_value($conn, 'SELECT RELEASE_LOCK(?)', 's', [$name]);
}

/* ---- Record numbers (RJ-202610-0001 ...) ---- */

/** The tables with a sequential record number, and its column. Names cannot be bound, so only these. */
const RECORD_NUMBER_COLUMNS = ['repair_jobs' => 'job_number', 'quotations' => 'quotation_number', 'billing' => 'billing_number', 'receipts' => 'receipt_number'];

/** The highest record number starting with $prefix in $table, or null when none yet. */
function record_number_last(mysqli $conn, string $table, string $prefix): ?string
{
    $column = RECORD_NUMBER_COLUMNS[$table] ?? throw new InvalidArgumentException("No record number in table: $table");
    $n = dal_value($conn, "SELECT `$column` FROM `$table` WHERE `$column` LIKE ? ORDER BY `$column` DESC LIMIT 1", 's', [$prefix . '%']);
    return $n === null ? null : (string)$n;
}

/** Releases the given locks (ones not held are ignored). */
function db_locks_release(mysqli $conn, array $names): void
{
    if (!$names) return;
    $cols = [];
    foreach (array_keys(array_values($names)) as $n) $cols[] = "RELEASE_LOCK(?) AS r$n";
    dal_one($conn, 'SELECT ' . implode(', ', $cols), str_repeat('s', count($names)), array_values($names));
}
