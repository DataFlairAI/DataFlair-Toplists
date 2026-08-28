<?php
/**
 * API Contract Safety P3 — deep sync-payload validation ("canary").
 *
 * A backend field rename inside `data[].items` passes the shallow sync gates
 * (200, valid JSON, `data` key) and would previously be persisted wholesale,
 * silently blanking offer text, bonus codes, or tracker links on every card.
 * This class inspects each already-fetched bulk payload BEFORE it is
 * persisted (and, on page 1, before the destructive reset) and reports a
 * hard failure when a render-critical field has vanished from the response
 * shape.
 *
 * False-positive safety: a key that is PRESENT with a null value is always
 * valid (that is how the API serializes "no value"), and every field check
 * is collective and all-or-nothing — a field only counts as gone when its
 * key is absent from EVERY sampled entry and the sample is at least
 * MIN_SAMPLE entries. Legitimate partial data can never trip this.
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Sync;

final class ContractCanary
{
    /** Collective checks need at least this many entries to be conclusive. */
    private const MIN_SAMPLE = 3;

    /** Cap sampled items/trackers so huge tenants stay O(small). */
    private const MAX_SAMPLE = 50;

    /**
     * Inspect one page's bulk `data` payload.
     *
     * @param array<int|string, mixed> $toplists Decoded `data` array of the bulk response.
     * @return string|null Human-readable hard-failure reason, or null when safe.
     */
    public function assess(array $toplists): ?string
    {
        if ($toplists === []) {
            return null;
        }
        if (!array_is_list($toplists)) {
            return 'The toplist collection is no longer a plain list.';
        }

        $first = $toplists[0];
        if (!is_array($first)) {
            return 'Toplist entries are no longer objects.';
        }
        if (!array_key_exists('id', $first)) {
            return 'Toplists no longer expose an "id" field.';
        }
        if (!array_key_exists('name', $first)) {
            return 'Toplists no longer expose a "name" field.';
        }

        // Geo is read by the plugin's own render gate and, on sites that do
        // their own geo targeting, by tenant code straight out of the stored
        // payload. It is also the most actively changed part of the upstream
        // response, so it earns an explicit check.
        $anyGeoKey     = false;
        $geoObjects    = 0;
        $anyGeoCode    = false;
        $anyGeoCovered = false;
        foreach ($toplists as $toplist) {
            if (!is_array($toplist) || !array_key_exists('geo', $toplist)) {
                continue;
            }
            $anyGeoKey = true;
            if ($toplist['geo'] === null) {
                continue;
            }
            if (!is_array($toplist['geo'])) {
                return 'Toplist "geo" is no longer an object.';
            }
            $geoObjects++;
            $anyGeoCode    = $anyGeoCode    || array_key_exists('code', $toplist['geo']);
            $anyGeoCovered = $anyGeoCovered || array_key_exists('coveredCountries', $toplist['geo']);
        }
        if (!$anyGeoKey) {
            return 'No toplist exposes a "geo" field any more.';
        }

        // GeoRenderGate matches on `code` (country) and `coveredCountries`
        // (market) and is DEFAULT-DENY: if either key disappears, every
        // country or market toplist silently renders nothing rather than
        // erroring. The upstream resource emits both keys on every geo
        // object, so absence from all of them is drift, never partial data.
        // Gated on MIN_SAMPLE like every other collective check: a page
        // holding a single toplist is not enough evidence to pause a site.
        if ($geoObjects >= self::MIN_SAMPLE && !$anyGeoCode) {
            return 'No toplist geo exposes a "code" field any more (geo-targeted toplists would stop rendering).';
        }
        if ($geoObjects >= self::MIN_SAMPLE && !$anyGeoCovered) {
            return 'No toplist geo exposes a "coveredCountries" field any more (market-targeted toplists would stop rendering).';
        }

        $items         = [];
        $rawItemCount  = 0;
        foreach ($toplists as $toplist) {
            if (!is_array($toplist)) {
                continue;
            }
            // Absent and null both mean "no items here" — only a retype
            // (string/object where a list belongs) is drift.
            $rawItems = $toplist['items'] ?? null;
            if ($rawItems !== null && !is_array($rawItems)) {
                return 'Toplist "items" is no longer an array.';
            }
            foreach ((array) $rawItems as $item) {
                $rawItemCount++;
                if (is_array($item)) {
                    $items[] = $item;
                }
                if (count($items) >= self::MAX_SAMPLE) {
                    break 2;
                }
            }
        }

        // Items present but none of them objects: ID-reference drift
        // ("items": [101, 102, ...]) blanks every card just as hard.
        if ($rawItemCount >= self::MIN_SAMPLE && $items === []) {
            return 'Toplist items are no longer objects.';
        }

        if (count($items) < self::MIN_SAMPLE) {
            return null;
        }

        $offers      = [];
        $anyOfferKey = false;
        $anyBrandKey = false;
        foreach ($items as $item) {
            if (array_key_exists('offer', $item)) {
                $anyOfferKey = true;
                if ($item['offer'] !== null && !is_array($item['offer'])) {
                    return 'Toplist item "offer" is no longer an object.';
                }
                if (is_array($item['offer'])) {
                    $offers[] = $item['offer'];
                }
            }
            if (array_key_exists('brand', $item) || array_key_exists('brandId', $item)) {
                $anyBrandKey = true;
            }
        }
        if (!$anyOfferKey) {
            return 'No toplist item exposes an "offer" field any more.';
        }
        if (!$anyBrandKey) {
            return 'No toplist item exposes a "brand" or "brandId" field any more.';
        }

        if (count($offers) < self::MIN_SAMPLE) {
            return null;
        }

        $anyOfferText = false;
        $trackers     = [];
        foreach ($offers as $offer) {
            if (array_key_exists('offerText', $offer)) {
                $anyOfferText = true;
            }
            $rawTrackers = $offer['trackers'] ?? null;
            if ($rawTrackers !== null && !is_array($rawTrackers)) {
                return 'Offer "trackers" is no longer an array.';
            }
            foreach ((array) $rawTrackers as $tracker) {
                if (is_array($tracker) && count($trackers) < self::MAX_SAMPLE) {
                    $trackers[] = $tracker;
                }
            }
        }
        if (!$anyOfferText) {
            return 'No offer exposes an "offerText" field any more.';
        }

        if (count($trackers) < self::MIN_SAMPLE) {
            return null;
        }
        foreach ($trackers as $tracker) {
            if (array_key_exists('trackerLink', $tracker)) {
                return null;
            }
        }

        return 'No campaign tracker exposes a "trackerLink" field any more.';
    }
}
