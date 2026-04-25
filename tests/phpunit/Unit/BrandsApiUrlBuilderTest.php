<?php
/**
 * Phase 9.11 — Pins Http\BrandsApiUrlBuilder behaviour: respects the
 * `dataflair_brands_api_version` option (v1 default, v2 opt-in) and
 * appends the page query parameter.
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Tests\Unit\Http;

use Brain\Monkey;
use Brain\Monkey\Functions;
use DataFlair\Toplists\Http\ApiBaseUrlDetector;
use DataFlair\Toplists\Http\BrandsApiUrlBuilder;
use DataFlair\Toplists\Support\UrlTransformer;
use DataFlair\Toplists\Support\UrlValidator;
use PHPUnit\Framework\TestCase;

require_once DATAFLAIR_PLUGIN_DIR . 'src/Support/UrlValidator.php';
require_once DATAFLAIR_PLUGIN_DIR . 'src/Support/UrlTransformer.php';
require_once DATAFLAIR_PLUGIN_DIR . 'src/Http/ApiBaseUrlDetector.php';
require_once DATAFLAIR_PLUGIN_DIR . 'src/Http/BrandsApiUrlBuilder.php';

final class BrandsApiUrlBuilderTest extends TestCase
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

    private function builder(): BrandsApiUrlBuilder
    {
        return new BrandsApiUrlBuilder(
            new ApiBaseUrlDetector(new UrlTransformer(new UrlValidator()))
        );
    }

    public function test_v1_default_appends_page_param(): void
    {
        Functions\when('get_option')->alias(function ($key, $default = false) {
            if ($key === 'dataflair_api_base_url')      return 'https://tenant.dataflair.ai/api/v1';
            if ($key === 'dataflair_brands_api_version') return 'v1';
            return $default;
        });

        $this->assertSame(
            'https://tenant.dataflair.ai/api/v1/brands?page=2',
            $this->builder()->buildPageUrl(2)
        );
    }

    public function test_v2_opt_in_rewrites_path_segment(): void
    {
        Functions\when('get_option')->alias(function ($key, $default = false) {
            if ($key === 'dataflair_api_base_url')      return 'https://tenant.dataflair.ai/api/v1';
            if ($key === 'dataflair_brands_api_version') return 'v2';
            return $default;
        });

        $this->assertSame(
            'https://tenant.dataflair.ai/api/v2/brands?page=5',
            $this->builder()->buildPageUrl(5)
        );
    }

    public function test_strips_trailing_slash_before_appending(): void
    {
        Functions\when('get_option')->alias(function ($key, $default = false) {
            if ($key === 'dataflair_api_base_url')      return 'https://tenant.dataflair.ai/api/v1/';
            if ($key === 'dataflair_brands_api_version') return 'v1';
            return $default;
        });

        $this->assertSame(
            'https://tenant.dataflair.ai/api/v1/brands?page=1',
            $this->builder()->buildPageUrl(1)
        );
    }

    public function test_falls_back_to_default_base_when_nothing_stored(): void
    {
        Functions\when('get_option')->alias(function ($key, $default = false) {
            return $default;
        });

        $this->assertSame(
            'https://sigma.dataflair.ai/api/v1/brands?page=1',
            $this->builder()->buildPageUrl(1)
        );
    }
}
