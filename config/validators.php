<?php
/**
 * config/validators.php
 * Centralized input sanitization and validation helpers for TG-BASICS.
 * Used across all auth and module pages.
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

/**
 * Trim and enforce max length. Returns string or '' if oversized.
 */
function san_str(string $value, int $max = MAX_TEXT): string {
    $v = trim($value);
    if (mb_strlen($v) > $max) return '';   // reject oversized
    return $v;
}

/**
 * Cast to positive integer. Returns 0 if invalid or negative.
 */
function san_int(mixed $value, int $min = 0, int $max = PHP_INT_MAX): int {
    $v = (int)$value;
    if ($v < $min || $v > $max) return 0;
    return $v;
}

/**
 * Cast to non-negative float. Returns 0.0 if invalid or negative.
 */
function san_float(mixed $value, float $min = 0.0, float $max = 999_999_999.99): float {
    if (!is_numeric($value)) return 0.0;
    $v = (float)$value;
    if ($v < $min || $v > $max) return 0.0;
    return $v;
}

/**
 * Whitelist check — returns value if in list, '' otherwise.
 */
function san_enum(string $value, array $allowed): string {
    $v = trim($value);
    return in_array($v, $allowed, true) ? $v : '';
}

// ── VALIDATORS ────────────────────────────────────────────────────────────

/**
 * Full name: letters, spaces, hyphens, apostrophes. Max MAX_NAME.
 */
function validate_name(string $v): bool {
    return $v !== '' && mb_strlen($v) <= MAX_NAME && preg_match('/^[\pL\s\'\-\.]+$/u', $v);
}

/**
 * Email address — RFC-compliant + max length.
 */
function validate_email(string $v): bool {
    return mb_strlen($v) <= MAX_EMAIL && filter_var($v, FILTER_VALIDATE_EMAIL) !== false;
}

/**
 * Philippine mobile number: 09XXXXXXXXX (11 digits).
 */
function validate_phone(string $v): bool {
    $digits = preg_replace('/\D/', '', $v);
    return preg_match('/^09\d{9}$/', $digits) === 1;
}

/**
 * Username: 3–50 alphanumeric or underscore chars.
 */
function validate_username(string $v): bool {
    return preg_match('/^[a-zA-Z0-9_]{3,' . MAX_USERNAME . '}$/', $v) === 1;
}

/**
 * Password: 8–128 chars, at least 1 uppercase, 1 digit, 1 special char.
 */
function validate_password(string $v): bool {
    if (mb_strlen($v) < 8 || mb_strlen($v) > MAX_PASSWORD) return false;
    if (!preg_match('/[A-Z]/', $v))          return false;
    if (!preg_match('/[0-9]/', $v))          return false;
    if (!preg_match('/[^a-zA-Z0-9]/', $v))  return false;
    return true;
}

/**
 * Date string in Y-m-d format.
 */
function validate_date(string $v): bool {
    if ($v === '') return false;
    $d = DateTime::createFromFormat('Y-m-d', $v);
    return $d && $d->format('Y-m-d') === $v;
}

/**
 * Date that is not in the future.
 */
function validate_date_not_future(string $v): bool {
    return validate_date($v) && strtotime($v) <= time();
}

/**
 * Plate number: alphanumeric + spaces/hyphens, max MAX_PLATE.
 */
function validate_plate(string $v): bool {
    return $v !== '' && mb_strlen($v) <= MAX_PLATE && preg_match('/^[A-Z0-9\-\s]+$/', strtoupper($v));
}

/**
 * Year model: 4-digit integer, 1960–(current year + 1).
 */
function validate_year(int $v): bool {
    return $v >= 1960 && $v <= ((int)date('Y') + 1);
}

/**
 * Policy number: alphanumeric + hyphens, max MAX_POLICY_NUM.
 */
function validate_policy_number(string $v): bool {
    return $v !== '' && mb_strlen($v) <= MAX_POLICY_NUM && preg_match('/^[A-Z0-9\-]+$/i', $v);
}

/**
 * Activation / reset token: 64 hex characters exactly.
 */
function validate_token(string $v): bool {
    return preg_match('/^[a-f0-9]{64}$/', $v) === 1;
}

/**
 * Search query: max MAX_SEARCH, strip control characters.
 */
function validate_search(string $v): string {
    $v = trim(preg_replace('/[\x00-\x1F\x7F]/', '', $v));
    return mb_strlen($v) <= MAX_SEARCH ? $v : mb_substr($v, 0, MAX_SEARCH);
}

/**
 * Numeric premium/amount: non-negative float within reasonable bounds.
 */
function validate_amount(mixed $v): bool {
    return is_numeric($v) && (float)$v >= 0 && (float)$v <= 999_999_999.99;
}

// ── CSRF PROTECTION ───────────────────────────────────────────────────────────

/**
 * Return the current session CSRF token, generating one if needed.
 */
function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Verify the CSRF token submitted with a form.
 * Call at the top of every POST handler. Terminates with 403 on failure.
 */
function csrf_verify(): void {
    $submitted = $_POST['csrf_token'] ?? '';
    if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $submitted)) {
        http_response_code(403);
        die('Invalid or missing CSRF token. Please go back and try again.');
    }
}

/**
 * Output a hidden CSRF input field for use inside <form> tags.
 * Usage: <?= csrf_field() ?>
 */
function csrf_field(): string {
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token()) . '"/>';
}
