<?php
/**
 * Phase 9.12 — public `[dataflair_toplist]` shortcode orchestrator.
 *
 * Pulled verbatim out of the god-class so the public shortcode entry point
 * stops touching `$wpdb` directly. Behaviour is byte-identical to v2.1.7:
 *
 *   - Same shortcode-attribute defaults and merge order.
 *   - Same `dataflair_render_started` / `dataflair_render_finished` action
 *     payloads (including the `elapsed_ms`, `layout`, `item_count` fields).
 *   - Same error strings on missing identifier, unknown toplist, and
 *     malformed JSON payload.
 *   - Same stale-banner threshold (3 days) and HTML markup for the cards
 *     wrapper, title, and notice.
 *
 * Invariants preserved from Phase 0A:
 *   - Read-only render. The orchestrator only calls repositories and
 *     renderers. No HTTP, no `wp_insert_post`, no `update_option` —
 *     `RenderIsReadOnlyTest` continues to enforce this.
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Frontend\Shortcode;

use DataFlair\Toplists\Database\ToplistsRepositoryInterface;
use DataFlair\Toplists\Frontend\Render\BrandMetaPrefetcher;
use DataFlair\Toplists\Frontend\Render\CardRendererInterface;
use DataFlair\Toplists\Frontend\Render\TableRendererInterface;
use DataFlair\Toplists\Frontend\Render\ViewModels\CasinoCardVM;
use DataFlair\Toplists\Frontend\Render\ViewModels\ToplistTableVM;
use DataFlair\Toplists\Geo\GeoFamilySelector;
use DataFlair\Toplists\Geo\GeoRenderGate;
use DataFlair\Toplists\Geo\VisitorGeoResolverInterface;

final class ToplistShortcode
{
    private const STALE_AFTER_SECONDS = 3 * 24 * 60 * 60;

    public function __construct(
        private readonly ToplistsRepositoryInterface $toplistsRepo,
        private readonly CardRendererInterface $cardRenderer,
        private readonly TableRendererInterface $tableRenderer,
        private readonly BrandMetaPrefetcher $brandMetaPrefetcher,
        private readonly VisitorGeoResolverInterface $visitorGeoResolver,
        private readonly GeoRenderGate $geoRenderGate,
        private readonly GeoFamilySelector $geoFamilySelector
    ) {
    }

    /**
     * @param array<string,mixed>|string $atts Raw shortcode attributes (WP may
     *     pass an empty string when no attributes are supplied).
     */
    public function render($atts): string
    {
        $render_t0 = microtime(true);

        $shortcode_defaults = [
            'id'     => '',
            'slug'   => '',
            'title'  => '',
            'limit'  => 0,
            'layout' => 'cards',
            'template' => '',
            'auto_geo' => 'false',
        ];

        $atts = wp_parse_args(is_array($atts) ? $atts : [], $shortcode_defaults);

        do_action('dataflair_render_started', [
            'toplist_id' => (int) ($atts['id'] ?? 0),
            'slug'       => (string) ($atts['slug'] ?? ''),
            'layout'     => (string) ($atts['layout'] ?? 'cards'),
        ]);

        $visitor_country = $this->visitorGeoResolver->resolve();
        $auto_geo        = filter_var($atts['auto_geo'] ?? false, FILTER_VALIDATE_BOOLEAN);

        if ($auto_geo && !empty($atts['template'])) {
            $family  = $this->toplistsRepo->findFamilyByTemplateId((int) $atts['template']);
            $toplist = $this->geoFamilySelector->select($family, $visitor_country);

            if (!$toplist) {
                $this->signalUncacheable();
                return '';
            }
        } elseif (!empty($atts['slug'])) {
            $toplist = $this->toplistsRepo->findBySlug((string) $atts['slug']);
        } elseif (!empty($atts['id'])) {
            $toplist = $this->toplistsRepo->findByApiToplistId((int) $atts['id']);
        } else {
            return '<p style="color: red;">DataFlair Error: Toplist ID or slug is required</p>';
        }

        if (!$toplist) {
            $identifier = !empty($atts['slug'])
                ? 'slug "' . esc_html((string) $atts['slug']) . '"'
                : 'ID ' . esc_html((string) $atts['id']);
            return '<p style="color: red;">DataFlair Error: Toplist ' . $identifier . ' not found. Please sync first.</p>';
        }

        $data = json_decode((string) ($toplist['data'] ?? ''), true);

        if (!isset($data['data']['items'])) {
            return '<p style="color: red;">DataFlair Error: Invalid toplist data</p>';
        }

        $this->signalUncacheable();

        if (!$this->geoRenderGate->shouldRender($data['data']['geo'] ?? null, $visitor_country)) {
            return '';
        }

        $resolved_toplist_id = (int) ($toplist['api_toplist_id'] ?? 0);

        $last_synced = strtotime((string) ($toplist['last_synced'] ?? ''));
        $is_stale    = (time() - (int) $last_synced) > self::STALE_AFTER_SECONDS;

        $items = $data['data']['items'];

        if ((int) $atts['limit'] > 0) {
            $items = array_slice($items, 0, (int) $atts['limit']);
        }

        $title = !empty($atts['title']) ? (string) $atts['title'] : (string) ($data['data']['name'] ?? '');

        $customizations  = $atts;
        $pros_cons_data  = isset($customizations['prosCons']) ? $customizations['prosCons'] : [];
        unset(
            $customizations['id'],
            $customizations['title'],
            $customizations['limit'],
            $customizations['layout'],
            $customizations['prosCons'],
            $customizations['template'],
            $customizations['auto_geo']
        );

        if (isset($atts['layout']) && $atts['layout'] === 'table') {
            $table_html = $this->tableRenderer->render(
                new ToplistTableVM(
                    (array) $items,
                    (string) $title,
                    (bool) $is_stale,
                    (int) $last_synced,
                    (array) $pros_cons_data
                )
            );

            do_action('dataflair_render_finished', [
                'toplist_id' => $resolved_toplist_id,
                'item_count' => count($items),
                'elapsed_ms' => (int) round((microtime(true) - $render_t0) * 1000),
                'layout'     => 'table',
            ]);

            return $table_html;
        }

        ob_start();
        ?>
        <div class="dataflair-toplist">
            <?php if ($is_stale): ?>
                <div class="dataflair-notice">
                    ⚠️ This data was last updated on <?php echo date('M d, Y', (int) $last_synced); ?>. Using cached version.
                </div>
            <?php endif; ?>

            <?php if (!empty($title)): ?>
            <h2 class="dataflair-title"><?php echo esc_html($title); ?></h2>
            <?php endif; ?>

                        <?php
            $brand_meta_map = $this->brandMetaPrefetcher->prefetch($items);
            foreach ($items as $item):
                echo $this->cardRenderer->render(
                    new CasinoCardVM(
                        (array) $item,
                        $resolved_toplist_id,
                        (array) $customizations,
                        (array) $pros_cons_data,
                        $brand_meta_map
                    )
                );
            endforeach; ?>
        </div>
        <?php
        $html = ob_get_clean();

        do_action('dataflair_render_finished', [
            'toplist_id' => $resolved_toplist_id,
            'item_count' => count($items),
            'elapsed_ms' => (int) round((microtime(true) - $render_t0) * 1000),
            'layout'     => 'cards',
        ]);

        return $html;
    }

    /**
     * Signal to WP-level full-page-cache plugins that this response must
     * not be cached and replayed to a different visitor's geo. Does not
     * cover edge/CDN caching in front of the whole site — that layer never
     * runs this code on a cache hit, so it needs separate infra-level
     * exclusion regardless of anything here.
     */
    private function signalUncacheable(): void
    {
        if (!defined('DONOTCACHEPAGE')) {
            define('DONOTCACHEPAGE', true);
        }

        if (function_exists('nocache_headers')) {
            nocache_headers();
        }
    }
}
