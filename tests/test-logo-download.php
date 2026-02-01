<?php
/**
 * Test Logo Download Functionality
 * 
 * Run this test from WordPress admin or via WP-CLI:
 * wp eval-file tests/test-logo-download.php
 * 
 * Or access via: /wp-admin/admin.php?page=dataflair-tests&test=logo
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
 * Test logo download for a brand
 */
function test_logo_download() {
    echo "<h2>🧪 Testing Logo Download Functionality</h2>\n";
    echo "<style>
        .test-container { max-width: 1200px; margin: 20px auto; padding: 20px; font-family: monospace; }
        .test-section { background: #f5f5f5; padding: 15px; margin: 10px 0; border-left: 4px solid #0073aa; }
        .test-pass { color: #00a32a; font-weight: bold; }
        .test-fail { color: #d63638; font-weight: bold; }
        .test-info { color: #2271b1; }
        .test-warning { color: #dba617; }
        pre { background: #1e1e1e; color: #d4d4d4; padding: 10px; overflow-x: auto; }
    </style>\n";
    echo "<div class='test-container'>\n";
    
    // Test 1: Check if theme function exists
    echo "<div class='test-section'>\n";
    echo "<h3>Test 1: Theme Logo Download Function</h3>\n";
    if (function_exists('strikeodds_download_and_save_logo')) {
        echo "<p class='test-pass'>✓ Theme function 'strikeodds_download_and_save_logo' exists</p>\n";
    } else {
        echo "<p class='test-fail'>✗ Theme function 'strikeodds_download_and_save_logo' NOT found</p>\n";
        echo "<p class='test-info'>Make sure the theme's inc/reviews.php is loaded</p>\n";
        echo "</div>\n";
        echo "</div>\n";
        return;
    }
    echo "</div>\n";
    
    // Test 2: Check logos directory
    echo "<div class='test-section'>\n";
    echo "<h3>Test 2: Logos Directory</h3>\n";
    $theme_dir = get_template_directory();
    $logos_dir = $theme_dir . '/assets/logos';
    
    if (file_exists($logos_dir)) {
        echo "<p class='test-pass'>✓ Logos directory exists: {$logos_dir}</p>\n";
        if (is_writable($logos_dir)) {
            echo "<p class='test-pass'>✓ Directory is writable</p>\n";
        } else {
            echo "<p class='test-fail'>✗ Directory is NOT writable</p>\n";
            echo "<p class='test-info'>Run: chmod 755 {$logos_dir}</p>\n";
        }
    } else {
        echo "<p class='test-warning'>⚠ Directory does not exist, will be created on first download</p>\n";
    }
    echo "</div>\n";
    
    // Test 3: Get a test brand from database
    echo "<div class='test-section'>\n";
    echo "<h3>Test 3: Fetch Test Brand from Database</h3>\n";
    global $wpdb;
    $table = $wpdb->prefix . 'dataflair_brands';
    
    $brand = $wpdb->get_row(
        "SELECT * FROM {$table} ORDER BY id LIMIT 1",
        ARRAY_A
    );
    
    if (!$brand) {
        echo "<p class='test-fail'>✗ No brands found in database</p>\n";
        echo "<p class='test-info'>Please sync brands first in Dataflair plugin settings</p>\n";
        echo "</div>\n";
        echo "</div>\n";
        return;
    }
    
    echo "<p class='test-pass'>✓ Found test brand: <strong>{$brand['name']}</strong> (API ID: {$brand['api_brand_id']})</p>\n";
    
    $data = json_decode($brand['data'], true);
    if (!$data) {
        echo "<p class='test-fail'>✗ Brand data is not valid JSON</p>\n";
        echo "</div>\n";
        echo "</div>\n";
        return;
    }
    echo "</div>\n";
    
    // Test 4: Extract logo URL from brand data
    echo "<div class='test-section'>\n";
    echo "<h3>Test 4: Extract Logo URL from Brand Data</h3>\n";
    
    $logo_url = '';
    $logo_keys = array('logo', 'brandLogo', 'logoUrl', 'image', 'logoImage');
    
    foreach ($logo_keys as $key) {
        if (!empty($data[$key])) {
            if (is_array($data[$key])) {
                if (!empty($data[$key]['rectangular'])) {
                    $logo_url = $data[$key]['rectangular'];
                    break;
                } elseif (!empty($data[$key]['square'])) {
                    $logo_url = $data[$key]['square'];
                    break;
                } elseif (!empty($data[$key]['url'])) {
                    $logo_url = $data[$key]['url'];
                    break;
                }
            } else {
                $logo_url = $data[$key];
                break;
            }
        }
    }
    
    if (empty($logo_url)) {
        echo "<p class='test-fail'>✗ No logo URL found in brand data</p>\n";
        echo "<p class='test-info'>Available keys: " . implode(', ', array_keys($data)) . "</p>\n";
    } else {
        echo "<p class='test-pass'>✓ Logo URL extracted: <a href='{$logo_url}' target='_blank'>{$logo_url}</a></p>\n";
        
        // Test 5: Download logo
        echo "<div class='test-section'>\n";
        echo "<h3>Test 5: Download and Save Logo</h3>\n";
        
        $local_url = strikeodds_download_and_save_logo($logo_url, $brand['api_brand_id']);
        
        if ($local_url) {
            echo "<p class='test-pass'>✓ Logo downloaded successfully!</p>\n";
            echo "<p class='test-info'>Local URL: <a href='{$local_url}' target='_blank'>{$local_url}</a></p>\n";
            
            // Check if file exists
            $theme_dir = get_template_directory();
            $logos_dir = $theme_dir . '/assets/logos';
            $filename = basename(parse_url($local_url, PHP_URL_PATH));
            $file_path = $logos_dir . '/' . $filename;
            
            if (file_exists($file_path)) {
                $file_size = filesize($file_path);
                echo "<p class='test-pass'>✓ File exists: {$file_path}</p>\n";
                echo "<p class='test-info'>File size: " . number_format($file_size) . " bytes</p>\n";
                echo "<p class='test-info'><img src='{$local_url}' alt='Logo' style='max-width: 200px; border: 1px solid #ddd; padding: 10px; background: #1a1a1a;'></p>\n";
            } else {
                echo "<p class='test-fail'>✗ File does not exist at expected path</p>\n";
            }
        } else {
            echo "<p class='test-fail'>✗ Logo download failed</p>\n";
            echo "<p class='test-info'>Check error logs for details</p>\n";
        }
        echo "</div>\n";
    }
    echo "</div>\n";
    
    // Test 6: Test logo URL validation
    echo "<div class='test-section'>\n";
    echo "<h3>Test 6: Logo URL Validation</h3>\n";
    
    $test_urls = array(
        'http://mex.dataflair.ai.test/images/brand_logos/500x250/432_neonvegascasino_500x250.svg' => 'Valid remote URL',
        'https://example.com/logo.png' => 'Valid HTTPS URL',
        'invalid-url' => 'Invalid URL',
        '' => 'Empty string',
    );
    
    foreach ($test_urls as $url => $description) {
        $is_valid = !empty($url) && filter_var($url, FILTER_VALIDATE_URL);
        if ($is_valid) {
            echo "<p class='test-pass'>✓ {$description}: Valid</p>\n";
        } else {
            echo "<p class='test-info'>ℹ {$description}: Invalid (expected)</p>\n";
        }
    }
    echo "</div>\n";
    
    echo "</div>\n";
}

// Run test if accessed directly
if (php_sapi_name() !== 'cli' && isset($_GET['run_test'])) {
    test_logo_download();
} elseif (php_sapi_name() === 'cli') {
    // For WP-CLI
    test_logo_download();
}
