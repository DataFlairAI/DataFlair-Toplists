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
}
