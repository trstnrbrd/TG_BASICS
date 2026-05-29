<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../config/validators.php';

class ValidatorsTest extends TestCase
{
    // ══════════════════════════════════════════════════════
    // san_str — String Sanitizer
    // ══════════════════════════════════════════════════════

    public function test_san_str_returns_value_within_limit()
    {
        $this->assertSame('Hello', san_str('Hello', 100));
    }

    public function test_san_str_trims_whitespace()
    {
        $this->assertSame('Hello', san_str('  Hello  ', 100));
    }

    public function test_san_str_rejects_oversized_input()
    {
        $this->assertSame('', san_str('Hello World', 5));
    }

    public function test_san_str_empty_returns_empty()
    {
        $this->assertSame('', san_str('', 100));
    }

    // ══════════════════════════════════════════════════════
    // san_int — Integer Sanitizer
    // ══════════════════════════════════════════════════════

    public function test_san_int_returns_valid_integer()
    {
        $this->assertSame(5, san_int(5, 1, 10));
    }

    public function test_san_int_rejects_below_minimum()
    {
        $this->assertSame(0, san_int(-1, 0));
    }

    public function test_san_int_rejects_above_maximum()
    {
        $this->assertSame(0, san_int(999, 0, 100));
    }

    public function test_san_int_rejects_non_numeric_string()
    {
        $this->assertSame(0, san_int('abc', 0));
    }

    // ══════════════════════════════════════════════════════
    // san_float — Float Sanitizer
    // ══════════════════════════════════════════════════════

    public function test_san_float_returns_valid_float()
    {
        $this->assertSame(3.14, san_float(3.14));
    }

    public function test_san_float_rejects_negative()
    {
        $this->assertSame(0.0, san_float(-5.0));
    }

    public function test_san_float_rejects_non_numeric()
    {
        $this->assertSame(0.0, san_float('abc'));
    }

    // ══════════════════════════════════════════════════════
    // san_enum — Whitelist Checker
    // ══════════════════════════════════════════════════════

    public function test_san_enum_returns_value_if_in_whitelist()
    {
        $this->assertSame('Cash', san_enum('Cash', ['Cash', 'Check', 'E-Wallet']));
    }

    public function test_san_enum_rejects_value_not_in_whitelist()
    {
        $this->assertSame('', san_enum('Bitcoin', ['Cash', 'Check', 'E-Wallet']));
    }

    public function test_san_enum_trims_before_checking()
    {
        $this->assertSame('Cash', san_enum('  Cash  ', ['Cash', 'Check']));
    }

    // ══════════════════════════════════════════════════════
    // validate_name
    // ══════════════════════════════════════════════════════

    public function test_validate_name_accepts_valid_name()
    {
        $this->assertTrue(validate_name('Juan dela Cruz'));
    }

    public function test_validate_name_accepts_name_with_hyphen()
    {
        $this->assertTrue(validate_name('Mary-Jane Watson'));
    }

    public function test_validate_name_rejects_empty()
    {
        $this->assertFalse(validate_name(''));
    }

    public function test_validate_name_rejects_numbers()
    {
        $this->assertFalse(validate_name('Juan123'));
    }

    // ══════════════════════════════════════════════════════
    // validate_email
    // ══════════════════════════════════════════════════════

    public function test_validate_email_accepts_valid_email()
    {
        $this->assertTrue(validate_email('juan@gmail.com'));
    }

    public function test_validate_email_rejects_missing_at_sign()
    {
        $this->assertFalse(validate_email('juangmail.com'));
    }

    public function test_validate_email_rejects_empty()
    {
        $this->assertFalse(validate_email(''));
    }

    // ══════════════════════════════════════════════════════
    // validate_phone — PH Mobile Number (09XXXXXXXXX)
    // ══════════════════════════════════════════════════════

    public function test_validate_phone_accepts_valid_ph_number()
    {
        $this->assertTrue(validate_phone('09123456789'));
    }

    public function test_validate_phone_rejects_landline_format()
    {
        $this->assertFalse(validate_phone('028123456'));
    }

    public function test_validate_phone_rejects_short_number()
    {
        $this->assertFalse(validate_phone('0912345'));
    }

    public function test_validate_phone_rejects_letters()
    {
        $this->assertFalse(validate_phone('09abc56789'));
    }

    // ══════════════════════════════════════════════════════
    // validate_username
    // ══════════════════════════════════════════════════════

    public function test_validate_username_accepts_valid_username()
    {
        $this->assertTrue(validate_username('tristan_123'));
    }

    public function test_validate_username_rejects_too_short()
    {
        $this->assertFalse(validate_username('ab'));
    }

    public function test_validate_username_rejects_spaces()
    {
        $this->assertFalse(validate_username('tristan reboredo'));
    }

    // ══════════════════════════════════════════════════════
    // validate_password
    // ══════════════════════════════════════════════════════

    public function test_validate_password_accepts_strong_password()
    {
        $this->assertTrue(validate_password('Secure@123'));
    }

    public function test_validate_password_rejects_no_uppercase()
    {
        $this->assertFalse(validate_password('secure@123'));
    }

    public function test_validate_password_rejects_no_number()
    {
        $this->assertFalse(validate_password('Secure@abc'));
    }

    public function test_validate_password_rejects_no_special_char()
    {
        $this->assertFalse(validate_password('Secure1234'));
    }

    public function test_validate_password_rejects_too_short()
    {
        $this->assertFalse(validate_password('S@1ab'));
    }

    // ══════════════════════════════════════════════════════
    // validate_date
    // ══════════════════════════════════════════════════════

    public function test_validate_date_accepts_valid_date()
    {
        $this->assertTrue(validate_date('2026-05-29'));
    }

    public function test_validate_date_rejects_wrong_format()
    {
        $this->assertFalse(validate_date('29-05-2026'));
    }

    public function test_validate_date_rejects_invalid_date()
    {
        $this->assertFalse(validate_date('2026-13-01'));
    }

    public function test_validate_date_rejects_empty()
    {
        $this->assertFalse(validate_date(''));
    }

    // ══════════════════════════════════════════════════════
    // validate_plate — PH Plate Number
    // ══════════════════════════════════════════════════════

    public function test_validate_plate_accepts_valid_plate()
    {
        $this->assertTrue(validate_plate('ABC 1234'));
    }

    public function test_validate_plate_rejects_empty()
    {
        $this->assertFalse(validate_plate(''));
    }

    public function test_validate_plate_rejects_special_characters()
    {
        $this->assertFalse(validate_plate('AB@-123'));
    }

    // ══════════════════════════════════════════════════════
    // validate_year — Vehicle Year Model
    // ══════════════════════════════════════════════════════

    public function test_validate_year_accepts_current_year()
    {
        $this->assertTrue(validate_year((int)date('Y')));
    }

    public function test_validate_year_accepts_year_1990()
    {
        $this->assertTrue(validate_year(1990));
    }

    public function test_validate_year_rejects_year_before_1960()
    {
        $this->assertFalse(validate_year(1959));
    }

    public function test_validate_year_rejects_future_year()
    {
        $this->assertFalse(validate_year((int)date('Y') + 5));
    }

    // ══════════════════════════════════════════════════════
    // validate_amount — Premium / Billing Amounts
    // ══════════════════════════════════════════════════════

    public function test_validate_amount_accepts_positive_amount()
    {
        $this->assertTrue(validate_amount(5000.00));
    }

    public function test_validate_amount_accepts_zero()
    {
        $this->assertTrue(validate_amount(0));
    }

    public function test_validate_amount_rejects_negative()
    {
        $this->assertFalse(validate_amount(-100));
    }

    public function test_validate_amount_rejects_non_numeric()
    {
        $this->assertFalse(validate_amount('abc'));
    }

    // ══════════════════════════════════════════════════════
    // validate_token — Activation / Reset Tokens
    // ══════════════════════════════════════════════════════

    public function test_validate_token_accepts_valid_64_char_hex()
    {
        $token = bin2hex(random_bytes(32)); // generates valid 64-char hex
        $this->assertTrue(validate_token($token));
    }

    public function test_validate_token_rejects_too_short()
    {
        $this->assertFalse(validate_token('abc123'));
    }

    public function test_validate_token_rejects_non_hex_characters()
    {
        $this->assertFalse(validate_token(str_repeat('z', 64)));
    }

}
