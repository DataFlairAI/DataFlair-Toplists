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

        if ($visitorCountryAlpha2 === null) {
            return false;
        }

        if ($geoType === 'country') {
            return ($geo['code'] ?? null) === $visitorCountryAlpha2;
        }

        if ($geoType === 'market') {
            $covered = $geo['coveredCountries'] ?? null;

            return is_array($covered) && in_array($visitorCountryAlpha2, $covered, true);
        }

        return false;
    }
}
