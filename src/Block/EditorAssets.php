<?php
/**
 * Phase 7 — EditorAssets
 *
 * Single responsibility: enqueue the editor-only stylesheet used by the
 * Gutenberg block inserter + editor canvas. Fires on the
 * `enqueue_block_editor_assets` hook.
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Block;

final class EditorAssets
{
    public function __construct(
        private string $pluginUrl,
        private string $version
    ) {}

    public function enqueue(): void
    {
        wp_enqueue_style(
            'dataflair-toplist-editor',
            $this->pluginUrl . 'assets/editor.css',
            [],
            $this->version
        );
    }
}
