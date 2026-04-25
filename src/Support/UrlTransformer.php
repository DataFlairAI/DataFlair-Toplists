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
}
