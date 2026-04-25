<?php
/**
 * Phase 9.12 — Pins Frontend\Redirect\CampaignRedirectHandler behaviour.
 *
 * Covers every branch of the legacy `handle_campaign_redirect()` we extracted:
 *   - silent return when no `?campaign=` query var
 *   - silent return when path does not contain `/go`
 *   - 404 when sanitized campaign comes back empty
 *   - 404 when transient lookup misses or returns a non-URL
 *   - 301 redirect to the validated tracker URL on hit
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Tests\Unit\Frontend;

use Brain\Monkey;
use Brain\Monkey\Functions;
use DataFlair\Toplists\Frontend\Redirect\CampaignRedirectHandler;
use PHPUnit\Framework\TestCase;

require_once DATAFLAIR_PLUGIN_DIR . 'src/Frontend/Redirect/CampaignRedirectHandler.php';

final class CampaignRedirectHandlerTest extends TestCase
{
    /** @var array<string,bool> */
    private array $sideEffects = [];

    /** @var array{0:string,1:int}|null */
    private ?array $redirectCalled = null;

    private ?int $statusHeader = null;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        $_GET    = [];
        $_SERVER = [];
        $this->sideEffects   = [];
        $this->redirectCalled = null;
        $this->statusHeader   = null;

        Functions\when('sanitize_text_field')->returnArg();
        Functions\when('status_header')->alias(function (int $code): void {
            $this->statusHeader = $code;
        });
        Functions\when('nocache_headers')->alias(function (): void {
            $this->sideEffects['nocache_headers'] = true;
        });
        Functions\when('get_transient')->justReturn(false);
        Functions\when('wp_redirect')->alias(function (string $url, int $status = 302): bool {
            $this->redirectCalled = [$url, $status];
            throw new RedirectExitException();
        });
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_returns_silently_when_no_campaign_query_var(): void
    {
        $_SERVER['REQUEST_URI'] = '/go/?other=yes';

        (new CampaignRedirectHandler())->handle();

        $this->assertNull($this->statusHeader);
        $this->assertNull($this->redirectCalled);
        $this->assertSame([], $this->sideEffects);
    }

    public function test_returns_silently_when_path_has_no_go_segment(): void
    {
        $_GET['campaign']       = 'spring-promo';
        $_SERVER['REQUEST_URI'] = '/landing/spring/?campaign=spring-promo';

        (new CampaignRedirectHandler())->handle();

        $this->assertNull($this->statusHeader);
        $this->assertNull($this->redirectCalled);
    }

    public function test_returns_404_when_sanitized_campaign_is_empty(): void
    {
        $_GET['campaign']       = 'spring-promo';
        $_SERVER['REQUEST_URI'] = '/go/?campaign=spring-promo';

        Functions\when('sanitize_text_field')->justReturn('');

        (new CampaignRedirectHandler())->handle();

        $this->assertSame(404, $this->statusHeader);
        $this->assertTrue($this->sideEffects['nocache_headers'] ?? false);
        $this->assertNull($this->redirectCalled);
    }

    public function test_returns_404_when_transient_missing(): void
    {
        $_GET['campaign']       = 'unknown';
        $_SERVER['REQUEST_URI'] = '/go/?campaign=unknown';

        Functions\when('get_transient')->justReturn(false);

        (new CampaignRedirectHandler())->handle();

        $this->assertSame(404, $this->statusHeader);
        $this->assertTrue($this->sideEffects['nocache_headers'] ?? false);
        $this->assertNull($this->redirectCalled);
    }

    public function test_returns_404_when_transient_value_is_not_a_valid_url(): void
    {
        $_GET['campaign']       = 'broken';
        $_SERVER['REQUEST_URI'] = '/go/?campaign=broken';

        Functions\when('get_transient')->justReturn('not-a-url');

        (new CampaignRedirectHandler())->handle();

        $this->assertSame(404, $this->statusHeader);
        $this->assertNull($this->redirectCalled);
    }

    public function test_issues_301_redirect_on_valid_campaign(): void
    {
        $_GET['campaign']       = 'spring-promo';
        $_SERVER['REQUEST_URI'] = '/go/?campaign=spring-promo';

        $expected_key = 'dataflair_tracker_' . md5('spring-promo');
        Functions\when('get_transient')->alias(function (string $key) use ($expected_key) {
            return $key === $expected_key ? 'https://track.example.com/aff/abc' : false;
        });

        try {
            (new CampaignRedirectHandler())->handle();
            $this->fail('Expected RedirectExitException to be thrown by wp_redirect stub.');
        } catch (RedirectExitException $e) {
            // Expected — `exit;` after wp_redirect short-circuits real WP.
        }

        $this->assertSame(['https://track.example.com/aff/abc', 301], $this->redirectCalled);
        $this->assertNull($this->statusHeader);
    }

    public function test_register_hooks_template_redirect(): void
    {
        $captured = [];
        Functions\when('add_action')->alias(function (string $hook, $callback) use (&$captured): void {
            $captured[$hook] = $callback;
        });

        $handler = new CampaignRedirectHandler();
        $handler->register();

        $this->assertArrayHasKey('template_redirect', $captured);
        $this->assertSame([$handler, 'handle'], $captured['template_redirect']);
    }
}

class RedirectExitException extends \RuntimeException
{
}
