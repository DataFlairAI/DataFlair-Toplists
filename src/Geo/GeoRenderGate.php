<?php

declare(strict_types=1);

namespace DataFlair\Toplists\Geo;

/**
 * Layer 1 render-safety gate — docs/contracts/geo-targeting.md on the main
 * DataFlair repo is the source of truth this mirrors. Applies to every
 * render regardless of how the toplist was selected: pinned by a fixed
 * slug/ID (the common case — one embed per page) or arrived at via
 * GeoFamilySelector's Layer 2 auto-select cascade.
 *
 * Default-deny: an unresolved visitor country never matches a country/market
 * toplist. Global is the only geo_type that renders unconditionally — an
 * explicit "everyone" editorial choice, never an automatic fallback. No
 * match means empty output, not an error state.
 *
 * Not filterable, unlike this plugin's other collaborators — this is
 * compliance-mandated algorithm logic, not a swappable I/O boundary.
 */
final class GeoRenderGate
{
    /**
     * @param  array{geo_type?:string|null,code?:string|null,coveredCountries?:array<int,string>|null}|null  $geo
     *     Decoded `data.geo` object from a synced toplist row.
     */
    public function shouldRender(?array $geo, ?string $visitorCountryAlpha2): bool
    {
        $geoType = $geo['geo_type'] ?? null;

        if ($geoType === 'global') {
            return true;
        }

        $visitorCountryAlpha2 = $this->normalizeCode($visitorCountryAlpha2);

        if ($visitorCountryAlpha2 === null) {
            return false;
        }

        if ($geoType === 'country') {
            return $this->normalizeCode($geo['code'] ?? null) === $visitorCountryAlpha2;
        }

        if ($geoType === 'market') {
            $covered = $geo['coveredCountries'] ?? null;

            if (!is_array($covered)) {
                return false;
            }

            foreach ($covered as $coveredCode) {
                if ($this->normalizeCode($coveredCode) === $visitorCountryAlpha2) {
                    return true;
                }
            }

            return false;
        }

        return false;
    }

    /**
     * Same uppercase+trim normalization VisitorGeoResolver applies to the
     * detected visitor country — mirrored here so a mixed-case stored code
     * (manual edit, import) can't silently fail to match an otherwise
     * correct visitor match.
     */
    private function normalizeCode(mixed $code): ?string
    {
        if (!is_string($code) || $code === '') {
            return null;
        }

        $normalized = strtoupper(trim($code));

        return $normalized === '' ? null : $normalized;
    }
}
