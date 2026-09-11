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

    /**
     * @param int[]|null $ids When given, restricts the page to just these
     *                        api_brand_ids (still paginated) instead of the
     *                        full catalog - used by "re-sync selected".
     */
    public function buildPageUrl(int $page, int $perPage = 25, ?array $ids = null): string
    {
        $url = $this->effectiveBase() . '/brands?per_page=' . $perPage . '&page=' . $page;

        if ($ids !== null) {
            foreach ($ids as $id) {
                $url .= '&ids[]=' . (int) $id;
            }
        }

        return $url;
    }

    /**
     * The base URL brand sync will hit after the `dataflair_brands_api_version`
     * rewrite. The stored option can still read `/api/v1` while V2 is selected
     * and in effect (or vice versa, after a manual URL edit), so Settings shows
     * this instead of the raw option. The rewrite is symmetric — whichever
     * version is selected is the one applied — so the radio is authoritative
     * in both directions, not just v1-to-v2. Settings passes $persist = false
     * so rendering never writes an option.
     */
    public function effectiveBase(bool $persist = true): string
    {
        $base    = $this->base->detect($persist);
        $version = get_option('dataflair_brands_api_version', 'v1') === 'v2' ? 'v2' : 'v1';

        return UrlTransformer::withApiVersion($base, $version);
    }
}
