<?php

declare(strict_types=1);

namespace DataFlair\Toplists\Geo;

/**
 * Layer 2 auto-select cascade — docs/contracts/geo-targeting.md on the main
 * DataFlair repo is the source of truth this mirrors. Optional convenience
 * feature: given every toplist row sharing one template family, picks the
 * best candidate for the current visitor:
 *
 *  1. Exact match   — a geo_type=country row whose code equals the visitor's.
 *  2. Covering market — else, exactly one geo_type=market row whose
 *     coveredCountries contains the visitor's country. More than one match
 *     is treated as ambiguous: no candidate, a warning is logged, never an
 *     arbitrary pick.
 *  3. Explicit global — else, a geo_type=global row in the family.
 *  4. Otherwise — no candidate.
 *
 * When the visitor's country can't be resolved, steps 1-2 can never match
 * (they require a real code to compare), but step 3 still applies — a
 * global row is GeoRenderGate's one visitor-independent case, so an
 * unresolved visitor must still be able to reach it here.
 *
 * Whatever this picks (if anything) still passes through GeoRenderGate
 * before rendering — this class only narrows *which* row to check, it never
 * bypasses the check itself.
 *
 * Not filterable, same reasoning as GeoRenderGate.
 */
final class GeoFamilySelector
{
    /**
     * @param  array<int,array<string,mixed>>  $familyRows  Raw repository
     *     rows (same shape ToplistShortcode already decodes) — each row's
     *     `data` column is the synced JSON blob.
     * @return array<string,mixed>|null The winning raw row, or null.
     */
    public function select(array $familyRows, ?string $visitorCountryAlpha2): ?array
    {
        $candidates = [];
        foreach ($familyRows as $row) {
            $decoded = json_decode((string) ($row['data'] ?? ''), true);
            $geo = $decoded['data']['geo'] ?? null;
            if (is_array($geo)) {
                $candidates[] = ['row' => $row, 'geo' => $geo];
            }
        }

        if ($visitorCountryAlpha2 === null) {
            foreach ($candidates as $candidate) {
                if (($candidate['geo']['geo_type'] ?? null) === 'global') {
                    return $candidate['row'];
                }
            }

            return null;
        }

        foreach ($candidates as $candidate) {
            if (($candidate['geo']['geo_type'] ?? null) === 'country'
                && ($candidate['geo']['code'] ?? null) === $visitorCountryAlpha2) {
                return $candidate['row'];
            }
        }

        $coveringMarkets = array_values(array_filter(
            $candidates,
            static function (array $candidate) use ($visitorCountryAlpha2): bool {
                $covered = $candidate['geo']['coveredCountries'] ?? null;

                return ($candidate['geo']['geo_type'] ?? null) === 'market'
                    && is_array($covered)
                    && in_array($visitorCountryAlpha2, $covered, true);
            }
        ));

        if (count($coveringMarkets) === 1) {
            return $coveringMarkets[0]['row'];
        }

        if (count($coveringMarkets) > 1) {
            error_log(
                'DataFlair: ambiguous geo family match for visitor country '
                .$visitorCountryAlpha2.' — '.count($coveringMarkets)
                .' covering markets found, no candidate selected.'
            );

            return null;
        }

        foreach ($candidates as $candidate) {
            if (($candidate['geo']['geo_type'] ?? null) === 'global') {
                return $candidate['row'];
            }
        }

        return null;
    }
}
