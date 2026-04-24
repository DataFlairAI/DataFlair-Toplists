<?php
/**
 * Phase 3 — pins the immutable SyncRequest value-object shape.
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Tests\Unit\Sync;

use DataFlair\Toplists\Sync\SyncRequest;
use PHPUnit\Framework\TestCase;

require_once DATAFLAIR_PLUGIN_DIR . 'src/Sync/SyncRequest.php';

final class SyncRequestTest extends TestCase
{
    public function test_toplists_factory_defaults_match_h13_budget(): void
    {
        $r = SyncRequest::toplists(3);

        $this->assertSame(SyncRequest::TYPE_TOPLISTS, $r->type);
        $this->assertSame(3, $r->page);
        $this->assertSame(10, $r->perPage);
        $this->assertSame(25.0, $r->budgetSeconds);
    }

    public function test_brands_factory_defaults_match_h13_budget(): void
    {
        $r = SyncRequest::brands(7);

        $this->assertSame(SyncRequest::TYPE_BRANDS, $r->type);
        $this->assertSame(7, $r->page);
        $this->assertSame(5, $r->perPage);
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
