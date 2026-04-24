<?php
/**
 * Phase 7 — BlockRegistrar
 *
 * Single responsibility: own the `register_block_type` call + the
 * editor-assets hook for the `dataflair-toplists/toplist` block.
 *
 * Resolution order for the block metadata file:
 *   1. `build/block.json` (compiled bundle — production)
 *   2. `src/block.json`   (source tree — local dev fallback)
 *
 * Both remain supported; if neither exists the registrar silently no-ops so
 * that stripped-down builds (e.g. ancient WP without block support) keep
 * booting.
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Block;

final class BlockRegistrar
{
    public function __construct(
        private ToplistBlock $block,
        private EditorAssets $editorAssets,
        private string $pluginDir,
        private string $version
    ) {}

    /**
     * Wire WP action hooks. Called from the god-class boot path.
     */
    public function register(): void
    {
        add_action('init', [$this, 'registerBlock']);
        add_action('enqueue_block_editor_assets', [$this->editorAssets, 'enqueue']);
    }

    /**
     * Direct entry point for the `init` action — exposed public so the hook
     * callback is testable in isolation.
     */
    public function registerBlock(): void
    {
        if (!function_exists('register_block_type')) {
            return;
        }

        $block_json = $this->resolveBlockJsonPath();
        if ($block_json === null) {
            return;
        }

        register_block_type($block_json, [
            'render_callback' => [$this->block, 'render'],
            'version'         => $this->version,
        ]);
    }

    private function resolveBlockJsonPath(): ?string
    {
        $built = $this->pluginDir . 'build/block.json';
        if (file_exists($built)) {
            return $built;
        }
        $source = $this->pluginDir . 'src/block.json';
        if (file_exists($source)) {
            return $source;
        }
        return null;
    }
}
