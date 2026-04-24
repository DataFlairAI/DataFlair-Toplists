<?php
declare(strict_types=1);

namespace DataFlair\Toplists\Frontend\Render;

use DataFlair\Toplists\Database\BrandsRepositoryInterface;
use DataFlair\Toplists\Frontend\Render\ViewModels\CasinoCardVM;
use DataFlair\Toplists\Logging\LoggerInterface;

/**
 * Default casino-card renderer.
 *
 * Phase 4 extraction of the god-class `render_casino_card()` primary path.
 * Renders via the shared `views/frontend/casino-card.php` template. The
 * read-only invariants from Phase 0A / 0B / 1 are preserved byte-for-byte
 * (no wp_remote_*, no wp_insert_post, no wp_handle_sideload, no
 * update_option, no update_post_meta — render never writes).
 *
 * Dependencies are constructor-injected so the class is trivially swappable
 * via the `dataflair_card_renderer` filter and trivially mockable in tests.
 */
final class CardRenderer implements CardRendererInterface
{
    use ProsConsResolver;

    public function __construct(
        private readonly BrandsRepositoryInterface $brandsRepo,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Render one casino card as HTML.
     *
     * Primary path only: the template-include path that has existed since
     * Phase 0A. The pre-Phase-0A fallback that ran 18 $wpdb->prepare calls
     * and sideloaded logos at render time is gone — it was unreachable in
     * practice (template always exists on plugin install) and violates the
     * Phase 0A read-only contract.
     */
    public function render(CasinoCardVM $vm): string
    {
        // Make template variables available. Template include is the public
        // seam this method preserves.
        $item = $vm->item;
        $toplist_id = $vm->toplistId;
        $customizations = $vm->customizations;
        $pros_cons_data = $vm->prosConsData;

        // Phase 0B H7: resolve brand metadata preferring the prefetched
        // map; only fall back to per-card queries when the caller is
        // legacy (null map).
        $brand = isset($item['brand']) && is_array($item['brand']) ? $item['brand'] : [];

        $precomputed_local_logo_url = null;
        $precomputed_review_post_id = null;
        $override = null;

        $meta_row = null;
        if (is_array($vm->brandMetaMap)) {
            $meta_row = $this->lookupBrandMetaFromMap($brand, $vm->brandMetaMap);
        }

        if ($meta_row !== null) {
            $precomputed_local_logo_url = $meta_row->local_logo_url ?? null;
            $precomputed_review_post_id = !empty($meta_row->cached_review_post_id)
                ? (int) $meta_row->cached_review_post_id
                : null;
            $override = $meta_row->review_url_override ?? null;
        } elseif ($vm->brandMetaMap === null) {
            // Legacy fallback: no prefetch map from caller. Delegate to
            // the repository so Phase 2 invariants hold (batched lookup,
            // prepared statements, no raw $wpdb in this layer).
            [$precomputed_local_logo_url, $precomputed_review_post_id, $override]
                = $this->resolveBrandMetaFallback($brand);
        }

        if (!empty($precomputed_local_logo_url)) {
            $brand['local_logo_url'] = $precomputed_local_logo_url;
        }

        // Review URL cascade. Matches pre-extraction semantics:
        //   override -> published CPT permalink -> /reviews/{slug}/
        $review_url = null;
        $dataflair_review_url_is_admin_override = false;
        $dataflair_review_cpt_is_published = false;

        if (!empty($override)) {
            $review_url = esc_url((string) $override);
            $dataflair_review_url_is_admin_override = true;
        }

        if (empty($review_url)
            && $precomputed_review_post_id
            && get_post_status($precomputed_review_post_id) === 'publish'
        ) {
            $review_url = get_permalink($precomputed_review_post_id);
            $dataflair_review_cpt_is_published = true;
        }

        if (empty($review_url)) {
            $brand_slug = !empty($brand['slug'])
                ? $brand['slug']
                : sanitize_title((string) ($brand['name'] ?? ''));
            $review_url = home_url('/reviews/' . $brand_slug . '/');
        }

        $review_url = apply_filters('dataflair_review_url', $review_url, $brand, $item);

        // Write brand back into $item so the template sees the enriched copy.
        $item['brand'] = $brand;

        // Ensure ProductTypeLabels is loaded; template references it directly.
        require_once DATAFLAIR_PLUGIN_DIR . 'includes/ProductTypeLabels.php';

        ob_start();
        include DATAFLAIR_PLUGIN_DIR . 'views/frontend/casino-card.php';
        return (string) ob_get_clean();
    }

    /**
     * Resolve a brand row from the prefetched map using the same cascade
     * order the legacy per-card queries used (api_brand_id -> id -> slug
     * -> name).
     *
     * @param array<string,mixed> $brand
     * @param array{ids:array<int,object>,slugs:array<string,object>,names:array<string,object>} $map
     */
    private function lookupBrandMetaFromMap(array $brand, array $map): ?object
    {
        if (!empty($brand['api_brand_id']) && isset($map['ids'][(int) $brand['api_brand_id']])) {
            return $map['ids'][(int) $brand['api_brand_id']];
        }
        if (!empty($brand['id']) && isset($map['ids'][(int) $brand['id']])) {
            return $map['ids'][(int) $brand['id']];
        }
        if (!empty($brand['slug']) && isset($map['slugs'][(string) $brand['slug']])) {
            return $map['slugs'][(string) $brand['slug']];
        }
        if (!empty($brand['name']) && isset($map['names'][(string) $brand['name']])) {
            return $map['names'][(string) $brand['name']];
        }
        return null;
    }

    /**
     * Legacy per-card fallback — invoked only when the caller passes a
     * null brand_meta_map (i.e. never from the default TableRenderer or
     * shortcode; only from downstream callers that skipped prefetch).
     *
     * Delegates to BrandsRepository so the repository contract owns the
     * SQL shape.
     *
     * @param array<string,mixed> $brand
     * @return array{0: ?string, 1: ?int, 2: ?string}
     */
    private function resolveBrandMetaFallback(array $brand): array
    {
        $precomputed_local_logo_url = null;
        $precomputed_review_post_id = null;
        $override = null;

        $api_brand_id = !empty($brand['api_brand_id']) ? (int) $brand['api_brand_id'] : 0;
        $brand_id     = !empty($brand['id']) ? (int) $brand['id'] : 0;
        $slug         = !empty($brand['slug']) ? (string) $brand['slug'] : '';
        $name         = !empty($brand['name']) ? (string) $brand['name'] : '';

        if ($api_brand_id > 0) {
            $row = $this->brandsRepo->findByApiBrandId($api_brand_id);
            if ($row) {
                $precomputed_local_logo_url = $row['local_logo_url'] ?? null;
                if (!empty($row['cached_review_post_id'])) {
                    $precomputed_review_post_id = (int) $row['cached_review_post_id'];
                }
                if (!empty($row['review_url_override'])) {
                    $override = (string) $row['review_url_override'];
                }
            }
        }

        if (empty($override) && $brand_id > 0 && $brand_id !== $api_brand_id) {
            $row = $this->brandsRepo->findByApiBrandId($brand_id);
            if ($row && !empty($row['review_url_override'])) {
                $override = (string) $row['review_url_override'];
            }
        }

        if (empty($override) && $slug !== '') {
            $row = $this->brandsRepo->findBySlug($slug);
            if ($row && !empty($row['review_url_override'])) {
                $override = (string) $row['review_url_override'];
            }
        }

        if (empty($override) && $name !== '') {
            $row = $this->brandsRepo->findByName($name);
            if ($row && !empty($row['review_url_override'])) {
                $override = (string) $row['review_url_override'];
            }
        }

        return [$precomputed_local_logo_url, $precomputed_review_post_id, $override];
    }
}
