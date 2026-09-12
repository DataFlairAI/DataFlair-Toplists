<?php
/**
 * Phase 6 — pins the RestRouter contract.
 *
 * The router owns every `register_rest_route()` call for the
 * `dataflair/v1` namespace. These tests lock in:
 *   - The three routes are registered with the correct HTTP methods.
 *   - The namespace is the public contract value `dataflair/v1`.
 *   - The casinos route declares the H12 pagination args with the right
 *     defaults and bounds.
 *   - The `/toplists` route's permission callback checks `edit_posts`.
 *   - The `/health` route's permission callback checks `manage_options`.
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Tests\Unit\Rest;

use DataFlair\Toplists\Database\ToplistsRepositoryInterface;
use DataFlair\Toplists\Http\ApiBaseUrlDetector;
use DataFlair\Toplists\Logging\NullLogger;
use DataFlair\Toplists\Rest\Controllers\CasinosController;
use DataFlair\Toplists\Rest\Controllers\HealthController;
use DataFlair\Toplists\Rest\Controllers\ToplistsController;
use DataFlair\Toplists\Rest\Controllers\WebhookController;
use DataFlair\Toplists\Rest\RestRouter;
use DataFlair\Toplists\Sync\BrandSyncOutcome;
use DataFlair\Toplists\Sync\BrandSyncServiceInterface;
use DataFlair\Toplists\Sync\SyncRequest;
use DataFlair\Toplists\Sync\SyncResult;
use DataFlair\Toplists\Sync\ToplistPersisterInterface;
use DataFlair\Toplists\Webhooks\WebhookEventsRepositoryInterface;
use DataFlair\Toplists\Webhooks\WebhookSignatureVerifier;
use PHPUnit\Framework\TestCase;

require_once DATAFLAIR_PLUGIN_DIR . 'includes/Logging/LoggerInterface.php';
require_once DATAFLAIR_PLUGIN_DIR . 'includes/Logging/NullLogger.php';
require_once DATAFLAIR_PLUGIN_DIR . 'src/Database/ToplistsQuery.php';
require_once DATAFLAIR_PLUGIN_DIR . 'src/Database/ToplistsPage.php';
require_once DATAFLAIR_PLUGIN_DIR . 'src/Database/ToplistsRepositoryInterface.php';
require_once DATAFLAIR_PLUGIN_DIR . 'src/Sync/SyncRequest.php';
require_once DATAFLAIR_PLUGIN_DIR . 'src/Sync/SyncResult.php';
require_once DATAFLAIR_PLUGIN_DIR . 'src/Sync/BrandSyncOutcome.php';
require_once DATAFLAIR_PLUGIN_DIR . 'src/Sync/BrandSyncServiceInterface.php';
require_once DATAFLAIR_PLUGIN_DIR . 'src/Sync/ToplistPersisterInterface.php';
require_once DATAFLAIR_PLUGIN_DIR . 'src/Webhooks/WebhookEventsRepositoryInterface.php';
require_once DATAFLAIR_PLUGIN_DIR . 'src/Webhooks/WebhookSignatureVerifier.php';
require_once DATAFLAIR_PLUGIN_DIR . 'src/Support/UrlValidator.php';
require_once DATAFLAIR_PLUGIN_DIR . 'src/Support/UrlTransformer.php';
require_once DATAFLAIR_PLUGIN_DIR . 'src/Http/ApiBaseUrlDetector.php';
require_once DATAFLAIR_PLUGIN_DIR . 'src/Rest/Controllers/ToplistsController.php';
require_once DATAFLAIR_PLUGIN_DIR . 'src/Rest/Controllers/CasinosController.php';
require_once DATAFLAIR_PLUGIN_DIR . 'src/Rest/Controllers/HealthController.php';
require_once DATAFLAIR_PLUGIN_DIR . 'src/Rest/Controllers/WebhookController.php';
require_once DATAFLAIR_PLUGIN_DIR . 'src/Rest/RestRouter.php';
require_once __DIR__ . '/RestControllerTestStubs.php';

final class RestRouterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        \RestControllerTestStubs::reset();
    }

    public function test_register_declares_four_routes_on_the_dataflair_v1_namespace(): void
    {
        $this->buildRouter()->register();

        $this->assertCount(4, \RestControllerTestStubs::$registered_routes);
        foreach (\RestControllerTestStubs::$registered_routes as $route) {
            $this->assertSame('dataflair/v1', $route['namespace']);
        }
    }

    public function test_webhooks_route_is_registered_at_slash_webhooks_with_POST_and_no_capability_check(): void
    {
        $this->buildRouter()->register();

        $webhooks = $this->routeFor('/webhooks');
        $this->assertNotNull($webhooks);
        $this->assertSame('POST', $webhooks['args']['methods']);
        // Unauthenticated-but-signed: the caller is DataFlair's queue worker,
        // not a logged-in WP user, so this deliberately is NOT [$this, ...].
        $this->assertSame('__return_true', $webhooks['args']['permission_callback']);
    }

    public function test_toplists_route_is_registered_at_slash_toplists_with_GET(): void
    {
        $this->buildRouter()->register();

        $toplists = $this->routeFor('/toplists');
        $this->assertNotNull($toplists);
        $this->assertSame('GET', $toplists['args']['methods']);
    }

    public function test_casinos_route_is_registered_with_pagination_args(): void
    {
        $this->buildRouter()->register();

        $casinos = $this->routeFor('/toplists/(?P<id>\d+)/casinos');
        $this->assertNotNull($casinos);
        $this->assertSame('GET', $casinos['args']['methods']);

        $args = $casinos['args']['args'];
        $this->assertSame(1,   $args['page']['default']);
        $this->assertSame(1,   $args['page']['minimum']);
        $this->assertSame(20,  $args['per_page']['default']);
        $this->assertSame(100, $args['per_page']['maximum']);
        $this->assertSame(0,   $args['full']['default']);
        $this->assertSame([0, 1], $args['full']['enum']);
    }

    public function test_health_route_is_registered_at_slash_health(): void
    {
        $this->buildRouter()->register();

        $health = $this->routeFor('/health');
        $this->assertNotNull($health);
        $this->assertSame('GET', $health['args']['methods']);
    }

    public function test_toplists_permission_check_follows_edit_posts(): void
    {
        \RestControllerTestStubs::$canEditPosts = false;
        $router = $this->buildRouter();
        $this->assertFalse($router->canEditPosts());

        \RestControllerTestStubs::$canEditPosts = true;
        $this->assertTrue($router->canEditPosts());
    }

    public function test_health_permission_check_follows_manage_options(): void
    {
        \RestControllerTestStubs::$canManageOptions = false;
        $router = $this->buildRouter();
        $this->assertFalse($router->canManageOptions());

        \RestControllerTestStubs::$canManageOptions = true;
        $this->assertTrue($router->canManageOptions());
    }

    private function buildRouter(): RestRouter
    {
        $logger = new NullLogger();
        $repo   = $this->fakeRepository();

        return new RestRouter(
            new ToplistsController($repo, $logger),
            new CasinosController(
                $repo,
                fn(array $items): array => [],
                fn(array $brand, array $map): ?object => null,
                $logger
            ),
            new HealthController($repo),
            new WebhookController(
                new WebhookSignatureVerifier(),
                $this->fakeWebhookEventsRepo(),
                $this->fakeToplistPersister(),
                $this->fakeBrandSyncService(),
                new ApiBaseUrlDetector(new \DataFlair\Toplists\Support\UrlTransformer(new \DataFlair\Toplists\Support\UrlValidator())),
                'test-token',
                $logger
            )
        );
    }

    private function fakeWebhookEventsRepo(): WebhookEventsRepositoryInterface
    {
        return new class implements WebhookEventsRepositoryInterface {
            public function hasProcessed(string $deliveryId): bool { return false; }
            public function recordProcessed(string $deliveryId, string $eventType): bool { return true; }
        };
    }

    private function fakeToplistPersister(): ToplistPersisterInterface
    {
        return new class implements ToplistPersisterInterface {
            public function store(array $toplist, string $rawJson): bool { return true; }
            public function fetchAndStore(string $endpoint, string $token): bool { return true; }
        };
    }

    private function fakeBrandSyncService(): BrandSyncServiceInterface
    {
        return new class implements BrandSyncServiceInterface {
            public function syncPage(SyncRequest $request): SyncResult
            {
                return SyncResult::success($request->page, $request->page, 0, 0, false, true);
            }
            public function syncOne(int $apiBrandId): BrandSyncOutcome
            {
                return BrandSyncOutcome::active($apiBrandId);
            }
        };
    }

    /**
     * @return array{namespace:string,route:string,args:array<string,mixed>}|null
     */
    private function routeFor(string $route): ?array
    {
        foreach (\RestControllerTestStubs::$registered_routes as $r) {
            if ($r['route'] === $route) {
                return $r;
            }
        }
        return null;
    }

    private function fakeRepository(): ToplistsRepositoryInterface
    {
        return new class implements ToplistsRepositoryInterface {
            public function findByApiToplistId(int $api_toplist_id): ?array { return null; }
            public function findBySlug(string $slug): ?array { return null; }
            public function upsert(array $row) { return false; }
            public function deleteByApiToplistId(int $api_toplist_id): bool { return true; }
            public function collectGeoNames(): array { return []; }
            public function listAllForOptions(): array { return []; }
            public function countAll(): int { return 0; }
            public function findPaginated(\DataFlair\Toplists\Database\ToplistsQuery $q): \DataFlair\Toplists\Database\ToplistsPage { return new \DataFlair\Toplists\Database\ToplistsPage([], 0, 1, 25); }
            public function findItemSummaryByApiToplistId(int $id): array { return []; }
            public function findRawDataByApiToplistId(int $id): ?array { return null; }
            public function findFamilyByTemplateId(int $templateId): array { return []; }
        };
    }
}
