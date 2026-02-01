<?php
/**
 * Test Toplist Fetching
 * 
 * Tests fetching and rendering toplists
 */

// Load WordPress if not already loaded
if (!defined('ABSPATH')) {
    // Try to find wp-load.php from the plugin directory
    $wp_load_paths = array(
        dirname(dirname(dirname(dirname(__FILE__)))) . '/wp-load.php', // Standard WordPress structure
        dirname(dirname(dirname(__FILE__))) . '/wp-load.php', // Alternative structure
        '../../../wp-load.php',
        '../../../../wp-load.php',
    );
    
    $wp_loaded = false;
    foreach ($wp_load_paths as $path) {
        if (file_exists($path)) {
            require_once($path);
            $wp_loaded = true;
            break;
        }
    }
    
    if (!$wp_loaded) {
        die('Error: Could not find wp-load.php. Please run this test from WordPress admin or via WP-CLI.');
    }
}

/**
 * Test toplist fetching
 */
function test_toplist_fetch() {
    echo "<h2>🧪 Testing Toplist Fetching</h2>\n";
    echo "<style>
        .test-container { max-width: 1200px; margin: 20px auto; padding: 20px; font-family: monospace; }
        .test-section { background: #f5f5f5; padding: 15px; margin: 10px 0; border-left: 4px solid #0073aa; }
        .test-pass { color: #00a32a; font-weight: bold; }
        .test-fail { color: #d63638; font-weight: bold; }
        .test-info { color: #2271b1; }
        .test-warning { color: #dba617; }
        pre { background: #1e1e1e; color: #d4d4d4; padding: 10px; overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; margin: 10px 0; }
        table th, table td { padding: 8px; text-align: left; border: 1px solid #ddd; }
        table th { background: #0073aa; color: white; }
    </style>\n";
    echo "<div class='test-container'>\n";
    
    global $wpdb;
    $table = $wpdb->prefix . 'dataflair_toplists';
    
    // Test 1: Check if table exists
    echo "<div class='test-section'>\n";
    echo "<h3>Test 1: Database Table</h3>\n";
    
    $table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table));
    if ($table_exists === $table) {
        echo "<p class='test-pass'>✓ Table exists: {$table}</p>\n";
    } else {
        echo "<p class='test-fail'>✗ Table does not exist: {$table}</p>\n";
        echo "</div>\n";
        echo "</div>\n";
        return;
    }
    
    // Count toplists
    $count = $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
    echo "<p class='test-info'>Total toplists in database: {$count}</p>\n";
    echo "</div>\n";
    
    // Test 2: Fetch a toplist
    echo "<div class='test-section'>\n";
    echo "<h3>Test 2: Fetch Toplist from Database</h3>\n";
    
    $toplist = $wpdb->get_row(
        "SELECT * FROM {$table} ORDER BY id LIMIT 1",
        ARRAY_A
    );
    
    if (!$toplist) {
        echo "<p class='test-fail'>✗ No toplists found in database</p>\n";
        echo "<p class='test-info'>Please sync toplists first in Dataflair plugin settings</p>\n";
        echo "</div>\n";
        echo "</div>\n";
        return;
    }
    
    echo "<p class='test-pass'>✓ Found toplist: <strong>{$toplist['name']}</strong></p>\n";
    echo "<p class='test-info'>API Toplist ID: {$toplist['api_toplist_id']}</p>\n";
    echo "<p class='test-info'>Database ID: {$toplist['id']}</p>\n";
    echo "<p class='test-info'>Last synced: {$toplist['last_synced']}</p>\n";
    echo "</div>\n";
    
    // Test 3: Parse toplist data
    echo "<div class='test-section'>\n";
    echo "<h3>Test 3: Parse Toplist Data</h3>\n";
    
    $data = json_decode($toplist['data'], true);
    if (!$data) {
        echo "<p class='test-fail'>✗ Toplist data is not valid JSON</p>\n";
        echo "</div>\n";
        echo "</div>\n";
        return;
    }
    
    echo "<p class='test-pass'>✓ Data parsed successfully</p>\n";
    
    if (isset($data['data']['name'])) {
        echo "<p class='test-info'>Toplist name: {$data['data']['name']}</p>\n";
    }
    
    if (isset($data['data']['items']) && is_array($data['data']['items'])) {
        $item_count = count($data['data']['items']);
        echo "<p class='test-pass'>✓ Found {$item_count} casino items</p>\n";
    } else {
        echo "<p class='test-fail'>✗ No items found in toplist data</p>\n";
        echo "</div>\n";
        echo "</div>\n";
        return;
    }
    echo "</div>\n";
    
    // Test 4: Analyze items
    echo "<div class='test-section'>\n";
    echo "<h3>Test 4: Analyze Toplist Items</h3>\n";
    
    $items = $data['data']['items'];
    $items_to_test = array_slice($items, 0, 3); // Test first 3 items
    
    echo "<table>\n";
    echo "<tr><th>Position</th><th>Brand Name</th><th>Has Logo</th><th>Has Offer</th><th>Has Rating</th><th>Has Tracker</th></tr>\n";
    
    foreach ($items_to_test as $item) {
        $brand = $item['brand'] ?? array();
        $offer = $item['offer'] ?? array();
        
        $has_logo = !empty($brand['logo']) || !empty($brand['brandLogo']) || !empty($brand['logoUrl']);
        $has_offer = !empty($offer['offerText']) || !empty($offer['bonus']);
        $has_rating = isset($item['rating']) || isset($brand['rating']);
        $has_tracker = !empty($offer['trackers']) && is_array($offer['trackers']) && !empty($offer['trackers'][0]['trackerLink']);
        
        $brand_name = $brand['name'] ?? 'Unknown';
        $position = $item['position'] ?? '?';
        
        echo "<tr>\n";
        echo "<td>{$position}</td>\n";
        echo "<td><strong>{$brand_name}</strong></td>\n";
        echo "<td>" . ($has_logo ? "<span class='test-pass'>✓</span>" : "<span class='test-warning'>⚠</span>") . "</td>\n";
        echo "<td>" . ($has_offer ? "<span class='test-pass'>✓</span>" : "<span class='test-warning'>⚠</span>") . "</td>\n";
        echo "<td>" . ($has_rating ? "<span class='test-pass'>✓</span>" : "<span class='test-warning'>⚠</span>") . "</td>\n";
        echo "<td>" . ($has_tracker ? "<span class='test-pass'>✓</span>" : "<span class='test-warning'>⚠</span>") . "</td>\n";
        echo "</tr>\n";
    }
    echo "</table>\n";
    echo "</div>\n";
    
    // Test 5: Test shortcode rendering
    echo "<div class='test-section'>\n";
    echo "<h3>Test 5: Shortcode Rendering</h3>\n";
    
    $shortcode = '[dataflair_toplist id="' . $toplist['api_toplist_id'] . '" limit="3"]';
    echo "<p class='test-info'>Shortcode: <code>{$shortcode}</code></p>\n";
    
    $rendered = do_shortcode($shortcode);
    
    if (!empty($rendered)) {
        echo "<p class='test-pass'>✓ Shortcode rendered successfully</p>\n";
        echo "<p class='test-info'>Output length: " . strlen($rendered) . " characters</p>\n";
        
        // Check for logo downloads
        if (strpos($rendered, '/assets/logos/') !== false) {
            echo "<p class='test-pass'>✓ Local logos are being used in output</p>\n";
        } else {
            echo "<p class='test-warning'>⚠ No local logo URLs found (may be using remote URLs)</p>\n";
        }
        
        // Show preview (truncated)
        echo "<details><summary>View rendered output (first 500 chars)</summary>\n";
        echo "<pre>" . esc_html(substr($rendered, 0, 500)) . "...</pre>\n";
        echo "</details>\n";
    } else {
        echo "<p class='test-fail'>✗ Shortcode returned empty output</p>\n";
    }
    echo "</div>\n";
    
    // Test 6: Logo download for items
    echo "<div class='test-section'>\n";
    echo "<h3>Test 6: Logo Download for Toplist Items</h3>\n";
    
    if (function_exists('strikeodds_download_and_save_logo')) {
        $logos_downloaded = 0;
        $logos_failed = 0;
        
        foreach (array_slice($items, 0, 5) as $item) {
            $brand = $item['brand'] ?? array();
            $api_brand_id = $brand['api_brand_id'] ?? null;
            
            if (!$api_brand_id) {
                continue;
            }
            
            // Extract logo URL
            $logo_url = '';
            $logo_keys = array('logo', 'brandLogo', 'logoUrl', 'image');
            
            foreach ($logo_keys as $key) {
                if (!empty($brand[$key])) {
                    if (is_array($brand[$key])) {
                        if (!empty($brand[$key]['rectangular'])) {
                            $logo_url = $brand[$key]['rectangular'];
                            break;
                        } elseif (!empty($brand[$key]['square'])) {
                            $logo_url = $brand[$key]['square'];
                            break;
                        }
                    } else {
                        $logo_url = $brand[$key];
                        break;
                    }
                }
            }
            
            if (!empty($logo_url) && filter_var($logo_url, FILTER_VALIDATE_URL)) {
                $local_url = strikeodds_download_and_save_logo($logo_url, $api_brand_id);
                if ($local_url) {
                    $logos_downloaded++;
                    echo "<p class='test-pass'>✓ Logo downloaded for {$brand['name']}</p>\n";
                } else {
                    $logos_failed++;
                    echo "<p class='test-warning'>⚠ Failed to download logo for {$brand['name']}</p>\n";
                }
            }
        }
        
        echo "<p class='test-info'>Logos downloaded: {$logos_downloaded}</p>\n";
        echo "<p class='test-info'>Logos failed: {$logos_failed}</p>\n";
    } else {
        echo "<p class='test-fail'>✗ Theme logo download function not available</p>\n";
    }
    echo "</div>\n";
    
    echo "</div>\n";
}

// Run test
if (php_sapi_name() !== 'cli' && isset($_GET['run_test'])) {
    test_toplist_fetch();
} elseif (php_sapi_name() === 'cli') {
    test_toplist_fetch();
}
