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
            'https://tenant.dataflair.ai/api/v1/brands?per_page=25&page=2',
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
            'https://tenant.dataflair.ai/api/v2/brands?per_page=25&page=5',
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
            'https://tenant.dataflair.ai/api/v1/brands?per_page=25&page=1',
            $this->builder()->buildPageUrl(1)
        );
    }

    public function test_ids_are_appended_as_repeated_query_params(): void
    {
        Functions\when('get_option')->alias(function ($key, $default = false) {
            if ($key === 'dataflair_api_base_url')      return 'https://tenant.dataflair.ai/api/v1';
            if ($key === 'dataflair_brands_api_version') return 'v1';
            return $default;
        });

        $this->assertSame(
            'https://tenant.dataflair.ai/api/v1/brands?per_page=25&page=1&ids[]=5&ids[]=8',
            $this->builder()->buildPageUrl(1, 25, [5, 8])
        );
    }

    public function test_null_ids_omits_the_ids_query_params(): void
    {
        Functions\when('get_option')->alias(function ($key, $default = false) {
            if ($key === 'dataflair_api_base_url')      return 'https://tenant.dataflair.ai/api/v1';
            if ($key === 'dataflair_brands_api_version') return 'v1';
            return $default;
        });

        $this->assertStringNotContainsString('ids', $this->builder()->buildPageUrl(1, 25, null));
    }

    public function test_falls_back_to_default_base_when_nothing_stored(): void
    {
        Functions\when('get_option')->alias(function ($key, $default = false) {
            return $default;
        });

        $this->assertSame(
            'https://sigma.dataflair.ai/api/v1/brands?per_page=25&page=1',
            $this->builder()->buildPageUrl(1)
        );
    }

    /**
     * effectiveBase() is what Settings displays for brand sync. It must
     * reflect the v2 rewrite even though the raw stored option still ends in
     * /v1 — the exact mismatch Sigma read as a broken sync (V2 was selected
     * and working; the label never said so). The v1 case is already pinned
     * by test_v1_default_appends_page_param, since buildPageUrl delegates.
     */
    public function test_effective_base_reflects_v2_rewrite_even_though_stored_option_says_v1(): void
    {
        Functions\when('get_option')->alias(function ($key, $default = false) {
            if ($key === 'dataflair_api_base_url')      return 'https://sigma-staging.dataflair.ai/api/v1';
            if ($key === 'dataflair_brands_api_version') return 'v2';
            return $default;
        });

        $this->assertSame(
            'https://sigma-staging.dataflair.ai/api/v2',
            $this->builder()->effectiveBase()
        );
    }

    /**
     * The rewrite must be symmetric. Before this test, effectiveBase() only
     * rewrote v1->v2 and returned a /api/v2 stored URL unchanged when V1 was
     * selected — so switching the radio back to V1 silently kept calling v2,
     * and the Settings copy ("follows the version selected above") was false
     * for exactly this case.
     */
    public function test_effective_base_downgrades_to_v1_even_though_stored_option_says_v2(): void
    {
        Functions\when('get_option')->alias(function ($key, $default = false) {
            if ($key === 'dataflair_api_base_url')      return 'https://tenant.dataflair.ai/api/v2';
            if ($key === 'dataflair_brands_api_version') return 'v1';
            return $default;
        });

        $this->assertSame(
            'https://tenant.dataflair.ai/api/v1',
            $this->builder()->effectiveBase()
        );
    }

    /**
     * Webhook sync slice — BrandSyncService::syncOne() targets the single-
     * brand endpoint (bypasses the API's active() scope, unlike buildPageUrl's
     * list endpoint), so it needs its own URL, not a page of one.
     */
    public function test_builds_single_brand_url(): void
    {
        Functions\when('get_option')->alias(function ($key, $default = false) {
            if ($key === 'dataflair_api_base_url')      return 'https://tenant.dataflair.ai/api/v1';
            if ($key === 'dataflair_brands_api_version') return 'v1';
            return $default;
        });

        $this->assertSame(
            'https://tenant.dataflair.ai/api/v1/brands/42',
            $this->builder()->buildSingleUrl(42)
        );
    }

    public function test_single_brand_url_respects_v2_opt_in(): void
    {
        Functions\when('get_option')->alias(function ($key, $default = false) {
            if ($key === 'dataflair_api_base_url')      return 'https://tenant.dataflair.ai/api/v1';
            if ($key === 'dataflair_brands_api_version') return 'v2';
            return $default;
        });

        $this->assertSame(
            'https://tenant.dataflair.ai/api/v2/brands/42',
            $this->builder()->buildSingleUrl(42)
        );
    }
}
