<?php
/*
 * One-click database backup (Settings > System Settings, Owner only; owner's request 2026-09-25 — the free host
 * is not reliable, and the weekly backup should not need phpMyAdmin).
 *
 * Pure PHP on purpose: shared hosting disables exec(), so mysqldump cannot be called. The file imports as-is in
 * phpMyAdmin (select or create the database > Import) or with `mysql`. It has no USE / CREATE DATABASE line, so
 * importing it can only ever touch the database that was selected for the import.
 */

const DB_BACKUP_WARN_DAYS = 7;   // remind the Owner when the last backup is older than this

/**
 * Writes the whole database as SQL through $out() in small pieces, so a large database never has to fit in
 * memory. Returns ['tables' => n, 'rows' => n]. Tables are read inside one consistent snapshot, so the file
 * shows a single moment even while staff keep saving.
 */
function db_backup_write(mysqli $conn, callable $out): array
{
    $db       = (string)$conn->query('SELECT DATABASE()')->fetch_row()[0];
    $old_tz   = (string)$conn->query('SELECT @@session.time_zone')->fetch_row()[0];
    $old_cs   = $conn->character_set_name();
    $binary   = ['binary', 'varbinary', 'tinyblob', 'blob', 'mediumblob', 'longblob', 'bit'];
    $n_tables = 0;
    $n_rows   = 0;

    // Read as utf8mb4 whatever the host's default is, matching the file's SET NAMES, so the stored text comes
    // back byte for byte (Ñ, emoji in Facebook names)
    $conn->set_charset('utf8mb4');
    // TIMESTAMP values are written in UTC and the file says so, so a restore on a server in another time zone
    // gets the same instants back (DATETIME values are stored as-is either way)
    $conn->query("SET time_zone = '+00:00'");
    $conn->query('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $conn->query('START TRANSACTION WITH CONSISTENT SNAPSHOT');
    try {
        $out("-- TG-BASICS database backup\n"
           . "-- Database: {$db}\n"
           . '-- Created:  ' . date('Y-m-d H:i:s') . " (Asia/Manila)\n"
           . '-- Server:   ' . $conn->server_info . "\n"
           . "--\n"
           . "-- To restore: phpMyAdmin > select (or create) the database > Import > choose this file.\n"
           . "-- Every table in it is replaced by the copy in this file.\n\n"
           . "SET NAMES utf8mb4;\n"
           . "SET time_zone = '+00:00';\n"
           . "SET FOREIGN_KEY_CHECKS = 0;\n"
           . "SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\n\n");

        $tables = [];
        $res = $conn->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'");
        while ($row = $res->fetch_row()) $tables[] = $row[0];

        $cols_st = $conn->prepare("SELECT COLUMN_NAME, DATA_TYPE, EXTRA FROM information_schema.COLUMNS
                                   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION");
        foreach ($tables as $table) {
            $q = '`' . str_replace('`', '``', $table) . '`';
            $create = $conn->query("SHOW CREATE TABLE $q")->fetch_row()[1];
            $out("--\n-- Table {$q}\n--\n\nDROP TABLE IF EXISTS {$q};\n{$create};\n\n");

            // Generated columns (users.full_name, billing.total_amount_due) are rebuilt by the database itself;
            // inserting a value into them is an error, so they are left out of the INSERTs
            $cols = [];
            $cols_st->bind_param('s', $table);
            $cols_st->execute();
            foreach ($cols_st->get_result() as $c) {
                if (preg_match('/GENERATED|VIRTUAL|PERSISTENT|STORED/i', $c['EXTRA'])) continue;
                $cols[] = ['name' => $c['COLUMN_NAME'], 'bin' => in_array(strtolower($c['DATA_TYPE']), $binary, true)];
            }
            $names = implode(', ', array_map(fn($c) => '`' . str_replace('`', '``', $c['name']) . '`', $cols));
            $select = implode(', ', array_map(fn($c) => $c['bin'] ? 'HEX(`' . str_replace('`', '``', $c['name']) . '`)' : '`' . str_replace('`', '``', $c['name']) . '`', $cols));

            // Streamed row by row (unbuffered) and written 100 rows per INSERT
            $data  = $conn->query("SELECT $select FROM $q", MYSQLI_USE_RESULT);
            $batch = [];
            $count = 0;
            while ($row = $data->fetch_row()) {
                $vals = [];
                foreach ($row as $i => $v) {
                    if ($v === null)          $vals[] = 'NULL';
                    elseif ($cols[$i]['bin']) $vals[] = $v === '' ? "''" : '0x' . $v;
                    else                      $vals[] = "'" . $conn->real_escape_string($v) . "'";
                }
                $batch[] = '(' . implode(',', $vals) . ')';
                $count++;
                if (count($batch) === 100) {
                    $out("INSERT INTO {$q} ({$names}) VALUES\n" . implode(",\n", $batch) . ";\n");
                    $batch = [];
                }
            }
            $data->free();
            if ($batch) $out("INSERT INTO {$q} ({$names}) VALUES\n" . implode(",\n", $batch) . ";\n");
            $out($count ? "\n" : "-- (no rows)\n\n");
            $n_tables++;
            $n_rows += $count;
        }

        $out("SET FOREIGN_KEY_CHECKS = 1;\n\n-- Backup complete: {$n_tables} tables, {$n_rows} rows.\n");
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    } finally {
        $st = $conn->prepare('SET time_zone = ?');
        $st->bind_param('s', $old_tz);
        $st->execute();
        $conn->set_charset($old_cs);
    }
    return ['tables' => $n_tables, 'rows' => $n_rows];
}

/**
 * When the last backup was made and by whom, for the Settings card and the dashboard reminder.
 * ['at' => 'Y-m-d H:i:s' | null, 'by' => name | null, 'days' => whole days ago | null, 'overdue' => bool]
 */
function db_backup_status(mysqli $conn): array
{
    $at  = getSetting($conn, 'backup_last_at', '');
    $uid = (int)getSetting($conn, 'backup_last_by', '0');
    $by  = null;
    if ($uid > 0) {
        // Same masking as everywhere else: the hidden account never shows its real name
        $st = $conn->prepare("SELECT CASE WHEN is_hidden = 1 THEN 'Developer' ELSE full_name END FROM users WHERE user_id = ?");
        $st->bind_param('i', $uid);
        $st->execute();
        $by = $st->get_result()->fetch_row()[0] ?? null;
    }
    $days = $at !== '' ? (int)floor((time() - strtotime($at)) / 86400) : null;
    return ['at' => $at !== '' ? $at : null, 'by' => $by, 'days' => $days, 'overdue' => $days === null || $days >= DB_BACKUP_WARN_DAYS];
}
