<?php
/**
 * Contract for brand-logo sideloading.
 *
 * Phase 2 — extracted from `DataFlair_Toplists::download_brand_logo()`. The
 * god-class method becomes a thin delegator. Return shape preserved: a
 * string URL on success, `false` on failure.
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Http;

interface LogoDownloaderInterface
{
    /**
     * Download and cache a brand logo locally.
     *
     * @param array  $brand_data Raw brand payload from upstream.
     * @param string $brand_slug Sanitised slug used for the on-disk filename.
     *
     * @return string|false      Local URL to the saved logo, or `false` on any failure.
     */
    public function download(array $brand_data, string $brand_slug);
}
