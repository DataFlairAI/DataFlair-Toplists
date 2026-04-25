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

    public function buildPageUrl(int $page): string
    {
        $version = get_option('dataflair_brands_api_version', 'v1');
        $base    = $this->base->detect();

        if ($version === 'v2') {
            $base = preg_replace('#/api/v\d+$#', '/api/v2', $base);
        }

        return rtrim((string) $base, '/') . '/brands?page=' . $page;
    }
}
