<?php
/**
 * DataFlair Perf Probe — MU-plugin (must-use).
 *
 * Phase 0.5. Drop this file into wp-content/mu-plugins/ on a perf-rig
 * target to capture peak memory + query count + wall time on every WP
 * request. Attaches to the `shutdown` hook and emits a single line to
 * stderr (and WP_CLI::log if running under WP-CLI) in the form:
 *
 *   [DataFlair-Perf] uri=<uri> peak_mb=<f> wall_s=<f> queries=<n>
 *
 * Deliberately dependency-free. Does not load the DataFlair plugin or any
 * of its classes — it's just a fork-safe PHP probe that any session
 * (AJAX, REST, WP-CLI, frontend) flows through.
 *
 * To copy into strike-odds.test:
 *   cp mu-plugins/dataflair-perf-probe.php \
 *      /Users/mexpower/Sites/strike-odds/wp-content/mu-plugins/
 *
 * Remove it when done perf-testing — it does cost ~0.1 ms per request.
 */

if (!defined('ABSPATH')) { exit; }

if (!defined('DATAFLAIR_PERF_PROBE_T0')) {
    define('DATAFLAIR_PERF_PROBE_T0', microtime(true));
}

add_action('shutdown', function () {
    $wall     = microtime(true) - DATAFLAIR_PERF_PROBE_T0;
    $peak_mb  = round(memory_get_peak_usage(true) / 1024 / 1024, 1);
    $queries  = isset($GLOBALS['wpdb']->num_queries) ? (int) $GLOBALS['wpdb']->num_queries : 0;

    $uri = '';
    if (defined('WP_CLI') && WP_CLI) {
        $uri = 'wp-cli';
    } elseif (defined('REST_REQUEST') && REST_REQUEST) {
        $uri = 'rest:' . ($_SERVER['REQUEST_URI'] ?? 'unknown');
    } elseif (defined('DOING_AJAX') && DOING_AJAX) {
        $uri = 'ajax:' . ($_REQUEST['action'] ?? 'unknown');
    } else {
        $uri = $_SERVER['REQUEST_URI'] ?? 'unknown';
    }

    $line = sprintf(
        "[DataFlair-Perf] uri=%s peak_mb=%s wall_s=%.3f queries=%d",
        $uri, $peak_mb, $wall, $queries
    );

    // Always stderr so CI can capture; also log via WP-CLI when available.
    fwrite(STDERR, $line . "\n");

    if (defined('WP_CLI') && WP_CLI) {
        \WP_CLI::log($line);
    }

    // Also honour WP_DEBUG_LOG by mirroring into error_log so sites with
    // a regular debug.log catch the probe output too.
    if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
        error_log($line);
    }
}, PHP_INT_MAX);
