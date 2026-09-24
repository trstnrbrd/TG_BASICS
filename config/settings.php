<?php
/**
 * config/settings.php
 * System settings helper.
 * Usage: require_once 'config/settings.php';
 *        $val = getSetting($conn, 'key', 'default');
 */

function getAllSettings(mysqli $conn): array {
    static $cache = null;
    if ($cache !== null) return $cache;

    $cache = [];
    $result = $conn->query("SELECT setting_key, setting_value FROM system_settings");
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $cache[$row['setting_key']] = $row['setting_value'];
        }
    }
    return $cache;
}

function getSetting(mysqli $conn, string $key, ?string $default = null): string {
    $all = getAllSettings($conn);
    if (isset($all[$key])) return $all[$key];
    return $default ?? '';
}

function setSetting(mysqli $conn, string $key, string $value): bool {
    $stmt = $conn->prepare(
        "INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
    );
    $stmt->bind_param('ss', $key, $value);
    return $stmt->execute();
}
