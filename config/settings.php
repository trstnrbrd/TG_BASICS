<?php
/**
 * config/settings.php
 * System settings helper — auto-creates table on first use.
 * Usage: require_once 'config/settings.php';
 *        $val = getSetting($conn, 'key', 'default');
 */

function _settingsDefaults(): array {
    return [
        'company_name'           => 'TG Customworks & Basic Car Insurance Services',
        'company_address'        => '49 Villa Tierra St., San Roque, Pandi, Bulacan',
        'company_contact'        => '',
        'company_email'          => '',
        'smtp_host'              => 'smtp.gmail.com',
        'smtp_port'              => '587',
        'smtp_username'          => 'Twizter1018@gmail.com',
        'smtp_password'          => 'wtig taza tmkw ynuy',
        'smtp_encryption'        => 'tls',
        'smtp_sender_name'       => 'TG-BASICS System',
        'smtp_sender_email'      => 'Twizter1018@gmail.com',
        'eligibility_max_age'    => '10',
        'renewal_urgent_days'    => '7',
        'renewal_expiring_days'  => '30',
        'max_login_attempts'     => '5',
        'lockout_duration'       => '15',
        'activation_link_expiry' => '24',
        'reset_link_expiry'      => '1',
        'timezone'               => 'Asia/Manila',
        'date_format'            => 'M d, Y',
    ];
}

function _initSettingsTable(mysqli $conn): void {
    static $done = false;
    if ($done) return;
    $done = true;

    $conn->query("
        CREATE TABLE IF NOT EXISTS system_settings (
            setting_key VARCHAR(100) PRIMARY KEY,
            setting_value TEXT,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )
    ");

    $defaults = _settingsDefaults();
    $stmt = $conn->prepare("INSERT IGNORE INTO system_settings (setting_key, setting_value) VALUES (?, ?)");
    foreach ($defaults as $key => $val) {
        $stmt->bind_param('ss', $key, $val);
        $stmt->execute();
    }

    _initMigrations($conn);
}

function _initMigrations(mysqli $conn): void {
    static $done = false;
    if ($done) return;
    $done = true;

    // Add two_factor_enabled column to users table
    $result = $conn->query("SHOW COLUMNS FROM users LIKE 'two_factor_enabled'");
    if ($result && $result->num_rows === 0) {
        $conn->query("ALTER TABLE users ADD COLUMN two_factor_enabled TINYINT(1) NOT NULL DEFAULT 0");
    }

    // Add profile_photo column to users table
    $result = $conn->query("SHOW COLUMNS FROM users LIKE 'profile_photo'");
    if ($result && $result->num_rows === 0) {
        $conn->query("ALTER TABLE users ADD COLUMN profile_photo VARCHAR(255) DEFAULT NULL");
    }

    // Add theme column to users table
    $result = $conn->query("SHOW COLUMNS FROM users LIKE 'theme'");
    if ($result && $result->num_rows === 0) {
        $conn->query("ALTER TABLE users ADD COLUMN theme VARCHAR(20) NOT NULL DEFAULT 'light'");
    }

    // Create email_verifications table
    $conn->query("
        CREATE TABLE IF NOT EXISTS email_verifications (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            new_email VARCHAR(255) NOT NULL,
            token VARCHAR(64) NOT NULL,
            expires_at DATETIME NOT NULL,
            used TINYINT(1) NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_token (token),
            INDEX idx_user (user_id)
        )
    ");

    // Create two_factor_codes table
    $conn->query("
        CREATE TABLE IF NOT EXISTS two_factor_codes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            code VARCHAR(6) NOT NULL,
            expires_at DATETIME NOT NULL,
            used TINYINT(1) NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_user_code (user_id, code),
            INDEX idx_user (user_id)
        )
    ");
}

function getAllSettings(mysqli $conn): array {
    static $cache = null;
    if ($cache !== null) return $cache;

    _initSettingsTable($conn);

    $cache = _settingsDefaults();
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
    if ($default !== null) return $default;
    $defaults = _settingsDefaults();
    return $defaults[$key] ?? '';
}

function setSetting(mysqli $conn, string $key, string $value): bool {
    _initSettingsTable($conn);
    $stmt = $conn->prepare(
        "INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
    );
    $stmt->bind_param('ss', $key, $value);
    return $stmt->execute();
}
