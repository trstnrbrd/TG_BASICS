<?php
/**
 * includes/backup.php
 * Backups: the one-click database backup (one .sql file, Settings) and the weekly full backup
 * (database + every uploaded file as one .tar.gz, from the weekly cron job). The reads are in
 * includes/data/backup.php. (Was db_backup.php + full_backup.php until 2026-11-04.)
 */

/* =========================================================================
 * DATABASE BACKUP (.sql)
 * ========================================================================= */

/*
 * One-click database backup for the Owner (Settings > System Settings).
 * Written in plain PHP because the web host does not allow running mysqldump.
 * The file it makes can be imported straight into phpMyAdmin.
 */

require_once __DIR__ . '/data_access.php';   // the reads: includes/data/backup.php

const DB_BACKUP_WARN_DAYS = 7;   // remind the Owner if the last backup is older than this

/** Writes the whole database as one SQL file. Returns ['tables' => n, 'rows' => n]. */
function db_backup_write(mysqli $conn, callable $out): array
{
    $db       = backup_database_name($conn);
    $old_tz   = backup_session_time_zone($conn);
    $old_cs   = $conn->character_set_name();
    $binary   = ['binary', 'varbinary', 'tinyblob', 'blob', 'mediumblob', 'longblob', 'bit'];
    $n_tables = 0;
    $n_rows   = 0;

    $conn->set_charset('utf8mb4');            // so special characters (Ñ, emoji) come out correct
    backup_set_time_zone($conn, '+00:00');    // write timestamps in UTC
    backup_begin_snapshot($conn);             // every table read from the same moment
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

        foreach (backup_tables($conn) as $table) {
            $q = backtick_name($table);
            $create = backup_create_table($conn, $table);
            $out("--\n-- Table {$q}\n--\n\nDROP TABLE IF EXISTS {$q};\n{$create};\n\n");

            // skip computed columns (e.g. users.full_name) — the database rebuilds them itself
            $cols = [];
            foreach (backup_columns($conn, $table) as $c) {
                if (preg_match('/\b(VIRTUAL|STORED|PERSISTENT) GENERATED\b/i', $c['Extra'])) continue;
                preg_match('/^[a-z]+/i', $c['Type'], $t);
                $cols[] = ['name' => $c['Field'], 'bin' => in_array(strtolower($t[0] ?? ''), $binary, true)];
            }
            if (!$cols) throw new RuntimeException("Could not read the columns of table {$table}.");
            $names = implode(', ', array_map(fn($c) => backtick_name($c['name']), $cols));
            $select = implode(', ', array_map(fn($c) => $c['bin'] ? 'HEX(' . backtick_name($c['name']) . ')' : backtick_name($c['name']), $cols));

            // 100 rows per INSERT; read one row at a time (backup_rows_stream()), so a big table never fills the memory
            $data  = backup_rows_stream($conn, $select, $table);
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
        backup_set_time_zone($conn, $old_tz);
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
        $by = user_display_name($conn, $uid);   // the developer account shows as "Developer"
    }
    $days = $at !== '' ? (int)floor((time() - strtotime($at)) / 86400) : null;
    return ['at' => $at !== '' ? $at : null, 'by' => $by, 'days' => $days, 'overdue' => $days === null || $days >= DB_BACKUP_WARN_DAYS];
}

/* =========================================================================
 * WEEKLY FULL BACKUP (.tar.gz: database + uploads)
 * ========================================================================= */

/**
 * Weekly full backup: the database AND every uploaded file (photos, documents, receipts, signatures),
 * saved as one .tar.gz file in backups/ on the server. Runs from the weekly cron job.
 * The owner downloads it in Settings (password required). Only the newest FULL_BACKUP_KEEP files are kept.
 *
 * The .tar.gz is written by hand (tar_* functions below) because the host may not have the zip extension.
 * It opens with 7-Zip, WinRAR, or Windows 11 (right-click > Extract All).
 */

require_once __DIR__ . '/data_access.php';

const FULL_BACKUP_KEEP = 4;   // keep the last 4 weeks

function full_backup_dir(): string
{
    return dirname(__DIR__) . '/backups';
}

/** Makes the full backup. Returns its details, or ['skipped' => true] if another backup is already running. */
function full_backup_create(mysqli $conn): array
{
    if (!db_lock_get($conn, 'tgb_full_backup', 0)) return ['skipped' => true];

    $dir   = full_backup_dir();
    $stamp = date('Y-m-d-His');
    $final = "$dir/tg-basics-full-$stamp.tar.gz";
    $part  = "$final.part";              // renamed only when complete, so a broken file is never offered
    $sql   = "$dir/database-$stamp.sql.tmp";
    try {
        full_backup_prepare_dir($dir);

        // 1. the database, into a temporary .sql file
        $fh = fopen($sql, 'wb');
        if (!$fh) throw new RuntimeException('Could not write to the backups folder.');
        $stats = db_backup_write($conn, function (string $s) use ($fh) {
            if (fwrite($fh, $s) === false) throw new RuntimeException('Could not write the database file (disk full?).');
        });
        fclose($fh);

        // 2. the database + all uploaded files, into one .tar.gz
        $gz = gzopen($part, 'wb6');
        if (!$gz) throw new RuntimeException('Could not create the backup file.');
        tar_add_file($gz, 'database.sql', $sql);
        $files   = 0;
        $uploads = dirname(__DIR__) . '/uploads';
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($uploads, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if (!$f->isFile() || in_array($f->getFilename(), ['.htaccess', '.gitkeep'], true)) continue;
            $rel = 'uploads/' . str_replace('\\', '/', substr($f->getPathname(), strlen($uploads) + 1));
            tar_add_file($gz, $rel, $f->getPathname());
            $files++;
        }
        tar_write($gz, str_repeat("\0", 1024));   // end of the tar archive
        gzclose($gz);
        rename($part, $final);
    } catch (Throwable $e) {
        if (isset($gz) && is_resource($gz)) gzclose($gz);
        if (is_file($part)) unlink($part);
        throw $e;
    } finally {
        if (isset($fh) && is_resource($fh)) fclose($fh);
        if (is_file($sql)) unlink($sql);
        db_lock_release($conn, 'tgb_full_backup');
    }

    // Delete old backups, keep the newest ones
    $all = full_backup_files();
    foreach (array_slice($all, FULL_BACKUP_KEEP) as $old) unlink($old);

    return ['file' => basename($final), 'size' => filesize($final), 'tables' => $stats['tables'],
            'rows' => $stats['rows'], 'files' => $files];
}

/** The weekly cron job: make the backup, write it to the Activity Log, and email the owner. */
function full_backup_weekly_run(mysqli $conn): array
{
    $info = full_backup_create($conn);
    if (!empty($info['skipped'])) return $info;

    $desc = 'Automatic weekly backup saved: ' . $info['tables'] . ' tables, ' . number_format($info['rows']) . ' rows, '
          . number_format($info['files']) . ' uploaded files (' . full_backup_size_label($info['size']) . ').';
    audit_log_add($conn, null, 'DATABASE_BACKUP', $desc);

    // Tell the owner (not the hidden developer account)
    $info['emails_sent'] = 0;
    foreach (owners_with_email($conn) as $owner) {
        if (sendWeeklyBackupEmail($owner['email'], $owner['full_name'], $info, app_url('modules/admin/settings.php#db-backup'))) $info['emails_sent']++;
    }
    return $info;
}

/** The newest full backup on the server, or null if there is none yet. */
function full_backup_latest(): ?array
{
    $path = full_backup_files()[0] ?? null;
    if ($path === null) return null;
    return ['path' => $path, 'name' => basename($path), 'size' => filesize($path), 'at' => date('Y-m-d H:i:s', filemtime($path))];
}

/** All full backup files, newest first. */
function full_backup_files(): array
{
    $files = glob(full_backup_dir() . '/tg-basics-full-*.tar.gz') ?: [];
    rsort($files);   // the date is in the file name, so this sorts newest first
    return $files;
}

/** Creates backups/ and blocks it from the web (in case the root .htaccess rule is missing). */
function full_backup_prepare_dir(string $dir): void
{
    if (!is_dir($dir) && !mkdir($dir, 0755, true)) throw new RuntimeException('Could not create the backups folder.');
    if (!is_file("$dir/.htaccess")) {
        file_put_contents("$dir/.htaccess", "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n"
            . "<IfModule !mod_authz_core.c>\n    Order deny,allow\n    Deny from all\n</IfModule>\n");
    }
}

/** "12.4 MB" */
function full_backup_size_label(int $bytes): string
{
    if ($bytes >= 1048576) return number_format($bytes / 1048576, 1) . ' MB';
    return number_format(max(1, $bytes / 1024), 0) . ' KB';
}

// ── Minimal .tar writer (POSIX ustar format) ─────────────────────────────

/** Adds one file to the archive: a 512-byte header, the file content, then padding to a multiple of 512. */
function tar_add_file($gz, string $name, string $path): void
{
    $size = filesize($path);
    tar_write($gz, tar_header($name, $size, filemtime($path)));
    $in = fopen($path, 'rb');
    while (($chunk = fread($in, 65536)) !== false && $chunk !== '') tar_write($gz, $chunk);
    fclose($in);
    $pad = (512 - $size % 512) % 512;
    if ($pad) tar_write($gz, str_repeat("\0", $pad));
}

function tar_header(string $name, int $size, int $mtime): string
{
    // Names longer than 100 characters go into the 155-character "prefix" field
    $prefix = '';
    if (strlen($name) > 100) {
        $cut = strrpos(substr($name, 0, 156), '/');
        if ($cut === false || strlen($name) - $cut - 1 > 100) throw new RuntimeException("File path too long for the backup: $name");
        $prefix = substr($name, 0, $cut);
        $name   = substr($name, $cut + 1);
    }
    $h = pack('a100a8a8a8a12a12a8a1a100a6a2a32a32a8a8a155a12',
        $name, '0000644', '0000000', '0000000', sprintf('%011o', $size), sprintf('%011o', $mtime),
        '        ', '0', '', 'ustar', '00', 'tgbasics', 'tgbasics', '', '', $prefix, '');
    $sum = array_sum(array_map('ord', str_split($h)));   // checksum: every header byte added up
    return substr_replace($h, sprintf('%06o', $sum) . "\0 ", 148, 8);
}

function tar_write($gz, string $data): void
{
    if (gzwrite($gz, $data) !== strlen($data)) throw new RuntimeException('Could not write the backup file (disk full?).');
}
