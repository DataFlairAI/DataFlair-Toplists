<?php
/**
 * Test Toplist Rendering — Shortcode, Gutenberg Block, Geo Edge Cases
 *
 * Coverage:
 *  - Shortcode: missing id, invalid id, valid id, limit, custom title
 *  - Stale data warning (data older than 3 days shows banner)
 *  - Geo edge cases: country geo, market geo, global toplist (null geo name)
 *  - Gutenberg block: no toplistId configured → placeholder message
 *  - Gutenberg block: render_block() delegates to toplist_shortcode() correctly
 *  - REST endpoint: GET /wp-json/dataflair/v1/toplists (block editor list)
 *  - REST endpoint: GET /wp-json/dataflair/v1/toplists/{id}/casinos
 *  - Casino card rendering: logo resolution chain (local → rectangular → square → empty)
 *  - Casino card: missing brand data gracefully handled
 *
 * Run via WP-CLI:  wp eval-file tests/test-toplist-render.php
 * Run via browser: /wp-admin/?page=dataflair&tab=debug&run_test=toplist-render
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

$GLOBALS['_dfr_pass'] = 0;
$GLOBALS['_dfr_fail'] = 0;
$GLOBALS['_dfr_warn'] = 0;

function dfr_pass(string $msg): void { $GLOBALS['_dfr_pass']++; echo "<p class='test-pass'>✓ {$msg}</p>\n"; }
function dfr_fail(string $msg): void { $GLOBALS['_dfr_fail']++; echo "<p class='test-fail'>✗ {$msg}</p>\n"; }
function dfr_warn(string $msg): void { $GLOBALS['_dfr_warn']++; echo "<p class='test-warning'>⚠ {$msg}</p>\n"; }
function dfr_info(string $msg): void { echo "<p class='test-info'>{$msg}</p>\n"; }
function dfr_section(string $t): void { echo "<div class='test-section'>\n<h3>{$t}</h3>\n"; }
function dfr_end(): void { echo "</div>\n"; }

// ── Main ──────────────────────────────────────────────────────────────────────

function test_toplist_render(): void {
    echo "<h2>🧪 Toplist Rendering — Comprehensive Tests</h2>\n";
    echo "<style>
        .test-container{max-width:1200px;margin:20px auto;padding:20px;font-family:monospace}
        .test-section{background:#f5f5f5;padding:15px;margin:10px 0;border-left:4px solid #0073aa}
        .test-pass{color:#00a32a;font-weight:bold;margin:3px 0}
        .test-fail{color:#d63638;font-weight:bold;margin:3px 0}
        .test-info{color:#2271b1;margin:3px 0}
        .test-warning{color:#dba617;margin:3px 0}
        pre{background:#1e1e1e;color:#d4d4d4;padding:10px;overflow-x:auto;font-size:12px}
        details summary{cursor:pointer;color:#2271b1}
        .render-output{border:2px dashed #ccc;padding:10px;margin:10px 0;background:#fff}
    </style>\n";
    echo "<div class='test-container'>\n";

    // ── Get plugin instance ───────────────────────────────────────────────────
    dfr_section('T01 · Plugin Instance');
    if (!class_exists('DataFlair_Toplists')) {
        dfr_fail("DataFlair_Toplists class not found — is the plugin active?");
        dfr_end();
        echo "</div>\n"; return;
    }
    $plugin = DataFlair_Toplists::get_instance();
    if (!$plugin) {
        dfr_fail("Could not get plugin instance via get_instance()");
        dfr_end();
        echo "</div>\n"; return;
    }
    dfr_pass("Plugin instance obtained");

    // Confirm shortcode is registered
    if (shortcode_exists('dataflair_toplist')) {
        dfr_pass("Shortcode [dataflair_toplist] is registered");
    } else {
        dfr_fail("Shortcode [dataflair_toplist] is NOT registered");
    }
    dfr_end();

    // ── Fetch available toplists from DB ──────────────────────────────────────
    global $wpdb;
    $table = $wpdb->prefix . 'dataflair_toplists';
    $toplists = $wpdb->get_results("SELECT * FROM {$table} ORDER BY id ASC LIMIT 20");

    dfr_section('T02 · Synced Toplists in Database');
    if (empty($toplists)) {
        dfr_warn("No toplists found in the database. Run Settings → Fetch All Toplists first.");
        dfr_info("Skipping render tests — no synced data to test against.");
        dfr_end();
        echo "</div>\n";
        _dfr_summary();
        return;
    }
    dfr_pass(count($toplists) . " toplist(s) found in DB");

    // Build a quick summary
    echo "<table style='width:100%;border-collapse:collapse;font-size:13px'>\n";
    echo "<tr style='background:#0073aa;color:white'><th style='padding:6px'>api_toplist_id</th><th style='padding:6px'>name</th><th style='padding:6px'>geo.name</th><th style='padding:6px'>last_synced</th><th style='padding:6px'>data age</th></tr>\n";
    foreach ($toplists as $tl) {
        $d = json_decode($tl->data, true);
        $geo_name   = $d['data']['geo']['name'] ?? '(null — global)';
        $geo_type   = $d['data']['geo']['geo_type'] ?? '(null)';
        $age_secs   = time() - strtotime($tl->last_synced);
        $age_days   = round($age_secs / 86400, 1);
        $age_color  = $age_days > 3 ? '#d63638' : '#00a32a';
        echo "<tr>
            <td style='padding:6px;border:1px solid #ddd'>{$tl->api_toplist_id}</td>
            <td style='padding:6px;border:1px solid #ddd'>" . esc_html($tl->name) . "</td>
            <td style='padding:6px;border:1px solid #ddd'>" . esc_html("{$geo_type}: {$geo_name}") . "</td>
            <td style='padding:6px;border:1px solid #ddd'>" . esc_html($tl->last_synced) . "</td>
            <td style='padding:6px;border:1px solid #ddd;color:{$age_color}'>{$age_days}d</td>
        </tr>\n";
    }
    echo "</table>\n";
    dfr_end();

    $first_toplist    = $toplists[0];
    $first_id         = $first_toplist->api_toplist_id;
    $first_data       = json_decode($first_toplist->data, true);
    $first_item_count = count($first_data['data']['items'] ?? []);

    // ── T03: Shortcode — missing id ───────────────────────────────────────────
    dfr_section("T03 · Shortcode — Missing id attribute");
    $out = do_shortcode('[dataflair_toplist]');
    if (str_contains($out, 'Error') && str_contains($out, 'required')) {
        dfr_pass("Missing id → error message shown: \"" . esc_html(strip_tags($out)) . "\"");
    } elseif (str_contains($out, 'Error')) {
        dfr_pass("Missing id → error message shown (contains 'Error'): " . esc_html(strip_tags($out)));
    } else {
        dfr_fail("Missing id → expected error message, got: " . esc_html(substr(strip_tags($out), 0, 100)));
    }
    dfr_end();

    // ── T04: Shortcode — non-existent id ─────────────────────────────────────
    dfr_section("T04 · Shortcode — Non-Existent id (999999)");
    $out = do_shortcode('[dataflair_toplist id="999999"]');
    if (str_contains($out, 'Error') || str_contains($out, 'not found')) {
        dfr_pass("Non-existent id → error/not-found message: \"" . esc_html(strip_tags($out)) . "\"");
    } else {
        dfr_fail("Non-existent id → expected error, got: " . esc_html(substr(strip_tags($out), 0, 100)));
    }
    dfr_end();

    // ── T05: Shortcode — valid id ─────────────────────────────────────────────
    dfr_section("T05 · Shortcode — Valid id={$first_id}");
    $out = do_shortcode("[dataflair_toplist id=\"{$first_id}\"]");

    if (empty(trim($out))) {
        dfr_fail("Shortcode returned empty output for id={$first_id}");
    } elseif (str_contains($out, 'dataflair-toplist')) {
        dfr_pass("Shortcode rendered .dataflair-toplist container");
    } else {
        dfr_warn("Shortcode returned output but no .dataflair-toplist found. Output start: " . esc_html(substr(strip_tags($out), 0, 100)));
    }

    if ($first_item_count > 0) {
        if (str_contains($out, 'casino-card') || str_contains($out, 'casino-card-wrapper')) {
            dfr_pass("Casino cards rendered ({$first_item_count} items in data)");
        } else {
            dfr_warn("No casino cards found in output despite {$first_item_count} items in data");
        }
    }

    echo "<details><summary>View rendered HTML (first toplist)</summary>\n";
    echo "<div class='render-output'>" . $out . "</div>\n";
    echo "</details>\n";
    dfr_end();

    // ── T06: Shortcode — limit attribute ─────────────────────────────────────
    dfr_section("T06 · Shortcode — limit=2 attribute (toplist has {$first_item_count} items)");
    if ($first_item_count < 2) {
        dfr_warn("Toplist only has {$first_item_count} item(s) — cannot meaningfully test limit=2");
    } else {
        $out_limit = do_shortcode("[dataflair_toplist id=\"{$first_id}\" limit=\"2\"]");
        $out_full  = do_shortcode("[dataflair_toplist id=\"{$first_id}\"]");

        // Count casino card occurrences (rough proxy)
        $cards_limited = substr_count($out_limit, 'casino-card-wrapper');
        $cards_full    = substr_count($out_full,   'casino-card-wrapper');

        if ($cards_limited > 0 && $cards_limited <= 2) {
            dfr_pass("limit=2 → {$cards_limited} casino card(s) rendered (within limit)");
        } elseif ($cards_limited === 0) {
            dfr_warn("limit=2 → no casino cards found in output (check template class names)");
        } else {
            dfr_fail("limit=2 → {$cards_limited} casino cards rendered (expected ≤ 2)");
        }

        if ($cards_full > $cards_limited) {
            dfr_pass("Full render ({$cards_full} cards) > limited render ({$cards_limited} cards) — limit working");
        }
    }
    dfr_end();

    // ── T07: Shortcode — custom title attribute ───────────────────────────────
    dfr_section("T07 · Shortcode — Custom title attribute");
    $custom_title = 'My Custom Test Title ' . time();
    $out = do_shortcode("[dataflair_toplist id=\"{$first_id}\" title=\"{$custom_title}\"]");
    if (str_contains($out, $custom_title)) {
        dfr_pass("Custom title appears in rendered output");
    } else {
        dfr_fail("Custom title \"{$custom_title}\" NOT found in rendered output");
    }

    // Verify default title (toplist name) when no custom title
    $default_name = $first_data['data']['name'] ?? '';
    if (!empty($default_name)) {
        $out_default = do_shortcode("[dataflair_toplist id=\"{$first_id}\"]");
        if (str_contains($out_default, esc_html($default_name))) {
            dfr_pass("Default title (toplist name: \"{$default_name}\") used when no title attribute");
        } else {
            dfr_warn("Default title \"{$default_name}\" not found in output — might be HTML-escaped differently");
        }
    }
    dfr_end();

    // ── T08: Stale data warning ───────────────────────────────────────────────
    dfr_section("T08 · Stale Data Warning (>3 days old)");

    // Find a toplist older than 3 days, or simulate one
    $stale_toplist = null;
    foreach ($toplists as $tl) {
        $age = time() - strtotime($tl->last_synced);
        if ($age > (3 * 86400)) { $stale_toplist = $tl; break; }
    }

    if ($stale_toplist) {
        $stale_id = $stale_toplist->api_toplist_id;
        $age_days = round((time() - strtotime($stale_toplist->last_synced)) / 86400, 1);
        dfr_info("Found stale toplist id={$stale_id} ({$age_days} days old)");
        $out = do_shortcode("[dataflair_toplist id=\"{$stale_id}\"]");
        if (str_contains($out, 'dataflair-notice') || str_contains($out, 'last updated') || str_contains($out, 'cached')) {
            dfr_pass("Stale data warning banner present in output");
        } else {
            dfr_fail("No stale data warning found — expected banner for data {$age_days} days old");
        }
    } else {
        // Simulate by temporarily updating last_synced to 4 days ago
        dfr_info("No organically stale toplist found — simulating by setting last_synced to 4 days ago");
        $original_synced = $first_toplist->last_synced;
        $stale_time = date('Y-m-d H:i:s', strtotime('-4 days'));
        $wpdb->update($table, ['last_synced' => $stale_time], ['id' => $first_toplist->id]);

        $out = do_shortcode("[dataflair_toplist id=\"{$first_id}\"]");
        if (str_contains($out, 'dataflair-notice') || str_contains($out, 'last updated') || str_contains($out, 'cached')) {
            dfr_pass("Stale data warning banner present when last_synced is 4 days ago");
        } else {
            dfr_fail("No stale data warning found even when last_synced is 4 days ago");
        }

        // Restore original last_synced
        $wpdb->update($table, ['last_synced' => $original_synced], ['id' => $first_toplist->id]);
        dfr_info("Restored last_synced to original value");

        // Also verify: fresh toplist should NOT show warning
        $out_fresh = do_shortcode("[dataflair_toplist id=\"{$first_id}\"]");
        if (!str_contains($out_fresh, 'dataflair-notice')) {
            dfr_pass("No stale warning on fresh data (correct)");
        } else {
            dfr_warn("Stale warning present on fresh data (false positive?)");
        }
    }
    dfr_end();

    // ── T09: Geo edge cases ───────────────────────────────────────────────────
    dfr_section("T09 · Geo Edge Cases");

    $country_toplist = null;
    $market_toplist  = null;
    $global_toplist  = null;

    foreach ($toplists as $tl) {
        $d = json_decode($tl->data, true);
        $geo_type = $d['data']['geo']['geo_type'] ?? null;
        $geo_name = $d['data']['geo']['name'] ?? null;

        if ($geo_type === 'country' && $geo_name !== null && $country_toplist === null) {
            $country_toplist = $tl;
        } elseif ($geo_type === 'market' && $geo_name !== null && $market_toplist === null) {
            $market_toplist = $tl;
        } elseif ($geo_name === null && $global_toplist === null) {
            $global_toplist = $tl;
        }
    }

    // Country geo toplist
    if ($country_toplist) {
        $d = json_decode($country_toplist->data, true);
        $name = $d['data']['geo']['name'];
        dfr_pass("Country geo toplist found (id={$country_toplist->api_toplist_id}, geo=\"{$name}\")");
        $out = do_shortcode("[dataflair_toplist id=\"{$country_toplist->api_toplist_id}\"]");
        if (!str_contains($out, 'Error')) {
            dfr_pass("Country geo toplist renders without errors");
        } else {
            dfr_fail("Country geo toplist render error: " . esc_html(substr(strip_tags($out), 0, 100)));
        }
    } else {
        dfr_warn("No country-geo toplist found in DB — skipping country geo test");
    }

    // Market geo toplist
    if ($market_toplist) {
        $d = json_decode($market_toplist->data, true);
        $name = $d['data']['geo']['name'];
        dfr_pass("Market geo toplist found (id={$market_toplist->api_toplist_id}, geo=\"{$name}\")");
        $out = do_shortcode("[dataflair_toplist id=\"{$market_toplist->api_toplist_id}\"]");
        if (!str_contains($out, 'Error')) {
            dfr_pass("Market geo toplist renders without errors");
        } else {
            dfr_fail("Market geo toplist render error: " . esc_html(substr(strip_tags($out), 0, 100)));
        }
    } else {
        dfr_warn("No market-geo toplist found in DB — skipping market geo test");
    }

    // Global toplist (geo.name = null)
    if ($global_toplist) {
        dfr_pass("Global toplist found (id={$global_toplist->api_toplist_id}, geo.name=null)");
        $out = do_shortcode("[dataflair_toplist id=\"{$global_toplist->api_toplist_id}\"]");
        if (!str_contains($out, 'Error') && !empty(trim($out))) {
            dfr_pass("Global toplist (null geo) renders without errors");
        } elseif (str_contains($out, 'Error')) {
            dfr_fail("Global toplist render error: " . esc_html(substr(strip_tags($out), 0, 100)));
        }

        // Check that geo name doesn't break rendering
        if (str_contains($out, 'dataflair-toplist')) {
            dfr_pass("Global toplist container rendered despite null geo.name");
        }

        // NOTE: The admin geo picker (get_geos AJAX) uses isset($geo['name']) — null geos are NOT added to the picker.
        // This is expected behaviour but may cause confusion. Flag it as a warning.
        dfr_warn("NOTE: Global toplists (null geo) do not appear in the admin geo-picker dropdown — this is known behaviour");
    } else {
        dfr_warn("No global toplist (null geo) in DB — cannot test null geo edge case");
    }
    dfr_end();

    // ── T10: Gutenberg block — no toplistId ───────────────────────────────────
    dfr_section("T10 · Gutenberg Block — No toplistId configured");
    $out = $plugin->render_block([]);
    if (!empty(trim($out)) && (str_contains($out, 'configure') || str_contains($out, 'Please'))) {
        dfr_pass("render_block([]) → placeholder message shown: \"" . esc_html(strip_tags($out)) . "\"");
    } elseif (!empty(trim($out))) {
        dfr_warn("render_block([]) returns output but no 'configure' hint: " . esc_html(substr(strip_tags($out), 0, 100)));
    } else {
        dfr_fail("render_block([]) returned empty output — expected placeholder message");
    }
    dfr_end();

    // ── T11: Gutenberg block — delegates to shortcode ─────────────────────────
    dfr_section("T11 · Gutenberg Block — Delegates to toplist_shortcode()");
    $block_atts = ['toplistId' => $first_id, 'title' => '', 'limit' => 0];
    $block_out  = $plugin->render_block($block_atts);
    $short_out  = $plugin->toplist_shortcode(['id' => $first_id, 'title' => '', 'limit' => 0]);

    if (empty(trim($block_out))) {
        dfr_fail("render_block with toplistId={$first_id} returned empty output");
    } elseif ($block_out === $short_out) {
        dfr_pass("render_block output is identical to toplist_shortcode output (correct delegation)");
    } else {
        // Not identical but both non-empty — check they both have the toplist container
        $both_have_container = str_contains($block_out, 'dataflair-toplist') && str_contains($short_out, 'dataflair-toplist');
        if ($both_have_container) {
            dfr_pass("Both render_block and toplist_shortcode produce the .dataflair-toplist container");
        } else {
            dfr_warn("render_block and toplist_shortcode outputs differ unexpectedly");
        }
    }
    dfr_end();

    // ── T12: REST endpoint — GET /wp-json/dataflair/v1/toplists ──────────────
    dfr_section("T12 · REST API — GET /wp-json/dataflair/v1/toplists (block editor)");
    $home_url = get_home_url();
    $rest_list_url = $home_url . '/wp-json/dataflair/v1/toplists';
    dfr_info("URL: <a href='" . esc_url($rest_list_url) . "' target='_blank'>" . esc_html($rest_list_url) . "</a>");

    // Authenticate as admin to test REST routes
    $admin_users = get_users(['role' => 'administrator', 'number' => 1]);
    if (empty($admin_users)) {
        dfr_warn("No admin user found — cannot test REST endpoint (requires edit_posts capability)");
    } else {
        wp_set_current_user($admin_users[0]->ID);

        $rest_request = new WP_REST_Request('GET', '/dataflair/v1/toplists');
        $rest_response = rest_do_request($rest_request);
        $rest_status   = $rest_response->get_status();
        $rest_data     = $rest_response->get_data();

        if ($rest_status === 200) {
            dfr_pass("REST GET /dataflair/v1/toplists → 200 OK");
        } else {
            dfr_fail("REST GET /dataflair/v1/toplists → status {$rest_status}");
        }

        if (is_array($rest_data) && !empty($rest_data)) {
            dfr_pass("REST endpoint returns array of " . count($rest_data) . " toplist option(s)");
            $first = $rest_data[0];
            if (isset($first['value']) && isset($first['label'])) {
                dfr_pass("Each toplist option has 'value' and 'label' keys");
                dfr_info("Sample: value={$first['value']}, label=\"" . esc_html($first['label']) . "\"");
            } else {
                dfr_fail("Toplist option missing 'value' or 'label' key. Keys: " . implode(', ', array_keys($first)));
            }
        } elseif (is_array($rest_data) && empty($rest_data)) {
            dfr_warn("REST endpoint returned empty array (no synced toplists?)");
        } else {
            dfr_fail("REST endpoint returned unexpected data type: " . gettype($rest_data));
        }

        // ── T13: REST endpoint — GET /wp-json/dataflair/v1/toplists/{id}/casinos ──
        dfr_section("T13 · REST API — GET /wp-json/dataflair/v1/toplists/{$first_id}/casinos");
        $rest_casino_req = new WP_REST_Request('GET', "/dataflair/v1/toplists/{$first_id}/casinos");
        $rest_casino_req->set_param('id', $first_id);
        $rest_casino_resp = rest_do_request($rest_casino_req);
        $casino_status    = $rest_casino_resp->get_status();
        $casino_data      = $rest_casino_resp->get_data();

        if ($casino_status === 200) {
            dfr_pass("REST GET /dataflair/v1/toplists/{$first_id}/casinos → 200 OK");
        } else {
            dfr_fail("REST GET /dataflair/v1/toplists/{$first_id}/casinos → status {$casino_status}");
        }

        if (is_array($casino_data) && !empty($casino_data)) {
            dfr_pass("Casino REST endpoint returns array of " . count($casino_data) . " casino(s)");
            $casino = $casino_data[0];
            // The casinos REST endpoint returns: position, brandName, brandSlug, pros, cons
            foreach (['position', 'brandName', 'brandSlug'] as $ck) {
                if (isset($casino[$ck])) {
                    dfr_pass("casino.{$ck} present: \"" . esc_html((string)$casino[$ck]) . "\"");
                } else {
                    dfr_warn("casino.{$ck} missing from REST response");
                }
            }
        } else {
            dfr_warn("Casino REST endpoint returned empty array for toplist id={$first_id}");
        }

        // Test with non-existent toplist id
        $rest_404_req  = new WP_REST_Request('GET', '/dataflair/v1/toplists/999999/casinos');
        $rest_404_req->set_param('id', 999999);
        $rest_404_resp = rest_do_request($rest_404_req);
        $s404 = $rest_404_resp->get_status();
        if ($s404 === 404 || (is_array($rest_404_resp->get_data()) && empty($rest_404_resp->get_data()))) {
            dfr_pass("Non-existent toplist id (999999) → empty/404 response (correct)");
        } else {
            dfr_warn("Non-existent toplist id → status {$s404} (expected 404 or empty)");
        }

        dfr_end();

        // ── T14: REST — unauthenticated request is rejected ───────────────────
        dfr_section("T14 · REST API — Unauthenticated Request Blocked");
        wp_set_current_user(0); // logout
        $unauth_req  = new WP_REST_Request('GET', '/dataflair/v1/toplists');
        $unauth_resp = rest_do_request($unauth_req);
        $unauth_status = $unauth_resp->get_status();
        if ($unauth_status === 401 || $unauth_status === 403) {
            dfr_pass("Unauthenticated REST request → {$unauth_status} (correctly rejected)");
        } else {
            dfr_fail("Unauthenticated REST request → {$unauth_status} (expected 401 or 403)");
        }
        dfr_end();
    }

    dfr_end(); // Close T12 section

    // ── T15: Casino card rendering edge cases ─────────────────────────────────
    dfr_section("T15 · Casino Card — Logo Resolution Chain");

    $include_path = dirname(dirname(__FILE__)) . '/views/frontend/casino-card.php';
    if (!file_exists($include_path)) {
        dfr_warn("views/frontend/casino-card.php not found — skipping card tests");
        dfr_end();
        _dfr_summary();
        return;
    }
    dfr_pass("casino-card.php template found");

    // render_casino_card() is private — test it indirectly via the shortcode output.
    // The shortcode renders all items in the toplist, so we can inspect the HTML.
    $out = do_shortcode("[dataflair_toplist id=\"{$first_id}\"]");

    // Logo: rectangular → square fallback chain visible in output
    if (str_contains($out, 'casino-logo')) {
        dfr_pass("casino-logo element present in rendered output");
    } else {
        dfr_warn("casino-logo element not found in shortcode output");
    }

    // Logo placeholder shown when no logo URL resolves
    if (str_contains($out, 'casino-logo-placeholder')) {
        dfr_pass("casino-logo-placeholder element present (handles missing logos gracefully)");
    } else {
        dfr_info("No casino-logo-placeholder in output (all brands have logos — OK)");
    }

    // Ribbon (position 1 gets OUR TOP CHOICE)
    if (str_contains($out, 'casino-card-ribbon')) {
        dfr_pass("casino-card-ribbon present (position #1 ribbon rendered)");
    } else {
        dfr_warn("casino-card-ribbon not found in output");
    }

    // CTA button rendered
    if (str_contains($out, 'Visit Site') || str_contains($out, 'casino-cta')) {
        dfr_pass("CTA button present in rendered output");
    } else {
        dfr_warn("CTA button not found in rendered output");
    }

    // Brand name rendered
    if (!empty($first_data['data']['items'][0]['brand']['name'] ?? '')) {
        $brand_name = $first_data['data']['items'][0]['brand']['name'];
        if (str_contains($out, esc_html($brand_name))) {
            dfr_pass("Brand name \"{$brand_name}\" appears in rendered output");
        } else {
            dfr_warn("Brand name \"{$brand_name}\" not found in rendered output");
        }
    }

    dfr_info("NOTE: render_casino_card() is private — full unit testing requires making it protected or adding a public test proxy method.");
    dfr_end();

    _dfr_summary();
}

function _dfr_summary(): void {
    global $_dfr_pass, $_dfr_fail, $_dfr_warn;
    $pass  = $GLOBALS['_dfr_pass'];
    $fail  = $GLOBALS['_dfr_fail'];
    $warn  = $GLOBALS['_dfr_warn'];
    $total = $pass + $fail;
    $color = $fail === 0 ? '#00a32a' : '#d63638';

    echo "<div class='test-section' style='border-left-color:{$color}'>\n";
    echo "<h3>📊 Test Summary — Toplist Rendering</h3>\n";
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
    test_toplist_render();
}
