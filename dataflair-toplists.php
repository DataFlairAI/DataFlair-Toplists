<?php
/**
 * Plugin Name: DataFlair Toplists
 * Plugin URI: https://dataflair.ai
 * Description: Fetch and display casino toplists from DataFlair API
 * Version: 1.0.0
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
define('DATAFLAIR_VERSION', '1.2.0');
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
        
        // Shortcode
        add_shortcode('dataflair_toplist', array($this, 'toplist_shortcode'));
        
        // Gutenberg Block
        add_action('init', array($this, 'register_block'));
        
        // REST API for block editor
        add_action('rest_api_init', array($this, 'register_rest_routes'));
        
        // Cron
        add_action('dataflair_sync_cron', array($this, 'cron_sync_toplists'));
        add_action('dataflair_brands_sync_cron', array($this, 'cron_sync_brands'));
        
        // Custom cron schedule
        add_filter('cron_schedules', array($this, 'add_custom_cron_schedules'));
        
        // Enqueue frontend styles and scripts
        add_action('wp_enqueue_scripts', array($this, 'enqueue_frontend_assets'));
        
        // Enqueue admin scripts
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        
        // Check if shortcode/block is used and enqueue Alpine.js if needed
        add_action('wp_footer', array($this, 'maybe_enqueue_alpine'), 5);
        
        // Also check in widgets and other areas
        add_filter('widget_text', array($this, 'check_widget_for_shortcode'), 10, 2);
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
        
        // Schedule cron (every 2 days)
        if (!wp_next_scheduled('dataflair_sync_cron')) {
            wp_schedule_event(time(), 'twicedaily', 'dataflair_sync_cron');
        }
        
        // Schedule brands cron (every 15 minutes)
        if (!wp_next_scheduled('dataflair_brands_sync_cron')) {
            wp_schedule_event(time(), 'dataflair_15min', 'dataflair_brands_sync_cron');
        }
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
        $current_version = '1.2'; // Updated version with alternative toplists table
        
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
        $brands_table_name = $wpdb->prefix . DATAFLAIR_BRANDS_TABLE_NAME;
        $alternative_toplists_table = $wpdb->prefix . DATAFLAIR_ALTERNATIVE_TOPLISTS_TABLE_NAME;
        $charset_collate = $wpdb->get_charset_collate();
        
        // Check if brands table exists
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$brands_table_name'") === $brands_table_name;
        
        if ($table_exists) {
            // Check if new columns exist
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
            
            error_log('DataFlair: Database schema upgraded to version 1.2');
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
        // Keep dataflair_api_endpoints for internal use (populated by fetch_all_toplists)
        register_setting('dataflair_settings', 'dataflair_api_endpoints', $args);
        
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
            update_option('dataflair_api_token', sanitize_text_field($_POST['dataflair_api_token']));
        }
        
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
        
        wp_enqueue_script(
            'dataflair-admin',
            DATAFLAIR_PLUGIN_URL . 'assets/admin.js',
            array('jquery'),
            DATAFLAIR_VERSION,
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
            </nav>
            
            <form id="dataflair-settings-form">
                
                <?php if ($current_tab === 'api'): ?>
                    <!-- API Settings Tab -->
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
                    <p class="description">This will automatically discover and sync all available toplists from the DataFlair API. Existing endpoints will be updated.</p>
                    <p class="description">Last automatic sync: <?php echo $this->get_last_cron_time(); ?></p>
            
            <hr>
            
            <h2>Synced Toplists</h2>
            <?php if ($toplists): ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th style="width: 40px;"></th>
                            <th>WP ID</th>
                            <th>API ID</th>
                            <th>Name</th>
                            <th>Version</th>
                            <th>Items</th>
                            <th>Last Synced</th>
                            <th>Shortcode</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($toplists as $toplist): 
                            $data = json_decode($toplist->data, true);
                            $items_count = isset($data['data']['items']) ? count($data['data']['items']) : 0;
                        ?>
                        <tr class="toplist-row" data-toplist-id="<?php echo esc_attr($toplist->id); ?>">
                            <td>
                                <button type="button" class="toplist-toggle-btn" title="View Details">
                                    <span class="dashicons dashicons-arrow-right"></span>
                                </button>
                            </td>
                            <td><?php echo esc_html($toplist->id); ?></td>
                            <td><?php echo esc_html($toplist->api_toplist_id); ?></td>
                            <td><?php echo esc_html($toplist->name); ?></td>
                            <td><?php echo esc_html($toplist->version); ?></td>
                            <td><?php echo esc_html($items_count); ?></td>
                            <td><?php echo esc_html($toplist->last_synced); ?></td>
                            <td>
                                <code>[dataflair_toplist id="<?php echo esc_attr($toplist->api_toplist_id); ?>"]</code>
                            </td>
                        </tr>
                        <tr class="toplist-accordion-content" data-toplist-id="<?php echo esc_attr($toplist->id); ?>" style="display: none;">
                            <td colspan="8" style="padding: 0;">
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
                <li><strong>id</strong> (required): API toplist ID</li>
                <li><strong>title</strong> (optional): Custom title for the toplist</li>
                <li><strong>limit</strong> (optional): Number of casinos to show (default: all)</li>
            </ul>
            <p>Example: <code>[dataflair_toplist id="3" title="Best UK Casinos" limit="5"]</code></p>
                    
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
                <?php endif; ?>
            </form>
            
            <style>
                /* Toplist Accordion Styles */
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
     * AJAX handler to fetch all toplists from API and sync them
     */
    public function ajax_fetch_all_toplists() {
        check_ajax_referer('dataflair_fetch_all_toplists', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Unauthorized'));
        }
        
        $token = get_option('dataflair_api_token');
        if (empty($token)) {
            wp_send_json_error(array('message' => 'API token not configured. Please set your API token first.'));
        }
        
        // Use default base API URL
        $base_url = 'https://sigma.dataflair.ai/api/v1';
        
        // Fetch list of all toplists
        $list_url = $base_url . '/toplists';
        
        $response = wp_remote_get($list_url, array(
            'timeout' => 30,
            'headers' => array(
                'Accept' => 'application/json',
                'Authorization' => 'Bearer ' . $token
            )
        ));
        
        if (is_wp_error($response)) {
            $error_msg = 'Failed to fetch toplist list: ' . $response->get_error_message();
            error_log('DataFlair fetch_all_toplists error: ' . $error_msg);
            wp_send_json_error(array('message' => $error_msg));
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        
        error_log('DataFlair /toplists API Response Code: ' . $status_code);
        error_log('DataFlair /toplists API Response Body (first 500 chars): ' . substr($body, 0, 500));
        
        if ($status_code !== 200) {
            $error_msg = 'API returned status ' . $status_code . '. Response: ' . substr($body, 0, 200);
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
        
        // Extract toplist endpoints
        $toplist_endpoints = array();
        if (isset($data['data']) && is_array($data['data'])) {
            foreach ($data['data'] as $toplist) {
                if (isset($toplist['id'])) {
                    $toplist_endpoints[] = $base_url . '/toplists/' . $toplist['id'];
                }
            }
        }
        
        if (empty($toplist_endpoints)) {
            wp_send_json_error(array('message' => 'No toplists found in the API response.'));
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
    }
    
    /**
     * Cron sync handler for brands
     */
    public function cron_sync_brands() {
        $this->sync_all_brands();
    }
    
    /**
     * Sync all configured toplists
     */
    private function sync_all_toplists() {
        $token = get_option('dataflair_api_token');
        $endpoints = get_option('dataflair_api_endpoints');
        
        if (empty($token)) {
            return array('success' => false, 'message' => 'API token not configured');
        }
        
        if (empty($endpoints)) {
            return array('success' => false, 'message' => 'No endpoints configured');
        }
        
        $endpoints_array = array_filter(array_map('trim', explode("\n", $endpoints)));
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
        
        return array(
            'success' => true,
            'synced' => $synced,
            'errors' => $errors
        );
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
            
            <p class="description">This will fetch all active brands from the DataFlair API in batches of 15. Existing brands will be updated.</p>
            <p class="description">Auto-sync runs every 15 minutes. Last automatic sync: <?php echo $this->get_last_brands_cron_time(); ?></p>
            
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
                            <div class="custom-multiselect" data-filter="license">
                                <button type="button" class="dataflair-multiselect-toggle" data-filter-type="licenses">
                                    <span class="selected-text">All Licenses</span>
                                    <span class="dashicons dashicons-arrow-down-alt2"></span>
                                </button>
                                <div class="dataflair-multiselect-dropdown" style="display: none;">
                                    <div class="dataflair-multiselect-search">
                                        <input type="text" placeholder="Search licenses..." class="search-input">
                                    </div>
                                    <div class="multiselect-actions">
                                        <a href="#" class="dataflair-multiselect-select-all">Select All</a>
                                        <a href="#" class="dataflair-multiselect-clear">Clear</a>
                                    </div>
                                    <div class="dataflair-multiselect-options">
                                        <?php foreach ($all_licenses as $license): ?>
                                            <label class="multiselect-option">
                                                <input type="checkbox" value="<?php echo esc_attr($license); ?>">
                                                <span><?php echo esc_html($license); ?></span>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Geo Filter -->
                        <div class="filter-group">
                            <label>Top Geos</label>
                            <div class="custom-multiselect" data-filter="geo">
                                <button type="button" class="dataflair-multiselect-toggle" data-filter-type="top_geos">
                                    <span class="selected-text">All Geos</span>
                                    <span class="dashicons dashicons-arrow-down-alt2"></span>
                                </button>
                                <div class="dataflair-multiselect-dropdown" style="display: none;">
                                    <div class="dataflair-multiselect-search">
                                        <input type="text" placeholder="Search geos..." class="search-input">
                                    </div>
                                    <div class="multiselect-actions">
                                        <a href="#" class="dataflair-multiselect-select-all">Select All</a>
                                        <a href="#" class="dataflair-multiselect-clear">Clear</a>
                                    </div>
                                    <div class="dataflair-multiselect-options">
                                        <?php foreach ($all_geos as $geo): ?>
                                            <label class="multiselect-option">
                                                <input type="checkbox" value="<?php echo esc_attr($geo); ?>">
                                                <span><?php echo esc_html($geo); ?></span>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Payment Filter -->
                        <div class="filter-group">
                            <label>Payment Methods</label>
                            <div class="custom-multiselect" data-filter="payment">
                                <button type="button" class="dataflair-multiselect-toggle" data-filter-type="payment_methods">
                                    <span class="selected-text">All Payments</span>
                                    <span class="dashicons dashicons-arrow-down-alt2"></span>
                                </button>
                                <div class="dataflair-multiselect-dropdown" style="display: none;">
                                    <div class="dataflair-multiselect-search">
                                        <input type="text" placeholder="Search payments..." class="search-input">
                                    </div>
                                    <div class="multiselect-actions">
                                        <a href="#" class="dataflair-multiselect-select-all">Select All</a>
                                        <a href="#" class="dataflair-multiselect-clear">Clear</a>
                                    </div>
                                    <div class="dataflair-multiselect-options">
                                        <?php foreach ($all_payment_methods as $method): ?>
                                            <label class="multiselect-option">
                                                <input type="checkbox" value="<?php echo esc_attr($method); ?>">
                                                <span><?php echo esc_html($method); ?></span>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
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
                            <th style="width: 16%;" class="sortable">
                                <a href="#" class="sort-link" data-sort="name">
                                    Brand Name
                                    <span class="sorting-indicator">
                                        <span class="dashicons dashicons-sort"></span>
                                    </span>
                                </a>
                            </th>
                            <th style="width: 10%;">Product Type</th>
                            <th style="width: 14%;">Licenses</th>
                            <th style="width: 18%;">Top Geos</th>
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
                            <th style="width: 15%;">Last Synced</th>
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
                            <td colspan="9">
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
    private function get_last_brands_cron_time() {
        $timestamp = wp_next_scheduled('dataflair_brands_sync_cron');
        if ($timestamp) {
            return 'Next sync: ' . date('Y-m-d H:i:s', $timestamp);
        }
        return 'Not scheduled';
    }
    
    /**
     * AJAX handler to sync brands in batches (one page at a time)
     */
    public function ajax_sync_brands_batch() {
        check_ajax_referer('dataflair_sync_brands_batch', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Unauthorized'));
        }
        
        $token = get_option('dataflair_api_token');
        if (empty($token)) {
            wp_send_json_error(array('message' => 'API token not configured. Please set your API token first.'));
        }
        
        // Get the page number from request
        $page = isset($_POST['page']) ? intval($_POST['page']) : 1;
        
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
        
        $token = get_option('dataflair_api_token');
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
     * Sync a single page of brands from API (15 brands per page)
     */
    private function sync_brands_page($page, $token) {
        $base_url = 'https://sigma.dataflair.ai/api/v1';
        $brands_url = $base_url . '/brands?page=' . $page;
        
        $response = wp_remote_get($brands_url, array(
            'timeout' => 30,
            'headers' => array(
                'Accept' => 'application/json',
                'Authorization' => 'Bearer ' . $token
            )
        ));
        
        if (is_wp_error($response)) {
            $error_msg = 'Failed to fetch brands page ' . $page . ': ' . $response->get_error_message();
            error_log('DataFlair sync_brands_page error: ' . $error_msg);
            return array('success' => false, 'message' => $error_msg);
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        
        if ($status_code !== 200) {
            $error_msg = 'API returned status ' . $status_code . ' for page ' . $page;
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
        $token = get_option('dataflair_api_token');
        
        if (empty($token)) {
            return array('success' => false, 'message' => 'API token not configured');
        }
        
        $base_url = 'https://sigma.dataflair.ai/api/v1';
        $synced = 0;
        $errors = 0;
        $current_page = 1;
        $last_page = 1;
        
        global $wpdb;
        $brands_table_name = $wpdb->prefix . DATAFLAIR_BRANDS_TABLE_NAME;
        
        // Loop through all pages
        do {
            $brands_url = $base_url . '/brands?page=' . $current_page;
            
            $response = wp_remote_get($brands_url, array(
                'timeout' => 30,
                'headers' => array(
                    'Accept' => 'application/json',
                    'Authorization' => 'Bearer ' . $token
                )
            ));
            
            if (is_wp_error($response)) {
                $error_msg = 'Failed to fetch brands page ' . $current_page . ': ' . $response->get_error_message();
                error_log('DataFlair fetch_all_brands error: ' . $error_msg);
                return array('success' => false, 'message' => $error_msg);
            }
            
            $status_code = wp_remote_retrieve_response_code($response);
            $body = wp_remote_retrieve_body($response);
            
            if ($status_code !== 200) {
                $error_msg = 'API returned status ' . $status_code . ' for page ' . $current_page;
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
     * Fetch and store single toplist
     */
    private function fetch_and_store_toplist($endpoint, $token) {
        global $wpdb;
        $table_name = $wpdb->prefix . DATAFLAIR_TABLE_NAME;
        
        // Add token as query parameter
        $url = add_query_arg('token', $token, $endpoint);
        
        $response = wp_remote_get($url, array(
            'timeout' => 30,
            'headers' => array(
                'Accept' => 'application/json',
                'Authorization' => 'Bearer ' . $token
            )
        ));
        
        if (is_wp_error($response)) {
            $error_message = 'DataFlair API Error for ' . $endpoint . ': ' . $response->get_error_message();
            error_log($error_message);
            add_settings_error('dataflair_messages', 'dataflair_api_error', $error_message, 'error');
            return false;
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        
        // Log response for debugging
        error_log('DataFlair API Response Code: ' . $status_code . ' for URL: ' . $url);
        
        if ($status_code !== 200) {
            $error_message = 'DataFlair API returned status ' . $status_code . ' for ' . $endpoint . '. Response: ' . substr($body, 0, 200);
            error_log($error_message);
            add_settings_error('dataflair_messages', 'dataflair_api_error', $error_message, 'error');
            return false;
        }
        
        $data = json_decode($body, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            $error_message = 'DataFlair JSON Parse Error: ' . json_last_error_msg() . ' for ' . $endpoint;
            error_log($error_message);
            add_settings_error('dataflair_messages', 'dataflair_json_error', $error_message, 'error');
            return false;
        }
        
        if (!isset($data['data']['id'])) {
            $error_message = 'DataFlair API Error: Invalid response format for ' . $endpoint . '. Response: ' . substr($body, 0, 300);
            error_log($error_message);
            add_settings_error('dataflair_messages', 'dataflair_format_error', $error_message, 'error');
            return false;
        }
        
        $api_id = $data['data']['id'];
        $name = $data['data']['name'];
        $version = $data['data']['version'];
        
        // Insert or update
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM $table_name WHERE api_toplist_id = %d",
            $api_id
        ));
        
        if ($existing) {
            $wpdb->update(
                $table_name,
                array(
                    'name' => $name,
                    'data' => $body,
                    'version' => $version,
                    'last_synced' => current_time('mysql')
                ),
                array('api_toplist_id' => $api_id),
                array('%s', '%s', '%s', '%s'),
                array('%d')
            );
        } else {
            $wpdb->insert(
                $table_name,
                array(
                    'api_toplist_id' => $api_id,
                    'name' => $name,
                    'data' => $body,
                    'version' => $version,
                    'last_synced' => current_time('mysql')
                ),
                array('%d', '%s', '%s', '%s', '%s')
            );
        }
        
        return true;
    }
    
    /**
     * Shortcode handler
     */
    public function toplist_shortcode($atts) {
        // Extract shortcode-specific attributes
        $shortcode_defaults = array(
            'id' => '',
            'title' => '',
            'limit' => 0
        );
        
        // Merge with defaults but preserve all other attributes (for customization)
        $atts = wp_parse_args($atts, $shortcode_defaults);
        
        if (empty($atts['id'])) {
            return '<p style="color: red;">DataFlair Error: Toplist ID is required</p>';
        }
        
        global $wpdb;
        $table_name = $wpdb->prefix . DATAFLAIR_TABLE_NAME;
        
        // Get toplist by API ID
        $toplist = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_name WHERE api_toplist_id = %d",
            $atts['id']
        ));
        
        if (!$toplist) {
            return '<p style="color: red;">DataFlair Error: Toplist ID ' . esc_html($atts['id']) . ' not found. Please sync first.</p>';
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
     * Render individual casino card
     */
    private function render_casino_card($item, $toplist_id, $customizations = array(), $pros_cons_data = array()) {
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
                            x-text="showDetails ? 'Less Information -' : 'More Information +'"
                            @click.prevent="showDetails = !showDetails"
                            data-gtm-element="details-toggle"
                            data-gtm-brand="<?php echo esc_attr($brand_slug); ?>"
                        >
                            More Information +
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
        wp_enqueue_style(
            'dataflair-toplists',
            DATAFLAIR_PLUGIN_URL . 'assets/style.css',
            array(),
            DATAFLAIR_VERSION
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
        $timestamp = wp_next_scheduled('dataflair_sync_cron');
        if ($timestamp) {
            return 'Next sync: ' . date('Y-m-d H:i:s', $timestamp);
        }
        return 'Not scheduled';
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
    }
    
    /**
     * REST API callback to get available toplists
     */
    public function get_toplists_rest() {
        global $wpdb;
        $table_name = $wpdb->prefix . DATAFLAIR_TABLE_NAME;
        
        $toplists = $wpdb->get_results("SELECT api_toplist_id, name FROM $table_name ORDER BY api_toplist_id ASC");
        
        $options = array();
        foreach ($toplists as $toplist) {
            $options[] = array(
                'value' => (string)$toplist->api_toplist_id,
                'label' => $toplist->name . ' (ID: ' . $toplist->api_toplist_id . ')'
            );
        }
        
        return rest_ensure_response($options);
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
        
        if (!isset($data['data']['items'])) {
            return rest_ensure_response(array());
        }
        
        $casinos = array();
        foreach ($data['data']['items'] as $item) {
            $brand = $item['brand'];
            $casinos[] = array(
                'position' => $item['position'],
                'brandName' => $brand['name'],
                'brandSlug' => sanitize_title($brand['name']),
                'pros' => !empty($item['pros']) ? $item['pros'] : array(),
                'cons' => !empty($item['cons']) ? $item['cons'] : array()
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
        global $wpdb;
        
        if (!$this->supports_json_type()) {
            error_log('DataFlair: JSON type not supported by MySQL version. Skipping migration.');
            return;
        }
        
        $table_name = $wpdb->prefix . DATAFLAIR_TABLE_NAME;
        $brands_table_name = $wpdb->prefix . DATAFLAIR_BRANDS_TABLE_NAME;
        
        // Check current column type
        $toplist_column = $wpdb->get_row($wpdb->prepare(
            "SELECT DATA_TYPE FROM INFORMATION_SCHEMA.COLUMNS 
            WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'data'",
            DB_NAME,
            $table_name
        ));
        
        $brand_column = $wpdb->get_row($wpdb->prepare(
            "SELECT DATA_TYPE FROM INFORMATION_SCHEMA.COLUMNS 
            WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'data'",
            DB_NAME,
            $brands_table_name
        ));
        
        // Migrate toplists table
        if ($toplist_column && strtolower($toplist_column->DATA_TYPE) !== 'json') {
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
                // All data is valid JSON, proceed with migration
                $wpdb->query("ALTER TABLE $table_name MODIFY COLUMN data JSON NOT NULL");
                error_log('DataFlair: Successfully migrated toplists data column to JSON type');
            } else {
                error_log("DataFlair: Cannot migrate toplists table - found $invalid_count invalid JSON rows");
            }
        }
        
        // Migrate brands table
        if ($brand_column && strtolower($brand_column->DATA_TYPE) !== 'json') {
            // First, validate all JSON data
            $invalid_rows = $wpdb->get_results(
                "SELECT id FROM $brands_table_name WHERE data IS NOT NULL AND data != ''",
                ARRAY_A
            );
            
            $valid_count = 0;
            $invalid_count = 0;
            
            foreach ($invalid_rows as $row) {
                $data = $wpdb->get_var($wpdb->prepare(
                    "SELECT data FROM $brands_table_name WHERE id = %d",
                    $row['id']
                ));
                
                // Validate JSON
                json_decode($data);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $valid_count++;
                } else {
                    $invalid_count++;
                    error_log('DataFlair: Invalid JSON in brand ID ' . $row['id'] . ': ' . json_last_error_msg());
                }
            }
            
            if ($invalid_count === 0) {
                // All data is valid JSON, proceed with migration
                $wpdb->query("ALTER TABLE $brands_table_name MODIFY COLUMN data JSON NOT NULL");
                error_log('DataFlair: Successfully migrated brands data column to JSON type');
            } else {
                error_log("DataFlair: Cannot migrate brands table - found $invalid_count invalid JSON rows");
            }
        }
    }
}

// Initialize plugin
DataFlair_Toplists::get_instance();