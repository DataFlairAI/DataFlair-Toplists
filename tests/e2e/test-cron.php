<?php
/**
 * E2E Test: Cron Removal (Phase 0B)
 *
 * Phase 0B deleted every cron registration in the plugin. These assertions
 * therefore PASS when the cron infrastructure is confirmed gone — the
 * "positive" signal the user wants is "the cron is no longer there."
 *
 * Verifies the post-Phase-0B contract:
 *  1. Custom 'dataflair_15min' schedule is NOT registered.
 *  2. 'dataflair_sync_cron' is NOT scheduled with WP-Cron.
 *  3. 'dataflair_brands_sync_cron' is NOT scheduled with WP-Cron.
 *  4. No callbacks attached to 'dataflair_sync_cron' action (zero listeners).
 *  5. No callbacks attached to 'dataflair_brands_sync_cron' action.
 *  6. One-time migration option 'dataflair_cron_cleared_v1_11' is set to "1".
 *  7. Firing the now-dead action is a harmless no-op — no fatal, no row change.
 *  8. The schedules filter chain does not introduce 'dataflair_15min'.
 *
 * Run via WP-CLI (inside Docker):
 *   wp --allow-root eval-file /var/www/html/wp-content/plugins/DataFlair-Toplists/tests/e2e/test-cron.php
 */

if ( ! defined( 'ABSPATH' ) ) {
    die( 'Run via WP-CLI: wp eval-file tests/e2e/test-cron.php' . PHP_EOL );
}

global $wpdb, $wp_filter;

$GLOBALS['e2e_pass'] = 0;
$GLOBALS['e2e_fail'] = 0;

function e2e_pass( string $msg ): void {
    $GLOBALS['e2e_pass']++;
    echo "\033[32m✓\033[0m {$msg}\n";
}

function e2e_fail( string $msg ): void {
    $GLOBALS['e2e_fail']++;
    echo "\033[31m✗\033[0m {$msg}\n";
}

function e2e_assert( bool $cond, string $pass_msg, string $fail_msg ): void {
    $cond ? e2e_pass( $pass_msg ) : e2e_fail( $fail_msg );
}

echo "\n\033[1m── Cron Removal E2E (Phase 0B contract) ──\033[0m\n\n";

// ── Test 1: custom schedule is NOT registered ───────────────────────────────

$schedules = wp_get_schedules();
e2e_assert(
    ! isset( $schedules['dataflair_15min'] ),
    "Custom schedule 'dataflair_15min' is NOT registered (cron removed)",
    "Custom schedule 'dataflair_15min' is STILL registered — cron filter not removed"
);

// ── Test 2: dataflair_sync_cron is NOT scheduled ─────────────────────────────

$toplist_next = wp_next_scheduled( 'dataflair_sync_cron' );
e2e_assert(
    $toplist_next === false,
    "'dataflair_sync_cron' has no scheduled run (cron unhooked)",
    "'dataflair_sync_cron' is still scheduled at " . date( 'Y-m-d H:i:s', (int) $toplist_next )
);

// ── Test 3: dataflair_brands_sync_cron is NOT scheduled ──────────────────────

$brands_next = wp_next_scheduled( 'dataflair_brands_sync_cron' );
e2e_assert(
    $brands_next === false,
    "'dataflair_brands_sync_cron' has no scheduled run (cron unhooked)",
    "'dataflair_brands_sync_cron' is still scheduled at " . date( 'Y-m-d H:i:s', (int) $brands_next )
);

// ── Test 4: zero listeners attached to the legacy sync action ───────────────

$toplist_listeners = isset( $wp_filter['dataflair_sync_cron'] )
    ? array_sum( array_map( 'count', (array) $wp_filter['dataflair_sync_cron']->callbacks ) )
    : 0;
e2e_assert(
    $toplist_listeners === 0,
    "Zero callbacks attached to 'dataflair_sync_cron' action (handler deleted)",
    "{$toplist_listeners} callback(s) still listening on 'dataflair_sync_cron'"
);

// ── Test 5: zero listeners attached to the legacy brands action ─────────────

$brands_listeners = isset( $wp_filter['dataflair_brands_sync_cron'] )
    ? array_sum( array_map( 'count', (array) $wp_filter['dataflair_brands_sync_cron']->callbacks ) )
    : 0;
e2e_assert(
    $brands_listeners === 0,
    "Zero callbacks attached to 'dataflair_brands_sync_cron' action (handler deleted)",
    "{$brands_listeners} callback(s) still listening on 'dataflair_brands_sync_cron'"
);

// ── Test 6: one-time migration flag is set ───────────────────────────────────

$cleared = (string) get_option( 'dataflair_cron_cleared_v1_11', '' );
e2e_assert(
    $cleared === '1',
    "Migration flag 'dataflair_cron_cleared_v1_11' is set (clearer ran on activation/upgrade)",
    "Migration flag 'dataflair_cron_cleared_v1_11' is empty — wp_clear_scheduled_hook never ran"
);

// ── Test 7: firing the dead action is a harmless no-op ──────────────────────

$toplists_table   = $wpdb->prefix . DATAFLAIR_TABLE_NAME;
$brands_table     = $wpdb->prefix . DATAFLAIR_BRANDS_TABLE_NAME;
$toplists_before  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$toplists_table}" );
$brands_before    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$brands_table}" );
$ts_before_topl   = (int) get_option( 'dataflair_last_toplists_cron_run', 0 );
$ts_before_brands = (int) get_option( 'dataflair_last_brands_cron_run', 0 );

$fatal = false;
try {
    do_action( 'dataflair_sync_cron' );
    do_action( 'dataflair_brands_sync_cron' );
} catch ( Throwable $e ) {
    $fatal = true;
    e2e_fail( "Firing dead cron action threw: " . $e->getMessage() );
}
if ( ! $fatal ) {
    e2e_pass( "Firing 'dataflair_sync_cron' + 'dataflair_brands_sync_cron' did not throw" );
}

$toplists_after  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$toplists_table}" );
$brands_after    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$brands_table}" );
$ts_after_topl   = (int) get_option( 'dataflair_last_toplists_cron_run', 0 );
$ts_after_brands = (int) get_option( 'dataflair_last_brands_cron_run', 0 );

e2e_assert(
    $toplists_before === $toplists_after && $brands_before === $brands_after,
    "Dead action did not mutate any wp_dataflair_* row counts (toplists={$toplists_after}, brands={$brands_after})",
    "Dead action mutated row counts: toplists {$toplists_before}→{$toplists_after}, brands {$brands_before}→{$brands_after}"
);

e2e_assert(
    $ts_before_topl === $ts_after_topl && $ts_before_brands === $ts_after_brands,
    "Dead action did not bump legacy cron timestamps (no listener wrote them)",
    "Dead action bumped a legacy timestamp — a stale listener is still attached"
);

// ── Test 8: cron_schedules filter chain does not yield dataflair_15min ──────

$filtered = apply_filters( 'cron_schedules', array() );
e2e_assert(
    is_array( $filtered ) && ! isset( $filtered['dataflair_15min'] ),
    "cron_schedules filter does not introduce 'dataflair_15min' (filter callback removed)",
    "cron_schedules filter still injects 'dataflair_15min' — add_filter not removed"
);

// ── Summary ───────────────────────────────────────────────────────────────────

$p = $GLOBALS['e2e_pass'];
$f = $GLOBALS['e2e_fail'];
echo "\n\033[1mCron Removal: {$p} passed, {$f} failed\033[0m\n";
echo "(All assertions PASS = cron is fully removed per Phase 0B contract.)\n\n";
exit( $f > 0 ? 1 : 0 );
