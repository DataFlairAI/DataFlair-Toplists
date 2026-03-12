<?php
/**
 * Data Integrity Checker for DataFlair Toplist API responses.
 *
 * Validates that all critical nested data is present in an API response
 * before it is stored. Returns structured warnings so the admin can see
 * exactly what data is missing — without blocking the sync.
 *
 * Usage:
 *   $result = DataFlair_DataIntegrityChecker::validate($toplist_data);
 *   // $result['warnings']     — array of human-readable issue strings
 *   // $result['item_count']   — total items in the toplist
 *   // $result['locked_count'] — items with isLocked=true
 *   // $result['geo_coverage'] — unique geo names across all offer geos
 */

if (!defined('ABSPATH')) {
    exit;
}

class DataFlair_DataIntegrityChecker {

    /**
     * Validates a decoded toplist API response and returns a structured report.
     *
     * @param array $toplist_data The decoded 'data' key from the API response
     * @return array {
     *     'warnings'     => string[],
     *     'item_count'   => int,
     *     'locked_count' => int,
     *     'geo_coverage' => string[]
     * }
     */
    public static function validate(array $toplist_data): array {
        $result = [
            'warnings'     => [],
            'item_count'   => 0,
            'locked_count' => 0,
            'geo_coverage' => [],
        ];

        // ── Top-level required fields ──
        $required_top = ['id', 'name', 'status', 'version', 'template', 'site', 'geo', 'items'];
        foreach ($required_top as $field) {
            if (!isset($toplist_data[$field]) || $toplist_data[$field] === null) {
                $result['warnings'][] = "Toplist missing top-level field: {$field}";
            }
        }

        // ── Snapshot fields (expected after Phase 1 backend deploy) ──
        $snapshot_fields = ['slug', 'currentPeriod', 'publishedAt', 'shortcode'];
        foreach ($snapshot_fields as $field) {
            if (!isset($toplist_data[$field]) || $toplist_data[$field] === null) {
                $result['warnings'][] = "Toplist missing snapshot field: {$field} (expected after Phase 1 backend deploy)";
            }
        }

        // ── Template validation ──
        $template = $toplist_data['template'] ?? [];
        if (empty($template['productType'])) {
            $result['warnings'][] = "Template missing productType";
        }

        // ── Geo validation ──
        $geo = $toplist_data['geo'] ?? [];
        if (empty($geo['name'])) {
            $result['warnings'][] = "Toplist missing geo name";
        }

        // ── Items validation ──
        $items = $toplist_data['items'] ?? [];
        if (!is_array($items)) {
            $result['warnings'][] = "Toplist items is not an array";
            return $result;
        }

        $result['item_count'] = count($items);

        $geo_map = [];
        foreach ($items as $index => $item) {
            $pos = $item['position'] ?? ($index + 1);

            // Count locked items
            if (!empty($item['isLocked'])) {
                $result['locked_count']++;
            }

            // Validate each item
            $item_warnings = self::validateItem($item, $pos);
            $result['warnings'] = array_merge($result['warnings'], $item_warnings);

            // Collect geo coverage
            $offer_geos = $item['offer']['geos'] ?? [];
            $countries  = is_array($offer_geos['countries'] ?? null) ? $offer_geos['countries'] : [];
            $markets    = is_array($offer_geos['markets'] ?? null)   ? $offer_geos['markets']   : [];
            foreach (array_merge($countries, $markets) as $geo_name) {
                if (!empty($geo_name)) {
                    $geo_map[$geo_name] = true;
                }
            }
        }

        $result['geo_coverage'] = array_keys($geo_map);
        return $result;
    }

    /**
     * Validates a single toplist item and returns an array of warning strings.
     *
     * @param array $item     Decoded item object from API response
     * @param mixed $pos      Position label for error messages
     * @return string[]
     */
    private static function validateItem(array $item, $pos): array {
        $warnings = [];

        // ── Brand ──
        $brand = $item['brand'] ?? null;
        if (!$brand) {
            $warnings[] = "Position {$pos}: missing brand object";
            return $warnings; // Cannot validate further without brand
        }

        $brand_required = ['id', 'name', 'slug', 'logo', 'rating'];
        foreach ($brand_required as $field) {
            $val = $brand[$field] ?? null;
            if ($val === null || $val === '') {
                $warnings[] = "Position {$pos}: brand missing {$field}";
            }
        }

        // Logo sub-fields
        if (is_array($brand['logo'] ?? null)) {
            if (empty($brand['logo']['rectangular'])) {
                $warnings[] = "Position {$pos}: brand logo missing rectangular URL";
            }
        }

        // ── Offer ──
        $offer = $item['offer'] ?? null;
        if (!$offer) {
            $warnings[] = "Position {$pos}: missing offer object";
            return $warnings; // Cannot validate further without offer
        }

        if (empty($offer['offerText'])) {
            $warnings[] = "Position {$pos}: offer missing offerText";
        }
        if (empty($offer['offerTypeName'])) {
            $warnings[] = "Position {$pos}: offer missing offerTypeName";
        }

        // ⛔ OFFER GEOS — the known bug. Validate explicitly and thoroughly.
        $geos = $offer['geos'] ?? null;
        if ($geos === null) {
            $warnings[] = "Position {$pos}: offer geos is NULL (not present in API response)";
        } elseif (!is_array($geos)) {
            $warnings[] = "Position {$pos}: offer geos is not an array (type: " . gettype($geos) . ")";
        } else {
            $countries = $geos['countries'] ?? null;
            $markets   = $geos['markets'] ?? null;

            if ($countries === null) {
                $warnings[] = "Position {$pos}: offer geos.countries is NULL";
            } elseif (!is_array($countries)) {
                $warnings[] = "Position {$pos}: offer geos.countries is not an array";
            }

            if ($markets === null) {
                $warnings[] = "Position {$pos}: offer geos.markets is NULL";
            } elseif (!is_array($markets)) {
                $warnings[] = "Position {$pos}: offer geos.markets is not an array";
            }

            // Both present as arrays but both empty is worth flagging
            if (is_array($countries) && is_array($markets) && empty($countries) && empty($markets)) {
                $warnings[] = "Position {$pos}: offer has empty geos (no countries and no markets)";
            }
        }

        // ── Offer currencies ──
        if (empty($offer['currencies'])) {
            $warnings[] = "Position {$pos}: offer missing currencies";
        }

        // ── Trackers ──
        $trackers = $offer['trackers'] ?? [];
        if (empty($trackers)) {
            $warnings[] = "Position {$pos}: offer has 0 trackers";
        }

        foreach ($trackers as $ti => $tracker) {
            if (empty($tracker['trackerLink'])) {
                $warnings[] = "Position {$pos}, tracker #{$ti}: missing trackerLink";
            }
            if (empty($tracker['tcLink'])) {
                $warnings[] = "Position {$pos}, tracker #{$ti}: missing tcLink";
            }

            $tracker_geos = $tracker['geos'] ?? null;
            if ($tracker_geos === null) {
                $warnings[] = "Position {$pos}, tracker #{$ti}: missing geos object";
            } else {
                $tg_countries = $tracker_geos['countries'] ?? [];
                $tg_markets   = $tracker_geos['markets'] ?? [];
                if (empty($tg_countries) && empty($tg_markets)) {
                    $warnings[] = "Position {$pos}, tracker #{$ti}: tracker has empty geos";
                }
            }
        }

        return $warnings;
    }
}
