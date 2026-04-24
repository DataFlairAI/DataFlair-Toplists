<?php
/**
 * Phase 2 — behavioural pin for AlternativesRepository.
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Tests\Unit\Database;

use DataFlair\Toplists\Database\AlternativesRepository;
use Mockery as M;
use PHPUnit\Framework\TestCase;

require_once DATAFLAIR_PLUGIN_DIR . 'src/Database/AlternativesRepositoryInterface.php';
require_once DATAFLAIR_PLUGIN_DIR . 'src/Database/AlternativesRepository.php';

final class AlternativesRepositoryTest extends TestCase
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

    public function test_find_by_toplist_id_returns_empty_when_no_rows(): void
    {
        $wpdb = $this->makeWpdb();
        $wpdb->shouldReceive('get_results')->once()->andReturn([]);

        $repo = new AlternativesRepository($wpdb);
        $this->assertSame([], $repo->findByToplistId(10));
    }

    public function test_find_by_toplist_and_geo_returns_single_row(): void
    {
        $wpdb = $this->makeWpdb();
        $wpdb->shouldReceive('get_row')->once()->andReturn(['id' => 1, 'toplist_id' => 10, 'geo' => 'GB']);

        $repo = new AlternativesRepository($wpdb);
        $this->assertSame('GB', $repo->findByToplistAndGeo(10, 'GB')['geo']);
    }

    public function test_upsert_rejects_missing_key_fields(): void
    {
        $wpdb = $this->makeWpdb();
        $wpdb->shouldNotReceive('insert');

        $repo = new AlternativesRepository($wpdb);
        $this->assertFalse($repo->upsert(['geo' => 'GB']));
        $this->assertFalse($repo->upsert(['toplist_id' => 10]));
    }

    public function test_upsert_inserts_new_geo_row(): void
    {
        $wpdb = $this->makeWpdb();
        $wpdb->shouldReceive('get_row')->once()->andReturn(null);
        $wpdb->shouldReceive('insert')->once()->andReturn(1);
        $wpdb->insert_id = 42;

        $repo = new AlternativesRepository($wpdb);
        $this->assertSame(42, $repo->upsert(['toplist_id' => 10, 'geo' => 'GB', 'data' => '{}']));
    }

    public function test_upsert_updates_existing_geo_row(): void
    {
        $wpdb = $this->makeWpdb();
        $wpdb->shouldReceive('get_row')->once()->andReturn(['id' => 99, 'toplist_id' => 10, 'geo' => 'GB']);
        $wpdb->shouldReceive('update')->once()->andReturn(1);

        $repo = new AlternativesRepository($wpdb);
        $this->assertSame(99, $repo->upsert(['toplist_id' => 10, 'geo' => 'GB', 'data' => '{"new":true}']));
    }

    public function test_delete_by_toplist_id_returns_true_on_success(): void
    {
        $wpdb = $this->makeWpdb();
        $wpdb->shouldReceive('delete')->once()->andReturn(3);

        $repo = new AlternativesRepository($wpdb);
        $this->assertTrue($repo->deleteByToplistId(10));
    }
}
