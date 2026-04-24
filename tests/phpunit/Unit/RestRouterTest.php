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
use DataFlair\Toplists\Logging\NullLogger;
use DataFlair\Toplists\Rest\Controllers\CasinosController;
use DataFlair\Toplists\Rest\Controllers\HealthController;
use DataFlair\Toplists\Rest\Controllers\ToplistsController;
use DataFlair\Toplists\Rest\RestRouter;
use PHPUnit\Framework\TestCase;

require_once DATAFLAIR_PLUGIN_DIR . 'includes/Logging/LoggerInterface.php';
require_once DATAFLAIR_PLUGIN_DIR . 'includes/Logging/NullLogger.php';
require_once DATAFLAIR_PLUGIN_DIR . 'src/Database/ToplistsRepositoryInterface.php';
require_once DATAFLAIR_PLUGIN_DIR . 'src/Rest/Controllers/ToplistsController.php';
require_once DATAFLAIR_PLUGIN_DIR . 'src/Rest/Controllers/CasinosController.php';
require_once DATAFLAIR_PLUGIN_DIR . 'src/Rest/Controllers/HealthController.php';
require_once DATAFLAIR_PLUGIN_DIR . 'src/Rest/RestRouter.php';
require_once __DIR__ . '/RestControllerTestStubs.php';

final class RestRouterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        \RestControllerTestStubs::reset();
    }

    public function test_register_declares_three_routes_on_the_dataflair_v1_namespace(): void
    {
        $this->buildRouter()->register();

        $this->assertCount(3, \RestControllerTestStubs::$registered_routes);
        foreach (\RestControllerTestStubs::$registered_routes as $route) {
            $this->assertSame('dataflair/v1', $route['namespace']);
        }
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
            new HealthController($repo)
        );
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
        };
    }
}
