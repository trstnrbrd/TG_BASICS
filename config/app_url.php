<?php
/**
 * config/app_url.php — where the app lives on the web, worked out from the request instead of hardcoded.
 *
 *   app_base_path()               "/TG-BASICS/" today; "/" if the app is ever moved to a domain root
 *   app_url('auth/activate.php')  full link for emails and QR codes: https://host/TG-BASICS/auth/activate.php
 *
 * Every link that leaves the browser (activation, password reset, email verification, the client QR/profile
 * link) goes through app_url(), so moving hosts or folders needs no code change.
 */

/** True when the request came in over HTTPS, directly or through a host's SSL proxy (X-Forwarded-Proto). */
function app_is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
}

/** URL path of the app folder, always with a leading and trailing slash. */
function app_base_path(): string
{
    static $base = null;
    if ($base !== null) return $base;

    $root = str_replace('\\', '/', (string)realpath(dirname(__DIR__)));

    // The running script's URL path minus its location inside the app folder:
    // /TG-BASICS/modules/repair/view_repair.php - /modules/repair/view_repair.php = /TG-BASICS/
    $file = realpath($_SERVER['SCRIPT_FILENAME'] ?? '');
    $name = (string)($_SERVER['SCRIPT_NAME'] ?? '');
    if ($file !== false && $name !== '') {
        $file = str_replace('\\', '/', $file);
        if (strncasecmp($file, $root . '/', strlen($root) + 1) === 0) {
            $rel = substr($file, strlen($root));
            if (strlen($name) >= strlen($rel) && strcasecmp(substr($name, -strlen($rel)), $rel) === 0) {
                return $base = rtrim(substr($name, 0, strlen($name) - strlen($rel)), '/') . '/';
            }
        }
    }

    // No web request to read it from (command-line tests): the app folder sits directly under the web root.
    return $base = '/' . basename($root) . '/';
}

/** Absolute URL to a path inside the app, for links sent out by email or encoded in QR codes. */
function app_url(string $path = ''): string
{
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return (app_is_https() ? 'https' : 'http') . '://' . $host . app_base_path() . ltrim($path, '/');
}
