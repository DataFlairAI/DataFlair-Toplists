<?php
/**
 * Phase 9.6 (admin UX redesign) — pins ToplistAccordionDetailsHandler contract.
 *
 * Verifies: invalid ID rejection, not-found case, item ordering,
 * brand name resolution, and partial status flagging.
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Tests\Unit\Admin\Ajax;

use Brain\Monkey;
use DataFlair\Toplists\Admin\Ajax\ToplistAccordionDetailsHandler;
use DataFlair\Toplists\Database\BrandsRepositoryInterface;
use DataFlair\Toplists\Database\ToplistsRepositoryInterface;
use PHPUnit\Framework\TestCase;

require_once DATAFLAIR_PLUGIN_DIR . 'src/Admin/AjaxHandlerInterface.php';
require_once DATAFLAIR_PLUGIN_DIR . 'src/Database/ToplistsRepositoryInterface.php';
require_once DATAFLAIR_PLUGIN_DIR . 'src/Database/ToplistsQuery.php';
require_once DATAFLAIR_PLUGIN_DIR . 'src/Database/ToplistsPage.php';
require_once DATAFLAIR_PLUGIN_DIR . 'src/Database/BrandsRepositoryInterface.php';
require_once DATAFLAIR_PLUGIN_DIR . 'src/Database/BrandsQuery.php';
require_once DATAFLAIR_PLUGIN_DIR . 'src/Database/BrandsPage.php';
require_once DATAFLAIR_PLUGIN_DIR . 'src/Admin/Ajax/ToplistAccordionDetailsHandler.php';

final class ToplistAccordionDetailsHandlerTest extends TestCase
{
    protected function setUp(): void    { parent::setUp(); Monkey\setUp(); }
    protected function tearDown(): void { Monkey\tearDown(); parent::tearDown(); }

    private function makeHandler(
        ?array $toplistRow,
        array  $itemSummary,
        array  $brands
    ): ToplistAccordionDetailsHandler {
        $toplists = $this->createStub(ToplistsRepositoryInterface::class);
        $toplists->method('findByApiToplistId')->willReturn($toplistRow);
        $toplists->method('findItemSummaryByApiToplistId')->willReturn($itemSummary);

        $brandsRepo = $this->createStub(BrandsRepositoryInterface::class);
        $brandsRepo->method('findManyByApiBrandIds')->willReturn($brands);

        return new ToplistAccordionDetailsHandler($toplists, $brandsRepo);
    }

    public function test_rejects_zero_id(): void
    {
        $h = $this->makeHandler(null, [], []);
        $this->assertFalse($h->handle([])['success']);
    }

    public function test_returns_error_when_toplist_not_found(): void
    {
        $h = $this->makeHandler(null, [], []);
        $result = $h->handle(['api_toplist_id' => 99]);
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('not found', strtolower($result['data']['message']));
    }

    public function test_returns_items_with_resolved_brand_names(): void
    {
        $summary = [
            ['position' => 1, 'brand_id' => 10, 'bonus_offer' => 'Get €200', 'bonus_code' => ''],
            ['position' => 2, 'brand_id' => 20, 'bonus_offer' => '50 spins',  'bonus_code' => ''],
        ];
        $brands = [
            ['api_brand_id' => 10, 'name' => 'BrandA'],
            ['api_brand_id' => 20, 'name' => 'BrandB'],
        ];

        $h = $this->makeHandler(['id' => 1, 'last_synced' => '2026-04-25 10:00:00'], $summary, $brands);
        $result = $h->handle(['api_toplist_id' => 1]);

        $this->assertTrue($result['success']);
        $items = $result['data']['items'];
        $this->assertCount(2, $items);
        $this->assertSame('BrandA', $items[0]['brand_name']);
        $this->assertSame(1, $items[0]['position']);
        $this->assertSame('synced', $items[0]['status']);
    }

    public function test_flags_partial_when_brand_name_missing(): void
    {
        $summary = [['position' => 1, 'brand_id' => 999, 'bonus_offer' => 'Bonus', 'bonus_code' => '']];
        // Brand 999 not in the brands response → empty name
        $h = $this->makeHandler(['id' => 1, 'last_synced' => ''], $summary, []);

        $result = $h->handle(['api_toplist_id' => 1]);
        $this->assertSame('partial', $result['data']['items'][0]['status']);
    }

    public function test_flags_partial_when_offer_missing(): void
    {
        $summary = [['position' => 1, 'brand_id' => 5, 'bonus_offer' => '', 'bonus_code' => '']];
        $brands  = [['api_brand_id' => 5, 'name' => 'BrandX']];
        $h = $this->makeHandler(['id' => 1, 'last_synced' => ''], $summary, $brands);

        $result = $h->handle(['api_toplist_id' => 1]);
        $this->assertSame('partial', $result['data']['items'][0]['status']);
    }
}
