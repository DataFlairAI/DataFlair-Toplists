<?php
/**
 * Phase 9.5 — WPPB-style i18n registrar.
 *
 * Adds `load_plugin_textdomain` on the `init` hook. Previously missing
 * entirely from the plugin — the Text Domain header was declared but
 * never actually loaded, which meant `__()` and `_e()` calls fell
 * through to English strings even when a .mo file was present.
 *
 * Single responsibility: load translations.
 */

declare(strict_types=1);

namespace DataFlair\Toplists;

final class I18n
{
    public const TEXT_DOMAIN = 'dataflair-toplists';

    public function __construct(
        private readonly string $pluginFile,
        private readonly string $languagesPath = 'languages'
    ) {
    }

    public function register(): void
    {
        if (!function_exists('add_action')) {
            return;
        }
        add_action('init', [$this, 'loadTextDomain']);
    }

    public function loadTextDomain(): void
    {
        if (!function_exists('load_plugin_textdomain')
            || !function_exists('plugin_basename')
        ) {
            return;
        }

        load_plugin_textdomain(
            self::TEXT_DOMAIN,
            false,
            dirname(plugin_basename($this->pluginFile)) . '/' . $this->languagesPath
        );
    }
}
