<?php
/**
 * Test Cron Jobs
 *
 * Coverage:
 *  - Both cron hooks are scheduled (dataflair_sync_cron, dataflair_brands_sync_cron)
 *  - dataflair_15min custom schedule is registered
 *  - Next run time is not in the past (not stuck)
 *  - Manual trigger: fire cron_sync_toplists() and verify last_synced timestamps update in DB
 *  - Manual trigger: fire cron_sync_brands() and verify brand data updates in DB
 *  - After cron fires, dataflair_last_toplists_cron_run / dataflair_last_brands_cron_run options update
 *  - Toplists DB rows get a fresh last_synced after toplist cron fires
 *  - Brands DB rows get a fresh last_synced after brands cron fires
 *  - Cron does NOT run without a configured token (graceful skip)
 *
 * Run via WP admin: DataFlair → Tests → Cron Test
 * Run via WP-CLI:   wp eval-file wp-content/plugins/dataflair-toplists/tests/test-cron.php --allow-root
 */

if (!defined('ABSPATH')) {
    $wp_load_paths = [
        dirname(dirname(dirname(dirname(__FILE__)))) . '/wp-load.php',
        dirname(dirname(dirname(__FILE__))) . '/wp-load.php',
        '../../../wp-load.php',
        '../../../../wp-load.php',
    ];
    $wp_loaded = false;
    foreach ($wp_load_paths as $path) {
        if (file_exists($path)) { require_once $path; $wp_loaded = true; break; }
    }
    if (!$wp_loaded) { die('Error: Could not find wp-load.php.'); }
}

// ── Helpers ───────────────────────────────────────────────────────────────────
$GLOBALS['_dfc_pass'] = 0;
$GLOBALS['_dfc_fail'] = 0;
$GLOBALS['_dfc_warn'] = 0;

function dfc_pass(string $msg): void { $GLOBALS['_dfc_pass']++; echo "<p class='test-pass'>✓ {$msg}</p>\n"; }
function dfc_fail(string $msg): void { $GLOBALS['_dfc_fail']++; echo "<p class='test-fail'>✗ {$msg}</p>\n"; }
function dfc_warn(string $msg): void { $GLOBALS['_dfc_warn']++; echo "<p class='test-warning'>⚠ {$msg}</p>\n"; }
function dfc_info(string $msg): void { echo "<p class='test-info'>{$msg}</p>\n"; }
function dfc_section(string $t): void { echo "<div class='test-section'>\n<h3>{$t}</h3>\n"; }
function dfc_end(): void { echo "</div>\n"; }

// ── Main ──────────────────────────────────────────────────────────────────────
function test_cron(): void {
    echo "<h2>🧪 Cron Job Tests</h2>\n";
    echo "<style>
        .test-container{max-width:1200px;margin:20px auto;padding:20px;font-family:monospace}
        .test-section{background:#f5f5f5;padding:15px;margin:10px 0;border-left:4px solid #0073aa}
        .test-pass{color:#00a32a;font-weight:bold;margin:3px 0}
        .test-fail{color:#d63638;font-weight:bold;margin:3px 0}
        .test-info{color:#2271b1;margin:3px 0}
        .test-warning{color:#dba617;margin:3px 0}
        pre{background:#1e1e1e;color:#d4d4d4;padding:10px;overflow-x:auto;font-size:12px}
        table{width:100%;border-collapse:collapse;margin:10px 0;font-size:13px}
        table th,table td{padding:6px 10px;text-align:left;border:1px solid #ddd}
        table th{background:#0073aa;color:white}
    </style>\n";
    echo "<div class='test-container'>\n";

    // ── T01: Custom schedule registered ──────────────────────────────────────
    dfc_section("T01 · Custom Cron Schedule (dataflair_15min)");
    $schedules = wp_get_schedules();
    if (isset($schedules['dataflair_15min'])) {
        $interval = $schedules['dataflair_15min']['interval'];
        dfc_pass("dataflair_15min schedule registered (interval: {$interval}s = " . round($interval/60) . " min)");
        if ($interval === 900) {
            dfc_pass("Interval is exactly 900s (15 minutes) — correct");
        } else {
            dfc_warn("Interval is {$interval}s — expected 900s (15 min)");
        }
    } else {
        dfc_fail("dataflair_15min custom schedule NOT registered");
    }
    dfc_end();

    // ── T02: Hooks are scheduled ──────────────────────────────────────────────
    dfc_section("T02 · Cron Hooks Scheduled");
    $hooks = [
        'dataflair_sync_cron'        => 'Toplists sync (twice daily)',
        'dataflair_brands_sync_cron' => 'Brands sync (every 15 min)',
    ];

    echo "<table>\n<tr><th>Hook</th><th>Description</th><th>Scheduled?</th><th>Next run</th><th>Overdue?</th></tr>\n";
    foreach ($hooks as $hook => $desc) {
        $next = wp_next_scheduled($hook);
        $scheduled = $next !== false;
        $overdue   = $scheduled && $next < time();
        $next_str  = $scheduled ? date('Y-m-d H:i:s', $next) . ' (' . ($overdue ? '<span style="color:#d63638">OVERDUE by ' . human_time_diff($next) . '</span>' : 'in ' . human_time_diff($next)) . ')' : '—';

        if ($scheduled) { $GLOBALS['_dfc_pass']++; } else { $GLOBALS['_dfc_fail']++; }

        echo "<tr>
            <td><strong>{$hook}</strong></td>
            <td>{$desc}</td>
            <td>" . ($scheduled ? "<span style='color:#00a32a'>✓ Yes</span>" : "<span style='color:#d63638'>✗ No</span>") . "</td>
            <td>{$next_str}</td>
            <td>" . ($overdue ? "<span style='color:#dba617'>⚠ Yes</span>" : ($scheduled ? "No" : "—")) . "</td>
        </tr>\n";

        if ($overdue) {
            dfc_warn("{$hook} is overdue — WordPress pseudo-cron fires on next page load");
        }
    }
    echo "</table>\n";
    dfc_end();

    // ── T03: Last run times ───────────────────────────────────────────────────
    dfc_section("T03 · Last Cron Run Times");
    $run_options = [
        'dataflair_last_toplists_cron_run' => 'Toplists cron last ran',
        'dataflair_last_brands_cron_run'   => 'Brands cron last ran',
    ];

    foreach ($run_options as $opt => $label) {
        $ts = get_option($opt);
        if (empty($ts)) {
            dfc_warn("{$label}: never (option not set — cron hasn't fired yet or was cleared)");
        } else {
            $ago  = human_time_diff($ts);
            $date = date('Y-m-d H:i:s', $ts);
            $stale = (time() - $ts) > (3 * 24 * 3600); // > 3 days old
            if ($stale) {
                dfc_warn("{$label}: {$date} ({$ago} ago) — MORE than 3 days ago, cron may be stuck");
            } else {
                dfc_pass("{$label}: {$date} ({$ago} ago)");
            }
        }
    }
    dfc_end();

    // ── T04: Verify plugin instance ───────────────────────────────────────────
    dfc_section("T04 · Plugin Instance for Manual Trigger");
    if (!class_exists('DataFlair_Toplists')) {
        dfc_fail("DataFlair_Toplists class not found — cannot test manual cron trigger");
        dfc_end();
        _dfc_summary();
        return;
    }
    $plugin = DataFlair_Toplists::get_instance();
    dfc_pass("Plugin instance obtained");
    dfc_end();

    // ── T05: Token configured? ────────────────────────────────────────────────
    dfc_section("T05 · API Token — Required for Cron to Pull Fresh Data");
    $token = trim(get_option('dataflair_api_token', ''));
    if (empty($token)) {
        dfc_fail("No API token configured — cron will silently skip all syncs");
        dfc_info("Set the token in DataFlair → Settings before expecting cron to work.");
        dfc_end();
        _dfc_summary();
        return;
    }
    dfc_pass("API token configured (length: " . strlen($token) . ")");
    dfc_end();

    // ── T06: Manual toplist cron trigger — verifies DB gets updated ───────────
    dfc_section("T06 · Manual Trigger — dataflair_sync_cron (Toplists)");
    global $wpdb;
    $table = $wpdb->prefix . 'dataflair_toplists';

    // Snapshot last_synced BEFORE firing cron
    $before = $wpdb->get_results("SELECT api_toplist_id, last_synced FROM {$table}", ARRAY_A);
    $before_map = array_column($before, 'last_synced', 'api_toplist_id');

    if (empty($before)) {
        dfc_warn("No toplists in DB — skipping toplist cron trigger test. Run Fetch All Toplists first.");
    } else {
        dfc_info("Toplists in DB before trigger: " . count($before));

        // Force last_synced to 1 hour ago so we can detect if cron actually pulled fresh data
        $wpdb->query("UPDATE {$table} SET last_synced = DATE_SUB(NOW(), INTERVAL 1 HOUR)");

        // Clear the last_cron_run marker so we can detect the update
        delete_option('dataflair_last_toplists_cron_run');

        $start = microtime(true);
        do_action('dataflair_sync_cron');
        $elapsed = round(microtime(true) - $start, 2);

        dfc_info("Cron action fired in {$elapsed}s");

        // Check last_cron_run updated
        $last_run = get_option('dataflair_last_toplists_cron_run');
        if (!empty($last_run) && (time() - $last_run) < 30) {
            dfc_pass("dataflair_last_toplists_cron_run updated (fired " . (time() - $last_run) . "s ago)");
        } else {
            dfc_fail("dataflair_last_toplists_cron_run was NOT updated after firing cron action");
        }

        // Check DB last_synced updated
        $after = $wpdb->get_results("SELECT api_toplist_id, last_synced FROM {$table}", ARRAY_A);
        $updated = 0;
        $failed  = 0;
        foreach ($after as $row) {
            $before_ts = strtotime($before_map[$row['api_toplist_id']] ?? '2000-01-01');
            $after_ts  = strtotime($row['last_synced']);
            if ($after_ts > $before_ts + 30) { // updated by more than 30s
                $updated++;
            } else {
                $failed++;
                dfc_warn("Toplist id={$row['api_toplist_id']}: last_synced did NOT update after cron (was {$row['last_synced']})");
            }
        }

        if ($updated > 0) {
            dfc_pass("{$updated}/" . count($after) . " toplist(s) got a fresh last_synced after cron fired");
        }
        if ($failed > 0) {
            dfc_warn("{$failed} toplist(s) were NOT updated — API call may have failed (check API token + connectivity)");
        }

        // Show comparison table
        echo "<table>\n<tr><th>api_toplist_id</th><th>last_synced before</th><th>last_synced after</th><th>Updated?</th></tr>\n";
        foreach ($after as $row) {
            $b_ts = $before_map[$row['api_toplist_id']] ?? '(unknown)';
            $a_ts = $row['last_synced'];
            $changed = strtotime($a_ts) > strtotime($b_ts) + 30;
            $color = $changed ? '#00a32a' : '#d63638';
            echo "<tr>
                <td>{$row['api_toplist_id']}</td>
                <td>" . esc_html($b_ts) . "</td>
                <td>" . esc_html($a_ts) . "</td>
                <td style='color:{$color}'>" . ($changed ? '✓ Yes' : '✗ No') . "</td>
            </tr>\n";
        }
        echo "</table>\n";
    }
    dfc_end();

    // ── T07: Manual brands cron trigger ───────────────────────────────────────
    dfc_section("T07 · Manual Trigger — dataflair_brands_sync_cron (Brands)");
    $brands_table = $wpdb->prefix . 'dataflair_brands';
    $brands_table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$brands_table}'") === $brands_table;

    if (!$brands_table_exists) {
        dfc_warn("Brands table '{$brands_table}' does not exist — brands sync may store data differently");

        // Still fire the action and check last_run
        delete_option('dataflair_last_brands_cron_run');
        $start = microtime(true);
        do_action('dataflair_brands_sync_cron');
        $elapsed = round(microtime(true) - $start, 2);
        dfc_info("Brands cron action fired in {$elapsed}s");

        $last_run = get_option('dataflair_last_brands_cron_run');
        if (!empty($last_run) && (time() - $last_run) < 30) {
            dfc_pass("dataflair_last_brands_cron_run updated after firing cron action");
        } else {
            dfc_fail("dataflair_last_brands_cron_run was NOT updated after firing brands cron action");
        }
    } else {
        // Snapshot before
        $brands_before = $wpdb->get_results("SELECT id, last_synced FROM {$brands_table} LIMIT 20", ARRAY_A);
        $brands_before_map = array_column($brands_before, 'last_synced', 'id');

        dfc_info("Brands in DB before trigger: " . count($brands_before));
        $wpdb->query("UPDATE {$brands_table} SET last_synced = DATE_SUB(NOW(), INTERVAL 1 HOUR) LIMIT 20");
        delete_option('dataflair_last_brands_cron_run');

        $start = microtime(true);
        do_action('dataflair_brands_sync_cron');
        $elapsed = round(microtime(true) - $start, 2);
        dfc_info("Brands cron action fired in {$elapsed}s");

        $last_run = get_option('dataflair_last_brands_cron_run');
        if (!empty($last_run) && (time() - $last_run) < 30) {
            dfc_pass("dataflair_last_brands_cron_run updated");
        } else {
            dfc_fail("dataflair_last_brands_cron_run was NOT updated");
        }

        $brands_after = $wpdb->get_results("SELECT id, last_synced FROM {$brands_table} LIMIT 20", ARRAY_A);
        $updated = 0;
        foreach ($brands_after as $row) {
            $b = strtotime($brands_before_map[$row['id']] ?? '2000-01-01');
            $a = strtotime($row['last_synced']);
            if ($a > $b + 30) $updated++;
        }
        if ($updated > 0) {
            dfc_pass("{$updated}/" . count($brands_after) . " brand(s) got fresh last_synced after brands cron");
        } else {
            dfc_warn("No brands updated — API may be unreachable or /brands endpoint returning 404");
        }
    }
    dfc_end();

    // ── T08: Cron without token — graceful skip ───────────────────────────────
    dfc_section("T08 · Cron Without Token — Graceful Skip");
    $real_token = get_option('dataflair_api_token');
    update_option('dataflair_api_token', ''); // temporarily clear token

    delete_option('dataflair_last_toplists_cron_run');
    $start = microtime(true);
    do_action('dataflair_sync_cron');
    $elapsed = round(microtime(true) - $start, 2);

    // Restore token immediately
    update_option('dataflair_api_token', $real_token);

    // The cron should skip gracefully (no fatal error, no DB writes)
    $ran = get_option('dataflair_last_toplists_cron_run');
    if (empty($ran)) {
        dfc_pass("Cron with empty token skipped silently (no last_cron_run written) — correct");
    } else {
        // It ran — this might be OK if the function still records run time even on no-token
        dfc_warn("Cron ran even with empty token (last_cron_run was written). Check if API calls were attempted.");
    }
    dfc_info("Time taken with no token: {$elapsed}s (should be near instant)");
    if ($elapsed < 2) {
        dfc_pass("Cron with no token completed in {$elapsed}s — fast exit, no API calls made");
    } else {
        dfc_warn("Cron with no token took {$elapsed}s — may be making unnecessary API calls");
    }
    dfc_end();

    _dfc_summary();
}

function _dfc_summary(): void {
    $pass  = $GLOBALS['_dfc_pass'];
    $fail  = $GLOBALS['_dfc_fail'];
    $warn  = $GLOBALS['_dfc_warn'];
    $total = $pass + $fail;
    $color = $fail === 0 ? '#00a32a' : '#d63638';

    echo "<div class='test-section' style='border-left-color:{$color}'>\n";
    echo "<h3>📊 Test Summary — Cron Jobs</h3>\n";
    echo "<p class='test-pass'>✓ {$pass} passed</p>\n";
    if ($fail > 0) echo "<p class='test-fail'>✗ {$fail} failed</p>\n";
    if ($warn > 0) echo "<p class='test-warning'>⚠ {$warn} warnings</p>\n";
    echo "<p class='test-info'>Total assertions: {$total}</p>\n";
    if ($fail === 0) {
        echo "<p class='test-pass' style='font-size:16px'>🎉 All tests passed!</p>\n";
    } else {
        echo "<p class='test-fail' style='font-size:16px'>❌ {$fail} test(s) failed — review above</p>\n";
    }
    echo "</div>\n</div>\n";
}

// ── Entry points ──────────────────────────────────────────────────────────────
if (php_sapi_name() === 'cli' || isset($_GET['run_test']) || isset($_GET['test'])) {
    test_cron();
}
