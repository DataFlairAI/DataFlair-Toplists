<?php
/**
 * Phase 3 — pins the immutable SyncRequest value-object shape.
 *
 * The per_page/budget defaults below were tuned post-launch against real
 * API incidents (see 6b7bfe8, 7ffa781, 3c70b8d, a8d8426) and no longer
 * match the original Phase 3 "H13 budget" numbers the factories launched
 * with — toplists per_page=5/budget=60s avoids the ~27s-per-page timeout
 * seen with include=items at per_page=25; brands per_page=25 is the
 * validated throughput fix from 7ffa781.
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Tests\Unit\Sync;

use DataFlair\Toplists\Sync\SyncRequest;
use PHPUnit\Framework\TestCase;

require_once DATAFLAIR_PLUGIN_DIR . 'src/Sync/SyncRequest.php';

final class SyncRequestTest extends TestCase
{
    public function test_toplists_factory_defaults_match_tuned_budget(): void
    {
        $r = SyncRequest::toplists(3);

        $this->assertSame(SyncRequest::TYPE_TOPLISTS, $r->type);
        $this->assertSame(3, $r->page);
        $this->assertSame(5, $r->perPage);
        $this->assertSame(60.0, $r->budgetSeconds);
    }

    public function test_brands_factory_defaults_match_tuned_budget(): void
    {
        $r = SyncRequest::brands(7);

        $this->assertSame(SyncRequest::TYPE_BRANDS, $r->type);
        $this->assertSame(7, $r->page);
        $this->assertSame(25, $r->perPage);
        $this->assertSame(25.0, $r->budgetSeconds);
    }

    public function test_properties_are_readonly(): void
    {
        $r = SyncRequest::toplists(1);

        $this->expectException(\Error::class);
        $r->page = 99;
    }

    public function test_custom_per_page_and_budget_are_honoured(): void
    {
        $r = SyncRequest::brands(1, 15, 10.0);

        $this->assertSame(15, $r->perPage);
        $this->assertSame(10.0, $r->budgetSeconds);
    }
}
