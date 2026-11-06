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

/**
 * The number settings of Settings > Insurance / Security: key => [default, min, max, label].
 * The one copy of their defaults and limits: Settings checks a save against them (on the server, not
 * just the form's min/max), prints the form's min/max from them, and every page reads a value through
 * setting_int(), which also keeps an out-of-range value already saved inside the limits.
 */
const SETTING_NUMBERS = [
    'eligibility_max_age'    => [10, 1, 50,  'Max Vehicle Age'],
    'renewal_urgent_days'    => [7,  1, 365, 'Urgent Threshold'],
    'renewal_expiring_days'  => [30, 1, 365, 'Expiring Threshold'],
    'max_login_attempts'     => [5,  1, 20,  'Max Login Attempts'],
    'lockout_duration'       => [15, 1, 120, 'Lockout Duration'],
    'activation_link_expiry' => [24, 1, 168, 'Activation Link Expiry'],
    'reset_link_expiry'      => [1,  1, 24,  'Reset Link Expiry'],
];

/** A raw value made a valid number for $key: the default when not a whole number, else kept within min / max. */
function setting_int_clamp(string $key, $raw): int {
    [$default, $min, $max] = SETTING_NUMBERS[$key];
    $s = is_string($raw) || is_int($raw) ? trim((string)$raw) : '';
    if (!preg_match('/^-?\d{1,9}$/', $s)) return $default;
    return max($min, min($max, (int)$s));
}

/** A number setting (SETTING_NUMBERS), always within its limits. */
function setting_int(mysqli $conn, string $key): int {
    return setting_int_clamp($key, getSetting($conn, $key, (string)SETTING_NUMBERS[$key][0]));
}

/**
 * Checks the number settings a Settings save sent. Returns [values, error]: error is '' when every value
 * is a whole number within its limits and Urgent is not longer than Expiring; values are ints by key.
 */
function setting_numbers_check(array $post): array {
    $vals = [];
    foreach (SETTING_NUMBERS as $key => [, $min, $max, $label]) {
        $s = is_string($post[$key] ?? null) ? trim($post[$key]) : '';
        if (!preg_match('/^\d{1,9}$/', $s) || (int)$s < $min || (int)$s > $max) {
            return [[], "$label must be a whole number from $min to $max."];
        }
        $vals[$key] = (int)$s;
    }
    if ($vals['renewal_urgent_days'] > $vals['renewal_expiring_days']) {
        return [[], 'The Urgent threshold cannot be longer than the Expiring threshold.'];
    }
    return [$vals, ''];
}

/**
 * The business details the owner edits in Settings > Company Info. COMPANY_DEFAULTS fill in a key that
 * has no row yet (fresh or local database); a saved value always wins. The one copy of these defaults:
 * printed reports, receipts, billing statements, emails and the public pages all call company_info().
 */
const COMPANY_DEFAULTS = [
    'company_name'    => 'TG Customworks & Basic Car Insurance',
    'company_address' => '49 Villa Tierra St., San Roque, Pandi, Bulacan',
    'company_contact' => '09171453448',
    'company_email'   => 'tgcustomworksbulacan@gmail.com',
    // the shop's Google Maps place (its reviews tab): the landing page's "Open in Google Maps" and "See all reviews"
    'company_maps_url' => 'https://www.google.com/maps/place/TG+CustomWorks/@14.8557179,120.9540001,17z/data=!4m8!3m7!1s0x3397ac71057677fb:0x35ddd0d771ab5d8f!8m2!3d14.8557127!4d120.956575!9m1!1b1!16s%2Fg%2F11f2wc709p',
];

/**
 * ['name' => …, 'address' => …, 'contact' => …, 'email' => …, 'maps_url' => …] from Settings; an empty or missing value
 * gets its default (a receipt never prints a blank shop name). The phone is saved by Settings as
 * company_contact; the public pages used to read a company_phone key Settings never writes, so an old
 * company_phone row is still used when company_contact has none.
 */
function company_info(mysqli $conn): array {
    $out = [];
    foreach (COMPANY_DEFAULTS as $key => $default) {
        $v = getSetting($conn, $key, '');
        if ($v === '' && $key === 'company_contact') $v = getSetting($conn, 'company_phone', '');
        $out[substr($key, 8)] = $v !== '' ? $v : $default;   // 'company_name' -> 'name'
    }
    return $out;
}
