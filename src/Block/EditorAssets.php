<?php
/**
 * Phase 7 — EditorAssets
 *
 * Single responsibility: enqueue the editor-only stylesheet used by the
 * Gutenberg block canvas (ServerSideRender preview).
 *
 * Must fire on `enqueue_block_assets` (not `enqueue_block_editor_assets`):
 * since WP 6.3 the editor content is iframed, and only assets from
 * `enqueue_block_assets` are injected into that iframe. Styles from
 * `enqueue_block_editor_assets` stay in the parent chrome and never reach
 * the casino-card markup — which is why an unstyled ribbon SVG balloons
 * to fill the block width.
 *
 * Guarded with `is_admin()` so the stylesheet is never loaded on the
 * public front-end (card visuals there come from the active theme).
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Block;

final class EditorAssets
{
    public function __construct(
        private string $pluginUrl,
        private string $version
    ) {}

    /**
     * Enqueue editor canvas styles when running in wp-admin.
     *
     * @return void
     */
    public function enqueue(): void
    {
        // Front-end must not load editor.css — theme style.css owns card look there.
        if (!is_admin()) {
            return;
        }

        wp_enqueue_style(
            'dataflair-toplist-editor',
            $this->pluginUrl . 'assets/editor.css',
            [],
            $this->version
        );
    }
}
