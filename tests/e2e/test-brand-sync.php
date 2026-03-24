<?php
/**
 * E2E Test: Brand Sync
 *
 * Verifies that syncing brands from the DataFlair API:
 *  1. Returns success (no HTTP errors)
 *  2. Populates wp_dataflair_brands with at least 1 row
 *  3. Each brand has required fields: id, api_brand_id, name, slug, status
 *  4. Brand data column contains valid JSON
 *  5. product_types is populated for at least one brand
 *  6. A second sync is idempotent (row count stays same ± small delta)
 *  7. dataflair_last_brands_cron_run option is updated
 *
 * Run via WP-CLI (inside Docker):
 *   wp --allow-root eval-file /var/www/html/wp-content/plugins/DataFlair-Toplists/tests/e2e/test-brand-sync.php
 */

if ( ! defined( 'ABSPATH' ) ) {
    die( 'Run via WP-CLI: wp eval-file tests/e2e/test-brand-sync.php' . PHP_EOL );
}

global $wpdb;

// ── Helpers ───────────────────────────────────────────────────────────────────
// NOTE: $GLOBALS used because WP-CLI eval-file wraps code in a function scope,
// making file-level $pass/$fail invisible to nested functions via `global`.

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

// ── Pre-flight ────────────────────────────────────────────────────────────────

echo "\n\033[1m── Brand Sync E2E Tests ──\033[0m\n\n";

$token = trim( get_option( 'dataflair_api_token' ) );
e2e_assert( ! empty( $token ), 'API token is configured', 'API token is MISSING — cannot run brand sync tests' );

if ( empty( $token ) ) {
    echo "\nAborted: no token.\n";
    exit( 1 );
}

$brands_table = $wpdb->prefix . DATAFLAIR_BRANDS_TABLE_NAME;

// ── Test 1: brands table exists ───────────────────────────────────────────────

$table_exists = $wpdb->get_var( "SHOW TABLES LIKE '{$brands_table}'" ) === $brands_table;
e2e_assert( $table_exists, "Brands table '{$brands_table}' exists", "Brands table '{$brands_table}' does NOT exist" );

if ( ! $table_exists ) {
    echo "\nAborted: table missing.\n";
    exit( 1 );
}

// ── Test 2: sync runs without fatal error ─────────────────────────────────────

echo "  Running brand sync (this may take a few seconds)…\n";
$before = time();

try {
    do_action( 'dataflair_brands_sync_cron' );
    $after = time();
    e2e_pass( 'Brand sync completed without fatal error (' . ( $after - $before ) . 's)' );
} catch ( Throwable $e ) {
    e2e_fail( 'Brand sync threw exception: ' . $e->getMessage() );
    exit( 1 );
}

// ── Test 3: rows exist after sync ─────────────────────────────────────────────

$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$brands_table}" );
e2e_assert( $count > 0, "Brands table has {$count} rows after sync", "Brands table is empty after sync (0 rows)" );

// ── Test 4: required columns are populated ────────────────────────────────────

$sample = $wpdb->get_results(
    "SELECT id, api_brand_id, name, slug, status, data FROM {$brands_table} LIMIT 10",
    ARRAY_A
);

$missing_fields = 0;
foreach ( $sample as $row ) {
    foreach ( [ 'id', 'api_brand_id', 'name', 'slug', 'status' ] as $col ) {
        if ( empty( $row[ $col ] ) ) {
            e2e_fail( "Brand id={$row['id']} missing required field: {$col}" );
            $missing_fields++;
        }
    }
}

if ( $missing_fields === 0 ) {
    e2e_pass( 'All sampled brands have required fields (id, api_brand_id, name, slug, status)' );
}

// ── Test 5: data column is valid JSON ─────────────────────────────────────────

$invalid_json = 0;
foreach ( $sample as $row ) {
    if ( ! empty( $row['data'] ) ) {
        json_decode( $row['data'] );
        if ( json_last_error() !== JSON_ERROR_NONE ) {
            e2e_fail( "Brand id={$row['id']} has invalid JSON in data column" );
            $invalid_json++;
        }
    }
}

if ( $invalid_json === 0 ) {
    e2e_pass( 'All sampled brands have valid JSON in the data column' );
}

// ── Test 6: at least one brand has product_types set ─────────────────────────

$with_product_types = (int) $wpdb->get_var(
    "SELECT COUNT(*) FROM {$brands_table} WHERE product_types IS NOT NULL AND product_types != ''"
);
e2e_assert(
    $with_product_types > 0,
    "{$with_product_types} brands have product_types populated",
    'No brands have product_types populated'
);

// ── Test 7: cron timestamp updated ───────────────────────────────────────────

$last_run = (int) get_option( 'dataflair_last_brands_cron_run', 0 );
e2e_assert(
    $last_run >= $before,
    'dataflair_last_brands_cron_run updated after sync (' . date( 'Y-m-d H:i:s', $last_run ) . ')',
    'dataflair_last_brands_cron_run was NOT updated after sync'
);

// ── Test 8: second sync is idempotent ─────────────────────────────────────────

echo "  Running second sync to verify idempotency…\n";
do_action( 'dataflair_brands_sync_cron' );
$count2 = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$brands_table}" );
$delta  = abs( $count2 - $count );
e2e_assert(
    $delta <= 5,
    "Second sync is idempotent (first: {$count}, second: {$count2}, delta: {$delta})",
    "Second sync changed row count significantly (first: {$count}, second: {$count2}, delta: {$delta})"
);

// ── Summary ───────────────────────────────────────────────────────────────────

$p = $GLOBALS['e2e_pass'];
$f = $GLOBALS['e2e_fail'];
echo "\n\033[1mBrand Sync: {$p} passed, {$f} failed\033[0m\n\n";
exit( $f > 0 ? 1 : 0 );
