<?php
/**
 * Run All Tests
 * 
 * Executes all test files in sequence
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

function run_all_tests() {
    $test_files = array(
        'test-logo-download.php' => 'Logo Download Tests',
        'test-brand-data.php' => 'Brand Data Extraction Tests',
        'test-toplist-fetch.php' => 'Toplist Fetching Tests',
    );
    
    echo "<!DOCTYPE html><html><head><title>Dataflair Plugin Tests</title></head><body>\n";
    echo "<h1>🧪 Dataflair Toplists Plugin - Test Suite</h1>\n";
    echo "<p>Running all tests...</p>\n";
    echo "<hr>\n";
    
    $results = array();
    
    foreach ($test_files as $file => $name) {
        $file_path = __DIR__ . '/' . $file;
        
        if (!file_exists($file_path)) {
            echo "<div style='padding: 20px; background: #fee; border-left: 4px solid #d63638; margin: 10px 0;'>\n";
            echo "<h3>❌ {$name}</h3>\n";
            echo "<p>Test file not found: {$file}</p>\n";
            echo "</div>\n";
            $results[$name] = 'file_not_found';
            continue;
        }
        
        echo "<div style='padding: 20px; background: #f5f5f5; border-left: 4px solid #0073aa; margin: 10px 0;'>\n";
        echo "<h2>Running: {$name}</h2>\n";
        echo "<hr>\n";
        
        ob_start();
        include $file_path;
        $output = ob_get_clean();
        
        echo $output;
        echo "</div>\n";
        
        $results[$name] = 'completed';
    }
    
    // Summary
    echo "<hr>\n";
    echo "<h2>📊 Test Summary</h2>\n";
    echo "<table style='width: 100%; border-collapse: collapse;'>\n";
    echo "<tr style='background: #0073aa; color: white;'><th style='padding: 10px; text-align: left;'>Test</th><th style='padding: 10px; text-align: left;'>Status</th></tr>\n";
    
    foreach ($results as $name => $status) {
        $status_text = $status === 'completed' ? '✅ Completed' : '❌ Failed';
        $bg_color = $status === 'completed' ? '#d4edda' : '#f8d7da';
        echo "<tr style='background: {$bg_color};'><td style='padding: 10px;'>{$name}</td><td style='padding: 10px;'>{$status_text}</td></tr>\n";
    }
    
    echo "</table>\n";
    echo "</body></html>\n";
}

// Run if accessed directly
if (php_sapi_name() !== 'cli') {
    run_all_tests();
} else {
    // For CLI, run each test separately
    echo "Running all tests...\n\n";
    include __DIR__ . '/test-logo-download.php';
    echo "\n" . str_repeat('=', 80) . "\n\n";
    include __DIR__ . '/test-brand-data.php';
    echo "\n" . str_repeat('=', 80) . "\n\n";
    include __DIR__ . '/test-toplist-fetch.php';
}
