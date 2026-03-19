<?php
/**
 * Plugin Name: DataFlair Toplists
 * Plugin URI: https://dataflair.ai
 * Description: Fetch and display casino toplists from DataFlair API
 * Version: 1.7.0
 * Author: DataFlair
 * Author URI: https://dataflair.ai
 * License: GPL v2 or later
 * Text Domain: dataflair-toplists
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('DATAFLAIR_VERSION', '1.7.0');
define('DATAFLAIR_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('DATAFLAIR_PLUGIN_URL', plugin_dir_url(__FILE__));
define('DATAFLAIR_TABLE_NAME', 'dataflair_toplists');
define('DATAFLAIR_BRANDS_TABLE_NAME', 'dataflair_brands');
define('DATAFLAIR_ALTERNATIVE_TOPLISTS_TABLE_NAME', 'dataflair_alternative_toplists');

// Load Composer autoloader
if (file_exists(DATAFLAIR_PLUGIN_DIR . 'vendor/autoload.php')) {
    require_once DATAFLAIR_PLUGIN_DIR . 'vendor/autoload.php';
}

/**
 * Main DataFlair Plugin Class
 */
class DataFlair_Toplists {
    
    private static $instance = null;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        $this->init_hooks();
    }
    
    private function init_hooks() {
        // Activation/Deactivation
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
        
        // Check and upgrade database schema
        add_action('plugins_loaded', array($this, 'check_database_upgrade'));
        
        // Admin menu
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('wp_ajax_dataflair_save_settings', array($this, 'ajax_save_settings'));
        add_action('wp_ajax_dataflair_fetch_all_toplists', array($this, 'ajax_fetch_all_toplists'));
        add_action('wp_ajax_dataflair_fetch_all_brands', array($this, 'ajax_fetch_all_brands'));
        add_action('wp_ajax_dataflair_sync_brands_batch', array($this, 'ajax_sync_brands_batch'));
        add_action('wp_ajax_dataflair_get_alternative_toplists', array($this, 'ajax_get_alternative_toplists'));
        add_action('wp_ajax_dataflair_save_alternative_toplist', array($this, 'ajax_save_alternative_toplist'));
        add_action('wp_ajax_dataflair_delete_alternative_toplist', array($this, 'ajax_delete_alternative_toplist'));
        add_action('wp_ajax_dataflair_get_available_geos', array($this, 'ajax_get_available_geos'));
        add_action('wp_ajax_dataflair_api_preview', array($this, 'ajax_api_preview'));
        
        // Shortcode
        add_shortcode('dataflair_toplist', array($this, 'toplist_shortcode'));
        
        // Gutenberg Block
        add_action('init', array($this, 'register_block'));
        
        // REST API for block editor
        add_action('rest_api_init', array($this, 'register_rest_routes'));
        
        // Redirect handler for /go/ campaign links
        add_action('template_redirect', array($this, 'handle_campaign_redirect'));
        
        // Cron
        add_action('dataflair_sync_cron', array($this, 'cron_sync_toplists'));
        add_action('dataflair_brands_sync_cron', array($this, 'cron_sync_brands'));
        
        // Custom cron schedule — must be registered before any wp_schedule_event calls
        add_filter('cron_schedules', array($this, 'add_custom_cron_schedules'));

        // Self-healing cron: reschedule on init so the custom schedule is already
        // registered when wp_schedule_event runs (activation fires too early).
        add_action('init', array($this, 'ensure_cron_scheduled'));

        // Enqueue frontend styles and scripts
        add_action('wp_enqueue_scripts', array($this, 'enqueue_frontend_assets'));
        
        // Enqueue admin scripts
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        
        // Enqueue block editor assets
        add_action('enqueue_block_editor_assets', array($this, 'enqueue_editor_assets'));
        
        // Check if shortcode/block is used and enqueue Alpine.js if needed
        add_action('wp_footer', array($this, 'maybe_enqueue_alpine'), 5);
        
        // Also check in widgets and other areas
        add_filter('widget_text', array($this, 'check_widget_for_shortcode'), 10, 2);

        // Admin notice: plain permalinks break the REST API (and the Gutenberg block)
        add_action('admin_notices', array($this, 'maybe_notice_plain_permalinks'));
    }
    
    /**
     * Show an admin notice if plain permalinks are active (REST API won't work).
     */
    public function maybe_notice_plain_permalinks() {
        if (empty(get_option('permalink_structure'))) {
            echo '<div class="notice notice-error"><p>'
               . '<strong>DataFlair:</strong> The Gutenberg block requires '
               . 'pretty permalinks. Go to <a href="' . admin_url('options-permalink.php') . '">'
               . 'Settings &rarr; Permalinks</a> and choose any option other than Plain.'
               . '</p></div>';
        }
    }

    /**
     * Plugin activation
     */
    public function activate() {
        global $wpdb;
        $table_name = $wpdb->prefix . DATAFLAIR_TABLE_NAME;
        $brands_table_name = $wpdb->prefix . DATAFLAIR_BRANDS_TABLE_NAME;
        $alternative_toplists_table = $wpdb->prefix . DATAFLAIR_ALTERNATIVE_TOPLISTS_TABLE_NAME;
        $charset_collate = $wpdb->get_charset_collate();
        
        // Check if JSON type is supported
        $supports_json = $this->supports_json_type();
        $data_type = $supports_json ? 'JSON' : 'longtext';
        
        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            api_toplist_id bigint(20) NOT NULL,
            name varchar(255) NOT NULL,
            slug varchar(255) DEFAULT NULL,
            current_period varchar(100) DEFAULT NULL,
            published_at datetime DEFAULT NULL,
            item_count int(11) NOT NULL DEFAULT 0,
            locked_count int(11) NOT NULL DEFAULT 0,
            sync_warnings text DEFAULT NULL,
            data $data_type NOT NULL,
            version varchar(50) DEFAULT NULL,
            last_synced datetime NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY api_toplist_id (api_toplist_id)
        ) $charset_collate;";
        
        $brands_sql = "CREATE TABLE IF NOT EXISTS $brands_table_name (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            api_brand_id bigint(20) NOT NULL,
            name varchar(255) NOT NULL,
            slug varchar(255) NOT NULL,
            status varchar(50) NOT NULL,
            product_types text,
            licenses text,
            top_geos text,
            offers_count int(11) DEFAULT 0,
            trackers_count int(11) DEFAULT 0,
            classification_types VARCHAR(500) NOT NULL DEFAULT '',
            data $data_type NOT NULL,
            last_synced datetime NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY api_brand_id (api_brand_id)
        ) $charset_collate;";
        
        $alternative_toplists_sql = "CREATE TABLE IF NOT EXISTS $alternative_toplists_table (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            toplist_id bigint(20) NOT NULL,
            geo varchar(255) NOT NULL,
            alternative_toplist_id bigint(20) NOT NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY toplist_geo (toplist_id, geo),
            KEY toplist_id (toplist_id),
            KEY alternative_toplist_id (alternative_toplist_id)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        dbDelta($brands_sql);
        dbDelta($alternative_toplists_sql);
        
        // Cron scheduling is handled by ensure_cron_scheduled() on 'init',
        // where the dataflair_15min custom schedule is already registered.
        // Scheduling here (during activation) happens before cron_schedules
        // filters run, so the custom interval would be silently ignored.
    }
    
    /**
     * Plugin deactivation
     */
    public function deactivate() {
        wp_clear_scheduled_hook('dataflair_sync_cron');
        wp_clear_scheduled_hook('dataflair_brands_sync_cron');
    }
    
    /**
     * Check and upgrade database schema if needed
     */
    public function check_database_upgrade() {
        $db_version = get_option('dataflair_db_version', '1.0');
        $current_version = '1.7'; // v1.7: add classification_types column to brands table

        if (version_compare($db_version, $current_version, '<')) {
            $this->upgrade_database();
            update_option('dataflair_db_version', $current_version);
        }

        // Migrate to JSON type if supported
        $this->migrate_to_json_type();
    }
    
    /**
     * Upgrade database schema
     */
    private function upgrade_database() {
        global $wpdb;
        $table_name             = $wpdb->prefix . DATAFLAIR_TABLE_NAME;
        $brands_table_name      = $wpdb->prefix . DATAFLAIR_BRANDS_TABLE_NAME;
        $charset_collate        = $wpdb->get_charset_collate();

        // ── Toplists table: add snapshot + integrity columns (v1.5) ──
        $tl_table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'") === $table_name;
        if ($tl_table_exists) {
            $tl_columns = $wpdb->get_col("DESCRIBE $table_name");

            if (!in_array('slug', $tl_columns)) {
                $wpdb->query("ALTER TABLE $table_name ADD COLUMN slug VARCHAR(255) DEFAULT NULL AFTER name");
            }
            if (!in_array('current_period', $tl_columns)) {
                $wpdb->query("ALTER TABLE $table_name ADD COLUMN current_period VARCHAR(100) DEFAULT NULL AFTER slug");
            } else {
                // v1.6: widen current_period from VARCHAR(7) to VARCHAR(100) for edition labels
                $wpdb->query("ALTER TABLE $table_name MODIFY COLUMN current_period VARCHAR(100) DEFAULT NULL");
            }
            if (!in_array('published_at', $tl_columns)) {
                $wpdb->query("ALTER TABLE $table_name ADD COLUMN published_at DATETIME DEFAULT NULL AFTER current_period");
            }
            if (!in_array('item_count', $tl_columns)) {
                $wpdb->query("ALTER TABLE $table_name ADD COLUMN item_count INT DEFAULT 0 AFTER published_at");
            }
            if (!in_array('locked_count', $tl_columns)) {
                $wpdb->query("ALTER TABLE $table_name ADD COLUMN locked_count INT DEFAULT 0 AFTER item_count");
            }
            if (!in_array('sync_warnings', $tl_columns)) {
                $wpdb->query("ALTER TABLE $table_name ADD COLUMN sync_warnings TEXT DEFAULT NULL AFTER locked_count");
            }

            // Add slug index if it doesn't already exist
            $idx = $wpdb->get_results("SHOW INDEX FROM $table_name WHERE Key_name = 'idx_slug'");
            if (empty($idx)) {
                $wpdb->query("CREATE INDEX idx_slug ON $table_name (slug)");
            }

            error_log('DataFlair: Toplists table upgraded to v1.5 (snapshot + integrity columns)');
        }

        // ── Brands table: add columns if missing (v1.2 compat) ──
        $brands_table_exists = $wpdb->get_var("SHOW TABLES LIKE '$brands_table_name'") === $brands_table_name;

        if ($brands_table_exists) {
            $columns = $wpdb->get_col("DESCRIBE $brands_table_name");

            if (!in_array('product_types', $columns)) {
                $wpdb->query("ALTER TABLE $brands_table_name ADD COLUMN product_types text AFTER status");
            }
            if (!in_array('licenses', $columns)) {
                $wpdb->query("ALTER TABLE $brands_table_name ADD COLUMN licenses text AFTER product_types");
            }
            if (!in_array('top_geos', $columns)) {
                $wpdb->query("ALTER TABLE $brands_table_name ADD COLUMN top_geos text AFTER licenses");
            }
            if (!in_array('offers_count', $columns)) {
                $wpdb->query("ALTER TABLE $brands_table_name ADD COLUMN offers_count int(11) DEFAULT 0 AFTER top_geos");
            }
            if (!in_array('trackers_count', $columns)) {
                $wpdb->query("ALTER TABLE $brands_table_name ADD COLUMN trackers_count int(11) DEFAULT 0 AFTER offers_count");
            }

            if (!in_array('classification_types', $columns)) {
                $wpdb->query("ALTER TABLE $brands_table_name ADD COLUMN classification_types VARCHAR(500) NOT NULL DEFAULT '' AFTER trackers_count");
            }

            error_log('DataFlair: Database schema upgraded to version 1.5');
        } else {
            // Table doesn't exist, create it with full schema
            $brands_sql = "CREATE TABLE IF NOT EXISTS $brands_table_name (
                id bigint(20) NOT NULL AUTO_INCREMENT,
                api_brand_id bigint(20) NOT NULL,
                name varchar(255) NOT NULL,
                slug varchar(255) NOT NULL,
                status varchar(50) NOT NULL,
                product_types text,
                licenses text,
                top_geos text,
                offers_count int(11) DEFAULT 0,
                trackers_count int(11) DEFAULT 0,
                classification_types VARCHAR(500) NOT NULL DEFAULT '',
                data longtext NOT NULL,
                last_synced datetime NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY api_brand_id (api_brand_id)
            ) $charset_collate;";

            require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
            dbDelta($brands_sql);

            error_log('DataFlair: Brands table created with full schema');
        }

        // Check if alternative toplists table exists, create if not
        $this->ensure_alternative_toplists_table();
    }
    
    /**
     * Ensure alternative toplists table exists
     */
    private function ensure_alternative_toplists_table() {
        global $wpdb;
        $alternative_toplists_table = $wpdb->prefix . DATAFLAIR_ALTERNATIVE_TOPLISTS_TABLE_NAME;
        $charset_collate = $wpdb->get_charset_collate();
        
        $alt_table_exists = $wpdb->get_var("SHOW TABLES LIKE '$alternative_toplists_table'") === $alternative_toplists_table;
        
        if (!$alt_table_exists) {
            $alternative_toplists_sql = "CREATE TABLE IF NOT EXISTS $alternative_toplists_table (
                id bigint(20) NOT NULL AUTO_INCREMENT,
                toplist_id bigint(20) NOT NULL,
                geo varchar(255) NOT NULL,
                alternative_toplist_id bigint(20) NOT NULL,
                created_at datetime NOT NULL,
                updated_at datetime NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY toplist_geo (toplist_id, geo),
                KEY toplist_id (toplist_id),
                KEY alternative_toplist_id (alternative_toplist_id)
            ) $charset_collate;";
            
            require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
            dbDelta($alternative_toplists_sql);
            
            error_log('DataFlair: Alternative toplists table created');
        }
    }
    
    /**
     * Add custom cron schedules
     */
    public function add_custom_cron_schedules($schedules) {
        $schedules['dataflair_15min'] = array(
            'interval' => 900, // 15 minutes in seconds
            'display' => __('Every 15 Minutes', 'dataflair-toplists')
        );
        return $schedules;
    }

    /**
     * Ensure cron jobs are correctly scheduled.
     *
     * Called on 'init' so that the dataflair_15min custom schedule is already
     * registered (via the cron_schedules filter above) before we call
     * wp_schedule_event(). The activate() hook fires too early for custom
     * schedules to be recognised, which causes the brands cron to be stored
     * with an unknown interval and never fire. This method self-heals that.
     */
    public function ensure_cron_scheduled() {
        // Toplists cron — twice daily (built-in schedule, safe from activation too)
        if ( ! wp_next_scheduled( 'dataflair_sync_cron' ) ) {
            wp_schedule_event( time(), 'twicedaily', 'dataflair_sync_cron' );
        }

        // Brands cron — every 15 minutes using our custom schedule.
        // If the event exists but is registered with a different/unknown recurrence
        // (the activation-time bug), clear and reschedule it correctly.
        $next = wp_next_scheduled( 'dataflair_brands_sync_cron' );
        if ( ! $next ) {
            wp_schedule_event( time(), 'dataflair_15min', 'dataflair_brands_sync_cron' );
        } else {
            // Detect wrong recurrence: fetch the cron array and verify the schedule name
            $crons = _get_cron_array();
            $correct = false;
            foreach ( $crons as $timestamp => $hooks ) {
                if ( isset( $hooks['dataflair_brands_sync_cron'] ) ) {
                    foreach ( $hooks['dataflair_brands_sync_cron'] as $event ) {
                        if ( isset( $event['schedule'] ) && $event['schedule'] === 'dataflair_15min' ) {
                            $correct = true;
                        }
                    }
                }
            }
            if ( ! $correct ) {
                // Wrong schedule stored — clear and reschedule with the correct one
                wp_clear_scheduled_hook( 'dataflair_brands_sync_cron' );
                wp_schedule_event( time(), 'dataflair_15min', 'dataflair_brands_sync_cron' );
            }
        }
    }
    
    /**
     * Add admin menu
     */
    public function add_admin_menu() {
        add_menu_page(
            'DataFlair',
            'DataFlair',
            'manage_options',
            'dataflair-toplists',
            array($this, 'settings_page'),
            'dashicons-list-view',
            30
        );
        
        // Add Toplists submenu (this will replace the default first submenu item)
        add_submenu_page(
            'dataflair-toplists',
            'Toplists',
            'Toplists',
            'manage_options',
            'dataflair-toplists',
            array($this, 'settings_page')
        );
        
        add_submenu_page(
            'dataflair-toplists',
            'Brands',
            'Brands',
            'manage_options',
            'dataflair-brands',
            array($this, 'brands_page')
        );
        
        // Add Tests submenu (only for development)
        if (defined('WP_DEBUG') && WP_DEBUG) {
            add_submenu_page(
                'dataflair-toplists',
                'Tests',
                'Tests',
                'manage_options',
                'dataflair-tests',
                array($this, 'tests_page')
            );
        }
        
    }
    
    /**
     * Tests page
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
    
    /**
     * Register settings
     */
    public function register_settings() {
        $args = array(
            'sanitize_callback' => null,
            'default' => null,
        );
        
        register_setting('dataflair_settings', 'dataflair_api_token', $args);
        register_setting('dataflair_settings', 'dataflair_api_base_url', $args);
        // Keep dataflair_api_endpoints for internal use (populated by fetch_all_toplists)
        register_setting('dataflair_settings', 'dataflair_api_endpoints', $args);
        // API base URL - can be auto-detected or manually set
        register_setting('dataflair_settings', 'dataflair_api_base_url', $args);

        // HTTP Basic Auth for staging/protected environments
        register_setting('dataflair_settings', 'dataflair_http_auth_user', $args);
        register_setting('dataflair_settings', 'dataflair_http_auth_pass', $args);

        // Default customization settings
        register_setting('dataflair_settings', 'dataflair_ribbon_bg_color', $args);
        register_setting('dataflair_settings', 'dataflair_ribbon_text_color', $args);
        register_setting('dataflair_settings', 'dataflair_cta_bg_color', $args);
        register_setting('dataflair_settings', 'dataflair_cta_text_color', $args);
        
    }
    
    /**
     * AJAX save settings handler
     */
    public function ajax_save_settings() {
        check_ajax_referer('dataflair_save_settings', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Unauthorized'));
        }
        
        // Save API settings
        if (isset($_POST['dataflair_api_token'])) {
            // Trim whitespace and preserve token value - don't use sanitize_text_field as it may modify special characters
            $token = trim($_POST['dataflair_api_token']);
            update_option('dataflair_api_token', $token);
        }
        
        // Save API base URL if manually set
        if (isset($_POST['dataflair_api_base_url'])) {
            $base_url = trim($_POST['dataflair_api_base_url']);
            if (!empty($base_url)) {
                // Clean the URL: remove trailing slashes, sanitize
                $base_url = rtrim(esc_url_raw($base_url), '/');
                // Strip any path segments after /api/v1 (e.g. /api/v1/toplists → /api/v1)
                $base_url = preg_replace('#(/api/v\d+)/.*$#', '$1', $base_url);
                update_option('dataflair_api_base_url', $base_url);
            } else {
                // Empty = clear the stored value so auto-detect kicks in
                delete_option('dataflair_api_base_url');
            }
        }

        // Save HTTP Basic Auth credentials (for staging environments)
        if (isset($_POST['dataflair_http_auth_user'])) {
            update_option('dataflair_http_auth_user', sanitize_text_field($_POST['dataflair_http_auth_user']));
        }
        if (isset($_POST['dataflair_http_auth_pass'])) {
            // Don't sanitize password — it may contain special characters
            update_option('dataflair_http_auth_pass', trim($_POST['dataflair_http_auth_pass']));
        }

        // Save Brands API version (v1 or v2 only — default v1 for safety)
        $version = isset($_POST['dataflair_brands_api_version'])
            && $_POST['dataflair_brands_api_version'] === 'v2' ? 'v2' : 'v1';
        update_option('dataflair_brands_api_version', $version);

        // Save customization settings
        if (isset($_POST['dataflair_ribbon_bg_color'])) {
            update_option('dataflair_ribbon_bg_color', sanitize_text_field($_POST['dataflair_ribbon_bg_color']));
        }
        
        if (isset($_POST['dataflair_ribbon_text_color'])) {
            update_option('dataflair_ribbon_text_color', sanitize_text_field($_POST['dataflair_ribbon_text_color']));
        }
        
        if (isset($_POST['dataflair_cta_bg_color'])) {
            update_option('dataflair_cta_bg_color', sanitize_text_field($_POST['dataflair_cta_bg_color']));
        }
        
        if (isset($_POST['dataflair_cta_text_color'])) {
            update_option('dataflair_cta_text_color', sanitize_text_field($_POST['dataflair_cta_text_color']));
        }
        
        wp_send_json_success(array('message' => 'Settings saved successfully.'));
    }
    
    /**
     * Enqueue admin scripts
     */
    public function enqueue_admin_scripts($hook) {
        // Only load on our settings pages
        if ($hook !== 'toplevel_page_dataflair-toplists' && $hook !== 'dataflair_page_dataflair-brands') {
            return;
        }
        
        // Enqueue Select2 CSS and JS (brands page + toplists page)
        if ($hook === 'dataflair_page_dataflair-brands' || $hook === 'toplevel_page_dataflair-toplists') {
            // Select2 CSS
            wp_enqueue_style(
                'select2',
                'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css',
                array(),
                '4.1.0'
            );
            
            // Select2 JS
            wp_enqueue_script(
                'select2',
                'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js',
                array('jquery'),
                '4.1.0',
                true
            );
        }
        
        // Use file modification time as version to prevent caching issues
        $admin_js_version = file_exists(DATAFLAIR_PLUGIN_DIR . 'assets/admin.js') 
            ? filemtime(DATAFLAIR_PLUGIN_DIR . 'assets/admin.js') 
            : DATAFLAIR_VERSION;
        
        wp_enqueue_script(
            'dataflair-admin',
            DATAFLAIR_PLUGIN_URL . 'assets/admin.js',
            array('jquery'),
            $admin_js_version,
            true
        );
        
        wp_localize_script('dataflair-admin', 'dataflairAdmin', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('dataflair_save_settings'),
            'fetchNonce' => wp_create_nonce('dataflair_fetch_all_toplists'),
            'fetchBrandsNonce' => wp_create_nonce('dataflair_fetch_all_brands'),
            'syncBrandsBatchNonce' => wp_create_nonce('dataflair_sync_brands_batch')
        ));
    }
    
    /**
     * Settings page HTML
     */
    public function settings_page() {
        global $wpdb;
        $table_name = $wpdb->prefix . DATAFLAIR_TABLE_NAME;
        
        // Get synced toplists
        $toplists = $wpdb->get_results("SELECT * FROM $table_name ORDER BY api_toplist_id ASC");
        
        // Get current tab from URL
        $current_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'api';
        
        ?>
        <div class="wrap">
            <h1>DataFlair Toplists Settings</h1>
            
            <?php settings_errors(); ?>
            
            <nav class="nav-tab-wrapper">
                <a href="?page=dataflair-toplists&tab=api" class="nav-tab <?php echo $current_tab === 'api' ? 'nav-tab-active' : ''; ?>">
                    API Settings
                </a>
                <a href="?page=dataflair-toplists&tab=customizations" class="nav-tab <?php echo $current_tab === 'customizations' ? 'nav-tab-active' : ''; ?>">
                    Customizations
                </a>
                <a href="?page=dataflair-toplists&tab=api_preview" class="nav-tab <?php echo $current_tab === 'api_preview' ? 'nav-tab-active' : ''; ?>">
                    API Preview
                </a>
            </nav>
            
            <form id="dataflair-settings-form">
                
                <?php if ($current_tab === 'api'): ?>
                    <!-- API Settings Tab -->
                    <style>
                        #dataflair_api_base_url::placeholder {
                            color: #d3d3d3;
                        }
                        #dataflair_api_base_url::-webkit-input-placeholder {
                            color: #d3d3d3;
                        }
                        #dataflair_api_base_url::-moz-placeholder {
                            color: #d3d3d3;
                            opacity: 1;
                        }
                        #dataflair_api_base_url:-ms-input-placeholder {
                            color: #d3d3d3;
                        }
                    </style>
                    <div class="tab-content">
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="dataflair_api_token">API Bearer Token</label>
                        </th>
                        <td>
                            <input type="text" 
                                   id="dataflair_api_token" 
                                   name="dataflair_api_token" 
                                   value="<?php echo esc_attr(get_option('dataflair_api_token')); ?>" 
                                   class="regular-text">
                            <p class="description">Your DataFlair API bearer token</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="dataflair_api_base_url">API Base URL</label>
                        </th>
                        <td>
                            <input type="text"
                                   id="dataflair_api_base_url"
                                   name="dataflair_api_base_url"
                                   value="<?php echo esc_attr(get_option('dataflair_api_base_url', '')); ?>"
                                   class="regular-text"
                                   placeholder="https://tenant.dataflair.ai/api/v1">
                            <p class="description">
                                Your DataFlair API base URL (e.g., https://tenant.dataflair.ai/api/v1).
                                Leave empty to auto-detect from token or stored endpoints.
                                <?php
                                $current_base = get_option('dataflair_api_base_url');
                                if (!empty($current_base)) {
                                    echo '<br><strong>Current: ' . esc_html($current_base) . '</strong>';
                                }
                                ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Brands API Version</th>
                        <td>
                            <label>
                                <input type="radio" name="dataflair_brands_api_version"
                                       value="v1" <?php checked(get_option('dataflair_brands_api_version','v1'), 'v1'); ?>>
                                V1 <span style="color:#646970;">(default)</span>
                            </label>
                            &nbsp;&nbsp;
                            <label>
                                <input type="radio" name="dataflair_brands_api_version"
                                       value="v2" <?php checked(get_option('dataflair_brands_api_version','v1'), 'v2'); ?>>
                                V2
                            </label>
                            <p class="description">
                                V2 includes classificationTypes, 15 multi-vertical brand fields
                                (sports, poker, sweeps-coins) and unified offer types.
                                Requires DataFlair API &ge; v2.
                            </p>
                        </td>
                    </tr>
                </table>

                <?php submit_button('Save Settings', 'primary', 'submit', false, array('id' => 'dataflair-save-settings')); ?>
                <span id="dataflair-save-message" style="margin-left: 10px;"></span>
                    </div>

            <hr>

            <h2>Sync Toplists</h2>
                    <button type="button" id="dataflair-fetch-all-toplists" class="button button-primary">
                        Fetch All Toplists from API
                    </button>
                    <span id="dataflair-fetch-message" style="margin-left: 10px;"></span>
                    <p class="description">Fetches all toplists from the DataFlair API. Existing toplists will be updated.</p>
                    <p class="description">Auto-sync runs twice daily. <?php echo $this->get_last_cron_time(); ?></p>
            
            <hr>
            
            <h2>Synced Toplists</h2>
            <?php if ($toplists): ?>
                <?php
                // Collect unique template names for the filter dropdown
                $all_templates = array();
                foreach ($toplists as $tl) {
                    $tl_data = json_decode($tl->data, true);
                    $tname = isset($tl_data['data']['template']['name']) ? $tl_data['data']['template']['name'] : '';
                    if ($tname) $all_templates[] = $tname;
                }
                $all_templates = array_values(array_unique($all_templates));
                sort($all_templates);
                ?>

                <!-- Template filter -->
                <div class="dataflair-filters" style="margin-bottom: 16px;">
                    <div class="filter-row">
                        <div class="filter-group" style="max-width: 320px;">
                            <label style="font-weight:600;">Template</label>
                            <select id="dataflair-filter-template" class="dataflair-toplist-select2" style="width:100%;">
                                <?php foreach ($all_templates as $tname): ?>
                                    <option value="<?php echo esc_attr($tname); ?>"><?php echo esc_html($tname); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="filter-group filter-actions" style="flex:0; align-self:flex-end;">
                            <button type="button" id="dataflair-clear-toplist-filters" class="button">Clear</button>
                        </div>
                        <div class="filter-group" style="flex:0; align-self:flex-end; white-space:nowrap;">
                            <span id="dataflair-toplists-count" style="color:#646970;">Showing <?php echo count($toplists); ?> toplists</span>
                        </div>
                    </div>
                </div>

                <table class="wp-list-table widefat fixed striped dataflair-toplists-table">
                    <thead>
                        <tr>
                            <th style="width: 40px;"></th>
                            <th>WP ID</th>
                            <th>API ID</th>
                            <th>Name</th>
                            <th>Slug</th>
                            <th class="sortable-toplist">
                                <a href="#" class="toplist-sort-link" data-sort="template">Template <span class="toplist-sort-indicator"></span></a>
                            </th>
                            <th>Period</th>
                            <th>Version</th>
                            <th class="sortable-toplist">
                                <a href="#" class="toplist-sort-link" data-sort="items">Items <span class="toplist-sort-indicator"></span></a>
                            </th>
                            <th>Locked</th>
                            <th>Sync Health</th>
                            <th class="sortable-toplist">
                                <a href="#" class="toplist-sort-link" data-sort="last_synced">Last Synced <span class="toplist-sort-indicator"></span></a>
                            </th>
                            <th>Shortcode</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($toplists as $toplist):
                            $data = json_decode($toplist->data, true);
                            $template_name = isset($data['data']['template']['name']) ? $data['data']['template']['name'] : '';

                            // Prefer extracted columns; fall back to decoding JSON (for rows synced before v1.5)
                            $items_count  = isset($toplist->item_count)   ? (int) $toplist->item_count   : (isset($data['data']['items']) ? count($data['data']['items']) : 0);
                            $locked_count = isset($toplist->locked_count) ? (int) $toplist->locked_count : 0;

                            // Sync health
                            $sync_warnings_raw  = isset($toplist->sync_warnings) ? $toplist->sync_warnings : null;
                            $sync_warnings_arr  = (!empty($sync_warnings_raw)) ? json_decode($sync_warnings_raw, true) : [];
                            $warning_count      = is_array($sync_warnings_arr) ? count($sync_warnings_arr) : 0;
                            $last_synced_ts     = $toplist->last_synced ? strtotime($toplist->last_synced) : 0;
                            $is_stale           = $last_synced_ts && (time() - $last_synced_ts) > 3600;

                            if ($is_stale) {
                                $health_html = '<span style="color:#d63638;" title="Last sync was over 1 hour ago">&#128308; Stale</span>';
                            } elseif ($warning_count > 0) {
                                $health_html = '<a href="#" class="toplist-warnings-toggle" data-toplist-id="' . esc_attr($toplist->id) . '" style="color:#dba617; text-decoration:none;" title="Click to view warnings">&#9888;&#65039; ' . $warning_count . ' warning' . ($warning_count !== 1 ? 's' : '') . '</a>';
                            } else {
                                $health_html = '<span style="color:#00a32a;" title="All data validated OK">&#9989;</span>';
                            }
                        ?>
                        <tr class="toplist-row"
                            data-toplist-id="<?php echo esc_attr($toplist->id); ?>"
                            data-template="<?php echo esc_attr($template_name); ?>"
                            data-items="<?php echo esc_attr($items_count); ?>"
                            data-last-synced="<?php echo esc_attr($toplist->last_synced); ?>">
                            <td>
                                <button type="button" class="toplist-toggle-btn" title="View Details">
                                    <span class="dashicons dashicons-arrow-right"></span>
                                </button>
                            </td>
                            <td><?php echo esc_html($toplist->id); ?></td>
                            <td><?php echo esc_html($toplist->api_toplist_id); ?></td>
                            <td><?php echo esc_html($toplist->name); ?></td>
                            <td><?php echo isset($toplist->slug) && !empty($toplist->slug) ? '<code>' . esc_html($toplist->slug) . '</code>' : '<span style="color:#999;">—</span>'; ?></td>
                            <td><?php echo esc_html($template_name ?: '—'); ?></td>
                            <td><?php echo isset($toplist->current_period) && !empty($toplist->current_period) ? esc_html($toplist->current_period) : '<span style="color:#999;">—</span>'; ?></td>
                            <td><?php echo esc_html($toplist->version); ?></td>
                            <td><?php echo esc_html($items_count); ?></td>
                            <td><?php echo esc_html($locked_count); ?></td>
                            <td><?php echo $health_html; ?></td>
                            <td><?php echo esc_html($toplist->last_synced); ?></td>
                            <td>
                                <code>[dataflair_toplist id="<?php echo esc_attr($toplist->api_toplist_id); ?>"]</code>
                            </td>
                        </tr>
                        <?php if ($warning_count > 0): ?>
                        <tr class="toplist-warnings-row" id="warnings-<?php echo esc_attr($toplist->id); ?>" style="display:none;">
                            <td colspan="13" style="padding: 0;">
                                <div style="padding: 12px 20px; background: #fff8e5; border-left: 4px solid #dba617;">
                                    <strong style="color:#dba617;">&#9888;&#65039; Sync warnings for <?php echo esc_html($toplist->name); ?>:</strong>
                                    <ul style="margin: 8px 0 0 20px; padding: 0;">
                                        <?php foreach ($sync_warnings_arr as $w): ?>
                                        <li style="font-family: monospace; font-size: 12px; margin: 2px 0;"><?php echo esc_html($w); ?></li>
                                        <?php endforeach; ?>
                                    </ul>
                                </div>
                            </td>
                        </tr>
                        <?php endif; ?>
                        <tr class="toplist-accordion-content" data-toplist-id="<?php echo esc_attr($toplist->id); ?>" style="display: none;">
                            <td colspan="13" style="padding: 0;">
                                <div class="toplist-accordion-inner" style="padding: 20px; background: #f9f9f9; border-left: 4px solid #0073aa;">
                                    <h3 style="margin-top: 0;">Alternative Toplists for Different Geos</h3>
                                    <p class="description">Set alternative toplists to show when a user from a specific geo visits a page where this toplist is displayed.</p>
                                    
                                    <div class="alternative-toplists-list"></div>
                                    
                                    <div class="add-alternative-toplist" style="margin-top: 20px; padding: 15px; background: white; border: 1px solid #ddd;">
                                        <h4 style="margin-top: 0;">Add Alternative Toplist</h4>
                                        <table class="form-table" style="margin: 0;">
                                            <tr>
                                                <th scope="row"><label>Geo / Market</label></th>
                                                <td>
                                                    <select class="alt-geo-select" style="min-width: 200px;">
                                                        <option value="">Select a geo...</option>
                                                    </select>
                                                    <p class="description">Select the geo/market for which to show an alternative toplist.</p>
                                                </td>
                                            </tr>
                                            <tr>
                                                <th scope="row"><label>Alternative Toplist</label></th>
                                                <td>
                                                    <select class="alt-toplist-select" style="min-width: 300px;">
                                                        <option value="">Select a toplist...</option>
                                                        <?php foreach ($toplists as $alt_toplist): ?>
                                                            <option value="<?php echo esc_attr($alt_toplist->id); ?>">
                                                                <?php echo esc_html($alt_toplist->name); ?> (ID: <?php echo esc_html($alt_toplist->api_toplist_id); ?>)
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                    <p class="description">Select which toplist to show for users from the selected geo.</p>
                                                </td>
                                            </tr>
                                        </table>
                                        <button type="button" class="button button-primary save-alternative-toplist">Add Alternative</button>
                                        <span class="alt-save-message" style="margin-left: 10px;"></span>
                                    </div>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p>No toplists synced yet. Click "Fetch All Toplists from API" to discover and sync available toplists.</p>
            <?php endif; ?>
            
            <hr>
            
            <h2>Shortcode Usage</h2>
            <p>Use the shortcode with these parameters:</p>
            <ul>
                <li><strong>id</strong> (required unless <em>slug</em> is used): API toplist ID</li>
                <li><strong>slug</strong> (optional): Toplist slug (alternative to id)</li>
                <li><strong>title</strong> (optional): Custom title for the toplist</li>
                <li><strong>limit</strong> (optional): Number of casinos to show (default: all)</li>
            </ul>
            <p>By ID: <code>[dataflair_toplist id="3" title="Best UK Casinos" limit="5"]</code></p>
            <p>By slug: <code>[dataflair_toplist slug="brazil-casinos"]</code></p>

            <script>
            jQuery(document).ready(function($) {
                $(document).on('click', '.toplist-warnings-toggle', function(e) {
                    e.preventDefault();
                    var id = $(this).data('toplist-id');
                    $('#warnings-' + id).toggle();
                });
            });
            </script>
                    
                <?php elseif ($current_tab === 'customizations'): ?>
                    <!-- Customizations Tab -->
                    <div class="tab-content">
                        <h2>Default Customization Options</h2>
                        <p class="description">These settings will be used as defaults for all toplist blocks. You can override them per block in the block settings. Use Tailwind CSS color classes (e.g., "brand-600", "blue-500", "bg-[#ff0000]").</p>
                        
                        <table class="form-table">
                            <tr>
                                <th scope="row">
                                    <label for="dataflair_ribbon_bg_color">Ribbon Background Color</label>
                                </th>
                                <td>
                                    <input type="text" 
                                           id="dataflair_ribbon_bg_color" 
                                           name="dataflair_ribbon_bg_color" 
                                           value="<?php echo esc_attr(get_option('dataflair_ribbon_bg_color', 'brand-600')); ?>" 
                                           class="regular-text"
                                           placeholder="brand-600">
                                    <p class="description">Default background color for the "Our Top Choice" ribbon (e.g., "brand-600", "blue-500", "bg-[#ff0000]")</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="dataflair_ribbon_text_color">Ribbon Text Color</label>
                                </th>
                                <td>
                                    <input type="text" 
                                           id="dataflair_ribbon_text_color" 
                                           name="dataflair_ribbon_text_color" 
                                           value="<?php echo esc_attr(get_option('dataflair_ribbon_text_color', 'white')); ?>" 
                                           class="regular-text"
                                           placeholder="white">
                                    <p class="description">Default text color for the ribbon (e.g., "white", "gray-900", "text-[#ffffff]")</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="dataflair_cta_bg_color">CTA Button Background Color</label>
                                </th>
                                <td>
                                    <input type="text" 
                                           id="dataflair_cta_bg_color" 
                                           name="dataflair_cta_bg_color" 
                                           value="<?php echo esc_attr(get_option('dataflair_cta_bg_color', 'brand-600')); ?>" 
                                           class="regular-text"
                                           placeholder="brand-600">
                                    <p class="description">Default background color for the "Visit Site" button (e.g., "brand-600", "green-500", "bg-[#00ff00]")</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="dataflair_cta_text_color">CTA Button Text Color</label>
                                </th>
                                <td>
                                    <input type="text" 
                                           id="dataflair_cta_text_color" 
                                           name="dataflair_cta_text_color" 
                                           value="<?php echo esc_attr(get_option('dataflair_cta_text_color', 'white')); ?>" 
                                           class="regular-text"
                                           placeholder="white">
                                    <p class="description">Default text color for the CTA button (e.g., "white", "gray-900", "text-[#ffffff]")</p>
                                </td>
                            </tr>
                        </table>
                        
                        <?php submit_button('Save Settings', 'primary', 'submit', false, array('id' => 'dataflair-save-settings-custom')); ?>
                        <span id="dataflair-save-message-custom" style="margin-left: 10px;"></span>
                    </div>
                <?php elseif ($current_tab === 'api_preview'): ?>
                    <!-- API Preview Tab -->
                    <div class="tab-content">
                        <h2>API Response Preview</h2>
                        <p class="description">Fetch a live response from the DataFlair API using your stored token. Select an endpoint and click <strong>Fetch Preview</strong> to inspect the raw JSON.</p>

                        <?php
                        $token = trim(get_option('dataflair_api_token', ''));
                        $base_url = $this->get_api_base_url();
                        if (empty($token)): ?>
                            <div class="notice notice-warning inline"><p>No API token configured. Set your token on the <a href="?page=dataflair-toplists&tab=api">API Settings</a> tab first.</p></div>
                        <?php else: ?>
                        <!-- Mode toggle -->
                        <div style="margin-bottom:16px;">
                            <label>
                                <input type="radio" name="df-preview-mode" value="single" checked> Single endpoint
                            </label>
                            &nbsp;&nbsp;
                            <label>
                                <input type="radio" name="df-preview-mode" value="compare"> Compare V1 vs V2
                            </label>
                        </div>
                        <!-- Single mode panel -->
                        <div id="df-single-panel">
                        <table class="form-table">
                            <tr>
                                <th scope="row"><label for="df-preview-endpoint">Endpoint</label></th>
                                <td>
                                    <select id="df-preview-endpoint" style="min-width:320px;">
                                        <option value="toplists">GET /toplists (list all)</option>
                                        <option value="toplists/custom">GET /toplists/{id} (single — enter ID below)</option>
                                        <option value="brands">GET /brands (list all)</option>
                                        <option value="brands/custom">GET /brands/{id} (single — enter ID below)</option>
                                    </select>
                                </td>
                            </tr>
                            <tr id="df-preview-id-row" style="display:none;">
                                <th scope="row"><label for="df-preview-id">Resource ID</label></th>
                                <td><input type="number" id="df-preview-id" class="small-text" placeholder="42"></td>
                            </tr>
                            <tr>
                                <th scope="row"></th>
                                <td>
                                    <button type="button" id="df-preview-fetch" class="button button-primary">Fetch Preview</button>
                                    <span id="df-preview-status" style="margin-left:10px;"></span>
                                </td>
                            </tr>
                        </table>
                        </div><!-- /#df-single-panel -->
                        <!-- Compare mode panel -->
                        <div id="df-compare-panel" style="display:none;">
                            <table class="form-table">
                                <tr>
                                    <th scope="row">Endpoint</th>
                                    <td>
                                        <select id="df-compare-endpoint">
                                            <option value="brands">brands</option>
                                        </select>
                                        <button type="button" id="df-compare-run" class="button button-primary">Run Comparison</button>
                                        <span id="df-compare-status" style="margin-left:10px;"></span>
                                    </td>
                                </tr>
                            </table>
                            <div id="df-compare-result" style="display:none; margin-top:16px;">
                                <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px;">
                                    <div>
                                        <strong id="df-v1-label" style="font-family:monospace;font-size:12px;color:#666;"></strong>
                                        <pre id="df-v1-json" style="background:#1e1e1e;color:#d4d4d4;padding:16px;border-radius:4px;max-height:400px;overflow:auto;font-size:11px;white-space:pre-wrap;word-break:break-all;margin:6px 0;"></pre>
                                        <button type="button" class="button button-secondary button-small df-copy-btn" data-target="df-v1-json">Copy V1</button>
                                    </div>
                                    <div>
                                        <strong id="df-v2-label" style="font-family:monospace;font-size:12px;color:#666;"></strong>
                                        <pre id="df-v2-json" style="background:#1e1e1e;color:#d4d4d4;padding:16px;border-radius:4px;max-height:400px;overflow:auto;font-size:11px;white-space:pre-wrap;word-break:break-all;margin:6px 0;"></pre>
                                        <button type="button" class="button button-secondary button-small df-copy-btn" data-target="df-v2-json">Copy V2</button>
                                    </div>
                                </div>
                                <div id="df-compare-diff" style="margin-top:12px; padding:12px; background:#f0f7ff; border-left:4px solid #0073aa; font-size:12px;"></div>
                            </div>
                        </div><!-- /#df-compare-panel -->

                        <div id="df-preview-result" style="display:none;margin-top:16px;">
                            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;">
                                <strong id="df-preview-url-label" style="font-family:monospace;font-size:12px;color:#666;"></strong>
                                <button type="button" id="df-preview-copy" class="button button-secondary button-small">Copy JSON</button>
                            </div>
                            <pre id="df-preview-json" style="
                                background:#1e1e1e;
                                color:#d4d4d4;
                                padding:16px;
                                border-radius:4px;
                                max-height:600px;
                                overflow:auto;
                                font-size:12px;
                                line-height:1.5;
                                white-space:pre-wrap;
                                word-break:break-all;
                            "></pre>
                        </div>

                        <script>
                        (function($){
                            var nonce = '<?php echo wp_create_nonce('dataflair_api_preview'); ?>';
                            var $endpoint = $('#df-preview-endpoint');
                            var $idRow    = $('#df-preview-id-row');
                            var $idInput  = $('#df-preview-id');
                            var $fetch    = $('#df-preview-fetch');
                            var $status   = $('#df-preview-status');
                            var $result   = $('#df-preview-result');
                            var $json     = $('#df-preview-json');
                            var $urlLabel = $('#df-preview-url-label');
                            var $copy     = $('#df-preview-copy');

                            $endpoint.on('change', function(){
                                var v = $(this).val();
                                $idRow.toggle(v === 'toplists/custom' || v === 'brands/custom');
                            });

                            $fetch.on('click', function(){
                                var ep = $endpoint.val();
                                var resourceId = $idInput.val().trim();

                                if ((ep === 'toplists/custom' || ep === 'brands/custom') && !resourceId) {
                                    alert('Please enter a resource ID.');
                                    return;
                                }

                                $fetch.prop('disabled', true);
                                $status.text('Fetching…');
                                $result.hide();

                                $.post(ajaxurl, {
                                    action:      'dataflair_api_preview',
                                    _ajax_nonce: nonce,
                                    endpoint:    ep,
                                    resource_id: resourceId
                                }, function(res){
                                    $fetch.prop('disabled', false);
                                    if (res.success) {
                                        var elapsed = res.data.elapsed ? '  ' + res.data.elapsed : '';
                                        $status.css('color','green').text('✔ ' + res.data.status + elapsed);
                                        $urlLabel.text(res.data.url);
                                        $json.text(res.data.body);
                                        $result.show();
                                    } else {
                                        $status.css('color','red').text('✖ ' + (res.data || 'Unknown error'));
                                    }
                                }).fail(function(){
                                    $fetch.prop('disabled', false);
                                    $status.css('color','red').text('✖ AJAX request failed');
                                });
                            });

                            $copy.on('click', function(){
                                var text = $json.text();
                                navigator.clipboard.writeText(text).then(function(){
                                    $copy.text('Copied!');
                                    setTimeout(function(){ $copy.text('Copy JSON'); }, 2000);
                                });
                            });

                            // Mode toggle: single vs compare
                            $('input[name="df-preview-mode"]').on('change', function(){
                                var mode = $(this).val();
                                if (mode === 'compare') {
                                    $('#df-single-panel').hide();
                                    $('#df-compare-panel').show();
                                } else {
                                    $('#df-single-panel').show();
                                    $('#df-compare-panel').hide();
                                }
                            });

                            // Copy buttons in compare panel
                            $(document).on('click', '.df-copy-btn', function(){
                                var targetId = $(this).data('target');
                                var text = $('#' + targetId).text();
                                var $btn = $(this);
                                navigator.clipboard.writeText(text).then(function(){
                                    $btn.text('Copied!');
                                    setTimeout(function(){ $btn.text($btn.data('target') === 'df-v1-json' ? 'Copy V1' : 'Copy V2'); }, 2000);
                                });
                            });

                            // Run comparison
                            $('#df-compare-run').on('click', function(){
                                var $btn = $(this);
                                var $status = $('#df-compare-status');
                                $btn.prop('disabled', true);
                                $status.text('Fetching V1…');
                                $('#df-compare-result').hide();

                                // Call V1 (brands)
                                $.post(ajaxurl, {
                                    action: 'dataflair_api_preview',
                                    _ajax_nonce: nonce,
                                    endpoint: 'brands'
                                }, function(v1res){
                                    if (!v1res.success) {
                                        $status.css('color','red').text('✖ V1 failed: ' + (v1res.data || 'error'));
                                        $btn.prop('disabled', false);
                                        return;
                                    }
                                    $status.text('Fetching V2…');

                                    // Call V2 (brands_v2)
                                    $.post(ajaxurl, {
                                        action: 'dataflair_api_preview',
                                        _ajax_nonce: nonce,
                                        endpoint: 'brands_v2'
                                    }, function(v2res){
                                        $btn.prop('disabled', false);
                                        if (!v2res.success) {
                                            $status.css('color','red').text('✖ V2 failed: ' + (v2res.data || 'error'));
                                            return;
                                        }

                                        // Display results
                                        var v1elapsed = v1res.data.elapsed ? '  ' + v1res.data.elapsed : '';
                                        var v2elapsed = v2res.data.elapsed ? '  ' + v2res.data.elapsed : '';
                                        $('#df-v1-label').text('GET ' + v1res.data.url + '  ✔ ' + v1res.data.status + v1elapsed);
                                        $('#df-v2-label').text('GET ' + v2res.data.url + '  ✔ ' + v2res.data.status + v2elapsed);
                                        $('#df-v1-json').text(v1res.data.body);
                                        $('#df-v2-json').text(v2res.data.body);

                                        // Compute field diff
                                        var diffHtml = '';
                                        try {
                                            var v1data = JSON.parse(v1res.data.body);
                                            var v2data = JSON.parse(v2res.data.body);
                                            var v1brand = (v1data.data && v1data.data[0]) ? v1data.data[0] : null;
                                            var v2brand = (v2data.data && v2data.data[0]) ? v2data.data[0] : null;
                                            if (v1brand && v2brand) {
                                                var v1keys = Object.keys(v1brand);
                                                var v2keys = Object.keys(v2brand);
                                                var brandOnlyV2 = v2keys.filter(function(k){ return v1keys.indexOf(k) === -1; });
                                                diffHtml += '<strong>Fields only in V2 (brand):</strong> ' + (brandOnlyV2.length ? brandOnlyV2.join(', ') : 'none') + '<br>';

                                                var v1offer = (v1brand.offers && v1brand.offers[0]) ? v1brand.offers[0] : null;
                                                var v2offer = (v2brand.offers && v2brand.offers[0]) ? v2brand.offers[0] : null;
                                                if (v1offer && v2offer) {
                                                    var v1offerKeys = Object.keys(v1offer);
                                                    var v2offerKeys = Object.keys(v2offer);
                                                    var offerOnlyV2 = v2offerKeys.filter(function(k){ return v1offerKeys.indexOf(k) === -1; });
                                                    diffHtml += '<strong>Fields only in V2 (offer):</strong> ' + (offerOnlyV2.length ? offerOnlyV2.join(', ') : 'none');
                                                }
                                            }
                                        } catch(e) {
                                            diffHtml = 'Could not compute diff: ' + e.message;
                                        }
                                        $('#df-compare-diff').html(diffHtml || 'No differences found.');
                                        $('#df-compare-result').show();
                                        $status.css('color','green').text('✔ Comparison complete');
                                    }).fail(function(){
                                        $btn.prop('disabled', false);
                                        $status.css('color','red').text('✖ V2 AJAX request failed');
                                    });
                                }).fail(function(){
                                    $btn.prop('disabled', false);
                                    $status.css('color','red').text('✖ V1 AJAX request failed');
                                });
                            });
                        })(jQuery);
                        </script>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </form>

            <style>
                /* Toplist Accordion Styles */
                .dataflair-toplists-table th.sortable-toplist { padding: 0; }
                .toplist-sort-link {
                    display: flex;
                    align-items: center;
                    justify-content: space-between;
                    padding: 8px 10px;
                    text-decoration: none;
                    color: inherit;
                    white-space: nowrap;
                }
                .toplist-sort-link:hover { background: #f0f0f1; }
                .toplist-sort-indicator { font-size: 10px; margin-left: 4px; color: #2271b1; }
                .dataflair-toplists-table tbody tr.toplist-row { transition: opacity 0.15s ease; }
                .toplist-toggle-btn {
                    background: none;
                    border: none;
                    cursor: pointer;
                    padding: 4px;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    transition: all 0.2s;
                }
                .toplist-toggle-btn:hover {
                    background: #f0f0f1;
                    border-radius: 3px;
                }
                .toplist-toggle-btn .dashicons {
                    transition: transform 0.2s;
                }
                .toplist-accordion-inner {
                    animation: slideIn 0.2s ease-out;
                }
                @keyframes slideIn {
                    from {
                        opacity: 0;
                        transform: translateY(-10px);
                    }
                    to {
                        opacity: 1;
                        transform: translateY(0);
                    }
                }
            </style>
        </div>
        <?php
    }
    
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
    private function api_get($url, $token, $timeout = 30) {
        // Force HTTPS on production/staging (skip for local .test/.local domains)
        $url = $this->maybe_force_https($url);

        $headers = array(
            'Accept'        => 'application/json',
            'Authorization' => 'Bearer ' . trim($token),
        );

        // Docker compatibility: when WordPress runs inside Docker (wp-env),
        // .test/.local domains from the host machine can't be resolved inside the container.
        // We swap the hostname to host.docker.internal (Docker's alias for the host machine)
        // and send the original hostname as the Host header so Laravel's tenancy middleware
        // can still resolve the correct tenant.
        $parsed = parse_url($url);
        $host = isset($parsed['host']) ? $parsed['host'] : '';

        if ($this->is_local_url($url) && $this->is_running_in_docker()) {
            $original_host = $host;
            // Replace the .test/.local hostname with host.docker.internal
            $url = str_replace($original_host, 'host.docker.internal', $url);
            // Tell the server which vhost/tenant we want
            $headers['Host'] = $original_host;
            error_log('DataFlair api_get() Docker detected: rewrote ' . $original_host . ' → host.docker.internal');
        }

        // If HTTP Basic Auth is configured (e.g. staging behind .htpasswd),
        // inject credentials into the URL so cURL sends them at the transport layer.
        $http_user = trim(get_option('dataflair_http_auth_user', ''));
        $http_pass = trim(get_option('dataflair_http_auth_pass', ''));

        if (!empty($http_user) && !empty($http_pass)) {
            $url = preg_replace('#^(https?://)#i', '$1' . urlencode($http_user) . ':' . urlencode($http_pass) . '@', $url);
        }

        error_log('DataFlair api_get() FINAL URL: ' . $url);
        error_log('DataFlair api_get() Host header: ' . (isset($headers['Host']) ? $headers['Host'] : '(from URL)'));
        error_log('DataFlair api_get() Token: ' . substr(trim($token), 0, 15) . '... (len=' . strlen(trim($token)) . ')');

        return wp_remote_get($url, array(
            'timeout' => $timeout,
            'headers' => $headers,
        ));
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

    /**
     * AJAX handler to fetch all toplists from API and sync them
     */
    public function ajax_fetch_all_toplists() {
        check_ajax_referer('dataflair_fetch_all_toplists', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Unauthorized'));
        }
        
        $token = trim(get_option('dataflair_api_token'));
        if (empty($token)) {
            wp_send_json_error(array('message' => 'API token not configured. Please set your API token first.'));
        }
        
        // Debug: Log token details (prefix only for security)
        error_log('DataFlair DEBUG: Token length=' . strlen($token) . ', starts with=' . substr($token, 0, 15) . '...');

        // Get API base URL (auto-detected or manually set)
        $base_url = $this->get_api_base_url();

        error_log('DataFlair DEBUG: Base URL from get_api_base_url()=' . $base_url);
        error_log('DataFlair DEBUG: Stored option dataflair_api_base_url=' . get_option('dataflair_api_base_url', '(not set)'));

        // ── Fetch ALL pages of toplists (v2 API paginates, default 15/page) ──
        $toplist_endpoints = array();
        $current_page = 1;
        $last_page = 1;
        $base_url_detected = false;

        do {
            $list_url = $base_url . '/toplists?per_page=100&page=' . $current_page;
            error_log('DataFlair DEBUG: Fetching page ' . $current_page . ' — URL=' . $list_url);

            $response = $this->api_get($list_url, $token);

            if (is_wp_error($response)) {
                $error_msg = 'Failed to fetch toplist list (page ' . $current_page . '): ' . $response->get_error_message();
                error_log('DataFlair fetch_all_toplists error: ' . $error_msg);
                wp_send_json_error(array('message' => $error_msg));
            }

            $status_code = wp_remote_retrieve_response_code($response);
            $body = wp_remote_retrieve_body($response);
            $response_headers = wp_remote_retrieve_headers($response);

            error_log('DataFlair /toplists API Response Code: ' . $status_code . ' (page ' . $current_page . ')');
            error_log('DataFlair /toplists API Response Body (first 500 chars): ' . substr($body, 0, 500));

            // Auto-detect and store base URL from first successful response
            if ($status_code === 200 && !$base_url_detected) {
                $parsed_url = parse_url($list_url);
                if (isset($parsed_url['scheme']) && isset($parsed_url['host'])) {
                    $detected_base = $parsed_url['scheme'] . '://' . $parsed_url['host'] . '/api/v1';
                    $current_base = get_option('dataflair_api_base_url');
                    if (empty($current_base) || $current_base !== $detected_base) {
                        update_option('dataflair_api_base_url', $detected_base);
                        error_log('DataFlair: Auto-detected and stored API base URL: ' . $detected_base);
                    }
                }
                $base_url_detected = true;
            }

            if ($status_code !== 200) {
                $error_msg = $this->build_detailed_api_error($status_code, $body, $response_headers, $list_url);
                error_log('DataFlair fetch_all_toplists error: ' . $error_msg);
                wp_send_json_error(array('message' => $error_msg));
            }

            $data = json_decode($body, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                $error_msg = 'JSON decode error: ' . json_last_error_msg() . '. Response: ' . substr($body, 0, 200);
                error_log('DataFlair fetch_all_toplists error: ' . $error_msg);
                wp_send_json_error(array('message' => $error_msg));
            }

            if (!isset($data['data'])) {
                $error_msg = 'Invalid response format from API. Expected "data" key. Response structure: ' . print_r(array_keys($data), true);
                error_log('DataFlair fetch_all_toplists error: ' . $error_msg);
                wp_send_json_error(array('message' => 'Invalid response format from API. Expected "data" key in response. Check debug.log for details.'));
            }

            // Extract toplist endpoints from this page
            if (isset($data['data']) && is_array($data['data'])) {
                foreach ($data['data'] as $toplist) {
                    if (isset($toplist['id'])) {
                        $toplist_endpoints[] = $base_url . '/toplists/' . $toplist['id'];
                    }
                }
            }

            // Check for more pages (Laravel paginated response includes meta.last_page)
            if (isset($data['meta']['last_page'])) {
                $last_page = (int) $data['meta']['last_page'];
            }

            $current_page++;
        } while ($current_page <= $last_page);

        error_log('DataFlair DEBUG: Fetched ' . count($toplist_endpoints) . ' toplist(s) across ' . ($current_page - 1) . ' page(s)');

        if (empty($toplist_endpoints)) {
            wp_send_json_error(array('message' => 'No toplists found in the API response. Make sure at least one toplist has a live edition.'));
        }
        
        // Update the endpoints setting
        $endpoints_string = implode("\n", $toplist_endpoints);
        update_option('dataflair_api_endpoints', $endpoints_string);
        
        // Now sync all the toplists
        $result = $this->sync_all_toplists();
        
        if ($result['success']) {
            $message = sprintf(
                'Fetched %d toplists from API and synced them! Successfully synced: %d | Errors: %d', 
                count($toplist_endpoints),
                $result['synced'], 
                $result['errors']
            );
            
            if ($result['errors'] > 0) {
                $message .= ' - Check WordPress debug.log for details.';
            }
            
            wp_send_json_success(array(
                'message' => $message,
                'toplists_count' => count($toplist_endpoints),
                'synced' => $result['synced'],
                'errors' => $result['errors']
            ));
        } else {
            wp_send_json_error(array('message' => 'Toplists fetched but sync failed: ' . $result['message']));
        }
    }
    
    /**
     * Cron sync handler
     */
    public function cron_sync_toplists() {
        $this->sync_all_toplists();
        // Record exact time this cron fired so the admin UI can show "X minutes ago"
        update_option('dataflair_last_toplists_cron_run', time());
    }
    
    /**
     * Cron sync handler for brands
     */
    public function cron_sync_brands() {
        $this->sync_all_brands();
        // Record exact time this cron fired so the admin UI can show "X minutes ago"
        update_option('dataflair_last_brands_cron_run', time());
    }
    
    /**
     * Sync all configured toplists.
     *
     * Re-discovers toplist endpoints from the API on every run (paginated) so
     * newly published editions are picked up automatically — no manual fetch needed.
     */
    private function sync_all_toplists() {
        $token = trim(get_option('dataflair_api_token'));

        if (empty($token)) {
            return array('success' => false, 'message' => 'API token not configured');
        }

        // ── Re-discover endpoints from the API (handles pagination) ──
        $discovered = $this->discover_toplist_endpoints($token);

        if (!empty($discovered)) {
            $endpoints_string = implode("\n", $discovered);
            update_option('dataflair_api_endpoints', $endpoints_string);
            $endpoints_array = $discovered;
            error_log('DataFlair sync: Re-discovered ' . count($discovered) . ' toplist endpoint(s) from API');
        } else {
            // Fallback to stored endpoints if API discovery fails
            $endpoints = get_option('dataflair_api_endpoints');
            if (empty($endpoints)) {
                return array('success' => false, 'message' => 'No endpoints configured and API discovery returned no results');
            }
            $endpoints_array = array_filter(array_map('trim', explode("\n", $endpoints)));
            error_log('DataFlair sync: API discovery returned 0 results, falling back to ' . count($endpoints_array) . ' stored endpoint(s)');
        }

        // Clear old tracker transients before syncing new data
        $this->clear_tracker_transients();

        // Purge all existing toplists so stale records from a previous API key
        // (or removed toplists) are not left behind after a full sync.
        global $wpdb;
        $table_name = $wpdb->prefix . DATAFLAIR_TABLE_NAME;
        $wpdb->query("TRUNCATE TABLE {$table_name}");

        $synced = 0;
        $errors = 0;

        foreach ($endpoints_array as $endpoint) {
            $result = $this->fetch_and_store_toplist($endpoint, $token);
            if ($result) {
                $synced++;
            } else {
                $errors++;
            }
        }

        // Record sync time so the admin UI shows "Last sync: X ago" whether
        // the sync was triggered by cron or manually via the admin panel.
        update_option('dataflair_last_toplists_cron_run', time());

        return array(
            'success' => true,
            'synced' => $synced,
            'errors' => $errors
        );
    }

    /**
     * Clear all DataFlair tracker transients
     * Called before syncing to expire old campaign mappings
     */
    private function clear_tracker_transients() {
        global $wpdb;
        
        // Delete all transients with dataflair_tracker_ prefix
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} 
                WHERE option_name LIKE %s 
                OR option_name LIKE %s",
                '_transient_dataflair_tracker_%',
                '_transient_timeout_dataflair_tracker_%'
            )
        );
        
        error_log('DataFlair: Cleared all tracker transients before sync');
    }
    
    /**
     * Brands page HTML
     */
    public function brands_page() {
        global $wpdb;
        $brands_table_name = $wpdb->prefix . DATAFLAIR_BRANDS_TABLE_NAME;
        
        // Get synced brands
        $brands = $wpdb->get_results("SELECT * FROM $brands_table_name ORDER BY name ASC");
        
        ?>
        <div class="wrap">
            <h1>DataFlair Brands</h1>
            
            <?php settings_errors(); ?>
            
            <p>Manage brands from the DataFlair API. Only active brands are synced.</p>
            
            <h2>Sync Brands</h2>
            <button type="button" id="dataflair-fetch-all-brands" class="button button-primary">
                Sync Brands from API
            </button>
            <span id="dataflair-fetch-brands-message" style="margin-left: 10px;"></span>
            
            <!-- Progress Bar -->
            <div id="dataflair-sync-progress" style="display: none; margin-top: 15px;">
                <div style="background: #f0f0f1; border-radius: 4px; height: 24px; position: relative; overflow: hidden; border: 1px solid #dcdcde;">
                    <div id="dataflair-progress-bar" style="background: linear-gradient(90deg, #2271b1 0%, #135e96 100%); height: 100%; width: 0%; transition: width 0.3s ease; display: flex; align-items: center; justify-content: center;">
                        <span id="dataflair-progress-text" style="color: white; font-size: 12px; font-weight: 600; position: absolute; left: 50%; transform: translateX(-50%);"></span>
                    </div>
                </div>
            </div>
            
            <p class="description">Fetches all active brands from the DataFlair API in batches of 15. Existing brands will be updated.</p>
            <p class="description">Auto-sync runs every 15 minutes. <?php echo $this->get_last_brands_cron_time(); ?></p>
            
            <hr>
            
            <h2>Synced Brands (<?php echo count($brands); ?>)</h2>
            <?php if ($brands): ?>
                <?php
                // Collect unique values for filters
                $all_licenses = array();
                $all_geos = array();
                $all_payment_methods = array();
                
                foreach ($brands as $brand) {
                    $data = json_decode($brand->data, true);
                    
                    // Licenses
                    if (!empty($data['licenses']) && is_array($data['licenses'])) {
                        $all_licenses = array_merge($all_licenses, $data['licenses']);
                    }
                    
                    // Top Geos
                    if (!empty($data['topGeos']['countries']) && is_array($data['topGeos']['countries'])) {
                        $all_geos = array_merge($all_geos, $data['topGeos']['countries']);
                    }
                    if (!empty($data['topGeos']['markets']) && is_array($data['topGeos']['markets'])) {
                        $all_geos = array_merge($all_geos, $data['topGeos']['markets']);
                    }
                    
                    // Payment Methods
                    if (!empty($data['paymentMethods']) && is_array($data['paymentMethods'])) {
                        $all_payment_methods = array_merge($all_payment_methods, $data['paymentMethods']);
                    }
                }
                
                // Get unique and sorted values
                $all_licenses = array_unique($all_licenses);
                $all_geos = array_unique($all_geos);
                $all_payment_methods = array_unique($all_payment_methods);
                sort($all_licenses);
                sort($all_geos);
                sort($all_payment_methods);
                ?>
                
                <!-- Filters -->
                <div class="dataflair-filters">
                    <div class="filter-row">
                        <!-- License Filter -->
                        <div class="filter-group">
                            <label>Licenses</label>
                            <select id="dataflair-filter-licenses" class="dataflair-select2" multiple="multiple" data-filter-type="licenses" style="width: 100%;">
                                <?php foreach ($all_licenses as $license): ?>
                                    <option value="<?php echo esc_attr($license); ?>"><?php echo esc_html($license); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <!-- Geo Filter -->
                        <div class="filter-group">
                            <label>Top Geos</label>
                            <select id="dataflair-filter-top-geos" class="dataflair-select2" multiple="multiple" data-filter-type="top_geos" style="width: 100%;">
                                <?php foreach ($all_geos as $geo): ?>
                                    <option value="<?php echo esc_attr($geo); ?>"><?php echo esc_html($geo); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <!-- Payment Filter -->
                        <div class="filter-group">
                            <label>Payment Methods</label>
                            <select id="dataflair-filter-payment-methods" class="dataflair-select2" multiple="multiple" data-filter-type="payment_methods" style="width: 100%;">
                                <?php foreach ($all_payment_methods as $method): ?>
                                    <option value="<?php echo esc_attr($method); ?>"><?php echo esc_html($method); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <!-- Actions -->
                        <div class="filter-group filter-actions">
                            <button type="button" id="dataflair-clear-all-filters" class="button">Clear All Filters</button>
                            <span id="dataflair-brands-count">Showing <?php echo count($brands); ?> brands</span>
                        </div>
                    </div>
                </div>
                
                <table class="wp-list-table widefat striped dataflair-brands-table">
                    <thead>
                        <tr>
                            <th style="width: 3%;"></th>
                            <th style="width: 5%;">ID</th>
                            <th style="width: 8%;">Logo</th>
                            <th style="width: 14%;" class="sortable">
                                <a href="#" class="sort-link" data-sort="name">
                                    Brand Name
                                    <span class="sorting-indicator">
                                        <span class="dashicons dashicons-sort"></span>
                                    </span>
                                </a>
                            </th>
                            <th style="width: 10%;">Product Type</th>
                            <th style="width: 12%;">Licenses</th>
                            <th style="width: 16%;">Top Geos</th>
                            <th style="width: 7%;" class="sortable">
                                <a href="#" class="sort-link" data-sort="offers">
                                    Offers
                                    <span class="sorting-indicator">
                                        <span class="dashicons dashicons-sort"></span>
                                    </span>
                                </a>
                            </th>
                            <th style="width: 7%;" class="sortable">
                                <a href="#" class="sort-link" data-sort="trackers">
                                    Trackers
                                    <span class="sorting-indicator">
                                        <span class="dashicons dashicons-sort"></span>
                                    </span>
                                </a>
                            </th>
                            <th style="width: 13%;">Last Synced</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($brands as $index => $brand): 
                            $data = json_decode($brand->data, true);
                            $brand_id = 'brand-' . $brand->api_brand_id;
                            
                            // Prepare filter data attributes
                            $licenses_json = !empty($data['licenses']) ? json_encode($data['licenses']) : '[]';
                            
                            $geos = array();
                            if (!empty($data['topGeos']['countries'])) {
                                $geos = array_merge($geos, $data['topGeos']['countries']);
                            }
                            if (!empty($data['topGeos']['markets'])) {
                                $geos = array_merge($geos, $data['topGeos']['markets']);
                            }
                            $geos_json = json_encode($geos);
                            
                            $payments_json = !empty($data['paymentMethods']) ? json_encode($data['paymentMethods']) : '[]';
                        ?>
                        <tr class="brand-row" 
                            data-brand-name="<?php echo esc_attr($brand->name); ?>"
                            data-offers-count="<?php echo esc_attr($brand->offers_count); ?>"
                            data-trackers-count="<?php echo esc_attr($brand->trackers_count); ?>"
                            data-brand-data='<?php echo esc_attr(json_encode(array(
                                'licenses' => !empty($data['licenses']) ? $data['licenses'] : array(),
                                'topGeos' => $geos,
                                'paymentMethods' => !empty($data['paymentMethods']) ? $data['paymentMethods'] : array()
                            ))); ?>'>
                            <td class="toggle-cell">
                                <button type="button" class="brand-toggle" aria-expanded="false">
                                    <span class="dashicons dashicons-arrow-right-alt2"></span>
                                </button>
                            </td>
                            <td><?php echo esc_html($brand->api_brand_id); ?></td>
                            <td class="brand-logo-cell">
                                <?php 
                                // Get logo URL - prioritize local_logo
                                $logo_url = '';
                                if (!empty($data['local_logo'])) {
                                    $logo_url = $data['local_logo'];
                                } else {
                                    // Fallback to external logo with nested structure support
                                    $logo_keys = array('logo', 'brandLogo', 'logoUrl', 'image');
                                    foreach ($logo_keys as $key) {
                                        if (!empty($data[$key])) {
                                            if (is_array($data[$key])) {
                                                // Check for nested logo object with rectangular/square
                                                $logo_url = $data[$key]['rectangular'] ?? 
                                                           $data[$key]['square'] ?? 
                                                           $data[$key]['url'] ?? 
                                                           $data[$key]['src'] ?? '';
                                            } else {
                                                $logo_url = $data[$key];
                                            }
                                            if ($logo_url) break;
                                        }
                                    }
                                }
                                
                                if ($logo_url && !is_array($logo_url)): ?>
                                    <img src="<?php echo esc_url($logo_url); ?>" 
                                         alt="<?php echo esc_attr($brand->name); ?>" 
                                         class="brand-logo-thumb"
                                         onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                                    <div class="brand-logo-placeholder" style="display: none;">
                                        <?php echo esc_html(strtoupper(substr($brand->name, 0, 2))); ?>
                                    </div>
                                <?php else: ?>
                                    <div class="brand-logo-placeholder">
                                        <?php echo esc_html(strtoupper(substr($brand->name, 0, 2))); ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <strong><?php echo esc_html($brand->name); ?></strong>
                                <div class="row-actions">
                                    <span class="slug"><?php echo esc_html($brand->slug); ?></span>
                                </div>
                            </td>
                            <td><?php echo esc_html($brand->product_types ?: '—'); ?></td>
                            <td>
                                <?php 
                                $licenses = $brand->licenses;
                                if (strlen($licenses) > 25) {
                                    echo '<span title="' . esc_attr($licenses) . '">' . esc_html(substr($licenses, 0, 25)) . '...</span>';
                                } else {
                                    echo esc_html($licenses ?: '—');
                                }
                                ?>
                            </td>
                            <td>
                                <?php 
                                $top_geos = $brand->top_geos;
                                if (strlen($top_geos) > 35) {
                                    echo '<span title="' . esc_attr($top_geos) . '">' . esc_html(substr($top_geos, 0, 35)) . '...</span>';
                                } else {
                                    echo esc_html($top_geos ?: '—');
                                }
                                ?>
                            </td>
                            <td style="text-align: center;">
                                <?php if ($brand->offers_count > 0): ?>
                                    <span class="count"><?php echo esc_html($brand->offers_count); ?></span>
                                <?php else: ?>
                                    <span style="color: #999;">0</span>
                                <?php endif; ?>
                            </td>
                            <td style="text-align: center;">
                                <?php if ($brand->trackers_count > 0): ?>
                                    <span class="count"><?php echo esc_html($brand->trackers_count); ?></span>
                                <?php else: ?>
                                    <span style="color: #999;">0</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo esc_html(date('Y-m-d H:i', strtotime($brand->last_synced))); ?></td>
                        </tr>
                        
                        <!-- Expandable Details Row -->
                        <tr class="brand-details" id="<?php echo esc_attr($brand_id); ?>" style="display: none;">
                            <td colspan="10">
                                <div class="brand-details-content">
                                    <div class="details-grid">
                                        <div class="detail-section">
                                            <h4>Payment Methods</h4>
                                            <div class="detail-content">
                                                <?php 
                                                if (!empty($data['paymentMethods']) && is_array($data['paymentMethods'])) {
                                                    echo '<div class="badge-list">';
                                                    foreach (array_slice($data['paymentMethods'], 0, 15) as $method) {
                                                        echo '<span class="badge">' . esc_html($method) . '</span>';
                                                    }
                                                    if (count($data['paymentMethods']) > 15) {
                                                        $remaining = array_slice($data['paymentMethods'], 15);
                                                        $tooltip = esc_attr(implode(', ', $remaining));
                                                        echo '<span class="badge more with-tooltip" title="' . $tooltip . '">+' . (count($data['paymentMethods']) - 15) . ' more</span>';
                                                    }
                                                    echo '</div>';
                                                } else {
                                                    echo '<span class="no-data">No payment methods</span>';
                                                }
                                                ?>
                                            </div>
                                        </div>
                                        
                                        <div class="detail-section">
                                            <h4>Currencies</h4>
                                            <div class="detail-content">
                                                <?php 
                                                if (!empty($data['currencies']) && is_array($data['currencies'])) {
                                                    echo '<div class="badge-list">';
                                                    foreach ($data['currencies'] as $currency) {
                                                        echo '<span class="badge">' . esc_html($currency) . '</span>';
                                                    }
                                                    echo '</div>';
                                                } else {
                                                    echo '<span class="no-data">No currencies specified</span>';
                                                }
                                                ?>
                                            </div>
                                        </div>
                                        
                                        <div class="detail-section">
                                            <h4>Rating</h4>
                                            <div class="detail-content">
                                                <?php 
                                                $rating = isset($data['rating']) ? $data['rating'] : null;
                                                if ($rating) {
                                                    echo '<span class="rating">' . esc_html($rating) . ' / 5</span>';
                                                } else {
                                                    echo '<span class="no-data">Not rated</span>';
                                                }
                                                ?>
                                            </div>
                                        </div>
                                        
                                        <div class="detail-section full-width">
                                            <h4>Game Providers</h4>
                                            <div class="detail-content">
                                                <?php 
                                                if (!empty($data['gameProviders']) && is_array($data['gameProviders'])) {
                                                    echo '<div class="badge-list">';
                                                    foreach ($data['gameProviders'] as $provider) {
                                                        echo '<span class="badge">' . esc_html($provider) . '</span>';
                                                    }
                                                    echo '</div>';
                                                } else {
                                                    echo '<span class="no-data">No game providers specified</span>';
                                                }
                                                ?>
                                            </div>
                                        </div>
                                        
                                        <div class="detail-section full-width">
                                            <h4>Restricted Countries</h4>
                                            <div class="detail-content restricted-countries">
                                                <?php 
                                                if (!empty($data['restrictedCountries']) && is_array($data['restrictedCountries'])) {
                                                    echo '<div class="badge-list">';
                                                    foreach (array_slice($data['restrictedCountries'], 0, 20) as $country) {
                                                        echo '<span class="badge badge-gray">' . esc_html($country) . '</span>';
                                                    }
                                                    if (count($data['restrictedCountries']) > 20) {
                                                        $remaining = array_slice($data['restrictedCountries'], 20);
                                                        $tooltip = esc_attr(implode(', ', $remaining));
                                                        echo '<span class="badge badge-gray more with-tooltip" title="' . $tooltip . '">+' . (count($data['restrictedCountries']) - 20) . ' more</span>';
                                                    }
                                                    echo '</div>';
                                                } else {
                                                    echo '<span class="no-data">No restrictions</span>';
                                                }
                                                ?>
                                            </div>
                                        </div>
                                        
                                        <div class="detail-section">
                                            <h4>Customer Support</h4>
                                            <div class="detail-content">
                                                <?php 
                                                $hasCustomerSupport = !empty($data['languages']['customerSupport']) && is_array($data['languages']['customerSupport']) && count($data['languages']['customerSupport']) > 0;
                                                if ($hasCustomerSupport) {
                                                    echo '<span class="support-available">Available</span><br>';
                                                    echo '<div class="badge-list" style="margin-top: 6px;">';
                                                    foreach ($data['languages']['customerSupport'] as $lang) {
                                                        echo '<span class="badge">' . esc_html($lang) . '</span>';
                                                    }
                                                    echo '</div>';
                                                } else {
                                                    echo '<span class="no-data">Not specified</span>';
                                                }
                                                ?>
                                            </div>
                                        </div>
                                        
                                        <div class="detail-section">
                                            <h4>Live Chat</h4>
                                            <div class="detail-content">
                                                <?php 
                                                $hasLiveChat = !empty($data['languages']['livechat']) && is_array($data['languages']['livechat']) && count($data['languages']['livechat']) > 0;
                                                if ($hasLiveChat) {
                                                    echo '<span class="support-available">Available</span><br>';
                                                    echo '<div class="badge-list" style="margin-top: 6px;">';
                                                    foreach ($data['languages']['livechat'] as $lang) {
                                                        echo '<span class="badge">' . esc_html($lang) . '</span>';
                                                    }
                                                    echo '</div>';
                                                } else {
                                                    echo '<span class="no-data">Not available</span>';
                                                }
                                                ?>
                                            </div>
                                        </div>
                                        
                                        <div class="detail-section full-width">
                                            <h4>Website Languages</h4>
                                            <div class="detail-content">
                                                <?php 
                                                if (!empty($data['languages']['website']) && is_array($data['languages']['website'])) {
                                                    echo '<div class="badge-list">';
                                                    foreach ($data['languages']['website'] as $lang) {
                                                        echo '<span class="badge">' . esc_html($lang) . '</span>';
                                                    }
                                                    echo '</div>';
                                                } else {
                                                    echo '<span class="no-data">Not specified</span>';
                                                }
                                                ?>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <hr class="details-separator">
                                    
                                    <div class="offers-section">
                                        <h4>Offers (<?php echo $brand->offers_count; ?>)</h4>
                                        <?php if (!empty($data['offers']) && is_array($data['offers'])): ?>
                                            <div class="offers-list">
                                                <?php foreach ($data['offers'] as $offer): ?>
                                                    <div class="offer-item-inline">
                                                        <span class="offer-type-badge"><?php echo esc_html($offer['offerTypeName'] ?? 'Unknown'); ?></span>
                                                        <span class="offer-separator">:</span>
                                                        <span class="offer-text-inline"><?php echo esc_html($offer['offerText'] ?? 'No description'); ?></span>
                                                        <span class="offer-separator">:</span>
                                                        <span class="offer-geo-inline">
                                                            <?php 
                                                            $geos = array();
                                                            if (!empty($offer['geos']['countries'])) {
                                                                $geos = array_merge($geos, $offer['geos']['countries']);
                                                            }
                                                            if (!empty($offer['geos']['markets'])) {
                                                                $geos = array_merge($geos, $offer['geos']['markets']);
                                                            }
                                                            echo $geos ? esc_html(implode(', ', $geos)) : 'All Geos';
                                                            ?>
                                                        </span>
                                                        <span class="offer-separator">:</span>
                                                        <span class="offer-tracker-inline">
                                                            <?php 
                                                            $trackers = isset($offer['trackers']) && is_array($offer['trackers']) ? $offer['trackers'] : array();
                                                            if (count($trackers) > 0) {
                                                                echo '<span class="tracker-count-inline">' . count($trackers) . ' tracker' . (count($trackers) > 1 ? 's' : '') . '</span>';
                                                            } else {
                                                                echo '<span class="no-trackers-inline">No trackers</span>';
                                                            }
                                                            ?>
                                                        </span>
                                                        <span class="offer-separator">|</span>
                                                        <span class="offer-extra-info">
                                                            <?php 
                                                            $extras = array();
                                                            if (!empty($offer['bonus_wagering_requirement'])) {
                                                                $extras[] = 'Wagering: ' . esc_html($offer['bonus_wagering_requirement']) . 'x';
                                                            }
                                                            if (!empty($offer['minimum_deposit'])) {
                                                                $extras[] = 'Min Deposit: $' . esc_html($offer['minimum_deposit']);
                                                            }
                                                            if (!empty($offer['bonus_code'])) {
                                                                $extras[] = 'Code: ' . esc_html($offer['bonus_code']);
                                                            }
                                                            if (!empty($offer['has_free_spins'])) {
                                                                $extras[] = '<span class="badge-free-spins">Free Spins</span>';
                                                            }
                                                            echo implode(' | ', $extras);
                                                            ?>
                                                        </span>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php else: ?>
                                            <p class="no-data">No offers available</p>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                
                <!-- Pagination -->
                <div class="tablenav bottom">
                    <div class="tablenav-pages">
                        <span class="displaying-num" id="brands-total-count"><?php echo count($brands); ?> items</span>
                        <span class="pagination-links" style="margin-right: 10px;">
                            <label for="items-per-page-selector" style="margin-right: 5px;">Show:</label>
                            <select id="items-per-page-selector" style="margin-right: 10px;">
                                <option value="10">10</option>
                                <option value="20" selected>20</option>
                                <option value="50">50</option>
                                <option value="100">100</option>
                                <option value="200">200</option>
                            </select>
                        </span>
                        <span class="pagination-links">
                            <a class="first-page button" href="#" id="pagination-first" disabled>
                                <span class="screen-reader-text">First page</span>
                                <span aria-hidden="true">«</span>
                            </a>
                            <a class="prev-page button" href="#" id="pagination-prev" disabled>
                                <span class="screen-reader-text">Previous page</span>
                                <span aria-hidden="true">‹</span>
                            </a>
                            <span class="paging-input">
                                <label for="current-page-selector" class="screen-reader-text">Current Page</label>
                                <input class="current-page" id="current-page-selector" type="text" name="paged" value="1" size="2" aria-describedby="table-paging">
                                <span class="tablenav-paging-text"> of <span class="total-pages" id="total-pages">1</span></span>
                            </span>
                            <a class="next-page button" href="#" id="pagination-next">
                                <span class="screen-reader-text">Next page</span>
                                <span aria-hidden="true">›</span>
                            </a>
                            <a class="last-page button" href="#" id="pagination-last">
                                <span class="screen-reader-text">Last page</span>
                                <span aria-hidden="true">»</span>
                            </a>
                        </span>
                    </div>
                </div>
                
                <style>
                    /* Select2 styling for WordPress admin */
                    .select2-container {
                        z-index: 999999;
                    }
                    .select2-container--default .select2-selection--multiple {
                        border: 1px solid #8c8f94;
                        border-radius: 4px;
                        min-height: 30px;
                        padding: 2px;
                    }
                    .select2-container--default .select2-selection--multiple .select2-selection__choice {
                        background-color: #2271b1;
                        border: 1px solid #2271b1;
                        color: #fff;
                        padding: 2px 6px;
                        margin: 2px;
                        border-radius: 3px;
                        display: inline-flex;
                        align-items: center;
                    }
                    .select2-container--default .select2-selection--multiple .select2-selection__choice__remove {
                        /* Select2 v4.1+ renders this as a <button> — reset browser defaults */
                        border: none;
                        background: rgba(255, 255, 255, 0.25);
                        padding: 0;
                        margin-right: 0;
                        color: #fff;
                        cursor: pointer;
                        font-size: 13px;
                        font-weight: 700;
                        line-height: 1;
                        display: inline-flex;
                        align-items: center;
                        justify-content: center;
                        width: 16px;
                        height: 16px;
                        border-radius: 50%;
                        flex-shrink: 0;
                    }
                    .select2-container--default .select2-selection--multiple .select2-selection__choice__remove:hover {
                        background: rgba(255, 255, 255, 0.45);
                        color: #fff;
                    }
                    /* Inner ×  span — strip any inherited spacing so button stays square */
                    .select2-container--default .select2-selection--multiple .select2-selection__choice__remove span {
                        display: flex;
                        align-items: center;
                        justify-content: center;
                        line-height: 1;
                        margin: 0;
                        padding: 0;
                    }
                    /* Display label — own the gap from the × button */
                    .select2-container--default .select2-selection--multiple .select2-selection__choice__display {
                        padding-left: 15px;
                    }
                    .select2-container--default .select2-search--inline .select2-search__field {
                        margin-top: 2px;
                        padding: 2px;
                    }
                    .filter-group .select2-container {
                        margin-top: 5px;
                    }
                    
                    /* Filters */
                    .dataflair-filters {
                        background: #f9f9f9;
                        border: 1px solid #dcdcde;
                        border-radius: 6px;
                        padding: 20px;
                        margin-bottom: 20px;
                        box-shadow: 0 1px 3px rgba(0,0,0,0.05);
                    }
                    .filter-row {
                        display: flex;
                        align-items: flex-end;
                        gap: 16px;
                        flex-wrap: wrap;
                    }
                    .filter-group {
                        display: flex;
                        flex-direction: column;
                        gap: 8px;
                        flex: 1;
                        min-width: 220px;
                        max-width: 280px;
                    }
                    .filter-group > label {
                        font-weight: 600;
                        font-size: 13px;
                        color: #1d2327;
                        letter-spacing: 0;
                    }
                    
                    /* Custom Multiselect */
                    .custom-multiselect {
                        position: relative;
                        flex: 1;
                    }
                    .multiselect-toggle {
                        width: 100%;
                        padding: 8px 12px;
                        background: white;
                        border: 1px solid #8c8f94;
                        border-radius: 4px;
                        font-size: 13px;
                        text-align: left;
                        cursor: pointer;
                        display: flex;
                        justify-content: space-between;
                        align-items: center;
                        transition: all 0.2s;
                        min-height: 38px;
                        box-shadow: 0 1px 2px rgba(0,0,0,0.05);
                    }
                    .multiselect-toggle:hover {
                        border-color: #2271b1;
                        box-shadow: 0 1px 3px rgba(0,0,0,0.1);
                    }
                    .multiselect-toggle .dashicons {
                        font-size: 18px;
                        width: 18px;
                        height: 18px;
                        transition: transform 0.2s;
                        color: #8c8f94;
                        flex-shrink: 0;
                        margin-left: 8px;
                    }
                    .custom-multiselect.open .multiselect-toggle .dashicons {
                        transform: rotate(180deg);
                        color: #2271b1;
                    }
                    .custom-multiselect.open .multiselect-toggle {
                        border-color: #2271b1;
                        box-shadow: 0 0 0 1px #2271b1;
                    }
                    .selected-text {
                        overflow: hidden;
                        text-overflow: ellipsis;
                        white-space: nowrap;
                        flex: 1;
                    }
                    
                    /* Dropdown */
                    .multiselect-dropdown {
                        position: absolute;
                        top: 100%;
                        left: 0;
                        right: 0;
                        background: white;
                        border: 1px solid #2271b1;
                        border-radius: 3px;
                        margin-top: 4px;
                        box-shadow: 0 2px 8px rgba(0,0,0,0.1);
                        z-index: 1000;
                        display: none;
                    }
                    .custom-multiselect.open .multiselect-dropdown {
                        display: block;
                    }
                    
                    /* Search */
                    .multiselect-search {
                        padding: 8px;
                        border-bottom: 1px solid #dcdcde;
                    }
                    .multiselect-search input {
                        width: 100%;
                        padding: 4px 8px;
                        border: 1px solid #8c8f94;
                        border-radius: 3px;
                        font-size: 12px;
                    }
                    .multiselect-search input:focus {
                        border-color: #2271b1;
                        outline: none;
                    }
                    
                    /* Actions */
                    .multiselect-actions {
                        padding: 6px 8px;
                        border-bottom: 1px solid #dcdcde;
                        display: flex;
                        gap: 12px;
                        font-size: 11px;
                    }
                    .multiselect-actions a {
                        color: #2271b1;
                        text-decoration: none;
                    }
                    .multiselect-actions a:hover {
                        text-decoration: underline;
                    }
                    
                    /* Options */
                    .multiselect-options {
                        max-height: 200px;
                        overflow-y: auto;
                        padding: 4px;
                    }
                    .multiselect-option {
                        display: flex;
                        align-items: center;
                        padding: 6px 8px;
                        cursor: pointer;
                        border-radius: 2px;
                        font-size: 12px;
                        margin: 0;
                    }
                    .multiselect-option:hover {
                        background: #f0f0f1;
                    }
                    .multiselect-option input[type="checkbox"] {
                        margin: 0 8px 0 0;
                        cursor: pointer;
                    }
                    .multiselect-option span {
                        flex: 1;
                    }
                    .multiselect-option.hidden {
                        display: none;
                    }
                    
                    /* Filter Actions */
                    .filter-actions {
                        display: flex;
                        flex-direction: row;
                        gap: 16px;
                        align-items: center;
                        margin-left: auto;
                    }
                    .filter-actions > label {
                        display: none;
                    }
                    #clear-filters {
                        white-space: nowrap;
                        font-size: 13px;
                        padding: 8px 16px;
                        height: 38px;
                        line-height: 1.5;
                    }
                    #filter-count {
                        font-size: 13px;
                        color: #1d2327;
                        font-weight: 600;
                        white-space: nowrap;
                    }
                    .brand-row.filtered-out,
                    .brand-details.filtered-out {
                        display: none !important;
                    }
                    
                    @media screen and (max-width: 1200px) {
                        .filter-row {
                            flex-wrap: wrap;
                        }
                        .filter-group {
                            min-width: 180px;
                        }
                        .filter-actions {
                            width: 100%;
                            margin-left: 0;
                            margin-top: 8px;
                            justify-content: space-between;
                        }
                    }
                    
                    @media screen and (max-width: 782px) {
                        .filter-group {
                            min-width: 100%;
                            max-width: 100%;
                        }
                        .filter-actions {
                            flex-direction: column;
                            align-items: flex-start;
                            gap: 8px;
                        }
                    }
                    
                    /* Sortable Headers */
                    /* Smooth row visibility transitions */
                    .dataflair-brands-table tbody tr.brand-row {
                        transition: opacity 0.15s ease;
                    }
                    .dataflair-brands-table {
                        transition: opacity 0.1s ease;
                    }
                    .dataflair-brands-table thead th.sortable {
                        padding: 0;
                    }
                    .dataflair-brands-table .sort-link {
                        display: flex;
                        align-items: center;
                        justify-content: space-between;
                        padding: 8px 10px;
                        color: #2c3338;
                        text-decoration: none;
                        cursor: pointer;
                        transition: background-color 0.2s;
                    }
                    .dataflair-brands-table .sort-link:hover {
                        background-color: #f0f0f1;
                    }
                    .sorting-indicator {
                        display: inline-flex;
                        align-items: center;
                        margin-left: 4px;
                    }
                    .sorting-indicator .dashicons {
                        font-size: 16px;
                        width: 16px;
                        height: 16px;
                        color: #a7aaad;
                    }
                    .sort-link.asc .dashicons-sort,
                    .sort-link.desc .dashicons-sort {
                        display: none;
                    }
                    .sort-link.asc .sorting-indicator::after {
                        content: '\f142';
                        font-family: dashicons;
                        font-size: 16px;
                        color: #2271b1;
                    }
                    .sort-link.desc .sorting-indicator::after {
                        content: '\f140';
                        font-family: dashicons;
                        font-size: 16px;
                        color: #2271b1;
                    }
                    
                    /* Brand Rows */
                    .brand-row {
                        border-bottom: 2px solid #dcdcde;
                    }
                    .brand-row:last-of-type {
                        border-bottom: none;
                    }
                    .brand-row.page-hidden,
                    .brand-details.page-hidden {
                        display: none !important;
                    }
                    
                    /* Pagination */
                    .tablenav.bottom {
                        margin-top: 10px;
                    }
                    .tablenav-pages {
                        float: right;
                    }
                    .tablenav-pages .pagination-links {
                        margin-left: 10px;
                    }
                    .tablenav-pages .button[disabled] {
                        color: #a7aaad;
                        cursor: default;
                        pointer-events: none;
                    }
                    .current-page {
                        width: 40px;
                        text-align: center;
                    }
                    
                    .dataflair-brands-table .row-actions {
                        color: #999;
                        font-size: 12px;
                        padding: 2px 0 0;
                    }
                    .dataflair-brands-table .count {
                        display: inline-block;
                        background: #2271b1;
                        color: white;
                        padding: 2px 8px;
                        border-radius: 10px;
                        font-size: 11px;
                        font-weight: 600;
                    }
                    
                    /* Brand Logo */
                    .brand-logo-cell {
                        text-align: center;
                        padding: 8px !important;
                    }
                    .brand-logo-thumb {
                        width: 60px;
                        height: 45px;
                        object-fit: contain;
                        background: #1a1a1a;
                        border-radius: 6px;
                        padding: 6px;
                        display: inline-block;
                    }
                    .brand-logo-placeholder {
                        width: 60px;
                        height: 45px;
                        display: inline-flex;
                        align-items: center;
                        justify-content: center;
                        background: linear-gradient(135deg, #352d67 0%, #5a4fa5 100%);
                        color: #fff;
                        font-size: 14px;
                        font-weight: 700;
                        border-radius: 6px;
                        text-transform: uppercase;
                        letter-spacing: 1px;
                    }
                    
                    /* Toggle Button */
                    .toggle-cell {
                        padding: 8px 4px !important;
                    }
                    .brand-toggle {
                        background: none;
                        border: none;
                        cursor: pointer;
                        padding: 4px;
                        display: flex;
                        align-items: center;
                        justify-content: center;
                        transition: transform 0.2s ease;
                    }
                    .brand-toggle:hover {
                        background: #f0f0f1;
                        border-radius: 3px;
                    }
                    .brand-toggle .dashicons {
                        transition: transform 0.2s ease;
                        font-size: 20px;
                        width: 20px;
                        height: 20px;
                    }
                    .brand-toggle[aria-expanded="true"] .dashicons {
                        transform: rotate(90deg);
                    }
                    
                    /* Details Row */
                    .brand-details td {
                        padding: 0 !important;
                        background: #f9f9f9;
                        border-top: none !important;
                    }
                    .brand-details-content {
                        padding: 20px;
                    }
                    
                    /* Details Grid */
                    .details-grid {
                        display: grid;
                        grid-template-columns: repeat(3, 1fr);
                        gap: 20px;
                        margin-bottom: 20px;
                    }
                    .detail-section.full-width {
                        grid-column: 1 / -1;
                    }
                    .detail-section h4 {
                        margin: 0 0 10px 0;
                        color: #1d2327;
                        font-size: 13px;
                        font-weight: 600;
                        text-transform: uppercase;
                        letter-spacing: 0.5px;
                    }
                    .detail-content {
                        font-size: 13px;
                        line-height: 1.6;
                    }
                    
                    /* Badges */
                    .badge-list {
                        display: flex;
                        flex-wrap: wrap;
                        gap: 6px;
                    }
                    .badge {
                        display: inline-block;
                        padding: 4px 10px;
                        background: #e0e0e0;
                        border-radius: 12px;
                        font-size: 11px;
                        font-weight: 500;
                        color: #2c3338;
                    }
                    .badge-gray {
                        background: #f0f0f1;
                        color: #646970;
                    }
                    .badge.more {
                        background: #2271b1;
                        color: white;
                    }
                    .badge.with-tooltip {
                        cursor: help;
                        position: relative;
                    }
                    .badge.with-tooltip:hover {
                        background: #135e96;
                    }
                    .no-data {
                        color: #999;
                        font-style: italic;
                    }
                    .rating {
                        font-size: 16px;
                        font-weight: 600;
                        color: #2271b1;
                    }
                    
                    /* Separator */
                    .details-separator {
                        margin: 20px 0;
                        border: none;
                        border-top: 2px solid #dcdcde;
                    }
                    
                    /* Offers Section */
                    .offers-section h4 {
                        margin: 0 0 12px 0;
                        color: #1d2327;
                        font-size: 14px;
                        font-weight: 600;
                    }
                    .offers-list {
                        display: flex;
                        flex-direction: column;
                        gap: 8px;
                    }
                    .offer-item-inline {
                        display: flex;
                        align-items: center;
                        gap: 8px;
                        padding: 8px 12px;
                        background: white;
                        border: 1px solid #dcdcde;
                        border-radius: 3px;
                        font-size: 12px;
                        line-height: 1.4;
                        flex-wrap: wrap;
                    }
                    .offer-type-badge {
                        display: inline-flex;
                        padding: 3px 10px;
                        background: #2271b1;
                        color: white;
                        border-radius: 10px;
                        font-size: 11px;
                        font-weight: 600;
                        text-transform: uppercase;
                        white-space: nowrap;
                    }
                    .offer-separator {
                        color: #8c8f94;
                        font-weight: 600;
                    }
                    .offer-text-inline {
                        font-weight: 500;
                        color: #1d2327;
                    }
                    .offer-geo-inline {
                        color: #646970;
                    }
                    .offer-tracker-inline {
                        color: #646970;
                    }
                    .tracker-count-inline {
                        background: #00a32a;
                        color: white;
                        padding: 2px 8px;
                        border-radius: 10px;
                        font-weight: 600;
                        font-size: 11px;
                    }
                    .no-trackers-inline {
                        color: #999;
                        font-style: italic;
                    }
                    .offer-extra-info {
                        color: #646970;
                        font-size: 11px;
                    }
                    .badge-free-spins {
                        background: #00a32a;
                        color: white;
                        padding: 2px 8px;
                        border-radius: 10px;
                        font-weight: 600;
                        font-size: 10px;
                        text-transform: uppercase;
                    }
                    .support-available {
                        color: #00a32a;
                        font-weight: 600;
                        font-size: 13px;
                    }
                    
                    @media screen and (max-width: 782px) {
                        .details-grid {
                            grid-template-columns: 1fr;
                        }
                    }
                </style>
            <?php else: ?>
                <p>No brands synced yet. Click "Sync Brands from API" to fetch active brands.</p>
            <?php endif; ?>
            
            <hr>
            
            <h2>Brand Details</h2>
            <p>Brands are read-only and automatically synced from the DataFlair API. They are used for managing casino brands across your site.</p>
        </div>
        <?php
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

    private function get_last_brands_cron_time() {
        $last_run  = get_option( 'dataflair_last_brands_cron_run' );
        $next_run  = wp_next_scheduled( 'dataflair_brands_sync_cron' );

        $last_str  = $last_run ? $this->time_ago( $last_run ) : 'never';
        $next_str  = $next_run ? $this->time_until( $next_run ) : 'not scheduled';

        return 'Last sync: ' . $last_str . ' &mdash; Next sync: ' . $next_str;
    }
    
    /**
     * AJAX handler to sync brands in batches (one page at a time)
     */
    public function ajax_sync_brands_batch() {
        check_ajax_referer('dataflair_sync_brands_batch', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Unauthorized'));
        }
        
        $token = trim(get_option('dataflair_api_token'));
        if (empty($token)) {
            wp_send_json_error(array('message' => 'API token not configured. Please set your API token first.'));
        }
        
        // Get the page number from request
        $page = isset($_POST['page']) ? intval($_POST['page']) : 1;

        // On the first page of a full sync, purge all existing brand records so
        // stale brands from a previous API key are not left behind.
        if ($page === 1) {
            global $wpdb;
            $brands_table = $wpdb->prefix . 'dataflair_brands';
            $wpdb->query("TRUNCATE TABLE {$brands_table}");
        }

        // Sync one page of brands (15 brands per page)
        $result = $this->sync_brands_page($page, $token);
        
        if ($result['success']) {
            wp_send_json_success(array(
                'page' => $page,
                'last_page' => $result['last_page'],
                'synced' => $result['synced'],
                'errors' => $result['errors'],
                'total_synced' => $result['total_synced'],
                'is_complete' => $page >= $result['last_page']
            ));
        } else {
            wp_send_json_error(array('message' => $result['message']));
        }
    }
    
    /**
     * AJAX handler to fetch all brands (kept for backward compatibility, now triggers batch sync)
     */
    public function ajax_fetch_all_brands() {
        check_ajax_referer('dataflair_fetch_all_brands', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Unauthorized'));
        }
        
        $token = trim(get_option('dataflair_api_token'));
        if (empty($token)) {
            wp_send_json_error(array('message' => 'API token not configured. Please set your API token first.'));
        }
        
        // Return initial response - JavaScript will handle batch processing
        wp_send_json_success(array(
            'message' => 'Starting batch sync...',
            'start_batch' => true
        ));
    }
    
    /**
     * Download and save brand logo locally
     * 
     * @param array $brand_data Brand data from API
     * @param string $brand_slug Brand slug for filename
     * @return string|false Local URL to saved logo or false on failure
     */
    private function download_brand_logo($brand_data, $brand_slug) {
        // Extract logo URL from brand data
        $logo_url = '';
        $logo_keys = array('logo', 'brandLogo', 'logoUrl', 'image', 'logoImage');
        
        foreach ($logo_keys as $key) {
            if (!empty($brand_data[$key])) {
                if (is_array($brand_data[$key])) {
                    // Check for nested logo object with rectangular/square options
                    if (!empty($brand_data[$key]['rectangular'])) {
                        $logo_url = $brand_data[$key]['rectangular'];
                        break;
                    } elseif (!empty($brand_data[$key]['square'])) {
                        $logo_url = $brand_data[$key]['square'];
                        break;
                    } elseif (!empty($brand_data[$key]['url'])) {
                        $logo_url = $brand_data[$key]['url'];
                        break;
                    } elseif (!empty($brand_data[$key]['src'])) {
                        $logo_url = $brand_data[$key]['src'];
                        break;
                    } elseif (!empty($brand_data[$key]['path'])) {
                        $logo_url = $brand_data[$key]['path'];
                        break;
                    }
                } else {
                    $logo_url = $brand_data[$key];
                    break;
                }
            }
        }
        
        if (empty($logo_url) || !filter_var($logo_url, FILTER_VALIDATE_URL)) {
            error_log('DataFlair: No valid logo URL found for brand "' . ($brand_data['name'] ?? 'unknown') . '". Available keys: ' . implode(', ', array_keys($brand_data)));
            return false;
        }
        
        error_log('DataFlair: Found logo URL for brand "' . ($brand_data['name'] ?? 'unknown') . '": ' . $logo_url);
        
        // Create logos directory if it doesn't exist
        $upload_dir = DATAFLAIR_PLUGIN_DIR . 'assets/logos/';
        if (!file_exists($upload_dir)) {
            wp_mkdir_p($upload_dir);
        }
        
        // Get file extension from URL
        $path_info = pathinfo(parse_url($logo_url, PHP_URL_PATH));
        $extension = !empty($path_info['extension']) ? $path_info['extension'] : 'png';
        
        // Only allow safe image extensions
        $allowed_extensions = array('jpg', 'jpeg', 'png', 'gif', 'webp', 'svg');
        if (!in_array(strtolower($extension), $allowed_extensions)) {
            $extension = 'png';
        }
        
        // Create unique filename
        $filename = sanitize_file_name($brand_slug) . '.' . $extension;
        $file_path = $upload_dir . $filename;
        
        // Check if file already exists and is recent (less than 7 days old)
        if (file_exists($file_path) && (time() - filemtime($file_path)) < (7 * 24 * 60 * 60)) {
            // Return existing file URL
            $file_url = DATAFLAIR_PLUGIN_URL . 'assets/logos/' . $filename;
            error_log('DataFlair: Using cached logo for brand "' . ($brand_data['name'] ?? 'unknown') . '": ' . $file_url);
            return $file_url;
        }
        
        // Download the image
        $response = wp_remote_get($logo_url, array(
            'timeout' => 30,
            'sslverify' => false // Some CDNs have SSL issues
        ));
        
        if (is_wp_error($response)) {
            error_log('DataFlair: Failed to download logo from ' . $logo_url . ': ' . $response->get_error_message());
            return false;
        }
        
        $image_data = wp_remote_retrieve_body($response);
        $response_code = wp_remote_retrieve_response_code($response);
        
        if ($response_code !== 200 || empty($image_data)) {
            error_log('DataFlair: Failed to download logo, HTTP ' . $response_code);
            return false;
        }
        
        // Save the image
        $saved = file_put_contents($file_path, $image_data);
        
        if ($saved === false) {
            error_log('DataFlair: Failed to save logo to ' . $file_path);
            return false;
        }
        
        // Return the URL to the saved file
        $file_url = DATAFLAIR_PLUGIN_URL . 'assets/logos/' . $filename;
        error_log('DataFlair: Successfully saved logo for brand "' . ($brand_data['name'] ?? 'unknown') . '" to: ' . $file_url);
        return $file_url;
    }
    
    /**
     * Sync a single page of brands from API (15 brands per page)
     */
    private function sync_brands_page($page, $token) {
        $brands_url = $this->get_brands_api_url($page);

        $response = $this->api_get($brands_url, $token);

        if (is_wp_error($response)) {
            $error_msg = 'Failed to fetch brands page ' . $page . ': ' . $response->get_error_message();
            error_log('DataFlair sync_brands_page error: ' . $error_msg);
            return array('success' => false, 'message' => $error_msg);
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $response_headers = wp_remote_retrieve_headers($response);

        if ($status_code !== 200) {
            $error_msg = $this->build_detailed_api_error($status_code, $body, $response_headers, $brands_url);
            error_log('DataFlair sync_brands_page error: ' . $error_msg);
            return array('success' => false, 'message' => $error_msg);
        }
        
        $data = json_decode($body, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            $error_msg = 'JSON decode error: ' . json_last_error_msg();
            error_log('DataFlair sync_brands_page error: ' . $error_msg);
            return array('success' => false, 'message' => $error_msg);
        }
        
        if (!isset($data['data'])) {
            $error_msg = 'Invalid response format from API. Expected "data" key.';
            error_log('DataFlair sync_brands_page error: ' . $error_msg);
            return array('success' => false, 'message' => $error_msg);
        }
        
        // Get pagination info
        $last_page = isset($data['meta']['last_page']) ? intval($data['meta']['last_page']) : 1;
        $total = isset($data['meta']['total']) ? intval($data['meta']['total']) : 0;
        
        $synced = 0;
        $errors = 0;
        
        global $wpdb;
        $brands_table_name = $wpdb->prefix . DATAFLAIR_BRANDS_TABLE_NAME;
        
        // Log the number of brands on this page
        $brands_on_page = count($data['data']);
        error_log('DataFlair: Page ' . $page . ' has ' . $brands_on_page . ' brands');
        
        // Process each brand on this page
        foreach ($data['data'] as $brand_data) {
            // Check if brand is active
            $brand_status = isset($brand_data['brandStatus']) ? $brand_data['brandStatus'] : '';
            
            error_log('DataFlair: Processing brand ID ' . (isset($brand_data['id']) ? $brand_data['id'] : 'unknown') . 
                     ', Status: ' . $brand_status);
            
            if ($brand_status !== 'Active') {
                error_log('DataFlair: Skipping brand - status is not Active');
                continue; // Skip non-active brands
            }
            
            if (!isset($brand_data['id'])) {
                $errors++;
                error_log('DataFlair brand missing ID: ' . json_encode($brand_data));
                continue;
            }
            
            $api_brand_id = $brand_data['id'];
            $brand_name = isset($brand_data['name']) ? $brand_data['name'] : 'Unnamed Brand';
            $brand_slug = isset($brand_data['slug']) ? $brand_data['slug'] : sanitize_title($brand_name);
            
            // Download and save logo locally
            $local_logo_path = $this->download_brand_logo($brand_data, $brand_slug);
            if ($local_logo_path) {
                // Add local logo path to brand data
                $brand_data['local_logo'] = $local_logo_path;
                error_log('DataFlair: Logo saved locally for brand "' . $brand_name . '" at: ' . $local_logo_path);
            }
            
            // Extract computed fields
            $product_types = isset($brand_data['productTypes']) && is_array($brand_data['productTypes']) 
                ? implode(', ', $brand_data['productTypes']) 
                : '';
            
            $licenses = isset($brand_data['licenses']) && is_array($brand_data['licenses'])
                ? implode(', ', $brand_data['licenses'])
                : '';

            // Extract V2 classification types (e.g. Casino, Sportsbook, Poker)
            $classification_types = isset($brand_data['classificationTypes'])
                && is_array($brand_data['classificationTypes'])
                ? implode(', ', $brand_data['classificationTypes']) : '';

            // Combine top geo countries and markets
            $top_geos_arr = array();
            if (isset($brand_data['topGeos']['countries']) && is_array($brand_data['topGeos']['countries'])) {
                $top_geos_arr = array_merge($top_geos_arr, $brand_data['topGeos']['countries']);
            }
            if (isset($brand_data['topGeos']['markets']) && is_array($brand_data['topGeos']['markets'])) {
                $top_geos_arr = array_merge($top_geos_arr, $brand_data['topGeos']['markets']);
            }
            $top_geos = implode(', ', $top_geos_arr);

            // ⚠️ Warn if brand has offers but no topGeos — likely an API data issue
            $brand_offers_count = isset($brand_data['offersCount']) ? intval($brand_data['offersCount'])
                : (isset($brand_data['offers']) && is_array($brand_data['offers']) ? count($brand_data['offers']) : 0);
            if (empty($top_geos_arr) && $brand_offers_count > 0) {
                error_log(sprintf(
                    '[DataFlair Sync] Brand #%d (%s): has %d offer(s) but no topGeos — check DataFlair admin',
                    $api_brand_id,
                    $brand_name,
                    $brand_offers_count
                ));
            }
            
            // Count offers
            $offers_count = isset($brand_data['offers']) && is_array($brand_data['offers']) 
                ? count($brand_data['offers']) 
                : 0;
            
            // Count trackers across all offers
            $trackers_count = 0;
            if (isset($brand_data['offers']) && is_array($brand_data['offers'])) {
                foreach ($brand_data['offers'] as $offer) {
                    if (isset($offer['trackers']) && is_array($offer['trackers'])) {
                        $trackers_count += count($offer['trackers']);
                    }
                }
            }
            
            // Insert or update
            $existing = $wpdb->get_row($wpdb->prepare(
                "SELECT id FROM $brands_table_name WHERE api_brand_id = %d",
                $api_brand_id
            ));
            
            if ($existing) {
                $result = $wpdb->update(
                    $brands_table_name,
                    array(
                        'name' => $brand_name,
                        'slug' => $brand_slug,
                        'status' => $brand_status,
                        'product_types' => $product_types,
                        'licenses' => $licenses,
                        'classification_types' => $classification_types,
                        'top_geos' => $top_geos,
                        'offers_count' => $offers_count,
                        'trackers_count' => $trackers_count,
                        'data' => json_encode($brand_data),
                        'last_synced' => current_time('mysql')
                    ),
                    array('api_brand_id' => $api_brand_id),
                    array('%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s'),
                    array('%d')
                );
            } else {
                $result = $wpdb->insert(
                    $brands_table_name,
                    array(
                        'api_brand_id' => $api_brand_id,
                        'name' => $brand_name,
                        'slug' => $brand_slug,
                        'status' => $brand_status,
                        'product_types' => $product_types,
                        'licenses' => $licenses,
                        'classification_types' => $classification_types,
                        'top_geos' => $top_geos,
                        'offers_count' => $offers_count,
                        'trackers_count' => $trackers_count,
                        'data' => json_encode($brand_data),
                        'last_synced' => current_time('mysql')
                    ),
                    array('%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s')
                );
            }
            
            if ($result !== false) {
                $synced++;
                error_log('DataFlair: Successfully synced brand ID ' . $api_brand_id . ' (' . $brand_name . ')');
            } else {
                $errors++;
                error_log('DataFlair brand sync error for brand ID ' . $api_brand_id . ': ' . $wpdb->last_error);
                error_log('DataFlair brand sync error - Last query: ' . $wpdb->last_query);
            }
        }
        
        error_log('DataFlair: Page ' . $page . ' complete. Synced: ' . $synced . ', Errors: ' . $errors);
        
        // Get total synced count from database
        $total_synced = $wpdb->get_var("SELECT COUNT(*) FROM $brands_table_name WHERE status = 'Active'");
        
        error_log('DataFlair: Total active brands in database: ' . $total_synced);
        
        return array(
            'success' => true,
            'last_page' => $last_page,
            'synced' => $synced,
            'errors' => $errors,
            'total_synced' => intval($total_synced),
            'total_brands' => $total
        );
    }
    
    /**
     * Sync all brands from API (only active ones) - handles pagination
     * Used by cron job
     */
    private function sync_all_brands() {
        $token = trim(get_option('dataflair_api_token'));
        
        if (empty($token)) {
            return array('success' => false, 'message' => 'API token not configured');
        }
        
        $base_url = $this->get_api_base_url();
        $synced = 0;
        $errors = 0;
        $current_page = 1;
        $last_page = 1;
        
        global $wpdb;
        $brands_table_name = $wpdb->prefix . DATAFLAIR_BRANDS_TABLE_NAME;
        
        // Loop through all pages
        do {
            $brands_url = $base_url . '/brands?page=' . $current_page;

            $response = $this->api_get($brands_url, $token);

            if (is_wp_error($response)) {
                $error_msg = 'Failed to fetch brands page ' . $current_page . ': ' . $response->get_error_message();
                error_log('DataFlair fetch_all_brands error: ' . $error_msg);
                return array('success' => false, 'message' => $error_msg);
            }

            $status_code = wp_remote_retrieve_response_code($response);
            $body = wp_remote_retrieve_body($response);
            $response_headers = wp_remote_retrieve_headers($response);

            if ($status_code !== 200) {
                $error_msg = $this->build_detailed_api_error($status_code, $body, $response_headers, $brands_url);
                error_log('DataFlair fetch_all_brands error: ' . $error_msg);
                return array('success' => false, 'message' => $error_msg);
            }
            
            $data = json_decode($body, true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                $error_msg = 'JSON decode error: ' . json_last_error_msg();
                error_log('DataFlair fetch_all_brands error: ' . $error_msg);
                return array('success' => false, 'message' => $error_msg);
            }
            
            if (!isset($data['data'])) {
                $error_msg = 'Invalid response format from API. Expected "data" key.';
                error_log('DataFlair fetch_all_brands error: ' . $error_msg);
                return array('success' => false, 'message' => $error_msg);
            }
            
            // Get pagination info
            if (isset($data['meta']['last_page'])) {
                $last_page = intval($data['meta']['last_page']);
            }
            
            error_log('DataFlair: Processing brands page ' . $current_page . ' of ' . $last_page);
            
            // Process each brand on this page
            foreach ($data['data'] as $brand_data) {
                // Check if brand is active
                $brand_status = isset($brand_data['brandStatus']) ? $brand_data['brandStatus'] : '';
                
                if ($brand_status !== 'Active') {
                    continue; // Skip non-active brands
                }
                
                if (!isset($brand_data['id'])) {
                    $errors++;
                    error_log('DataFlair brand missing ID: ' . json_encode($brand_data));
                    continue;
                }
                
                $api_brand_id = $brand_data['id'];
                $brand_name = isset($brand_data['name']) ? $brand_data['name'] : 'Unnamed Brand';
                $brand_slug = isset($brand_data['slug']) ? $brand_data['slug'] : sanitize_title($brand_name);
                
                // Extract computed fields
                $product_types = isset($brand_data['productTypes']) && is_array($brand_data['productTypes']) 
                    ? implode(', ', $brand_data['productTypes']) 
                    : '';
                
                $licenses = isset($brand_data['licenses']) && is_array($brand_data['licenses']) 
                    ? implode(', ', $brand_data['licenses']) 
                    : '';
                
                // Combine top geo countries and markets
                $top_geos_arr = array();
                if (isset($brand_data['topGeos']['countries']) && is_array($brand_data['topGeos']['countries'])) {
                    $top_geos_arr = array_merge($top_geos_arr, $brand_data['topGeos']['countries']);
                }
                if (isset($brand_data['topGeos']['markets']) && is_array($brand_data['topGeos']['markets'])) {
                    $top_geos_arr = array_merge($top_geos_arr, $brand_data['topGeos']['markets']);
                }
                $top_geos = implode(', ', $top_geos_arr);
                
                // Count offers
                $offers_count = isset($brand_data['offers']) && is_array($brand_data['offers']) 
                    ? count($brand_data['offers']) 
                    : 0;
                
                // Count trackers across all offers
                $trackers_count = 0;
                if (isset($brand_data['offers']) && is_array($brand_data['offers'])) {
                    foreach ($brand_data['offers'] as $offer) {
                        if (isset($offer['trackers']) && is_array($offer['trackers'])) {
                            $trackers_count += count($offer['trackers']);
                        }
                    }
                }
                
                // Insert or update
                $existing = $wpdb->get_row($wpdb->prepare(
                    "SELECT id FROM $brands_table_name WHERE api_brand_id = %d",
                    $api_brand_id
                ));
                
                if ($existing) {
                    $result = $wpdb->update(
                        $brands_table_name,
                        array(
                            'name' => $brand_name,
                            'slug' => $brand_slug,
                            'status' => $brand_status,
                            'product_types' => $product_types,
                            'licenses' => $licenses,
                            'top_geos' => $top_geos,
                            'offers_count' => $offers_count,
                            'trackers_count' => $trackers_count,
                            'data' => json_encode($brand_data),
                            'last_synced' => current_time('mysql')
                        ),
                        array('api_brand_id' => $api_brand_id),
                        array('%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s'),
                        array('%d')
                    );
                } else {
                    $result = $wpdb->insert(
                        $brands_table_name,
                        array(
                            'api_brand_id' => $api_brand_id,
                            'name' => $brand_name,
                            'slug' => $brand_slug,
                            'status' => $brand_status,
                            'product_types' => $product_types,
                            'licenses' => $licenses,
                            'top_geos' => $top_geos,
                            'offers_count' => $offers_count,
                            'trackers_count' => $trackers_count,
                            'data' => json_encode($brand_data),
                            'last_synced' => current_time('mysql')
                        ),
                        array('%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s')
                    );
                }
                
                if ($result !== false) {
                    $synced++;
                } else {
                    $errors++;
                    error_log('DataFlair brand sync error for brand ID ' . $api_brand_id . ': ' . $wpdb->last_error);
                }
            }
            
            $current_page++;
            
        } while ($current_page <= $last_page);
        
        error_log('DataFlair: Brands sync complete. Total synced: ' . $synced . ', Errors: ' . $errors);

        // Record sync time so the admin UI shows "Last sync: X ago" whether
        // the sync was triggered by cron or manually via the admin panel.
        update_option('dataflair_last_brands_cron_run', time());

        return array(
            'success' => true,
            'synced' => $synced,
            'errors' => $errors
        );
    }

    /**
     * AJAX handler to get alternative toplists for a toplist
     */
    public function ajax_get_alternative_toplists() {
        check_ajax_referer('dataflair_save_settings', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Unauthorized'));
        }
        
        $toplist_id = isset($_POST['toplist_id']) ? intval($_POST['toplist_id']) : 0;
        
        if (!$toplist_id) {
            wp_send_json_error(array('message' => 'Invalid toplist ID'));
        }
        
        global $wpdb;
        $alt_table = $wpdb->prefix . DATAFLAIR_ALTERNATIVE_TOPLISTS_TABLE_NAME;
        
        // Ensure table exists
        $this->ensure_alternative_toplists_table();
        
        $alternatives = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $alt_table WHERE toplist_id = %d ORDER BY geo ASC",
            $toplist_id
        ), ARRAY_A);
        
        wp_send_json_success(array('alternatives' => $alternatives));
    }
    
    /**
     * AJAX handler to save an alternative toplist mapping
     */
    public function ajax_save_alternative_toplist() {
        check_ajax_referer('dataflair_save_settings', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Unauthorized'));
        }
        
        $toplist_id = isset($_POST['toplist_id']) ? intval($_POST['toplist_id']) : 0;
        $geo = isset($_POST['geo']) ? sanitize_text_field($_POST['geo']) : '';
        $alternative_toplist_id = isset($_POST['alternative_toplist_id']) ? intval($_POST['alternative_toplist_id']) : 0;
        
        error_log('DataFlair: Save alternative toplist - toplist_id: ' . $toplist_id . ', geo: ' . $geo . ', alt_toplist_id: ' . $alternative_toplist_id);
        
        if (!$toplist_id || !$geo || !$alternative_toplist_id) {
            wp_send_json_error(array('message' => 'Missing required parameters'));
        }
        
        global $wpdb;
        $alt_table = $wpdb->prefix . DATAFLAIR_ALTERNATIVE_TOPLISTS_TABLE_NAME;
        
        // Ensure table exists
        $this->ensure_alternative_toplists_table();
        
        // Check if mapping already exists
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM $alt_table WHERE toplist_id = %d AND geo = %s",
            $toplist_id,
            $geo
        ));
        
        if ($existing) {
            // Update existing mapping
            $result = $wpdb->update(
                $alt_table,
                array(
                    'alternative_toplist_id' => $alternative_toplist_id,
                    'updated_at' => current_time('mysql')
                ),
                array('id' => $existing->id),
                array('%d', '%s'),
                array('%d')
            );
            error_log('DataFlair: Update result: ' . ($result !== false ? 'success' : 'failed') . ' - ' . $wpdb->last_error);
        } else {
            // Insert new mapping
            $result = $wpdb->insert(
                $alt_table,
                array(
                    'toplist_id' => $toplist_id,
                    'geo' => $geo,
                    'alternative_toplist_id' => $alternative_toplist_id,
                    'created_at' => current_time('mysql'),
                    'updated_at' => current_time('mysql')
                ),
                array('%d', '%s', '%d', '%s', '%s')
            );
            error_log('DataFlair: Insert result: ' . ($result !== false ? 'success' : 'failed') . ' - ' . $wpdb->last_error);
        }
        
        if ($result !== false) {
            wp_send_json_success(array('message' => 'Alternative toplist saved successfully'));
        } else {
            wp_send_json_error(array('message' => 'Failed to save alternative toplist: ' . $wpdb->last_error));
        }
    }
    
    /**
     * AJAX handler to delete an alternative toplist mapping
     */
    public function ajax_delete_alternative_toplist() {
        check_ajax_referer('dataflair_save_settings', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Unauthorized'));
        }
        
        $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
        
        if (!$id) {
            wp_send_json_error(array('message' => 'Invalid ID'));
        }
        
        global $wpdb;
        $alt_table = $wpdb->prefix . DATAFLAIR_ALTERNATIVE_TOPLISTS_TABLE_NAME;
        
        // Ensure table exists
        $this->ensure_alternative_toplists_table();
        
        $result = $wpdb->delete($alt_table, array('id' => $id), array('%d'));
        
        if ($result !== false) {
            wp_send_json_success(array('message' => 'Alternative toplist deleted successfully'));
        } else {
            wp_send_json_error(array('message' => 'Failed to delete alternative toplist'));
        }
    }
    
    /**
     * AJAX handler to get available geos from toplists data
     */
    public function ajax_get_available_geos() {
        check_ajax_referer('dataflair_save_settings', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Unauthorized'));
        }
        
        global $wpdb;
        $toplists_table = $wpdb->prefix . DATAFLAIR_TABLE_NAME;
        
        // Ensure table exists
        $this->ensure_alternative_toplists_table();
        
        // Get all toplists and extract unique geos from their data
        $toplists = $wpdb->get_results("SELECT data FROM $toplists_table", ARRAY_A);
        
        $all_geos = array();
        
        foreach ($toplists as $toplist) {
            if (!empty($toplist['data'])) {
                $toplist_data = json_decode($toplist['data'], true);
                
                // Get geo name from toplist data
                // Format: "geo": { "geo_type": "country", "name": "United Kingdom" }
                if (isset($toplist_data['data']['geo']['name'])) {
                    $geo_name = $toplist_data['data']['geo']['name'];
                    if (!in_array($geo_name, $all_geos)) {
                        $all_geos[] = $geo_name;
                    }
                }
            }
        }
        
        sort($all_geos);
        
        wp_send_json_success(array('geos' => $all_geos));
    }

    /**
     * AJAX: Fetch a live API response for the admin preview tab.
     * Uses the stored token and base URL — no credentials are returned to the browser.
     */
    public function ajax_api_preview() {
        check_ajax_referer('dataflair_api_preview', '_ajax_nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $token = trim(get_option('dataflair_api_token', ''));
        if (empty($token)) {
            wp_send_json_error('No API token configured.');
        }

        $endpoint_key = isset($_POST['endpoint']) ? sanitize_text_field($_POST['endpoint']) : '';
        $resource_id  = isset($_POST['resource_id']) ? absint($_POST['resource_id']) : 0;

        $base_url = rtrim($this->get_api_base_url(), '/');
        $start = microtime(true);

        switch ($endpoint_key) {
            case 'toplists':
                $url = $base_url . '/toplists';
                break;
            case 'toplists/custom':
                if (!$resource_id) {
                    wp_send_json_error('Resource ID required for single toplist.');
                }
                $url = $base_url . '/toplists/' . $resource_id;
                break;
            case 'brands':
                $url = $base_url . '/brands';
                break;
            case 'brands/custom':
                if (!$resource_id) {
                    wp_send_json_error('Resource ID required for single brand.');
                }
                $url = $base_url . '/brands/' . $resource_id;
                break;
            case 'brands_v2':
                $v2_base = preg_replace('#/api/v\d+$#', '/api/v2', $base_url);
                $url = rtrim($v2_base, '/') . '/brands';
                break;
            default:
                wp_send_json_error('Unknown endpoint.');
        }

        $response = $this->api_get($url, $token);

        if (is_wp_error($response)) {
            wp_send_json_error($response->get_error_message());
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $raw_body    = wp_remote_retrieve_body($response);

        // Pretty-print JSON if possible
        $decoded = json_decode($raw_body, true);
        $pretty  = ($decoded !== null) ? json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : $raw_body;

        $elapsed = round((microtime(true) - $start) * 1000) . 'ms';

        wp_send_json_success(array(
            'url'     => $url,
            'status'  => $status_code . ' ' . get_status_header_desc($status_code),
            'body'    => $pretty,
            'elapsed' => $elapsed,
        ));
    }

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
            $list_url = $base_url . '/toplists?per_page=100&page=' . $current_page;
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
        // Extract shortcode-specific attributes
        $shortcode_defaults = array(
            'id'    => '',  // Primary — looks up by api_toplist_id
            'slug'  => '',  // Optional — looks up by slug column
            'title' => '',
            'limit' => 0,
        );

        // Merge with defaults but preserve all other attributes (for customization)
        $atts = wp_parse_args($atts, $shortcode_defaults);

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
        unset($customizations['id'], $customizations['title'], $customizations['limit'], $customizations['prosCons']);
        
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
            
                        <?php foreach ($items as $item): 
                echo $this->render_casino_card($item, $atts['id'], $customizations, $pros_cons_data);
            endforeach; ?>
        </div>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Get or create review post for a casino brand
     * Auto-creates draft review if it doesn't exist
     * 
     * @param array $brand Brand data from API
     * @param array $item Full toplist item data
     * @return int|false Post ID of review, or false on failure
     */
    private function get_or_create_review_post($brand, $item) {
        // Check if review post type exists
        if (!post_type_exists('review')) {
            error_log('DataFlair: Review post type not registered');
            return false;
        }
        
        $brand_slug = !empty($brand['slug']) ? $brand['slug'] : sanitize_title($brand['name']);
        $brand_name = !empty($brand['name']) ? $brand['name'] : 'Unknown Casino';
        
        // Check if review post already exists
        $existing_review = get_page_by_path($brand_slug, OBJECT, 'review');
        
        if ($existing_review) {
            return $existing_review->ID;
        }
        
        // Auto-create draft review post
        $review_data = array(
            'post_title'   => $brand_name . ' Review',
            'post_name'    => $brand_slug,
            'post_content' => '',
            'post_status'  => 'draft',
            'post_type'    => 'review',
            'post_author'  => get_current_user_id() ?: 1,
        );
        
        $review_id = wp_insert_post($review_data);
        
        if (is_wp_error($review_id)) {
            error_log('DataFlair: Failed to create review post: ' . $review_id->get_error_message());
            return false;
        }
        
        // Populate meta fields with brand data
        if (!empty($brand['id'])) {
            update_post_meta($review_id, '_review_brand_id', $brand['id']);
        }
        
        // Extract and save logo URL
        $logo_url = '';
        if (!empty($brand['logo'])) {
            if (is_array($brand['logo'])) {
                $logo_url = $brand['logo']['rectangular'] ?? $brand['logo']['square'] ?? $brand['logo']['url'] ?? '';
            } else {
                $logo_url = $brand['logo'];
            }
        }
        
        // Save all review meta fields
        update_post_meta($review_id, '_review_brand_name', $brand_name);
        update_post_meta($review_id, '_review_logo', $logo_url);
        update_post_meta($review_id, '_review_url', !empty($item['offer']['tracking_url']) ? $item['offer']['tracking_url'] : '');
        update_post_meta($review_id, '_review_rating', !empty($item['rating']) ? $item['rating'] : (!empty($brand['rating']) ? $brand['rating'] : ''));
        update_post_meta($review_id, '_review_bonus', !empty($item['offer']['offerText']) ? $item['offer']['offerText'] : '');
        
        // Save payment methods
        $payments = array();
        if (!empty($item['paymentMethods'])) {
            $payments = is_array($item['paymentMethods']) ? $item['paymentMethods'] : explode(',', $item['paymentMethods']);
        } elseif (!empty($brand['paymentMethods'])) {
            $payments = is_array($brand['paymentMethods']) ? $brand['paymentMethods'] : explode(',', $brand['paymentMethods']);
        }
        update_post_meta($review_id, '_review_payments', implode(', ', $payments));
        
        // Save licenses
        $licenses = array();
        if (!empty($brand['licenses'])) {
            $licenses = is_array($brand['licenses']) ? $brand['licenses'] : explode(',', $brand['licenses']);
        }
        update_post_meta($review_id, '_review_licenses', implode(', ', $licenses));
        
        error_log('DataFlair: Auto-created draft review post #' . $review_id . ' for ' . $brand_name);
        
        return $review_id;
    }
    
    /**
     * Render individual casino card
     * Uses the new structured template for better layout
     */
    private function render_casino_card($item, $toplist_id, $customizations = array(), $pros_cons_data = array()) {
        // Check if new template exists
        $template_path = DATAFLAIR_PLUGIN_DIR . 'includes/render-casino-card.php';
        
        if (file_exists($template_path)) {
            // Get or create review post and generate review URL
            $brand = $item['brand'];
            
            // Download and save logo locally if not already done
            if (empty($brand['local_logo']) && !empty($brand['api_brand_id'])) {
                // Extract logo URL from brand data (same logic as in render-casino-card.php)
                $logo_url = '';
                $logo_sources = array('logo', 'brandLogo', 'logoUrl', 'image', 'logoImage');
                
                foreach ($logo_sources as $key) {
                    if (!empty($brand[$key])) {
                        if (is_array($brand[$key])) {
                            if (!empty($brand[$key]['rectangular'])) {
                                $logo_url = $brand[$key]['rectangular'];
                                break;
                            } elseif (!empty($brand[$key]['square'])) {
                                $logo_url = $brand[$key]['square'];
                                break;
                            } elseif (!empty($brand[$key]['url'])) {
                                $logo_url = $brand[$key]['url'];
                                break;
                            } elseif (!empty($brand[$key]['src'])) {
                                $logo_url = $brand[$key]['src'];
                                break;
                            } elseif (!empty($brand[$key][0])) {
                                $logo_url = $brand[$key][0];
                                break;
                            }
                        } else {
                            $logo_url = $brand[$key];
                            break;
                        }
                    }
                }
                
                // Download and save logo using theme's function if available
                if (!empty($logo_url) && filter_var($logo_url, FILTER_VALIDATE_URL)) {
                    if (function_exists('strikeodds_download_and_save_logo')) {
                        $local_logo = strikeodds_download_and_save_logo($logo_url, $brand['api_brand_id']);
                        if ($local_logo) {
                            $brand['local_logo'] = $local_logo;
                        }
                    }
                }
            }
            
            $review_id = $this->get_or_create_review_post($brand, $item);
            
            if ($review_id) {
                // Get permalink - works for both published and draft posts
                $review_url = get_permalink($review_id);
                // If permalink is false (draft), generate preview link
                if (!$review_url) {
                    $review_url = get_preview_post_link($review_id);
                }
                // Final fallback to slug-based URL
                if (!$review_url) {
                    $brand_slug = !empty($brand['slug']) ? $brand['slug'] : sanitize_title($brand['name']);
                    $review_url = home_url('/reviews/' . $brand_slug . '/');
                }
            } else {
                // Fallback to /reviews/{slug} format
                $brand_slug = !empty($brand['slug']) ? $brand['slug'] : sanitize_title($brand['name']);
                $review_url = home_url('/reviews/' . $brand_slug . '/');
            }
            
            // Pass review URL to template
            $review_url = apply_filters('dataflair_review_url', $review_url, $brand, $item);
            
            // Update item with processed brand data (including local_logo)
            $item['brand'] = $brand;
            
            // Use new template
            ob_start();
            include $template_path;
            return ob_get_clean();
        }
        
        // Fallback to original rendering (legacy support)
        // Get customization values with defaults
        $ribbon_bg = !empty($customizations['ribbonBgColor']) ? $customizations['ribbonBgColor'] : 'brand-600';
        $ribbon_text_color = !empty($customizations['ribbonTextColor']) ? $customizations['ribbonTextColor'] : 'white';
        $ribbon_text = !empty($customizations['ribbonText']) ? $customizations['ribbonText'] : 'Our Top Choice';
        $rank_bg = !empty($customizations['rankBgColor']) ? $customizations['rankBgColor'] : 'gray-100';
        $rank_text = !empty($customizations['rankTextColor']) ? $customizations['rankTextColor'] : 'gray-900';
        $rank_radius = !empty($customizations['rankBorderRadius']) ? $customizations['rankBorderRadius'] : 'rounded';
        $brand_link = !empty($customizations['brandLinkColor']) ? $customizations['brandLinkColor'] : 'brand-600';
        $bonus_label = !empty($customizations['bonusLabelStyle']) ? $customizations['bonusLabelStyle'] : 'text-gray-600';
        $bonus_text = !empty($customizations['bonusTextStyle']) ? $customizations['bonusTextStyle'] : 'text-gray-900 text-lg leading-6 font-semibold';
        $feature_check_bg = !empty($customizations['featureCheckBg']) ? $customizations['featureCheckBg'] : 'green-100';
        $feature_check_color = !empty($customizations['featureCheckColor']) ? $customizations['featureCheckColor'] : 'green-600';
        $feature_text = !empty($customizations['featureTextColor']) ? $customizations['featureTextColor'] : 'gray-600';
        $cta_bg = !empty($customizations['ctaBgColor']) ? $customizations['ctaBgColor'] : 'brand-600';
        $cta_hover_bg = !empty($customizations['ctaHoverBgColor']) ? $customizations['ctaHoverBgColor'] : 'brand-700';
        $cta_text = !empty($customizations['ctaTextColor']) ? $customizations['ctaTextColor'] : 'white';
        $cta_radius = !empty($customizations['ctaBorderRadius']) ? $customizations['ctaBorderRadius'] : 'rounded';
        $cta_shadow = !empty($customizations['ctaShadow']) ? $customizations['ctaShadow'] : 'shadow-md';
        $metric_label = !empty($customizations['metricLabelStyle']) ? $customizations['metricLabelStyle'] : 'text-gray-600';
        $metric_value = !empty($customizations['metricValueStyle']) ? $customizations['metricValueStyle'] : 'text-gray-900 font-semibold';
        $rg_border = !empty($customizations['rgBorderColor']) ? $customizations['rgBorderColor'] : 'gray-300';
        $rg_text = !empty($customizations['rgTextColor']) ? $customizations['rgTextColor'] : 'gray-600';
        
        // Helper function to build Tailwind classes
        $build_class = function($base, $custom) {
            if (empty($custom)) {
                return '';
            }
            // If custom value contains brackets, it's already a Tailwind arbitrary value like bg-[#ff0000]
            if (strpos($custom, '[') !== false) {
                return $custom;
            }
            // If it already contains the base prefix (e.g., "bg-blue-600"), return as-is
            if (strpos($custom, $base . '-') === 0) {
                return $custom;
            }
            // If it's a full class name like "text-gray-600", return as-is
            if (preg_match('/^(text|bg|border|hover:bg|hover:text)-/', $custom)) {
                return $custom;
            }
            // Otherwise, prepend the base class prefix
            return $base . '-' . $custom;
        };
        
                            $brand = $item['brand'];
                            $offer = $item['offer'];
                            $position = $item['position'];
        
        // Get casino URL from API data
        $casino_url = '';
        if (!empty($offer['tracking_url'])) {
            $casino_url = $offer['tracking_url'];
        } elseif (!empty($offer['url'])) {
            $casino_url = $offer['url'];
        } elseif (!empty($offer['link'])) {
            $casino_url = $offer['link'];
        } elseif (!empty($brand['url'])) {
            $casino_url = $brand['url'];
        } elseif (!empty($brand['website'])) {
            $casino_url = $brand['website'];
        } elseif (!empty($item['url'])) {
            $casino_url = $item['url'];
        }
        
        // Allow filtering of URL via hook
        $casino_url = apply_filters('dataflair_casino_url', $casino_url, $item, $brand, $offer);
        
        // Fallback to # if no URL found
        if (empty($casino_url)) {
            $casino_url = '#';
        }
        
        // Extract data
        $brand_name = esc_html($brand['name']);
        $brand_slug = sanitize_title($brand_name);
        $logo_url = !empty($brand['logo']) ? esc_url($brand['logo']) : '';
        $review_url = !empty($brand['review_url']) ? esc_url($brand['review_url']) : $casino_url;
        $offer_text = !empty($offer['offerText']) ? esc_html($offer['offerText']) : '';
        $rating = !empty($item['rating']) ? floatval($item['rating']) : (!empty($brand['rating']) ? floatval($brand['rating']) : 0);
        $bonus_wagering = !empty($offer['bonus_wagering_requirement']) ? esc_html($offer['bonus_wagering_requirement']) : '';
        $min_deposit = !empty($offer['minimum_deposit']) ? esc_html($offer['minimum_deposit']) : '';
        $payout_time = !empty($offer['payout_time']) ? esc_html($offer['payout_time']) : '';
        $max_payout = !empty($offer['max_payout']) ? esc_html($offer['max_payout']) : 'None';
        $licenses = !empty($brand['licenses']) ? esc_html(implode(', ', $brand['licenses'])) : '';
        $games_count = !empty($item['games_count']) ? esc_html($item['games_count']) : '';
        $features = !empty($item['features']) ? $item['features'] : array();
        $payment_methods = !empty($item['payment_methods']) ? $item['payment_methods'] : array();
        $allowed_countries = !empty($item['allowed_countries']) ? esc_attr(implode(',', $item['allowed_countries'])) : '';
        
        // Get default pros/cons from API
        $api_pros = !empty($item['pros']) ? $item['pros'] : array();
        $api_cons = !empty($item['cons']) ? $item['cons'] : array();
        
        // Generate unique ID for this casino card
        $card_id = 'casino-details-' . $brand_slug . '-' . $position;
        
        ob_start();
        ?>
        <div
            class="relative w-full mb-4 geot-element"
            <?php if ($position === 1): ?>data-has-casino-level-highlight=""<?php endif; ?>
            x-data="{ showDetails: false }"
            data-entry="casino"
            data-position="<?php echo esc_attr($position); ?>"
            data-gtm-module="casino-toplist"
            data-gtm-entry="casino"
            data-gtm-brand="<?php echo esc_attr($brand_slug); ?>"
            data-gtm-position="<?php echo esc_attr($position); ?>"
        >
            <?php if ($position === 1): ?>
                <div
                    class="flex h-10 justify-center items-center text-sm leading-[22px] uppercase rounded-tl-2xl rounded-tr-2xl <?php echo esc_attr($build_class('text', $ribbon_text_color)); ?> <?php echo esc_attr($build_class('bg', $ribbon_bg)); ?>"
                    data-gtm-element="highlight-badge"
                >
                    <?php echo esc_html($ribbon_text); ?>
                </div>
                                    <?php endif; ?>

            <div
                class="casino-row-container w-full relative shadow-[0_2px_6px_0px_rgba(71,85,105,0.1)] bg-white-50 p-4 border-solid border-2 <?php echo esc_attr($build_class('border', $ribbon_bg)); ?> rounded-bl-2xl rounded-br-2xl <?php echo $position === 1 ? '' : 'rounded-tl-2xl rounded-tr-2xl'; ?>"
                <?php if (!empty($allowed_countries)): ?>data-allowed-countries="<?php echo $allowed_countries; ?>"<?php endif; ?>
                x-data="{ showDetails: false }"
                data-gtm-element="casino-card"
            >
                <div class="grid grid-cols-1 tablet:grid-cols-2 gap-4 tablet:gap-6 desktop:flex desktop:gap-6 justify-between relative z-10">
                    <div class="flex desktop:w-81 flex-col tablet:flex-row gap-4">
                        <div class="flex gap-2 items-center justify-center tablet:justify-start">
                            <span
                                class="toplist-position-number <?php echo esc_attr($build_class('bg', $rank_bg)); ?> <?php echo esc_attr($build_class('text', $rank_text)); ?> <?php echo esc_attr($rank_radius); ?> w-6 h-6 flex justify-center items-center text-sm font-medium"
                                data-gtm-element="toplist-position"
                                data-gtm-position="<?php echo esc_attr($position); ?>"
                            >
                                <?php echo esc_html($position); ?>
                            </span>
                            <?php if (!empty($logo_url)): ?>
                                <a
                                    href="<?php echo esc_url($review_url); ?>"
                                    class="w-23 tablet:w-30 h-[69px] tablet:h-23 flex justify-center items-center rounded-lg"
                                    data-gtm-element="logo-link"
                                    data-gtm-brand="<?php echo esc_attr($brand_slug); ?>"
                                >
                                    <img
                                        decoding="async"
                                        loading="lazy"
                                        class="column max-sm:h-full max-sm:object-contain w-23 tablet:w-30 h-[69px] tablet:h-23 rounded-lg"
                                        src="<?php echo $logo_url; ?>"
                                        alt="<?php echo $brand_name; ?>"
                                        width="120"
                                        height="90"
                                    >
                                </a>
                            <?php endif; ?>
                            <div class="flex flex-col gap-2 whitespace-nowrap">
                                <a
                                    href="<?php echo esc_url($review_url); ?>"
                                    class="text-base font-semibold text-gray-900 no-underline"
                                    data-field="brand-name"
                                    data-gtm-element="brand-name-link"
                                    data-gtm-brand="<?php echo esc_attr($brand_slug); ?>"
                                >
                                    <?php echo $brand_name; ?>
                                </a>
                                <?php if ($rating > 0): ?>
                                    <div
                                        class="flex max-w-fit rounded bg-gray-100 p-1 text-sm leading-5.5 gap-1 items-center h-8 text-gray-600"
                                        data-gtm-element="rating-badge"
                                        data-gtm-metric="rating"
                                        data-gtm-value="<?php echo esc_attr($rating); ?>"
                                    >
                                        <span>Our rating:</span>
                                        <span class="icon-star-full <?php echo esc_attr($build_class('text', $brand_link)); ?> text-sm"></span>
                                        <span><?php echo esc_html($rating); ?>/5</span>
                                </div>
                                    <?php endif; ?>
                                <a
                                    href="<?php echo esc_url($review_url); ?>"
                                    class="hidden tablet:inline text-sm font-normal <?php echo esc_attr($build_class('text', $brand_link)); ?> leading-4 underline hover:no-underline"
                                    data-gtm-element="review-link"
                                    data-gtm-brand="<?php echo esc_attr($brand_slug); ?>"
                                >
                                    <?php echo $brand_name; ?> Review
                                </a>
                            </div>
                        </div>
                        <a
                            href="<?php echo esc_url($review_url); ?>"
                            class="tablet:hidden text-center w-full text-sm font-normal <?php echo esc_attr($build_class('text', $brand_link)); ?> leading-4 underline"
                            data-gtm-element="review-link"
                            data-gtm-brand="<?php echo esc_attr($brand_slug); ?>"
                        >
                            <?php echo $brand_name; ?> Review
                        </a>
                    </div>

                    <div class="flex flex-col gap-1 items-center text-center justify-center desktop:w-77">
                        <span
                            class="<?php echo esc_attr($bonus_label); ?> text-sm leading-5.5"
                            data-gtm-element="bonus-label"
                        >
                            Welcome bonus:
                        </span>
                        <div>
                            <a
                                class="<?php echo esc_attr($bonus_text); ?> <?php echo esc_attr('hover:' . $build_class('text', $brand_link)); ?>"
                                href="<?php echo esc_url($casino_url); ?>"
                                target="_blank"
                                rel="nofollow noreferrer"
                                data-restricted-countries=""
                                data-field="bonus-offer"
                                data-gtm-element="bonus-offer-link"
                                data-gtm-brand="<?php echo esc_attr($brand_slug); ?>"
                            >
                                <?php echo $offer_text; ?>
                            </a>
                        </div>
                    </div>

                    <div class="desktop:w-77">
                        <?php if (!empty($features)): ?>
                            <div class="flex">
                                <ul
                                    class="flex flex-col gap-2"
                                    data-gtm-element="features-list"
                                >
                                    <?php foreach ($features as $feature): ?>
                                        <li
                                            class="flex gap-2 items-center text-sm leading-[22px] <?php echo esc_attr($build_class('text', $feature_text)); ?>"
                                            data-gtm-element="feature-item"
                                            data-gtm-feature="<?php echo esc_attr(sanitize_title($feature)); ?>"
                                        >
                                            <span class="icon-check rounded-2xl flex items-center justify-center w-4 h-4 <?php echo esc_attr($build_class('text', $feature_check_color)); ?> <?php echo esc_attr($build_class('bg', $feature_check_bg)); ?>"></span>
                                            <?php echo esc_html($feature); ?>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                                    <?php endif; ?>
                                </div>

                    <div class="flex flex-col gap-4 items-center desktop:w-45">
                        <div class="w-full tablet:w-auto desktop:w-full">
                            <a
                                class="btn group btn--primary geot-element <?php echo esc_attr($build_class('bg', $cta_bg)); ?> <?php echo esc_attr('hover:' . $build_class('bg', $cta_hover_bg)); ?> <?php echo esc_attr($build_class('text', $cta_text)); ?> <?php echo esc_attr($cta_radius); ?> <?php echo esc_attr($cta_shadow); ?>"
                                href="<?php echo esc_url($casino_url); ?>"
                                target="_blank"
                                rel="nofollow noreferrer"
                                data-track-link="data-track-link"
                                data-promo-code=""
                                data-field="cta-link"
                                data-gtm-element="visit-site-cta"
                                data-gtm-brand="<?php echo esc_attr($brand_slug); ?>"
                                data-gtm-position="<?php echo esc_attr($position); ?>"
                            >
                                <span class="whitespace-nowrap">
                                    Visit Site
                                </span>
                                <span class="icon-arrow-right text-2xl"></span>
                            </a>
                        </div>

                        <a
                            href="#"
                            class="view-more-button no-underline <?php echo esc_attr($build_class('text', $brand_link)); ?> text-base"
                            x-text="showDetails ? 'Show less -' : 'Show more +'"
                            @click.prevent="showDetails = !showDetails"
                            data-gtm-element="details-toggle"
                            data-gtm-brand="<?php echo esc_attr($brand_slug); ?>"
                        >
                            Show more +
                        </a>
                    </div>
                </div>

                <div
                    style="max-height: 166px"
                    x-ref="container1"
                    x-bind:style="showDetails ? 'max-height: ' + $refs.container1.scrollHeight + 'px' : ''"
                    class="relative overflow-hidden transition-all max-h-0 duration-500 z-10"
                    id="<?php echo esc_attr($card_id); ?>"
                    data-gtm-element="details-container"
                >
                    <div class="flex justify-between mt-4 gap-4 desktop:gap-12 flex-col desktop:flex-row">
                        <?php if (!empty($payment_methods)): ?>
                            <div class="flex tablet:gap-12 desktop:w-72">
                                <div
                                    class="flex flex-col w-full tablet:w-1/2 desktop:w-full desktop:flex-basis-[302px] desktop:flex-grow-0 desktop:flex-shrink-0 gap-2"
                                    data-gtm-element="payment-methods-section"
                                >
                                    <span class="text-gray-600 text-base">Payment methods</span>
                                    <div class="grid grid-cols-5 gap-2 justify-between">
                                        <?php foreach ($payment_methods as $method): 
                                            $method_name = is_array($method) ? ($method['name'] ?? '') : $method;
                                            $method_logo = is_array($method) ? ($method['logo'] ?? '') : '';
                                            $method_slug = sanitize_title($method_name);
                                        ?>
                                            <?php if (!empty($method_logo)): ?>
                                                <img
                                                    loading="lazy"
                                                    decoding="async"
                                                    src="<?php echo esc_url($method_logo); ?>"
                                                    width="48"
                                                    height="36"
                                                    alt="<?php echo esc_attr($method_name); ?>"
                                                    class="border border-gray-300 rounded w-12 h-9"
                                                    data-gtm-element="payment-method"
                                                    data-gtm-method="<?php echo esc_attr($method_slug); ?>"
                                                >
                                    <?php endif; ?>
                        <?php endforeach; ?>
                                    </div>
                                </div>

                                <div class="hidden tablet:flex desktop:hidden tablet:flex-col tablet:gap-4 tablet:w-1/2 desktop:w-full">
                                    <?php if (!empty($bonus_wagering)): ?>
                                        <div
                                            class="flex flex-col text-base <?php echo esc_attr($metric_label); ?>"
                                            data-gtm-element="metric"
                                            data-gtm-metric="bonus-wagering"
                                            data-gtm-value="<?php echo esc_attr($bonus_wagering); ?>x-bonus"
                                        >
                                            Bonus Wagering
                                            <div>
                                                <span class="geot-element <?php echo esc_attr($metric_value); ?>">
                                                    <?php echo esc_html($bonus_wagering); ?>x bonus
                                                </span>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                    <?php if (!empty($payout_time)): ?>
                                        <div
                                            class="flex flex-col text-base <?php echo esc_attr($metric_label); ?>"
                                            data-gtm-element="metric"
                                            data-gtm-metric="payout-time"
                                            data-gtm-value="<?php echo esc_attr($payout_time); ?>"
                                        >
                                            Payout Time
                                            <span class="<?php echo esc_attr($metric_value); ?>"><?php echo esc_html($payout_time); ?></span>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endif; ?>

                        <div class="grid grid-cols-2 gap-4 tablet:gap-y-4 tablet:gap-x-12 desktop:grid-cols-3 desktop:gap-4 desktop:flex-grow">
                            <?php if (!empty($bonus_wagering)): ?>
                                <div
                                    class="flex tablet:hidden desktop:flex flex-col text-base <?php echo esc_attr($metric_label); ?>"
                                    data-gtm-element="metric"
                                    data-gtm-metric="bonus-wagering"
                                    data-gtm-value="<?php echo esc_attr($bonus_wagering); ?>x-bonus"
                                >
                                    Bonus Wagering
                                    <div>
                                        <span class="geot-element <?php echo esc_attr($metric_value); ?>">
                                            <?php echo esc_html($bonus_wagering); ?>x bonus
                                        </span>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <?php if (!empty($min_deposit)): ?>
                                <div
                                    class="flex flex-col text-base <?php echo esc_attr($metric_label); ?> tablet:w-1/2 desktop:w-full"
                                    data-gtm-element="metric"
                                    data-gtm-metric="min-deposit"
                                    data-gtm-value="<?php echo esc_attr($min_deposit); ?>"
                                >
                                    Min Deposit
                                    <div>
                                        <span class="geot-element <?php echo esc_attr($metric_value); ?>">
                                            <?php echo esc_html($min_deposit); ?>
                                        </span>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <?php if (!empty($games_count)): ?>
                                <div
                                    class="flex flex-col tablet:w-1/2 desktop:w-full text-base <?php echo esc_attr($metric_label); ?>"
                                    data-gtm-element="metric"
                                    data-gtm-metric="casino-games"
                                    data-gtm-value="<?php echo esc_attr($games_count); ?>"
                                >
                                    Casino Games
                                    <span class="<?php echo esc_attr($metric_value); ?>"><?php echo esc_html($games_count); ?></span>
                                </div>
                            <?php endif; ?>

                            <?php if (!empty($payout_time)): ?>
                                <div class="flex tablet:hidden desktop:flex">
                                    <div
                                        class="flex flex-col text-base <?php echo esc_attr($metric_label); ?>"
                                        data-gtm-element="metric"
                                        data-gtm-metric="payout-time"
                                        data-gtm-value="<?php echo esc_attr($payout_time); ?>"
                                    >
                                        Payout Time
                                        <span class="<?php echo esc_attr($metric_value); ?>"><?php echo esc_html($payout_time); ?></span>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <?php if (!empty($max_payout)): ?>
                                <div
                                    class="flex flex-col tablet:w-1/2 desktop:w-full text-base <?php echo esc_attr($metric_label); ?>"
                                    data-gtm-element="metric"
                                    data-gtm-metric="max-payout"
                                    data-gtm-value="<?php echo esc_attr($max_payout); ?>"
                                >
                                    Max Payout
                                    <span class="<?php echo esc_attr($metric_value); ?>"><?php echo esc_html($max_payout); ?></span>
                                </div>
                            <?php endif; ?>

                            <?php if (!empty($licenses)): ?>
                                <div
                                    class="flex flex-col tablet:w-1/2 text-base <?php echo esc_attr($metric_label); ?> desktop:w-full"
                                    data-gtm-element="metric"
                                    data-gtm-metric="licences"
                                    data-gtm-value="<?php echo esc_attr(strtolower(str_replace(' ', '-', $licenses))); ?>"
                                >
                                    Licences
                                    <span class="<?php echo esc_attr($metric_value); ?>"><?php echo $licenses; ?></span>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <?php
                    // Get pros/cons for this casino
                    // Start with API defaults, then override with block-level customizations if provided
                    $casino_key = 'casino-' . $position . '-' . $brand_slug;
                    $has_block_override = isset($pros_cons_data[$casino_key]);
                    
                    // Use API defaults if no block-level override exists, otherwise use block-level (even if empty)
                    if ($has_block_override) {
                        $casino_pros_cons = $pros_cons_data[$casino_key];
                        $pros = isset($casino_pros_cons['pros']) ? $casino_pros_cons['pros'] : $api_pros;
                        $cons = isset($casino_pros_cons['cons']) ? $casino_pros_cons['cons'] : $api_cons;
                    } else {
                        // No block-level override, use API defaults
                        $pros = $api_pros;
                        $cons = $api_cons;
                    }
                    ?>
                    
                    <?php if (!empty($pros) || !empty($cons)): ?>
                        <div class="flex flex-col gap-4 pt-4 mt-4 border-t border-solid <?php echo esc_attr($build_class('border', $rg_border)); ?>">
                            <?php if (!empty($pros)): ?>
                                <div data-gtm-element="pros-section">
                                    <h4 class="text-base font-semibold <?php echo esc_attr($build_class('text', $brand_link)); ?> mb-2">Pros:</h4>
                                    <ul class="list-disc list-inside flex flex-col gap-2">
                                        <?php foreach ($pros as $pro): ?>
                                            <?php if (!empty(trim($pro))): ?>
                                                <li class="text-sm <?php echo esc_attr($build_class('text', $feature_text)); ?>"><?php echo esc_html($pro); ?></li>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    </ul>
                                </div>
                            <?php endif; ?>
                            
                            <?php if (!empty($cons)): ?>
                                <div data-gtm-element="cons-section">
                                    <h4 class="text-base font-semibold text-red-600 mb-2">Cons:</h4>
                                    <ul class="list-disc list-inside flex flex-col gap-2">
                                        <?php foreach ($cons as $con): ?>
                                            <?php if (!empty(trim($con))): ?>
                                                <li class="text-sm <?php echo esc_attr($build_class('text', $feature_text)); ?>"><?php echo esc_html($con); ?></li>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    </ul>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <div
                        class="flex gap-1 items-center text-sm leading-5.5 text-gray-600 pt-4"
                        data-gtm-element="reviewer-section"
                    >
                        <span>
                            Reviewed by
                            <a
                                href="#"
                                class="underline"
                                data-gtm-element="reviewer-link"
                            >
                                DataFlair
                            </a>
                        </span>
                        <img
                            loading="lazy"
                            decoding="async"
                            src="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 16 16'%3E%3Cpath fill='%2300a86b' d='M8 0L10.122 5.09L16 5.878L12 9.755L12.938 16L8 13.245L3.062 16L4 9.755L0 5.878L5.878 5.09L8 0Z'/%3E%3C/svg%3E"
                            width="16"
                            height="16"
                            alt=""
                            data-gtm-element="review-verified-icon"
                        >
                    </div>
                </div>

                <div
                    class="desktop:flex gap-2 items-center text-sm leading-5.5 pt-4 mt-4 border-t border-solid <?php echo esc_attr($build_class('border', $rg_border)); ?> relative z-10"
                    data-gtm-element="responsible-gambling-section"
                >
                    <div>
                        <p
                            class="geot-element <?php echo esc_attr($build_class('text', $rg_text)); ?> inline-flex"
                            data-gtm-element="responsible-gambling-message"
                        >
                            21+ to wager – Please Gamble Responsibly. Gambling problem? Call 1-800-GAMBLER
                        </p>
                        <p
                            class="geot-element <?php echo esc_attr($build_class('text', $rg_text)); ?> text-sm inline-flex"
                            data-gtm-element="responsible-gambling-link"
                            data-gtm-value="gambleaware.org"
                        >
                            gambleaware.org
                        </p>
                    </div>
                </div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Enqueue frontend styles
     */
    public function enqueue_frontend_assets() {
        // Use file modification time as version to prevent caching issues
        $style_css_version = file_exists(DATAFLAIR_PLUGIN_DIR . 'assets/style.css') 
            ? filemtime(DATAFLAIR_PLUGIN_DIR . 'assets/style.css') 
            : DATAFLAIR_VERSION;
        
        wp_enqueue_style(
            'dataflair-toplists',
            DATAFLAIR_PLUGIN_URL . 'assets/style.css',
            array(),
            $style_css_version
        );
    }
    
    /**
     * Static flag to track if we've already checked for Alpine.js
     */
    private static $alpine_checked = false;
    private static $shortcode_used = false;
    
    /**
     * Check widget content for shortcode usage
     */
    public function check_widget_for_shortcode($text, $instance = null) {
        if (has_shortcode($text, 'dataflair_toplist')) {
            self::$shortcode_used = true;
        }
        return $text;
    }
    
    /**
     * Check if Alpine.js is already loaded and enqueue if needed
     */
    public function maybe_enqueue_alpine() {
        // Avoid checking multiple times
        if (self::$alpine_checked) {
            return;
        }
        
        self::$alpine_checked = true;
        
        // Check if shortcode or block is used on the page
        global $post;
        $has_shortcode = false;
        $has_block = false;
        
        // Check main post content
        if ($post) {
            $has_shortcode = has_shortcode($post->post_content, 'dataflair_toplist');
            $has_block = has_block('dataflair-toplists/toplist', $post);
        }
        
        // Check if widget shortcode was found
        if (self::$shortcode_used) {
            $has_shortcode = true;
        }
        
        // Check all posts in query (for archive pages, etc.)
        if (!$has_shortcode && !$has_block) {
            global $wp_query;
            if ($wp_query && !empty($wp_query->posts)) {
                foreach ($wp_query->posts as $query_post) {
                    if (has_shortcode($query_post->post_content, 'dataflair_toplist') ||
                        has_block('dataflair-toplists/toplist', $query_post)) {
                        $has_shortcode = true;
                        break;
                    }
                }
            }
        }
        
        // If no shortcode/block found, don't enqueue
        if (!$has_shortcode && !$has_block) {
            return;
        }
        
        // Check if Alpine.js is already enqueued by checking common handles
        $alpine_handles = array('alpinejs', 'alpine', 'alpine-js', 'alpine.js');
        $alpine_enqueued = false;
        
        foreach ($alpine_handles as $handle) {
            if (wp_script_is($handle, 'enqueued') || wp_script_is($handle, 'registered')) {
                $alpine_enqueued = true;
                break;
            }
        }
        
        // Also check if Alpine is loaded via inline script or other methods
        // by checking if it's in the global scripts queue
        if (!$alpine_enqueued) {
            global $wp_scripts;
            if ($wp_scripts && !empty($wp_scripts->queue)) {
                foreach ($wp_scripts->queue as $queued_handle) {
                    $script = $wp_scripts->registered[$queued_handle] ?? null;
                    if ($script && isset($script->src) && (
                        strpos($script->src, 'alpine') !== false ||
                        strpos($script->src, 'alpinejs') !== false
                    )) {
                        $alpine_enqueued = true;
                        break;
                    }
                }
            }
        }
        
        // Check if Alpine is loaded via theme or other plugins by checking script dependencies
        if (!$alpine_enqueued && isset($wp_scripts)) {
            foreach ($wp_scripts->registered as $handle => $script) {
                if (isset($script->deps) && is_array($script->deps)) {
                    foreach ($script->deps as $dep) {
                        if (in_array($dep, $alpine_handles)) {
                            $alpine_enqueued = true;
                            break 2;
                        }
                    }
                }
            }
        }
        
        // If Alpine.js is not found, enqueue it from CDN
        if (!$alpine_enqueued) {
            // Allow filtering the Alpine.js URL (for custom CDN or local version)
            $alpine_url = apply_filters('dataflair_alpinejs_url', 'https://cdn.jsdelivr.net/npm/alpinejs@3.13.5/dist/cdn.min.js');
            
            wp_enqueue_script(
                'alpinejs',
                $alpine_url,
                array(),
                '3.13.5',
                true
            );
            
            // Add defer attribute for better performance
            add_filter('script_loader_tag', array($this, 'add_alpine_defer_attribute'), 10, 2);
        }
    }
    
    /**
     * Add defer attribute to Alpine.js script tag
     */
    public function add_alpine_defer_attribute($tag, $handle) {
        if ('alpinejs' === $handle) {
            return str_replace(' src', ' defer src', $tag);
        }
        return $tag;
    }
    
    /**
     * Get last cron execution time
     */
    private function get_last_cron_time() {
        $last_run = get_option('dataflair_last_toplists_cron_run');
        $next_run = wp_next_scheduled('dataflair_sync_cron');

        $last_str = $last_run ? $this->time_ago($last_run) : 'never';
        $next_str = $next_run ? $this->time_until($next_run) : 'not scheduled';

        return 'Last sync: ' . $last_str . ' &mdash; Next sync: ' . $next_str;
    }
    
    /**
     * Register Gutenberg block
     */
    public function register_block() {
        if (!function_exists('register_block_type')) {
            return;
        }
        
        // Check for built block first, then source
        $block_json = DATAFLAIR_PLUGIN_DIR . 'build/block.json';
        if (!file_exists($block_json)) {
            $block_json = DATAFLAIR_PLUGIN_DIR . 'src/block.json';
        }
        
        if (file_exists($block_json)) {
            register_block_type($block_json, array(
                'render_callback' => array($this, 'render_block'),
                'version' => DATAFLAIR_VERSION
            ));
        }
    }
    
    /**
     * Enqueue editor assets for Gutenberg block
     */
    public function enqueue_editor_assets() {
        $editor_style = DATAFLAIR_PLUGIN_URL . 'assets/editor.css';
        wp_enqueue_style(
            'dataflair-toplist-editor',
            $editor_style,
            array(),
            DATAFLAIR_VERSION
        );
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
     * Render callback for the block
     */
    public function render_block($attributes) {
        // Get default values from settings (if set)
        $ribbon_bg_default = get_option('dataflair_ribbon_bg_color', 'brand-600');
        $ribbon_text_default = get_option('dataflair_ribbon_text_color', 'white');
        $cta_bg_default = get_option('dataflair_cta_bg_color', 'brand-600');
        $cta_text_default = get_option('dataflair_cta_text_color', 'white');
        
        // Default values from block.json (with settings as fallback)
        $defaults = array(
            'toplistId' => '',
            'title' => '',
            'limit' => 0,
            'ribbonBgColor' => $ribbon_bg_default,
            'ribbonTextColor' => $ribbon_text_default,
            'ribbonText' => 'Our Top Choice',
            'rankBgColor' => 'gray-100',
            'rankTextColor' => 'gray-900',
            'rankBorderRadius' => 'rounded',
            'brandLinkColor' => 'brand-600',
            'bonusLabelStyle' => 'text-gray-600',
            'bonusTextStyle' => 'text-gray-900 text-lg leading-6 font-semibold',
            'featureCheckBg' => 'green-100',
            'featureCheckColor' => 'green-600',
            'featureTextColor' => 'gray-600',
            'ctaBgColor' => $cta_bg_default,
            'ctaHoverBgColor' => 'brand-700',
            'ctaTextColor' => $cta_text_default,
            'ctaBorderRadius' => 'rounded',
            'ctaShadow' => 'shadow-md',
            'metricLabelStyle' => 'text-gray-600',
            'metricValueStyle' => 'text-gray-900 font-semibold',
            'rgBorderColor' => 'gray-300',
            'rgTextColor' => 'gray-600'
        );
        
        // Merge attributes with defaults (attributes take precedence)
        $atts = wp_parse_args($attributes, $defaults);
        
        $toplist_id = $atts['toplistId'];
        
        if (empty($toplist_id)) {
            return '<p>' . esc_html__('Please configure the toplist ID in the block settings.', 'dataflair-toplists') . '</p>';
        }
        
        // Pass all attributes to shortcode (using merged values)
        $shortcode_atts = array(
            'id' => $toplist_id,
            'title' => $atts['title'],
            'limit' => intval($atts['limit']),
            'ribbonBgColor' => $atts['ribbonBgColor'],
            'ribbonTextColor' => $atts['ribbonTextColor'],
            'ribbonText' => $atts['ribbonText'],
            'rankBgColor' => $atts['rankBgColor'],
            'rankTextColor' => $atts['rankTextColor'],
            'rankBorderRadius' => $atts['rankBorderRadius'],
            'brandLinkColor' => $atts['brandLinkColor'],
            'bonusLabelStyle' => $atts['bonusLabelStyle'],
            'bonusTextStyle' => $atts['bonusTextStyle'],
            'featureCheckBg' => $atts['featureCheckBg'],
            'featureCheckColor' => $atts['featureCheckColor'],
            'featureTextColor' => $atts['featureTextColor'],
            'ctaBgColor' => $atts['ctaBgColor'],
            'ctaHoverBgColor' => $atts['ctaHoverBgColor'],
            'ctaTextColor' => $atts['ctaTextColor'],
            'ctaBorderRadius' => $atts['ctaBorderRadius'],
            'ctaShadow' => $atts['ctaShadow'],
            'metricLabelStyle' => $atts['metricLabelStyle'],
            'metricValueStyle' => $atts['metricValueStyle'],
            'rgBorderColor' => $atts['rgBorderColor'],
            'rgTextColor' => $atts['rgTextColor'],
            'prosCons' => isset($atts['prosCons']) ? $atts['prosCons'] : array()
        );
        
        return $this->toplist_shortcode($shortcode_atts);
    }
    
    /**
     * Register REST API routes for block editor
     */
    public function register_rest_routes() {
        register_rest_route('dataflair/v1', '/toplists', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_toplists_rest'),
            'permission_callback' => function() {
                return current_user_can('edit_posts');
            }
        ));
        
        register_rest_route('dataflair/v1', '/toplists/(?P<id>\d+)/casinos', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_toplist_casinos_rest'),
            'permission_callback' => function() {
                return current_user_can('edit_posts');
            },
            'args' => array(
                'id' => array(
                    'required' => true,
                    'type' => 'integer',
                ),
            ),
        ));

        register_rest_route('dataflair/v1', '/health', array(
            'methods'             => 'GET',
            'callback'            => function() {
                global $wpdb;
                $table = $wpdb->prefix . DATAFLAIR_TABLE_NAME;
                $count = $wpdb->get_var("SELECT COUNT(*) FROM $table");
                return rest_ensure_response(array(
                    'status'     => 'ok',
                    'toplists'   => (int) $count,
                    'plugin_ver' => DATAFLAIR_VERSION,
                    'db_error'   => $wpdb->last_error ?: null,
                ));
            },
            'permission_callback' => function() { return current_user_can('manage_options'); },
        ));
    }
    
    /**
     * REST API callback to get available toplists
     */
    public function get_toplists_rest() {
        try {
            global $wpdb;
            $table_name = $wpdb->prefix . DATAFLAIR_TABLE_NAME;

            $toplists = $wpdb->get_results("SELECT api_toplist_id, name, slug FROM $table_name ORDER BY api_toplist_id ASC");

            if ($wpdb->last_error) {
                return new WP_Error('db_error', $wpdb->last_error, array('status' => 500));
            }

            $options = array();
            foreach ($toplists as $toplist) {
                $suffix = !empty($toplist->slug)
                    ? ' [' . $toplist->slug . ']'
                    : ' (ID: ' . $toplist->api_toplist_id . ')';
                $options[] = array(
                    'value' => (string) $toplist->api_toplist_id,
                    'label' => $toplist->name . $suffix,
                );
            }

            return rest_ensure_response($options);
        } catch (\Exception $e) {
            return new WP_Error('exception', $e->getMessage(), array('status' => 500));
        }
    }
    
    /**
     * REST API callback to get casinos for a toplist
     */
    public function get_toplist_casinos_rest($request) {
        global $wpdb;
        $table_name = $wpdb->prefix . DATAFLAIR_TABLE_NAME;
        $toplist_id = intval($request['id']);

        $toplist = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_name WHERE api_toplist_id = %d",
            $toplist_id
        ));

        if (!$toplist) {
            return new WP_Error('not_found', 'Toplist not found', array('status' => 404));
        }

        $data = json_decode($toplist->data, true);

        // Support multiple known data shapes (editions model added listItems)
        $items = null;
        if (isset($data['data']['items'])) {
            $items = $data['data']['items'];
        } elseif (isset($data['data']['listItems'])) {
            $items = $data['data']['listItems'];
        } elseif (isset($data['listItems'])) {
            $items = $data['listItems'];
        }

        if (empty($items)) {
            return rest_ensure_response(array());
        }

        $casinos = array();
        foreach ($items as $item) {
            $brand_name = '';
            if (isset($item['brand']['name'])) {
                $brand_name = $item['brand']['name'];
            } elseif (isset($item['brandName'])) {
                $brand_name = $item['brandName'];
            }
            if (empty($brand_name)) {
                continue;
            }
            $casinos[] = array(
                'position'  => isset($item['position']) ? $item['position'] : 0,
                'brandName' => $brand_name,
                'brandSlug' => sanitize_title($brand_name),
                'pros'      => !empty($item['pros']) ? $item['pros'] : array(),
                'cons'      => !empty($item['cons']) ? $item['cons'] : array(),
            );
        }

        return rest_ensure_response($casinos);
    }
    
    /**
     * Check if MySQL/MariaDB supports JSON data type
     * 
     * @return bool
     */
    private function supports_json_type() {
        global $wpdb;
        
        // Get MySQL version
        $version = $wpdb->get_var("SELECT VERSION()");
        
        if (empty($version)) {
            return false;
        }
        
        // Check if it's MariaDB
        if (stripos($version, 'mariadb') !== false) {
            // MariaDB 10.2.7+ supports JSON
            preg_match('/(\d+)\.(\d+)\.(\d+)/', $version, $matches);
            if (!empty($matches)) {
                $major = (int)$matches[1];
                $minor = (int)$matches[2];
                $patch = (int)$matches[3];
                return ($major > 10) || ($major == 10 && $minor > 2) || ($major == 10 && $minor == 2 && $patch >= 7);
            }
        } else {
            // MySQL 5.7.8+ supports JSON
            preg_match('/(\d+)\.(\d+)\.(\d+)/', $version, $matches);
            if (!empty($matches)) {
                $major = (int)$matches[1];
                $minor = (int)$matches[2];
                $patch = (int)$matches[3];
                return ($major > 5) || ($major == 5 && $minor > 7) || ($major == 5 && $minor == 7 && $patch >= 8);
            }
        }
        
        return false;
    }
    
    /**
     * Migrate data fields from longtext to JSON type
     */
    public function migrate_to_json_type() {
        // Guard: skip entirely if migration already completed.
        // MariaDB reports JSON columns as "longtext" in INFORMATION_SCHEMA, so
        // checking DATA_TYPE would always return false — causing an infinite loop.
        if (get_option('dataflair_json_migration_done')) {
            return;
        }

        global $wpdb;

        if (!$this->supports_json_type()) {
            // Mark done so we stop trying on every cron tick.
            update_option('dataflair_json_migration_done', '1');
            error_log('DataFlair: JSON type not supported by MySQL/MariaDB version. Staying on longtext.');
            return;
        }

        $table_name = $wpdb->prefix . DATAFLAIR_TABLE_NAME;
        $brands_table_name = $wpdb->prefix . DATAFLAIR_BRANDS_TABLE_NAME;

        $toplists_migrated = false;
        $brands_migrated   = false;

        // Migrate toplists table
        {
            // First, validate all JSON data
            $invalid_rows = $wpdb->get_results(
                "SELECT id FROM $table_name WHERE data IS NOT NULL AND data != ''",
                ARRAY_A
            );
            
            $valid_count = 0;
            $invalid_count = 0;
            
            foreach ($invalid_rows as $row) {
                $data = $wpdb->get_var($wpdb->prepare(
                    "SELECT data FROM $table_name WHERE id = %d",
                    $row['id']
                ));
                
                // Validate JSON
                json_decode($data);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $valid_count++;
                } else {
                    $invalid_count++;
                    error_log('DataFlair: Invalid JSON in toplist ID ' . $row['id'] . ': ' . json_last_error_msg());
                }
            }
            
            if ($invalid_count === 0) {
                $wpdb->query("ALTER TABLE $table_name MODIFY COLUMN data JSON NOT NULL");
                $toplists_migrated = true;
                error_log('DataFlair: Successfully migrated toplists data column to JSON type');
            } else {
                error_log("DataFlair: Cannot migrate toplists table - found $invalid_count invalid JSON rows");
            }
        }

        // Migrate brands table
        {
            $invalid_rows = $wpdb->get_results(
                "SELECT id FROM $brands_table_name WHERE data IS NOT NULL AND data != ''",
                ARRAY_A
            );

            $valid_count   = 0;
            $invalid_count = 0;

            foreach ($invalid_rows as $row) {
                $data = $wpdb->get_var($wpdb->prepare(
                    "SELECT data FROM $brands_table_name WHERE id = %d",
                    $row['id']
                ));

                json_decode($data);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $valid_count++;
                } else {
                    $invalid_count++;
                    error_log('DataFlair: Invalid JSON in brand ID ' . $row['id'] . ': ' . json_last_error_msg());
                }
            }

            if ($invalid_count === 0) {
                $wpdb->query("ALTER TABLE $brands_table_name MODIFY COLUMN data JSON NOT NULL");
                $brands_migrated = true;
                error_log('DataFlair: Successfully migrated brands data column to JSON type');
            } else {
                error_log("DataFlair: Cannot migrate brands table - found $invalid_count invalid JSON rows");
            }
        }

        // Mark migration done so it never runs again.
        // Run regardless of success — on MariaDB the column stays "longtext" even
        // after ALTER TABLE, so we must not rely on DATA_TYPE to detect completion.
        update_option('dataflair_json_migration_done', '1');
    }
}

// Initialize plugin
DataFlair_Toplists::get_instance();