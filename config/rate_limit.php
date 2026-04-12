<?php
/**
 * config/rate_limit.php
 * IP-based rate limiting for auth endpoints.
 *
 * Usage:
 *   require_once __DIR__ . '/rate_limit.php';
 *   rate_limit_check($conn, 'login');        // throws header redirect on block
 *   rate_limit_record($conn, 'login');       // call after a failed attempt
 *   rate_limit_clear($conn, 'login');        // call after a successful attempt
 *
 * Limits (per IP per endpoint):
 *   max 5 attempts within a 15-minute rolling window
 */

define('RL_MAX_ATTEMPTS', 5);
define('RL_WINDOW_SECS',  15 * 60);   // 15 minutes

/**
 * Get the real client IP, accounting for proxies.
 */
function rl_get_ip(): string {
    foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'] as $key) {
        if (!empty($_SERVER[$key])) {
            // X-Forwarded-For can be a comma-separated list — take the first
            $ip = trim(explode(',', $_SERVER[$key])[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }
    }
    return '0.0.0.0';
}

/**
 * Count recent attempts for this IP + endpoint within the rolling window.
 */
function rl_count(mysqli $conn, string $endpoint): int {
    $ip      = rl_get_ip();
    $cutoff  = date('Y-m-d H:i:s', time() - RL_WINDOW_SECS);
    $stmt    = $conn->prepare(
        "SELECT COUNT(*) FROM rate_limit_attempts
         WHERE ip = ? AND endpoint = ? AND attempted_at >= ?"
    );
    $stmt->bind_param('sss', $ip, $endpoint, $cutoff);
    $stmt->execute();
    $stmt->bind_result($count);
    $stmt->fetch();
    $stmt->close();
    return (int)$count;
}

/**
 * Check if the current IP is rate-limited.
 * If blocked, sets HTTP 429 and terminates with a JSON or HTML error.
 */
function rate_limit_check(mysqli $conn, string $endpoint): void {
    // Prune old records first (keep table small)
    $cutoff = date('Y-m-d H:i:s', time() - RL_WINDOW_SECS);
    $conn->query("DELETE FROM rate_limit_attempts WHERE attempted_at < '$cutoff'");

    if (rl_count($conn, $endpoint) >= RL_MAX_ATTEMPTS) {
        http_response_code(429);
        // Return JSON for AJAX calls, plain message otherwise
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) || str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json')) {
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Too many attempts. Please wait 15 minutes before trying again.']);
        } else {
            // Store error in session so the page can display it
            if (session_status() === PHP_SESSION_NONE) session_start();
            $_SESSION['rate_limit_error'] = 'Too many attempts from your IP address. Please wait 15 minutes before trying again.';
            // Redirect back to the same page
            $redirect = $_SERVER['HTTP_REFERER'] ?? '../auth/login.php';
            header("Location: $redirect");
        }
        exit;
    }
}

/**
 * Record a failed attempt for this IP + endpoint.
 */
function rate_limit_record(mysqli $conn, string $endpoint): void {
    $ip   = rl_get_ip();
    $now  = date('Y-m-d H:i:s');
    $stmt = $conn->prepare(
        "INSERT INTO rate_limit_attempts (ip, endpoint, attempted_at) VALUES (?, ?, ?)"
    );
    $stmt->bind_param('sss', $ip, $endpoint, $now);
    $stmt->execute();
    $stmt->close();
}

/**
 * Clear all attempts for this IP + endpoint (e.g. on successful login).
 */
function rate_limit_clear(mysqli $conn, string $endpoint): void {
    $ip   = rl_get_ip();
    $stmt = $conn->prepare(
        "DELETE FROM rate_limit_attempts WHERE ip = ? AND endpoint = ?"
    );
    $stmt->bind_param('ss', $ip, $endpoint);
    $stmt->execute();
    $stmt->close();
}
