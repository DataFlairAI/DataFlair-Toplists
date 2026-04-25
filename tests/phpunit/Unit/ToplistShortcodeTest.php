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
        ?TableRendererInterface $table = null
    ): ToplistShortcode {
        return new ToplistShortcode(
            $repo,
            $card  ?? $this->stubCardRenderer(),
            $table ?? $this->stubTableRenderer(),
            new BrandMetaPrefetcher($this->stubEmptyBrandsRepo())
        );
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

    private function stubRepo(?array $row): ToplistsRepositoryInterface
    {
        return new class($row) implements ToplistsRepositoryInterface {
            public function __construct(private ?array $row) {}
            public function findByApiToplistId(int $api_toplist_id): ?array { return $this->row; }
            public function findBySlug(string $slug): ?array { return $this->row; }
            public function upsert(array $row) { return false; }
            public function deleteByApiToplistId(int $api_toplist_id): bool { return true; }
            public function collectGeoNames(): array { return []; }
            public function listAllForOptions(): array { return []; }
            public function countAll(): int { return 0; }
        };
    }

    private function buildToplistRow(array $items, string $name = 'Top Casinos', ?int $last_synced_ts = null): array
    {
        $payload = [
            'data' => [
                'name'  => $name,
                'items' => $items,
            ],
        ];
        return [
            'data'        => json_encode($payload),
            'last_synced' => date('Y-m-d H:i:s', $last_synced_ts ?? time()),
        ];
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
        $sc = $this->shortcode($this->stubRepo($this->buildToplistRow($items)));
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
}
