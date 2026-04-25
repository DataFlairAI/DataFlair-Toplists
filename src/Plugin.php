<?php
/**
 * Phase 8 / 9.5 — Canonical plugin bootstrap.
 *
 * `DataFlair\Toplists\Plugin::boot()` is the public entry point. It is
 * idempotent: repeat calls return the same `Plugin` instance and do not
 * re-register hooks.
 *
 * Phase 8 (v2.0.0) established this seam; Phase 9.5 (v2.1.1) wired in
 * the WPPB-style decoupled registrars so the entry point now owns the
 * plugin-info filter, GitHub auto-updates, i18n, and schema-migration
 * hooks. The legacy `DataFlair_Toplists` god-class continues to own the
 * remaining runtime hook registrations until v3.0.0 when it is removed
 * entirely.
 *
 * Services are resolved through {@see Container}; see `buildContainer()`
 * for the wiring.
 */

declare(strict_types=1);

namespace DataFlair\Toplists;

use DataFlair\Toplists\Admin\PluginInfoFilter;
use DataFlair\Toplists\Database\SchemaMigrator;
use DataFlair\Toplists\Frontend\Assets\AlpineDeferAttribute;
use DataFlair\Toplists\Frontend\Assets\AlpineJsEnqueuer;
use DataFlair\Toplists\Frontend\Assets\PromoCopyScript;
use DataFlair\Toplists\Frontend\Assets\StylesEnqueuer;
use DataFlair\Toplists\Frontend\Assets\WidgetShortcodeDetector;
use DataFlair\Toplists\Frontend\Shortcode\ShortcodeRegistrar;
use DataFlair\Toplists\UpdateChecker\GithubUpdateChecker;

final class Plugin
{
    private static ?self $instance = null;

    private bool $booted = false;

    private Container $container;

    private \DataFlair_Toplists $legacy;

    /**
     * Absolute path to the plugin's main file. Injected at boot so
     * nothing inside `src/` needs to compute it from `dirname(__FILE__)`
     * and hope the directory layout never changes.
     */
    private static ?string $pluginFile = null;

    public static function boot(?string $pluginFile = null): self
    {
        if ($pluginFile !== null) {
            self::$pluginFile = $pluginFile;
        }
        if (self::$instance === null) {
            self::$instance = new self();
        }
        self::$instance->registerHooks();
        return self::$instance;
    }

    public static function instance(): ?self
    {
        return self::$instance;
    }

    /**
     * Reset the static singleton. Test-only seam.
     */
    public static function resetForTests(): void
    {
        self::$instance   = null;
        self::$pluginFile = null;
    }

    public static function pluginFile(): string
    {
        if (self::$pluginFile !== null) {
            return self::$pluginFile;
        }
        // Fallback for legacy bootstraps that skipped the $pluginFile
        // argument — compute from DATAFLAIR_PLUGIN_DIR.
        if (defined('DATAFLAIR_PLUGIN_DIR')) {
            return DATAFLAIR_PLUGIN_DIR . 'dataflair-toplists.php';
        }
        return '';
    }

    private function __construct()
    {
        $this->container = $this->buildContainer();
        // The god-class still owns runtime hook dispatch (shortcode,
        // REST, block, admin). Plugin::boot() bootstraps it so existing
        // behaviour is byte-identical to v2.1.0.
        $this->legacy = \DataFlair_Toplists::get_instance();
    }

    public function container(): Container
    {
        return $this->container;
    }

    /**
     * Register the hooks owned by Plugin::boot(). Phase 9.5 moved four
     * responsibilities out of the god-class and into dedicated
     * registrars:
     *
     *   - Plugin-info filter (View details popup)
     *   - Text-domain loading
     *   - GitHub auto-update checker
     *   - Schema migration on plugins_loaded
     *
     * Each registrar hooks into WordPress on its own schedule; calling
     * `register()` on each one wires them once per request. The
     * `$booted` guard prevents double-registration if `boot()` fires
     * more than once.
     */
    private function registerHooks(): void
    {
        if ($this->booted) {
            return;
        }
        $this->booted = true;

        $pluginFile = self::pluginFile();

        (new PluginInfoFilter())->register();
        (new I18n($pluginFile))->register();
        (new GithubUpdateChecker($pluginFile))->register();
        (new SchemaMigrator())->register();

        // Phase 9.8 — Frontend asset registrars.
        (new StylesEnqueuer(
            DATAFLAIR_PLUGIN_DIR,
            DATAFLAIR_PLUGIN_URL,
            DATAFLAIR_VERSION
        ))->register();
        (new WidgetShortcodeDetector())->register();
        (new AlpineJsEnqueuer(new AlpineDeferAttribute()))->register();
        (new PromoCopyScript())->register();

        // Phase 9.12 — public shortcode + `/go/?campaign=…` redirect.
        // The shortcode callback is the legacy delegator so renderer
        // resolution stays lazy: `toplist_shortcode($atts)` calls
        // `toplist_shortcode_instance()` on first invocation, which is
        // *after* every other plugin has had a chance to register a
        // `dataflair_card_renderer` / `dataflair_table_renderer` filter.
        (new ShortcodeRegistrar([$this->legacy, 'toplist_shortcode']))->register();
        $this->legacy->campaign_redirect_handler()->register();
    }

    private function buildContainer(): Container
    {
        $c = new Container();

        $c->register('logger', static fn() => \DataFlair\Toplists\Logging\LoggerFactory::get());

        return $c;
    }
}
