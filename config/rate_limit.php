<?php
/**
 * Blocks an IP (or user) after too many failed attempts. Used for login, 2FA, and the Transaction PIN.
 * Limit: 5 attempts per 5 minutes.
 *
 * Usage:
 *   rate_limit_check($conn, 'login');    // stops the page if blocked
 *   rate_limit_record($conn, 'login');   // call after a failed attempt
 *   rate_limit_clear($conn, 'login');    // call after success
 */

define('RL_MAX_ATTEMPTS', 5);
define('RL_WINDOW_SECS',  5 * 60);    // 5 minutes

/** The visitor's real IP address. */
function rl_get_ip(): string {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    return filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : '0.0.0.0';
}

/** How many attempts in the last 5 minutes. */
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

/** Has this IP/user used up its attempts? */
function rate_limit_blocked(mysqli $conn, string $endpoint, ?string $key = null): bool {
    $cutoff = date('Y-m-d H:i:s', time() - RL_WINDOW_SECS);
    $prune = $conn->prepare("DELETE FROM rate_limit_attempts WHERE attempted_at < ?");
    $prune->bind_param('s', $cutoff);
    $prune->execute();
    $prune->close();

    return rl_count($conn, $endpoint, $key) >= RL_MAX_ATTEMPTS;
}

/** Stops the page with an error if this IP is blocked. */
function rate_limit_check(mysqli $conn, string $endpoint): void {
    if (rate_limit_blocked($conn, $endpoint)) {
        http_response_code(429);
        // Return JSON for AJAX calls, plain message otherwise
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) || str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json')) {
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Too many attempts. Please wait 5 minutes before trying again.']);
        } else {
            if (session_status() === PHP_SESSION_NONE) session_start();
            $_SESSION['rate_limit_error'] = 'Too many attempts from your IP address. Please wait 5 minutes before trying again.';
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

/** Save a failed attempt. */
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

/** Clear attempts after a successful login/action. */
function rate_limit_clear(mysqli $conn, string $endpoint, ?string $key = null): void {
    $id   = $key ?? rl_get_ip();
    $stmt = $conn->prepare(
        "DELETE FROM rate_limit_attempts WHERE ip = ? AND endpoint = ?"
    );
    $stmt->bind_param('ss', $id, $endpoint);
    $stmt->execute();
    $stmt->close();
}
