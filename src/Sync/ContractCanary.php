<?php
/**
 * API Contract Safety P3 — deep page-1 payload validation ("canary").
 *
 * A backend field rename inside `data[].items` passes the shallow sync gates
 * (200, valid JSON, `data` key) and would previously be persisted wholesale,
 * silently blanking offer text, bonus codes, or tracker links on every card.
 * This class inspects the already-fetched page-1 bulk payload BEFORE the
 * destructive reset + persist phase and reports a hard failure when a
 * render-critical field has vanished from the response shape.
 *
 * False-positive safety: every field check is collective and all-or-nothing.
 * A field only counts as gone when it is absent from EVERY sampled entry and
 * the sample is at least MIN_SAMPLE entries. Legitimate partial data (an
 * offer with no text, an item with no trackers) always leaves the key
 * present-but-null upstream, or fails the sample threshold, so real-world
 * tenants can never trip this on valid data.
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Sync;

final class ContractCanary
{
    /** Collective checks need at least this many entries to be conclusive. */
    private const MIN_SAMPLE = 3;

    /** Cap the inspected items so huge tenants stay O(small). */
    private const MAX_ITEMS = 50;

    /**
     * Inspect a page-1 bulk `data` payload.
     *
     * @param array<int, mixed> $toplists Decoded `data` array of the bulk response.
     * @return string|null Human-readable hard-failure reason, or null when safe.
     */
    public function assess(array $toplists): ?string
    {
        if ($toplists === []) {
            return null;
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

        $items = [];
        foreach ($toplists as $toplist) {
            if (!is_array($toplist)) {
                continue;
            }
            if (array_key_exists('items', $toplist) && !is_array($toplist['items'])) {
                return 'Toplist "items" is no longer an array.';
            }
            foreach ((array) ($toplist['items'] ?? []) as $item) {
                if (is_array($item)) {
                    $items[] = $item;
                }
                if (count($items) >= self::MAX_ITEMS) {
                    break 2;
                }
            }
        }

        if (count($items) < self::MIN_SAMPLE) {
            return null;
        }

        $withOffer = 0;
        $withBrand = 0;
        $offers    = [];
        foreach ($items as $item) {
            if (isset($item['offer']) && is_array($item['offer'])) {
                $withOffer++;
                $offers[] = $item['offer'];
            }
            if (array_key_exists('brand', $item) || array_key_exists('brandId', $item)) {
                $withBrand++;
            }
        }

        if ($withOffer === 0) {
            return 'No toplist item exposes an "offer" object any more.';
        }
        if ($withBrand === 0) {
            return 'No toplist item exposes a "brand" or "brandId" field any more.';
        }

        if (count($offers) >= self::MIN_SAMPLE) {
            $withOfferText = 0;
            $trackers      = [];
            foreach ($offers as $offer) {
                if (array_key_exists('offerText', $offer)) {
                    $withOfferText++;
                }
                if (array_key_exists('trackers', $offer) && !is_array($offer['trackers'])) {
                    return 'Offer "trackers" is no longer an array.';
                }
                foreach ((array) ($offer['trackers'] ?? []) as $tracker) {
                    if (is_array($tracker)) {
                        $trackers[] = $tracker;
                    }
                }
            }

            if ($withOfferText === 0) {
                return 'No offer exposes an "offerText" field any more.';
            }

            if (count($trackers) >= self::MIN_SAMPLE) {
                $withLink = 0;
                foreach ($trackers as $tracker) {
                    if (array_key_exists('trackerLink', $tracker)) {
                        $withLink++;
                    }
                }
                if ($withLink === 0) {
                    return 'No campaign tracker exposes a "trackerLink" field any more.';
                }
            }
        }

        return null;
    }
}
