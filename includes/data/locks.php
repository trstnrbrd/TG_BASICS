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

/** Releases the given locks (ones not held are ignored). */
function db_locks_release(mysqli $conn, array $names): void
{
    if (!$names) return;
    $cols = [];
    foreach (array_keys(array_values($names)) as $n) $cols[] = "RELEASE_LOCK(?) AS r$n";
    dal_one($conn, 'SELECT ' . implode(', ', $cols), str_repeat('s', count($names)), array_values($names));
}
