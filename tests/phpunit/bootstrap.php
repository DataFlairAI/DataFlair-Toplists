<?php
/**
 * PHPUnit bootstrap for DataFlair Toplists plugin tests.
 *
 * Uses Brain\Monkey to stub all WordPress functions so the plugin
 * classes can be loaded and tested without a running WordPress installation.
 */

// Resolve the plugin root (two levels up from tests/phpunit/)
$plugin_root = dirname(dirname(__DIR__));

// Use the main repo vendor directory (Brain\Monkey may not be installed yet in worktree)
$vendor_dirs = [
    $plugin_root . '/vendor/autoload.php',                                          // worktree local
    dirname(dirname($plugin_root)) . '/vendor/autoload.php',                        // main repo (worktrees sit 2 levels deep)
    dirname(dirname(dirname(dirname($plugin_root)))) . '/vendor/autoload.php',      // main repo alt
    '/Users/mexpower/Sites/DataFlair-Toplists/vendor/autoload.php',                 // absolute fallback
];

$autoload_loaded = false;
foreach ($vendor_dirs as $path) {
    if (file_exists($path)) {
        require_once $path;
        $autoload_loaded = true;
        break;
    }
}

if (!$autoload_loaded) {
    die('Could not find vendor/autoload.php. Run composer install first.' . PHP_EOL);
}

// ── WordPress constant stubs ──
if (!defined('ABSPATH')) {
    define('ABSPATH', '/tmp/wordpress/');
}
// WordPress DB result-type constants
if (!defined('ARRAY_A'))  define('ARRAY_A',  'ARRAY_A');
if (!defined('ARRAY_N'))  define('ARRAY_N',  'ARRAY_N');
if (!defined('OBJECT'))   define('OBJECT',   'OBJECT');
if (!defined('OBJECT_K')) define('OBJECT_K', 'OBJECT_K');
if (!defined('DATAFLAIR_TABLE_NAME')) {
    define('DATAFLAIR_TABLE_NAME', 'dataflair_toplists');
}
if (!defined('DATAFLAIR_BRANDS_TABLE_NAME')) {
    define('DATAFLAIR_BRANDS_TABLE_NAME', 'dataflair_brands');
}
if (!defined('DATAFLAIR_ALTERNATIVE_TOPLISTS_TABLE_NAME')) {
    define('DATAFLAIR_ALTERNATIVE_TOPLISTS_TABLE_NAME', 'dataflair_alternative_toplists');
}
if (!defined('DATAFLAIR_VERSION')) {
    define('DATAFLAIR_VERSION', '1.5.0');
}
if (!defined('DATAFLAIR_PLUGIN_DIR')) {
    define('DATAFLAIR_PLUGIN_DIR', $plugin_root . '/');
}
if (!defined('DATAFLAIR_PLUGIN_URL')) {
    define('DATAFLAIR_PLUGIN_URL', 'http://localhost/wp-content/plugins/dataflair-toplists/');
}

// Load plugin classes under test (no WordPress bootstrap needed for unit tests)
require_once DATAFLAIR_PLUGIN_DIR . 'includes/DataIntegrityChecker.php';
require_once DATAFLAIR_PLUGIN_DIR . 'includes/ProductTypeLabels.php';
require_once DATAFLAIR_PLUGIN_DIR . 'includes/Brand.php';
require_once DATAFLAIR_PLUGIN_DIR . 'includes/Toplist.php';
