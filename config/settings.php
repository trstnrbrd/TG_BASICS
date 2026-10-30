<?php
/**
 * config/settings.php
 * System settings helper.
 * Usage: require_once 'config/settings.php';
 *        $val = getSetting($conn, 'key', 'default');
 */

require_once __DIR__ . '/../includes/data_access.php';

function getAllSettings(mysqli $conn): array {
    static $cache = null;
    if ($cache !== null) return $cache;
    return $cache = settings_all($conn);
}

function getSetting(mysqli $conn, string $key, ?string $default = null): string {
    $all = getAllSettings($conn);
    if (isset($all[$key])) return $all[$key];
    return $default ?? '';
}

function setSetting(mysqli $conn, string $key, string $value): bool {
    return setting_save($conn, $key, $value);
}
