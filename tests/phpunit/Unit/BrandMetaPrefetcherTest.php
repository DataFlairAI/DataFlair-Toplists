<?php
/**
 * Phase 9.9 — Pins BrandMetaPrefetcher map shape and repo delegation.
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Tests\Unit\Frontend\Render;

use DataFlair\Toplists\Database\BrandsRepositoryInterface;
use DataFlair\Toplists\Frontend\Render\BrandMetaPrefetcher;
use Mockery as M;
use PHPUnit\Framework\TestCase;

require_once DATAFLAIR_PLUGIN_DIR . 'src/Database/BrandsRepositoryInterface.php';
require_once DATAFLAIR_PLUGIN_DIR . 'src/Frontend/Render/BrandMetaPrefetcher.php';

final class BrandMetaPrefetcherTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        global $wpdb;
        $wpdb = M::mock('wpdb');
        $wpdb->prefix = 'wp_';
    }

    protected function tearDown(): void
    {
        M::close();
        parent::tearDown();
    }

    public function test_returns_empty_shape_for_no_items(): void
    {
        $repo = M::mock(BrandsRepositoryInterface::class);
        $repo->shouldNotReceive('findManyByApiBrandIds');

        $prefetcher = new BrandMetaPrefetcher($repo);
        $map = $prefetcher->prefetch([]);

        $this->assertSame(['ids' => [], 'slugs' => [], 'names' => []], $map);
    }

    public function test_delegates_id_batch_to_repository(): void
    {
        $repo = M::mock(BrandsRepositoryInterface::class);
        $repo->shouldReceive('findManyByApiBrandIds')
            ->once()
            ->with(M::on(fn ($ids) => is_array($ids) && in_array(100, $ids, true)))
            ->andReturn([
                100 => ['api_brand_id' => 100, 'slug' => 'betway', 'name' => 'Betway'],
            ]);

        $prefetcher = new BrandMetaPrefetcher($repo);
        $map = $prefetcher->prefetch([
            ['brand' => ['api_brand_id' => 100]],
        ]);

        $this->assertArrayHasKey(100, $map['ids']);
        $this->assertSame('Betway', $map['ids'][100]->name);
        $this->assertArrayHasKey('betway', $map['slugs']);
        $this->assertArrayHasKey('Betway', $map['names']);
    }

    public function test_recasts_repo_array_rows_to_objects(): void
    {
        $repo = M::mock(BrandsRepositoryInterface::class);
        $repo->shouldReceive('findManyByApiBrandIds')
            ->once()
            ->andReturn([
                200 => ['api_brand_id' => 200, 'slug' => 'bet365', 'name' => 'Bet365'],
            ]);

        $prefetcher = new BrandMetaPrefetcher($repo);
        $map = $prefetcher->prefetch([
            ['brand' => ['api_brand_id' => 200]],
        ]);

        $this->assertIsObject($map['ids'][200]);
        $this->assertSame('Bet365', $map['ids'][200]->name);
    }
}
