<?php
/**
 * Unit tests for the Toplist model new accessor methods added in v1.5.
 */

use PHPUnit\Framework\TestCase;
use DataFlair\Toplists\Models\Toplist;

class ToplistModelTest extends TestCase {

    private function makeToplist(array $attributes = []): Toplist {
        $defaults = [
            'id'             => 1,
            'api_toplist_id' => 42,
            'name'           => 'Test Toplist',
            'slug'           => 'test-toplist',
            'current_period' => '2026-03',
            'published_at'   => '2026-03-01 12:00:00',
            'item_count'     => 10,
            'locked_count'   => 3,
            'sync_warnings'  => null,
            'version'        => '2026-03-01T12:00:00+00:00',
            'data'           => '{"data":{"id":42,"name":"Test","items":[]}}',
            'last_synced'    => '2026-03-11 00:00:00',
        ];

        // Use reflection to create the Toplist instance with attributes
        $r = new ReflectionClass(Toplist::class);
        $instance = $r->newInstanceWithoutConstructor();

        $attrProp = $r->getProperty('attributes');
        $attrProp->setAccessible(true);
        $attrProp->setValue($instance, array_merge($defaults, $attributes));

        return $instance;
    }

    public function test_get_slug_returns_slug_attribute(): void {
        $toplist = $this->makeToplist(['slug' => 'brazil-casinos']);
        $this->assertSame('brazil-casinos', $toplist->getSlug());
    }

    public function test_get_slug_returns_null_when_not_set(): void {
        $toplist = $this->makeToplist(['slug' => null]);
        $this->assertNull($toplist->getSlug());
    }

    public function test_get_current_period_returns_value(): void {
        $toplist = $this->makeToplist(['current_period' => '2026-06']);
        $this->assertSame('2026-06', $toplist->getCurrentPeriod());
    }

    public function test_get_published_at_returns_value(): void {
        $toplist = $this->makeToplist(['published_at' => '2026-06-01 00:00:00']);
        $this->assertSame('2026-06-01 00:00:00', $toplist->getPublishedAt());
    }

    public function test_get_item_count_returns_integer(): void {
        $toplist = $this->makeToplist(['item_count' => '8']);
        $this->assertSame(8, $toplist->getItemCount());
    }

    public function test_get_locked_count_returns_integer(): void {
        $toplist = $this->makeToplist(['locked_count' => '2']);
        $this->assertSame(2, $toplist->getLockedCount());
    }

    public function test_get_sync_warnings_returns_empty_array_when_null(): void {
        $toplist = $this->makeToplist(['sync_warnings' => null]);
        $this->assertSame([], $toplist->getSyncWarnings());
    }

    public function test_get_sync_warnings_decodes_json(): void {
        $warnings = ['Position 1: offer geos is NULL', 'Position 2: missing trackerLink'];
        $toplist  = $this->makeToplist(['sync_warnings' => json_encode($warnings)]);
        $this->assertSame($warnings, $toplist->getSyncWarnings());
    }

    public function test_has_sync_warnings_false_when_null(): void {
        $toplist = $this->makeToplist(['sync_warnings' => null]);
        $this->assertFalse($toplist->hasSyncWarnings());
    }

    public function test_has_sync_warnings_true_when_warnings_present(): void {
        $toplist = $this->makeToplist(['sync_warnings' => json_encode(['some warning'])]);
        $this->assertTrue($toplist->hasSyncWarnings());
    }
}
