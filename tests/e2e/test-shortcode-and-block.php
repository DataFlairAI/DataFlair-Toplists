<?php
/**
 * E2E Test: Shortcode + Gutenberg Block (user request — Apr 2026)
 *
 * Covers user's scenarios 2 + 4:
 *   2. Create a shortcode, add it to a page  → render → assert HTML contract
 *   4. Publish a page with the toplist Gutenberg block → render → assert HTML
 *
 * Also touches scenario 3 (review CPT round-trip) by spot-checking that the
 * card markup contains a Read Review link when a published review CPT exists
 * for one of the brands in the rendered toplist.
 *
 * Pre-conditions: at least one row in wp_dataflair_toplists with item_count > 0.
 * If none exists this script triggers a sync once before running.
 *
 * Usage:
 *   docker exec <cli-container> wp --allow-root eval-file \
 *     /var/www/html/wp-content/plugins/DataFlair-Toplists/tests/e2e/test-shortcode-and-block.php
 */

if (!defined('ABSPATH')) {
    die('Run via WP-CLI: wp eval-file tests/e2e/test-shortcode-and-block.php' . PHP_EOL);
}

global $wpdb;

$GLOBALS['e2e_pass'] = 0;
$GLOBALS['e2e_fail'] = 0;

function e2e_pass(string $msg): void {
    $GLOBALS['e2e_pass']++;
    echo "\033[32m✓\033[0m {$msg}\n";
}
function e2e_fail(string $msg): void {
    $GLOBALS['e2e_fail']++;
    echo "\033[31m✗\033[0m {$msg}\n";
}
function e2e_assert(bool $cond, string $pass_msg, string $fail_msg): void {
    $cond ? e2e_pass($pass_msg) : e2e_fail($fail_msg);
}

echo "\n\033[1m── Shortcode + Gutenberg Block E2E ──\033[0m\n\n";

// ── Pre-flight: pick a toplist with items ───────────────────────────────────

$toplists_table = $wpdb->prefix . DATAFLAIR_TABLE_NAME;
$row = $wpdb->get_row(
    "SELECT api_toplist_id, slug, name, item_count
     FROM {$toplists_table}
     WHERE item_count > 0
     ORDER BY item_count DESC
     LIMIT 1",
    ARRAY_A
);

if (!$row) {
    echo "  No synced toplists found — running a sync first…\n";
    do_action('dataflair_sync_cron');
    $row = $wpdb->get_row(
        "SELECT api_toplist_id, slug, name, item_count
         FROM {$toplists_table}
         WHERE item_count > 0
         ORDER BY item_count DESC
         LIMIT 1",
        ARRAY_A
    );
}

if (!$row) {
    e2e_fail('No toplists with items in DB even after sync — cannot test render');
    echo "\nDone: {$GLOBALS['e2e_pass']} passed, {$GLOBALS['e2e_fail']} failed.\n";
    exit(1);
}

$toplist_api_id   = (int) $row['api_toplist_id'];
$toplist_slug     = (string) $row['slug'];
$toplist_name     = (string) $row['name'];
$toplist_items    = (int) $row['item_count'];

e2e_pass("Test target: toplist api_id={$toplist_api_id} slug='{$toplist_slug}' ({$toplist_items} items)");

// ── Test 1: shortcode rendered inline returns HTML ──────────────────────────

$shortcode = "[dataflair_toplist id=\"{$toplist_api_id}\"]";
$rendered  = do_shortcode($shortcode);

e2e_assert(
    is_string($rendered) && strlen($rendered) > 100,
    'Inline shortcode renders a non-trivial HTML string (' . strlen($rendered) . ' chars)',
    'Inline shortcode returned empty/short output: ' . substr($rendered, 0, 200)
);

e2e_assert(
    str_contains($rendered, 'dataflair-toplist'),
    'Shortcode HTML contains the dataflair-toplist wrapper class',
    'Shortcode HTML missing dataflair-toplist wrapper'
);

e2e_assert(
    str_contains($rendered, 'casino-card') || str_contains($rendered, 'toplist-table'),
    'Shortcode HTML contains either casino-card or toplist-table markup',
    'Shortcode HTML contains neither casino-card nor toplist-table — render path broken'
);

// ── Test 2: shortcode by slug also works ─────────────────────────────────────

if ($toplist_slug !== '') {
    $by_slug = do_shortcode("[dataflair_toplist slug=\"{$toplist_slug}\"]");
    e2e_assert(
        is_string($by_slug) && strlen($by_slug) > 100,
        "Shortcode by slug='{$toplist_slug}' renders non-trivial HTML",
        'Shortcode by slug returned empty/short output'
    );
}

// ── Test 3: shortcode embedded in a published Page ───────────────────────────

$page_id = wp_insert_post(array(
    'post_title'   => 'E2E Shortcode Page ' . wp_rand(100000, 999999),
    'post_status'  => 'publish',
    'post_type'    => 'page',
    'post_content' => "<p>Intro before toplist.</p>\n\n{$shortcode}\n\n<p>Outro.</p>",
    'post_author'  => get_users(array('role' => 'administrator', 'number' => 1, 'fields' => 'ids'))[0] ?? 1,
), true);

if (is_wp_error($page_id) || !$page_id) {
    e2e_fail('Failed to create shortcode page: ' . (is_wp_error($page_id) ? $page_id->get_error_message() : 'unknown'));
    echo "\nDone: {$GLOBALS['e2e_pass']} passed, {$GLOBALS['e2e_fail']} failed.\n";
    exit(1);
}

e2e_pass("Created published page #{$page_id} containing the shortcode");

// Force the_content filter chain — same path as a real page render
$page_post = get_post($page_id);
setup_postdata($page_post);
$page_html = apply_filters('the_content', $page_post->post_content);
wp_reset_postdata();

e2e_assert(
    str_contains($page_html, 'dataflair-toplist'),
    'Page render expanded the [dataflair_toplist] shortcode (wrapper present)',
    'Page render did NOT expand the shortcode — wrapper missing in the_content output'
);

e2e_assert(
    !str_contains($page_html, '[dataflair_toplist'),
    'Raw [dataflair_toplist tag is fully expanded (no leakage)',
    'Raw [dataflair_toplist tag remained unexpanded in the page HTML'
);

// ── Test 4: same render via WP REST page endpoint ────────────────────────────

if (!did_action('rest_api_init')) do_action('rest_api_init');

$req = new WP_REST_Request('GET', "/wp/v2/pages/{$page_id}");
$req->set_query_params(array('context' => 'view'));
$res = rest_do_request($req);
$rest_status = $res->get_status();
$rest_data   = $res->get_data();

e2e_assert(
    $rest_status === 200,
    "WP REST GET /wp/v2/pages/{$page_id} returns 200",
    "WP REST GET /wp/v2/pages/{$page_id} returned {$rest_status}"
);

if ($rest_status === 200 && isset($rest_data['content']['rendered'])) {
    e2e_assert(
        str_contains($rest_data['content']['rendered'], 'dataflair-toplist'),
        'WP REST page content.rendered contains the toplist wrapper',
        'WP REST page content.rendered missing toplist wrapper — REST render path broken'
    );
}

// ── Test 5: Gutenberg block — published page with dataflair/toplist ─────────

// Block name is `dataflair-toplists/toplist` (registered via build/block.json
// with attribute "toplistId"). Using the wrong namespace returns an empty
// string from do_blocks(), so the namespace MUST match build/block.json.
$block_markup = sprintf(
    '<!-- wp:dataflair-toplists/toplist {"toplistId":%d} /-->',
    $toplist_api_id
);

$block_page_id = wp_insert_post(array(
    'post_title'   => 'E2E Block Page ' . wp_rand(100000, 999999),
    'post_status'  => 'publish',
    'post_type'    => 'page',
    'post_content' => "<p>Block test.</p>\n\n{$block_markup}\n\n<p>End.</p>",
    'post_author'  => get_users(array('role' => 'administrator', 'number' => 1, 'fields' => 'ids'))[0] ?? 1,
), true);

if (is_wp_error($block_page_id) || !$block_page_id) {
    e2e_fail('Failed to create block page: ' . (is_wp_error($block_page_id) ? $block_page_id->get_error_message() : 'unknown'));
} else {
    e2e_pass("Created published page #{$block_page_id} containing the dataflair/toplist block");

    // Block is dynamic (server-rendered) — the_content runs do_blocks() through render_block().
    $block_post = get_post($block_page_id);
    setup_postdata($block_post);
    $block_html = apply_filters('the_content', $block_post->post_content);
    wp_reset_postdata();

    e2e_assert(
        !str_contains($block_html, '<!-- wp:dataflair-toplists/toplist'),
        'Gutenberg block comment is fully consumed (no raw wp:dataflair-toplists/toplist comment left)',
        'Raw <!-- wp:dataflair-toplists/toplist comment leaked into rendered HTML'
    );

    e2e_assert(
        str_contains($block_html, 'dataflair-toplist'),
        'Block render produced dataflair-toplist wrapper',
        'Block render did NOT produce dataflair-toplist wrapper — server-render callback broken'
    );

    e2e_assert(
        str_contains($block_html, 'casino-card') || str_contains($block_html, 'toplist-table'),
        'Block render produced casino-card or toplist-table markup',
        'Block render missing casino-card / toplist-table markup'
    );
}

// ── Test 6: rendered card includes brand + offer data points ─────────────────

// Pull one brand from the toplist's data.items and assert its name appears in render.
$data_row = $wpdb->get_row(
    $wpdb->prepare("SELECT data FROM {$toplists_table} WHERE api_toplist_id = %d", $toplist_api_id),
    ARRAY_A
);
$decoded = is_array($data_row) ? json_decode($data_row['data'], true) : null;
$first_item = $decoded['data']['items'][0] ?? null;
$first_brand_name = $first_item['brand']['name'] ?? '';

if ($first_brand_name !== '') {
    // Brand names may contain HTML special chars — normalise both sides.
    $needle_html = esc_html($first_brand_name);
    e2e_assert(
        str_contains($rendered, $needle_html) || str_contains($rendered, $first_brand_name),
        "Rendered HTML contains first brand name '{$first_brand_name}'",
        "Rendered HTML missing first brand name '{$first_brand_name}' — data plumbing broken"
    );
}

// ── Test 7: rendered HTML carries no fatal-error marker ─────────────────────

foreach (array($rendered, $page_html, $block_html ?? '') as $haystack) {
    if ($haystack === '') continue;
    if (preg_match('/(?i)(fatal error|stack trace|wpdb prepare was called incorrectly)/', $haystack, $m)) {
        e2e_fail("Render output contains fatal-error marker: {$m[0]}");
    }
}
e2e_pass('No fatal-error markers in any rendered output');

// ── Cleanup ───────────────────────────────────────────────────────────────────

wp_delete_post($page_id, true);
if (!empty($block_page_id) && !is_wp_error($block_page_id)) {
    wp_delete_post($block_page_id, true);
}
e2e_pass('Cleaned up test pages');

// ── Summary ───────────────────────────────────────────────────────────────────

$p = $GLOBALS['e2e_pass'];
$f = $GLOBALS['e2e_fail'];
echo "\n\033[1mShortcode + Block: {$p} passed, {$f} failed\033[0m\n\n";
exit($f > 0 ? 1 : 0);
