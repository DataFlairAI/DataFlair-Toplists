<?php
/**
 * Plugin Name: DataFlair Toplists
 * Plugin URI: https://dataflair.ai
 * Description: Fetch and display casino toplists from DataFlair API
 * Version: 2.1.8
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
if (!defined('DATAFLAIR_VERSION'))                          define('DATAFLAIR_VERSION', '2.1.8');
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
     * Phase 9.10 — extracted sync-pipeline helpers. Lazy.
     *
     * @var \DataFlair\Toplists\Sync\TransientCleaner|null
     */
    private $transient_cleaner = null;

    /** @var \DataFlair\Toplists\Database\PaginatedDeleter|null */
    private $paginated_deleter = null;

    /** @var \DataFlair\Toplists\Database\JsonValueCollector|null */
    private $json_value_collector = null;

    /** @var \DataFlair\Toplists\Sync\EndpointDiscovery|null */
    private $endpoint_discovery = null;

    /** @var \DataFlair\Toplists\Database\ToplistDataStore|null */
    private $toplist_data_store = null;

    /** @var \DataFlair\Toplists\Sync\ToplistFetcher|null */
    private $toplist_fetcher = null;

    /** @var \DataFlair\Toplists\Sync\LogoSync|null */
    private $logo_sync = null;

    /**
     * Phase 9.11 — extracted HTTP/URL/Support utilities. Lazy.
     *
     * @var \DataFlair\Toplists\Support\UrlValidator|null
     */
    private $url_validator = null;

    /** @var \DataFlair\Toplists\Support\UrlTransformer|null */
    private $url_transformer = null;

    /** @var \DataFlair\Toplists\Support\EnvironmentDetector|null */
    private $environment_detector = null;

    /** @var \DataFlair\Toplists\Http\ApiBaseUrlDetector|null */
    private $api_base_url_detector = null;

    /** @var \DataFlair\Toplists\Http\BrandsApiUrlBuilder|null */
    private $brands_api_url_builder = null;

    /** @var \DataFlair\Toplists\Http\ApiErrorFormatter|null */
    private $api_error_formatter = null;

    /** @var \DataFlair\Toplists\Support\RelativeTimeFormatter|null */
    private $relative_time_formatter = null;

    /** @var \DataFlair\Toplists\Frontend\Shortcode\ToplistShortcode|null */
    private $toplist_shortcode_instance = null;

    /** @var \DataFlair\Toplists\Frontend\Redirect\CampaignRedirectHandler|null */
    private $campaign_redirect_handler = null;

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
            $this->brands_repo(),
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

    /**
     * Phase 9.10 — lazy getters for the extracted sync-pipeline helpers.
     */
    private function transient_cleaner() {
        if ($this->transient_cleaner instanceof \DataFlair\Toplists\Sync\TransientCleaner) {
            return $this->transient_cleaner;
        }
        $this->transient_cleaner = new \DataFlair\Toplists\Sync\TransientCleaner();
        return $this->transient_cleaner;
    }

    private function paginated_deleter() {
        if ($this->paginated_deleter instanceof \DataFlair\Toplists\Database\PaginatedDeleter) {
            return $this->paginated_deleter;
        }
        $this->paginated_deleter = new \DataFlair\Toplists\Database\PaginatedDeleter();
        return $this->paginated_deleter;
    }

    private function json_value_collector() {
        if ($this->json_value_collector instanceof \DataFlair\Toplists\Database\JsonValueCollector) {
            return $this->json_value_collector;
        }
        $this->json_value_collector = new \DataFlair\Toplists\Database\JsonValueCollector();
        return $this->json_value_collector;
    }

    private function endpoint_discovery() {
        if ($this->endpoint_discovery instanceof \DataFlair\Toplists\Sync\EndpointDiscovery) {
            return $this->endpoint_discovery;
        }
        $this->endpoint_discovery = new \DataFlair\Toplists\Sync\EndpointDiscovery(
            $this->api_client(),
            \Closure::fromCallable([$this, 'get_api_base_url'])
        );
        return $this->endpoint_discovery;
    }

    private function toplist_data_store() {
        if ($this->toplist_data_store instanceof \DataFlair\Toplists\Database\ToplistDataStore) {
            return $this->toplist_data_store;
        }
        $this->toplist_data_store = new \DataFlair\Toplists\Database\ToplistDataStore();
        return $this->toplist_data_store;
    }

    private function toplist_fetcher() {
        if ($this->toplist_fetcher instanceof \DataFlair\Toplists\Sync\ToplistFetcher) {
            return $this->toplist_fetcher;
        }
        $this->toplist_fetcher = new \DataFlair\Toplists\Sync\ToplistFetcher(
            $this->api_client(),
            $this->toplist_data_store(),
            \Closure::fromCallable([$this, 'build_detailed_api_error'])
        );
        return $this->toplist_fetcher;
    }

    private function logo_sync() {
        if ($this->logo_sync instanceof \DataFlair\Toplists\Sync\LogoSync) {
            return $this->logo_sync;
        }
        $this->logo_sync = new \DataFlair\Toplists\Sync\LogoSync(
            $this->logo_downloader()
        );
        return $this->logo_sync;
    }

    /**
     * Phase 9.11 — lazy getters for the extracted HTTP/URL/Support utilities.
     */
    private function url_validator() {
        if ($this->url_validator instanceof \DataFlair\Toplists\Support\UrlValidator) {
            return $this->url_validator;
        }
        $this->url_validator = new \DataFlair\Toplists\Support\UrlValidator();
        return $this->url_validator;
    }

    private function url_transformer() {
        if ($this->url_transformer instanceof \DataFlair\Toplists\Support\UrlTransformer) {
            return $this->url_transformer;
        }
        $this->url_transformer = new \DataFlair\Toplists\Support\UrlTransformer($this->url_validator());
        return $this->url_transformer;
    }

    private function environment_detector() {
        if ($this->environment_detector instanceof \DataFlair\Toplists\Support\EnvironmentDetector) {
            return $this->environment_detector;
        }
        $this->environment_detector = new \DataFlair\Toplists\Support\EnvironmentDetector();
        return $this->environment_detector;
    }

    private function api_base_url_detector() {
        if ($this->api_base_url_detector instanceof \DataFlair\Toplists\Http\ApiBaseUrlDetector) {
            return $this->api_base_url_detector;
        }
        $this->api_base_url_detector = new \DataFlair\Toplists\Http\ApiBaseUrlDetector($this->url_transformer());
        return $this->api_base_url_detector;
    }

    private function brands_api_url_builder() {
        if ($this->brands_api_url_builder instanceof \DataFlair\Toplists\Http\BrandsApiUrlBuilder) {
            return $this->brands_api_url_builder;
        }
        $this->brands_api_url_builder = new \DataFlair\Toplists\Http\BrandsApiUrlBuilder($this->api_base_url_detector());
        return $this->brands_api_url_builder;
    }

    private function api_error_formatter() {
        if ($this->api_error_formatter instanceof \DataFlair\Toplists\Http\ApiErrorFormatter) {
            return $this->api_error_formatter;
        }
        $this->api_error_formatter = new \DataFlair\Toplists\Http\ApiErrorFormatter();
        return $this->api_error_formatter;
    }

    private function relative_time_formatter() {
        if ($this->relative_time_formatter instanceof \DataFlair\Toplists\Support\RelativeTimeFormatter) {
            return $this->relative_time_formatter;
        }
        $this->relative_time_formatter = new \DataFlair\Toplists\Support\RelativeTimeFormatter();
        return $this->relative_time_formatter;
    }

    /**
     * Phase 9.12 — lazy getter for the public-shortcode orchestrator. Public
     * because `\DataFlair\Toplists\Plugin::registerHooks()` constructs the
     * `ShortcodeRegistrar` against this instance so the legacy delegator
     * (`toplist_shortcode($atts)`) and the registrar share one object.
     *
     * @return \DataFlair\Toplists\Frontend\Shortcode\ToplistShortcode
     */
    public function toplist_shortcode_instance() {
        if ($this->toplist_shortcode_instance instanceof \DataFlair\Toplists\Frontend\Shortcode\ToplistShortcode) {
            return $this->toplist_shortcode_instance;
        }
        $this->toplist_shortcode_instance = new \DataFlair\Toplists\Frontend\Shortcode\ToplistShortcode(
            $this->toplists_repo(),
            $this->card_renderer(),
            $this->table_renderer(),
            $this->brand_meta_prefetcher()
        );
        return $this->toplist_shortcode_instance;
    }

    /**
     * Phase 9.12 — lazy getter for the campaign redirect handler. Public so
     * `Plugin::registerHooks()` can register its `template_redirect` hook
     * without touching the legacy method.
     *
     * @return \DataFlair\Toplists\Frontend\Redirect\CampaignRedirectHandler
     */
    public function campaign_redirect_handler() {
        if ($this->campaign_redirect_handler instanceof \DataFlair\Toplists\Frontend\Redirect\CampaignRedirectHandler) {
            return $this->campaign_redirect_handler;
        }
        $this->campaign_redirect_handler = new \DataFlair\Toplists\Frontend\Redirect\CampaignRedirectHandler();
        return $this->campaign_redirect_handler;
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
            new \DataFlair\Toplists\Admin\Pages\DashboardPage(),
            new \DataFlair\Toplists\Admin\Pages\ToplistsListPage(
                \Closure::fromCallable([$this, 'format_last_sync_label'])
            ),
            $this->brands_page_obj(),
            new \DataFlair\Toplists\Admin\Pages\ToolsPage(
                \Closure::fromCallable([$this, 'get_api_base_url'])
            ),
            $this->settings_page_obj()
        ))->register();
        (new \DataFlair\Toplists\Admin\SettingsRegistrar())->register();

        // Phase 5 — AJAX handlers live in src/Admin/Handlers and route through
        // DataFlair\Toplists\Admin\AjaxRouter. The router owns the centralised
        // nonce + capability checks; each handler receives the sanitised
        // request payload and returns a structured response array. The legacy
        // `ajax_*` methods on this class still exist (kept until Phase 8) so
        // external callers that invoked them directly continue to work.
        $this->admin_bootstrap()->boot();

        // Phase 9.12 — `add_shortcode('dataflair_toplist', ...)` and the
        // `template_redirect` action for `/go/?campaign=…` are registered by
        // `Plugin::registerHooks()` via `Frontend\Shortcode\ShortcodeRegistrar`
        // and `Frontend\Redirect\CampaignRedirectHandler`. The legacy
        // `toplist_shortcode()` / `handle_campaign_redirect()` methods on this
        // class survive as thin delegators because the block editor render
        // callback still binds to `[$this, 'toplist_shortcode']`.

        // Phase 7 — Gutenberg block registration + editor assets now live in
        // DataFlair\Toplists\Block\{BlockRegistrar, ToplistBlock, EditorAssets}.
        // BlockRegistrar::register() wires the `init` and
        // `enqueue_block_editor_assets` hooks in one call.
        $this->block_bootstrap()->boot()->register();

        // REST API for block editor
        add_action('rest_api_init', array($this, 'register_rest_routes'));

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
        // Phase 9.11 — delegate to Support\UrlValidator.
        return $this->url_validator()->isLocal((string) $url);
    }

    /**
     * Force HTTPS on a URL unless it's a local dev domain.
     * Production/staging servers redirect HTTP→HTTPS which strips Authorization headers.
     *
     * @param string $url The URL to upgrade
     * @return string The URL, with https:// if non-local
     */
    private function maybe_force_https($url) {
        // Phase 9.11 — delegate to Support\UrlTransformer.
        return $this->url_transformer()->maybeForceHttps((string) $url);
    }

    /**
     * Get the brands API URL for the given page, respecting the selected API version.
     * Toplists always use v1 — only brands sync uses this helper.
     *
     * @param int $page Page number
     * @return string Full brands URL with page parameter
     */
    private function get_brands_api_url($page) {
        // Phase 9.11 — delegate to Http\BrandsApiUrlBuilder.
        return $this->brands_api_url_builder()->buildPageUrl((int) $page);
    }

    /**
     * Get API base URL - auto-detect from stored endpoints or use default
     *
     * @return string API base URL
     */
    private function get_api_base_url() {
        // Phase 9.11 — delegate to Http\ApiBaseUrlDetector.
        return $this->api_base_url_detector()->detect();
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
        // Phase 9.11 — delegate to Support\EnvironmentDetector.
        return $this->environment_detector()->isRunningInDocker();
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
        // Phase 9.11 — delegate to Http\ApiErrorFormatter.
        return $this->api_error_formatter()->format(
            (int) $status_code,
            (string) $body,
            $headers,
            (string) $url
        );
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
     * Phase 9.10 — chunked tracker-transient cleanup.
     * Behaviour preserved verbatim; signature unchanged.
     *
     * @param \DataFlair\Toplists\Support\WallClockBudget|null $budget
     * @return int Total number of option rows deleted.
     */
    private function clear_tracker_transients($budget = null) {
        return $this->transient_cleaner()->clear($budget);
    }

    /**
     * Phase 9.10 — paginated table wipe (replaces TRUNCATE).
     * Behaviour preserved verbatim; signature unchanged.
     *
     * @param string $table Fully-qualified table name.
     * @param int    $chunk Rows to delete per statement (default 500).
     * @return int Total rows deleted across all chunks.
     */
    private function delete_all_paginated($table, $chunk = 500) {
        return $this->paginated_deleter()->deleteAll((string) $table, (int) $chunk);
    }

    /**
     * Phase 9.10 — aggregate DISTINCT CSV-column values for admin
     * filter dropdowns. Behaviour preserved verbatim.
     *
     * @param string $brands_table Fully-qualified brands table name.
     * @param string $column       Lean CSV column on the brands table.
     * @return string[]
     */
    private function collect_distinct_csv_values($brands_table, $column) {
        return $this->json_value_collector()->collect((string) $brands_table, (string) $column);
    }

    /**
     * Get last brands cron execution time
     */
    /**
     * Returns human-readable relative time string for a Unix timestamp.
     * e.g. "just now", "3 minutes ago", "2 hours ago"
     */
    private function time_ago( $timestamp ) {
        // Phase 9.11 — delegate to Support\RelativeTimeFormatter.
        return $this->relative_time_formatter()->timeAgo((int) $timestamp);
    }

    /**
     * Returns a future-relative label, e.g. "in 3 minutes", "in 45 seconds"
     */
    private function time_until( $timestamp ) {
        // Phase 9.11 — delegate to Support\RelativeTimeFormatter.
        return $this->relative_time_formatter()->timeUntil((int) $timestamp);
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
        // Phase 9.10 — delegate via the LogoSync wrapper. Phase 2 already
        // moved the heavy lifting (3 MB cap, 8 s timeout, HEAD-first,
        // dataflair_brand_logo_stored hook, 7-day reuse window) into
        // LogoDownloader; LogoSync gives the sync side a named seam.
        return $this->logo_sync()->download((array) $brand_data, (string) $brand_slug);
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
     * Phase 9.10 — discover the full set of toplist show-endpoints.
     * Behaviour preserved verbatim.
     *
     * @param string $token Bearer token.
     * @return string[]     Toplist endpoint URLs.
     */
    private function discover_toplist_endpoints($token) {
        return $this->endpoint_discovery()->discover((string) $token);
    }

    /**
     * Phase 9.10 — fetch a single toplist by endpoint and persist.
     */
    private function fetch_and_store_toplist($endpoint, $token) {
        return $this->toplist_fetcher()->fetchAndStore((string) $endpoint, (string) $token);
    }

    /**
     * Phase 9.10 — upsert a decoded toplist payload.
     */
    private function store_toplist_data($toplist_data, $body) {
        return $this->toplist_data_store()->store((array) $toplist_data, (string) $body);
    }

    /**
     * Phase 9.12 — delegates to `Frontend\Shortcode\ToplistShortcode::render()`.
     * Kept as a public seam because the Gutenberg block render callback
     * (and any downstream code that bound to `[$plugin, 'toplist_shortcode']`)
     * still references this method.
     *
     * @param array|string $atts
     * @return string
     */
    public function toplist_shortcode($atts) {
        return $this->toplist_shortcode_instance()->render($atts);
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
     * Phase 9.12 — delegates to `Frontend\Redirect\CampaignRedirectHandler::handle()`.
     * Kept as a public seam for any downstream code that may have bound a
     * filter or replacement directly to `[$plugin, 'handle_campaign_redirect']`.
     */
    public function handle_campaign_redirect() {
        $this->campaign_redirect_handler()->handle();
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
