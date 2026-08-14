<?php
/**
 * Phase 9.12 — Pins Frontend\Shortcode\ToplistShortcode behaviour.
 *
 * Covers every branch of the legacy `toplist_shortcode()` we extracted:
 *   - error: missing both id and slug
 *   - error: toplist not found by id and by slug (both error strings)
 *   - error: malformed JSON in the `data` column
 *   - happy path: cards layout emits the wrapper + invokes CardRenderer once
 *     per item with the prefetched brand_meta_map injected
 *   - happy path: `layout=table` short-circuits to TableRenderer.render()
 *   - `limit` slices items before rendering
 *   - `title` overrides the stored toplist name
 *   - stale notice fires only when last_synced > 3 days ago
 *   - dataflair_render_started + dataflair_render_finished fire with the
 *     payload shape the Phase 1 telemetry contract requires
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Tests\Unit\Frontend;

use Brain\Monkey;
use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use DataFlair\Toplists\Database\BrandsRepositoryInterface;
use DataFlair\Toplists\Database\ToplistsRepositoryInterface;
use DataFlair\Toplists\Frontend\Render\BrandMetaPrefetcher;
use DataFlair\Toplists\Frontend\Render\CardRendererInterface;
use DataFlair\Toplists\Frontend\Render\TableRendererInterface;
use DataFlair\Toplists\Frontend\Render\ViewModels\CasinoCardVM;
use DataFlair\Toplists\Frontend\Render\ViewModels\ToplistTableVM;
use DataFlair\Toplists\Frontend\Shortcode\ToplistShortcode;
use DataFlair\Toplists\Geo\GeoFamilySelector;
use DataFlair\Toplists\Geo\GeoRenderGate;
use DataFlair\Toplists\Geo\VisitorGeoResolverInterface;
use PHPUnit\Framework\TestCase;

// Production classes load via composer's PSR-4 map (DataFlair\Toplists\
// Frontend\\ → src/Frontend/). The sibling stubs file declares the global
// WP helpers (esc_html, wp_parse_args) — globals can't be autoloaded.
require_once __DIR__ . '/ToplistShortcodeTestStubs.php';

final class ToplistShortcodeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        // esc_html / wp_parse_args are declared as plain global functions in
        // ToplistShortcodeTestStubs.php so Patchwork doesn't throw DefinedTooEarly
        // when another test in the suite already declared them.

        // signalUncacheable() calls nocache_headers() on every successful render.
        // Unlike esc_html/wp_parse_args this can't be a plain guarded global: once
        // any other test in the full suite registers it with Patchwork first,
        // function_exists() reports true here too, so we need our own expectation
        // regardless of suite run order rather than relying on function_exists().
        Functions\when('nocache_headers')->justReturn(null);

        // BrandMetaPrefetcher accesses `global $wpdb` for the table prefix and
        // may fall through to `$wpdb->prepare()` + `$wpdb->get_results()` when
        // an item carries a slug or name that wasn't already resolved through
        // the BrandsRepository ID lookup. We give those calls a noisy-noop
        // double via shouldIgnoreMissing(), since the unit-test contract here
        // is "renderer is invoked with the right VM", not "fallback SQL works".
        global $wpdb;
        $wpdb = \Mockery::mock('wpdb')->shouldIgnoreMissing();
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive('prepare')->andReturnUsing(static fn($sql) => $sql);
        $wpdb->shouldReceive('get_results')->andReturn([]);
    }

    protected function tearDown(): void
    {
        \Mockery::close();
        Monkey\tearDown();
        parent::tearDown();
    }

    private function shortcode(
        ToplistsRepositoryInterface $repo,
        ?CardRendererInterface $card = null,
        ?TableRendererInterface $table = null,
        ?VisitorGeoResolverInterface $geoResolver = null,
        ?string $geoTargetingEnabledOption = '1'
    ): ToplistShortcode {
        return new ToplistShortcode(
            $repo,
            $card  ?? $this->stubCardRenderer(),
            $table ?? $this->stubTableRenderer(),
            new BrandMetaPrefetcher($this->stubEmptyBrandsRepo()),
            $geoResolver ?? $this->stubGeoResolver(null),
            new GeoRenderGate(),
            new GeoFamilySelector(),
            $this->stubOptionReader(['dataflair_geo_targeting_enabled' => $geoTargetingEnabledOption])
        );
    }

    /**
     * @param array<string,mixed> $overrides option_name => stored value
     */
    private function stubOptionReader(array $overrides = []): \Closure
    {
        return static fn(string $name, $default = false) => $overrides[$name] ?? $default;
    }

    private function stubGeoResolver(?string $country): VisitorGeoResolverInterface
    {
        return new class($country) implements VisitorGeoResolverInterface {
            public function __construct(private ?string $country) {}
            public function resolve(): ?string { return $this->country; }
        };
    }

    private function stubCardRenderer(string $output = '<div class="card"></div>'): CardRendererInterface
    {
        return new class($output) implements CardRendererInterface {
            public array $calls = [];
            public function __construct(private string $output) {}
            public function render(CasinoCardVM $vm): string
            {
                $this->calls[] = $vm;
                return $this->output;
            }
        };
    }

    private function stubTableRenderer(string $output = '<table></table>'): TableRendererInterface
    {
        return new class($output) implements TableRendererInterface {
            public array $calls = [];
            public function __construct(private string $output) {}
            public function render(ToplistTableVM $vm): string
            {
                $this->calls[] = $vm;
                return $this->output;
            }
        };
    }

    private function stubEmptyBrandsRepo(): BrandsRepositoryInterface
    {
        return new class implements BrandsRepositoryInterface {
            public function findByApiBrandId(int $api_brand_id): ?array { return null; }
            public function findBySlug(string $slug): ?array { return null; }
            public function findByName(string $name): ?array { return null; }
            public function findManyByApiBrandIds(array $api_brand_ids): array { return []; }
            public function findReviewPostsByApiBrandIds(array $api_brand_ids): array { return []; }
            public function upsert(array $row) { return false; }
            public function updateLocalLogoUrl(int $id, string $local_url): bool { return true; }
            public function updateCachedReviewPostId(int $id, int $review_post_id): bool { return true; }
            public function updateReviewUrlOverrideByApiBrandId(int $api_brand_id, ?string $url): bool { return true; }
            public function setDisabledByApiBrandIds(array $api_brand_ids, bool $disabled): int { return 0; }
            public function findPaginated(\DataFlair\Toplists\Database\BrandsQuery $query): \DataFlair\Toplists\Database\BrandsPage
            {
                return new \DataFlair\Toplists\Database\BrandsPage([], 0, 1, 25);
            }
            public function findActiveByApiBrandIds(array $api_brand_ids): array { return []; }
            public function collectDistinctValuesForFilter(string $field): array { return []; }
        };
    }

    private function stubRepo(?array $row, array $family = []): ToplistsRepositoryInterface
    {
        return new class($row, $family) implements ToplistsRepositoryInterface {
            public function __construct(private ?array $row, private array $family) {}
            public function findByApiToplistId(int $api_toplist_id): ?array { return $this->row; }
            public function findBySlug(string $slug): ?array { return $this->row; }
            public function upsert(array $row) { return false; }
            public function deleteByApiToplistId(int $api_toplist_id): bool { return true; }
            public function collectGeoNames(): array { return []; }
            public function listAllForOptions(): array { return []; }
            public function countAll(): int { return 0; }
            public function findPaginated(\DataFlair\Toplists\Database\ToplistsQuery $q): \DataFlair\Toplists\Database\ToplistsPage { return new \DataFlair\Toplists\Database\ToplistsPage([], 0, 1, 25); }
            public function findItemSummaryByApiToplistId(int $id): array { return []; }
            public function findRawDataByApiToplistId(int $id): ?array { return null; }
            public function findFamilyByTemplateId(int $templateId): array { return $this->family; }
        };
    }

    private function buildToplistRow(array $items, string $name = 'Top Casinos', ?int $last_synced_ts = null, ?array $geo = null): array
    {
        $payload = [
            'data' => [
                'name'  => $name,
                'items' => $items,
                'geo'   => $geo ?? ['geo_type' => 'global'],
            ],
        ];
        return [
            'data'        => json_encode($payload),
            'last_synced' => date('Y-m-d H:i:s', $last_synced_ts ?? time()),
        ];
    }

    private function buildFamilyRow(int $apiToplistId, array $geo, array $items = [['brand' => ['name' => 'Acme'], 'position' => 1]]): array
    {
        $row = $this->buildToplistRow($items, 'Top Casinos', null, $geo);
        $row['api_toplist_id'] = $apiToplistId;
        return $row;
    }

    public function test_returns_error_when_neither_id_nor_slug_given(): void
    {
        $sc = $this->shortcode($this->stubRepo(null));

        $html = $sc->render([]);

        $this->assertStringContainsString('Toplist ID or slug is required', $html);
    }

    public function test_returns_error_when_toplist_not_found_by_id(): void
    {
        $sc = $this->shortcode($this->stubRepo(null));

        $html = $sc->render(['id' => 999]);

        $this->assertStringContainsString('ID 999', $html);
        $this->assertStringContainsString('not found', $html);
    }

    public function test_returns_error_when_toplist_not_found_by_slug(): void
    {
        $sc = $this->shortcode($this->stubRepo(null));

        $html = $sc->render(['slug' => 'missing-list']);

        $this->assertStringContainsString('slug "missing-list"', $html);
        $this->assertStringContainsString('not found', $html);
    }

    public function test_returns_error_when_data_column_is_invalid_json(): void
    {
        $repo = $this->stubRepo([
            'data'        => '{not json',
            'last_synced' => date('Y-m-d H:i:s'),
        ]);

        $html = $this->shortcode($repo)->render(['id' => 42]);

        $this->assertStringContainsString('Invalid toplist data', $html);
    }

    public function test_cards_layout_emits_wrapper_and_invokes_card_renderer_per_item(): void
    {
        $items = [
            ['brand' => ['name' => 'Acme'], 'position' => 1],
            ['brand' => ['name' => 'Beta'], 'position' => 2],
            ['brand' => ['name' => 'Gamma'], 'position' => 3],
        ];

        $card  = $this->stubCardRenderer('<div class="dataflair-card"></div>');
        $table = $this->stubTableRenderer();
        $sc    = $this->shortcode($this->stubRepo($this->buildToplistRow($items)), $card, $table);

        $html = $sc->render(['id' => 42]);

        $this->assertStringContainsString('class="dataflair-toplist"', $html);
        $this->assertStringContainsString('class="dataflair-title"', $html);
        $this->assertStringContainsString('Top Casinos', $html);
        $this->assertSame(3, substr_count($html, 'class="dataflair-card"'));
        $this->assertCount(3, $card->calls);
        $this->assertCount(0, $table->calls);
    }

    public function test_table_layout_short_circuits_to_table_renderer(): void
    {
        $items = [['brand' => ['name' => 'Acme'], 'position' => 1]];

        $card  = $this->stubCardRenderer();
        $table = $this->stubTableRenderer('<table id="dataflair-table"></table>');
        $sc    = $this->shortcode($this->stubRepo($this->buildToplistRow($items)), $card, $table);

        $html = $sc->render(['id' => 7, 'layout' => 'table']);

        $this->assertSame('<table id="dataflair-table"></table>', $html);
        $this->assertCount(1, $table->calls);
        $this->assertCount(0, $card->calls);
        $this->assertInstanceOf(ToplistTableVM::class, $table->calls[0]);
    }

    public function test_limit_attribute_slices_items_before_rendering(): void
    {
        $items = [
            ['brand' => ['name' => 'Acme'],  'position' => 1],
            ['brand' => ['name' => 'Beta'],  'position' => 2],
            ['brand' => ['name' => 'Gamma'], 'position' => 3],
            ['brand' => ['name' => 'Delta'], 'position' => 4],
        ];

        $card = $this->stubCardRenderer('<div class="card"></div>');
        $sc   = $this->shortcode($this->stubRepo($this->buildToplistRow($items)), $card);

        $sc->render(['id' => 42, 'limit' => 2]);

        $this->assertCount(2, $card->calls);
    }

    public function test_cta_mode_aliases_are_normalized_to_cta_mode(): void
    {
        $items = [['brand' => ['name' => 'Acme'], 'position' => 1]];
        $card  = $this->stubCardRenderer('<div class="card"></div>');
        $sc    = $this->shortcode($this->stubRepo($this->buildToplistRow($items)), $card);

        $sc->render(['id' => 42, 'cta_mode' => 'dual_app']);
        $vm = $card->calls[0];
        $customizations = $vm->customizations;
        $this->assertSame('dual_app', $customizations['ctaMode']);
    }

    public function test_title_attribute_overrides_stored_name(): void
    {
        $items = [['brand' => ['name' => 'Acme'], 'position' => 1]];
        $sc    = $this->shortcode($this->stubRepo($this->buildToplistRow($items, 'Stored Name')));

        $html = $sc->render(['id' => 42, 'title' => 'Custom Title']);

        $this->assertStringContainsString('Custom Title', $html);
        $this->assertStringNotContainsString('Stored Name', $html);
    }

    public function test_stale_notice_fires_when_data_older_than_three_days(): void
    {
        $items   = [['brand' => ['name' => 'Acme'], 'position' => 1]];
        $stale_t = time() - (4 * 24 * 60 * 60);

        $sc = $this->shortcode($this->stubRepo($this->buildToplistRow($items, 'Stored', $stale_t)));

        $html = $sc->render(['id' => 42]);

        $this->assertStringContainsString('dataflair-notice', $html);
        $this->assertStringContainsString('cached version', $html);
    }

    public function test_render_started_action_fires_with_payload(): void
    {
        $captured = null;
        Actions\expectDone('dataflair_render_started')
            ->once()
            ->with(\Mockery::on(function ($payload) use (&$captured) {
                $captured = $payload;
                return is_array($payload);
            }));
        Actions\expectDone('dataflair_render_finished')->atLeast()->once();

        $items = [['brand' => ['name' => 'Acme'], 'position' => 1]];
        $sc    = $this->shortcode($this->stubRepo($this->buildToplistRow($items)));
        $sc->render(['id' => 42, 'slug' => '', 'layout' => 'cards']);

        $this->assertSame(42, $captured['toplist_id']);
        $this->assertSame('cards', $captured['layout']);
        $this->assertSame('', $captured['slug']);
    }

    public function test_render_finished_action_fires_with_layout_and_item_count(): void
    {
        $captured = null;
        Actions\expectDone('dataflair_render_started')->atLeast()->once();
        Actions\expectDone('dataflair_render_finished')
            ->once()
            ->with(\Mockery::on(function ($payload) use (&$captured) {
                $captured = $payload;
                return is_array($payload);
            }));

        $items = [
            ['brand' => ['name' => 'Acme'], 'position' => 1],
            ['brand' => ['name' => 'Beta'], 'position' => 2],
        ];
        $sc = $this->shortcode($this->stubRepo($this->buildFamilyRow(7, ['geo_type' => 'global'], $items)));
        $sc->render(['id' => 7, 'layout' => 'cards']);

        $this->assertSame(7, $captured['toplist_id']);
        $this->assertSame(2, $captured['item_count']);
        $this->assertSame('cards', $captured['layout']);
        $this->assertArrayHasKey('elapsed_ms', $captured);
        $this->assertGreaterThanOrEqual(0, $captured['elapsed_ms']);
    }

    public function test_table_layout_render_finished_payload_has_table_layout(): void
    {
        $captured = null;
        Actions\expectDone('dataflair_render_started')->atLeast()->once();
        Actions\expectDone('dataflair_render_finished')
            ->once()
            ->with(\Mockery::on(function ($payload) use (&$captured) {
                $captured = $payload;
                return is_array($payload);
            }));

        $items = [['brand' => ['name' => 'Acme'], 'position' => 1]];
        $sc    = $this->shortcode(
            $this->stubRepo($this->buildToplistRow($items)),
            null,
            $this->stubTableRenderer('<table></table>')
        );
        $sc->render(['id' => 9, 'layout' => 'table']);

        $this->assertSame('table', $captured['layout']);
        $this->assertSame(1, $captured['item_count']);
    }

    public function test_pinned_country_toplist_renders_nothing_for_mismatched_visitor(): void
    {
        $items = [['brand' => ['name' => 'Acme'], 'position' => 1]];
        $row   = $this->buildToplistRow($items, 'India Casinos', null, ['geo_type' => 'country', 'code' => 'IN']);
        $sc    = $this->shortcode($this->stubRepo($row), null, null, $this->stubGeoResolver('GB'));

        $html = $sc->render(['id' => 42]);

        $this->assertSame('', $html);
    }

    public function test_pinned_country_toplist_renders_for_matching_visitor(): void
    {
        $items = [['brand' => ['name' => 'Acme'], 'position' => 1]];
        $row   = $this->buildToplistRow($items, 'India Casinos', null, ['geo_type' => 'country', 'code' => 'IN']);
        $sc    = $this->shortcode($this->stubRepo($row), null, null, $this->stubGeoResolver('IN'));

        $html = $sc->render(['id' => 42]);

        $this->assertStringContainsString('India Casinos', $html);
    }

    public function test_pinned_toplist_renders_nothing_when_visitor_country_unresolved(): void
    {
        $items = [['brand' => ['name' => 'Acme'], 'position' => 1]];
        $row   = $this->buildToplistRow($items, 'India Casinos', null, ['geo_type' => 'country', 'code' => 'IN']);
        $sc    = $this->shortcode($this->stubRepo($row), null, null, $this->stubGeoResolver(null));

        $html = $sc->render(['id' => 42]);

        $this->assertSame('', $html);
    }

    public function test_geo_gate_is_bypassed_when_geo_targeting_disabled(): void
    {
        $items = [['brand' => ['name' => 'Acme'], 'position' => 1]];
        $row   = $this->buildToplistRow($items, 'India Casinos', null, ['geo_type' => 'country', 'code' => 'IN']);
        $sc    = $this->shortcode($this->stubRepo($row), null, null, $this->stubGeoResolver('GB'), '0');

        $html = $sc->render(['id' => 42]);

        $this->assertStringContainsString('India Casinos', $html);
    }

    public function test_geo_gate_still_applies_when_geo_targeting_enabled_explicitly(): void
    {
        $items = [['brand' => ['name' => 'Acme'], 'position' => 1]];
        $row   = $this->buildToplistRow($items, 'India Casinos', null, ['geo_type' => 'country', 'code' => 'IN']);
        $sc    = $this->shortcode($this->stubRepo($row), null, null, $this->stubGeoResolver('GB'), '1');

        $html = $sc->render(['id' => 42]);

        $this->assertSame('', $html);
    }

    public function test_geo_gate_applies_by_default_when_option_unset(): void
    {
        $items = [['brand' => ['name' => 'Acme'], 'position' => 1]];
        $row   = $this->buildToplistRow($items, 'India Casinos', null, ['geo_type' => 'country', 'code' => 'IN']);
        $sc    = $this->shortcode($this->stubRepo($row), null, null, $this->stubGeoResolver('GB'), null);

        $html = $sc->render(['id' => 42]);

        $this->assertSame('', $html, 'an unset option (fresh install/upgrade) must preserve current gated behaviour');
    }

    public function test_auto_geo_selects_exact_country_match_from_family(): void
    {
        $family = [
            $this->buildFamilyRow(1, ['geo_type' => 'country', 'code' => 'IN'], [['brand' => ['name' => 'IndiaBrand'], 'position' => 1]]),
            $this->buildFamilyRow(2, ['geo_type' => 'country', 'code' => 'GB'], [['brand' => ['name' => 'UkBrand'], 'position' => 1]]),
        ];
        $card = $this->stubCardRenderer();
        $sc   = $this->shortcode($this->stubRepo(null, $family), $card, null, $this->stubGeoResolver('GB'));

        $sc->render(['template' => 5, 'auto_geo' => 'true']);

        $this->assertCount(1, $card->calls);
        $this->assertSame('UkBrand', $card->calls[0]->item['brand']['name']);
    }

    public function test_auto_geo_falls_back_to_covering_market_when_no_exact_country(): void
    {
        $family = [
            $this->buildFamilyRow(1, ['geo_type' => 'country', 'code' => 'IN'], [['brand' => ['name' => 'IndiaBrand'], 'position' => 1]]),
            $this->buildFamilyRow(2, ['geo_type' => 'market', 'code' => 'EU', 'coveredCountries' => ['DE', 'FR', 'GB']], [['brand' => ['name' => 'EuroBrand'], 'position' => 1]]),
        ];
        $card = $this->stubCardRenderer();
        $sc   = $this->shortcode($this->stubRepo(null, $family), $card, null, $this->stubGeoResolver('GB'));

        $sc->render(['template' => 5, 'auto_geo' => 'true']);

        $this->assertCount(1, $card->calls);
        $this->assertSame('EuroBrand', $card->calls[0]->item['brand']['name']);
    }

    public function test_auto_geo_ambiguous_covering_markets_yields_no_render(): void
    {
        $family = [
            $this->buildFamilyRow(1, ['geo_type' => 'market', 'code' => 'EU', 'coveredCountries' => ['GB', 'DE']], [['brand' => ['name' => 'EuroBrand'], 'position' => 1]]),
            $this->buildFamilyRow(2, ['geo_type' => 'market', 'code' => 'NW', 'coveredCountries' => ['GB', 'IE']], [['brand' => ['name' => 'NorthWestBrand'], 'position' => 1]]),
        ];
        $sc = $this->shortcode($this->stubRepo(null, $family), null, null, $this->stubGeoResolver('GB'));

        $html = $sc->render(['template' => 5, 'auto_geo' => 'true']);

        $this->assertSame('', $html);
    }

    public function test_auto_geo_falls_back_to_global_when_no_country_or_market_match(): void
    {
        $family = [
            $this->buildFamilyRow(1, ['geo_type' => 'country', 'code' => 'IN'], [['brand' => ['name' => 'IndiaBrand'], 'position' => 1]]),
            $this->buildFamilyRow(2, ['geo_type' => 'global'], [['brand' => ['name' => 'GlobalBrand'], 'position' => 1]]),
        ];
        $card = $this->stubCardRenderer();
        $sc   = $this->shortcode($this->stubRepo(null, $family), $card, null, $this->stubGeoResolver('GB'));

        $sc->render(['template' => 5, 'auto_geo' => 'true']);

        $this->assertCount(1, $card->calls);
        $this->assertSame('GlobalBrand', $card->calls[0]->item['brand']['name']);
    }

    public function test_auto_geo_selects_global_when_visitor_country_unresolved(): void
    {
        $family = [
            $this->buildFamilyRow(1, ['geo_type' => 'country', 'code' => 'IN'], [['brand' => ['name' => 'IndiaBrand'], 'position' => 1]]),
            $this->buildFamilyRow(2, ['geo_type' => 'global'], [['brand' => ['name' => 'GlobalBrand'], 'position' => 1]]),
        ];
        $card = $this->stubCardRenderer();
        $sc   = $this->shortcode($this->stubRepo(null, $family), $card, null, $this->stubGeoResolver(null));

        $sc->render(['template' => 5, 'auto_geo' => 'true']);

        $this->assertCount(1, $card->calls);
        $this->assertSame('GlobalBrand', $card->calls[0]->item['brand']['name']);
    }

    public function test_render_defines_donotcachepage_constant(): void
    {
        // Re-declares the setUp() stub locally rather than layering
        // Functions\expect() on top of it: when() reconfigures the same
        // stub target (last call wins), so this safely overrides the
        // blanket justReturn(null) for this test only. Mixing in expect()
        // here would race against setUp's catch-all under Mockery's
        // registration-order matching instead of asserting cleanly.
        $nocache_headers_called = false;
        Functions\when('nocache_headers')->alias(function () use (&$nocache_headers_called) {
            $nocache_headers_called = true;
        });

        $items = [['brand' => ['name' => 'Acme'], 'position' => 1]];
        $sc    = $this->shortcode($this->stubRepo($this->buildToplistRow($items)));

        $sc->render(['id' => 42]);

        $this->assertTrue(defined('DONOTCACHEPAGE'));
        $this->assertTrue(DONOTCACHEPAGE);
        $this->assertTrue($nocache_headers_called);
    }
}
