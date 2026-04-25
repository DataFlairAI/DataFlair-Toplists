<?php
/**
 * Plugin Name: DataFlair Toplists
 * Plugin URI: https://dataflair.ai
 * Description: Fetch and display casino toplists from DataFlair API
 * Version: 2.1.5
 * Requires at least: 6.3
 * Requires PHP: 8.1
 * Author: DataFlair
 * Author URI: https://dataflair.ai
 * License: GPL v2 or later
 * Text Domain: dataflair-toplists
 * Domain Path: /languages
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants (guarded so tests can pre-define them in their bootstrap)
if (!defined('DATAFLAIR_VERSION'))                          define('DATAFLAIR_VERSION', '2.1.5');
if (!defined('DATAFLAIR_PLUGIN_DIR'))                       define('DATAFLAIR_PLUGIN_DIR', plugin_dir_path(__FILE__));
if (!defined('DATAFLAIR_PLUGIN_URL'))                       define('DATAFLAIR_PLUGIN_URL', plugin_dir_url(__FILE__));
if (!defined('DATAFLAIR_TABLE_NAME'))                       define('DATAFLAIR_TABLE_NAME', 'dataflair_toplists');
if (!defined('DATAFLAIR_BRANDS_TABLE_NAME'))                define('DATAFLAIR_BRANDS_TABLE_NAME', 'dataflair_brands');
if (!defined('DATAFLAIR_ALTERNATIVE_TOPLISTS_TABLE_NAME'))  define('DATAFLAIR_ALTERNATIVE_TOPLISTS_TABLE_NAME', 'dataflair_alternative_toplists');

// Load Composer autoloader
if (file_exists(DATAFLAIR_PLUGIN_DIR . 'vendor/autoload.php')) {
    require_once DATAFLAIR_PLUGIN_DIR . 'vendor/autoload.php';
}

// Phase 9.5 — WPPB-style lifecycle hooks. WordPress requires these to
// be registered at plugin-file load time (not from inside a class
// __construct), so they live here at the top. The god-class below
// keeps its `activate()` / `deactivate()` methods as thin delegators
// for any downstream code that may invoke them directly.
if (function_exists('register_activation_hook')) {
    register_activation_hook(__FILE__, ['\\DataFlair\\Toplists\\Lifecycle\\Activator', 'activate']);
}
if (function_exists('register_deactivation_hook')) {
    register_deactivation_hook(__FILE__, ['\\DataFlair\\Toplists\\Lifecycle\\Deactivator', 'deactivate']);
}

/**
 * Main DataFlair Plugin Class.
 *
 * @deprecated 2.0.0 Use {@see \DataFlair\Toplists\Plugin::boot()} as the
 *   canonical bootstrap entry point.
 *
 *   Status as of v2.1.0: this god-class continues to function as a
 *   strangler-fig shim — it still owns the WordPress hook registrations
 *   and still delegates to the extracted collaborators in `src/` for the
 *   concerns Phases 2–8 fully extracted (repositories, HTTP client, sync
 *   services, rendering, admin, REST, block). The remaining ~80 methods
 *   (shortcode, schema upgrades, private DB helpers) are scheduled for
 *   incremental extraction during the v2.1.x line; after they land, the
 *   class symbol itself will be removed in v3.0.0 and calling
 *   `get_instance()` will throw `BadMethodCallException`.
 *
 *   v2.1.0 flips strict-deprecation warnings to **default-on** — any
 *   downstream call to `get_instance()` from outside `DATAFLAIR_PLUGIN_DIR`
 *   now emits `E_USER_DEPRECATED` once per unique caller file/line per
 *   request. Sites that need a quieter migration window can silence with
 *   `add_filter('dataflair_strict_deprecation', '__return_false');`.
 *
 * Migration path for downstream consumers:
 *
 *   // Legacy (still works in 2.0.x, emits E_USER_DEPRECATED in strict mode):
 *   $legacy = DataFlair_Toplists::get_instance();
 *
 *   // Canonical (v2.0.0+):
 *   $plugin = \DataFlair\Toplists\Plugin::boot();
 *   $logger = $plugin->container()->get('logger');
 *
 * Strict-mode deprecation notices are opt-in via
 * `add_filter('dataflair_strict_deprecation', '__return_true');` — off by
 * default to avoid spamming `error_log` on sites that haven't migrated yet.
 *
 * @see \DataFlair\Toplists\Plugin
 * @see \DataFlair\Toplists\Container
 * @see UPGRADING.md
 */
class DataFlair_Toplists {

    private static $instance = null;

    /**
     * Phase 2 — extracted collaborators. Lazy-instantiated via getter so
     * construction stays side-effect-free and the plugin boots identically
     * to v1.11.2 for existing callers. Every new-class in src/ is accessed
     * exclusively through its getter below; never read the property directly.
     *
     * @var \DataFlair\Toplists\Http\HttpClientInterface|null
     */
    private $api_client = null;

    /** @var \DataFlair\Toplists\Http\LogoDownloaderInterface|null */
    private $logo_downloader = null;

    /** @var \DataFlair\Toplists\Database\BrandsRepositoryInterface|null */
    private $brands_repo = null;

    /** @var \DataFlair\Toplists\Database\ToplistsRepositoryInterface|null */
    private $toplists_repo = null;

    /** @var \DataFlair\Toplists\Database\AlternativesRepositoryInterface|null */
    private $alternatives_repo = null;

    /**
     * Phase 3 — sync services. Lazy-instantiated. See src/Sync/*.
     *
     * @var \DataFlair\Toplists\Sync\ToplistSyncServiceInterface|null
     */
    private $toplist_sync_service = null;

    /** @var \DataFlair\Toplists\Sync\BrandSyncServiceInterface|null */
    private $brand_sync_service = null;

    /** @var \DataFlair\Toplists\Sync\AlternativesSyncServiceInterface|null */
    private $alternatives_sync_service = null;

    /**
     * Phase 4 — renderers. Lazy-instantiated. See src/Frontend/Render/*.
     *
     * @var \DataFlair\Toplists\Frontend\Render\CardRendererInterface|null
     */
    private $card_renderer = null;

    /** @var \DataFlair\Toplists\Frontend\Render\TableRendererInterface|null */
    private $table_renderer = null;

    /**
     * Phase 5 — admin bootstrap. Wires the AjaxRouter + handlers and the
     * asset registrar from one seam. Lazy-instantiated; see
     * src/Admin/AdminBootstrap.php.
     *
     * @var \DataFlair\Toplists\Admin\AdminBootstrap|null
     */
    private $admin_bootstrap = null;

    /**
     * Phase 6 — REST bootstrap. Wires the RestRouter + controllers from one
     * seam. Lazy-instantiated on rest_api_init; see src/Rest/RestBootstrap.php.
     *
     * @var \DataFlair\Toplists\Rest\RestBootstrap|null
     */
    private $rest_bootstrap = null;

    /**
     * Phase 7 — Block bootstrap. Wires the BlockRegistrar + ToplistBlock +
     * EditorAssets from one seam. Lazy-instantiated on init; see
     * src/Block/BlockBootstrap.php.
     *
     * @var \DataFlair\Toplists\Block\BlockBootstrap|null
     */
    private $block_bootstrap = null;

    /**
     * Phase 9.6 — extracted admin page classes. Both pages take closures for
     * the still-private god-class helpers (`get_api_base_url`,
     * `format_last_sync_label`, `collect_distinct_csv_values`) so they never
     * import the legacy class symbol. Lazy so the ~2,000 lines of HTML
     * builders don't load on every request — only when the admin pages
     * actually render.
     *
     * @var \DataFlair\Toplists\Admin\Pages\SettingsPage|null
     */
    private $settings_page_obj = null;

    /** @var \DataFlair\Toplists\Admin\Pages\BrandsPage|null */
    private $brands_page_obj = null;

    /**
     * Phase 9.9 — extracted review-CPT and brand-meta helpers. Lazy.
     *
     * @var \DataFlair\Toplists\Frontend\Content\ReviewPostFinder|null
     */
    private $review_post_finder = null;

    /** @var \DataFlair\Toplists\Frontend\Content\ReviewPostManager|null */
    private $review_post_manager = null;

    /** @var \DataFlair\Toplists\Frontend\Content\ReviewPostBatchFinder|null */
    private $review_post_batch_finder = null;

    /** @var \DataFlair\Toplists\Frontend\Render\BrandMetaPrefetcher|null */
    private $brand_meta_prefetcher = null;

    /** @var \DataFlair\Toplists\Frontend\Render\BrandMetaLookup|null */
    private $brand_meta_lookup = null;

    /** @var \DataFlair\Toplists\Frontend\Render\SyncLabelFormatter|null */
    private $sync_label_formatter = null;

    /**
     * Legacy singleton accessor. Continues to function as a strangler-fig
     * shim through the v2.x line because the god-class still owns hook
     * registrations for the methods Phases 2–8 did not fully extract.
     *
     * In v2.1.0 strict deprecation flips to ON by default. Sites that have
     * not yet migrated should silence the notices by returning false from
     * `dataflair_strict_deprecation` while they port their call sites to
     * `\DataFlair\Toplists\Plugin::boot()`.
     *
     * Notices are emitted at most once per request per caller file/line
     * via a static guard — the god-class still calls `get_instance()`
     * internally during hook dispatch, and firing on every such call
     * would drown `error_log`.
     *
     * @deprecated 2.0.0 Use `\DataFlair\Toplists\Plugin::boot()` instead.
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }

        // v2.1.0 — strict deprecation is now default-ON. Opt out with
        // `add_filter('dataflair_strict_deprecation', '__return_false');`.
        $strict = function_exists('apply_filters')
            ? apply_filters('dataflair_strict_deprecation', true)
            : true;

        if ($strict) {
            self::emitDeprecationOncePerCaller();
        }

        return self::$instance;
    }

    /**
     * Emits `E_USER_DEPRECATED` at most once per unique `file:line` caller
     * per request. Internal god-class hook dispatch re-enters get_instance()
     * dozens of times per request — the caller guard keeps the notice
     * signal, not noise.
     */
    private static function emitDeprecationOncePerCaller(): void
    {
        if (!function_exists('_deprecated_function')) {
            return;
        }

        static $seen = [];

        $trace  = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3);
        $caller = $trace[2] ?? $trace[1] ?? null;
        $file   = $caller['file'] ?? 'unknown';
        $line   = $caller['line'] ?? 0;
        $key    = $file . ':' . $line;

        if (isset($seen[$key])) {
            return;
        }
        $seen[$key] = true;

        // Filter out internal callers — callers inside this plugin file and
        // callers originating from extracted src/ classes legitimately go
        // through this singleton during the strangler-fig transition. We
        // only want to nag downstream consumers.
        $plugin_dir = defined('DATAFLAIR_PLUGIN_DIR') ? DATAFLAIR_PLUGIN_DIR : '';
        if ($plugin_dir !== '' && str_starts_with($file, $plugin_dir)) {
            return;
        }

        _deprecated_function(
            'DataFlair_Toplists::get_instance',
            '2.0.0',
            '\\DataFlair\\Toplists\\Plugin::boot()'
        );
    }

    private function __construct() {
        $this->init_hooks();
    }

    /**
     * Lazy accessor for the Phase 2 HTTP client.
     * Filterable via `dataflair_api_client` so test harnesses and downstream
     * consumers can inject a fake. The filter MUST return an instance of
     * {@see \DataFlair\Toplists\Http\HttpClientInterface}; other values are
     * rejected and the default `ApiClient` is used.
     *
     * @return \DataFlair\Toplists\Http\HttpClientInterface
     */
    private function api_client() {
        if ($this->api_client instanceof \DataFlair\Toplists\Http\HttpClientInterface) {
            return $this->api_client;
        }
        $default = new \DataFlair\Toplists\Http\ApiClient();
        $maybe   = function_exists('apply_filters')
            ? apply_filters('dataflair_api_client', $default)
            : $default;
        $this->api_client = ($maybe instanceof \DataFlair\Toplists\Http\HttpClientInterface)
            ? $maybe
            : $default;
        return $this->api_client;
    }

    /**
     * Lazy accessor for the Phase 2 logo downloader.
     * Filterable via `dataflair_logo_downloader`.
     *
     * @return \DataFlair\Toplists\Http\LogoDownloaderInterface
     */
    private function logo_downloader() {
        if ($this->logo_downloader instanceof \DataFlair\Toplists\Http\LogoDownloaderInterface) {
            return $this->logo_downloader;
        }
        $default = new \DataFlair\Toplists\Http\LogoDownloader();
        $maybe   = function_exists('apply_filters')
            ? apply_filters('dataflair_logo_downloader', $default)
            : $default;
        $this->logo_downloader = ($maybe instanceof \DataFlair\Toplists\Http\LogoDownloaderInterface)
            ? $maybe
            : $default;
        return $this->logo_downloader;
    }

    /**
     * @return \DataFlair\Toplists\Database\BrandsRepositoryInterface
     */
    private function brands_repo() {
        if ($this->brands_repo instanceof \DataFlair\Toplists\Database\BrandsRepositoryInterface) {
            return $this->brands_repo;
        }
        $default = new \DataFlair\Toplists\Database\BrandsRepository();
        $maybe   = function_exists('apply_filters')
            ? apply_filters('dataflair_brands_repository', $default)
            : $default;
        $this->brands_repo = ($maybe instanceof \DataFlair\Toplists\Database\BrandsRepositoryInterface)
            ? $maybe
            : $default;
        return $this->brands_repo;
    }

    /**
     * @return \DataFlair\Toplists\Database\ToplistsRepositoryInterface
     */
    private function toplists_repo() {
        if ($this->toplists_repo instanceof \DataFlair\Toplists\Database\ToplistsRepositoryInterface) {
            return $this->toplists_repo;
        }
        $default = new \DataFlair\Toplists\Database\ToplistsRepository();
        $maybe   = function_exists('apply_filters')
            ? apply_filters('dataflair_toplists_repository', $default)
            : $default;
        $this->toplists_repo = ($maybe instanceof \DataFlair\Toplists\Database\ToplistsRepositoryInterface)
            ? $maybe
            : $default;
        return $this->toplists_repo;
    }

    /**
     * @return \DataFlair\Toplists\Database\AlternativesRepositoryInterface
     */
    private function alternatives_repo() {
        if ($this->alternatives_repo instanceof \DataFlair\Toplists\Database\AlternativesRepositoryInterface) {
            return $this->alternatives_repo;
        }
        $default = new \DataFlair\Toplists\Database\AlternativesRepository();
        $maybe   = function_exists('apply_filters')
            ? apply_filters('dataflair_alternatives_repository', $default)
            : $default;
        $this->alternatives_repo = ($maybe instanceof \DataFlair\Toplists\Database\AlternativesRepositoryInterface)
            ? $maybe
            : $default;
        return $this->alternatives_repo;
    }

    /**
     * Lazy accessor for the Phase 3 toplist sync service.
     * Filterable via `dataflair_toplist_sync_service`.
     *
     * @return \DataFlair\Toplists\Sync\ToplistSyncServiceInterface
     */
    private function toplist_sync_service() {
        if ($this->toplist_sync_service instanceof \DataFlair\Toplists\Sync\ToplistSyncServiceInterface) {
            return $this->toplist_sync_service;
        }

        $token   = trim((string) get_option('dataflair_api_token'));
        $logger  = \DataFlair\Toplists\Logging\LoggerFactory::get();
        $default = new \DataFlair\Toplists\Sync\ToplistSyncService(
            $this->api_client(),
            new \DataFlair\Toplists\Sync\GodClassToplistPersister($this),
            $logger,
            $token,
            $this->get_api_base_url(),
            \Closure::fromCallable([$this, 'build_detailed_api_error'])
        );
        $maybe = function_exists('apply_filters')
            ? apply_filters('dataflair_toplist_sync_service', $default)
            : $default;
        $this->toplist_sync_service = ($maybe instanceof \DataFlair\Toplists\Sync\ToplistSyncServiceInterface)
            ? $maybe
            : $default;
        return $this->toplist_sync_service;
    }

    /**
     * Lazy accessor for the Phase 3 brand sync service.
     * Filterable via `dataflair_brand_sync_service`.
     *
     * @return \DataFlair\Toplists\Sync\BrandSyncServiceInterface
     */
    private function brand_sync_service() {
        if ($this->brand_sync_service instanceof \DataFlair\Toplists\Sync\BrandSyncServiceInterface) {
            return $this->brand_sync_service;
        }

        $token   = trim((string) get_option('dataflair_api_token'));
        $logger  = \DataFlair\Toplists\Logging\LoggerFactory::get();
        $urlFn   = function ($page) { return $this->get_brands_api_url((int) $page); };
        $default = new \DataFlair\Toplists\Sync\BrandSyncService(
            $this->api_client(),
            $this->logo_downloader(),
            $this->brands_repo(),
            $logger,
            $token,
            $urlFn,
            \Closure::fromCallable([$this, 'build_detailed_api_error'])
        );
        $maybe = function_exists('apply_filters')
            ? apply_filters('dataflair_brand_sync_service', $default)
            : $default;
        $this->brand_sync_service = ($maybe instanceof \DataFlair\Toplists\Sync\BrandSyncServiceInterface)
            ? $maybe
            : $default;
        return $this->brand_sync_service;
    }

    /**
     * Lazy accessor for the Phase 3 alternatives sync service.
     * Filterable via `dataflair_alternatives_sync_service`.
     *
     * @return \DataFlair\Toplists\Sync\AlternativesSyncServiceInterface
     */
    private function alternatives_sync_service() {
        if ($this->alternatives_sync_service instanceof \DataFlair\Toplists\Sync\AlternativesSyncServiceInterface) {
            return $this->alternatives_sync_service;
        }
        $default = new \DataFlair\Toplists\Sync\AlternativesSyncService(
            $this->alternatives_repo(),
            \DataFlair\Toplists\Logging\LoggerFactory::get()
        );
        $maybe = function_exists('apply_filters')
            ? apply_filters('dataflair_alternatives_sync_service', $default)
            : $default;
        $this->alternatives_sync_service = ($maybe instanceof \DataFlair\Toplists\Sync\AlternativesSyncServiceInterface)
            ? $maybe
            : $default;
        return $this->alternatives_sync_service;
    }

    /**
     * Lazy accessor for the Phase 4 card renderer.
     * Filterable via `dataflair_card_renderer`. Filter returns that do not
     * implement {@see \DataFlair\Toplists\Frontend\Render\CardRendererInterface}
     * are rejected and the default is used.
     *
     * @return \DataFlair\Toplists\Frontend\Render\CardRendererInterface
     */
    private function card_renderer() {
        if ($this->card_renderer instanceof \DataFlair\Toplists\Frontend\Render\CardRendererInterface) {
            return $this->card_renderer;
        }
        $default = new \DataFlair\Toplists\Frontend\Render\CardRenderer(
            $this->brands_repo(),
            \DataFlair\Toplists\Logging\LoggerFactory::get()
        );
        $maybe = function_exists('apply_filters')
            ? apply_filters('dataflair_card_renderer', $default)
            : $default;
        $this->card_renderer = ($maybe instanceof \DataFlair\Toplists\Frontend\Render\CardRendererInterface)
            ? $maybe
            : $default;
        return $this->card_renderer;
    }

    /**
     * Lazy accessor for the Phase 4 table (accordion) renderer.
     * Filterable via `dataflair_table_renderer`.
     *
     * @return \DataFlair\Toplists\Frontend\Render\TableRendererInterface
     */
    private function table_renderer() {
        if ($this->table_renderer instanceof \DataFlair\Toplists\Frontend\Render\TableRendererInterface) {
            return $this->table_renderer;
        }
        $default = new \DataFlair\Toplists\Frontend\Render\TableRenderer();
        $maybe   = function_exists('apply_filters')
            ? apply_filters('dataflair_table_renderer', $default)
            : $default;
        $this->table_renderer = ($maybe instanceof \DataFlair\Toplists\Frontend\Render\TableRendererInterface)
            ? $maybe
            : $default;
        return $this->table_renderer;
    }

    /**
     * Lazy accessor for the Phase 5 admin bootstrap. Wires the AjaxRouter
     * with all 11 handlers and the admin asset registrar. The bootstrap is
     * a thin seam — it owns nothing beyond wiring, so there is no filter
     * hook for replacing it. Consumers that need to swap a single handler
     * should filter that handler's dependencies (repos, sync services).
     *
     * @return \DataFlair\Toplists\Admin\AdminBootstrap
     */
    private function admin_bootstrap() {
        if ($this->admin_bootstrap instanceof \DataFlair\Toplists\Admin\AdminBootstrap) {
            return $this->admin_bootstrap;
        }
        $this->admin_bootstrap = new \DataFlair\Toplists\Admin\AdminBootstrap(
            \DataFlair\Toplists\Logging\LoggerFactory::get(),
            $this->brands_repo(),
            $this->toplists_repo(),
            $this->alternatives_repo(),
            $this->api_client(),
            $this->toplist_sync_service(),
            $this->brand_sync_service(),
            \Closure::fromCallable([$this, 'get_api_base_url'])
        );
        return $this->admin_bootstrap;
    }

    /**
     * Phase 6 — lazy RestBootstrap getter. The bootstrap itself is lazy, so
     * controllers are not instantiated until a REST request actually hits.
     */
    private function rest_bootstrap() {
        if ($this->rest_bootstrap instanceof \DataFlair\Toplists\Rest\RestBootstrap) {
            return $this->rest_bootstrap;
        }
        $this->rest_bootstrap = new \DataFlair\Toplists\Rest\RestBootstrap(
            \DataFlair\Toplists\Logging\LoggerFactory::get(),
            $this->toplists_repo(),
            \Closure::fromCallable([$this, 'prefetch_brand_metas_for_items']),
            \Closure::fromCallable([$this, 'lookup_brand_meta_from_map'])
        );
        return $this->rest_bootstrap;
    }

    /**
     * Phase 7 — lazy BlockBootstrap getter. Wires the Gutenberg block
     * registrar + render callback + editor assets from a single seam.
     * Shortcode renderer is passed as a closure so the block stays
     * `$wpdb`-free and the god-class shortcode method can be swapped later
     * without touching the block.
     */
    private function block_bootstrap() {
        if ($this->block_bootstrap instanceof \DataFlair\Toplists\Block\BlockBootstrap) {
            return $this->block_bootstrap;
        }
        $this->block_bootstrap = new \DataFlair\Toplists\Block\BlockBootstrap(
            \Closure::fromCallable([$this, 'toplist_shortcode']),
            \Closure::fromCallable('get_option'),
            DATAFLAIR_PLUGIN_DIR,
            DATAFLAIR_PLUGIN_URL,
            DATAFLAIR_VERSION
        );
        return $this->block_bootstrap;
    }

    /**
     * Phase 9.6 — lazy SettingsPage getter. Closure injection keeps the
     * extracted page from referencing the legacy class symbol while still
     * letting it call `get_api_base_url()` and `format_last_sync_label()`,
     * both of which are still private on this class.
     */
    private function settings_page_obj() {
        if ($this->settings_page_obj instanceof \DataFlair\Toplists\Admin\Pages\SettingsPage) {
            return $this->settings_page_obj;
        }
        $this->settings_page_obj = new \DataFlair\Toplists\Admin\Pages\SettingsPage(
            \Closure::fromCallable([$this, 'get_api_base_url']),
            \Closure::fromCallable([$this, 'format_last_sync_label'])
        );
        return $this->settings_page_obj;
    }

    /**
     * Phase 9.6 — lazy BrandsPage getter. Same closure-injection pattern as
     * settings_page_obj(). `collect_distinct_csv_values` and
     * `format_last_sync_label` are still private here; the brands page
     * receives them via Closure::fromCallable.
     */
    private function brands_page_obj() {
        if ($this->brands_page_obj instanceof \DataFlair\Toplists\Admin\Pages\BrandsPage) {
            return $this->brands_page_obj;
        }
        $this->brands_page_obj = new \DataFlair\Toplists\Admin\Pages\BrandsPage(
            \Closure::fromCallable([$this, 'collect_distinct_csv_values']),
            \Closure::fromCallable([$this, 'format_last_sync_label'])
        );
        return $this->brands_page_obj;
    }

    /**
     * Phase 9.9 — lazy getters for the extracted review-CPT and
     * brand-meta helpers. The legacy private god-class methods become
     * one-line delegators to these instances.
     */
    private function review_post_finder() {
        if ($this->review_post_finder instanceof \DataFlair\Toplists\Frontend\Content\ReviewPostFinder) {
            return $this->review_post_finder;
        }
        $this->review_post_finder = new \DataFlair\Toplists\Frontend\Content\ReviewPostFinder();
        return $this->review_post_finder;
    }

    private function review_post_manager() {
        if ($this->review_post_manager instanceof \DataFlair\Toplists\Frontend\Content\ReviewPostManager) {
            return $this->review_post_manager;
        }
        $this->review_post_manager = new \DataFlair\Toplists\Frontend\Content\ReviewPostManager(
            $this->review_post_finder(),
            \DataFlair\Toplists\Logging\LoggerFactory::get()
        );
        return $this->review_post_manager;
    }

    private function review_post_batch_finder() {
        if ($this->review_post_batch_finder instanceof \DataFlair\Toplists\Frontend\Content\ReviewPostBatchFinder) {
            return $this->review_post_batch_finder;
        }
        $this->review_post_batch_finder = new \DataFlair\Toplists\Frontend\Content\ReviewPostBatchFinder(
            $this->brands_repo()
        );
        return $this->review_post_batch_finder;
    }

    private function brand_meta_prefetcher() {
        if ($this->brand_meta_prefetcher instanceof \DataFlair\Toplists\Frontend\Render\BrandMetaPrefetcher) {
            return $this->brand_meta_prefetcher;
        }
        $this->brand_meta_prefetcher = new \DataFlair\Toplists\Frontend\Render\BrandMetaPrefetcher(
            $this->brands_repo()
        );
        return $this->brand_meta_prefetcher;
    }

    private function brand_meta_lookup() {
        if ($this->brand_meta_lookup instanceof \DataFlair\Toplists\Frontend\Render\BrandMetaLookup) {
            return $this->brand_meta_lookup;
        }
        $this->brand_meta_lookup = new \DataFlair\Toplists\Frontend\Render\BrandMetaLookup();
        return $this->brand_meta_lookup;
    }

    private function sync_label_formatter() {
        if ($this->sync_label_formatter instanceof \DataFlair\Toplists\Frontend\Render\SyncLabelFormatter) {
            return $this->sync_label_formatter;
        }
        $this->sync_label_formatter = new \DataFlair\Toplists\Frontend\Render\SyncLabelFormatter();
        return $this->sync_label_formatter;
    }

    private function init_hooks() {
        // Phase 9.5: activation/deactivation are now registered at
        // plugin-file load time via the WPPB-style hooks at the top of
        // dataflair-toplists.php, routing directly to
        // \DataFlair\Toplists\Lifecycle\{Activator, Deactivator}.
        // Schema migration on plugins_loaded is owned by
        // \DataFlair\Toplists\Database\SchemaMigrator (wired from
        // Plugin::boot()). The god-class keeps its activate() /
        // deactivate() / check_database_upgrade() methods as thin
        // delegators so direct callers still work.

        // Phase 9.6 — admin menu, settings registration and the
        // plain-permalinks notice are now owned by their own registrars
        // under src/Admin. Each one hooks into WP on its own schedule;
        // the calls below register them once per request.
        (new \DataFlair\Toplists\Admin\MenuRegistrar(
            $this->settings_page_obj(),
            $this->brands_page_obj(),
            $this
        ))->register();
        (new \DataFlair\Toplists\Admin\SettingsRegistrar())->register();

        // Phase 5 — AJAX handlers live in src/Admin/Handlers and route through
        // DataFlair\Toplists\Admin\AjaxRouter. The router owns the centralised
        // nonce + capability checks; each handler receives the sanitised
        // request payload and returns a structured response array. The legacy
        // `ajax_*` methods on this class still exist (kept until Phase 8) so
        // external callers that invoked them directly continue to work.
        $this->admin_bootstrap()->boot();
        
        // Shortcode
        add_shortcode('dataflair_toplist', array($this, 'toplist_shortcode'));
        
        // Phase 7 — Gutenberg block registration + editor assets now live in
        // DataFlair\Toplists\Block\{BlockRegistrar, ToplistBlock, EditorAssets}.
        // BlockRegistrar::register() wires the `init` and
        // `enqueue_block_editor_assets` hooks in one call.
        $this->block_bootstrap()->boot()->register();

        // REST API for block editor
        add_action('rest_api_init', array($this, 'register_rest_routes'));

        // Redirect handler for /go/ campaign links
        add_action('template_redirect', array($this, 'handle_campaign_redirect'));

        // Cron registration was removed in v1.11.0 (Phase 0B H1). DataFlair
        // sync now runs only when an operator triggers it from the admin
        // Tools page or via WP-CLI. Legacy cron events are cleared once at
        // upgrade time by the `dataflair_cron_cleared_v1_11` gate in
        // upgrade_database().

        // Phase 5 — admin asset enqueue lives in
        // DataFlair\Toplists\Admin\Assets\AdminAssetsRegistrar.
        $this->admin_bootstrap()->registerAssets();

        // Phase 9.8 — frontend stylesheet, Alpine.js conditional enqueue,
        // promo-copy footer script, Alpine defer attribute, and widget
        // shortcode detection moved to dedicated registrars under
        // src/Frontend/Assets/. Plugin::registerHooks() wires them.

        // Phase 9.6 — plain-permalinks admin notice extracted to
        // \DataFlair\Toplists\Admin\Notices\PermalinkNotice.
        (new \DataFlair\Toplists\Admin\Notices\PermalinkNotice())->register();
    }

    /**
     * Plugin activation.
     *
     * Phase 9.5 — logic lives in
     * {@see \DataFlair\Toplists\Lifecycle\Activator::activate()}. This
     * delegator exists only for direct callers that may still invoke
     * the god-class instance method.
     *
     * @deprecated 2.1.1 Call `\DataFlair\Toplists\Lifecycle\Activator::activate()`.
     */
    public function activate() {
        \DataFlair\Toplists\Lifecycle\Activator::activate();
    }

    /**
     * Plugin deactivation.
     *
     * Phase 9.5 — logic lives in
     * {@see \DataFlair\Toplists\Lifecycle\Deactivator::deactivate()}.
     *
     * @deprecated 2.1.1 Call `\DataFlair\Toplists\Lifecycle\Deactivator::deactivate()`.
     */
    public function deactivate() {
        \DataFlair\Toplists\Lifecycle\Deactivator::deactivate();
    }

    /**
     * Check and upgrade database schema if needed.
     *
     * Phase 9.5 — logic lives in
     * {@see \DataFlair\Toplists\Database\SchemaMigrator::checkDatabaseUpgrade()}.
     * The hook registration that used to call this method moved to
     * `SchemaMigrator::register()` (wired from `Plugin::boot()`). This
     * delegator exists only for explicit callers.
     *
     * @deprecated 2.1.1 Use `\DataFlair\Toplists\Database\SchemaMigrator::checkDatabaseUpgrade()`.
     */
    public function check_database_upgrade() {
        (new \DataFlair\Toplists\Database\SchemaMigrator())->checkDatabaseUpgrade();
    }

    /**
     * Ensure the alternative-toplists table exists.
     *
     * Phase 9.5 — logic lives in
     * {@see \DataFlair\Toplists\Database\SchemaMigrator::ensureAlternativeToplistsTable()}.
     * Still called from internal AJAX handlers for alt-toplist CRUD,
     * hence preserved as a delegator (not deleted).
     */
    private function ensure_alternative_toplists_table() {
        (new \DataFlair\Toplists\Database\SchemaMigrator())->ensureAlternativeToplistsTable();
    }

    // NOTE: add_custom_cron_schedules() and ensure_cron_scheduled() were
    // removed in v1.11.0 (Phase 0B H1). Auto-sync cron is gone — sync now
    // runs only when an operator triggers it from the admin Tools page or
    // via WP-CLI. Legacy cron events are cleared once at upgrade time by
    // the `dataflair_cron_cleared_v1_11` gate in SchemaMigrator.

    /**
     * Tests page
     *
     * @codeCoverageIgnore
     */
    public function tests_page() {
        $test_file = isset($_GET['test']) ? sanitize_text_field($_GET['test']) : 'all';
        $tests_dir = DATAFLAIR_PLUGIN_DIR . 'tests/';
        
        ?>
        <div class="wrap">
            <h1>🧪 Dataflair Plugin Tests</h1>
            <p>Run tests to verify plugin functionality.</p>
            
            <div style="margin: 20px 0;">
                <a href="<?php echo admin_url('admin.php?page=dataflair-tests&test=all'); ?>" class="button button-primary">Run All Tests</a>
                <a href="<?php echo admin_url('admin.php?page=dataflair-tests&test=logo'); ?>" class="button">Logo Download Test</a>
                <a href="<?php echo admin_url('admin.php?page=dataflair-tests&test=logo-url'); ?>" class="button">Logo URL Structure Test</a>
                <a href="<?php echo admin_url('admin.php?page=dataflair-tests&test=brand'); ?>" class="button">Brand Data Test</a>
                <a href="<?php echo admin_url('admin.php?page=dataflair-tests&test=toplist'); ?>" class="button">Toplist Fetch Test</a>
                <a href="<?php echo admin_url('admin.php?page=dataflair-tests&test=toplist-render'); ?>" class="button">Toplist Render Test</a>
                <a href="<?php echo admin_url('admin.php?page=dataflair-tests&test=api-edge'); ?>" class="button">API Edge Cases Test</a>
                <a href="<?php echo admin_url('admin.php?page=dataflair-tests&test=cron'); ?>" class="button">Cron Jobs Test</a>
            </div>
            
            <hr>
            
            <?php
            if ($test_file === 'all') {
                include $tests_dir . 'run-all-tests.php';
            } elseif ($test_file === 'logo') {
                include $tests_dir . 'test-logo-download.php';
            } elseif ($test_file === 'logo-url') {
                include $tests_dir . 'test-logo-url.php';
            } elseif ($test_file === 'brand') {
                include $tests_dir . 'test-brand-data.php';
            } elseif ($test_file === 'toplist') {
                include $tests_dir . 'test-toplist-fetch.php';
            } elseif ($test_file === 'toplist-render') {
                include $tests_dir . 'test-toplist-render.php';
            } elseif ($test_file === 'api-edge') {
                include $tests_dir . 'test-api-edge-cases.php';
            } elseif ($test_file === 'cron') {
                include $tests_dir . 'test-cron.php';
            } else {
                echo '<p>Invalid test selected.</p>';
            }
            ?>
        </div>
        <?php
    }
    
    // NOTE: ajax_save_settings() moved to DataFlair\Toplists\Admin\Handlers\SaveSettingsHandler
    // in v2.1.3 (Phase 9.7). The handler is registered with AjaxRouter via AdminBootstrap.

    /**
     * Check if a URL points to a local development domain (no SSL).
     *
     * @param string $url The URL to check
     * @return bool True if local dev domain
     */
    private function is_local_url($url) {
        $parsed = parse_url($url);
        $host = isset($parsed['host']) ? $parsed['host'] : '';
        return (
            preg_match('/\.(test|local|localhost|invalid|example)$/i', $host) ||
            $host === 'localhost' ||
            $host === '127.0.0.1' ||
            $host === '::1'
        );
    }

    /**
     * Force HTTPS on a URL unless it's a local dev domain.
     * Production/staging servers redirect HTTP→HTTPS which strips Authorization headers.
     *
     * @param string $url The URL to upgrade
     * @return string The URL, with https:// if non-local
     */
    private function maybe_force_https($url) {
        if (!$this->is_local_url($url)) {
            $url = preg_replace('#^http://#i', 'https://', $url);
        }
        return $url;
    }

    /**
     * Get the brands API URL for the given page, respecting the selected API version.
     * Toplists always use v1 — only brands sync uses this helper.
     *
     * @param int $page Page number
     * @return string Full brands URL with page parameter
     */
    private function get_brands_api_url($page) {
        $version = get_option('dataflair_brands_api_version', 'v1');
        $base    = $this->get_api_base_url();  // e.g. .../api/v1
        if ($version === 'v2') {
            $base = preg_replace('#/api/v\d+$#', '/api/v2', $base);
        }
        return rtrim($base, '/') . '/brands?page=' . intval($page);
    }

    /**
     * Get API base URL - auto-detect from stored endpoints or use default
     *
     * @return string API base URL
     */
    private function get_api_base_url() {
        // First, try to get from stored option (manually set or auto-detected)
        $base_url = get_option('dataflair_api_base_url');
        if (!empty($base_url)) {
            $base_url = $this->maybe_force_https($base_url);
            // Safety: strip anything after /api/v1 (e.g. /api/v1/toplists → /api/v1)
            $base_url = preg_replace('#(/api/v\d+)/.*$#', '$1', $base_url);
            return rtrim($base_url, '/');
        }
        
        // Try to extract from stored endpoints
        $endpoints = get_option('dataflair_api_endpoints');
        if (!empty($endpoints)) {
            $endpoints_array = array_filter(array_map('trim', explode("\n", $endpoints)));
            if (!empty($endpoints_array)) {
                $first_endpoint = $endpoints_array[0];
                // Extract base URL from endpoint (e.g., https://tenant.dataflair.ai/api/v1/toplists/3)
                if (preg_match('#^(https?://[^/]+/api/v\d+)/#', $first_endpoint, $matches)) {
                    $base_url = $matches[1];
                    $base_url = $this->maybe_force_https($base_url);
                    // Store it for future use
                    update_option('dataflair_api_base_url', $base_url);
                    return rtrim($base_url, '/');
                }
            }
        }
        
        // Fallback to default
        return 'https://sigma.dataflair.ai/api/v1';
    }

    /**
     * Make an authenticated API GET request, handling both Bearer token and HTTP Basic Auth.
     *
     * @param string $url    The API endpoint URL
     * @param string $token  The Bearer token
     * @param int    $timeout Timeout in seconds
     * @return array|WP_Error The response
     */
    /**
     * GET a DataFlair API URL with retry + transient-failure handling.
     *
     * Phase 0B changes:
     *   - Default timeout lowered 30 → 12 s (H13). Shared hosts hit 30 s
     *     FastCGI limits; 12 s gives the caller time to bail cooperatively.
     *   - Response hard-capped at 15 MB via `limit_response_size` + stream-
     *     to-temp (H2). Oversized payloads return a WP_Error with code
     *     `dataflair_response_too_large` so callers can surface a clean
     *     structured error instead of OOMing on json_decode().
     *   - Optional WallClockBudget narrows the per-attempt timeout to
     *     whichever is smaller: the caller-supplied $timeout or budget
     *     remaining (H13). When budget is exhausted the call short-circuits
     *     with `dataflair_budget_exhausted`.
     *
     * @param string                                         $url
     * @param string                                         $token
     * @param int                                            $timeout       Seconds; default 12 (was 30 pre-0B).
     * @param int                                            $max_retries
     * @param \DataFlair\Toplists\Support\WallClockBudget|null $budget      Optional cooperative wall-clock budget.
     * @return array|\WP_Error                                              wp_remote_get-shaped array or WP_Error.
     */
    private function api_get($url, $token, $timeout = 12, $max_retries = 2, $budget = null) {
        // Phase 2 — delegate to the extracted HttpClientInterface. All
        // retry/backoff/size-cap/budget/telemetry logic now lives in
        // `DataFlair\Toplists\Http\ApiClient`. Return shape unchanged:
        // array|WP_Error, byte-identical to pre-Phase-2 callers.
        return $this->api_client()->get($url, $token, $timeout, $max_retries, $budget);
    }

    /**
     * Detect if WordPress is running inside a Docker container.
     *
     * @return bool
     */
    private function is_running_in_docker() {
        // Method 1: Check for .dockerenv file (most reliable)
        if (file_exists('/.dockerenv')) {
            return true;
        }
        // Method 2: Check cgroup (Linux containers)
        if (is_readable('/proc/1/cgroup')) {
            $cgroup = file_get_contents('/proc/1/cgroup');
            if (strpos($cgroup, 'docker') !== false || strpos($cgroup, 'kubepods') !== false) {
                return true;
            }
        }
        // Method 3: Check if host.docker.internal resolves (Docker Desktop for Mac/Windows)
        $resolved = gethostbyname('host.docker.internal');
        if ($resolved !== 'host.docker.internal') {
            return true;
        }
        return false;
    }

    /**
     * Build a detailed, human-readable error message for failed API responses.
     * Helps diagnose whether it's HTTP Basic Auth, Bearer token, server error, etc.
     *
     * @param int          $status_code     HTTP status code
     * @param string       $body            Response body
     * @param array|object $headers         Response headers
     * @param string       $url             The requested URL
     * @return string Detailed error message
     */
    private function build_detailed_api_error($status_code, $body, $headers, $url) {
        $parsed = parse_url($url);
        $host = isset($parsed['host']) ? $parsed['host'] : 'unknown';

        // Try to decode JSON body for structured error info
        $json = json_decode($body, true);
        $api_message = '';
        if (is_array($json) && isset($json['message'])) {
            $api_message = $json['message'];
        }

        // Check for WWW-Authenticate header — tells us if it's HTTP Basic Auth or Bearer
        $www_auth = '';
        if (is_object($headers) && isset($headers['www-authenticate'])) {
            $www_auth = $headers['www-authenticate'];
        } elseif (is_array($headers) && isset($headers['www-authenticate'])) {
            $www_auth = $headers['www-authenticate'];
        }

        // Check content-type to see if the response is HTML (web server error page) vs JSON (API error)
        $content_type = '';
        if (is_object($headers) && isset($headers['content-type'])) {
            $content_type = $headers['content-type'];
        } elseif (is_array($headers) && isset($headers['content-type'])) {
            $content_type = $headers['content-type'];
        }
        $is_html_response = (stripos($content_type, 'text/html') !== false);

        switch ($status_code) {
            case 401:
                // Distinguish between HTTP Basic Auth 401 and API Bearer 401
                if (stripos($www_auth, 'Basic') !== false) {
                    $has_http_auth = !empty(trim(get_option('dataflair_http_auth_user', '')));
                    if ($has_http_auth) {
                        return 'HTTP Basic Auth failed (401). Your staging username/password was rejected by the web server at ' . $host . '. '
                             . 'Check that the HTTP Auth Username and Password in plugin settings match your .htpasswd or nginx auth_basic credentials. '
                             . 'This is the web server blocking the request before it reaches the DataFlair API.';
                    } else {
                        return 'HTTP Basic Auth required (401). The server at ' . $host . ' requires HTTP Basic Authentication (e.g. .htpasswd). '
                             . 'This is common on staging environments. Go to DataFlair plugin settings and fill in the "HTTP Auth Username" and "HTTP Auth Password" fields. '
                             . 'These are your web server credentials — not your DataFlair API token.';
                    }
                } elseif (stripos($www_auth, 'Bearer') !== false || !empty($api_message)) {
                    return 'API authentication failed (401). The DataFlair API rejected your Bearer token. '
                         . 'API says: "' . ($api_message ?: 'Unauthenticated') . '". '
                         . 'Possible causes: (1) Token is expired or revoked — generate a new one in DataFlair > Configuration > API Credentials. '
                         . '(2) Token is an API Key (dfk_) instead of a Plugin Token (dfp_) — only dfp_ tokens work for this plugin. '
                         . '(3) Token was copy-pasted with extra spaces or line breaks — re-copy it carefully. '
                         . 'Token starts with: ' . substr(trim(get_option('dataflair_api_token', '')), 0, 10) . '...';
                } elseif ($is_html_response) {
                    return 'Authentication failed (401) — the server at ' . $host . ' returned an HTML page instead of a JSON API response. '
                         . 'This usually means the web server itself (nginx/Apache) is blocking the request before it reaches the DataFlair API. '
                         . 'Most likely cause: HTTP Basic Auth (.htpasswd) is enabled on staging. '
                         . 'Go to plugin settings and fill in the "HTTP Auth Username" and "HTTP Auth Password" fields.';
                } else {
                    return 'Authentication failed (401) at ' . $host . '. '
                         . 'Could not determine the specific cause. Response body: ' . substr($body, 0, 300) . '. '
                         . 'Check: (1) Is staging behind HTTP Basic Auth? Add credentials in plugin settings. '
                         . '(2) Is your dfp_ token valid and not expired? (3) Is the API Base URL correct?';
                }

            case 403:
                return 'Access forbidden (403). The server accepted your credentials but your token does not have permission to access this resource. '
                     . 'API says: "' . ($api_message ?: 'Forbidden') . '". '
                     . 'Check that your API credential in DataFlair has the correct permissions and is marked as active.';

            case 404:
                return 'Endpoint not found (404) at ' . $url . '. '
                     . 'This usually means the API Base URL is wrong or the route does not exist. '
                     . 'Expected format: https://tenant.dataflair.ai/api/v1. '
                     . 'Currently configured: ' . get_option('dataflair_api_base_url', '(not set)');

            case 419:
                return 'CSRF token mismatch (419). The API returned a Laravel session error. '
                     . 'This should not happen for API routes. Check that the API Base URL points to /api/v1 routes, not web routes.';

            case 429:
                return 'Rate limited (429). Too many requests to the DataFlair API. '
                     . 'API says: "' . ($api_message ?: 'Too Many Requests') . '". '
                     . 'Wait a few minutes and try again, or check your API credential rate limit settings.';

            case 500:
                return 'Server error (500). The DataFlair API encountered an internal error. '
                     . 'This is a server-side issue, not a plugin configuration problem. '
                     . 'API says: "' . ($api_message ?: substr($body, 0, 200)) . '". '
                     . 'Contact DataFlair support if this persists.';

            case 502:
            case 503:
            case 504:
                return 'Server unavailable (' . $status_code . '). The DataFlair API at ' . $host . ' is temporarily unavailable. '
                     . 'This could be a deployment in progress, server overload, or infrastructure issue. Try again in a few minutes.';

            default:
                return 'Unexpected HTTP ' . $status_code . ' from ' . $host . '. '
                     . ($api_message ? 'API says: "' . $api_message . '". ' : '')
                     . 'Response body: ' . substr($body, 0, 300);
        }
    }

    // NOTE: ajax_fetch_all_toplists() moved to DataFlair\Toplists\Admin\Handlers\FetchAllToplistsHandler
    // and ajax_sync_toplists_batch() moved to SyncToplistsBatchHandler in v2.1.3 (Phase 9.7).

    // NOTE: sync_toplists_page_per_id() moved to DataFlair\Toplists\Sync\ToplistSyncService
    // in v1.12.1 (Phase 3). The progressive-split fallback logic lives there now.
    //
    // NOTE: cron_sync_toplists(), cron_sync_brands(), sync_all_toplists(),
    // sync_all_brands() were removed in v1.11.0 (Phase 0B H1). Auto-sync is
    // gone — paginated batch sync from the admin Tools page (or WP-CLI) is
    // now the only way to refresh toplists and brands.

    /**
     * Clear all DataFlair tracker transients.
     *
     * Phase 0B H10: chunked DELETE loop with LIMIT 1000 so a site that has
     * accumulated tens of thousands of tracker transients (seen on Sigma)
     * can't blow a single-query binlog / replication / MySQL packet ceiling.
     * Optional WallClockBudget lets a caller bail cleanly when the sync loop
     * needs to return control to the AJAX admin-JS driver.
     *
     * @param \DataFlair\Toplists\Support\WallClockBudget|null $budget
     * @return int Total number of option rows deleted.
     */
    private function clear_tracker_transients($budget = null) {
        global $wpdb;
        $chunk = 1000;
        $total = 0;

        foreach (
            array(
                '_transient_dataflair_tracker_%',
                '_transient_timeout_dataflair_tracker_%',
            ) as $pattern
        ) {
            while (true) {
                if ($budget !== null && $budget->exceeded(1.0)) break;
                $deleted = $wpdb->query(
                    $wpdb->prepare(
                        "DELETE FROM {$wpdb->options}
                         WHERE option_name LIKE %s
                         ORDER BY option_id
                         LIMIT %d",
                        $pattern,
                        $chunk
                    )
                );
                if ($deleted === false) break;
                $total += (int) $deleted;
                if ((int) $deleted < $chunk) break;
            }
        }

        error_log('DataFlair: Cleared ' . $total . ' tracker transient rows before sync');
        return $total;
    }
    
    /**
     * Phase 0B H11: Paginated table wipe that replaces TRUNCATE.
     *
     * TRUNCATE is implicitly committed (cannot be rolled back), cannot be
     * replicated under STATEMENT-based binlog, and on some managed MySQL
     * hosts it forces a metadata lock that blocks concurrent reads for
     * seconds. A chunked DELETE is binlog-safe, cancellable, and stays
     * inside the MySQL packet size even on multi-million-row tables.
     *
     * Hardens against SQL injection by whitelisting the table against the
     * plugin's known prefix, since MySQL doesn't allow placeholders in the
     * identifier position.
     *
     * @param string $table Fully-qualified table name.
     * @param int    $chunk Rows to delete per statement (default 500).
     * @return int Total rows deleted across all chunks.
     */
    private function delete_all_paginated($table, $chunk = 500) {
        global $wpdb;

        $allowed = array(
            $wpdb->prefix . DATAFLAIR_TABLE_NAME,
            $wpdb->prefix . DATAFLAIR_BRANDS_TABLE_NAME,
            $wpdb->prefix . DATAFLAIR_ALTERNATIVE_TOPLISTS_TABLE_NAME,
        );
        if (!in_array($table, $allowed, true)) {
            return 0;
        }

        $chunk = max(50, min(5000, (int) $chunk));
        $total = 0;
        while (true) {
            $deleted = $wpdb->query(
                $wpdb->prepare("DELETE FROM $table LIMIT %d", $chunk)
            );
            if ($deleted === false) break;
            $total += (int) $deleted;
            if ((int) $deleted < $chunk) break;
        }
        return $total;
    }

    /**
     * Phase 0B H5: Aggregate DISTINCT values from a comma-separated text
     * column on the brands table — used to populate filter dropdowns without
     * parsing every brand's JSON `data` blob in PHP.
     *
     * @param string $brands_table Fully-qualified brands table name.
     * @param string $column       Lean CSV column on the brands table.
     * @return string[]
     */
    private function collect_distinct_csv_values($brands_table, $column) {
        global $wpdb;
        $allowed = array('licenses', 'top_geos', 'product_types');
        if (!in_array($column, $allowed, true)) return array();
        $rows = $wpdb->get_col("SELECT DISTINCT $column FROM $brands_table WHERE $column IS NOT NULL AND $column != ''");
        $values = array();
        foreach ($rows as $csv) {
            foreach (array_map('trim', explode(',', (string) $csv)) as $v) {
                if ($v !== '') $values[$v] = true;
            }
        }
        $values = array_keys($values);
        sort($values);
        return $values;
    }

    /**
     * Get last brands cron execution time
     */
    /**
     * Returns human-readable relative time string for a Unix timestamp.
     * e.g. "just now", "3 minutes ago", "2 hours ago"
     */
    private function time_ago( $timestamp ) {
        $diff = time() - $timestamp;

        if ( $diff < 10 )                    return 'just now';
        if ( $diff < 60 )                    return $diff . ' seconds ago';
        if ( $diff < 120 )                   return '1 minute ago';
        if ( $diff < 3600 )                  return floor( $diff / 60 ) . ' minutes ago';
        if ( $diff < 7200 )                  return '1 hour ago';
        if ( $diff < 86400 )                 return floor( $diff / 3600 ) . ' hours ago';
        return date( 'Y-m-d H:i', $timestamp );
    }

    /**
     * Returns a future-relative label, e.g. "in 3 minutes", "in 45 seconds"
     */
    private function time_until( $timestamp ) {
        $diff = $timestamp - time();

        if ( $diff <= 0 )       return 'any moment';
        if ( $diff < 60 )       return 'in ' . $diff . ' seconds';
        if ( $diff < 120 )      return 'in 1 minute';
        if ( $diff < 3600 )     return 'in ' . floor( $diff / 60 ) . ' minutes';
        if ( $diff < 7200 )     return 'in 1 hour';
        return 'in ' . floor( $diff / 3600 ) . ' hours';
    }

    // NOTE: get_last_brands_cron_time() removed in v1.11.0 (Phase 0B H1).
    // Admin UI now calls format_last_sync_label() below.

    // NOTE: ajax_sync_brands_batch() moved to DataFlair\Toplists\Admin\Handlers\SyncBrandsBatchHandler
    // and ajax_fetch_all_brands() moved to FetchAllBrandsHandler in v2.1.3 (Phase 9.7).

    /**
     * Download and save brand logo locally.
     *
     * Fires the `dataflair_brand_logo_stored` action on every successful return
     * (both cached-hit and freshly-downloaded paths). Sigma theme subscribes to
     * this hook; see Phase 0A H0 in docs/plans.
     *
     * Action signature:
     *   do_action('dataflair_brand_logo_stored',
     *       int    $brand_id,     // wp_dataflair_brands.api_brand_id
     *       string $local_url,    // absolute URL to the stored logo
     *       string $remote_url    // upstream URL we fetched from
     *   );
     *
     * @param array  $brand_data Brand data from API
     * @param string $brand_slug Brand slug for filename
     * @return string|false Local URL to saved logo or false on failure
     */
    private function download_brand_logo($brand_data, $brand_slug) {
        // Phase 2 — delegate to the extracted LogoDownloaderInterface. All
        // Phase 0B H3 invariants (3 MB size cap, 8 s timeout, HEAD-first,
        // dataflair_brand_logo_stored hook, 7-day reuse window) preserved.
        return $this->logo_downloader()->download($brand_data, (string) $brand_slug);
    }

    // NOTE: sync_brands_page() moved to DataFlair\Toplists\Sync\BrandSyncService
    // in v1.12.1 (Phase 3). All Phase 0B / Phase 1 / Phase 0A invariants are
    // preserved byte-for-byte inside the service.
    //
    // NOTE: sync_all_brands() removed in v1.11.0 (Phase 0B H1). Batched
    // sync via ajax_sync_brands_batch is now the only path — cron is gone.

    // NOTE: ajax_get_alternative_toplists(), ajax_save_alternative_toplist(),
    // ajax_delete_alternative_toplist(), ajax_get_available_geos(),
    // ajax_api_preview(), and ajax_save_review_url() moved to dedicated handler
    // classes under DataFlair\Toplists\Admin\Ajax\ in v2.1.3 (Phase 9.7).

    /**
     * Discover all toplist endpoints by paginating through the /toplists index.
     *
     * The v2 API paginates (default 15/page). This method walks every page and
     * collects the full list of toplist show-endpoints. Returns an array of
     * endpoint URLs, or an empty array on failure.
     *
     * @param string $token Bearer token
     * @return string[] Toplist endpoint URLs
     */
    private function discover_toplist_endpoints($token) {
        $base_url = $this->get_api_base_url();
        $endpoints = array();
        $current_page = 1;
        $last_page = 1;

        do {
            $list_url = $base_url . '/toplists?per_page=15&page=' . $current_page;
            $response = $this->api_get($list_url, $token);

            if (is_wp_error($response)) {
                error_log('DataFlair discover_toplist_endpoints error (page ' . $current_page . '): ' . $response->get_error_message());
                break;
            }

            $status_code = wp_remote_retrieve_response_code($response);
            if ($status_code !== 200) {
                error_log('DataFlair discover_toplist_endpoints: HTTP ' . $status_code . ' on page ' . $current_page);
                break;
            }

            $data = json_decode(wp_remote_retrieve_body($response), true);
            if (json_last_error() !== JSON_ERROR_NONE || !isset($data['data'])) {
                error_log('DataFlair discover_toplist_endpoints: invalid JSON on page ' . $current_page);
                break;
            }

            foreach ($data['data'] as $toplist) {
                if (isset($toplist['id'])) {
                    $endpoints[] = $base_url . '/toplists/' . $toplist['id'];
                }
            }

            // Laravel paginated response includes meta.last_page
            if (isset($data['meta']['last_page'])) {
                $last_page = (int) $data['meta']['last_page'];
            }

            $current_page++;
        } while ($current_page <= $last_page);

        return $endpoints;
    }

    /**
     * Fetch and store single toplist
     */
    private function fetch_and_store_toplist($endpoint, $token) {
        global $wpdb;
        $table_name = $wpdb->prefix . DATAFLAIR_TABLE_NAME;

        $response = $this->api_get($endpoint, $token);

        if (is_wp_error($response)) {
            $error_message = 'DataFlair API Error for ' . $endpoint . ': ' . $response->get_error_message();
            error_log($error_message);
            add_settings_error('dataflair_messages', 'dataflair_api_error', $error_message, 'error');
            return false;
        }

        $status_code     = wp_remote_retrieve_response_code($response);
        $body            = wp_remote_retrieve_body($response);
        $response_headers = wp_remote_retrieve_headers($response);

        // Log response for debugging
        error_log('DataFlair API Response Code: ' . $status_code . ' for endpoint: ' . $endpoint);

        if ($status_code !== 200) {
            $error_message = $this->build_detailed_api_error($status_code, $body, $response_headers, $endpoint);
            error_log('DataFlair fetch_and_store_toplist error: ' . $error_message);
            add_settings_error('dataflair_messages', 'dataflair_api_error', $error_message, 'error');
            return false;
        }

        $response_data = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $error_message = 'DataFlair JSON Parse Error: ' . json_last_error_msg() . ' for ' . $endpoint;
            error_log($error_message);
            add_settings_error('dataflair_messages', 'dataflair_json_error', $error_message, 'error');
            return false;
        }

        if (!isset($response_data['data']['id'])) {
            $error_message = 'DataFlair API Error: Invalid response format for ' . $endpoint . '. Response: ' . substr($body, 0, 300);
            error_log($error_message);
            add_settings_error('dataflair_messages', 'dataflair_format_error', $error_message, 'error');
            return false;
        }

        $toplist_data = $response_data['data'];

        return $this->store_toplist_data($toplist_data, $body);
    }
    
    /**
     * Store toplist data directly (used when full data is already fetched in bulk)
     */
    private function store_toplist_data($toplist_data, $body) {
        global $wpdb;
        $table_name = $wpdb->prefix . DATAFLAIR_TABLE_NAME;

        if (!isset($toplist_data['id'])) {
            $error_message = 'DataFlair API Error: Invalid response format. Response: ' . substr($body, 0, 300);
            error_log($error_message);
            add_settings_error('dataflair_messages', 'dataflair_format_error', $error_message, 'error');
            return false;
        }

        // ── Run data integrity validation ──
        require_once DATAFLAIR_PLUGIN_DIR . 'includes/DataIntegrityChecker.php';
        $integrity = DataFlair_DataIntegrityChecker::validate($toplist_data);

        // Log warnings to PHP error log for monitoring (first 5 only to avoid log spam)
        if (!empty($integrity['warnings'])) {
            error_log(sprintf(
                '[DataFlair Sync] Toplist #%d (%s): %d warning(s) — %s',
                $toplist_data['id'],
                $toplist_data['name'] ?? 'unknown',
                count($integrity['warnings']),
                implode('; ', array_slice($integrity['warnings'], 0, 5))
            ));
        }

        $api_id  = $toplist_data['id'];
        $name    = $toplist_data['name'] ?? '';
        $version = $toplist_data['version'] ?? '';

        // Build the full data row (warnings are informational — they NEVER block the sync)
        $data_row = array(
            'name'           => $name,
            'slug'           => $toplist_data['slug'] ?? null,
            'current_period' => $toplist_data['currentPeriod'] ?? null,
            'published_at'   => isset($toplist_data['publishedAt']) ? date('Y-m-d H:i:s', strtotime($toplist_data['publishedAt'])) : null,
            'item_count'     => $integrity['item_count'],
            'locked_count'   => $integrity['locked_count'],
            'sync_warnings'  => !empty($integrity['warnings']) ? wp_json_encode($integrity['warnings']) : null,
            'data'           => $body,
            'version'        => $version,
            'last_synced'    => current_time('mysql'),
        );

        // Insert or update by api_toplist_id (upsert key never changes)
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM $table_name WHERE api_toplist_id = %d",
            $api_id
        ));

        // Format types matching $data_row key order:
        // name(%s), slug(%s), current_period(%s), published_at(%s),
        // item_count(%d), locked_count(%d), sync_warnings(%s),
        // data(%s), version(%s), last_synced(%s)
        $update_formats = array('%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s');

        if ($existing) {
            $result = $wpdb->update(
                $table_name,
                $data_row,
                array('api_toplist_id' => $api_id),
                $update_formats,
                array('%d')
            );

            if ($result === false) {
                $error_message = sprintf(
                    'DataFlair DB Update Error for toplist #%d: %s',
                    $api_id,
                    $wpdb->last_error ?: 'Unknown error'
                );
                error_log($error_message);
                add_settings_error('dataflair_messages', 'dataflair_db_error', $error_message, 'error');
                return false;
            }
        } else {
            $data_row['api_toplist_id'] = $api_id;
            // Append api_toplist_id format (%d) to the end
            $insert_formats = array_merge($update_formats, array('%d'));

            $result = $wpdb->insert(
                $table_name,
                $data_row,
                $insert_formats
            );

            if ($result === false) {
                $error_message = sprintf(
                    'DataFlair DB Insert Error for toplist #%d: %s',
                    $api_id,
                    $wpdb->last_error ?: 'Unknown error'
                );
                error_log($error_message);
                add_settings_error('dataflair_messages', 'dataflair_db_error', $error_message, 'error');
                return false;
            }
        }

        return true;
    }

    /**
     * Shortcode handler
     */
    public function toplist_shortcode($atts) {
        // Phase 1 — observability: capture render wall time + item count.
        $render_t0 = microtime(true);

        // Extract shortcode-specific attributes
        $shortcode_defaults = array(
            'id'    => '',  // Primary — looks up by api_toplist_id
            'slug'  => '',  // Optional — looks up by slug column
            'title' => '',
            'limit' => 0,
            'layout' => 'cards',
        );

        // Merge with defaults but preserve all other attributes (for customization)
        $atts = wp_parse_args($atts, $shortcode_defaults);

        do_action('dataflair_render_started', array(
            'toplist_id' => (int) ($atts['id'] ?? 0),
            'slug'       => (string) ($atts['slug'] ?? ''),
            'layout'     => (string) ($atts['layout'] ?? 'cards'),
        ));

        if (empty($atts['id']) && empty($atts['slug'])) {
            return '<p style="color: red;">DataFlair Error: Toplist ID or slug is required</p>';
        }

        global $wpdb;
        $table_name = $wpdb->prefix . DATAFLAIR_TABLE_NAME;

        // Look up toplist by slug first, then by API ID
        if (!empty($atts['slug'])) {
            $toplist = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM $table_name WHERE slug = %s LIMIT 1",
                $atts['slug']
            ));
        } else {
            $toplist = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM $table_name WHERE api_toplist_id = %d",
                intval($atts['id'])
            ));
        }
        
        if (!$toplist) {
            $identifier = !empty($atts['slug'])
                ? 'slug "' . esc_html($atts['slug']) . '"'
                : 'ID ' . esc_html($atts['id']);
            return '<p style="color: red;">DataFlair Error: Toplist ' . $identifier . ' not found. Please sync first.</p>';
        }
        
        $data = json_decode($toplist->data, true);
        
        if (!isset($data['data']['items'])) {
            return '<p style="color: red;">DataFlair Error: Invalid toplist data</p>';
        }
        
        // Check if data is stale (older than 3 days)
        $last_synced = strtotime($toplist->last_synced);
        $is_stale = (time() - $last_synced) > (3 * 24 * 60 * 60);
        
        $items = $data['data']['items'];
        
        // Apply limit
        if ($atts['limit'] > 0) {
            $items = array_slice($items, 0, $atts['limit']);
        }
        
        // Use custom title or default
        $title = !empty($atts['title']) ? $atts['title'] : $data['data']['name'];
        
        // Extract customization attributes (all attributes except shortcode-specific ones)
        $customizations = $atts;
        $pros_cons_data = isset($customizations['prosCons']) ? $customizations['prosCons'] : array();
        unset($customizations['id'], $customizations['title'], $customizations['limit'], $customizations['layout'], $customizations['prosCons']);

        if (isset($atts['layout']) && $atts['layout'] === 'table') {
            $table_html = $this->render_toplist_table($items, $title, $is_stale, $last_synced, $pros_cons_data);
            do_action('dataflair_render_finished', array(
                'toplist_id'  => (int) ($atts['id'] ?? 0),
                'item_count'  => count($items),
                'elapsed_ms'  => (int) round((microtime(true) - $render_t0) * 1000),
                'layout'      => 'table',
            ));
            return $table_html;
        }

        ob_start();
        ?>
        <div class="dataflair-toplist">
            <?php if ($is_stale): ?>
                <div class="dataflair-notice">
                    ⚠️ This data was last updated on <?php echo date('M d, Y', $last_synced); ?>. Using cached version.
                </div>
            <?php endif; ?>
            
            <?php if (!empty($title)): ?>
            <h2 class="dataflair-title"><?php echo esc_html($title); ?></h2>
            <?php endif; ?>
            
                        <?php
            // Phase 0B H7: prefetch every card's brand row in one (or at most
            // three) SQL round-trip instead of 5 cascading per-card queries.
            $brand_meta_map = $this->prefetch_brand_metas_for_items($items);
            foreach ($items as $item):
                echo $this->render_casino_card($item, $atts['id'], $customizations, $pros_cons_data, $brand_meta_map);
            endforeach; ?>
        </div>
        <?php
        $html = ob_get_clean();

        do_action('dataflair_render_finished', array(
            'toplist_id'  => (int) ($atts['id'] ?? 0),
            'item_count'  => count($items),
            'elapsed_ms'  => (int) round((microtime(true) - $render_t0) * 1000),
            'layout'      => 'cards',
        ));

        return $html;
    }

    /**
     * Render a simplified table layout for block testing.
     *
     * @param array $items
     * @param string $title
     * @param bool $is_stale
     * @param int $last_synced
     * @param array $pros_cons_data
     * @return string
     */
    private function render_toplist_table($items, $title, $is_stale, $last_synced, $pros_cons_data = array()) {
        // Phase 4 — delegate to TableRenderer. Filterable via `dataflair_table_renderer`.
        return $this->table_renderer()->render(
            new \DataFlair\Toplists\Frontend\Render\ViewModels\ToplistTableVM(
                (array) $items,
                (string) $title,
                (bool) $is_stale,
                (int) $last_synced,
                (array) $pros_cons_data
            )
        );
    }

    // Phase 9.9 — `resolve_pros_cons_for_table_item()` removed. Logic
    // lives in the `DataFlair\Toplists\Frontend\Render\ProsConsResolver`
    // trait used by both CardRenderer and TableRenderer.


    /**
     * Phase 9.9 — delegates to `Frontend\Content\ReviewPostFinder`.
     *
     * @return WP_Post|null
     */
    private function find_review_post_by_brand_meta(array $brand) {
        return $this->review_post_finder()->findByBrandMeta($brand);
    }

    /**
     * Phase 9.9 — delegates to `Frontend\Content\ReviewPostManager`.
     *
     * @param array $brand Brand data from API.
     * @param array $item  Full toplist item data.
     * @return int|false Post ID of the review, or false on failure.
     */
    private function get_or_create_review_post($brand, $item) {
        return $this->review_post_manager()->getOrCreate((array) $brand, (array) $item);
    }


    /**
     * Phase 9.9 — delegates to `Frontend\Render\BrandMetaPrefetcher`.
     *
     * @param array $items Items array from the toplist payload.
     * @return array{ids: array<int,object>, slugs: array<string,object>, names: array<string,object>}
     */
    private function prefetch_brand_metas_for_items(array $items) {
        return $this->brand_meta_prefetcher()->prefetch($items);
    }

    /**
     * Phase 9.9 — delegates to `Frontend\Render\BrandMetaLookup`.
     *
     * @param array $brand    The item's brand payload (api_brand_id/id/slug/name).
     * @param array $meta_map Output of prefetch_brand_metas_for_items().
     * @return object|null
     */
    private function lookup_brand_meta_from_map(array $brand, array $meta_map) {
        return $this->brand_meta_lookup()->lookup($brand, $meta_map);
    }

    /**
     * Phase 9.9 — delegates to `Frontend\Content\ReviewPostBatchFinder`.
     *
     * @param int[] $brand_ids
     * @return array<int,int>
     */
    private function find_review_posts_by_brand_metas(array $brand_ids) {
        return $this->review_post_batch_finder()->findByApiBrandIds($brand_ids);
    }

    /**
     * Render individual casino card
     * Uses the new structured template for better layout
     *
     * @codeCoverageIgnore
     */
    private function render_casino_card($item, $toplist_id, $customizations = array(), $pros_cons_data = array(), $brand_meta_map = null) {
        // Phase 4 — delegate to CardRenderer. Filterable via `dataflair_card_renderer`.
        // The pre-Phase-0A legacy fallback (sideload logos + auto-create
        // review CPTs at render time) is gone — it was unreachable once the
        // bundled template existed on disk and violated the Phase 0A
        // read-only contract enforced by RenderIsReadOnlyTest.
        return $this->card_renderer()->render(
            new \DataFlair\Toplists\Frontend\Render\ViewModels\CasinoCardVM(
                (array) $item,
                (int) $toplist_id,
                (array) $customizations,
                (array) $pros_cons_data,
                is_array($brand_meta_map) ? $brand_meta_map : null
            )
        );
    }
    
    // Phase 9.8 — frontend asset methods moved out of the god-class.
    //   enqueue_frontend_assets()  -> Frontend\Assets\StylesEnqueuer
    //   maybe_enqueue_alpine()     -> Frontend\Assets\AlpineJsEnqueuer
    //   enqueue_promo_copy_script()-> Frontend\Assets\PromoCopyScript
    //   add_alpine_defer_attribute -> Frontend\Assets\AlpineDeferAttribute
    //   check_widget_for_shortcode -> Frontend\Assets\WidgetShortcodeDetector


    /**
     * Build the "Last sync" label for the admin UI. Reads the given option
     * (still named `dataflair_last_*_cron_run` for backward compat — option
     * rename lands in Phase 1 via a dedicated migration).
     */
    /**
     * Accepts either the legacy `dataflair_last_*_cron_run` name or the new
     * `dataflair_last_*_sync` name and returns a human "Last sync: ..." label.
     *
     * Phase 1 renamed the stored option; the admin pages still pass the
     * legacy key for one release, so this helper falls back through:
     *   1. the key it was given
     *   2. the new-name twin (if caller passed legacy)
     *   3. the legacy-name twin (if caller passed new)
     */
    private function format_last_sync_label($option_key) {
        return $this->sync_label_formatter()->format((string) $option_key);
    }


    /**
     * Register the Gutenberg block.
     *
     * Phase 7 — delegates to DataFlair\Toplists\Block\BlockRegistrar.
     * Kept on the god-class as a public seam until Phase 8 shim birth so
     * any external caller holding a reference to `[$this, 'register_block']`
     * continues to work.
     */
    public function register_block() {
        $this->block_bootstrap()->boot()->registerBlock();
    }

    /**
     * Enqueue editor assets for the Gutenberg block.
     *
     * Phase 7 — delegates to DataFlair\Toplists\Block\EditorAssets.
     * Kept on the god-class as a public seam for the same reason as
     * register_block.
     */
    public function enqueue_editor_assets() {
        (new \DataFlair\Toplists\Block\EditorAssets(DATAFLAIR_PLUGIN_URL, DATAFLAIR_VERSION))->enqueue();
    }
    
    /**
     * Handle campaign redirect from /go/?campaign=campaign-name
     * Performs 301 redirect to tracker URL stored in transient
     */
    public function handle_campaign_redirect() {
        // Check if this is a /go/ request with campaign parameter
        if (!isset($_GET['campaign']) || empty($_GET['campaign'])) {
            return;
        }
        
        // Only handle if URL path is /go/ or contains /go
        $request_uri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';
        $parsed_url = parse_url($request_uri);
        $path = isset($parsed_url['path']) ? $parsed_url['path'] : '';
        
        // Check if path contains /go (handles /go/, /go, /go?campaign=, etc.)
        if (strpos($path, '/go') === false) {
            return;
        }
        
        $campaign_name = sanitize_text_field($_GET['campaign']);
        
        if (empty($campaign_name)) {
            // Invalid campaign name, return 404
            status_header(404);
            nocache_headers();
            return;
        }
        
        // Look up tracker URL from transient
        $transient_key = 'dataflair_tracker_' . md5($campaign_name);
        $tracker_url = get_transient($transient_key);
        
        if (empty($tracker_url) || !filter_var($tracker_url, FILTER_VALIDATE_URL)) {
            // Campaign not found or invalid URL, return 404
            status_header(404);
            nocache_headers();
            return;
        }
        
        // Perform 301 redirect to tracker URL
        wp_redirect($tracker_url, 301);
        exit;
    }
    
    /**
     * Render callback for the Gutenberg block.
     *
     * Phase 7 — delegates to DataFlair\Toplists\Block\ToplistBlock. Kept on
     * the god-class as a public seam in case any registered block metadata
     * still points at `[$this, 'render_block']`. New code should never call
     * this directly.
     */
    public function render_block($attributes) {
        $block = new \DataFlair\Toplists\Block\ToplistBlock(
            \Closure::fromCallable([$this, 'toplist_shortcode']),
            \Closure::fromCallable('get_option')
        );
        return $block->render($attributes);
    }
    
    /**
     * Register REST API routes for block editor
     */
    /**
     * Phase 6 — delegated to RestRouter. The three route registrations live
     * in src/Rest/RestRouter.php; callbacks delegate to dedicated controllers.
     */
    public function register_rest_routes() {
        $this->rest_bootstrap()->boot()->register();
    }
    
    /**
     * REST API callback to get available toplists.
     *
     * Phase 6 — delegates to ToplistsController::list(). Kept on the god-class
     * as a public seam because downstream code may still hold a reference to
     * the callable `[$this, 'get_toplists_rest']`.
     */
    public function get_toplists_rest() {
        return (new \DataFlair\Toplists\Rest\Controllers\ToplistsController(
            $this->toplists_repo(),
            \DataFlair\Toplists\Logging\LoggerFactory::get()
        ))->list();
    }
    
    /**
     * REST API callback to get casinos for a toplist.
     *
     * Phase 6 — delegates to CasinosController::listForToplist(). H12
     * (Phase 0B) pagination contract preserved verbatim: `?per_page` default
     * 20 max 100, `?page` default 1, lean default shape, `?full=1` for the
     * legacy verbose shape the block editor consumes, X-WP-Total +
     * X-WP-TotalPages headers on every response.
     */
    public function get_toplist_casinos_rest($request) {
        return (new \DataFlair\Toplists\Rest\Controllers\CasinosController(
            $this->toplists_repo(),
            \Closure::fromCallable([$this, 'prefetch_brand_metas_for_items']),
            \Closure::fromCallable([$this, 'lookup_brand_meta_from_map']),
            \DataFlair\Toplists\Logging\LoggerFactory::get()
        ))->listForToplist($request);
    }

    /**
     * Check if MySQL/MariaDB supports JSON data type.
     *
     * Phase 9.5 — logic lives in
     * {@see \DataFlair\Toplists\Database\SchemaMigrator::supportsJsonType()}.
     *
     * @deprecated 2.1.1 Use `\DataFlair\Toplists\Database\SchemaMigrator::supportsJsonType()`.
     */
    private function supports_json_type() {
        return (new \DataFlair\Toplists\Database\SchemaMigrator())->supportsJsonType();
    }

    /**
     * Migrate data fields from longtext to JSON type.
     *
     * Phase 9.5 — logic lives in
     * {@see \DataFlair\Toplists\Database\SchemaMigrator::migrateToJsonType()}.
     *
     * @deprecated 2.1.1 Use `\DataFlair\Toplists\Database\SchemaMigrator::migrateToJsonType()`.
     */
    public function migrate_to_json_type() {
        (new \DataFlair\Toplists\Database\SchemaMigrator())->migrateToJsonType();
    }
}

// Initialize plugin — canonical entry is Plugin::boot(). The call is idempotent
// and internally calls DataFlair_Toplists::get_instance() so the legacy
// strangler-fig shim continues to own hook registrations through v2.0.x.
// Passing __FILE__ lets Plugin::boot() resolve the plugin file for I18n
// + GithubUpdateChecker + PluginInfoFilter registrars.
\DataFlair\Toplists\Plugin::boot(__FILE__);

// Register WP-CLI commands (Phase 0A H0 + Phase 0.5 perf rig).
if (defined('WP_CLI') && WP_CLI) {
    require_once DATAFLAIR_PLUGIN_DIR . 'includes/Cli/ReconcileReviewsCommand.php';
    \WP_CLI::add_command(
        'dataflair reconcile-reviews',
        \DataFlair\Toplists\Cli\ReconcileReviewsCommand::class
    );

    require_once DATAFLAIR_PLUGIN_DIR . 'includes/Cli/PerfSeedCommand.php';
    \WP_CLI::add_command(
        'dataflair perf:seed',
        \DataFlair\Toplists\Cli\PerfSeedCommand::class
    );

    require_once DATAFLAIR_PLUGIN_DIR . 'includes/Cli/PerfRunCommand.php';
    \WP_CLI::add_command(
        'dataflair perf:run',
        \DataFlair\Toplists\Cli\PerfRunCommand::class
    );

    require_once DATAFLAIR_PLUGIN_DIR . 'includes/Cli/LogsCommand.php';
    \WP_CLI::add_command(
        'dataflair logs',
        \DataFlair\Toplists\Cli\LogsCommand::class
    );
}
