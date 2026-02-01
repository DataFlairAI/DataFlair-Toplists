<?php
/**
 * Test Logo URL After Local Save
 * 
 * Tests what the logo URL looks like after saving to local folder
 */

// Load WordPress if not already loaded
if (!defined('ABSPATH')) {
    $wp_load_paths = array(
        dirname(dirname(dirname(dirname(__FILE__)))) . '/wp-load.php',
        dirname(dirname(dirname(__FILE__))) . '/wp-load.php',
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
 * Test logo URL structure
 */
function test_logo_url_structure() {
    echo "<h2>🧪 Testing Logo URL After Local Save</h2>\n";
    echo "<style>
        .test-container { max-width: 1200px; margin: 20px auto; padding: 20px; font-family: monospace; }
        .test-section { background: #f5f5f5; padding: 15px; margin: 10px 0; border-left: 4px solid #0073aa; }
        .test-pass { color: #00a32a; font-weight: bold; }
        .test-fail { color: #d63638; font-weight: bold; }
        .test-info { color: #2271b1; }
        .test-warning { color: #dba617; }
        pre { background: #1e1e1e; color: #d4d4d4; padding: 10px; overflow-x: auto; }
        code { background: #f0f0f0; padding: 2px 6px; border-radius: 3px; }
    </style>\n";
    echo "<div class='test-container'>\n";
    
    // Test 1: Check function exists
    echo "<div class='test-section'>\n";
    echo "<h3>Test 1: Logo Download Function</h3>\n";
    
    if (!function_exists('strikeodds_download_and_save_logo')) {
        echo "<p class='test-fail'>✗ Theme function 'strikeodds_download_and_save_logo' NOT found</p>\n";
        echo "</div>\n";
        echo "</div>\n";
        return;
    }
    
    echo "<p class='test-pass'>✓ Theme function exists</p>\n";
    echo "</div>\n";
    
    // Test 2: Get test brand and logo URL
    echo "<div class='test-section'>\n";
    echo "<h3>Test 2: Get Test Brand and Logo URL</h3>\n";
    
    global $wpdb;
    $table = $wpdb->prefix . 'dataflair_brands';
    
    $brand = $wpdb->get_row(
        "SELECT * FROM {$table} ORDER BY id LIMIT 1",
        ARRAY_A
    );
    
    if (!$brand) {
        echo "<p class='test-fail'>✗ No brands found in database</p>\n";
        echo "</div>\n";
        echo "</div>\n";
        return;
    }
    
    $data = json_decode($brand['data'], true);
    if (!$data) {
        echo "<p class='test-fail'>✗ Brand data is not valid JSON</p>\n";
        echo "</div>\n";
        echo "</div>\n";
        return;
    }
    
    echo "<p class='test-pass'>✓ Found test brand: <strong>{$brand['name']}</strong></p>\n";
    echo "<p class='test-info'>API Brand ID: {$brand['api_brand_id']}</p>\n";
    
    // Extract logo URL
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
                }
            } else {
                $logo_url = $data[$key];
                break;
            }
        }
    }
    
    if (empty($logo_url)) {
        echo "<p class='test-fail'>✗ No logo URL found in brand data</p>\n";
        echo "</div>\n";
        echo "</div>\n";
        return;
    }
    
    echo "<p class='test-pass'>✓ Logo URL extracted: <a href='{$logo_url}' target='_blank'>{$logo_url}</a></p>\n";
    echo "</div>\n";
    
    // Test 3: Download and get local URL
    echo "<div class='test-section'>\n";
    echo "<h3>Test 3: Download Logo and Get Local URL</h3>\n";
    
    $local_url = strikeodds_download_and_save_logo($logo_url, $brand['api_brand_id']);
    
    if (!$local_url) {
        echo "<p class='test-fail'>✗ Logo download failed</p>\n";
        echo "</div>\n";
        echo "</div>\n";
        return;
    }
    
    echo "<p class='test-pass'>✓ Logo downloaded successfully!</p>\n";
    echo "<p class='test-info'><strong>Local URL:</strong> <a href='{$local_url}' target='_blank'>{$local_url}</a></p>\n";
    
    // Test 4: Analyze URL structure
    echo "<div class='test-section'>\n";
    echo "<h3>Test 4: URL Structure Analysis</h3>\n";
    
    $theme_dir = get_template_directory();
    $theme_url = get_template_directory_uri();
    $logos_dir = $theme_dir . '/assets/logos';
    
    echo "<table style='width: 100%; border-collapse: collapse; margin: 10px 0;'>\n";
    echo "<tr><th style='padding: 8px; text-align: left; border: 1px solid #ddd; background: #0073aa; color: white;'>Component</th><th style='padding: 8px; text-align: left; border: 1px solid #ddd; background: #0073aa; color: white;'>Value</th></tr>\n";
    
    echo "<tr><td style='padding: 8px; border: 1px solid #ddd;'><strong>Theme Directory (Path)</strong></td><td style='padding: 8px; border: 1px solid #ddd;'><code>{$theme_dir}</code></td></tr>\n";
    echo "<tr><td style='padding: 8px; border: 1px solid #ddd;'><strong>Theme Directory (URL)</strong></td><td style='padding: 8px; border: 1px solid #ddd;'><code>{$theme_url}</code></td></tr>\n";
    echo "<tr><td style='padding: 8px; border: 1px solid #ddd;'><strong>Logos Directory (Path)</strong></td><td style='padding: 8px; border: 1px solid #ddd;'><code>{$logos_dir}</code></td></tr>\n";
    echo "<tr><td style='padding: 8px; border: 1px solid #ddd;'><strong>Logos Directory (URL)</strong></td><td style='padding: 8px; border: 1px solid #ddd;'><code>{$theme_url}/assets/logos</code></td></tr>\n";
    
    // Parse the local URL
    $url_parts = parse_url($local_url);
    $path = isset($url_parts['path']) ? $url_parts['path'] : '';
    $filename = basename($path);
    
    echo "<tr><td style='padding: 8px; border: 1px solid #ddd;'><strong>Local URL Path</strong></td><td style='padding: 8px; border: 1px solid #ddd;'><code>{$path}</code></td></tr>\n";
    echo "<tr><td style='padding: 8px; border: 1px solid #ddd;'><strong>Filename</strong></td><td style='padding: 8px; border: 1px solid #ddd;'><code>{$filename}</code></td></tr>\n";
    echo "<tr><td style='padding: 8px; border: 1px solid #ddd;'><strong>Full Local URL</strong></td><td style='padding: 8px; border: 1px solid #ddd;'><code>{$local_url}</code></td></tr>\n";
    
    echo "</table>\n";
    echo "</div>\n";
    
    // Test 5: Verify file exists
    echo "<div class='test-section'>\n";
    echo "<h3>Test 5: Verify File Exists</h3>\n";
    
    $file_path = $logos_dir . '/' . $filename;
    
    if (file_exists($file_path)) {
        echo "<p class='test-pass'>✓ File exists at: <code>{$file_path}</code></p>\n";
        $file_size = filesize($file_path);
        echo "<p class='test-info'>File size: " . number_format($file_size) . " bytes</p>\n";
        echo "<p class='test-info'>File permissions: " . substr(sprintf('%o', fileperms($file_path)), -4) . "</p>\n";
    } else {
        echo "<p class='test-fail'>✗ File does NOT exist at: <code>{$file_path}</code></p>\n";
    }
    echo "</div>\n";
    
    // Test 6: Test URL accessibility
    echo "<div class='test-section'>\n";
    echo "<h3>Test 6: URL Accessibility</h3>\n";
    
    $response = wp_remote_head($local_url, array('timeout' => 10));
    
    if (is_wp_error($response)) {
        echo "<p class='test-warning'>⚠ Could not verify URL accessibility: " . $response->get_error_message() . "</p>\n";
    } else {
        $status_code = wp_remote_retrieve_response_code($response);
        if ($status_code === 200) {
            echo "<p class='test-pass'>✓ URL is accessible (HTTP {$status_code})</p>\n";
            $content_type = wp_remote_retrieve_header($response, 'content-type');
            if ($content_type) {
                echo "<p class='test-info'>Content-Type: {$content_type}</p>\n";
            }
        } else {
            echo "<p class='test-warning'>⚠ URL returned HTTP {$status_code}</p>\n";
        }
    }
    echo "</div>\n";
    
    // Test 7: Show how it's used in toplist
    echo "<div class='test-section'>\n";
    echo "<h3>Test 7: How Logo URL is Used in Toplist</h3>\n";
    
    echo "<p class='test-info'>When a toplist is rendered, the logo URL structure is:</p>\n";
    echo "<pre>\n";
    echo "1. Logo is downloaded: strikeodds_download_and_save_logo(\$remote_url, \$brand_id)\n";
    echo "2. Returns: get_template_directory_uri() . '/assets/logos/' . \$filename\n";
    echo "3. Example: {$local_url}\n";
    echo "\n";
    echo "4. In render_casino_card(), logo is set:\n";
    echo "   \$brand['local_logo'] = '{$local_url}'\n";
    echo "\n";
    echo "5. In render-casino-card.php template:\n";
    echo "   if (!empty(\$brand['local_logo'])) {\n";
    echo "       \$logo_url = \$brand['local_logo'];\n";
    echo "   }\n";
    echo "\n";
    echo "6. Final output in HTML:\n";
    echo "   <img src=\"{$local_url}\" alt=\"{$brand['name']}\" />\n";
    echo "</pre>\n";
    echo "</div>\n";
    
    // Test 8: Display the logo
    echo "<div class='test-section'>\n";
    echo "<h3>Test 8: Display Logo</h3>\n";
    echo "<p class='test-info'>Logo preview:</p>\n";
    echo "<div style='background: #1a1a1a; padding: 20px; border-radius: 8px; display: inline-block;'>\n";
    echo "<img src='{$local_url}' alt='Logo' style='max-width: 300px; height: auto;' onerror=\"this.style.display='none'; this.nextElementSibling.style.display='block';\">\n";
    echo "<div style='display: none; color: white;'>Logo failed to load</div>\n";
    echo "</div>\n";
    echo "</div>\n";
    
    echo "</div>\n";
}

// Run test
if (php_sapi_name() !== 'cli' && isset($_GET['run_test'])) {
    test_logo_url_structure();
} elseif (php_sapi_name() === 'cli') {
    test_logo_url_structure();
}
