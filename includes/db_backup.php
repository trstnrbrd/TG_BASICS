<?php
/*
 * One-click database backup for the Owner (Settings > System Settings).
 * Written in plain PHP because the web host does not allow running mysqldump.
 * The file it makes can be imported straight into phpMyAdmin.
 */

const DB_BACKUP_WARN_DAYS = 7;   // remind the Owner if the last backup is older than this

/** Writes the whole database as one SQL file. Returns ['tables' => n, 'rows' => n]. */
function db_backup_write(mysqli $conn, callable $out): array
{
    $db       = (string)$conn->query('SELECT DATABASE()')->fetch_row()[0];
    $old_tz   = (string)$conn->query('SELECT @@session.time_zone')->fetch_row()[0];
    $old_cs   = $conn->character_set_name();
    $binary   = ['binary', 'varbinary', 'tinyblob', 'blob', 'mediumblob', 'longblob', 'bit'];
    $n_tables = 0;
    $n_rows   = 0;

    $conn->set_charset('utf8mb4');            // so special characters (Ñ, emoji) come out correct
    $conn->query("SET time_zone = '+00:00'"); // write timestamps in UTC
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

        foreach ($tables as $table) {
            $q = '`' . str_replace('`', '``', $table) . '`';
            $create = $conn->query("SHOW CREATE TABLE $q")->fetch_row()[1];
            $out("--\n-- Table {$q}\n--\n\nDROP TABLE IF EXISTS {$q};\n{$create};\n\n");

            // skip computed columns (e.g. users.full_name) — the database rebuilds them itself
            $cols = [];
            $desc = $conn->query("SHOW COLUMNS FROM $q");
            foreach ($desc->fetch_all(MYSQLI_ASSOC) as $c) {
                if (preg_match('/\b(VIRTUAL|STORED|PERSISTENT) GENERATED\b/i', $c['Extra'])) continue;
                preg_match('/^[a-z]+/i', $c['Type'], $t);
                $cols[] = ['name' => $c['Field'], 'bin' => in_array(strtolower($t[0] ?? ''), $binary, true)];
            }
            if (!$cols) throw new RuntimeException("Could not read the columns of table {$table}.");
            $names = implode(', ', array_map(fn($c) => '`' . str_replace('`', '``', $c['name']) . '`', $cols));
            $select = implode(', ', array_map(fn($c) => $c['bin'] ? 'HEX(`' . str_replace('`', '``', $c['name']) . '`)' : '`' . str_replace('`', '``', $c['name']) . '`', $cols));

            // 100 rows per INSERT
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

/** When the last backup was made and by whom. */
function db_backup_status(mysqli $conn): array
{
    $at  = getSetting($conn, 'backup_last_at', '');
    $uid = (int)getSetting($conn, 'backup_last_by', '0');
    $by  = null;
    if ($uid > 0) {
        // hide the developer account's real name
        $st = $conn->prepare("SELECT CASE WHEN is_hidden = 1 THEN 'Developer' ELSE full_name END FROM users WHERE user_id = ?");
        $st->bind_param('i', $uid);
        $st->execute();
        $by = $st->get_result()->fetch_row()[0] ?? null;
    }
    $days = $at !== '' ? (int)floor((time() - strtotime($at)) / 86400) : null;
    return ['at' => $at !== '' ? $at : null, 'by' => $by, 'days' => $days, 'overdue' => $days === null || $days >= DB_BACKUP_WARN_DAYS];
}
