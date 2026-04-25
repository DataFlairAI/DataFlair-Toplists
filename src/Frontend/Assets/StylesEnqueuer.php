<?php
/**
 * Phase 9.8 — Frontend stylesheet enqueue.
 *
 * Hooks `wp_enqueue_scripts` and registers `dataflair-toplists` style
 * with a filemtime-based cache buster. Extracted from
 * `DataFlair_Toplists::enqueue_frontend_assets()`.
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Frontend\Assets;

final class StylesEnqueuer
{
    public function __construct(
        private readonly string $pluginDir,
        private readonly string $pluginUrl,
        private readonly string $fallbackVersion
    ) {
    }

    public function register(): void
    {
        add_action('wp_enqueue_scripts', [$this, 'enqueue']);
    }

    public function enqueue(): void
    {
        $cssPath = $this->pluginDir . 'assets/style.css';
        $version = file_exists($cssPath) ? (string) filemtime($cssPath) : $this->fallbackVersion;

        wp_enqueue_style(
            'dataflair-toplists',
            $this->pluginUrl . 'assets/style.css',
            [],
            $version
        );
    }
}
