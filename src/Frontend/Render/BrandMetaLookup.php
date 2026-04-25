<?php
/**
 * Phase 9.9 — Brand-meta cascade lookup helper.
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Frontend\Render;

/**
 * Resolve a single item's brand row from the prefetched brand-meta map using
 * the same cascading preference render_casino_card() has always used:
 *   1. api_brand_id   (numeric)
 *   2. id             (numeric, fallback for older payloads)
 *   3. slug           (string)
 *   4. name           (string)
 *
 * Pure helper — no DB / WP dependencies. Pairs with {@see BrandMetaPrefetcher}
 * which builds the map.
 */
final class BrandMetaLookup
{
    /**
     * @param array<string,mixed> $brand
     * @param array{ids: array<int,object>, slugs: array<string,object>, names: array<string,object>} $meta_map
     */
    public function lookup(array $brand, array $meta_map): ?object
    {
        if (!empty($brand['api_brand_id']) && isset($meta_map['ids'][(int) $brand['api_brand_id']])) {
            return $meta_map['ids'][(int) $brand['api_brand_id']];
        }
        if (!empty($brand['id']) && isset($meta_map['ids'][(int) $brand['id']])) {
            return $meta_map['ids'][(int) $brand['id']];
        }
        if (!empty($brand['slug']) && isset($meta_map['slugs'][(string) $brand['slug']])) {
            return $meta_map['slugs'][(string) $brand['slug']];
        }
        if (!empty($brand['name']) && isset($meta_map['names'][(string) $brand['name']])) {
            return $meta_map['names'][(string) $brand['name']];
        }
        return null;
    }
}
