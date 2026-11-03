<?php
/**
 * Circuit breaker for outside services (Pusher, OCR.space). When a service fails BREAKER_FAILURES times in a
 * row, the breaker "opens" and the app stops calling it for BREAKER_COOLDOWN seconds, so a page never waits on
 * a timeout that is sure to come. After the cooldown the next call is let through as a trial: success closes
 * the breaker, another failure opens it again for a full cooldown.
 *
 *   $r = breaker_call($conn, 'ocr', fn() => ...);   // null = breaker open, the call was skipped
 *
 * The callable returns ['ok' => bool, ...]; only ok === false counts as a failure of the SERVICE (a timeout,
 * an HTTP error), never a bad upload or "no text found".
 *
 * State lives in system_settings as `breaker_<service>` = {"fails":n,"open_until":unix time}, read fresh from
 * the table each time (getSetting() caches per request). A broken breaker never blocks the call: if its state
 * cannot be read or saved, the service is simply called as if there were no breaker.
 */

require_once __DIR__ . '/data_access.php';

const BREAKER_FAILURES = 3;     // failures in a row before the breaker opens
const BREAKER_COOLDOWN = 300;   // seconds the service is left alone once open

/** ['fails' => int, 'open_until' => int]; a closed, clean breaker when unset or unreadable. */
function breaker_state(mysqli $conn, string $service): array
{
    try {
        $raw = setting_read_fresh($conn, 'breaker_' . $service);
    } catch (Throwable $e) {
        $raw = null;
    }
    $s = $raw !== null ? json_decode($raw, true) : null;
    return ['fails' => (int)($s['fails'] ?? 0), 'open_until' => (int)($s['open_until'] ?? 0)];
}

/** True when the service may be called now (closed, or open but its cooldown is over). */
function breaker_allows(mysqli $conn, string $service, ?int $now = null): bool
{
    return breaker_state($conn, $service)['open_until'] <= ($now ?? time());
}

/** Seconds left before an open breaker lets a trial call through; 0 when it is not open. */
function breaker_seconds_left(mysqli $conn, string $service, ?int $now = null): int
{
    return max(0, breaker_state($conn, $service)['open_until'] - ($now ?? time()));
}

function breaker_save(mysqli $conn, string $service, int $fails, int $open_until): void
{
    try {
        setting_save($conn, 'breaker_' . $service, json_encode(['fails' => $fails, 'open_until' => $open_until]));
    } catch (Throwable $e) {
        error_log('[TG-BASICS] breaker state not saved for ' . $service . ': ' . $e->getMessage());
    }
}

/** A call worked: close the breaker. Writes only when there was something to clear. */
function breaker_success(mysqli $conn, string $service): void
{
    $s = breaker_state($conn, $service);
    if ($s['fails'] > 0 || $s['open_until'] > 0) breaker_save($conn, $service, 0, 0);
}

/** A call failed: count it, and open the breaker once the count reaches BREAKER_FAILURES. */
function breaker_failure(mysqli $conn, string $service, ?int $now = null): void
{
    $now   = $now ?? time();
    $fails = breaker_state($conn, $service)['fails'] + 1;
    $open  = $fails >= BREAKER_FAILURES ? $now + BREAKER_COOLDOWN : 0;
    if ($open) error_log("[TG-BASICS] breaker OPEN for $service after $fails failures; paused for " . BREAKER_COOLDOWN . ' s');
    breaker_save($conn, $service, $fails, $open);
}

/**
 * Runs $call unless the breaker is open. Returns $call's result, or null when it was skipped.
 * $conn null (no database at hand) = no breaker, just the call.
 */
function breaker_call(?mysqli $conn, string $service, callable $call, ?int $now = null): ?array
{
    if ($conn === null) return $call();
    if (!breaker_allows($conn, $service, $now)) return null;
    $r = $call();
    if (($r['ok'] ?? false) === true) breaker_success($conn, $service);
    else                              breaker_failure($conn, $service, $now);
    return $r;
}
