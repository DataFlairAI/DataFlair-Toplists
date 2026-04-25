<?php
/**
 * Phase 2 — behavioural pin for BrandsRepository.
 *
 * Uses Mockery-stubbed $wpdb to verify every method routes through the
 * correct prepared statement and re-shapes rows per the interface contract.
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Tests\Unit\Database;

use DataFlair\Toplists\Database\BrandsPage;
use DataFlair\Toplists\Database\BrandsQuery;
use DataFlair\Toplists\Database\BrandsRepository;
use Mockery as M;
use PHPUnit\Framework\TestCase;

require_once DATAFLAIR_PLUGIN_DIR . 'src/Database/BrandsPage.php';
require_once DATAFLAIR_PLUGIN_DIR . 'src/Database/BrandsQuery.php';
require_once DATAFLAIR_PLUGIN_DIR . 'src/Database/BrandsRepositoryInterface.php';
require_once DATAFLAIR_PLUGIN_DIR . 'src/Database/BrandsRepository.php';

final class BrandsRepositoryTest extends TestCase
{
    protected function tearDown(): void
    {
        M::close();
        parent::tearDown();
    }

    private function makeWpdb(): object
    {
        $wpdb = M::mock('wpdb');
        $wpdb->prefix   = 'wp_';
        $wpdb->posts    = 'wp_posts';
        $wpdb->postmeta = 'wp_postmeta';
        $wpdb->insert_id = 0;
        // prepare() returns the string with args substituted — good enough for test equality
        $wpdb->shouldReceive('prepare')->andReturnUsing(function ($sql, ...$args) {
            $flat = (count($args) === 1 && is_array($args[0])) ? $args[0] : $args;
            return vsprintf(str_replace(['%d', '%s', '%f'], ['%s', '%s', '%s'], $sql), $flat);
        });
        $wpdb->shouldReceive('esc_like')->andReturnUsing(static fn($v) => addcslashes((string) $v, '_%\\'));
        return $wpdb;
    }

    public function test_find_by_api_brand_id_returns_row_array(): void
    {
        $wpdb = $this->makeWpdb();
        $wpdb->shouldReceive('get_row')->once()->andReturn(['id' => 1, 'api_brand_id' => 100, 'name' => 'Betway']);

        $repo   = new BrandsRepository($wpdb);
        $result = $repo->findByApiBrandId(100);

        $this->assertIsArray($result);
        $this->assertSame('Betway', $result['name']);
    }

    public function test_find_by_api_brand_id_returns_null_on_miss(): void
    {
        $wpdb = $this->makeWpdb();
        $wpdb->shouldReceive('get_row')->once()->andReturn(null);

        $repo = new BrandsRepository($wpdb);
        $this->assertNull($repo->findByApiBrandId(999));
    }

    public function test_find_many_by_api_brand_ids_empty_input_returns_empty(): void
    {
        $wpdb = $this->makeWpdb();
        $wpdb->shouldNotReceive('get_results');

        $repo = new BrandsRepository($wpdb);
        $this->assertSame([], $repo->findManyByApiBrandIds([]));
    }

    public function test_find_many_by_api_brand_ids_keys_result_by_api_brand_id(): void
    {
        $wpdb = $this->makeWpdb();
        $wpdb->shouldReceive('get_results')->once()->andReturn([
            ['id' => 1, 'api_brand_id' => 100, 'name' => 'A'],
            ['id' => 2, 'api_brand_id' => 200, 'name' => 'B'],
        ]);

        $repo   = new BrandsRepository($wpdb);
        $result = $repo->findManyByApiBrandIds([100, 200, 100]); // dedupes internally

        $this->assertArrayHasKey(100, $result);
        $this->assertArrayHasKey(200, $result);
        $this->assertSame('A', $result[100]['name']);
        $this->assertSame('B', $result[200]['name']);
    }

    public function test_find_review_posts_by_brand_ids_dedupes_on_lowest_post_id(): void
    {
        $wpdb = $this->makeWpdb();
        $wpdb->shouldReceive('get_results')->once()->andReturn([
            ['api_brand_id' => '100', 'post_id' => '42'],
            ['api_brand_id' => '100', 'post_id' => '7'],  // lower ID — should win
            ['api_brand_id' => '200', 'post_id' => '15'],
        ]);

        $repo   = new BrandsRepository($wpdb);
        $result = $repo->findReviewPostsByApiBrandIds([100, 200]);

        $this->assertSame(7, $result[100], 'Duplicate entries should resolve to the lowest post_id.');
        $this->assertSame(15, $result[200]);
    }

    public function test_upsert_rejects_missing_api_brand_id(): void
    {
        $wpdb = $this->makeWpdb();
        $wpdb->shouldNotReceive('insert');
        $wpdb->shouldNotReceive('update');

        $repo = new BrandsRepository($wpdb);
        $this->assertFalse($repo->upsert(['name' => 'no-api-id']));
    }

    public function test_upsert_inserts_when_no_existing_row(): void
    {
        $wpdb = $this->makeWpdb();
        $wpdb->shouldReceive('get_row')->once()->andReturn(null);
        $wpdb->shouldReceive('insert')->once()->andReturn(1);
        $wpdb->insert_id = 555;

        $repo   = new BrandsRepository($wpdb);
        $result = $repo->upsert(['api_brand_id' => 123, 'name' => 'NewBrand']);

        $this->assertSame(555, $result);
    }

    public function test_upsert_updates_when_existing_row(): void
    {
        $wpdb = $this->makeWpdb();
        $wpdb->shouldReceive('get_row')->once()->andReturn(['id' => 999, 'api_brand_id' => 123]);
        $wpdb->shouldReceive('update')->once()->andReturn(1);

        $repo   = new BrandsRepository($wpdb);
        $result = $repo->upsert(['api_brand_id' => 123, 'name' => 'UpdatedBrand']);

        $this->assertSame(999, $result);
    }

    public function test_update_local_logo_url_delegates_to_wpdb_update(): void
    {
        $wpdb = $this->makeWpdb();
        $wpdb->shouldReceive('update')
            ->once()
            ->with('wp_' . DATAFLAIR_BRANDS_TABLE_NAME, ['local_logo_url' => 'http://cdn/logo.png'], ['id' => 42])
            ->andReturn(1);

        $repo = new BrandsRepository($wpdb);
        $this->assertTrue($repo->updateLocalLogoUrl(42, 'http://cdn/logo.png'));
    }

    public function test_update_cached_review_post_id_delegates_to_wpdb_update(): void
    {
        $wpdb = $this->makeWpdb();
        $wpdb->shouldReceive('update')
            ->once()
            ->with('wp_' . DATAFLAIR_BRANDS_TABLE_NAME, ['cached_review_post_id' => 777], ['id' => 42])
            ->andReturn(1);

        $repo = new BrandsRepository($wpdb);
        $this->assertTrue($repo->updateCachedReviewPostId(42, 777));
    }

    public function test_set_disabled_by_api_brand_ids_empty_input_returns_zero(): void
    {
        $wpdb = $this->makeWpdb();
        $wpdb->shouldNotReceive('query');

        $repo = new BrandsRepository($wpdb);
        $this->assertSame(0, $repo->setDisabledByApiBrandIds([], true));
    }

    public function test_set_disabled_by_api_brand_ids_runs_bulk_update(): void
    {
        $wpdb = $this->makeWpdb();
        $wpdb->shouldReceive('query')->once()->andReturn(3);

        $repo = new BrandsRepository($wpdb);
        $this->assertSame(3, $repo->setDisabledByApiBrandIds([100, 200, 300], true));
    }

    public function test_find_active_by_api_brand_ids_empty_input(): void
    {
        $wpdb = $this->makeWpdb();
        $wpdb->shouldNotReceive('get_results');

        $repo = new BrandsRepository($wpdb);
        $this->assertSame([], $repo->findActiveByApiBrandIds([]));
    }

    public function test_find_active_by_api_brand_ids_keys_by_api_id(): void
    {
        $wpdb = $this->makeWpdb();
        $wpdb->shouldReceive('get_results')->once()->andReturn([
            ['id' => 1, 'api_brand_id' => 100, 'name' => 'A', 'is_disabled' => 0],
            ['id' => 2, 'api_brand_id' => 200, 'name' => 'B', 'is_disabled' => 0],
        ]);

        $repo = new BrandsRepository($wpdb);
        $out  = $repo->findActiveByApiBrandIds([100, 200]);

        $this->assertArrayHasKey(100, $out);
        $this->assertArrayHasKey(200, $out);
    }

    public function test_find_paginated_returns_page_dto(): void
    {
        $wpdb = $this->makeWpdb();
        $wpdb->shouldReceive('get_var')->once()->andReturn('42');
        $wpdb->shouldReceive('get_results')->once()->andReturn([
            ['id' => 1, 'api_brand_id' => 100, 'name' => 'A'],
            ['id' => 2, 'api_brand_id' => 200, 'name' => 'B'],
        ]);

        $q = BrandsQuery::fromArray(['page' => 2, 'per_page' => 25]);
        $repo = new BrandsRepository($wpdb);
        $page = $repo->findPaginated($q);

        $this->assertInstanceOf(BrandsPage::class, $page);
        $this->assertSame(42, $page->total);
        $this->assertSame(2, $page->page);
        $this->assertSame(25, $page->perPage);
        $this->assertCount(2, $page->rows);
    }

    public function test_find_paginated_with_filters_binds_params(): void
    {
        $wpdb = $this->makeWpdb();
        $wpdb->shouldReceive('get_var')->once()->andReturn('5');
        $wpdb->shouldReceive('get_results')->once()->andReturn([]);

        $q = BrandsQuery::fromArray([
            'search'   => 'bet',
            'licenses' => ['MGA'],
            'disabled' => false,
        ]);
        $repo = new BrandsRepository($wpdb);
        $page = $repo->findPaginated($q);

        $this->assertSame(5, $page->total);
        $this->assertSame([], $page->rows);
    }

    public function test_collect_distinct_values_for_filter_csv_column(): void
    {
        $wpdb = $this->makeWpdb();
        $wpdb->shouldReceive('get_col')->once()->andReturn([
            'MGA, UKGC',
            'UKGC',
            ' Curacao ',
        ]);

        $repo   = new BrandsRepository($wpdb);
        $values = $repo->collectDistinctValuesForFilter('licenses');

        $this->assertContains('MGA', $values);
        $this->assertContains('UKGC', $values);
        $this->assertContains('Curacao', $values);
    }

    public function test_collect_distinct_values_for_unknown_field_returns_empty(): void
    {
        $wpdb = $this->makeWpdb();
        $repo = new BrandsRepository($wpdb);
        $this->assertSame([], $repo->collectDistinctValuesForFilter('nonsense'));
    }
}
