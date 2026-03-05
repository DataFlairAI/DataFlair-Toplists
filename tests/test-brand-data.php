<?php
/**
 * Test Brand Data — Comprehensive
 *
 * Validates ALL fields returned by GET /api/v1/brands:
 *  - Core fields: id, name, slug, rating, brandStatus, logo
 *  - Relationships: productTypes, licenses, paymentMethods, currencies,
 *    gameTypes, gameProviders, languages, topGeos, restrictedCountries
 *  - Offer + trackers
 *  - False positives: inactive brands MUST NOT appear
 *  - Pagination meta: per_page, total, last_page present
 *  - Empty arrays (not null) when a brand has no relationships
 *  - Alphabetical ordering (default sort)
 *
 * Run via WP-CLI:   wp eval-file tests/test-brand-data.php
 * Run via browser:  /wp-admin/?page=dataflair&tab=debug&run_test=brand-data
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
        if (file_exists($path)) {
            require_once $path;
            $wp_loaded = true;
            break;
        }
    }
    if (!$wp_loaded) {
        die('Error: Could not find wp-load.php.');
    }
}

// ── Helpers ──────────────────────────────────────────────────────────────────

$GLOBALS['_df_pass']  = 0;
$GLOBALS['_df_fail']  = 0;
$GLOBALS['_df_warn']  = 0;

function df_pass(string $msg): void {
    $GLOBALS['_df_pass']++;
    echo "<p class='test-pass'>✓ {$msg}</p>\n";
}

function df_fail(string $msg): void {
    $GLOBALS['_df_fail']++;
    echo "<p class='test-fail'>✗ {$msg}</p>\n";
}

function df_warn(string $msg): void {
    $GLOBALS['_df_warn']++;
    echo "<p class='test-warning'>⚠ {$msg}</p>\n";
}

function df_info(string $msg): void {
    echo "<p class='test-info'>{$msg}</p>\n";
}

function df_section(string $title): void {
    echo "<div class='test-section'>\n<h3>{$title}</h3>\n";
}

function df_end_section(): void {
    echo "</div>\n";
}

// Assert helpers
function df_assert_key_exists(array $data, string $key, string $label, bool $required = true): bool {
    if (array_key_exists($key, $data)) {
        df_pass("{$label}: key '{$key}' present");
        return true;
    }
    $required ? df_fail("{$label}: key '{$key}' MISSING") : df_warn("{$label}: key '{$key}' absent (optional)");
    return false;
}

function df_assert_is_array(array $data, string $key, string $label): bool {
    if (array_key_exists($key, $data) && is_array($data[$key])) {
        df_pass("{$label}: '{$key}' is array (" . count($data[$key]) . " items)");
        return true;
    }
    df_fail("{$label}: '{$key}' should be array, got " . gettype($data[$key] ?? null));
    return false;
}

function df_assert_is_string_non_empty(array $data, string $key, string $label): bool {
    if (!empty($data[$key]) && is_string($data[$key])) {
        $short = strlen($data[$key]) > 40 ? substr($data[$key], 0, 40) . '...' : $data[$key];
        df_pass("{$label}: '{$key}' = \"{$short}\"");
        return true;
    }
    df_fail("{$label}: '{$key}' should be non-empty string, got " . var_export($data[$key] ?? null, true));
    return false;
}

// ── Main test function ────────────────────────────────────────────────────────

function test_brand_data_comprehensive(): void {
    echo "<h2>🧪 Brand Data — Comprehensive Tests</h2>\n";
    echo "<style>
        .test-container { max-width: 1200px; margin: 20px auto; padding: 20px; font-family: monospace; }
        .test-section { background: #f5f5f5; padding: 15px; margin: 10px 0; border-left: 4px solid #0073aa; }
        .test-pass { color: #00a32a; font-weight: bold; margin: 3px 0; }
        .test-fail { color: #d63638; font-weight: bold; margin: 3px 0; }
        .test-info { color: #2271b1; margin: 3px 0; }
        .test-warning { color: #dba617; margin: 3px 0; }
        pre { background: #1e1e1e; color: #d4d4d4; padding: 10px; overflow-x: auto; font-size: 12px; }
        table { width: 100%; border-collapse: collapse; margin: 10px 0; font-size: 13px; }
        table th, table td { padding: 6px 10px; text-align: left; border: 1px solid #ddd; }
        table th { background: #0073aa; color: white; }
        .badge-pass { background: #d4edda; color: #155724; padding: 2px 6px; border-radius: 3px; }
        .badge-fail { background: #f8d7da; color: #721c24; padding: 2px 6px; border-radius: 3px; }
        .badge-warn { background: #fff3cd; color: #856404; padding: 2px 6px; border-radius: 3px; }
        details summary { cursor: pointer; color: #2271b1; }
    </style>\n";
    echo "<div class='test-container'>\n";

    // ── T01: API Configuration ────────────────────────────────────────────────
    df_section('T01 · API Configuration');
    $api_base_url = get_option('dataflair_api_base_url', '');
    $api_token    = get_option('dataflair_api_token', '');

    df_info("Base URL: <strong>" . esc_html($api_base_url ?: '(not set)') . "</strong>");

    if (empty($api_base_url)) {
        df_fail('dataflair_api_base_url is not set — cannot continue');
        df_end_section();
        echo "</div>\n";
        return;
    }
    df_pass('dataflair_api_base_url is configured');

    if (empty($api_token)) {
        df_fail('dataflair_api_token is not set — cannot continue');
        df_end_section();
        echo "</div>\n";
        return;
    }
    $prefix = substr($api_token, 0, 4);
    if ($prefix !== 'dfp_') {
        df_warn("Token does not start with 'dfp_' (starts with '{$prefix}') — are you using a Plugin Token?");
    } else {
        df_pass("Token starts with 'dfp_' (correct Plugin Token prefix)");
    }
    df_pass('dataflair_api_token is configured (length: ' . strlen($api_token) . ')');
    df_end_section();

    // ── T02: HTTP call to /brands (page 1) ───────────────────────────────────
    df_section('T02 · HTTP Request — GET /brands');
    $brands_url = rtrim($api_base_url, '/') . '/brands';
    df_info("URL: <a href='" . esc_url($brands_url) . "' target='_blank'>" . esc_html($brands_url) . "</a>");

    $response = wp_remote_get($brands_url, [
        'headers' => ['Authorization' => "Bearer {$api_token}", 'Accept' => 'application/json'],
        'timeout' => 30,
    ]);

    if (is_wp_error($response)) {
        df_fail('Request failed: ' . $response->get_error_message());
        df_end_section();
        echo "</div>\n";
        return;
    }

    $status = wp_remote_retrieve_response_code($response);
    $body   = wp_remote_retrieve_body($response);

    if ($status === 401) {
        df_fail("401 Unauthenticated — token is invalid, expired, or revoked. Regenerate via tinker.");
        df_end_section();
        echo "</div>\n";
        return;
    }
    if ($status === 403) {
        df_fail("403 Forbidden — IP not in whitelist or credential is restricted.");
        df_end_section();
        echo "</div>\n";
        return;
    }
    if ($status !== 200) {
        df_fail("Unexpected status {$status}. Response: " . esc_html(substr($body, 0, 300)));
        df_end_section();
        echo "</div>\n";
        return;
    }
    df_pass("HTTP 200 OK");

    $api = json_decode($body, true);
    if (!is_array($api)) {
        df_fail("Response body is not valid JSON");
        df_end_section();
        echo "</div>\n";
        return;
    }
    df_pass('Response is valid JSON');
    df_end_section();

    // ── T03: Pagination meta ──────────────────────────────────────────────────
    df_section('T03 · Pagination Meta');
    df_assert_key_exists($api, 'data', 'Pagination', true);
    df_assert_key_exists($api, 'meta', 'Pagination', true);
    df_assert_key_exists($api, 'links', 'Pagination', true);

    if (isset($api['meta'])) {
        $meta = $api['meta'];
        foreach (['current_page', 'per_page', 'total', 'last_page'] as $mkey) {
            df_assert_key_exists($meta, $mkey, "meta.{$mkey}");
        }
        if (isset($meta['per_page'])) {
            if ($meta['per_page'] === 15) {
                df_pass("meta.per_page = 15 (default)");
            } else {
                df_warn("meta.per_page = {$meta['per_page']} (expected 15 default)");
            }
        }
        if (isset($meta['total'])) {
            df_info("meta.total = {$meta['total']} brands in tenant");
        }
        if (isset($meta['last_page'])) {
            df_info("meta.last_page = {$meta['last_page']}");
        }
    }

    if (empty($api['data']) || !is_array($api['data'])) {
        df_warn('No brands in data[] — cannot test brand fields. Ensure active brands exist.');
        df_end_section();
        echo "</div>\n";
        return;
    }
    df_info('Brands returned on page 1: ' . count($api['data']));
    df_end_section();

    // ── T04: False-positive — only ACTIVE brands returned ────────────────────
    df_section('T04 · False-Positive Check — Only Active Brands Returned');
    $all_active = true;
    $statuses   = [];
    foreach ($api['data'] as $b) {
        $st = $b['brandStatus'] ?? '(missing)';
        $statuses[$st] = ($statuses[$st] ?? 0) + 1;
        if ($st !== 'Active') {
            $all_active = false;
            df_fail("Brand '{$b['name']}' (id={$b['id']}) has brandStatus='{$st}' — should not appear (inactive filter broken)");
        }
    }
    if ($all_active) {
        df_pass('All returned brands have brandStatus = "Active" (inactive brands correctly excluded)');
    }

    echo "<details><summary>Brand status distribution</summary><pre>";
    foreach ($statuses as $st => $cnt) {
        echo esc_html("  {$st}: {$cnt}\n");
    }
    echo "</pre></details>\n";
    df_end_section();

    // ── T05: Core brand fields ────────────────────────────────────────────────
    df_section('T05 · Core Brand Fields (first brand)');
    $brand = $api['data'][0];
    df_info("Testing brand: <strong>" . esc_html($brand['name'] ?? '?') . "</strong> (id={$brand['id']})");

    df_assert_key_exists($brand, 'id',   'Core field');
    df_assert_key_exists($brand, 'name', 'Core field');
    df_assert_key_exists($brand, 'slug', 'Core field');
    df_assert_key_exists($brand, 'brandStatus', 'Core field');
    df_assert_key_exists($brand, 'rating', 'Core field', false);
    df_assert_key_exists($brand, 'type',  'Core field', false);

    // brandStatus must be a string
    if (isset($brand['brandStatus'])) {
        if (is_string($brand['brandStatus'])) {
            df_pass("brandStatus is a string: \"{$brand['brandStatus']}\" (not an object)");
        } else {
            df_fail('brandStatus should be a string, got ' . gettype($brand['brandStatus']));
        }
    }
    df_end_section();

    // ── T06: Logo object ──────────────────────────────────────────────────────
    df_section('T06 · Logo Object');
    if (!array_key_exists('logo', $brand)) {
        df_fail("'logo' key missing from brand");
    } elseif ($brand['logo'] === null) {
        df_pass("logo is null (brand has no logo — valid state)");
    } elseif (is_array($brand['logo'])) {
        df_pass("logo is an object (array)");
        foreach (['rectangular', 'square', 'backgroundColor'] as $logoKey) {
            df_assert_key_exists($brand['logo'], $logoKey, "logo.{$logoKey}", false);
        }
        if (!empty($brand['logo']['rectangular'])) {
            df_pass("logo.rectangular URL present: " . esc_html(substr($brand['logo']['rectangular'], 0, 60)));
        } else {
            df_warn("logo.rectangular is empty (brand may have no rectangular logo)");
        }
    } else {
        df_fail("logo should be null or an object, got " . gettype($brand['logo']));
    }
    df_end_section();

    // ── T07: Array relationship fields ────────────────────────────────────────
    df_section('T07 · Array Relationship Fields');
    $array_fields = [
        'productTypes'       => 'Product Types',
        'licenses'           => 'Licenses',
        'paymentMethods'     => 'Payment Methods',
        'currencies'         => 'Currencies',
        'gameTypes'          => 'Game Types',
        'gameProviders'      => 'Game Providers',
        'restrictedCountries'=> 'Restricted Countries',
    ];

    echo "<table>\n<tr><th>Field</th><th>Present?</th><th>Type</th><th>Count</th><th>Sample</th></tr>\n";
    foreach ($array_fields as $key => $label) {
        $present = array_key_exists($key, $brand);
        $value   = $brand[$key] ?? '(missing)';
        $isArr   = is_array($value);
        $cnt     = $isArr ? count($value) : '—';
        $sample  = $isArr && !empty($value) ? esc_html(implode(', ', array_slice($value, 0, 3))) : '—';
        $badge   = $present ? ($isArr ? 'badge-pass' : 'badge-fail') : 'badge-fail';
        $label_out = $present ? ($isArr ? '✓ yes' : '✗ not array') : '✗ missing';
        echo "<tr>
            <td><strong>{$key}</strong></td>
            <td><span class='{$badge}'>{$label_out}</span></td>
            <td>" . esc_html(gettype($value)) . "</td>
            <td>{$cnt}</td>
            <td>{$sample}</td>
        </tr>\n";

        if (!$present) {
            $GLOBALS['_df_fail']++;
        } elseif (!$isArr) {
            $GLOBALS['_df_fail']++;
        } else {
            $GLOBALS['_df_pass']++;
            // Extra: empty array is valid (not null)
            if (empty($value)) {
                df_pass("{$key}: empty array [] — correct (not null when no relationships)");
            }
        }
    }
    echo "</table>\n";
    df_end_section();

    // ── T08: Languages object ─────────────────────────────────────────────────
    df_section('T08 · Languages Object (website / support / livechat)');
    if (!array_key_exists('languages', $brand)) {
        df_fail("'languages' key missing from brand");
    } elseif (!is_array($brand['languages'])) {
        df_fail("'languages' should be an object, got " . gettype($brand['languages']));
    } else {
        df_pass("'languages' is an object");
        foreach (['website', 'support', 'livechat'] as $lk) {
            if (!array_key_exists($lk, $brand['languages'])) {
                df_fail("languages.{$lk} key missing");
            } elseif (!is_array($brand['languages'][$lk])) {
                df_fail("languages.{$lk} should be array, got " . gettype($brand['languages'][$lk]));
            } else {
                $cnt = count($brand['languages'][$lk]);
                df_pass("languages.{$lk}: array ({$cnt} items)" . ($cnt > 0 ? ' — e.g. "' . esc_html($brand['languages'][$lk][0]) . '"' : ' — empty (valid)'));
            }
        }
    }
    df_end_section();

    // ── T09: topGeos object ───────────────────────────────────────────────────
    df_section('T09 · topGeos Object (countries / markets)');
    if (!array_key_exists('topGeos', $brand)) {
        df_fail("'topGeos' key missing");
    } elseif (!is_array($brand['topGeos'])) {
        df_fail("'topGeos' should be an object, got " . gettype($brand['topGeos']));
    } else {
        df_pass("'topGeos' is an object");
        foreach (['countries', 'markets'] as $gk) {
            if (!array_key_exists($gk, $brand['topGeos'])) {
                df_fail("topGeos.{$gk} key missing");
            } elseif (!is_array($brand['topGeos'][$gk])) {
                df_fail("topGeos.{$gk} should be array");
            } else {
                $cnt = count($brand['topGeos'][$gk]);
                df_pass("topGeos.{$gk}: array ({$cnt} items)");
            }
        }
    }
    df_end_section();

    // ── T10: Offers + trackers ────────────────────────────────────────────────
    df_section('T10 · Offers Array + Trackers');
    if (!array_key_exists('offers', $brand)) {
        df_fail("'offers' key missing");
    } elseif (!is_array($brand['offers'])) {
        df_fail("'offers' should be array");
    } else {
        $offerCount = count($brand['offers']);
        df_pass("'offers' is array ({$offerCount} offer(s))");

        if ($offerCount === 0) {
            df_warn("No offers on this brand — cannot test offer fields. Try a brand with active offers.");
        } else {
            $offer = $brand['offers'][0];
            df_info("First offer keys: " . implode(', ', array_keys($offer)));

            foreach (['id', 'offerText'] as $ok) {
                df_assert_key_exists($offer, $ok, "offer.{$ok}", false);
            }

            // Trackers
            if (!array_key_exists('trackers', $offer)) {
                df_fail("offer.trackers key missing");
            } elseif (!is_array($offer['trackers'])) {
                df_fail("offer.trackers should be array");
            } else {
                $tCount = count($offer['trackers']);
                df_pass("offer.trackers: array ({$tCount} tracker(s))");
                if ($tCount > 0) {
                    $t = $offer['trackers'][0];
                    df_info("First tracker keys: " . implode(', ', array_keys($t)));
                    foreach (['id', 'trackerLink'] as $tk) {
                        df_assert_key_exists($t, $tk, "tracker.{$tk}", false);
                    }
                    if (!empty($t['trackerLink'])) {
                        df_pass("tracker.trackerLink present: " . esc_html(substr($t['trackerLink'], 0, 60)));
                    }
                }
            }
        }
    }
    df_end_section();

    // ── T11: Empty arrays, not null ───────────────────────────────────────────
    df_section('T11 · All brands — Empty Arrays Are [] Not null');
    $null_violations = [];
    foreach ($api['data'] as $b) {
        foreach (['productTypes','licenses','paymentMethods','currencies','gameTypes','gameProviders','restrictedCountries','offers'] as $field) {
            if (array_key_exists($field, $b) && $b[$field] === null) {
                $null_violations[] = "Brand '{$b['name']}' (id={$b['id']}): '{$field}' is null (should be [])";
            }
        }
    }
    if (empty($null_violations)) {
        df_pass('All array fields return [] (not null) for brands without that relationship');
    } else {
        foreach ($null_violations as $v) {
            df_fail($v);
        }
    }
    df_end_section();

    // ── T12: Alphabetical order ───────────────────────────────────────────────
    df_section('T12 · Alphabetical Ordering (default sort)');
    if (count($api['data']) >= 2) {
        $names = array_column($api['data'], 'name');
        $sorted = $names;
        sort($sorted, SORT_STRING | SORT_FLAG_CASE);
        if ($names === $sorted) {
            df_pass('Brands are returned in alphabetical order');
        } else {
            df_warn('Brands are NOT alphabetically ordered. First few: ' . implode(', ', array_slice($names, 0, 5)));
            df_info('Sorted expected: ' . implode(', ', array_slice($sorted, 0, 5)));
        }
    } else {
        df_warn('Only ' . count($api['data']) . ' brand(s) returned — cannot test ordering');
    }
    df_end_section();

    // ── T13: Pagination — page 2 ──────────────────────────────────────────────
    df_section('T13 · Pagination — Fetch Page 2');
    $last_page = $api['meta']['last_page'] ?? 1;
    if ($last_page < 2) {
        df_warn("Only 1 page of brands ({$api['meta']['total']} total) — cannot test page 2 navigation");
    } else {
        $page2_url  = $brands_url . '?page=2';
        $r2 = wp_remote_get($page2_url, [
            'headers' => ['Authorization' => "Bearer {$api_token}", 'Accept' => 'application/json'],
            'timeout' => 15,
        ]);
        $s2 = is_wp_error($r2) ? 0 : wp_remote_retrieve_response_code($r2);
        if ($s2 === 200) {
            $d2 = json_decode(wp_remote_retrieve_body($r2), true);
            if (!empty($d2['data'])) {
                df_pass("Page 2 returns HTTP 200 with " . count($d2['data']) . " brands");
                if (isset($d2['meta']['current_page']) && $d2['meta']['current_page'] === 2) {
                    df_pass("meta.current_page = 2 on page 2 response");
                } else {
                    df_fail("meta.current_page should be 2, got " . ($d2['meta']['current_page'] ?? 'missing'));
                }
            } else {
                df_fail("Page 2 returned 200 but data[] is empty");
            }
        } else {
            df_fail("Page 2 returned status {$s2} (expected 200)");
        }
    }
    df_end_section();

    // ── T14: per_page parameter ───────────────────────────────────────────────
    df_section('T14 · per_page Parameter (request 5 brands)');
    $pp_url = $brands_url . '?per_page=5';
    $rpp = wp_remote_get($pp_url, [
        'headers' => ['Authorization' => "Bearer {$api_token}", 'Accept' => 'application/json'],
        'timeout' => 15,
    ]);
    $spp = is_wp_error($rpp) ? 0 : wp_remote_retrieve_response_code($rpp);
    if ($spp === 200) {
        $dpp = json_decode(wp_remote_retrieve_body($rpp), true);
        $returned = count($dpp['data'] ?? []);
        $expected = min(5, $api['meta']['total'] ?? 5);
        if ($returned <= 5) {
            df_pass("per_page=5 returned {$returned} brand(s) — within limit");
        } else {
            df_fail("per_page=5 returned {$returned} brands — exceeds requested limit");
        }
        if (isset($dpp['meta']['per_page']) && (int)$dpp['meta']['per_page'] === 5) {
            df_pass("meta.per_page reflects the requested value (5)");
        } else {
            df_warn("meta.per_page = " . ($dpp['meta']['per_page'] ?? 'missing') . " (expected 5)");
        }
    } else {
        df_warn("per_page parameter test skipped — status {$spp}");
    }
    df_end_section();

    // ── T15: Full brand dump (collapsible) ────────────────────────────────────
    df_section('T15 · Raw API Response (first brand — for inspection)');
    echo "<details><summary>View full brand JSON (click to expand)</summary>\n";
    echo "<pre>" . esc_html(json_encode($brand, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . "</pre>\n";
    echo "</details>\n";
    df_end_section();

    // ── Summary ───────────────────────────────────────────────────────────────
    $pass = $GLOBALS['_df_pass'];
    $fail = $GLOBALS['_df_fail'];
    $warn = $GLOBALS['_df_warn'];
    $total = $pass + $fail;

    echo "<div class='test-section' style='border-left-color: " . ($fail === 0 ? '#00a32a' : '#d63638') . ";'>\n";
    echo "<h3>📊 Test Summary — Brand Data</h3>\n";
    echo "<p class='test-pass'>✓ {$pass} passed</p>\n";
    if ($fail > 0)  echo "<p class='test-fail'>✗ {$fail} failed</p>\n";
    if ($warn > 0)  echo "<p class='test-warning'>⚠ {$warn} warnings</p>\n";
    echo "<p class='test-info'>Total assertions: {$total}</p>\n";
    if ($fail === 0) {
        echo "<p class='test-pass' style='font-size:16px;'>🎉 All tests passed!</p>\n";
    } else {
        echo "<p class='test-fail' style='font-size:16px;'>❌ {$fail} test(s) failed — review above</p>\n";
    }
    echo "</div>\n";

    echo "</div>\n";
}

// ── Entry points ──────────────────────────────────────────────────────────────
if (php_sapi_name() === 'cli' || isset($_GET['run_test']) || isset($_GET['test'])) {
    test_brand_data_comprehensive();
}
