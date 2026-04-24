<?php
declare(strict_types=1);

namespace DataFlair\Toplists\Frontend\Render;

use DataFlair\Toplists\Frontend\Render\ViewModels\CasinoCardVM;

/**
 * Public contract for rendering an individual casino card.
 *
 * Downstream code (themes, plugins, custom integrations) can swap the default
 * implementation via the `dataflair_card_renderer` filter. Any replacement
 * must implement this interface or it will be rejected and the default kept.
 */
interface CardRendererInterface
{
    /**
     * Render a single casino card as HTML.
     *
     * Invariants preserved from every Phase 0A / 0B / 1 hotfix:
     *   - Read-only: no wp_remote_*, no wp_insert_post, no wp_handle_sideload,
     *     no update_option, no update_post_meta — render never writes.
     *   - Logo URL is read from the pre-computed `local_logo_url` column
     *     populated at sync time; no render-time download.
     *   - Review post lookup goes through `cached_review_post_id`; no auto-
     *     created draft reviews at page view.
     *   - Brand metadata resolution prefers the prefetched brand_meta_map
     *     (Phase 0B H7) and only falls back to per-card queries when the map
     *     is null (legacy callers).
     *   - `dataflair_review_url` filter fires on the resolved review URL.
     *
     * @return string Rendered HTML for one casino card.
     */
    public function render(CasinoCardVM $vm): string;
}
