<?php
/**
 * Builds the full URL to a page, for links sent in emails and QR codes.
 *   app_url('auth/activate.php')  ->  https://host/TG-BASICS/auth/activate.php
 */

/** True if the request is HTTPS. */
function app_is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
}

/** The app's folder on the web, e.g. "/TG-BASICS/". */
function app_base_path(): string
{
    static $base = null;
    if ($base !== null) return $base;

    $root = str_replace('\\', '/', (string)realpath(dirname(__DIR__)));

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

    return $base = '/' . basename($root) . '/';
}

/** Full URL to a page inside the app. */
function app_url(string $path = ''): string
{
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return (app_is_https() ? 'https' : 'http') . '://' . $host . app_base_path() . ltrim($path, '/');
}
