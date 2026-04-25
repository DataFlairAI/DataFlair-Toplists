<?php
/**
 * Phase 9.6 (admin UX redesign) — pins BrandsQueryHandler contract.
 *
 * Verifies: delegates to findPaginated, shapes the response envelope,
 * and fetches filter facets via collectDistinctValuesForFilter.
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Tests\Unit\Admin\Ajax;

use DataFlair\Toplists\Admin\Ajax\BrandsQueryHandler;
use DataFlair\Toplists\Database\BrandsPage as BrandsPageDTO;
use DataFlair\Toplists\Database\BrandsQuery;
use DataFlair\Toplists\Database\BrandsRepositoryInterface;
use PHPUnit\Framework\TestCase;

require_once DATAFLAIR_PLUGIN_DIR . 'src/Database/BrandsRepositoryInterface.php';
require_once DATAFLAIR_PLUGIN_DIR . 'src/Database/BrandsPage.php';
require_once DATAFLAIR_PLUGIN_DIR . 'src/Database/BrandsQuery.php';
require_once DATAFLAIR_PLUGIN_DIR . 'src/Admin/AjaxHandlerInterface.php';
require_once DATAFLAIR_PLUGIN_DIR . 'src/Admin/Ajax/BrandsQueryHandler.php';

final class BrandsQueryHandlerTest extends TestCase
{
    private function makeRepo(array $rows = [], int $total = 0): BrandsRepositoryInterface
    {
        $repo = $this->createMock(BrandsRepositoryInterface::class);
        $dto  = new BrandsPageDTO($rows, $total, 1, 25);
        $repo->method('findPaginated')->willReturn($dto);
        $repo->method('collectDistinctValuesForFilter')->willReturn([]);
        return $repo;
    }

    public function test_returns_success_true(): void
    {
        $result = (new BrandsQueryHandler($this->makeRepo()))->handle([]);
        $this->assertTrue($result['success']);
    }

    public function test_response_contains_rows_and_total(): void
    {
        $brands = [
            ['api_brand_id' => 1, 'name' => 'Betway'],
            ['api_brand_id' => 2, 'name' => 'Bet365'],
        ];
        $repo   = $this->makeRepo($brands, 50);
        $result = (new BrandsQueryHandler($repo))->handle([]);

        $this->assertSame(2, count($result['data']['rows']));
        $this->assertSame(50, $result['data']['total']);
    }

    public function test_response_includes_filter_options_keys(): void
    {
        $result = (new BrandsQueryHandler($this->makeRepo()))->handle([]);
        $opts   = $result['data']['filter_options'];
        $this->assertArrayHasKey('licenses',      $opts);
        $this->assertArrayHasKey('geos',           $opts);
        $this->assertArrayHasKey('payments',       $opts);
        $this->assertArrayHasKey('product_types',  $opts);
    }

    public function test_pages_calculated_from_page_count(): void
    {
        // 75 rows at 25 per page = 3 pages
        $repo = $this->createMock(BrandsRepositoryInterface::class);
        $repo->method('findPaginated')->willReturn(new BrandsPageDTO([], 75, 1, 25));
        $repo->method('collectDistinctValuesForFilter')->willReturn([]);

        $result = (new BrandsQueryHandler($repo))->handle(['per_page' => 25]);
        $this->assertSame(3, $result['data']['pages']);
    }

    public function test_delegates_search_to_find_paginated(): void
    {
        $repo = $this->createMock(BrandsRepositoryInterface::class);
        $repo->method('collectDistinctValuesForFilter')->willReturn([]);
        $repo->expects($this->once())
             ->method('findPaginated')
             ->with($this->callback(fn(BrandsQuery $q) => $q->search === 'betway'))
             ->willReturn(new BrandsPageDTO([], 0, 1, 25));

        (new BrandsQueryHandler($repo))->handle(['search' => 'betway']);
    }
}
