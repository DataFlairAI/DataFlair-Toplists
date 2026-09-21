<?php
/**
 * Phase 9.11 — HTTPS upgrade for non-local URLs.
 *
 * Production / staging redirects from HTTP → HTTPS strip the
 * `Authorization` header. We rewrite outgoing API URLs to HTTPS
 * up-front unless they target a local dev domain.
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Support;

final class UrlTransformer
{
    public function __construct(private UrlValidator $validator)
    {
    }

    /**
     * Force `https://` on $url unless it is a local dev domain.
     */
    public function maybeForceHttps(string $url): string
    {
        if ($this->validator->isLocal($url)) {
            return $url;
        }
        return preg_replace('#^http://#i', 'https://', $url);
    }

    /**
     * Point an API base URL at a given version by rewriting its `/api/vN`
     * segment. A base without that segment comes back unchanged: brand sync
     * must never rewrite a URL it does not recognise. Brand sync (v2 default,
     * v1 legacy opt-in) and the admin API preview share this one owner, which is
     * what keeps Settings describing the URL sync really calls.
     */
    public static function withApiVersion(string $url, string $version): string
    {
        return (string) preg_replace('#/api/v\d+$#', '/api/' . $version, rtrim($url, '/'));
    }

    /**
     * Like withApiVersion(), but also rewrites a bare trailing `/vN` (no
     * `/api/` segment) — for a caller whose whole job is guaranteeing a
     * version, such as the admin API preview forcing V2 to reach a
     * V2-only endpoint. Brand sync deliberately does NOT use this: it
     * must never guess-rewrite a URL shape it does not recognise.
     */
    public static function forceApiVersion(string $url, string $version): string
    {
        $rewritten = self::withApiVersion($url, $version);
        if ($rewritten !== rtrim($url, '/')) {
            return $rewritten;
        }

        return (string) preg_replace('#/v\d+$#', '/' . $version, rtrim($url, '/'));
    }
}
