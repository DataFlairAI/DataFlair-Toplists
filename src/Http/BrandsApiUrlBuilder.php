<?php
/**
 * Phase 9.11 — Brands API URL builder.
 *
 * Brands sync respects the `dataflair_brands_api_version` option (`v1`
 * default, `v2` opt-in). Toplists always use v1, so this helper exists
 * only for the brands path.
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Http;

final class BrandsApiUrlBuilder
{
    public function __construct(private ApiBaseUrlDetector $base)
    {
    }

    public function buildPageUrl(int $page, int $perPage = 25): string
    {
        return rtrim($this->effectiveBase(), '/') . '/brands?per_page=' . $perPage . '&page=' . $page;
    }

    /**
     * The base URL brand sync will actually hit, after the
     * `dataflair_brands_api_version` rewrite — what Settings should display
     * as "Current", since the raw stored option can otherwise still read
     * `/api/v1` while V2 is selected and in effect.
     */
    public function effectiveBase(): string
    {
        $version = get_option('dataflair_brands_api_version', 'v1');
        $base    = $this->base->detect();

        if ($version === 'v2') {
            $base = preg_replace('#/api/v\d+$#', '/api/v2', $base);
        }

        return rtrim((string) $base, '/');
    }
}
