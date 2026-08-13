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
        // WordPress auto-registers blocks from block.json. Instead of re-registering,
        // use a filter to add the render callback to the auto-registered block type.
        add_filter('register_block_type_args', [$this, 'addRenderCallback'], 10, 2);
        add_action('enqueue_block_editor_assets', [$this->editorAssets, 'enqueue']);
    }

    /**
     * Filter callback to add render_callback to the auto-registered block type.
     * WordPress auto-registers blocks from block.json, so we use this filter
     * to inject the render callback instead of re-registering.
     *
     * @param array  $args Block type arguments.
     * @param string $block_type Block type name.
     */
    public function addRenderCallback(array $args, string $block_type): array
    {
        if ($block_type === 'dataflair-toplists/toplist') {
            $args['render_callback'] = [$this->block, 'render'];
        }
        return $args;
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
