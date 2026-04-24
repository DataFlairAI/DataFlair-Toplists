<?php
/**
 * Unit tests for DataFlair\Toplists\Support\WallClockBudget.
 *
 * Pure PHP — no WordPress, no Brain\Monkey, no fixtures. The class has no
 * dependencies so we can exercise it directly.
 */

use DataFlair\Toplists\Support\WallClockBudget;
use PHPUnit\Framework\TestCase;

class WallClockBudgetTest extends TestCase {

    public function test_fresh_budget_has_full_remaining_and_is_not_exceeded(): void {
        $budget = new WallClockBudget(2.0);

        $this->assertGreaterThan(1.9, $budget->remaining());
        $this->assertLessThanOrEqual(2.0, $budget->remaining());
        $this->assertFalse($budget->exceeded());
        $this->assertSame(2.0, $budget->total());
    }

    public function test_exceeded_becomes_true_after_sleep_past_budget(): void {
        $budget = new WallClockBudget(0.05);

        usleep(70_000); // 70 ms > 50 ms budget

        $this->assertTrue(
            $budget->exceeded(),
            'Budget must report exceeded once elapsed time surpasses total.'
        );
        $this->assertSame(
            0.0,
            $budget->remaining(),
            'remaining() must clamp to 0.0, not a negative number.'
        );
    }

    public function test_headroom_triggers_exceeded_before_hard_limit(): void {
        $budget = new WallClockBudget(0.10);

        usleep(60_000); // 60 ms elapsed, 40 ms left

        $this->assertFalse(
            $budget->exceeded(),
            'Budget is not strictly exceeded yet — 40 ms left.'
        );
        $this->assertTrue(
            $budget->exceeded(0.05),
            'With 50 ms headroom the budget should report exceeded early.'
        );
    }

    public function test_zero_budget_is_immediately_exceeded(): void {
        $budget = new WallClockBudget(0.0);

        $this->assertSame(0.0, $budget->remaining());
        $this->assertTrue($budget->exceeded());
    }

    public function test_negative_budget_is_clamped_to_zero(): void {
        $budget = new WallClockBudget(-5.0);

        $this->assertSame(0.0, $budget->total());
        $this->assertTrue($budget->exceeded());
    }

    public function test_elapsed_increases_monotonically(): void {
        $budget = new WallClockBudget(1.0);

        $first  = $budget->elapsed();
        usleep(10_000);
        $second = $budget->elapsed();

        $this->assertGreaterThan($first, $second);
    }
}
