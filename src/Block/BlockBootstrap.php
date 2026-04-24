<?php
/**
 * Phase 7 — BlockBootstrap
 *
 * Single responsibility: assemble the block subgraph from the god-class in
 * one call. Mirrors the Rest\RestBootstrap pattern: instantiate controllers
 * + registrar + assets, return the registrar so the god-class can call
 * `->register()` on it.
 *
 * Keeping assembly here means the god-class delegator is a 3-line getter
 * and the Phase 8 shim can drop the entire wiring cleanly.
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Block;

final class BlockBootstrap
{
    public function __construct(
        private \Closure $shortcodeRenderer,
        private \Closure $optionReader,
        private string $pluginDir,
        private string $pluginUrl,
        private string $version
    ) {}

    public function boot(): BlockRegistrar
    {
        $toplistBlock = new ToplistBlock($this->shortcodeRenderer, $this->optionReader);
        $editorAssets = new EditorAssets($this->pluginUrl, $this->version);
        return new BlockRegistrar($toplistBlock, $editorAssets, $this->pluginDir, $this->version);
    }
}
