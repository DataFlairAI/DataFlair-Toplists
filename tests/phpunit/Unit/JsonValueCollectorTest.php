<?php
/**
 * Phase 9.10 — Pins JsonValueCollector behaviour: column whitelist,
 * trim+dedup+sort.
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Tests\Unit\Database;

use DataFlair\Toplists\Database\JsonValueCollector;
use Mockery as M;
use PHPUnit\Framework\TestCase;

require_once DATAFLAIR_PLUGIN_DIR . 'src/Database/JsonValueCollector.php';

final class JsonValueCollectorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        global $wpdb;
        $wpdb = M::mock('wpdb');
    }

    protected function tearDown(): void
    {
        M::close();
        parent::tearDown();
    }

    public function test_returns_empty_for_unwhitelisted_column(): void
    {
        $collector = new JsonValueCollector();
        $this->assertSame([], $collector->collect('wp_dataflair_brands', 'evil_column'));
    }

    public function test_returns_sorted_unique_trimmed_values(): void
    {
        global $wpdb;
        $wpdb->shouldReceive('get_col')->once()->andReturn([
            'MGA, UKGC',
            'MGA,Curaçao',
            ' UKGC ,  ',
            '',
            null,
        ]);

        $collector = new JsonValueCollector();
        $values = $collector->collect('wp_dataflair_brands', 'licenses');

        $this->assertSame(['Curaçao', 'MGA', 'UKGC'], $values);
    }

    public function test_accepts_top_geos_and_product_types_columns(): void
    {
        global $wpdb;
        $wpdb->shouldReceive('get_col')->twice()->andReturn(['DE,FR', 'FR'], ['casino', 'sportsbook']);

        $collector = new JsonValueCollector();
        $this->assertSame(['DE', 'FR'], $collector->collect('wp_dataflair_brands', 'top_geos'));
        $this->assertSame(['casino', 'sportsbook'], $collector->collect('wp_dataflair_brands', 'product_types'));
    }
}
