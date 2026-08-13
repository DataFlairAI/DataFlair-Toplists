<?php

declare(strict_types=1);

namespace DataFlair\Toplists\Geo;

/**
 * Header-based visitor country resolver — docs/contracts/geo-targeting.md on
 * the main DataFlair repo is the source of truth this mirrors.
 *
 * Tries, in order:
 *  0. `?dataflair_geo=` query param — admin-only QA override (see
 *     resolveAdminOverride()). Lets a logged-in admin preview any country's
 *     geo-gated content from a plain browser URL, no VPN or custom headers
 *     needed. Gated to manage_options so a public visitor can never use it
 *     to bypass the compliance-driven restriction this class exists to
 *     enforce.
 *  1. `CF-IPCountry` header (Cloudflare)
 *  2. `X-Geoip-Country` header (reverse proxy / another plugin)
 *  3. the `dataflair_visitor_country` filter — a seam for a site-installed
 *     GeoIP library. This plugin takes no GeoIP dependency of its own; none
 *     exists in composer.json today, so this filter is the only way a site
 *     can plug one in without this plugin inventing a fake integration.
 *  4. null (unresolved — callers must default-deny, never guess).
 *
 * Read-only: inspects request headers/params only, no HTTP calls, no writes.
 */
final class VisitorGeoResolver implements VisitorGeoResolverInterface
{
    /**
     * Cloudflare sentinel values that mean "not a real country code" —
     * XX = unknown, T1 = Tor exit node. Treated as unresolved, same as a
     * missing header.
     */
    private const UNRESOLVED_SENTINELS = ['XX', 'T1'];

    public function resolve(): ?string
    {
        $override = $this->resolveAdminOverride();
        if ($override !== null) {
            return $override;
        }

        foreach (['HTTP_CF_IPCOUNTRY', 'HTTP_X_GEOIP_COUNTRY'] as $serverKey) {
            $value = $this->normalize($_SERVER[$serverKey] ?? null);
            if ($value !== null) {
                return $value;
            }
        }

        $filtered = function_exists('apply_filters')
            ? apply_filters('dataflair_visitor_country', null)
            : null;

        return $this->normalize($filtered);
    }

    /**
     * `?dataflair_geo=GB` QA override — only honored for a logged-in user
     * with manage_options. Never trusts current_user_can()'s absence as
     * permission: if the capability check itself isn't available, deny.
     */
    private function resolveAdminOverride(): ?string
    {
        if (!isset($_GET['dataflair_geo']) || !is_string($_GET['dataflair_geo'])) {
            return null;
        }

        if (!function_exists('current_user_can') || !current_user_can('manage_options')) {
            return null;
        }

        return $this->normalize($_GET['dataflair_geo']);
    }

    private function normalize(mixed $value): ?string
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        $normalized = strtoupper(trim($value));

        if ($normalized === '' || in_array($normalized, self::UNRESOLVED_SENTINELS, true)) {
            return null;
        }

        return $normalized;
    }
}
