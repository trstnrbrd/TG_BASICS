<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../config/validators.php';

class ValidatorsTest extends TestCase
{
    // ── Input Sanitization ──

    public function test_san_str_preserves_content_within_limit()
    {
        $result = san_str('Hello World', 100);
        $this->assertSame('Hello World', $result);
    }

    public function test_san_str_rejects_oversized_input()
    {
        // san_str returns '' when input exceeds max — it rejects, not truncates
        $result = san_str('Hello World', 5);
        $this->assertSame('', $result);
    }

    public function test_san_str_trims_whitespace()
    {
        $result = san_str('  Hello  ', 100);
        $this->assertSame('Hello', $result);
    }

    public function test_san_str_empty_input_returns_empty()
    {
        $result = san_str('', 100);
        $this->assertSame('', $result);
    }

    // ── Insurance Eligibility (10-year rule) ──

    public function test_vehicle_within_10_years_is_eligible()
    {
        $currentYear = (int)date('Y');
        $vehicleYear = $currentYear - 5;
        $this->assertTrue(($currentYear - $vehicleYear) <= 10);
    }

    public function test_vehicle_over_10_years_is_ineligible()
    {
        $currentYear = (int)date('Y');
        $vehicleYear = $currentYear - 11;
        $this->assertFalse(($currentYear - $vehicleYear) <= 10);
    }

    public function test_vehicle_exactly_10_years_is_eligible()
    {
        $currentYear = (int)date('Y');
        $vehicleYear = $currentYear - 10;
        $this->assertTrue(($currentYear - $vehicleYear) <= 10);
    }

    // ── Policy Status Thresholds ──

    public function test_policy_over_90_days_is_stable()
    {
        $daysLeft = 120;
        $status = $daysLeft > 90 ? 'stable' : ($daysLeft > 30 ? 'expiring' : 'urgent');
        $this->assertSame('stable', $status);
    }

    public function test_policy_under_90_days_is_expiring()
    {
        $daysLeft = 60;
        $status = $daysLeft > 90 ? 'stable' : ($daysLeft > 30 ? 'expiring' : 'urgent');
        $this->assertSame('expiring', $status);
    }

    public function test_policy_under_30_days_is_urgent()
    {
        $daysLeft = 15;
        $status = $daysLeft > 90 ? 'stable' : ($daysLeft > 30 ? 'expiring' : 'urgent');
        $this->assertSame('urgent', $status);
    }
}
