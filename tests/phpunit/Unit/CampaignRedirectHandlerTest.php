<?php

declare(strict_types=1);

namespace DataFlair\Toplists\Tests\Unit\Frontend;

use Brain\Monkey;
use Brain\Monkey\Functions;
use DataFlair\Toplists\Frontend\Redirect\CampaignRedirectHandler;
use PHPUnit\Framework\TestCase;

require_once DATAFLAIR_PLUGIN_DIR . 'src/Frontend/Redirect/CampaignRedirectHandler.php';

final class CampaignRedirectHandlerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        $_GET = [];
        $_SERVER = [];
        Functions\when('home_url')->alias(fn (string $path): string => 'https://publisher.test' . $path);
        Functions\expect('get_transient')->never();
        Functions\expect('wp_redirect')->never();
        Functions\expect('wp_remote_get')->never();
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    /** @dataProvider disabledRoutes */
    public function test_go_returns_410_without_lookup_or_redirect(string $uri): void
    {
        $_SERVER['REQUEST_URI'] = $uri;
        Functions\expect('nocache_headers')->once();
        Functions\expect('wp_die')->once()->with(
            'This redirect feature has been disabled.', 'Redirect unavailable', ['response' => 410]
        );
        (new CampaignRedirectHandler())->handle();
        $this->addToAssertionCount(1);
    }

    public static function disabledRoutes(): array
    {
        return array_map(fn ($uri) => [$uri], [
            '/go/?campaign=STRODD_CROSPO_WB_IN_LP', '/go?campaign=other',
            '/go/', '/go', '/go/?campaign=', '/go/?campaign[]=malformed',
            '/go/?df_route=old&df_sig=old',
        ]);
    }

    /** @dataProvider unrelatedRoutes */
    public function test_unrelated_routes_are_untouched(string $uri): void
    {
        $_SERVER['REQUEST_URI'] = $uri;
        Functions\expect('nocache_headers')->never();
        Functions\expect('wp_die')->never();
        (new CampaignRedirectHandler())->handle();
        $this->addToAssertionCount(1);
    }

    public static function unrelatedRoutes(): array
    {
        return array_map(fn ($uri) => [$uri], ['/', '/reviews/example/', '/golf/', '/goals/', '/go/child/', '/other/go/?campaign=x', '']);
    }

    public function test_wordpress_subdirectory_is_respected(): void
    {
        Functions\when('home_url')->alias(fn (string $path): string => 'https://publisher.test/site' . $path);
        $_SERVER['REQUEST_URI'] = '/site/go/?campaign=example';
        Functions\expect('nocache_headers')->once();
        Functions\expect('wp_die')->once()->with(
            'This redirect feature has been disabled.', 'Redirect unavailable', ['response' => 410]
        );
        (new CampaignRedirectHandler())->handle();
        $this->addToAssertionCount(1);
    }

    public function test_register_runs_before_canonical_redirect(): void
    {
        $handler = new CampaignRedirectHandler();
        Functions\expect('add_action')->once()->with('template_redirect', [$handler, 'handle'], 0);
        $handler->register();
        $this->addToAssertionCount(1);
    }
}
