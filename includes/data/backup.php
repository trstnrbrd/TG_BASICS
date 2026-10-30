<?php
/* =========================================================================
 * Reading the whole database for a backup (includes/db_backup.php writes
 * the SQL file). Table and column names come from the database itself
 * (SHOW TABLES / SHOW COLUMNS), never from a user, and are always quoted
 * with backtick_name().
 * ========================================================================= */

/** `name` with any backtick inside doubled, for a table or column name. */
function backtick_name(string $name): string
{
    return '`' . str_replace('`', '``', $name) . '`';
}

/** The name of the database this connection uses. */
function backup_database_name(mysqli $conn): string
{
    return (string)dal_value($conn, 'SELECT DATABASE()');
}

/** This connection's MySQL time zone (to put it back after the backup). */
function backup_session_time_zone(mysqli $conn): string
{
    return (string)dal_value($conn, 'SELECT @@session.time_zone');
}

/** Sets this connection's MySQL time zone. */
function backup_set_time_zone(mysqli $conn, string $tz): void
{
    dal_exec($conn, 'SET time_zone = ?', 's', [$tz]);
}

/** Starts a read-only snapshot: everything read until commit/rollback is from the same moment. */
function backup_begin_snapshot(mysqli $conn): void
{
    dal_exec($conn, 'SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $conn->begin_transaction(MYSQLI_TRANS_START_WITH_CONSISTENT_SNAPSHOT);
}

/** The names of the real tables (not views). */
function backup_tables(mysqli $conn): array
{
    return array_map(fn($r) => (string)reset($r), dal_all($conn, "SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'"));
}

/** The CREATE TABLE statement of a table. */
function backup_create_table(mysqli $conn, string $table): string
{
    $row = dal_one($conn, 'SHOW CREATE TABLE ' . backtick_name($table));
    return (string)array_values($row ?? [])[1];
}

/** The columns of a table, as SHOW COLUMNS gives them (Field, Type, Extra, ...). */
function backup_columns(mysqli $conn, string $table): array
{
    return dal_all($conn, 'SHOW COLUMNS FROM ' . backtick_name($table));
}

/**
 * Every row of a table, read one by one without loading the whole table
 * into memory (a big table must not use up the host's memory limit). This
 * is the one query not run as a prepared statement: prepared results are
 * buffered in full. It takes no values at all, and $select_sql / $table are
 * built only from SHOW COLUMNS / SHOW TABLES names quoted by backtick_name().
 * Free the result when done.
 */
function backup_rows_stream(mysqli $conn, string $select_sql, string $table): mysqli_result
{
    return $conn->query("SELECT $select_sql FROM " . backtick_name($table), MYSQLI_USE_RESULT);
}
