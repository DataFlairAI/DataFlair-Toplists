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

use DataFlair\Toplists\Support\UrlTransformer;

final class BrandsApiUrlBuilder
{
    public function __construct(private ApiBaseUrlDetector $base)
    {
    }

    public function buildPageUrl(int $page, int $perPage = 25): string
    {
        return $this->effectiveBase() . '/brands?per_page=' . $perPage . '&page=' . $page;
    }

    /**
     * The base URL brand sync will hit after the `dataflair_brands_api_version`
     * rewrite. The stored option can still read `/api/v1` while V2 is selected
     * and in effect, so Settings shows this instead of the raw option.
     * Settings passes $persist = false so rendering never writes an option.
     */
    public function effectiveBase(bool $persist = true): string
    {
        $base = $this->base->detect($persist);

        if (get_option('dataflair_brands_api_version', 'v1') === 'v2') {
            return UrlTransformer::withApiVersion($base, 'v2');
        }

        return rtrim($base, '/');
    }
}
