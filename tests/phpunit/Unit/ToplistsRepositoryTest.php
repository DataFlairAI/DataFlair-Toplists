<?php
/**
 * Phase 2 — behavioural pin for ToplistsRepository.
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Tests\Unit\Database;

use DataFlair\Toplists\Database\ToplistsRepository;
use Mockery as M;
use PHPUnit\Framework\TestCase;

require_once DATAFLAIR_PLUGIN_DIR . 'src/Database/ToplistsRepositoryInterface.php';
require_once DATAFLAIR_PLUGIN_DIR . 'src/Database/ToplistsRepository.php';

final class ToplistsRepositoryTest extends TestCase
{
    protected function tearDown(): void
    {
        M::close();
        parent::tearDown();
    }

    private function makeWpdb(): object
    {
        $wpdb = M::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->insert_id = 0;
        $wpdb->shouldReceive('prepare')->andReturnUsing(function ($sql, ...$args) {
            $flat = (count($args) === 1 && is_array($args[0])) ? $args[0] : $args;
            return vsprintf(str_replace(['%d', '%s', '%f'], ['%s', '%s', '%s'], $sql), $flat);
        });
        return $wpdb;
    }

    public function test_find_by_api_toplist_id_returns_row(): void
    {
        $wpdb = $this->makeWpdb();
        $wpdb->shouldReceive('get_row')->once()->andReturn(['id' => 1, 'api_toplist_id' => 42, 'name' => 'Top 10']);

        $repo = new ToplistsRepository($wpdb);
        $this->assertSame('Top 10', $repo->findByApiToplistId(42)['name']);
    }

    public function test_find_by_slug_returns_null_on_miss(): void
    {
        $wpdb = $this->makeWpdb();
        $wpdb->shouldReceive('get_row')->once()->andReturn(null);

        $repo = new ToplistsRepository($wpdb);
        $this->assertNull($repo->findBySlug('nonexistent'));
    }

    public function test_upsert_rejects_missing_api_toplist_id(): void
    {
        $wpdb = $this->makeWpdb();
        $wpdb->shouldNotReceive('insert');
        $wpdb->shouldNotReceive('update');

        $repo = new ToplistsRepository($wpdb);
        $this->assertFalse($repo->upsert(['name' => 'no-id']));
    }

    public function test_upsert_inserts_new_row(): void
    {
        $wpdb = $this->makeWpdb();
        $wpdb->shouldReceive('get_row')->once()->andReturn(null);
        $wpdb->shouldReceive('insert')->once()->andReturn(1);
        $wpdb->insert_id = 777;

        $repo = new ToplistsRepository($wpdb);
        $this->assertSame(777, $repo->upsert(['api_toplist_id' => 55, 'name' => 'New']));
    }

    public function test_upsert_updates_existing_row(): void
    {
        $wpdb = $this->makeWpdb();
        $wpdb->shouldReceive('get_row')->once()->andReturn(['id' => 100, 'api_toplist_id' => 55]);
        $wpdb->shouldReceive('update')->once()->andReturn(1);

        $repo = new ToplistsRepository($wpdb);
        $this->assertSame(100, $repo->upsert(['api_toplist_id' => 55, 'name' => 'Renamed']));
    }

    public function test_delete_by_api_toplist_id_returns_true_on_success(): void
    {
        $wpdb = $this->makeWpdb();
        $wpdb->shouldReceive('delete')->once()->andReturn(1);

        $repo = new ToplistsRepository($wpdb);
        $this->assertTrue($repo->deleteByApiToplistId(55));
    }

    public function test_delete_by_api_toplist_id_returns_false_on_wpdb_failure(): void
    {
        $wpdb = $this->makeWpdb();
        $wpdb->shouldReceive('delete')->once()->andReturn(false);

        $repo = new ToplistsRepository($wpdb);
        $this->assertFalse($repo->deleteByApiToplistId(55));
    }
}
