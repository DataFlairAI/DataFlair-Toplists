<?php
/**
 * Phase 9.9 — H7 brand-meta prefetcher.
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Frontend\Render;

use DataFlair\Toplists\Database\BrandsRepositoryInterface;

/**
 * Prefetch brand metadata for every item in a toplist in a single (or at most
 * three) SQL round-trip. Replaces the five cascading $wpdb->prepare calls
 * render_casino_card() previously ran per card. Phase 0B H7 invariant.
 *
 * Returns the same shape the legacy god-class helper did:
 *   ['ids' => [api_brand_id => row], 'slugs' => [...], 'names' => [...]]
 *
 * Rows from BrandsRepository::findManyByApiBrandIds (ARRAY_A) are recast to
 * stdClass so {@see BrandMetaLookup} and the casino-card view-model see the
 * exact same property shape they always have.
 */
final class BrandMetaPrefetcher
{
    public function __construct(
        private readonly BrandsRepositoryInterface $brandsRepo
    ) {
    }

    /**
     * @param array<int,array<string,mixed>> $items
     * @return array{ids: array<int,object>, slugs: array<string,object>, names: array<string,object>}
     */
    public function prefetch(array $items): array
    {
        global $wpdb;
        $brands_table = $wpdb->prefix . DATAFLAIR_BRANDS_TABLE_NAME;

        $wanted_ids = [];
        $wanted_slugs = [];
        $wanted_names = [];
        foreach ($items as $item) {
            $brand = isset($item['brand']) && is_array($item['brand']) ? $item['brand'] : [];
            if (!empty($brand['api_brand_id'])) {
                $wanted_ids[(int) $brand['api_brand_id']] = true;
            }
            if (!empty($brand['id'])) {
                $wanted_ids[(int) $brand['id']] = true;
            }
            if (!empty($brand['slug'])) {
                $wanted_slugs[(string) $brand['slug']] = true;
            }
            if (!empty($brand['name'])) {
                $wanted_names[(string) $brand['name']] = true;
            }
        }

        $by_id = [];
        $by_slug = [];
        $by_name = [];
        $columns = 'api_brand_id, slug, name, local_logo_url, cached_review_post_id, review_url_override';

        if (!empty($wanted_ids)) {
            $id_list = array_keys($wanted_ids);
            // findActiveByApiBrandIds excludes is_disabled=1 rows so disabled
            // brands are silently dropped from the front-end render path.
            foreach ($this->brandsRepo->findActiveByApiBrandIds($id_list) as $api_id => $row_array) {
                $row = (object) $row_array;
                $by_id[(int) $api_id] = $row;
                if (!empty($row->slug) && !isset($by_slug[(string) $row->slug])) {
                    $by_slug[(string) $row->slug] = $row;
                }
                if (!empty($row->name) && !isset($by_name[(string) $row->name])) {
                    $by_name[(string) $row->name] = $row;
                }
            }
        }

        $missing_slugs = array_diff_key($wanted_slugs, $by_slug);
        if (!empty($missing_slugs)) {
            $slug_list = array_keys($missing_slugs);
            $placeholders = implode(',', array_fill(0, count($slug_list), '%s'));
            $sql = $wpdb->prepare(
                "SELECT $columns FROM $brands_table WHERE slug IN ($placeholders)",
                $slug_list
            );
            foreach ((array) $wpdb->get_results($sql) as $row) {
                if (!empty($row->api_brand_id) && !isset($by_id[(int) $row->api_brand_id])) {
                    $by_id[(int) $row->api_brand_id] = $row;
                }
                if (!empty($row->slug)) {
                    $by_slug[(string) $row->slug] = $row;
                }
                if (!empty($row->name) && !isset($by_name[(string) $row->name])) {
                    $by_name[(string) $row->name] = $row;
                }
            }
        }

        $missing_names = array_diff_key($wanted_names, $by_name);
        if (!empty($missing_names)) {
            $name_list = array_keys($missing_names);
            $placeholders = implode(',', array_fill(0, count($name_list), '%s'));
            $sql = $wpdb->prepare(
                "SELECT $columns FROM $brands_table WHERE name IN ($placeholders)",
                $name_list
            );
            foreach ((array) $wpdb->get_results($sql) as $row) {
                if (!empty($row->api_brand_id) && !isset($by_id[(int) $row->api_brand_id])) {
                    $by_id[(int) $row->api_brand_id] = $row;
                }
                if (!empty($row->slug) && !isset($by_slug[(string) $row->slug])) {
                    $by_slug[(string) $row->slug] = $row;
                }
                if (!empty($row->name)) {
                    $by_name[(string) $row->name] = $row;
                }
            }
        }

        return ['ids' => $by_id, 'slugs' => $by_slug, 'names' => $by_name];
    }
}
