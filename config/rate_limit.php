<?php
/**
 * config/rate_limit.php
 * Attempt limiting for auth endpoints and the transaction-PIN check.
 *
 * Usage:
 *   require_once __DIR__ . '/rate_limit.php';
 *   rate_limit_check($conn, 'login');        // throws header redirect on block
 *   rate_limit_record($conn, 'login');       // call after a failed attempt
 *   rate_limit_clear($conn, 'login');        // call after a successful attempt
 *
 * By default a bucket is keyed on the client IP. Pass an explicit $key (e.g. 'uid:12')
 * to key it on something else — used for the PIN checks, where the caller is already
 * logged in, so the limit follows the account rather than a spoofable network address.
 * The key lives in the existing `ip` column (varchar 45), so keep it short.
 *
 * Limits (per key per endpoint):
 *   max 5 attempts within a 5-minute rolling window
 */

define('RL_MAX_ATTEMPTS', 5);
define('RL_WINDOW_SECS',  5 * 60);    // 5 minutes

/**
 * The connecting client's IP. Deliberately REMOTE_ADDR only: X-Forwarded-For,
 * X-Real-IP and CF-Connecting-IP are ordinary request headers the client controls,
 * so trusting them lets an attacker present a fresh "IP" on every request and
 * never accumulate attempts against the same bucket.
 */
function rl_get_ip(): string {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    return filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : '0.0.0.0';
}

/**
 * Count recent attempts for this key (default: IP) + endpoint within the rolling window.
 */
function rl_count(mysqli $conn, string $endpoint, ?string $key = null): int {
    $id      = $key ?? rl_get_ip();
    $cutoff  = date('Y-m-d H:i:s', time() - RL_WINDOW_SECS);
    $stmt    = $conn->prepare(
        "SELECT COUNT(*) FROM rate_limit_attempts
         WHERE ip = ? AND endpoint = ? AND attempted_at >= ?"
    );
    $stmt->bind_param('sss', $id, $endpoint, $cutoff);
    $stmt->execute();
    $stmt->bind_result($count);
    $stmt->fetch();
    $stmt->close();
    return (int)$count;
}

/**
 * True if this key (default: IP) has used up its attempts for the endpoint.
 * Prunes expired rows first to keep the table small. Doesn't send any response —
 * callers that need a JSON error (AJAX endpoints) use this directly.
 */
function rate_limit_blocked(mysqli $conn, string $endpoint, ?string $key = null): bool {
    $cutoff = date('Y-m-d H:i:s', time() - RL_WINDOW_SECS);
    $prune = $conn->prepare("DELETE FROM rate_limit_attempts WHERE attempted_at < ?");
    $prune->bind_param('s', $cutoff);
    $prune->execute();
    $prune->close();

    return rl_count($conn, $endpoint, $key) >= RL_MAX_ATTEMPTS;
}

/**
 * Check if the current IP is rate-limited.
 * If blocked, sets HTTP 429 and terminates with a JSON or HTML error.
 */
function rate_limit_check(mysqli $conn, string $endpoint): void {
    if (rate_limit_blocked($conn, $endpoint)) {
        http_response_code(429);
        // Return JSON for AJAX calls, plain message otherwise
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) || str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json')) {
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Too many attempts. Please wait 5 minutes before trying again.']);
        } else {
            // Store error in session so the page can display it
            if (session_status() === PHP_SESSION_NONE) session_start();
            $_SESSION['rate_limit_error'] = 'Too many attempts from your IP address. Please wait 5 minutes before trying again.';
            // Validate referer is same host before redirecting — prevent open redirect
            $redirect = '../auth/login.php';
            if (!empty($_SERVER['HTTP_REFERER'])) {
                $ref_host = parse_url($_SERVER['HTTP_REFERER'], PHP_URL_HOST);
                if ($ref_host !== '' && $ref_host === ($_SERVER['HTTP_HOST'] ?? '')) {
                    $redirect = $_SERVER['HTTP_REFERER'];
                }
            }
            header("Location: $redirect");
        }
        exit;
    }
}

/**
 * Record a failed attempt for this key (default: IP) + endpoint.
 */
function rate_limit_record(mysqli $conn, string $endpoint, ?string $key = null): void {
    $id   = $key ?? rl_get_ip();
    $now  = date('Y-m-d H:i:s');
    $stmt = $conn->prepare(
        "INSERT INTO rate_limit_attempts (ip, endpoint, attempted_at) VALUES (?, ?, ?)"
    );
    $stmt->bind_param('sss', $id, $endpoint, $now);
    $stmt->execute();
    $stmt->close();
}

/**
 * Clear all attempts for this key (default: IP) + endpoint (e.g. on successful login).
 */
function rate_limit_clear(mysqli $conn, string $endpoint, ?string $key = null): void {
    $id   = $key ?? rl_get_ip();
    $stmt = $conn->prepare(
        "DELETE FROM rate_limit_attempts WHERE ip = ? AND endpoint = ?"
    );
    $stmt->bind_param('ss', $id, $endpoint);
    $stmt->execute();
    $stmt->close();
}
