<?php
/**
 * E2E Test: Toplist Sync (post-Phase-0B — modern service entry)
 *
 * Cron was removed in Phase 0B. The toplist sync pipeline is still alive — it
 * just lives at ToplistSyncService::syncPage(SyncRequest) now. We exercise it
 * through the legacy shim's lazy accessor (`toplist_sync_service()`), which
 * Phase 9.13 will inline into the bootstrap.
 *
 * Verifies that calling ToplistSyncService::syncPage on the live site:
 *  1. Endpoints are configured (at least 1 in dataflair_api_endpoints).
 *  2. Service returns a SyncResult with success=true.
 *  3. wp_dataflair_toplists has at least 1 row after the call.
 *  4. Required columns are populated (id, api_toplist_id, name).
 *  5. data column is valid JSON with a data.items array.
 *  6. item_count matches the actual count in JSON.
 *  7. At least one toplist has items (item_count > 0).
 *  8. last_synced timestamps are updated for at least one row.
 *  9. Modern timestamp option (`dataflair_last_toplists_sync`) is updated, AND
 *     the legacy mirror (`dataflair_last_toplists_cron_run`) is dual-written.
 * 10. REST API /dataflair/v1/toplists returns HTTP 200 with a {value,label}
 *     dropdown list.
 * 11. REST API /dataflair/v1/toplists/{id}/casinos returns HTTP 200 with an
 *     array.
 * 12. Alternative toplists table exists (optional).
 *
 * Run via WP-CLI (inside Docker):
 *   wp --allow-root eval-file /var/www/html/wp-content/plugins/DataFlair-Toplists/tests/e2e/test-toplist-sync.php
 */

if ( ! defined( 'ABSPATH' ) ) {
    die( 'Run via WP-CLI: wp eval-file tests/e2e/test-toplist-sync.php' . PHP_EOL );
}

use DataFlair\Toplists\Sync\SyncRequest;
use DataFlair\Toplists\Sync\SyncResult;

global $wpdb;

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

/**
 * Resolve ToplistSyncService via the legacy shim's private accessor so the
 * test mirrors the production call path used by the AJAX handler.
 */
function e2e_resolve_toplist_sync_service() {
    $plugin = DataFlair_Toplists::get_instance();
    $ref    = new ReflectionMethod( DataFlair_Toplists::class, 'toplist_sync_service' );
    $ref->setAccessible( true );
    return $ref->invoke( $plugin );
}

// ── Pre-flight ────────────────────────────────────────────────────────────────

echo "\n\033[1m── Toplist Sync E2E (modern service entry) ──\033[0m\n\n";

$token = trim( get_option( 'dataflair_api_token' ) );
e2e_assert( ! empty( $token ), 'API token is configured', 'API token is MISSING — cannot run toplist sync tests' );

if ( empty( $token ) ) {
    echo "\nAborted: no token.\n";
    exit( 1 );
}

if ( ! class_exists( SyncRequest::class ) || ! class_exists( SyncResult::class ) ) {
    e2e_fail( 'ToplistSyncService classes not autoloadable — composer dump-autoload?' );
    exit( 1 );
}
e2e_pass( 'SyncRequest + SyncResult classes are autoloadable' );

$toplists_table = $wpdb->prefix . DATAFLAIR_TABLE_NAME;
$alt_table      = $wpdb->prefix . DATAFLAIR_ALTERNATIVE_TOPLISTS_TABLE_NAME;

// ── Test 1: toplists table exists ────────────────────────────────────────────

$table_exists = $wpdb->get_var( "SHOW TABLES LIKE '{$toplists_table}'" ) === $toplists_table;
e2e_assert( $table_exists, "Toplists table '{$toplists_table}' exists", "Toplists table '{$toplists_table}' does NOT exist" );

if ( ! $table_exists ) {
    echo "\nAborted: table missing.\n";
    exit( 1 );
}

// ── Test 2: endpoints configured ─────────────────────────────────────────────

$endpoints_raw = get_option( 'dataflair_api_endpoints', '' );
$endpoints     = array_filter( array_map( 'trim', explode( "\n", $endpoints_raw ) ) );
e2e_assert( count( $endpoints ) > 0, count( $endpoints ) . ' toplist endpoint(s) configured', 'No toplist endpoints configured' );

if ( count( $endpoints ) === 0 ) {
    echo "\nAborted: no endpoints.\n";
    exit( 1 );
}

// ── Test 3: ToplistSyncService::syncPage returns success ─────────────────────

// Brief pause to avoid API rate-limiting after brand sync tests
sleep( 2 );

echo "  Calling ToplistSyncService::syncPage(SyncRequest::toplists(1)) (may take 10–30s)…\n";
$before = time();

try {
    $service = e2e_resolve_toplist_sync_service();
    e2e_assert(
        is_object( $service ),
        'Resolved ToplistSyncService via legacy shim accessor',
        'ToplistSyncService accessor returned non-object'
    );

    $result = $service->syncPage( SyncRequest::toplists( 1 ) );
    $after  = time();

    e2e_assert(
        $result instanceof SyncResult,
        'ToplistSyncService::syncPage returned a SyncResult (' . ( $after - $before ) . 's)',
        'ToplistSyncService::syncPage did not return a SyncResult'
    );

    if ( $result instanceof SyncResult ) {
        // SyncResult::toArray() omits the `success` key on successful results
        // (only failure results include `message`). Read the readonly property
        // directly so we test the actual contract.
        e2e_assert(
            $result->success === true,
            'SyncResult->success === true (page=' . $result->page . ', last_page=' . $result->lastPage . ', synced=' . $result->synced . ', complete=' . ( $result->isComplete ? 'yes' : 'no' ) . ')',
            'SyncResult->success was false: ' . wp_json_encode( $result->toArray() )
        );
    }
} catch ( Throwable $e ) {
    e2e_fail( 'ToplistSyncService::syncPage threw: ' . $e->getMessage() );
    exit( 1 );
}

// ── Test 4: rows exist after sync ────────────────────────────────────────────

$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$toplists_table}" );

if ( $count === 0 ) {
    echo "  No rows found, retrying syncPage once…\n";
    sleep( 3 );
    e2e_resolve_toplist_sync_service()->syncPage( SyncRequest::toplists( 1 ) );
    $count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$toplists_table}" );
}

e2e_assert( $count > 0, "Toplists table has {$count} rows after sync", "Toplists table is empty after sync (0 rows)" );

// ── Test 5: required columns populated ───────────────────────────────────────

$rows = $wpdb->get_results(
    "SELECT id, api_toplist_id, name, slug, item_count, last_synced, data FROM {$toplists_table} LIMIT 10",
    ARRAY_A
);

$missing_fields = 0;
foreach ( $rows as $row ) {
    foreach ( [ 'id', 'api_toplist_id', 'name' ] as $col ) {
        if ( empty( $row[ $col ] ) ) {
            e2e_fail( "Toplist id={$row['id']} missing required field: {$col}" );
            $missing_fields++;
        }
    }
}
if ( $missing_fields === 0 ) {
    e2e_pass( 'All sampled toplists have required fields (id, api_toplist_id, name)' );
}

// ── Test 6: data column valid JSON with items array ──────────────────────────

$invalid_json        = 0;
$item_count_mismatch = 0;

foreach ( $rows as $row ) {
    if ( empty( $row['data'] ) ) {
        continue;
    }
    $decoded = json_decode( $row['data'], true );
    if ( json_last_error() !== JSON_ERROR_NONE ) {
        e2e_fail( "Toplist id={$row['id']} has invalid JSON in data column" );
        $invalid_json++;
        continue;
    }
    $items = $decoded['data']['items'] ?? null;
    if ( ! is_array( $items ) ) {
        e2e_fail( "Toplist id={$row['id']} data.items is not an array (got: " . gettype( $items ) . ")" );
        $invalid_json++;
        continue;
    }
    $actual = count( $items );
    $stored = (int) $row['item_count'];
    if ( $actual !== $stored ) {
        e2e_fail( "Toplist id={$row['id']} item_count mismatch: stored={$stored}, actual={$actual}" );
        $item_count_mismatch++;
    }
}

if ( $invalid_json === 0 ) {
    e2e_pass( 'All sampled toplists have valid JSON with data.items array' );
}
if ( $item_count_mismatch === 0 ) {
    e2e_pass( 'All sampled toplists have item_count matching actual items in JSON' );
}

// ── Test 7: at least one toplist has items ───────────────────────────────────

$with_items = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$toplists_table} WHERE item_count > 0" );
e2e_assert( $with_items > 0, "{$with_items} toplist(s) have item_count > 0", 'No toplists have any items (all item_count = 0)' );

// ── Test 8: last_synced is recent ────────────────────────────────────────────

$recent_synced = (int) $wpdb->get_var(
    $wpdb->prepare(
        "SELECT COUNT(*) FROM {$toplists_table} WHERE last_synced >= %s",
        date( 'Y-m-d H:i:s', $before )
    )
);
e2e_assert(
    $recent_synced > 0,
    "{$recent_synced} toplist(s) have last_synced updated by this sync",
    'No toplists had last_synced updated — sync may not have run correctly'
);

// ── Test 9: modern + legacy timestamps (only when sync ran to completion) ───
// ToplistSyncService::markSyncCompleted() runs only on the last page (or when
// budget-exhausted), so timestamp writes are conditional on $result->isComplete.

$modern_ts = (int) get_option( 'dataflair_last_toplists_sync', 0 );
$legacy_ts = (int) get_option( 'dataflair_last_toplists_cron_run', 0 );

if ( isset( $result ) && $result instanceof SyncResult && $result->isComplete ) {
    e2e_assert(
        $modern_ts >= $before,
        'dataflair_last_toplists_sync updated (' . date( 'Y-m-d H:i:s', $modern_ts ) . ')',
        'dataflair_last_toplists_sync was NOT updated even though SyncResult.isComplete === true'
    );
    e2e_assert(
        $legacy_ts >= $before,
        'Legacy mirror dataflair_last_toplists_cron_run dual-written (' . date( 'Y-m-d H:i:s', $legacy_ts ) . ')',
        'Legacy mirror was NOT updated — dual-write broken'
    );
} else {
    echo "  \xE2\x84\xB9  Single-page sync did not reach completion — timestamp write deferred. "
        . "modern = " . ( $modern_ts ? date( 'Y-m-d H:i:s', $modern_ts ) : '(never)' )
        . ", legacy = " . ( $legacy_ts ? date( 'Y-m-d H:i:s', $legacy_ts ) : '(never)' ) . "\n";
}

// ── Test 10: REST API returns synced data ────────────────────────────────────

$first_api_id = (int) $wpdb->get_var( "SELECT api_toplist_id FROM {$toplists_table} ORDER BY id LIMIT 1" );

if ( $first_api_id ) {
    $admins = get_users( [ 'role' => 'administrator', 'number' => 1, 'fields' => 'ids' ] );
    if ( ! empty( $admins ) ) {
        wp_set_current_user( $admins[0] );
    }

    if ( ! did_action( 'rest_api_init' ) ) {
        do_action( 'rest_api_init' );
    }

    $request  = new WP_REST_Request( 'GET', '/dataflair/v1/toplists' );
    $response = rest_do_request( $request );
    $status   = $response->get_status();
    e2e_assert( $status === 200, "REST /dataflair/v1/toplists returns HTTP 200", "REST /dataflair/v1/toplists returned HTTP {$status}" );

    $data = $response->get_data();
    $is_valid_list = is_array( $data ) && count( $data ) > 0 && isset( $data[0]['value'] ) && isset( $data[0]['label'] );
    e2e_assert(
        $is_valid_list,
        'REST /dataflair/v1/toplists returns ' . count( (array) $data ) . ' {value,label} option(s)',
        'REST /dataflair/v1/toplists returned empty or unexpected data (expected [{value,label},...])'
    );

    $req2  = new WP_REST_Request( 'GET', "/dataflair/v1/toplists/{$first_api_id}/casinos" );
    $res2  = rest_do_request( $req2 );
    $stat2 = $res2->get_status();
    e2e_assert(
        $stat2 === 200,
        "REST /dataflair/v1/toplists/{$first_api_id}/casinos returns HTTP 200",
        "REST /dataflair/v1/toplists/{$first_api_id}/casinos returned HTTP {$stat2}"
    );

    if ( $stat2 === 200 ) {
        $casinos = $res2->get_data();
        e2e_assert(
            is_array( $casinos ),
            "REST /dataflair/v1/toplists/{$first_api_id}/casinos returns array (" . count( (array) $casinos ) . " casino(s))",
            "REST /dataflair/v1/toplists/{$first_api_id}/casinos did not return an array"
        );
    }

    wp_set_current_user( 0 );
}

// ── Test 11: alternative toplists table ──────────────────────────────────────

$alt_exists = $wpdb->get_var( "SHOW TABLES LIKE '{$alt_table}'" ) === $alt_table;
if ( $alt_exists ) {
    $alt_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$alt_table}" );
    e2e_pass( "Alternative toplists table exists ({$alt_count} rows)" );
} else {
    e2e_pass( "Alternative toplists table not present (optional feature — skipped)" );
}

// ── Summary ───────────────────────────────────────────────────────────────────

$p = $GLOBALS['e2e_pass'];
$f = $GLOBALS['e2e_fail'];
echo "\n\033[1mToplist Sync: {$p} passed, {$f} failed\033[0m\n\n";
exit( $f > 0 ? 1 : 0 );
