<?php
/**
 * Phase 9.11 — DataFlair API base-URL resolution.
 *
 * Resolves the base URL the API client should hit. Order of precedence:
 *   1. `dataflair_api_base_url` option (manually set or auto-cached).
 *   2. First entry of the `dataflair_api_endpoints` option, with the
 *      base extracted, HTTPS-forced, and cached back into option 1.
 *   3. Hard-coded fallback to `https://sigma.dataflair.ai/api/v1`.
 *
 * Returns a URL with no trailing slash and no path beyond `/api/vN`.
 *
 * Tier 2 caches the extracted base back into option 1. Read-only callers (a
 * Settings render) pass $persist = false so a GET never writes an option.
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Http;

use DataFlair\Toplists\Support\UrlTransformer;

final class ApiBaseUrlDetector
{
    private const FALLBACK = 'https://sigma.dataflair.ai/api/v1';

    public function __construct(private UrlTransformer $transformer)
    {
    }

    public function detect(bool $persist = true): string
    {
        $stored = get_option('dataflair_api_base_url');
        if (! empty($stored)) {
            $stored = $this->transformer->maybeForceHttps((string) $stored);
            $stored = preg_replace('#(/api/v\d+)/.*$#', '$1', $stored);
            return rtrim((string) $stored, '/');
        }

        $fromEndpoints = $this->baseFromEndpoints();
        if ($fromEndpoints !== null) {
            $base = $this->transformer->maybeForceHttps($fromEndpoints);
            if ($persist) {
                update_option('dataflair_api_base_url', $base);
            }
            return rtrim($base, '/');
        }

        return self::FALLBACK;
    }

    /**
     * True when tier 1 or tier 2 can resolve a base. False means detect()
     * would return the hard-coded fallback, which Settings must not present
     * as this tenant's own configuration.
     */
    public function isConfigured(): bool
    {
        return ! empty(get_option('dataflair_api_base_url')) || $this->baseFromEndpoints() !== null;
    }

    /**
     * The host of this tenant's own configured base URL, or null - never
     * self::FALLBACK's host. detect() itself can't signal "unconfigured":
     * an empty/unparseable setting still makes it return a real, parseable
     * DataFlair host, so composing isConfigured() + detect() ad-hoc at each
     * call site has repeatedly been gotten wrong (fixed three times in the
     * same webhook receiver alone - see WebhookController's git history).
     * Every caller that needs certainty a host belongs to THIS tenant,
     * rather than a best-effort display value, should call this instead.
     */
    public function detectConfiguredHost(bool $persist = true): ?string
    {
        if (! $this->isConfigured()) {
            return null;
        }

        $host = parse_url($this->detect($persist), PHP_URL_HOST);

        return is_string($host) && $host !== '' ? $host : null;
    }

    private function baseFromEndpoints(): ?string
    {
        $endpoints = get_option('dataflair_api_endpoints');
        if (empty($endpoints)) {
            return null;
        }

        $list = array_filter(array_map('trim', explode("\n", (string) $endpoints)));
        if (empty($list)) {
            return null;
        }

        return preg_match('#^(https?://[^/]+/api/v\d+)/#', (string) reset($list), $matches) ? $matches[1] : null;
    }
}
