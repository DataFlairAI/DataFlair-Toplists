<?php
/**
 * Phase 9.11 — Local-URL detection.
 *
 * Identifies development hosts (`*.test`, `*.local`, `localhost`,
 * `127.0.0.1`, `::1`) so the rest of the plugin can decide whether
 * to force HTTPS, skip SSL verification, etc.
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Support;

final class UrlValidator
{
    /**
     * True when $url's host looks like a local development domain.
     */
    public function isLocal(string $url): bool
    {
        $parsed = parse_url($url);
        $host = isset($parsed['host']) ? $parsed['host'] : '';

        return (
            preg_match('/\.(test|local|localhost|invalid|example)$/i', $host)
            || $host === 'localhost'
            || $host === '127.0.0.1'
            || $host === '::1'
        );
    }
}
