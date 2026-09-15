<?php
/**
 * Phase 9.11 — Pins Http\ApiBaseUrlDetector resolution order:
 * stored option > endpoints option (with cache-back) > fallback.
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Tests\Unit\Http;

use Brain\Monkey;
use Brain\Monkey\Functions;
use DataFlair\Toplists\Http\ApiBaseUrlDetector;
use DataFlair\Toplists\Support\UrlTransformer;
use DataFlair\Toplists\Support\UrlValidator;
use PHPUnit\Framework\TestCase;

require_once DATAFLAIR_PLUGIN_DIR . 'src/Support/UrlValidator.php';
require_once DATAFLAIR_PLUGIN_DIR . 'src/Support/UrlTransformer.php';
require_once DATAFLAIR_PLUGIN_DIR . 'src/Http/ApiBaseUrlDetector.php';

final class ApiBaseUrlDetectorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    private function detector(): ApiBaseUrlDetector
    {
        return new ApiBaseUrlDetector(new UrlTransformer(new UrlValidator()));
    }

    public function test_returns_stored_option_with_https_forced(): void
    {
        Functions\when('get_option')->alias(function ($key, $default = false) {
            if ($key === 'dataflair_api_base_url') {
                return 'http://tenant.dataflair.ai/api/v1';
            }
            return $default;
        });

        $this->assertSame('https://tenant.dataflair.ai/api/v1', $this->detector()->detect());
    }

    public function test_strips_path_after_api_version_segment(): void
    {
        Functions\when('get_option')->alias(function ($key, $default = false) {
            if ($key === 'dataflair_api_base_url') {
                return 'https://tenant.dataflair.ai/api/v2/toplists/3';
            }
            return $default;
        });

        $this->assertSame('https://tenant.dataflair.ai/api/v2', $this->detector()->detect());
    }

    public function test_keeps_local_test_url_as_http(): void
    {
        Functions\when('get_option')->alias(function ($key, $default = false) {
            if ($key === 'dataflair_api_base_url') {
                return 'http://strike-odds.test/api/v1';
            }
            return $default;
        });

        $this->assertSame('http://strike-odds.test/api/v1', $this->detector()->detect());
    }

    public function test_extracts_base_from_endpoints_and_caches_it(): void
    {
        Functions\when('get_option')->alias(function ($key, $default = false) {
            if ($key === 'dataflair_api_base_url') {
                return false;
            }
            if ($key === 'dataflair_api_endpoints') {
                return "http://tenant.dataflair.ai/api/v1/toplists/3\nhttp://tenant.dataflair.ai/api/v1/toplists/4";
            }
            return $default;
        });

        $captured = [];
        Functions\when('update_option')->alias(function ($key, $value) use (&$captured) {
            $captured[$key] = $value;
            return true;
        });

        $this->assertSame('https://tenant.dataflair.ai/api/v1', $this->detector()->detect());
        $this->assertSame('https://tenant.dataflair.ai/api/v1', $captured['dataflair_api_base_url']);
    }

    /**
     * Settings renders the effective base on a plain GET; it must be able
     * to resolve tier 2 without persisting the cache-back.
     */
    public function test_does_not_cache_back_when_persist_is_false(): void
    {
        Functions\when('get_option')->alias(function ($key, $default = false) {
            if ($key === 'dataflair_api_base_url') {
                return false;
            }
            if ($key === 'dataflair_api_endpoints') {
                return "http://tenant.dataflair.ai/api/v1/toplists/3";
            }
            return $default;
        });
        Functions\expect('update_option')->never();

        $this->assertSame('https://tenant.dataflair.ai/api/v1', $this->detector()->detect(false));
    }

    /**
     * Settings asks this before showing an effective URL: when it is false,
     * detect() would only return the hard-coded fallback host.
     */
    public function test_is_configured_when_a_base_url_is_stored(): void
    {
        Functions\when('get_option')->alias(fn ($key, $default = false) => $key === 'dataflair_api_base_url' ? 'https://tenant.dataflair.ai/api/v1' : $default);

        $this->assertTrue($this->detector()->isConfigured());
    }

    public function test_is_configured_when_endpoints_yield_a_base(): void
    {
        Functions\when('get_option')->alias(fn ($key, $default = false) => $key === 'dataflair_api_endpoints' ? "https://tenant.dataflair.ai/api/v1/toplists/3" : $default);

        $this->assertTrue($this->detector()->isConfigured());
    }

    public function test_is_not_configured_when_endpoints_do_not_match(): void
    {
        Functions\when('get_option')->alias(fn ($key, $default = false) => $key === 'dataflair_api_endpoints' ? "not a url" : $default);

        $this->assertFalse($this->detector()->isConfigured());
    }

    public function test_is_not_configured_when_nothing_is_stored(): void
    {
        Functions\when('get_option')->alias(fn ($key, $default = false) => $default);

        $this->assertFalse($this->detector()->isConfigured());
    }

    public function test_falls_back_when_nothing_is_stored(): void
    {
        Functions\when('get_option')->alias(function ($key, $default = false) {
            return $default;
        });

        $this->assertSame('https://sigma.dataflair.ai/api/v1', $this->detector()->detect());
    }

    public function test_falls_back_when_endpoints_blob_has_no_match(): void
    {
        Functions\when('get_option')->alias(function ($key, $default = false) {
            if ($key === 'dataflair_api_endpoints') {
                return "garbage-without-protocol\n   ";
            }
            return $default;
        });

        $this->assertSame('https://sigma.dataflair.ai/api/v1', $this->detector()->detect());
    }

    public function test_detect_configured_host_returns_the_real_host_when_configured(): void
    {
        Functions\when('get_option')->alias(fn ($key, $default = false) => $key === 'dataflair_api_base_url' ? 'https://tenant.dataflair.ai/api/v1' : $default);

        $this->assertSame('tenant.dataflair.ai', $this->detector()->detectConfiguredHost());
    }

    public function test_detect_configured_host_lowercases_the_host(): void
    {
        // Hostnames are case-insensitive by spec. WebhookController compares
        // this value against a webhook payload's tenant_host with a strict
        // !==, so a purely cosmetic case difference must not make the
        // comparison fail.
        Functions\when('get_option')->alias(fn ($key, $default = false) => $key === 'dataflair_api_base_url' ? 'https://Tenant.DataFlair.ai/api/v1' : $default);

        $this->assertSame('tenant.dataflair.ai', $this->detector()->detectConfiguredHost());
    }

    public function test_detect_configured_host_is_null_when_nothing_is_stored(): void
    {
        // The exact case detect() itself can't signal: nothing configured,
        // so detect() would silently return the hard-coded fallback host
        // instead of empty/null.
        Functions\when('get_option')->alias(fn ($key, $default = false) => $default);

        $this->assertNull($this->detector()->detectConfiguredHost());
    }

    public function test_detect_configured_host_is_null_when_the_stored_url_has_no_parseable_host(): void
    {
        // Non-empty (isConfigured() === true) but schemeless, so detect()
        // returns a string with no parseable host - the exact combination
        // that let WebhookController's tenant guard silently pass when the
        // payload also had no tenant_host (null === null).
        Functions\when('get_option')->alias(fn ($key, $default = false) => $key === 'dataflair_api_base_url' ? 'tenant.dataflair.ai/api/v1' : $default);

        $this->assertNull($this->detector()->detectConfiguredHost());
    }
}
