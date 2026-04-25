<?php
/**
 * Phase 9.9 — Pins BrandMetaLookup cascade order.
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Tests\Unit\Frontend\Render;

use DataFlair\Toplists\Frontend\Render\BrandMetaLookup;
use PHPUnit\Framework\TestCase;

require_once DATAFLAIR_PLUGIN_DIR . 'src/Frontend/Render/BrandMetaLookup.php';

final class BrandMetaLookupTest extends TestCase
{
    private function makeMap(): array
    {
        return [
            'ids' => [
                100 => (object) ['name' => 'Betway', 'slug' => 'betway', 'api_brand_id' => 100],
                200 => (object) ['name' => 'Bet365', 'slug' => 'bet365', 'api_brand_id' => 200],
            ],
            'slugs' => [
                'unibet' => (object) ['name' => 'Unibet', 'slug' => 'unibet', 'api_brand_id' => 300],
            ],
            'names' => [
                'PointsBet' => (object) ['name' => 'PointsBet', 'slug' => 'pointsbet', 'api_brand_id' => 400],
            ],
        ];
    }

    public function test_api_brand_id_takes_priority(): void
    {
        $brand = ['api_brand_id' => 100, 'id' => 200, 'slug' => 'unibet', 'name' => 'PointsBet'];
        $row = (new BrandMetaLookup())->lookup($brand, $this->makeMap());

        $this->assertNotNull($row);
        $this->assertSame('Betway', $row->name);
    }

    public function test_falls_through_to_id_when_api_brand_id_missing(): void
    {
        $brand = ['id' => 200, 'slug' => 'unibet', 'name' => 'PointsBet'];
        $row = (new BrandMetaLookup())->lookup($brand, $this->makeMap());

        $this->assertNotNull($row);
        $this->assertSame('Bet365', $row->name);
    }

    public function test_falls_through_to_slug_when_ids_miss(): void
    {
        $brand = ['slug' => 'unibet', 'name' => 'PointsBet'];
        $row = (new BrandMetaLookup())->lookup($brand, $this->makeMap());

        $this->assertNotNull($row);
        $this->assertSame('Unibet', $row->name);
    }

    public function test_falls_through_to_name_when_everything_else_misses(): void
    {
        $brand = ['name' => 'PointsBet'];
        $row = (new BrandMetaLookup())->lookup($brand, $this->makeMap());

        $this->assertNotNull($row);
        $this->assertSame('PointsBet', $row->name);
    }

    public function test_returns_null_when_no_match_anywhere(): void
    {
        $brand = ['api_brand_id' => 9999, 'slug' => 'nope', 'name' => 'Nope'];
        $row = (new BrandMetaLookup())->lookup($brand, $this->makeMap());

        $this->assertNull($row);
    }

    public function test_returns_null_for_empty_brand(): void
    {
        $row = (new BrandMetaLookup())->lookup([], $this->makeMap());
        $this->assertNull($row);
    }
}
