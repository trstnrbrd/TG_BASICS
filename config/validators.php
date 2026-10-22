<?php
/**
 * Input validation, CSRF protection, and other helpers used across the app.
 */

// ── LENGTH LIMITS ──────────────────────────────────────────────────────────
const MAX_NAME        = 100;
const MAX_EMAIL       = 255;
const MAX_USERNAME    = 50;
const MAX_PASSWORD    = 128;
const MAX_ADDRESS     = 300;
const MAX_PHONE       = 15;
const MAX_PLATE       = 20;
const MAX_MAKE_MODEL  = 60;
const MAX_COLOR       = 50;
const MAX_MOTOR_SN    = 50;
const MAX_POLICY_NUM  = 60;
const MAX_MORTGAGEE   = 100;
const MAX_FACEBOOK    = 100;    // client's Facebook name (optional)
const MAX_TEXT        = 2000;   // descriptions, notes
const MAX_SEARCH      = 100;
const MAX_TOKEN       = 128;

// ── WHITELISTS ─────────────────────────────────────────────────────────────
const ALLOWED_COVERAGE_TYPES = [
    'Comprehensive',
    'Comprehensive w/o AON/AOG',
    'CTPL',
];

const ALLOWED_PAYMENT_TERMS = [
    '1 time',
    '2 months',
    '3 months',
    '4 months',
    '6 months',
    '12 months',
];

const ALLOWED_PAYMENT_MODES = [
    'Cash',
    'Bank Transfer',
    'Check',
    'E-Wallet',
    'Other',
];

const ALLOWED_CLAIM_TYPES = [
    'claims',
    'repair',
];

const ALLOWED_ROLES = [
    'admin',
    'super_admin',
    'mechanic',
];

const ALLOWED_DOC_FIELDS = [
    'doc_insurance_policy',
    'doc_or_cr',
    'doc_drivers_license',
    'doc_affidavit',
    'doc_repair_estimate',
    'doc_damage_photos',
    'doc_other',
];

// ── SANITIZERS ────────────────────────────────────────────────────────────

/** Trim and limit length. Returns '' if too long or not a plain string. */
function san_str(mixed $value, int $max = MAX_TEXT): string {
    if (!is_scalar($value)) return '';
    $v = trim((string)$value);
    if (mb_strlen($v) > $max) return '';   // reject oversized
    return $v;
}

/**
 * Re-fill a form field with what the user typed, after a validation error.
 * Usage: value="<?= old('full_name') ?>"
 */
function old(string $key, mixed $default = ''): string {
    $v = $_POST[$key] ?? $default;
    return htmlspecialchars(is_scalar($v) ? (string)$v : '');
}

/** Cast to a whole number. Returns 0 if invalid. */
function san_int(mixed $value, int $min = 0, int $max = PHP_INT_MAX): int {
    $v = (int)$value;
    if ($v < $min || $v > $max) return 0;
    return $v;
}

/** Cast to a decimal number. Returns 0.0 if invalid. */
function san_float(mixed $value, float $min = 0.0, float $max = 999_999_999.99): float {
    if (!is_numeric($value)) return 0.0;
    $v = (float)$value;
    if ($v < $min || $v > $max) return 0.0;
    return $v;
}

/** Same as san_float(), but also accepts "500,000.50" or "₱ 500,000" from the money input field. */
function san_money(mixed $value, float $min = 0.0, float $max = 999_999_999.99): float {
    if (is_string($value)) $value = str_replace([',', ' ', '₱'], '', $value);
    return san_float($value, $min, $max);
}

/** Returns the value only if it is in the allowed list, otherwise ''. */
function san_enum(mixed $value, array $allowed): string {
    if (!is_scalar($value)) return '';
    $v = trim((string)$value);
    return in_array($v, $allowed, true) ? $v : '';
}

// ── VALIDATORS ────────────────────────────────────────────────────────────

/** Full name: letters, spaces, hyphens, apostrophes only. */
function validate_name(string $v): bool {
    return $v !== '' && mb_strlen($v) <= MAX_NAME && preg_match('/^[\pL\s\'\-\.]+$/u', $v);
}

/** Valid email address format. */
function validate_email(string $v): bool {
    return mb_strlen($v) <= MAX_EMAIL && filter_var($v, FILTER_VALIDATE_EMAIL) !== false;
}

/** Philippine mobile number: 09XXXXXXXXX. */
function validate_phone(string $v): bool {
    $digits = preg_replace('/\D/', '', $v);
    return preg_match('/^09\d{9}$/', $digits) === 1;
}

/** Username: 3–50 letters, numbers, or underscores. */
function validate_username(string $v): bool {
    return preg_match('/^[a-zA-Z0-9_]{3,' . MAX_USERNAME . '}$/', $v) === 1;
}

/** Password: 8–128 chars, needs an uppercase letter, a number, and a special character. */
function validate_password(string $v): bool {
    if (mb_strlen($v) < 8 || mb_strlen($v) > MAX_PASSWORD) return false;
    if (!preg_match('/[A-Z]/', $v))          return false;
    if (!preg_match('/[0-9]/', $v))          return false;
    if (!preg_match('/[^a-zA-Z0-9]/', $v))  return false;
    return true;
}

/** Date in YYYY-MM-DD format. */
function validate_date(string $v): bool {
    if ($v === '') return false;
    $d = DateTime::createFromFormat('Y-m-d', $v);
    return $d && $d->format('Y-m-d') === $v;
}

/** Valid date, and not a future date. */
function validate_date_not_future(string $v): bool {
    return validate_date($v) && strtotime($v) <= time();
}

/** Vehicle plate number: letters, numbers, spaces, hyphens. */
function validate_plate(string $v): bool {
    return $v !== '' && mb_strlen($v) <= MAX_PLATE && preg_match('/^[A-Z0-9\-\s]+$/', strtoupper($v));
}

/** Vehicle year, from 1960 up to next year. */
function validate_year(int $v): bool {
    return $v >= 1960 && $v <= ((int)date('Y') + 1);
}

/** Policy number: letters, numbers, hyphens. */
function validate_policy_number(string $v): bool {
    return $v !== '' && mb_strlen($v) <= MAX_POLICY_NUM && preg_match('/^[A-Z0-9\-]+$/i', $v);
}

/** Activation / password reset token: exactly 64 hex characters. */
function validate_token(string $v): bool {
    return preg_match('/^[a-f0-9]{64}$/', $v) === 1;
}

/** Clean up a search box value. */
function validate_search(string $v): string {
    $v = trim(preg_replace('/[\x00-\x1F\x7F]/', '', $v));
    return mb_strlen($v) <= MAX_SEARCH ? $v : mb_substr($v, 0, MAX_SEARCH);
}

/** A valid peso amount (0 or more). */
function validate_amount(mixed $v): bool {
    return is_numeric($v) && (float)$v >= 0 && (float)$v <= 999_999_999.99;
}

// ── CSRF PROTECTION ───────────────────────────────────────────────────────────
// A hidden token on every form, so a request must come from our own page, not another website.

/** Get the session's CSRF token (makes one if it doesn't exist yet). */
function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/** Check the form's CSRF token. Stops the page with a 403 error if it's wrong or missing. */
function csrf_verify(): void {
    $submitted = $_POST['csrf_token'] ?? '';
    if (!is_string($submitted) || !isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $submitted)) {
        http_response_code(403);
        if (str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json')) {
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'msg' => 'Your session expired. Please refresh the page and try again.', 'error' => 'Invalid or missing CSRF token.']);
            exit;
        }
        die('Invalid or missing CSRF token. Please go back and try again.');
    }
}

/** Same as csrf_verify(), but always answers in JSON (for AJAX calls). */
function csrf_verify_json(): void {
    $submitted = $_POST['csrf_token'] ?? '';
    if (!is_string($submitted) || !isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $submitted)) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'msg' => 'Your session expired. Please refresh the page and try again.', 'error' => 'Invalid or missing CSRF token.']);
        exit;
    }
}

// ── SEQUENTIAL NUMBER GENERATION (RJ-/BILL-/Q- style) ──────────────────────

/**
 * Makes the next number for a job/billing/quotation (highest number + 1).
 * If two people save at the same time and get the same number, this retries with a new one
 * instead of crashing.
 */
function insert_with_sequential_number(mysqli $conn, string $table, string $column, string $prefix, callable $attempt, int $max_tries = 5): mixed {
    $like = $prefix . '%';
    for ($try = 1; $try <= $max_tries; $try++) {
        $stmt = $conn->prepare("SELECT `$column` FROM `$table` WHERE `$column` LIKE ? ORDER BY `$column` DESC LIMIT 1");
        $stmt->bind_param('s', $like);
        $stmt->execute();
        $last   = $stmt->get_result()->fetch_row();
        $seq    = $last ? (int)substr($last[0], -4) + 1 : 1;
        $number = $prefix . str_pad((string)$seq, 4, '0', STR_PAD_LEFT);
        try {
            return $attempt($number);
        } catch (mysqli_sql_exception $e) {
            $collided = str_contains($e->getMessage(), 'Duplicate entry') && str_contains($e->getMessage(), "'$column'");
            if (!$collided || $try === $max_tries) throw $e;
            // someone else just took this number — try again with a new one
        }
    }
}

// ── CLOSING CHECK-THEN-WRITE RACES (e.g. "is this plate number free?") ─────

/**
 * Runs $work() while holding a lock on $name, so two requests can't both pass a
 * "does this already exist?" check and insert a duplicate at the same time.
 * Returns ['locked' => false] if the lock could not be taken, else ['locked' => true, 'result' => ...].
 */
function with_named_lock(mysqli $conn, string $name, callable $work, int $wait_seconds = 5): array {
    $lock_name = named_lock_acquire($conn, $name, $wait_seconds);
    if ($lock_name === null) {
        return ['locked' => false];
    }
    try {
        return ['locked' => true, 'result' => $work()];
    } finally {
        named_lock_release($conn, $lock_name);
    }
}

/** Takes a lock by name. Returns the lock name (pass it to named_lock_release()), or null if it timed out. */
function named_lock_acquire(mysqli $conn, string $name, int $wait_seconds = 5): ?string {
    $lock_name = named_lock_name($name);
    $stmt = $conn->prepare('SELECT GET_LOCK(?, ?)');
    $stmt->bind_param('si', $lock_name, $wait_seconds);
    $stmt->execute();
    return (int)$stmt->get_result()->fetch_row()[0] === 1 ? $lock_name : null;
}

/** Turns $name into a valid MySQL lock name. */
function named_lock_name(string $name): string {
    return 'tgb_' . substr(preg_replace('/[^A-Za-z0-9_]/', '_', $name), 0, 55);
}

/** Releases a lock taken by named_lock_acquire(). */
function named_lock_release(mysqli $conn, string $lock_name): void {
    $rel = $conn->prepare('SELECT RELEASE_LOCK(?)');
    $rel->bind_param('s', $lock_name);
    $rel->execute();
    $rel->get_result();
}

/** Hidden CSRF input for a <form>. Usage: <?= csrf_field() ?> */
function csrf_field(): string {
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token()) . '"/>';
}
