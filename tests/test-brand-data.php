<?php
/**
 * Test Brand Data Extraction
 * 
 * Tests all data points that should be extracted from brand data
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
 * Test brand data extraction
 */
function test_brand_data_extraction() {
    echo "<h2>🧪 Testing Brand Data Extraction</h2>\n";
    echo "<style>
        .test-container { max-width: 1200px; margin: 20px auto; padding: 20px; font-family: monospace; }
        .test-section { background: #f5f5f5; padding: 15px; margin: 10px 0; border-left: 4px solid #0073aa; }
        .test-pass { color: #00a32a; font-weight: bold; }
        .test-fail { color: #d63638; font-weight: bold; }
        .test-info { color: #2271b1; }
        .test-warning { color: #dba617; }
        .data-point { background: white; padding: 10px; margin: 5px 0; border: 1px solid #ddd; }
        pre { background: #1e1e1e; color: #d4d4d4; padding: 10px; overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; margin: 10px 0; }
        table th, table td { padding: 8px; text-align: left; border: 1px solid #ddd; }
        table th { background: #0073aa; color: white; }
    </style>\n";
    echo "<div class='test-container'>\n";
    
    // Test 1: Get API configuration
    echo "<div class='test-section'>\n";
    echo "<h3>Test 1: API Configuration</h3>\n";
    
    $api_base_url = get_option('dataflair_api_base_url', 'https://sigma.dataflair.ai/api/v1');
    $api_token = get_option('dataflair_api_token', '');
    
    echo "<p class='test-info'>API Base URL: <strong>{$api_base_url}</strong></p>\n";
    
    if (empty($api_token)) {
        echo "<p class='test-fail'>✗ API Token is not configured</p>\n";
        echo "<p class='test-info'>Please configure the API token in DataFlair → Settings</p>\n";
        echo "</div>\n";
        echo "</div>\n";
        return;
    } else {
        echo "<p class='test-pass'>✓ API Token is configured (length: " . strlen($api_token) . " characters)</p>\n";
    }
    echo "</div>\n";
    
    // Test 2: Fetch brand from API
    echo "<div class='test-section'>\n";
    echo "<h3>Test 2: Fetch Brand from API</h3>\n";
    
    $brands_url = rtrim($api_base_url, '/') . '/brands';
    echo "<p class='test-info'>Fetching from: <a href='{$brands_url}' target='_blank'>{$brands_url}</a></p>\n";
    
    $headers = array(
        'Authorization' => 'Bearer ' . $api_token,
        'Content-Type' => 'application/json',
    );
    
    $response = wp_remote_get($brands_url, array(
        'headers' => $headers,
        'timeout' => 30,
    ));
    
    if (is_wp_error($response)) {
        echo "<p class='test-fail'>✗ API Request failed: " . $response->get_error_message() . "</p>\n";
        echo "</div>\n";
        echo "</div>\n";
        return;
    }
    
    $status_code = wp_remote_retrieve_response_code($response);
    $body = wp_remote_retrieve_body($response);
    
    echo "<p class='test-info'>Response Code: {$status_code}</p>\n";
    
    if ($status_code !== 200) {
        echo "<p class='test-fail'>✗ API returned error status {$status_code}</p>\n";
        echo "<p class='test-info'>Response: " . esc_html(substr($body, 0, 500)) . "</p>\n";
        echo "</div>\n";
        echo "</div>\n";
        return;
    }
    
    $api_data = json_decode($body, true);
    if (!$api_data) {
        echo "<p class='test-fail'>✗ API response is not valid JSON</p>\n";
        echo "<p class='test-info'>Response: " . esc_html(substr($body, 0, 500)) . "</p>\n";
        echo "</div>\n";
        echo "</div>\n";
        return;
    }
    
    echo "<p class='test-pass'>✓ API response received and parsed successfully</p>\n";
    
    // Get first brand from API response
    if (empty($api_data['data']) || !is_array($api_data['data']) || empty($api_data['data'][0])) {
        echo "<p class='test-fail'>✗ No brands found in API response</p>\n";
        echo "<p class='test-info'>Response structure: " . esc_html(print_r(array_keys($api_data), true)) . "</p>\n";
        echo "</div>\n";
        echo "</div>\n";
        return;
    }
    
    $brand = $api_data['data'][0];
    $data = $brand; // Use API response directly
    
    echo "<p class='test-pass'>✓ Found test brand: <strong>{$brand['name']}</strong></p>\n";
    echo "<p class='test-info'>API Brand ID: {$brand['id']}</p>\n";
    
    // Show available keys in API response
    echo "<details><summary>View all available keys in API response</summary>\n";
    echo "<p class='test-info'>Available keys: " . implode(', ', array_keys($brand)) . "</p>\n";
    echo "</details>\n";
    
    echo "</div>\n";
    
    // Test all data points
    $data_points = array(
        'brand_name' => array(
            'keys' => array('name', 'brandName', 'title'),
            'required' => true,
            'description' => 'Brand/Casino name'
        ),
        'logo' => array(
            'keys' => array('logo', 'logoUrl', 'logo_url', 'image', 'brandLogo', 'logoImage'),
            'required' => false,
            'description' => 'Brand logo URL'
        ),
        'url' => array(
            'keys' => array('url', 'website', 'siteUrl', 'homepage'),
            'required' => false,
            'description' => 'Brand website URL'
        ),
        'rating' => array(
            'keys' => array('rating', 'averageRating', 'score', 'userRating'),
            'required' => false,
            'description' => 'Brand rating (0-5)'
        ),
        'licenses' => array(
            'keys' => array('licenses', 'license', 'licensing'),
            'required' => false,
            'description' => 'Gaming licenses'
        ),
        'payment_methods' => array(
            'keys' => array('payment_methods', 'paymentMethods', 'payments'),
            'required' => false,
            'description' => 'Accepted payment methods'
        ),
    );
    
    // Test 3: Test offers data
    echo "<div class='test-section'>\n";
    echo "<h3>Test 3: Offers Data (from API)</h3>\n";
    
    if (!empty($data['offers']) && is_array($data['offers'])) {
        $first_offer = reset($data['offers']);
        echo "<p class='test-pass'>✓ Offers array found with " . count($data['offers']) . " offer(s)</p>\n";
        
        $offer_data_points = array(
            'offerText' => 'Offer text/bonus description',
            'bonus_wagering_requirement' => 'Bonus wagering requirement',
            'minimum_deposit' => 'Minimum deposit',
            'has_free_spins' => 'Has free spins (boolean)',
            'bonus_expiry_date' => 'Bonus expiry date',
            'bonus_code' => 'Bonus code',
            'trackers' => 'Tracking URLs array',
        );
        
        echo "<table>\n";
        echo "<tr><th>Field</th><th>Description</th><th>Value</th><th>Status</th></tr>\n";
        
        foreach ($offer_data_points as $key => $description) {
            $value = isset($first_offer[$key]) ? $first_offer[$key] : null;
            $exists = isset($first_offer[$key]);
            
            if ($exists) {
                if (is_array($value)) {
                    $display_value = 'Array (' . count($value) . ' items)';
                    if ($key === 'trackers' && !empty($value[0]['trackerLink'])) {
                        $display_value .= ' - Tracker: ' . $value[0]['trackerLink'];
                    }
                } elseif (is_bool($value)) {
                    $display_value = $value ? 'true' : 'false';
                } else {
                    $display_value = strlen($value) > 50 ? substr($value, 0, 50) . '...' : $value;
                }
                echo "<tr><td><strong>{$key}</strong></td><td>{$description}</td><td>{$display_value}</td><td class='test-pass'>✓</td></tr>\n";
            } else {
                echo "<tr><td><strong>{$key}</strong></td><td>{$description}</td><td>—</td><td class='test-warning'>⚠</td></tr>\n";
            }
        }
        echo "</table>\n";
    } else {
        echo "<p class='test-warning'>⚠ No offers found in brand data</p>\n";
    }
    echo "</div>\n";
    
    // Test 4: Test main data points
    echo "<div class='test-section'>\n";
    echo "<h3>Test 4: Main Brand Data Points (from API)</h3>\n";
    echo "<table>\n";
    echo "<tr><th>Field</th><th>Description</th><th>Keys Checked</th><th>Value</th><th>Status</th></tr>\n";
    
    foreach ($data_points as $point_name => $point_config) {
        $found = false;
        $value = null;
        $found_key = null;
        
        foreach ($point_config['keys'] as $key) {
            if (isset($data[$key]) && !empty($data[$key])) {
                $found = true;
                $value = $data[$key];
                $found_key = $key;
                break;
            }
        }
        
        $status_class = $found ? 'test-pass' : ($point_config['required'] ? 'test-fail' : 'test-warning');
        $status_icon = $found ? '✓' : ($point_config['required'] ? '✗' : '⚠');
        
        $display_value = '—';
        if ($found) {
            if (is_array($value)) {
                $display_value = 'Array (' . count($value) . ' items)';
            } elseif (is_string($value)) {
                $display_value = strlen($value) > 50 ? substr($value, 0, 50) . '...' : $value;
            } else {
                $display_value = (string)$value;
            }
        }
        
        $keys_list = implode(', ', $point_config['keys']);
        echo "<tr>\n";
        echo "<td><strong>{$point_name}</strong></td>\n";
        echo "<td>{$point_config['description']}</td>\n";
        echo "<td>{$keys_list}</td>\n";
        echo "<td>{$display_value}</td>\n";
        echo "<td class='{$status_class}'>{$status_icon} " . ($found ? "Found (key: {$found_key})" : 'Not found') . "</td>\n";
        echo "</tr>\n";
    }
    echo "</table>\n";
    echo "</div>\n";
    
    // Test nested structures
    echo "<div class='test-section'>\n";
    echo "<h3>Nested Data Structures</h3>\n";
    
    // Test logo object
    if (!empty($data['logo']) && is_array($data['logo'])) {
        echo "<p class='test-info'>Logo object structure:</p>\n";
        echo "<pre>" . print_r($data['logo'], true) . "</pre>\n";
    }
    
    // Test trackers
    if (!empty($data['offers'][0]['trackers']) && is_array($data['offers'][0]['trackers'])) {
        echo "<p class='test-info'>First tracker structure:</p>\n";
        echo "<pre>" . print_r($data['offers'][0]['trackers'][0], true) . "</pre>\n";
    }
    
    echo "</div>\n";
    
    // Test 6: Summary
    echo "<div class='test-section'>\n";
    echo "<h3>Test 6: Test Summary</h3>\n";
    
    $required_found = 0;
    $required_total = 0;
    $optional_found = 0;
    $optional_total = 0;
    
    foreach ($data_points as $point_name => $point_config) {
        $found = false;
        foreach ($point_config['keys'] as $key) {
            if (isset($data[$key]) && !empty($data[$key])) {
                $found = true;
                break;
            }
        }
        
        if ($point_config['required']) {
            $required_total++;
            if ($found) $required_found++;
        } else {
            $optional_total++;
            if ($found) $optional_found++;
        }
    }
    
    echo "<p class='test-info'>Required fields: {$required_found}/{$required_total} found</p>\n";
    echo "<p class='test-info'>Optional fields: {$optional_found}/{$optional_total} found</p>\n";
    
    if ($required_found === $required_total) {
        echo "<p class='test-pass'>✓ All required fields are present</p>\n";
    } else {
        echo "<p class='test-fail'>✗ Some required fields are missing</p>\n";
    }
    
    echo "</div>\n";
    
    echo "</div>\n";
}

// Run test
if (php_sapi_name() !== 'cli' && isset($_GET['run_test'])) {
    test_brand_data_extraction();
} elseif (php_sapi_name() === 'cli') {
    test_brand_data_extraction();
}
