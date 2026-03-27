<?php
/**
 * E2E Test: Read Review link visibility (casino-review-link)
 *
 * Verifies render_casino_card() output:
 *  - Draft review CPT: no "Read Review" link (no .casino-review-link)
 *  - Published review CPT: "Read Review" link present
 *
 * Run via WP-CLI:
 *   wp --allow-root eval-file wp-content/plugins/DataFlair-Toplists/tests/e2e/test-read-review-link.php
 *
 * Or from plugin directory:
 *   wp eval-file tests/e2e/test-read-review-link.php
 */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Run via WP-CLI: wp eval-file tests/e2e/test-read-review-link.php' . PHP_EOL );
}

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

echo "\n\033[1m── Read Review link E2E (draft vs published) ──\033[0m\n\n";

if ( ! post_type_exists( 'review' ) ) {
	e2e_fail( "Post type 'review' is not registered — cannot run this E2E test." );
	echo "\nDone: {$GLOBALS['e2e_pass']} passed, {$GLOBALS['e2e_fail']} failed.\n";
	exit( 1 );
}

e2e_pass( "Post type 'review' is registered" );

$slug = 'e2e-df-read-review-' . wp_rand( 100000, 999999 );
$api_brand_id = 870000000 + ( wp_rand( 0, 99999 ) ); // unlikely collision with real brands

$item = array(
	'position' => 1,
	'rating'   => 4.5,
	'brand'    => array(
		'name'         => 'E2E Read Review Brand',
		'slug'         => $slug,
		'api_brand_id' => $api_brand_id,
	),
	'offer'    => array(
		'offerText' => 'E2E offer',
		'trackers'  => array(),
	),
);

$review_id = wp_insert_post(
	array(
		'post_title'   => 'E2E Read Review Brand Review',
		'post_name'    => $slug,
		'post_content' => '',
		'post_status'  => 'draft',
		'post_type'    => 'review',
		'post_author'  => get_current_user_id() ?: 1,
	)
);

if ( is_wp_error( $review_id ) || ! $review_id ) {
	e2e_fail( 'Failed to create draft review post: ' . ( is_wp_error( $review_id ) ? $review_id->get_error_message() : 'unknown' ) );
	echo "\nDone: {$GLOBALS['e2e_pass']} passed, {$GLOBALS['e2e_fail']} failed.\n";
	exit( 1 );
}

e2e_pass( "Created draft review post #{$review_id} (slug: {$slug})" );

$plugin = DataFlair_Toplists::get_instance();
$ref    = new ReflectionMethod( DataFlair_Toplists::class, 'render_casino_card' );
$ref->setAccessible( true );

$html_draft = $ref->invoke( $plugin, $item, 0, array(), array() );

$has_link_draft = ( false !== strpos( $html_draft, 'casino-review-link' ) );
e2e_assert(
	! $has_link_draft,
	'Draft review: output does NOT contain casino-review-link',
	'Draft review: output unexpectedly contains casino-review-link'
);

$updated = wp_update_post(
	array(
		'ID'          => $review_id,
		'post_status' => 'publish',
	),
	true
);

if ( is_wp_error( $updated ) ) {
	e2e_fail( 'Failed to publish review: ' . $updated->get_error_message() );
	wp_delete_post( $review_id, true );
	echo "\nDone: {$GLOBALS['e2e_pass']} passed, {$GLOBALS['e2e_fail']} failed.\n";
	exit( 1 );
}

e2e_pass( "Published review post #{$review_id}" );

$html_publish = $ref->invoke( $plugin, $item, 0, array(), array() );

$has_link_publish = ( false !== strpos( $html_publish, 'casino-review-link' ) );
e2e_assert(
	$has_link_publish,
	'Published review: output contains casino-review-link',
	'Published review: output missing casino-review-link'
);

wp_delete_post( $review_id, true );
e2e_pass( "Cleaned up test review post #{$review_id}" );

// ── Slug mismatch: published review at ...-india but API brand slug is shorter (same _review_brand_id) ──

echo "\n\033[1m── Slug mismatch (meta match) ──\033[0m\n\n";

$short_slug   = 'e2e-df-brand-' . wp_rand( 100000, 999999 );
$long_slug    = $short_slug . '-india';
$meta_brand_id = 860000000 + ( wp_rand( 0, 99999 ) );

$mismatch_id = wp_insert_post(
	array(
		'post_title'   => 'E2E India Slug Review',
		'post_name'    => $long_slug,
		'post_content' => '',
		'post_status'  => 'publish',
		'post_type'    => 'review',
		'post_author'  => get_current_user_id() ?: 1,
	)
);

if ( is_wp_error( $mismatch_id ) || ! $mismatch_id ) {
	e2e_fail( 'Failed to create mismatch slug review: ' . ( is_wp_error( $mismatch_id ) ? $mismatch_id->get_error_message() : 'unknown' ) );
	echo "\nDone: {$GLOBALS['e2e_pass']} passed, {$GLOBALS['e2e_fail']} failed.\n";
	exit( 1 );
}

update_post_meta( $mismatch_id, '_review_brand_id', (string) $meta_brand_id );

$item_mismatch = array(
	'position' => 1,
	'rating'   => 4.5,
	'brand'    => array(
		'name'         => 'E2E India Brand',
		'slug'         => $short_slug,
		'api_brand_id' => $meta_brand_id,
	),
	'offer'    => array(
		'offerText' => 'E2E offer',
		'trackers'  => array(),
	),
);

$html_mismatch = $ref->invoke( $plugin, $item_mismatch, 0, array(), array() );
$has_link_meta = ( false !== strpos( $html_mismatch, 'casino-review-link' ) );
e2e_assert(
	$has_link_meta,
	'Published review with different slug but matching _review_brand_id: casino-review-link present',
	'Slug mismatch: expected casino-review-link when _review_brand_id matches api_brand_id'
);

wp_delete_post( $mismatch_id, true );
e2e_pass( "Cleaned up mismatch-slug review post #{$mismatch_id}" );

// ── Draft at exact API slug + published at ...-india (same _review_brand_id), live 1xBet pattern ──

echo "\n\033[1m── Draft at API slug does not shadow published …-india ──\033[0m\n\n";

$base_slug  = 'e2e-df-shadow-' . wp_rand( 100000, 999999 );
$shadow_bid = 840000000 + ( wp_rand( 0, 99999 ) );

$shadow_draft = wp_insert_post(
	array(
		'post_title'   => 'E2E Shadow Draft',
		'post_name'    => $base_slug,
		'post_content' => '',
		'post_status'  => 'draft',
		'post_type'    => 'review',
		'post_author'  => get_current_user_id() ?: 1,
	)
);
$shadow_pub = wp_insert_post(
	array(
		'post_title'   => 'E2E Shadow Live',
		'post_name'    => $base_slug . '-india',
		'post_content' => '',
		'post_status'  => 'publish',
		'post_type'    => 'review',
		'post_author'  => get_current_user_id() ?: 1,
	)
);
update_post_meta( $shadow_draft, '_review_brand_id', (string) $shadow_bid );
update_post_meta( $shadow_pub, '_review_brand_id', (string) $shadow_bid );

$item_shadow = array(
	'position' => 1,
	'rating'   => 4.5,
	'brand'    => array(
		'name'         => 'E2E Shadow Brand',
		'slug'         => $base_slug,
		'api_brand_id' => $shadow_bid,
	),
	'offer'    => array(
		'offerText' => 'E2E offer',
		'trackers'  => array(),
	),
);

$html_shadow = $ref->invoke( $plugin, $item_shadow, 0, array(), array() );
$permalink_pub = get_permalink( $shadow_pub );
e2e_assert(
	false !== strpos( $html_shadow, 'casino-review-link' ),
	'Draft at API slug + published -india: Read Review link shown',
	'Shadow draft blocked published India review — missing casino-review-link'
);
e2e_assert(
	false !== strpos( $html_shadow, $permalink_pub ),
	'Card links point at published review permalink, not draft slug placeholder',
	'Expected Read Review href to match published permalink: ' . $permalink_pub
);

wp_delete_post( $shadow_draft, true );
wp_delete_post( $shadow_pub, true );
e2e_pass( 'Cleaned up shadow draft + published posts' );

echo "\nDone: {$GLOBALS['e2e_pass']} passed, {$GLOBALS['e2e_fail']} failed.\n";
exit( $GLOBALS['e2e_fail'] > 0 ? 1 : 0 );
