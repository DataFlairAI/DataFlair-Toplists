<?php
/**
 * Phase 9.10 — Thin wrapper around the LogoDownloader contract.
 *
 * The heavy lifting (HEAD-first size check, 3 MB cap, 8 s timeout,
 * 7-day reuse window, `dataflair_brand_logo_stored` action hook,
 * upload/sideload flow) lives in {@see \DataFlair\Toplists\Http\LogoDownloader}
 * — Phase 2 extracted the implementation. This sync-side wrapper exists
 * so the god-class delegator (`download_brand_logo()`) has an
 * extraction destination matching the v2.1.x carve-out plan.
 *
 * Behaviour preserved verbatim: returns the local URL on success, or
 * `false` on every failure path the downloader signals.
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Sync;

use DataFlair\Toplists\Http\LogoDownloaderInterface;

final class LogoSync
{
    public function __construct(
        private LogoDownloaderInterface $downloader
    ) {
    }

    /**
     * @param array<string,mixed> $brandData
     * @return string|false Local logo URL or `false` on failure.
     */
    public function download(array $brandData, string $brandSlug): string|false
    {
        return $this->downloader->download($brandData, $brandSlug);
    }
}
