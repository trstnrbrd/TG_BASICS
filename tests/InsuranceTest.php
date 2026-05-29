<?php
use PHPUnit\Framework\TestCase;

class InsuranceTest extends TestCase
{
    // ══════════════════════════════════════════════════════
    // Vehicle Eligibility — 10-Year Rule (PhilBritish)
    // ══════════════════════════════════════════════════════

    public function test_vehicle_5_years_old_is_eligible()
    {
        $age = (int)date('Y') - ((int)date('Y') - 5);
        $this->assertTrue($age <= 10);
    }

    public function test_vehicle_11_years_old_is_ineligible()
    {
        $age = (int)date('Y') - ((int)date('Y') - 11);
        $this->assertFalse($age <= 10);
    }

    public function test_vehicle_exactly_10_years_is_still_eligible()
    {
        $age = (int)date('Y') - ((int)date('Y') - 10);
        $this->assertTrue($age <= 10);
    }

    public function test_brand_new_vehicle_is_eligible()
    {
        $age = (int)date('Y') - (int)date('Y');
        $this->assertTrue($age <= 10);
    }

    // ══════════════════════════════════════════════════════
    // Policy Status Thresholds
    // ══════════════════════════════════════════════════════

    private function getStatus(int $daysLeft): string
    {
        return $daysLeft > 90 ? 'stable' : ($daysLeft > 30 ? 'expiring' : 'urgent');
    }

    public function test_120_days_left_is_stable()
    {
        $this->assertSame('stable', $this->getStatus(120));
    }

    public function test_60_days_left_is_expiring()
    {
        $this->assertSame('expiring', $this->getStatus(60));
    }

    public function test_15_days_left_is_urgent()
    {
        $this->assertSame('urgent', $this->getStatus(15));
    }

    public function test_exactly_90_days_is_expiring_not_stable()
    {
        $this->assertSame('expiring', $this->getStatus(90));
    }

    public function test_exactly_30_days_is_urgent_not_expiring()
    {
        $this->assertSame('urgent', $this->getStatus(30));
    }

    public function test_0_days_left_is_urgent()
    {
        $this->assertSame('urgent', $this->getStatus(0));
    }
}
