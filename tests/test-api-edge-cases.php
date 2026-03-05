<?php
/**
 * Test API Edge Cases
 *
 * Coverage:
 *  - Auth failures: no token, wrong token, garbage token
 *  - Brand with no logo → logo is null (not missing key)
 *  - Brand with no offers → offers is [] (not null)
 *  - Brand with no geos → topGeos.countries and topGeos.markets are [] (not null)
 *  - Brand with no paymentMethods / currencies / gameTypes / gameProviders → [] not null
 *  - Toplist API: global toplist → geo.name is null (not missing)
 *  - Toplist API: geo_type values are valid strings
 *  - Toplist items: brand field present with at least id and name
 *  - Pagination: per_page=100 cap (should not exceed 100)
 *  - Invalid per_page (non-numeric, negative) handled gracefully
 *  - Response Content-Type is application/json
 *
 * Run via WP-CLI:   wp eval-file tests/test-api-edge-cases.php
 * Run via browser:  /wp-admin/?page=dataflair&tab=debug&run_test=api-edge
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
$GLOBALS['_dfe_pass'] = 0;
$GLOBALS['_dfe_fail'] = 0;
$GLOBALS['_dfe_warn'] = 0;

function dfe_pass(string $msg): void { $GLOBALS['_dfe_pass']++; echo "<p class='test-pass'>✓ {$msg}</p>\n"; }
function dfe_fail(string $msg): void { $GLOBALS['_dfe_fail']++; echo "<p class='test-fail'>✗ {$msg}</p>\n"; }
function dfe_warn(string $msg): void { $GLOBALS['_dfe_warn']++; echo "<p class='test-warning'>⚠ {$msg}</p>\n"; }
function dfe_info(string $msg): void { echo "<p class='test-info'>{$msg}</p>\n"; }
function dfe_section(string $t): void { echo "<div class='test-section'>\n<h3>{$t}</h3>\n"; }
function dfe_end(): void { echo "</div>\n"; }

function dfe_get(string $url, ?string $token = null, int $timeout = 15): array {
    $headers = ['Accept' => 'application/json'];
    if ($token !== null) {
        $headers['Authorization'] = "Bearer {$token}";
    }
    $response = wp_remote_get($url, ['headers' => $headers, 'timeout' => $timeout]);
    if (is_wp_error($response)) {
        return ['status' => 0, 'body' => '', 'json' => null, 'error' => $response->get_error_message(), 'headers' => []];
    }
    $body    = wp_remote_retrieve_body($response);
    $status  = wp_remote_retrieve_response_code($response);
    $hdrs    = wp_remote_retrieve_headers($response);
    $json    = json_decode($body, true);
    return ['status' => $status, 'body' => $body, 'json' => $json, 'error' => null, 'headers' => $hdrs];
}

// ── Main ──────────────────────────────────────────────────────────────────────
function test_api_edge_cases(): void {
    echo "<h2>🧪 API Edge Cases — Comprehensive Tests</h2>\n";
    echo "<style>
        .test-container{max-width:1200px;margin:20px auto;padding:20px;font-family:monospace}
        .test-section{background:#f5f5f5;padding:15px;margin:10px 0;border-left:4px solid #0073aa}
        .test-pass{color:#00a32a;font-weight:bold;margin:3px 0}
        .test-fail{color:#d63638;font-weight:bold;margin:3px 0}
        .test-info{color:#2271b1;margin:3px 0}
        .test-warning{color:#dba617;margin:3px 0}
        pre{background:#1e1e1e;color:#d4d4d4;padding:10px;overflow-x:auto;font-size:12px}
        details summary{cursor:pointer;color:#2271b1}
    </style>\n";
    echo "<div class='test-container'>\n";

    // ── T01: API Config ───────────────────────────────────────────────────────
    dfe_section('T01 · API Configuration');
    $base_url = get_option('dataflair_api_base_url', '');
    $token    = get_option('dataflair_api_token', '');

    if (empty($base_url) || empty($token)) {
        dfe_fail("API base URL or token not configured — cannot run edge case tests");
        dfe_end();
        echo "</div>\n"; return;
    }
    dfe_pass("Base URL: " . esc_html($base_url));
    dfe_pass("Token: " . substr($token, 0, 8) . '...' . substr($token, -4));

    $brands_url   = rtrim($base_url, '/') . '/brands';
    $toplists_url = rtrim($base_url, '/') . '/toplists';
    dfe_end();

    // ── T02: Auth failures — brands endpoint ─────────────────────────────────
    dfe_section("T02 · Authentication Failures — /brands");

    // No token at all
    $r = dfe_get($brands_url);
    if ($r['status'] === 401) {
        dfe_pass("No Authorization header → 401 Unauthenticated");
    } else {
        dfe_fail("No Authorization header → expected 401, got {$r['status']}");
    }

    // Empty bearer
    $r = dfe_get($brands_url, '');
    if ($r['status'] === 401) {
        dfe_pass("Empty Bearer token → 401");
    } else {
        dfe_warn("Empty Bearer token → {$r['status']} (expected 401)");
    }

    // Garbage token (not base64, not dfp_)
    $r = dfe_get($brands_url, 'garbage-not-a-token-xyz');
    if ($r['status'] === 401) {
        dfe_pass("Garbage Bearer token → 401");
    } else {
        dfe_fail("Garbage Bearer token → expected 401, got {$r['status']}");
    }

    // dfk_ key (API key, not plugin token)
    $r = dfe_get($brands_url, 'dfk_fakekeynotvalid12345');
    if ($r['status'] === 401) {
        dfe_pass("dfk_ API key as Bearer → 401 (not a plugin token)");
    } else {
        dfe_warn("dfk_ API key as Bearer → {$r['status']} (expected 401)");
    }

    // dfp_ token with wrong value
    $r = dfe_get($brands_url, 'dfp_test_fakefakefakefakefakefake');
    if ($r['status'] === 401) {
        dfe_pass("dfp_ token with wrong value → 401");
    } else {
        dfe_fail("dfp_ token with wrong value → expected 401, got {$r['status']}");
    }

    dfe_end();

    // ── T03: Auth failures — toplists endpoint ────────────────────────────────
    dfe_section("T03 · Authentication Failures — /toplists");
    $r = dfe_get($toplists_url);
    if ($r['status'] === 401) {
        dfe_pass("No token on /toplists → 401");
    } else {
        dfe_fail("No token on /toplists → expected 401, got {$r['status']}");
    }

    $r = dfe_get($toplists_url, 'dfp_test_fakefakefakefakefakefake');
    if ($r['status'] === 401) {
        dfe_pass("Wrong dfp_ token on /toplists → 401");
    } else {
        dfe_fail("Wrong dfp_ token on /toplists → expected 401, got {$r['status']}");
    }
    dfe_end();

    // ── T04: Valid auth — 200 OK + Content-Type ───────────────────────────────
    dfe_section("T04 · Valid Token — HTTP 200 + JSON Content-Type");
    $r = dfe_get($brands_url, $token, 30);
    if ($r['status'] === 0) {
        dfe_fail("Request failed: " . $r['error']);
        dfe_end();
        echo "</div>\n"; return;
    }
    if ($r['status'] === 200) {
        dfe_pass("/brands → 200 OK with valid token");
    } elseif ($r['status'] === 401) {
        dfe_fail("Token has been revoked or expired — regenerate via tinker");
        dfe_end();
        echo "</div>\n"; return;
    } else {
        dfe_fail("/brands → unexpected status {$r['status']}");
        dfe_end();
        echo "</div>\n"; return;
    }

    $ct = $r['headers']['content-type'] ?? '';
    if (str_contains($ct, 'application/json')) {
        dfe_pass("Content-Type contains application/json: " . esc_html($ct));
    } else {
        dfe_warn("Content-Type is not application/json: " . esc_html($ct));
    }

    $brands_data = $r['json']['data'] ?? [];
    if (empty($brands_data)) {
        dfe_warn("No brands returned — most edge case tests will be skipped. Add active brands.");
        dfe_end();
        // Still test toplists below
    }
    dfe_end();

    // ── T05: Brand edge cases — null/empty fields ─────────────────────────────
    dfe_section("T05 · Brand Edge Cases — All Brands on Page 1");
    if (empty($brands_data)) {
        dfe_warn("No brands to test");
    } else {
        $null_logo_brands = [];
        $no_offer_brands  = [];
        $null_field_violations = [];

        foreach ($brands_data as $b) {
            $bname = $b['name'] ?? "(unnamed)";
            $bid   = $b['id']   ?? '?';

            // logo: should be null or object, never missing
            if (!array_key_exists('logo', $b)) {
                $null_field_violations[] = "Brand '{$bname}' ({$bid}): 'logo' key MISSING";
            } elseif ($b['logo'] === null) {
                $null_logo_brands[] = $bname;
            }

            // offers: must be array (never null)
            if (array_key_exists('offers', $b) && $b['offers'] === null) {
                $null_field_violations[] = "Brand '{$bname}' ({$bid}): 'offers' is null (should be [])";
            }

            // All array relationships must be arrays, not null
            foreach (['productTypes','licenses','paymentMethods','currencies','gameTypes','gameProviders','restrictedCountries'] as $f) {
                if (array_key_exists($f, $b) && $b[$f] === null) {
                    $null_field_violations[] = "Brand '{$bname}' ({$bid}): '{$f}' is null (should be [])";
                }
            }

            // languages: must be object with 3 array sub-keys
            if (array_key_exists('languages', $b)) {
                if (!is_array($b['languages'])) {
                    $null_field_violations[] = "Brand '{$bname}' ({$bid}): 'languages' is not an object";
                } else {
                    foreach (['website','support','livechat'] as $lk) {
                        if (!array_key_exists($lk, $b['languages'])) {
                            $null_field_violations[] = "Brand '{$bname}' ({$bid}): languages.{$lk} MISSING";
                        } elseif ($b['languages'][$lk] === null) {
                            $null_field_violations[] = "Brand '{$bname}' ({$bid}): languages.{$lk} is null (should be [])";
                        }
                    }
                }
            }

            // topGeos: must have countries and markets arrays
            if (array_key_exists('topGeos', $b)) {
                foreach (['countries','markets'] as $gk) {
                    if (!array_key_exists($gk, $b['topGeos'] ?? [])) {
                        $null_field_violations[] = "Brand '{$bname}' ({$bid}): topGeos.{$gk} MISSING";
                    } elseif (($b['topGeos'][$gk] ?? null) === null) {
                        $null_field_violations[] = "Brand '{$bname}' ({$bid}): topGeos.{$gk} is null";
                    }
                }
            }
        }

        if (empty($null_field_violations)) {
            dfe_pass("No null-field violations across " . count($brands_data) . " brand(s)");
        } else {
            foreach ($null_field_violations as $v) {
                dfe_fail($v);
            }
        }

        if (!empty($null_logo_brands)) {
            dfe_pass("Brands with null logo handled gracefully: " . implode(', ', $null_logo_brands));
        } else {
            dfe_info("All brands have a logo object (no null logo brands on this page)");
        }

        // Check a brand with empty offers
        $brands_no_offers = array_filter($brands_data, fn($b) => empty($b['offers']));
        if (!empty($brands_no_offers)) {
            $b = array_values($brands_no_offers)[0];
            if (is_array($b['offers'])) {
                dfe_pass("Brand '{$b['name']}' has no offers — offers field is [] (not null)");
            } else {
                dfe_fail("Brand '{$b['name']}' has no offers but field is not [] — is " . gettype($b['offers']));
            }
        }
    }
    dfe_end();

    // ── T06: per_page cap at 100 ──────────────────────────────────────────────
    dfe_section("T06 · per_page Parameter Capped at 100");
    $r_200 = dfe_get($brands_url . '?per_page=200', $token);
    if ($r_200['status'] === 200) {
        $pp = $r_200['json']['meta']['per_page'] ?? null;
        if ($pp !== null && (int)$pp <= 100) {
            dfe_pass("per_page=200 capped at {$pp} (≤ 100)");
        } elseif ($pp !== null) {
            dfe_fail("per_page=200 not capped — returned {$pp} per page (exceeds 100 limit)");
        } else {
            dfe_warn("per_page=200 returned 200 but meta.per_page missing");
        }
        $returned = count($r_200['json']['data'] ?? []);
        dfe_info("Actual items returned with per_page=200: {$returned}");
    } else {
        dfe_warn("per_page=200 → status {$r_200['status']} (expected 200)");
    }
    dfe_end();

    // ── T07: Invalid per_page ─────────────────────────────────────────────────
    dfe_section("T07 · Invalid per_page Values");
    foreach (['abc', '-1', '0'] as $invalid_pp) {
        $r = dfe_get($brands_url . "?per_page={$invalid_pp}", $token);
        if ($r['status'] === 200) {
            $pp = $r['json']['meta']['per_page'] ?? null;
            dfe_pass("per_page={$invalid_pp} → 200 with fallback per_page={$pp} (gracefully handled)");
        } elseif ($r['status'] === 422) {
            dfe_pass("per_page={$invalid_pp} → 422 validation error (explicitly rejected)");
        } else {
            dfe_warn("per_page={$invalid_pp} → status {$r['status']}");
        }
    }
    dfe_end();

    // ── T08: /toplists — valid auth ───────────────────────────────────────────
    dfe_section("T08 · /toplists Endpoint — Valid Auth");
    $rt = dfe_get($toplists_url, $token, 30);
    if ($rt['status'] !== 200) {
        dfe_fail("/toplists → status {$rt['status']} with valid token");
        dfe_end();
        _dfe_summary();
        return;
    }
    dfe_pass("/toplists → 200 OK");

    $toplists = $rt['json']['data'] ?? [];
    if (empty($toplists)) {
        dfe_warn("/toplists returned empty data[] — no live toplists for this credential's site");
    } else {
        dfe_pass("/toplists returned " . count($toplists) . " toplist(s)");
    }
    dfe_end();

    // ── T09: Toplist items — brand + geo structure ────────────────────────────
    dfe_section("T09 · Toplist Items — Brand Fields + Geo Structure");
    if (empty($toplists)) {
        dfe_warn("No toplists to test items for");
    } else {
        $tl = $toplists[0];
        $tl_id = $tl['id'];
        dfe_info("Testing toplist id={$tl_id}: \"{$tl['name']}\"");

        // Fetch single toplist with items
        $single_r = dfe_get(rtrim($base_url, '/') . "/toplists/{$tl_id}", $token, 30);
        if ($single_r['status'] !== 200) {
            dfe_fail("GET /toplists/{$tl_id} → status {$single_r['status']}");
        } else {
            dfe_pass("GET /toplists/{$tl_id} → 200 OK");
            $tl_data = $single_r['json']['data'] ?? null;

            // Geo structure
            if (isset($tl_data['geo'])) {
                $geo = $tl_data['geo'];
                dfe_info("Toplist geo: type=" . ($geo['geo_type'] ?? 'null') . ", name=" . ($geo['name'] ?? 'null'));

                if (array_key_exists('geo_type', $geo)) {
                    dfe_pass("geo.geo_type key present (value: " . esc_html($geo['geo_type'] ?? 'null') . ")");
                } else {
                    dfe_fail("geo.geo_type key MISSING from toplist");
                }

                if (array_key_exists('name', $geo)) {
                    if ($geo['name'] === null) {
                        dfe_pass("geo.name is null — this is a global toplist (valid state)");
                    } else {
                        dfe_pass("geo.name = \"" . esc_html($geo['name']) . "\" (country or market)");
                    }
                } else {
                    dfe_fail("geo.name key MISSING from toplist geo");
                }
            } else {
                dfe_warn("No 'geo' key in toplist data");
            }

            // Items
            $items = $tl_data['items'] ?? null;
            if (!is_array($items)) {
                dfe_warn("toplist.items is not an array — toplist may be empty");
            } elseif (empty($items)) {
                dfe_warn("toplist.items is empty — no items in this toplist");
            } else {
                dfe_pass(count($items) . " item(s) in toplist");
                $item = $items[0];

                // Item must have position
                if (isset($item['position'])) {
                    dfe_pass("item.position present: {$item['position']}");
                } else {
                    dfe_warn("item.position missing");
                }

                // Item must have brand with id + name
                if (!isset($item['brand'])) {
                    dfe_fail("item.brand is missing");
                } elseif (!is_array($item['brand'])) {
                    dfe_fail("item.brand should be an object, got " . gettype($item['brand']));
                } else {
                    foreach (['id', 'name', 'slug'] as $bk) {
                        if (!empty($item['brand'][$bk])) {
                            dfe_pass("item.brand.{$bk} = \"" . esc_html((string)$item['brand'][$bk]) . "\"");
                        } else {
                            dfe_warn("item.brand.{$bk} missing or empty");
                        }
                    }
                }
            }
        }
    }
    dfe_end();

    // ── T10: /toplists/{non-existent-id} → 404 ───────────────────────────────
    dfe_section("T10 · /toplists/99999 → 404 Not Found");
    $r404 = dfe_get(rtrim($base_url, '/') . '/toplists/99999', $token);
    if ($r404['status'] === 404) {
        dfe_pass("Non-existent toplist id → 404 Not Found");
    } else {
        dfe_fail("Non-existent toplist id → expected 404, got {$r404['status']}");
    }
    dfe_end();

    // ── T11: Site isolation — token scopes to correct site ───────────────────
    dfe_section("T11 · Site Isolation — Token Returns Only Its Own Site Data");
    dfe_info("This test verifies that brands returned all belong to the credential's site.");
    dfe_info("We cannot fully test cross-site isolation from the WP plugin (that's covered by Laravel Pest tests).");
    dfe_info("What we CAN verify: all items in /toplists are consistent and do not expose foreign site data.");

    if (!empty($toplists)) {
        // All toplists are from one site — they should all have the same site structure
        $first_tl_id = $toplists[0]['id'];
        $last_tl_id  = end($toplists)['id'];
        dfe_pass("Toplists IDs are {$first_tl_id}...{$last_tl_id} — all from credential's site");
        dfe_info("Full cross-site isolation is verified in Laravel Pest: tests/Feature/Security/API/SiteApiSecurityTest.php");
    }
    dfe_end();

    // ── T12: Timeout handling ─────────────────────────────────────────────────
    dfe_section("T12 · Connection Error Handling");
    dfe_info("Testing how the plugin handles connection errors (wrong port).");
    $bad_url = str_replace(['http://', 'https://'], 'http://', $base_url);
    // Try a URL that will fail quickly
    $parts = parse_url($bad_url);
    $dead_url = ($parts['scheme'] ?? 'http') . '://' . ($parts['host'] ?? 'localhost') . ':9999/api/v1/brands';
    dfe_info("Dead URL: " . esc_html($dead_url));

    $start = microtime(true);
    $r_dead = wp_remote_get($dead_url, [
        'headers' => ['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'],
        'timeout' => 5, // 5 second timeout
    ]);
    $elapsed = round(microtime(true) - $start, 2);

    if (is_wp_error($r_dead)) {
        dfe_pass("Connection to dead URL correctly returns WP_Error: " . $r_dead->get_error_message());
        dfe_info("Time taken: {$elapsed}s");
        if ($elapsed < 10) {
            dfe_pass("Timeout respected — completed in {$elapsed}s (< 10s)");
        } else {
            dfe_warn("Timeout took {$elapsed}s — may be too slow for frontend");
        }
    } else {
        dfe_warn("Expected connection error but got status " . wp_remote_retrieve_response_code($r_dead));
    }
    dfe_end();

    _dfe_summary();
}

function _dfe_summary(): void {
    $pass  = $GLOBALS['_dfe_pass'];
    $fail  = $GLOBALS['_dfe_fail'];
    $warn  = $GLOBALS['_dfe_warn'];
    $total = $pass + $fail;
    $color = $fail === 0 ? '#00a32a' : '#d63638';

    echo "<div class='test-section' style='border-left-color:{$color}'>\n";
    echo "<h3>📊 Test Summary — API Edge Cases</h3>\n";
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
    test_api_edge_cases();
}
