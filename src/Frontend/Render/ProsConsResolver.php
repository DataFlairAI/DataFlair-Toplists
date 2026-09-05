<?php
declare(strict_types=1);

namespace DataFlair\Toplists\Frontend\Render;

/**
 * Shared pros/cons resolution helper.
 *
 * Extracted from the god-class `resolve_pros_cons_for_table_item()` method
 * so both {@see CardRenderer} and {@see TableRenderer} can honour
 * block-level pros/cons overrides identically. The old god-class helper
 * was called directly from `views/frontend/casino-card.php` via
 * `$this->resolve_pros_cons_for_table_item(...)`; to keep that template
 * contract working when `$this` is the extracted renderer, {@see
 * CardRenderer} exposes a public forwarding method of the same name.
 *
 * Key formats (newest first):
 * - `casino-brand-{apiBrandId}` — stable; survives toplist reorders
 * - `casino-item-{listItemId}` — stable item id when brand id is absent
 * - `casino-slug-{slug}` — stable slug fallback
 * - `casino-{position}-{slug}` — legacy Gutenberg key; position changes
 *   when the DataFlair manager reorders a toplist, so lookup must scan
 *   any position for that brand, not only the current one
 */
trait ProsConsResolver
{
    /**
     * Resolve pros/cons for one row, honouring block-level overrides first
     * and falling back to the item's own `pros`/`cons` arrays.
     *
     * @param array<string,mixed> $item
     * @param array<string,array<string,mixed>> $pros_cons_data
     * @return array{pros: array<int,string>, cons: array<int,string>}
     */
    public function resolve_pros_cons_for_table_item(array $item, array $pros_cons_data): array
    {
        $fallback = array(
            'pros' => array(),
            'cons' => array(),
        );

        if (!empty($item['pros']) && is_array($item['pros'])) {
            $fallback['pros'] = $this->trimScalarList($item['pros']);
        }
        if (!empty($item['cons']) && is_array($item['cons'])) {
            $fallback['cons'] = $this->trimScalarList($item['cons']);
        }

        if (empty($pros_cons_data)) {
            return $fallback;
        }

        $brand = isset($item['brand']) && is_array($item['brand']) ? $item['brand'] : array();
        // Drift guard: the caller hands us the RAW item, so a retyped
        // brand.name must not warn on the (string) cast.
        $brand_name = isset($brand['name']) && is_scalar($brand['name']) ? (string) $brand['name'] : '';
        $position = isset($item['position']) ? (int) $item['position'] : 0;
        $item_id = isset($item['id']) ? (int) $item['id'] : 0;
        $brand_id = 0;

        if (!empty($brand['id'])) {
            $brand_id = (int) $brand['id'];
        } elseif (!empty($brand['api_brand_id'])) {
            $brand_id = (int) $brand['api_brand_id'];
        } elseif (!empty($item['brandId'])) {
            $brand_id = (int) $item['brandId'];
        }

        // WHY: Gutenberg historically keyed overrides as casino-{position}-{slug}.
        // After a reorder the toplist id stays the same but positions move —
        // collect every plausible slug so we can still find the saved copy.
        $brand_slugs = $this->collectBrandSlugs($brand, $brand_name);

        $candidate_keys = array();
        if ($brand_id > 0) {
            $candidate_keys[] = 'casino-brand-' . $brand_id;
        }
        if ($item_id > 0) {
            $candidate_keys[] = 'casino-item-' . $item_id;
        }
        foreach ($brand_slugs as $brand_slug) {
            $candidate_keys[] = 'casino-slug-' . $brand_slug;
            if ($position > 0) {
                $candidate_keys[] = 'casino-' . $position . '-' . $brand_slug;
            }
        }

        $legacy_key = $this->findLegacyPositionKey($pros_cons_data, $brand_slugs);
        if ($legacy_key !== null) {
            $candidate_keys[] = $legacy_key;
        }

        $candidate_keys = array_values(array_unique($candidate_keys));

        foreach ($candidate_keys as $candidate_key) {
            if (empty($pros_cons_data[$candidate_key]) || !is_array($pros_cons_data[$candidate_key])) {
                continue;
            }

            $override = $pros_cons_data[$candidate_key];
            return array(
                'pros' => !empty($override['pros']) && is_array($override['pros']) ? $this->trimScalarList($override['pros']) : $fallback['pros'],
                'cons' => !empty($override['cons']) && is_array($override['cons']) ? $this->trimScalarList($override['cons']) : $fallback['cons'],
            );
        }

        return $fallback;
    }

    /**
     * Build unique sanitized slug candidates used by block override keys.
     *
     * @param array<string,mixed> $brand
     * @return array<int,string>
     */
    private function collectBrandSlugs(array $brand, string $brand_name): array
    {
        $slugs = array();

        if (isset($brand['slug']) && is_scalar($brand['slug']) && (string) $brand['slug'] !== '') {
            $raw_slug = (string) $brand['slug'];
            $slugs[] = sanitize_title($raw_slug);
            // Keep the raw slug too when it already looks slug-like — older
            // block attributes may have stored either form.
            $slugs[] = $raw_slug;
        }

        if ($brand_name !== '') {
            $slugs[] = sanitize_title($brand_name);
        }

        return array_values(array_unique(array_filter(
            $slugs,
            static function ($slug) {
                return is_string($slug) && $slug !== '';
            }
        )));
    }

    /**
     * Find a legacy `casino-{N}-{slug}` override after the brand moved rank.
     *
     * Mirrors the Gutenberg editor's `findLegacyCasinoKeyByBrand()` so the
     * frontend keeps showing pros/cons without requiring an editor click to
     * rematerialize the stable key.
     *
     * @param array<string,array<string,mixed>> $pros_cons_data
     * @param array<int,string> $brand_slugs
     */
    private function findLegacyPositionKey(array $pros_cons_data, array $brand_slugs): ?string
    {
        if ($brand_slugs === array()) {
            return null;
        }

        foreach (array_keys($pros_cons_data) as $key) {
            if (!is_string($key) || !preg_match('/^casino-\d+-(.+)$/', $key, $matches)) {
                continue;
            }

            $key_slug = $matches[1];
            foreach ($brand_slugs as $brand_slug) {
                if ($key_slug === $brand_slug) {
                    return $key;
                }
            }
        }

        return null;
    }

    /**
     * trim() fatals on non-string input under strict_types (PHP 8 TypeError),
     * and upstream contract drift can retype list entries at any time — cast
     * scalars, drop everything else, so a drifted entry degrades instead of
     * white-screening every page that renders a toplist.
     *
     * @param array<int|string, mixed> $values
     * @return array<int, string>
     */
    private function trimScalarList(array $values): array
    {
        return array_values(array_filter(
            array_map(
                static function ($value) {
                    return is_scalar($value) ? trim((string) $value) : '';
                },
                $values
            ),
            static function ($value) {
                return $value !== '';
            }
        ));
    }
}
