<?php
/**
 * Live updates through Pusher Channels. Only a SIGNAL goes out ("claims changed", with the record id),
 * never a name, a number or any client data: the page that receives it asks our own server for the data.
 *
 *   realtime_signal('claims', ['id' => 12]);   // after a save. Never throws; returns false if it could not send.
 *   realtime_enabled()                          // false until config/pusher.php is filled in
 *
 * Uses plain curl and a signed request (no Composer package: vendor/ is not deployed). A short timeout and a
 * swallowed failure keep a Pusher outage from ever breaking the save that triggered the signal.
 */

function realtime_enabled(): bool
{
    static $on = null;
    if ($on !== null) return $on;
    $file = __DIR__ . '/../config/pusher.php';
    if (is_file($file)) require_once $file;
    return $on = defined('PUSHER_APP_ID') && PUSHER_APP_ID !== '' && defined('PUSHER_KEY') && PUSHER_KEY !== ''
        && defined('PUSHER_SECRET') && PUSHER_SECRET !== '' && defined('PUSHER_CLUSTER') && PUSHER_CLUSTER !== '';
}

/** Public values the browser needs to listen (the secret is never among them). */
function realtime_client_config(): array
{
    return realtime_enabled() ? ['key' => PUSHER_KEY, 'cluster' => PUSHER_CLUSTER] : [];
}

/** Tell every open page that $topic changed. $info is small and harmless, e.g. ['id' => 12]. */
function realtime_signal(string $topic, array $info = []): bool
{
    return realtime_publish('tg-live', 'changed', ['topic' => $topic] + $info, $error);
}

/** Which live topic a page's saves belong to: its module folder. null = never signals (sign-in, downloads, lookups). */
function realtime_topic_for_script(string $script): ?string
{
    $script = str_replace('\\', '/', $script);
    if (!preg_match('#/modules/([a-z_]+)/([a-z_]+)\.php$#i', $script, $m)) return null;   // auth/, ajax/, cron/ ...
    if ($m[1] === 'files' || $m[1] === 'public' || $m[2] === 'backup_database') return null;
    return ['insurance' => 'policies', 'renewal' => 'policies', 'repair' => 'repairs'][$m[1]] ?? $m[1];
}

/**
 * Called once from config/session.php. After a POST that saved something, every open page hears about it.
 * "Saved" = a redirect that is not an ?error= one, or an api_success() reply. A form shown again with errors,
 * a refused PIN or a failed CSRF check sends nothing. The signal goes out after the reply has been handed over,
 * so it never slows the save down.
 */
function realtime_watch_request(mysqli $conn): void
{
    if (PHP_SAPI === 'cli' && getenv('TG_REALTIME_LOG') === false) return;   // CLI (cron, tests) only when a test asks
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') return;
    $topic = realtime_topic_for_script($_SERVER['SCRIPT_NAME'] ?? '');
    if ($topic === null) return;
    $last_log = fn() => (int)$conn->query("SELECT COALESCE(MAX(log_id), 0) FROM audit_logs")->fetch_row()[0];
    $before   = $last_log();
    register_shutdown_function(function () use ($topic, $last_log, $before) {
        if (http_response_code() >= 400) return;
        // Saved = a new activity-log row (nearly every save writes one), an api_success() reply, or a redirect
        // that is not an ?error= one (headers_list() is empty under the CLI, so that one is a web-only extra).
        $location = null;
        foreach (headers_list() as $h) if (stripos($h, 'Location:') === 0) $location = $h;
        $redirect_ok = $location !== null && !preg_match('/[?&](error|pay_error)=/i', $location);
        try { $logged = $last_log() > $before; } catch (Throwable $e) { $logged = false; }
        $saved = (($GLOBALS['tg_api_ok'] ?? null) === true) || ($location === null || $redirect_ok) && $logged || $redirect_ok;
        if (!$saved) return;
        if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
        // The browser tab that saved ignores its own signal: it already shows the change, and reloading it would
        // drop the "saved" message. A random id per tab (realtime.js) sent with every save, never a person.
        $tab = $_POST['_live_tab'] ?? ($_SERVER['HTTP_X_LIVE_TAB'] ?? '');
        realtime_signal($topic, is_string($tab) && preg_match('/^[a-z0-9]{8,32}$/', $tab) ? ['tab' => $tab] : []);
    });
}

/** Low-level send. Returns true when Pusher accepted it. */
function realtime_publish(string $channel, string $event, array $data, ?string &$error = null): bool
{
    // tests: write the signal to a file instead of sending it
    if (($log = getenv('TG_REALTIME_LOG')) !== false && $log !== '') {
        file_put_contents($log, json_encode(['channel' => $channel, 'event' => $event, 'data' => $data]) . "\n", FILE_APPEND);
        return true;
    }
    if (!realtime_enabled()) { $error = 'not configured'; return false; }
    if (!function_exists('curl_init')) { $error = 'curl is not available'; return false; }

    $path = '/apps/' . PUSHER_APP_ID . '/events';
    $msg  = ['name' => $event, 'channels' => [$channel], 'data' => json_encode($data)];
    $body = json_encode($msg);
    $q = ['auth_key' => PUSHER_KEY, 'auth_timestamp' => (string)time(), 'auth_version' => '1.0', 'body_md5' => md5($body)];
    ksort($q);
    $qs  = http_build_query($q);
    $sig = hash_hmac('sha256', "POST\n" . $path . "\n" . $qs, PUSHER_SECRET);
    $url = 'https://api-' . PUSHER_CLUSTER . '.pusher.com' . $path . '?' . $qs . '&auth_signature=' . $sig;

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_CONNECTTIMEOUT => 2, CURLOPT_TIMEOUT => 3,
    ]);
    $out  = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($code === 200) return true;
    $error = $err !== '' ? $err : ('HTTP ' . $code . ' ' . substr((string)$out, 0, 120));
    error_log('[TG-BASICS] realtime_publish failed: ' . $error);
    return false;
}
