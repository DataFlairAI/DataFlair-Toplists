<?php
/**
 * Phase 9.10 — Pins ToplistDataStore behaviour: missing id rejection,
 * insert path, update path, integrity-warning logging, $wpdb error
 * propagation.
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Tests\Unit\Database;

use Brain\Monkey;
use Brain\Monkey\Functions;
use DataFlair\Toplists\Database\ToplistDataStore;
use Mockery as M;
use PHPUnit\Framework\TestCase;

require_once DATAFLAIR_PLUGIN_DIR . 'src/Database/ToplistDataStore.php';

final class ToplistDataStoreTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        global $wpdb;
        $wpdb = M::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->last_error = '';
        $wpdb->shouldReceive('prepare')->andReturnUsing(static function ($sql, ...$args) {
            return $sql;
        });

        Functions\when('add_settings_error')->justReturn(null);
        Functions\when('wp_json_encode')->alias(static function ($value) {
            return json_encode($value);
        });
        Functions\when('current_time')->alias(static function ($type) {
            return $type === 'mysql' ? '2026-04-25 12:00:00' : time();
        });
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        M::close();
        parent::tearDown();
    }

    public function test_missing_id_returns_false(): void
    {
        $store = new ToplistDataStore();
        $this->assertFalse($store->store(['name' => 'no id'], '{}'));
    }

    public function test_insert_path_when_no_existing_row(): void
    {
        global $wpdb;
        $wpdb->shouldReceive('get_row')->once()->andReturn(null);
        $wpdb->shouldReceive('insert')
            ->once()
            ->withArgs(function ($table, $row, $formats) {
                $this->assertSame('wp_dataflair_toplists', $table);
                $this->assertSame(7, $row['api_toplist_id']);
                $this->assertSame('Top 7', $row['name']);
                // 10 update formats + the trailing %d for api_toplist_id
                $this->assertCount(11, $formats);
                $this->assertSame('%d', $formats[10]);
                return true;
            })
            ->andReturn(1);

        $store = new ToplistDataStore();
        $payload = [
            'id'            => 7,
            'name'          => 'Top 7',
            'slug'          => 'top-7',
            'currentPeriod' => '2026Q1',
            'publishedAt'   => '2026-04-01T10:00:00Z',
            'version'       => 'v3',
        ];

        $this->assertTrue($store->store($payload, '{"raw":"json"}'));
    }

    public function test_update_path_when_existing_row(): void
    {
        global $wpdb;
        $wpdb->shouldReceive('get_row')->once()->andReturn((object) ['id' => 99]);
        $wpdb->shouldReceive('update')
            ->once()
            ->withArgs(function ($table, $row, $where, $formats, $whereFormats) {
                $this->assertSame('wp_dataflair_toplists', $table);
                $this->assertSame(['api_toplist_id' => 7], $where);
                $this->assertCount(10, $formats);
                $this->assertSame(['%d'], $whereFormats);
                $this->assertArrayNotHasKey('api_toplist_id', $row);
                return true;
            })
            ->andReturn(1);

        $store = new ToplistDataStore();
        $payload = [
            'id'   => 7,
            'name' => 'Top 7',
        ];

        $this->assertTrue($store->store($payload, '{}'));
    }

    public function test_returns_false_when_update_fails(): void
    {
        global $wpdb;
        $wpdb->shouldReceive('get_row')->once()->andReturn((object) ['id' => 1]);
        $wpdb->shouldReceive('update')->once()->andReturn(false);
        $wpdb->last_error = 'mock update fail';

        $store = new ToplistDataStore();
        $this->assertFalse($store->store(['id' => 7, 'name' => 'X'], '{}'));
    }

    public function test_returns_false_when_insert_fails(): void
    {
        global $wpdb;
        $wpdb->shouldReceive('get_row')->once()->andReturn(null);
        $wpdb->shouldReceive('insert')->once()->andReturn(false);
        $wpdb->last_error = 'mock insert fail';

        $store = new ToplistDataStore();
        $this->assertFalse($store->store(['id' => 7, 'name' => 'X'], '{}'));
    }
}
