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
        $brand_slug = sanitize_title($brand_name);
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

        $candidate_keys = array();
        if ($brand_id > 0) {
            $candidate_keys[] = 'casino-brand-' . $brand_id;
        }
        if ($item_id > 0) {
            $candidate_keys[] = 'casino-item-' . $item_id;
        }
        if (!empty($brand_slug)) {
            $candidate_keys[] = 'casino-slug-' . $brand_slug;
            $candidate_keys[] = 'casino-' . $position . '-' . $brand_slug;
        }

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
