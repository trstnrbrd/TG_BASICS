<?php
/**
 * Shared by the files in includes/data/: small helpers that run one prepared
 * statement. Every query goes through prepare() + bind_param(), even one with
 * no values in it, so no SQL anywhere is built from user input.
 *
 *   dal_all($conn, "SELECT ... WHERE a = ? AND b = ?", 'is', [$a, $b])  -> list of rows
 *   dal_one(...)    -> the first row, or null
 *   dal_value(...)  -> the first column of the first row, or null
 *   dal_exec(...)   -> affected rows (INSERT / UPDATE / DELETE)
 *   dal_in($ids)    -> "?,?,?" for an IN (...) list; bind the values with str_repeat('i', count($ids))
 */

/**
 * Runs one prepared statement and returns it (executed, not yet closed). A failure always throws
 * mysqli_sql_exception, whatever mysqli_report() is set to on the server, so callers (and
 * db_transaction()'s rollback) see the same thing everywhere.
 */
function dal_run(mysqli $conn, string $sql, string $types = '', array $params = []): mysqli_stmt
{
    $stmt = $conn->prepare($sql);
    if ($stmt === false) throw new mysqli_sql_exception($conn->error, $conn->errno);
    if ($types !== '') $stmt->bind_param($types, ...$params);
    if (!$stmt->execute()) throw new mysqli_sql_exception($stmt->error, $stmt->errno);
    return $stmt;
}

/** Every row the query returns, as associative arrays. */
function dal_all(mysqli $conn, string $sql, string $types = '', array $params = []): array
{
    $stmt = dal_run($conn, $sql, $types, $params);
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

/**
 * dal_all(), but [] when the query fails (e.g. a list table whose SQL has
 * not been run on this server yet). Only for lists that may be empty.
 */
function dal_all_or_empty(mysqli $conn, string $sql, string $types = '', array $params = []): array
{
    try {
        return dal_all($conn, $sql, $types, $params);
    } catch (mysqli_sql_exception $e) {
        return [];
    }
}

/** The first row the query returns, or null. */
function dal_one(mysqli $conn, string $sql, string $types = '', array $params = []): ?array
{
    $stmt = dal_run($conn, $sql, $types, $params);
    $row  = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

/** The first column of the first row, or null when there is no row. */
function dal_value(mysqli $conn, string $sql, string $types = '', array $params = []): mixed
{
    $stmt = dal_run($conn, $sql, $types, $params);
    $row  = $stmt->get_result()->fetch_row();
    $stmt->close();
    return $row[0] ?? null;
}

/** Runs an INSERT / UPDATE / DELETE and returns how many rows it changed. */
function dal_exec(mysqli $conn, string $sql, string $types = '', array $params = []): int
{
    $stmt = dal_run($conn, $sql, $types, $params);
    $n    = $stmt->affected_rows;
    $stmt->close();
    return $n;
}

/** How many rows a whole list query returns (its pager's total). */
function dal_count_rows(mysqli $conn, string $sql, string $types = '', array $params = []): int
{
    return (int)dal_value($conn, "SELECT COUNT(*) FROM ($sql) AS pg_rows", $types, $params);
}

/** One page of a list query: $sql (ORDER BY included, no LIMIT) with LIMIT and OFFSET bound as values too. */
function dal_page(mysqli $conn, string $sql, string $types, array $params, int $limit, int $offset): mysqli_result
{
    $stmt = dal_run($conn, $sql . ' LIMIT ? OFFSET ?', $types . 'ii', array_merge($params, [$limit, $offset]));
    return $stmt->get_result();
}

/** "?,?,?" for an IN (...) list of $values. The list must not be empty (check before the query). */
function dal_in(array $values): string
{
    return implode(',', array_fill(0, count($values), '?'));
}

/**
 * A prepared statement kept open for reuse on this connection. Used by the
 * inserts that Import Clients repeats hundreds of times, so each row costs one
 * round trip to the (remote) database instead of two.
 */
function dal_stmt(mysqli $conn, string $sql): mysqli_stmt
{
    static $cache = null;
    $cache ??= new WeakMap();
    $list = $cache[$conn] ?? [];
    if (!isset($list[$sql])) {
        $list[$sql]    = $conn->prepare($sql);
        $cache[$conn] = $list;
    }
    return $list[$sql];
}
