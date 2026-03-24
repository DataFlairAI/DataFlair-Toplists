<?php
/**
 * Unit tests for the Toplist model.
 *
 * Covers all accessor methods, query-builder helpers, and data helpers.
 * Uses reflection to build in-memory instances without a database.
 * ReflectionProperty::setAccessible() is NOT called — it has no effect in PHP 8.1+
 * and is deprecated in PHP 8.5.
 */

use PHPUnit\Framework\TestCase;
use DataFlair\Toplists\Models\Toplist;

class ToplistModelTest extends TestCase {

    // ── Factory helper ──────────────────────────────────────────────────────

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

        $r        = new ReflectionClass(Toplist::class);
        $instance = $r->newInstanceWithoutConstructor();

        $attrProp = $r->getProperty('attributes');
        $attrProp->setValue($instance, array_merge($defaults, $attributes));

        return $instance;
    }

    // ── Accessor tests ──────────────────────────────────────────────────────

    public function test_get_id_returns_integer(): void {
        $t = $this->makeToplist(['id' => '5']);
        $this->assertSame(5, $t->getId());
    }

    public function test_get_api_toplist_id_returns_integer(): void {
        $t = $this->makeToplist(['api_toplist_id' => '99']);
        $this->assertSame(99, $t->getApiToplistId());
    }

    public function test_get_name_returns_string(): void {
        $t = $this->makeToplist(['name' => 'Brazil Casinos']);
        $this->assertSame('Brazil Casinos', $t->getName());
    }

    public function test_get_version_returns_value(): void {
        $t = $this->makeToplist(['version' => 'v1.2']);
        $this->assertSame('v1.2', $t->getVersion());
    }

    public function test_get_version_returns_null_when_missing(): void {
        $t = $this->makeToplist(['version' => null]);
        $this->assertNull($t->getVersion());
    }

    public function test_get_slug_returns_slug_attribute(): void {
        $t = $this->makeToplist(['slug' => 'brazil-casinos']);
        $this->assertSame('brazil-casinos', $t->getSlug());
    }

    public function test_get_slug_returns_null_when_not_set(): void {
        $t = $this->makeToplist(['slug' => null]);
        $this->assertNull($t->getSlug());
    }

    public function test_get_current_period_returns_value(): void {
        $t = $this->makeToplist(['current_period' => '2026-06']);
        $this->assertSame('2026-06', $t->getCurrentPeriod());
    }

    public function test_get_current_period_returns_null_when_missing(): void {
        $t = $this->makeToplist(['current_period' => null]);
        $this->assertNull($t->getCurrentPeriod());
    }

    public function test_get_published_at_returns_value(): void {
        $t = $this->makeToplist(['published_at' => '2026-06-01 00:00:00']);
        $this->assertSame('2026-06-01 00:00:00', $t->getPublishedAt());
    }

    public function test_get_published_at_returns_null_when_missing(): void {
        $t = $this->makeToplist(['published_at' => null]);
        $this->assertNull($t->getPublishedAt());
    }

    public function test_get_item_count_returns_integer(): void {
        $t = $this->makeToplist(['item_count' => '8']);
        $this->assertSame(8, $t->getItemCount());
    }

    public function test_get_item_count_defaults_to_zero(): void {
        $t = $this->makeToplist(['item_count' => null]);
        $this->assertSame(0, $t->getItemCount());
    }

    public function test_get_locked_count_returns_integer(): void {
        $t = $this->makeToplist(['locked_count' => '2']);
        $this->assertSame(2, $t->getLockedCount());
    }

    public function test_get_locked_count_defaults_to_zero(): void {
        $t = $this->makeToplist(['locked_count' => null]);
        $this->assertSame(0, $t->getLockedCount());
    }

    // ── Sync-warnings tests ─────────────────────────────────────────────────

    public function test_get_sync_warnings_returns_empty_array_when_null(): void {
        $t = $this->makeToplist(['sync_warnings' => null]);
        $this->assertSame([], $t->getSyncWarnings());
    }

    public function test_get_sync_warnings_returns_empty_array_when_empty_string(): void {
        $t = $this->makeToplist(['sync_warnings' => '']);
        $this->assertSame([], $t->getSyncWarnings());
    }

    public function test_get_sync_warnings_decodes_json(): void {
        $warnings = ['Position 1: offer geos is NULL', 'Position 2: missing trackerLink'];
        $t        = $this->makeToplist(['sync_warnings' => json_encode($warnings)]);
        $this->assertSame($warnings, $t->getSyncWarnings());
    }

    public function test_get_sync_warnings_returns_empty_array_on_invalid_json(): void {
        $t = $this->makeToplist(['sync_warnings' => 'not-valid-json{']);
        $this->assertSame([], $t->getSyncWarnings());
    }

    public function test_has_sync_warnings_false_when_null(): void {
        $t = $this->makeToplist(['sync_warnings' => null]);
        $this->assertFalse($t->hasSyncWarnings());
    }

    public function test_has_sync_warnings_true_when_warnings_present(): void {
        $t = $this->makeToplist(['sync_warnings' => json_encode(['some warning'])]);
        $this->assertTrue($t->hasSyncWarnings());
    }

    // ── getData / getItems tests ─────────────────────────────────────────────

    public function test_get_data_decodes_json(): void {
        $data = ['data' => ['id' => 42, 'items' => []]];
        $t    = $this->makeToplist(['data' => json_encode($data)]);
        $this->assertSame($data, $t->getData());
    }

    public function test_get_data_returns_null_when_empty(): void {
        $t = $this->makeToplist(['data' => '']);
        $this->assertNull($t->getData());
    }

    public function test_get_data_returns_null_on_invalid_json(): void {
        $t = $this->makeToplist(['data' => '{invalid}']);
        $this->assertNull($t->getData());
    }

    public function test_get_items_returns_items_array(): void {
        $items = [['id' => 1, 'brand' => 'Casino A']];
        $data  = ['data' => ['items' => $items]];
        $t     = $this->makeToplist(['data' => json_encode($data)]);
        $this->assertSame($items, $t->getItems());
    }

    public function test_get_items_returns_empty_when_no_data(): void {
        $t = $this->makeToplist(['data' => '']);
        $this->assertSame([], $t->getItems());
    }

    public function test_get_items_returns_empty_when_items_key_missing(): void {
        $data = ['data' => ['id' => 1]];
        $t    = $this->makeToplist(['data' => json_encode($data)]);
        $this->assertSame([], $t->getItems());
    }

    // ── isStale test ────────────────────────────────────────────────────────

    public function test_is_stale_returns_true_when_synced_long_ago(): void {
        $old = date('Y-m-d H:i:s', strtotime('-10 days'));
        $t   = $this->makeToplist(['last_synced' => $old]);
        $this->assertTrue($t->isStale());
    }

    public function test_is_stale_returns_false_when_recently_synced(): void {
        $recent = date('Y-m-d H:i:s', strtotime('-1 hour'));
        $t      = $this->makeToplist(['last_synced' => $recent]);
        $this->assertFalse($t->isStale());
    }

    public function test_is_stale_returns_true_when_last_synced_null(): void {
        $t = $this->makeToplist(['last_synced' => null]);
        $this->assertTrue($t->isStale());
    }

    public function test_is_stale_custom_days_parameter(): void {
        $fourDaysAgo = date('Y-m-d H:i:s', strtotime('-4 days'));
        $t           = $this->makeToplist(['last_synced' => $fourDaysAgo]);
        // Default threshold is 3 days — 4 days ago should be stale
        $this->assertTrue($t->isStale(3));
        // With 5-day threshold, 4 days ago is NOT stale
        $this->assertFalse($t->isStale(5));
    }

    // ── Magic accessor / isset tests ─────────────────────────────────────────

    public function test_magic_get_returns_attribute(): void {
        $t = $this->makeToplist(['name' => 'Magic Test']);
        $this->assertSame('Magic Test', $t->name);
    }

    public function test_magic_isset_returns_true_for_existing_key(): void {
        $t = $this->makeToplist(['name' => 'Magic Test']);
        $this->assertTrue(isset($t->name));
    }

    public function test_magic_isset_returns_false_for_missing_key(): void {
        $t = $this->makeToplist();
        $this->assertFalse(isset($t->nonexistent_field));
    }

    // ── getAttribute / toArray tests ────────────────────────────────────────

    public function test_get_attribute_returns_default_when_missing(): void {
        $t = $this->makeToplist();
        $this->assertSame('default_val', $t->getAttribute('no_such_key', 'default_val'));
    }

    public function test_to_array_returns_all_attributes(): void {
        $t   = $this->makeToplist(['name' => 'My List', 'slug' => 'my-list']);
        $arr = $t->toArray();
        $this->assertSame('My List', $arr['name']);
        $this->assertSame('my-list', $arr['slug']);
    }

    // ── Query builder static state tests ─────────────────────────────────────

    public function test_where_returns_toplist_instance(): void {
        $result = Toplist::where('status', 'active');
        $this->assertInstanceOf(Toplist::class, $result);
        // Reset static state to avoid polluting other tests
        $r = new ReflectionClass(Toplist::class);
        $prop = $r->getProperty('query_where');
        $prop->setValue(null, []);
        $prop2 = $r->getProperty('query_order');
        $prop2->setValue(null, null);
        $prop3 = $r->getProperty('query_limit');
        $prop3->setValue(null, null);
    }

    public function test_order_by_returns_toplist_instance(): void {
        $result = Toplist::orderBy('name', 'DESC');
        $this->assertInstanceOf(Toplist::class, $result);
        // Reset static state
        $r = new ReflectionClass(Toplist::class);
        $r->getProperty('query_where')->setValue(null, []);
        $r->getProperty('query_order')->setValue(null, null);
        $r->getProperty('query_limit')->setValue(null, null);
    }

    public function test_limit_returns_toplist_instance(): void {
        $result = Toplist::limit(5);
        $this->assertInstanceOf(Toplist::class, $result);
        // Reset static state
        $r = new ReflectionClass(Toplist::class);
        $r->getProperty('query_where')->setValue(null, []);
        $r->getProperty('query_order')->setValue(null, null);
        $r->getProperty('query_limit')->setValue(null, null);
    }
}
