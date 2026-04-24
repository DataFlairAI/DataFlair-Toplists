<?php
/**
 * Integration test for CardRenderer — Phase 4 casino-card renderer.
 *
 * Drives CardRenderer::render() through the `views/frontend/casino-card.php`
 * template. Uses Brain\Monkey for `apply_filters` and shares the existing
 * RenderReadOnlyStubs file for the rest of the template helpers and
 * WP_Post / WP_Query mocks.
 *
 * NOTE: this file deliberately declares NOTHING at global scope (no
 * functions, no classes). Declaring a bare `apply_filters` here would be
 * parsed during PHPUnit test-discovery and win over Brain\Monkey's
 * function_exists-guarded stub — breaking every other suite that relies on
 * Brain\Monkey filter expectations (LoggerFactoryTest, LogsCommandTest).
 *
 * Invariants pinned here:
 *   1. When the `brand_meta_map` is present, per-card repository lookups
 *      are NOT invoked — Phase 0B H7 contract.
 *   2. When the map is null, CardRenderer delegates to
 *      BrandsRepository::findByApiBrandId().
 *   3. The precomputed `local_logo_url` is written into the rendered HTML
 *      verbatim — Phase 0A H0 contract.
 *   4. The `dataflair_review_url` filter fires on the resolved URL.
 *   5. Render emits no HTTP / media-sideload / CPT-write calls.
 */

declare(strict_types=1);

use Brain\Monkey;
use Brain\Monkey\Filters;
use DataFlair\Toplists\Database\BrandsRepositoryInterface;
use DataFlair\Toplists\Frontend\Render\CardRenderer;
use DataFlair\Toplists\Frontend\Render\ViewModels\CasinoCardVM;
use DataFlair\Toplists\Logging\NullLogger;
use PHPUnit\Framework\TestCase;

require_once DATAFLAIR_PLUGIN_DIR . 'includes/Logging/LoggerInterface.php';
require_once DATAFLAIR_PLUGIN_DIR . 'includes/Logging/NullLogger.php';
require_once DATAFLAIR_PLUGIN_DIR . 'src/Database/BrandsRepositoryInterface.php';
require_once DATAFLAIR_PLUGIN_DIR . 'src/Frontend/Render/ProsConsResolver.php';
require_once DATAFLAIR_PLUGIN_DIR . 'src/Frontend/Render/CardRendererInterface.php';
require_once DATAFLAIR_PLUGIN_DIR . 'src/Frontend/Render/CardRenderer.php';
require_once DATAFLAIR_PLUGIN_DIR . 'src/Frontend/Render/ViewModels/CasinoCardVM.php';

/**
 * Fake BrandsRepository used by CardRendererTest. Every lookup records the
 * call so we can assert whether the fallback path fired.
 */
final class FakeCardRendererBrandsRepo implements BrandsRepositoryInterface
{
    /** @var array<int, string> */
    public array $calls = [];
    /** @var array<int, array<string, mixed>> */
    public array $byApiBrandId = [];
    /** @var array<string, array<string, mixed>> */
    public array $bySlug = [];
    /** @var array<string, array<string, mixed>> */
    public array $byName = [];

    public function findByApiBrandId(int $api_brand_id): ?array
    {
        $this->calls[] = "findByApiBrandId:$api_brand_id";
        return $this->byApiBrandId[$api_brand_id] ?? null;
    }

    public function findBySlug(string $slug): ?array
    {
        $this->calls[] = "findBySlug:$slug";
        return $this->bySlug[$slug] ?? null;
    }

    public function findByName(string $name): ?array
    {
        $this->calls[] = "findByName:$name";
        return $this->byName[$name] ?? null;
    }

    public function findManyByApiBrandIds(array $api_brand_ids): array { return []; }
    public function findReviewPostsByApiBrandIds(array $api_brand_ids): array { return []; }
    public function upsert(array $row) { return false; }
    public function updateLocalLogoUrl(int $id, string $local_url): bool { return true; }
    public function updateCachedReviewPostId(int $id, int $review_post_id): bool { return true; }
    public function updateReviewUrlOverrideByApiBrandId(int $api_brand_id, ?string $url): bool { return true; }
}

/**
 * @runInSeparateProcess
 * @preserveGlobalState disabled
 */
final class CardRendererTest extends TestCase
{
    private FakeCardRendererBrandsRepo $repo;
    private CardRenderer $renderer;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        // RenderReadOnlyStubs declares its esc_html/home_url/WP_Post/WP_Query
        // stubs only at require-time — it is NEVER parsed as a test class, so
        // it does not interfere with PHPUnit test discovery. That's the key
        // invariant that keeps Brain\Monkey's apply_filters usable in other
        // suites.
        require_once __DIR__ . '/RenderReadOnlyStubs.php';

        $this->repo = new FakeCardRendererBrandsRepo();
        $this->renderer = new CardRenderer($this->repo, new NullLogger());
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    // ── Map path (Phase 0B H7) ──────────────────────────────────────────

    public function test_render_uses_brand_meta_map_and_does_not_query_repo(): void
    {
        Filters\expectApplied('dataflair_review_url')->atLeast()->once()->andReturnFirstArg();

        $map = [
            'ids' => [
                777 => (object) [
                    'local_logo_url' => 'https://cdn.example.test/acme-logo.png',
                    'cached_review_post_id' => 0,
                    'review_url_override' => null,
                ],
            ],
            'slugs' => [],
            'names' => [],
        ];

        $vm = new CasinoCardVM(
            [
                'position' => 1,
                'brand' => ['name' => 'Acme', 'slug' => 'acme', 'api_brand_id' => 777],
                'offer' => ['offerText' => '100% up to $500', 'trackers' => []],
            ],
            1,
            [],
            [],
            $map
        );

        $html = $this->renderer->render($vm);

        $this->assertStringContainsString('https://cdn.example.test/acme-logo.png', $html);
        $this->assertSame(
            [],
            $this->repo->calls,
            'BrandsRepository must not be called when brand_meta_map has a match. Calls: '
                . implode(', ', $this->repo->calls)
        );
    }

    public function test_render_uses_override_review_url_from_map(): void
    {
        $override = 'https://example.test/custom-review-url/';

        Filters\expectApplied('dataflair_review_url')
            ->atLeast()
            ->once()
            ->with($override, \Mockery::type('array'), \Mockery::type('array'))
            ->andReturn($override);

        $map = [
            'ids' => [
                777 => (object) [
                    'local_logo_url' => null,
                    'cached_review_post_id' => 0,
                    'review_url_override' => $override,
                ],
            ],
            'slugs' => [],
            'names' => [],
        ];

        $vm = new CasinoCardVM(
            [
                'position' => 1,
                'brand' => ['name' => 'Acme', 'slug' => 'acme', 'api_brand_id' => 777],
                'offer' => ['offerText' => '', 'trackers' => []],
            ],
            1,
            [],
            [],
            $map
        );

        $html = $this->renderer->render($vm);
        // Non-empty HTML + the filter expectation together pin the contract.
        $this->assertNotEmpty($html, 'CardRenderer::render() must return HTML.');
    }

    // ── Legacy fallback path (null map) ─────────────────────────────────

    public function test_render_falls_back_to_repo_when_map_is_null(): void
    {
        Filters\expectApplied('dataflair_review_url')->atLeast()->once()->andReturnFirstArg();

        $this->repo->byApiBrandId[777] = [
            'id' => 42,
            'api_brand_id' => 777,
            'local_logo_url' => 'https://cdn.example.test/fallback-logo.png',
            'cached_review_post_id' => 0,
            'review_url_override' => null,
        ];

        $vm = new CasinoCardVM(
            [
                'position' => 1,
                'brand' => ['name' => 'Acme', 'slug' => 'acme', 'api_brand_id' => 777],
                'offer' => ['offerText' => '', 'trackers' => []],
            ],
            1,
            [],
            [],
            null
        );

        $html = $this->renderer->render($vm);

        $this->assertStringContainsString('https://cdn.example.test/fallback-logo.png', $html);
        $this->assertContains(
            'findByApiBrandId:777',
            $this->repo->calls,
            'Legacy fallback path must call findByApiBrandId with the brand\'s api_brand_id.'
        );
    }

    public function test_render_uses_override_from_repo_when_map_null(): void
    {
        $override = 'https://example.test/legacy-override/';

        Filters\expectApplied('dataflair_review_url')
            ->atLeast()
            ->once()
            ->with($override, \Mockery::type('array'), \Mockery::type('array'))
            ->andReturn($override);

        $this->repo->byApiBrandId[777] = [
            'id' => 42,
            'api_brand_id' => 777,
            'local_logo_url' => null,
            'cached_review_post_id' => 0,
            'review_url_override' => $override,
        ];

        $vm = new CasinoCardVM(
            [
                'position' => 1,
                'brand' => ['name' => 'Acme', 'slug' => 'acme', 'api_brand_id' => 777],
                'offer' => ['offerText' => '', 'trackers' => []],
            ],
            1,
            [],
            [],
            null
        );

        $html = $this->renderer->render($vm);
        $this->assertNotEmpty($html, 'CardRenderer::render() must return HTML.');
    }
}
