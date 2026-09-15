<?php
/**
 * Phase 9.6 — pins SettingsPage contract.
 *
 * The render body is byte-identical to the v2.1.1 god-class settings_page()
 * method (~705 LOC of HTML + WP API calls). Re-rendering it under PHPUnit
 * would require stubbing 40+ WordPress functions, every wpdb query path, and
 * the `$_GET`/`$_POST` superglobals — a maintenance burden far beyond the
 * value of asserting "this big string equals that big string". HTML-shape
 * regressions are caught by the manual byte-identity smoke on
 * strike-odds.test that the Phase 9.6 acceptance criteria mandate.
 *
 * What this unit test pins is the contract that future refactors must keep:
 *   1. The class lives in the Admin\Pages namespace.
 *   2. It implements PageInterface.
 *   3. The constructor accepts exactly one `\Closure` parameter
 *      (brandsEffectiveBaseResolver). Two earlier closures were removed in
 *      2.3.3 because render() never called them.
 *   4. `render()` exists, returns void, and is callable.
 *
 * Anything that breaks one of these breaks the wiring inside
 * `DataFlair_Toplists::settings_page_obj()` (the lazy getter that injects
 * closures bound to the legacy private helpers). Catching it at unit-test
 * time is cheaper than catching it via a fatal on Sigma admin load.
 */

declare(strict_types=1);

namespace {
    // SettingsPage lives in Admin\Pages — its unqualified get_option()/
    // human_time_diff() calls resolve here first. Only webhookStatusLine()
    // (invoked via Reflection below) exercises these; render() itself is
    // deliberately not unit-tested (see class docblock above) so nothing
    // else in this file needs them.
    if (!class_exists('SettingsPageTestStubsStore')) {
        final class SettingsPageTestStubsStore
        {
            /** @var array<string,mixed> */
            public static array $options = [];

            // Simulates the site's configured UTC offset (WP's own
            // 'gmt_offset' option), in seconds. 0 by default so every
            // existing fixture (built with plain date(), i.e. implicitly
            // UTC-sited) is unaffected; a test can set this to prove
            // get_gmt_from_date() actually corrects a non-zero offset.
            public static int $gmtOffsetSeconds = 0;

            public static function reset(): void
            {
                self::$options = [];
                self::$gmtOffsetSeconds = 0;
            }
        }
    }
}

namespace DataFlair\Toplists\Admin\Pages {
    if (!function_exists(__NAMESPACE__ . '\\get_option')) {
        function get_option($key, $default = false)
        {
            return \SettingsPageTestStubsStore::$options[$key] ?? $default;
        }
    }
    if (!function_exists(__NAMESPACE__ . '\\human_time_diff')) {
        function human_time_diff($from, $to = null)
        {
            $to = $to ?? time();
            $diff = abs($to - $from);
            if ($diff < HOUR_IN_SECONDS) {
                return round($diff / 60) . ' mins';
            }
            if ($diff < DAY_IN_SECONDS) {
                return round($diff / HOUR_IN_SECONDS) . ' hours';
            }
            return round($diff / DAY_IN_SECONDS) . ' days';
        }
    }
    if (!function_exists(__NAMESPACE__ . '\\get_gmt_from_date')) {
        // Real WP: site-local datetime string -> GMT datetime string, by
        // subtracting the site's configured UTC offset. Mirrors that exactly
        // against the simulated offset above instead of a plain passthrough,
        // so a test can actually exercise the conversion.
        function get_gmt_from_date($string, $format = 'Y-m-d H:i:s')
        {
            return gmdate($format, strtotime($string) - \SettingsPageTestStubsStore::$gmtOffsetSeconds);
        }
    }
}

namespace DataFlair\Toplists\Tests\Unit\Admin {

use DataFlair\Toplists\Admin\Pages\PageInterface;
use DataFlair\Toplists\Admin\Pages\SettingsPage;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionNamedType;

require_once DATAFLAIR_PLUGIN_DIR . 'src/Admin/Pages/PageInterface.php';
require_once DATAFLAIR_PLUGIN_DIR . 'src/Admin/Pages/SettingsPage.php';

final class SettingsPageTest extends TestCase
{
    public function test_implements_page_interface(): void
    {
        $page = new SettingsPage(static fn() => 'http://api.test/api/v1');
        $this->assertInstanceOf(PageInterface::class, $page);
    }

    public function test_constructor_accepts_one_closure_parameter(): void
    {
        $reflection  = new ReflectionClass(SettingsPage::class);
        $constructor = $reflection->getConstructor();
        $this->assertNotNull($constructor, 'SettingsPage must declare a constructor');

        $params = $constructor->getParameters();
        $this->assertCount(1, $params, 'constructor takes exactly 1 parameter');

        $type = $params[0]->getType();
        $this->assertInstanceOf(ReflectionNamedType::class, $type);
        $this->assertSame(\Closure::class, $type->getName());
        $this->assertSame('brandsEffectiveBaseResolver', $params[0]->getName());
    }

    public function test_render_method_exists_and_is_void(): void
    {
        $reflection = new ReflectionClass(SettingsPage::class);
        $this->assertTrue($reflection->hasMethod('render'));

        $method = $reflection->getMethod('render');
        $this->assertTrue($method->isPublic());
        $this->assertFalse($method->isStatic());

        $returnType = $method->getReturnType();
        $this->assertInstanceOf(ReflectionNamedType::class, $returnType);
        $this->assertSame('void', $returnType->getName());
    }

    public function test_class_is_final(): void
    {
        $reflection = new ReflectionClass(SettingsPage::class);
        $this->assertTrue(
            $reflection->isFinal(),
            'SettingsPage must be final — extension is the wrong reuse vector'
        );
    }

    // ── webhookStatusLine() — the one piece of render() logic worth pinning
    // directly (decision logic, not HTML shape) — via Reflection since the
    // method is intentionally private (see class docblock on why render()
    // itself stays untested).

    protected function setUp(): void
    {
        parent::setUp();
        \SettingsPageTestStubsStore::reset();
    }

    private function statusLine(): ?array
    {
        $page = new SettingsPage(static fn () => null);
        $method = new \ReflectionMethod(SettingsPage::class, 'webhookStatusLine');
        $method->setAccessible(true);

        return $method->invoke($page);
    }

    public function test_status_is_null_when_webhook_sync_is_disabled(): void
    {
        \SettingsPageTestStubsStore::$options['dataflair_webhook_enabled'] = '0';

        $this->assertNull($this->statusLine());
    }

    public function test_status_is_soft_warning_when_never_processed(): void
    {
        \SettingsPageTestStubsStore::$options['dataflair_webhook_enabled'] = '1';

        $status = $this->statusLine();

        $this->assertNotNull($status);
        $this->assertStringContainsString('No webhook activity yet', $status['text']);
    }

    public function test_status_is_healthy_when_recently_processed(): void
    {
        \SettingsPageTestStubsStore::$options['dataflair_webhook_enabled'] = '1';
        \SettingsPageTestStubsStore::$options['dataflair_webhook_last_processed_at'] = date('Y-m-d H:i:s', time() - 120);

        $status = $this->statusLine();

        $this->assertStringStartsWith('● Receiving', $status['text']);
    }

    public function test_status_is_soft_warning_when_stale_beyond_48_hours(): void
    {
        \SettingsPageTestStubsStore::$options['dataflair_webhook_enabled'] = '1';
        \SettingsPageTestStubsStore::$options['dataflair_webhook_last_processed_at'] = date('Y-m-d H:i:s', time() - (49 * HOUR_IN_SECONDS));

        $status = $this->statusLine();

        $this->assertStringContainsString('No webhook activity in', $status['text']);
    }

    public function test_status_is_not_stale_at_47_hours(): void
    {
        \SettingsPageTestStubsStore::$options['dataflair_webhook_enabled'] = '1';
        \SettingsPageTestStubsStore::$options['dataflair_webhook_last_processed_at'] = date('Y-m-d H:i:s', time() - (47 * HOUR_IN_SECONDS));

        $status = $this->statusLine();

        $this->assertStringStartsWith('● Receiving', $status['text']);
    }

    public function test_status_shows_rejection_reason_when_more_recent_than_last_success(): void
    {
        \SettingsPageTestStubsStore::$options['dataflair_webhook_enabled'] = '1';
        \SettingsPageTestStubsStore::$options['dataflair_webhook_last_processed_at'] = date('Y-m-d H:i:s', time() - 3600);
        \SettingsPageTestStubsStore::$options['dataflair_webhook_last_rejected_at'] = date('Y-m-d H:i:s', time() - 60);
        \SettingsPageTestStubsStore::$options['dataflair_webhook_last_rejected_reason'] = 'invalid signature';

        $status = $this->statusLine();

        $this->assertStringContainsString('rejected', $status['text']);
        $this->assertStringContainsString('invalid signature', $status['text']);
    }

    public function test_status_prefers_healthy_when_last_success_is_more_recent_than_an_old_rejection(): void
    {
        \SettingsPageTestStubsStore::$options['dataflair_webhook_enabled'] = '1';
        \SettingsPageTestStubsStore::$options['dataflair_webhook_last_rejected_at'] = date('Y-m-d H:i:s', time() - 3600);
        \SettingsPageTestStubsStore::$options['dataflair_webhook_last_processed_at'] = date('Y-m-d H:i:s', time() - 60);

        $status = $this->statusLine();

        $this->assertStringStartsWith('● Receiving', $status['text']);
    }

    public function test_status_is_healthy_on_a_site_with_a_non_zero_utc_offset(): void
    {
        // Regression test: current_time('mysql') (how every
        // dataflair_webhook_last_*_at option is written) returns site-LOCAL
        // time, but the old code compared it directly against real UTC via
        // strtotime()+time()/human_time_diff(). A site 5 hours ahead of UTC
        // storing "processed 2 minutes ago" as its own local clock would
        // have shown as "5 hours ago" (past the healthy window) or, for a
        // site behind UTC, an event could appear to be in the future.
        \SettingsPageTestStubsStore::$gmtOffsetSeconds = 5 * HOUR_IN_SECONDS;
        \SettingsPageTestStubsStore::$options['dataflair_webhook_enabled'] = '1';
        // Site-local "now" on a UTC+5 site: real UTC time() plus the offset.
        $siteLocalNow = time() + \SettingsPageTestStubsStore::$gmtOffsetSeconds;
        \SettingsPageTestStubsStore::$options['dataflair_webhook_last_processed_at'] = date('Y-m-d H:i:s', $siteLocalNow - 120);

        $status = $this->statusLine();

        $this->assertStringStartsWith('● Receiving', $status['text']);
        $this->assertStringContainsString('mins ago', $status['text']);
    }
}

}
