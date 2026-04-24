<?php
/**
 * Plugin Name: DataFlair Toplists
 * Plugin URI: https://dataflair.ai
 * Description: Fetch and display casino toplists from DataFlair API
 * Version: 1.13.0
 * Requires at least: 6.3
 * Requires PHP: 8.1
 * Author: DataFlair
 * Author URI: https://dataflair.ai
 * License: GPL v2 or later
 * Text Domain: dataflair-toplists
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants (guarded so tests can pre-define them in their bootstrap)
if (!defined('DATAFLAIR_VERSION'))                          define('DATAFLAIR_VERSION', '1.13.0');
if (!defined('DATAFLAIR_PLUGIN_DIR'))                       define('DATAFLAIR_PLUGIN_DIR', plugin_dir_path(__FILE__));
if (!defined('DATAFLAIR_PLUGIN_URL'))                       define('DATAFLAIR_PLUGIN_URL', plugin_dir_url(__FILE__));
if (!defined('DATAFLAIR_TABLE_NAME'))                       define('DATAFLAIR_TABLE_NAME', 'dataflair_toplists');
if (!defined('DATAFLAIR_BRANDS_TABLE_NAME'))                define('DATAFLAIR_BRANDS_TABLE_NAME', 'dataflair_brands');
if (!defined('DATAFLAIR_ALTERNATIVE_TOPLISTS_TABLE_NAME'))  define('DATAFLAIR_ALTERNATIVE_TOPLISTS_TABLE_NAME', 'dataflair_alternative_toplists');

// Load Composer autoloader
if (file_exists(DATAFLAIR_PLUGIN_DIR . 'vendor/autoload.php')) {
    require_once DATAFLAIR_PLUGIN_DIR . 'vendor/autoload.php';
}

// Auto-updates from GitHub releases (public repo — no token needed)
if (class_exists('YahnisElsts\PluginUpdateChecker\v5\PucFactory')) {
    $dataflair_update_checker = YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
        'https://github.com/DataFlairAI/DataFlair-Toplists/',
        __FILE__,
        'dataflair-toplists'
    );
    $dataflair_update_checker->getVcsApi()->enableReleaseAssets();
}

// Plugin info for the "View details" popup on wp-admin/plugins.php
add_filter('plugins_api', 'dataflair_plugins_api_info', 20, 3);
// @codeCoverageIgnoreStart
function dataflair_plugins_api_info($res, $action, $args) {
    if ($action !== 'plugin_information') {
        return $res;
    }
    if (!isset($args->slug) || $args->slug !== 'dataflair-toplists') {
        return $res;
    }

    $res = new stdClass();
    $res->name    = 'DataFlair Toplists';
    $res->slug    = 'dataflair-toplists';
    $res->version = DATAFLAIR_VERSION;
    $res->author  = '<a href="https://dataflair.ai">DataFlair</a>';
    $res->homepage = 'https://dataflair.ai';
    $res->requires = '6.3';
    $res->tested   = '6.9';
    $res->requires_php = '8.1';

    $res->sections = [
        'description' => '
<p>DataFlair Toplists is a WordPress plugin that connects your site to the <strong>DataFlair</strong> affiliate management platform. DataFlair is a white-label iGaming data service built for casino and sportsbook affiliate publishers, it lets you manage your entire brand catalogue, bonus offers, promo codes, affiliate tracking links, geo rules, and rating data in one central dashboard, then distribute that data to all your WordPress affiliate sites simultaneously.</p>
<p>This plugin is the WordPress-side receiver. It syncs your toplists and brands from the DataFlair API, stores them locally in custom database tables, and renders fully styled casino and sportsbook comparison cards on any page or post, with no live API calls on the front end.</p>

<h4>How It Works</h4>
<p>Your DataFlair account holds your brand catalogue and toplist configurations (e.g. "Top 10 Casinos in India", "Best Sportsbooks in Italy"). The plugin syncs this data on a configurable schedule and caches it locally. You place the DataFlair Gutenberg block or <code>[dataflair_toplist id="123"]</code> shortcode on any page, and the plugin renders the full toplist from cached data, fast and reliable.</p>

<h4>Toplist Sync</h4>
<ul>
  <li>Syncs from DataFlair API v1 and v2</li>
  <li>Full sync and incremental sync modes with conflict detection and preview before overwriting</li>
  <li>Supports multiple geo-editions, rotation schedules, and locked item positions</li>
  <li>Stores complete offer, tracker, and geo data as JSON for flexible querying</li>
  <li>Paginated API fetch handles large brand catalogues automatically</li>
</ul>

<h4>Brand Management</h4>
<ul>
  <li>Syncs your full brand catalogue into a local database table</li>
  <li>Stores name, slug, logo, star rating, licenses, payment methods, classification types, and restricted countries</li>
  <li>Admin screen at DataFlair, Brands shows all synced brands with their affiliate data</li>
  <li><strong>Review URL Override:</strong> per-brand custom review URL that wins over all automatic URL generation across the entire site. Field locks after saving and unlocks on Edit.</li>
</ul>

<h4>Casino Card Rendering</h4>
<ul>
  <li>Renders fully styled casino cards showing: brand logo, name, star rating, bonus offer text, promo code copy button, feature list, affiliate CTA, and Read Review link (shown only when a published review or manual review URL exists)</li>
  <li>Promo codes render as a pill-shaped copy-to-clipboard button, matching the design of standalone review pages</li>
  <li>Review pros defaults are read from published review CPT meta with safe fallback logic for duplicate slugs and brand-id matches</li>
  <li>Review post resolution matches published reviews by slug or by _review_brand_id when the live review slug differs from the API slug (for example draft at base slug vs published …-india)</li>
  <li>Review URL resolution priority: manual override, published review post permalink, auto-generated /reviews/{slug}/, affiliate CTA link</li>
  <li>Normalizes casino key slug generation to match Gutenberg editor brandSlug behavior for brands with special characters</li>
  <li>Supports multiple product types (casino, sportsbook, poker) with type-aware labels</li>
</ul>

<h4>Gutenberg Block and Shortcode</h4>
<ul>
  <li>Native WordPress block with inspector controls for toplist selection, item limit, and display options</li>
  <li>Includes a testing-focused accordion table layout option to inspect synced data without wide horizontal-scroll tables</li>
  <li>Server-side rendered, always reflects live synced data</li>
  <li>Pros and cons overrides in the block editor use stable brand and item IDs when available, so custom copy survives reordered toplists and refreshed sync payloads</li>
  <li>Shortcode: <code>[dataflair_toplist id="123" limit="10"]</code> works anywhere</li>
</ul>

<h4>Automatic Updates</h4>
<ul>
  <li>Self-updating via GitHub releases, no WordPress.org required</li>
  <li>WordPress shows native update notifications when a new release is published on GitHub</li>
  <li>Powered by plugin-update-checker v5.6, release-based (not branch-based)</li>
</ul>

<h4>Admin Interface</h4>
<ul>
  <li>DataFlair, Toplists: view all synced toplists, trigger manual sync, preview before committing</li>
  <li>DataFlair, Brands: full brand table with review URL override editing</li>
  <li>DataFlair, Settings: configure API endpoint, API key, sync frequency, and feature flags</li>
  <li>REST API endpoints for the block editor</li>
</ul>
        ',

        'changelog' => '
<h4>1.13.0</h4>
<ul>
  <li><strong>Phase 4 — rendering + ViewModels extracted.</strong> The casino-card and toplist-table renderers are now owned by dedicated classes. The casino-card template has moved from <code>includes/render-casino-card.php</code> to <code>views/frontend/casino-card.php</code>; the old path stays as a forwarding shim for one release (deleted in Phase 5). No public contract change: <code>render_casino_card()</code> and <code>render_toplist_table()</code> on the god-class retain their signatures and continue to return byte-identical HTML.</li>
  <li>Added: <code>DataFlair\\Toplists\\Frontend\\Render\\CardRenderer</code> implementing <code>CardRendererInterface</code>. Wraps the casino-card template-include path through an immutable <code>CasinoCardVM</code> ViewModel (readonly <code>item</code>, <code>toplistId</code>, <code>customizations</code>, <code>prosConsData</code>, <code>brandMetaMap</code>). Preserves every Phase 0A / 0B / Phase 1 invariant — read-only (no <code>wp_remote_*</code>, no <code>wp_insert_post</code>, no <code>wp_handle_sideload</code>, no <code>update_option</code>, no <code>update_post_meta</code>), precomputed <code>local_logo_url</code> consumed verbatim, <code>cached_review_post_id</code> preferred over <code>WP_Query</code>, prefetched <code>brand_meta_map</code> wins over per-card repository calls (H7 contract), and the <code>dataflair_review_url</code> filter fires on the resolved URL. The god-class delegator drops the legacy pre-Phase-0A fallback (it was unreachable in practice and violated the read-only contract).</li>
  <li>Added: <code>DataFlair\\Toplists\\Frontend\\Render\\TableRenderer</code> implementing <code>TableRendererInterface</code>. Wraps the block-editor debug <code>layout=table</code> accordion output through the immutable <code>ToplistTableVM</code> ViewModel (readonly <code>items</code>, <code>title</code>, <code>isStale</code>, <code>lastSynced</code>, <code>prosConsData</code>). HTML output is byte-identical to the god-class method.</li>
  <li>Added: <code>DataFlair\\Toplists\\Frontend\\Render\\ProsConsResolver</code> trait — shared helper that both renderers consume. Keeps <code>resolve_pros_cons_for_table_item()</code> public so the template\'s <code>$this-></code> call surface is unchanged when <code>$this</code> rebinds to the renderer instance instead of the god-class.</li>
  <li>Added: lazy filter-based DI for the renderers — <code>dataflair_card_renderer</code>, <code>dataflair_table_renderer</code>. Any filter return that does not implement the documented interface is rejected and the default kept.</li>
  <li>Added: <code>BrandsRepository::findByName(string $name)</code> — backs the legacy per-card name-based review-URL fallback cascade used by <code>CardRenderer</code> when the caller passes a <code>null</code> <code>brand_meta_map</code>.</li>
  <li>Added: PSR-4 autoload entry for <code>DataFlair\\Toplists\\Frontend\\</code> → <code>src/Frontend/</code>. Existing entries retained.</li>
  <li>Changed: the god-class <code>render_casino_card()</code> shrinks from 638 lines to a 15-line delegator, and <code>render_toplist_table()</code> from 136 lines to an 11-line delegator. Plugin file drops from 6,411 to 5,663 lines. Template path references still work through the forwarding shim at <code>includes/render-casino-card.php</code>.</li>
  <li>Added: 17 new tests — <code>CasinoCardVMTest</code> + <code>ToplistTableVMTest</code> (readonly enforcement, default values, full-field construction), <code>CardRendererTest</code> (Brain Monkey integration — map-path does not query the repo, null-map falls back to <code>findByApiBrandId</code>, <code>dataflair_review_url</code> filter fires with the right initial URL in both paths), <code>TableRendererTest</code> (accordion wrapper, stale notice gating, title omission, pros/cons propagation through VM, offer fields rendered). Full suite: <strong>341 tests, 793 assertions, all green</strong>.</li>
</ul>

<h4>1.12.1</h4>
<ul>
  <li><strong>Phase 3 — sync services extracted.</strong> Continues the strangler-fig arc started in Phase 2. The toplist and brand sync pipelines are now owned by dedicated service classes; the god-class AJAX handlers shrink to 5–20 line delegators. No public contract change: every AJAX endpoint, every response shape, every filter, every action hook, every option name preserved byte-for-byte.</li>
  <li>Added: <code>DataFlair\\Toplists\\Sync\\ToplistSyncService</code> implementing <code>ToplistSyncServiceInterface</code>. Owns the full bulk happy-path + progressive per-ID fallback (per_page=10 → per_page=5 × 2 → per_page=1 × 5), the 30 s hard deadline, the JSON decode + invalid-body error paths, the page-1 paginated <code>DELETE FROM wp_dataflair_toplists</code> reset, the <code>dataflair_toplists_batch_last_page</code> transient, and the legacy <code>dataflair_last_toplists_sync</code> / <code>dataflair_last_toplists_cron_run</code> option writes.</li>
  <li>Added: <code>DataFlair\\Toplists\\Sync\\BrandSyncService</code> implementing <code>BrandSyncServiceInterface</code>. Owns the 13-column brand upsert, the Active status filter, the page-1 paginated <code>DELETE FROM wp_dataflair_brands</code>, the 25 s <code>WallClockBudget</code> with 3 s headroom, the H4 <code>unset()</code> + <code>gc_collect_cycles()</code> memory hygiene, the <code>download_brand_logo()</code> invocation (with its 3 MB / 8 s cap + HEAD-before-GET), and the <code>dataflair_brand_logo_stored</code> action hook at store time.</li>
  <li>Added: <code>DataFlair\\Toplists\\Sync\\AlternativesSyncService</code> implementing <code>AlternativesSyncServiceInterface</code>. Covers the alternative-toplists CRUD surface with input guards (<code>toplist_id &gt; 0</code>, save requires <code>toplist_id</code> + <code>geo</code>) and logger-side warnings on rejected input.</li>
  <li>Added: immutable value objects <code>SyncRequest</code> (readonly <code>type</code>, <code>page</code>, <code>perPage</code>, <code>budgetSeconds</code>; factories <code>toplists()</code> / <code>brands()</code> honour the H13 budget defaults of 25.0 s, per_page 10 for toplists and 5 for brands) and <code>SyncResult</code> (readonly <code>success</code>, <code>page</code>, <code>lastPage</code>, <code>synced</code>, <code>errors</code>, <code>partial</code>, <code>isComplete</code>, <code>nextPage</code>; <code>toArray()</code> preserves every legacy AJAX key — <code>page</code>, <code>last_page</code>, <code>synced</code>, <code>errors</code>, <code>partial</code>, <code>next_page</code>, <code>is_complete</code>, <code>total_synced</code>, <code>total_brands</code>, <code>skipped</code>, <code>skip_reason</code>, <code>fallback</code> — so the admin JS continues to work verbatim).</li>
  <li>Added: lazy filter-based DI for services — <code>dataflair_toplist_sync_service</code>, <code>dataflair_brand_sync_service</code>, <code>dataflair_alternatives_sync_service</code>. Any filter return that does not implement the documented interface is rejected and the default kept.</li>
  <li>Added: PSR-4 autoload entry for <code>DataFlair\\Toplists\\Sync\\</code> → <code>src/Sync/</code>. Existing entries retained.</li>
  <li>Changed: the god-class AJAX handlers <code>ajax_sync_toplists_batch</code>, <code>ajax_sync_brands_batch</code>, and the alternatives save/delete handlers are now thin delegators — nonce + capability + token precheck stay at the AJAX gate, everything below forwards into the service. Removed ~520 lines of now-dead private methods from the god-class (<code>sync_toplists_page_per_id</code>, <code>sync_brands_page</code>, plus helpers). God-class shrinks to 6,343 lines.</li>
  <li>Added: 38 new tests — <code>ToplistSyncServiceTest</code> (bulk happy path, per-ID fallback on WP_Error, 400 fails fast vs 500 retries, invalid JSON, missing <code>data</code> key, page-1 DELETE reset, persist-failure counts as error), <code>BrandSyncServiceTest</code> (13-column upsert shape, Active-only filter, missing-id error path, page-1 DELETE, logo downloader invocation), <code>AlternativesSyncServiceTest</code> (input guards, delegation), <code>SyncRequestTest</code> + <code>SyncResultTest</code> (readonly enforcement, <code>toArray</code> legacy-key shape, partial keeps same page for retry, extra-keys overrides for fallback path). Full suite: <strong>324 tests, 753 assertions, all green</strong>.</li>
</ul>

<h4>1.12.0</h4>
<ul>
  <li><strong>Phase 2 — repositories + HTTP client extracted.</strong> First real strangler-fig phase of the refactor arc. The god-class keeps every public method signature intact; the implementations now delegate through typed, testable collaborators.</li>
  <li>Added: new <code>src/</code> tree. <code>src/Http/{ApiClient, LogoDownloader}</code> implement <code>HttpClientInterface</code> + <code>LogoDownloaderInterface</code>. <code>src/Database/{ToplistsRepository, BrandsRepository, AlternativesRepository}</code> implement their matching interfaces — all Phase 0B/Phase 1 invariants preserved (15 MB response cap, 12 s timeout, 3 MB logo cap, 8 s logo timeout, HEAD-before-GET, 7-day reuse window, <code>dataflair_http_call</code> telemetry, <code>dataflair_brand_logo_stored</code> hook).</li>
  <li>Changed: <code>api_get()</code> and <code>download_brand_logo()</code> in the god-class are now 1–5 line delegators forwarding to the new <code>ApiClient</code> / <code>LogoDownloader</code>. No public contract change. Both accept their existing arguments and return exactly the same shapes.</li>
  <li>Added: lazy filter-based DI via <code>dataflair_api_client</code>, <code>dataflair_logo_downloader</code>, <code>dataflair_brands_repo</code>, <code>dataflair_toplists_repo</code>, <code>dataflair_alternatives_repo</code>. Any filter return that does not implement the documented interface is rejected and the default kept.</li>
  <li>Changed: H7 (batched <code>api_brand_id IN (...)</code> brand lookup) and H8 (batched review-post JOIN) now route through <code>BrandsRepository::findManyByApiBrandIds()</code> and <code>BrandsRepository::findReviewPostsByApiBrandIds()</code>. Behavior byte-identical; only the callsite changes.</li>
  <li>Added: PSR-4 autoload entries for <code>DataFlair\\Toplists\\Database\\</code> → <code>src/Database/</code> and <code>DataFlair\\Toplists\\Http\\</code> → <code>src/Http/</code>. Existing entries for <code>Models</code>, <code>Cli</code>, <code>Support</code>, <code>Logging</code> retained.</li>
  <li>Added: 39 new tests — <code>ApiClientTest</code> + <code>LogoDownloaderTest</code> (Brain Monkey behavioural pins: budget short-circuit, 15 MB cap, retry+success, retry exhaustion, HEAD-before-GET, size cap, hook firing, nested logo-URL shapes). <code>BrandsRepositoryTest</code>, <code>ToplistsRepositoryTest</code>, <code>AlternativesRepositoryTest</code> (Mockery-stubbed <code>$wpdb</code> — lookup, upsert/insert/update paths, delete, dedup rules). The pre-existing <code>SyncApiSizeCapTest</code> and <code>LogoSizeCapTest</code> were retargeted from god-class method scans to <code>src/Http/*</code> source scans. Full suite: <strong>286 tests, 645 assertions, all green</strong>.</li>
  <li>Tooling: <code>patchwork.json</code> adds <code>sleep</code> to <code>redefinable-internals</code> so Brain Monkey can stub retry backoff in unit tests.</li>
</ul>

<h4>1.11.2</h4>
<ul>
  <li><strong>Phase 1 — observability foundation.</strong> Lands before any extraction phase so every subsequent refactor (Phase 2 and later) ships with a contract for structured logging + telemetry in place. Consumers (Sentry on Sigma, stdout on local, file on shared hosts) are swappable without touching plugin code.</li>
  <li>Added: pluggable <code>DataFlair\Toplists\Logging\LoggerInterface</code> (PSR-3-shaped, hand-written — no <code>psr/log</code> dependency). Methods: <code>emergency/alert/critical/error/warning/notice/info/debug</code>, each accepting <code>(string $message, array $context = [])</code>.</li>
  <li>Added: three bundled implementations — <code>NullLogger</code> (no-op), <code>ErrorLogLogger</code> (writes to <code>error_log()</code> with a <code>[DataFlair][LEVEL]</code> prefix and JSON-encoded context), and a <code>SentryLogger</code> stub for downstream subclasses.</li>
  <li>Added: <code>LoggerFactory::get()</code> resolves the active logger via <code>apply_filters(\'dataflair_logger\', …)</code>. Caches per-request. A filter return value that does not implement <code>LoggerInterface</code> is rejected and the default is kept. Minimum log level filterable via <code>dataflair_logger_level</code> (default: <code>notice</code>).</li>
  <li>Added: six stable telemetry hooks emitted at named call sites — <code>dataflair_sync_batch_started</code>, <code>dataflair_sync_batch_finished</code>, <code>dataflair_sync_item_failed</code>, <code>dataflair_render_started</code>, <code>dataflair_render_finished</code>, <code>dataflair_http_call</code>. Structured payloads include <code>elapsed_seconds</code>, <code>memory_peak</code>, <code>page</code>, <code>per_page</code>, <code>budget_seconds</code>, etc. These are the telemetry points the later extraction phases will preserve byte-for-byte.</li>
  <li>Added: WP-CLI command <code>wp dataflair logs [--since=15m] [--level=warning] [--limit=200]</code>. Tails the active logger — for <code>ErrorLogLogger</code>, stream-reads the last 512 KB of the <code>error_log</code> destination, filters by the <code>[DataFlair]</code> tag + level threshold + time window. Custom loggers register their own tail via the <code>dataflair_logs_tail</code> filter.</li>
  <li>Changed: option rename migration (one-time, gated by <code>dataflair_options_renamed_v1_11_2</code>). <code>dataflair_last_toplists_cron_run</code> becomes <code>dataflair_last_toplists_sync</code>; brands equivalent. The legacy names continue to be written in parallel for one release so any downstream reader keeps working, and <code>format_last_sync_label()</code> falls back to the legacy name when the new one is empty.</li>
  <li>Tests: +13 new tests covering the <code>LoggerInterface</code> contract shape, factory resolution + caching + filter fallbacks, <code>ErrorLogLogger</code> min-level + JSON context encoding, and the <code>wp dataflair logs</code> command (tag filter, level filter, since filter, custom-tail precedence, limit cap). Total suite: 247 tests, 566 assertions, all green.</li>
</ul>

<h4>1.11.1</h4>
<ul>
  <li><strong>Phase 0.5 — perf rig + CI gate.</strong> Internal tooling release. No production-facing behaviour change. Gives the plugin a deterministic, repeatable perf harness so every subsequent refactor phase ships with a mechanical proof that it does not re-introduce the Sigma OOM.</li>
  <li>Added: WP-CLI command <code>wp dataflair perf:seed --tier={S|Sigma|L|XL|P}</code>. Generates deterministic synthetic toplists + brands at five tier sizes — from 10 toplists / 50 brands (tier S, smoke) up to 2,000 toplists / 5,000 brands (tier P, punishing). Each tier has a documented target JSON payload per toplist so the render path is exercised at realistic blob sizes.</li>
  <li>Added: WP-CLI command <code>wp dataflair perf:run --tier=Sigma --scenario={render|rest|admin|sync}</code>. Captures peak RSS, wall time, and query count across a scenario and fails with a non-zero exit when the configured thresholds (default 512 MB peak, 5 s wall) are breached.</li>
  <li>Added: drop-in MU-plugin <code>mu-plugins/dataflair-perf-probe.php</code> that emits a single-line peak-memory / wall-time / query-count stanza to stderr and error_log on every WP request. Dependency-free — works on any WP surface (frontend, REST, AJAX, WP-CLI).</li>
  <li>Added: <code>composer perf</code> script that wires the seed + run commands into a single repeatable invocation. Gracefully no-ops with a hint when WP-CLI is not on PATH so dev laptops without WP-CLI do not fail.</li>
  <li>Added: GitHub Actions workflow <code>.github/workflows/perf-gate.yml</code> that runs the Sigma-tier render scenario on every PR targeting <code>epic/refactor-april</code> or <code>main</code>, under a <code>memory_limit=1G</code> PHP process with the 512 MB / 5 s gate. A <code>skip-perf-gate</code> PR label bypasses the gate for docs-only or known-flaky situations — never leave it on a code PR.</li>
  <li>Added: <code>docs/PERF.md</code> — thresholds, tiers, scenarios, local run guide, probe output format, fatal-reproduction steps, environment variables, and the label-bypass policy.</li>
</ul>

<h4>1.11.0</h4>
<ul>
  <li><strong>Phase 0B safety rails — defense-in-depth follow-up to 1.10.8.</strong> This release lands twelve latent-OOM, timeout-cap, and memory-hygiene fixes across the sync, render, admin, REST, and migration paths. No behaviour change on the happy path — everything here either caps an unbounded resource or yields under pressure.</li>
  <li>Removed: the WP-cron auto-sync machinery (H1). <code>dataflair_sync_cron</code> and <code>dataflair_brands_sync_cron</code> are gone. Sync now runs only when an operator triggers it from the admin Tools page or via WP-CLI. A one-time migration (gated by the persistent option <code>dataflair_cron_cleared_v1_11</code>) clears any legacy schedules from prior installs.</li>
  <li>Added: <code>WallClockBudget</code> primitive (H13). Sync handlers take a 25 s budget with 3 s headroom; when the budget is exhausted mid-page they return <code>partial: true</code> and the admin JS re-issues the same page with exponential backoff (1 s → 2 s → 4 s → capped at 8 s, max 10 consecutive partials).</li>
  <li>Fixed: HTTP size caps. <code>api_get()</code> streams to a temp file with a 15 MB body cap and 12 s default timeout (H2). <code>download_brand_logo()</code> caps at 3 MB with an 8 s timeout and does a <code>HEAD</code> before <code>GET</code> to short-circuit oversized downloads (H3).</li>
  <li>Fixed: <code>unset()</code> + <code>gc_collect_cycles()</code> after each item inside the remaining sync batches (H4). Prevents PHP from carrying forward 200–500 MB of decoded JSON rows through an admin-triggered bulk sync.</li>
  <li>Fixed: casino-card render no longer fires N per-card <code>$wpdb->prepare</code> cascades (H7). The shortcode handler prefetches every card\'s brand row in at most three <code>IN(...)</code> queries and passes the map into <code>render_casino_card()</code>. The review-post lookup gets the same batched treatment (H8).</li>
  <li>Fixed: <code>brands</code> admin page paginates server-side at 50/page with a lean column projection and pulls data blobs only for the current slice (H5). Filter dropdowns now use <code>SELECT DISTINCT</code> on CSV columns instead of parsing every blob. <code>settings</code> page projects to a lean set of columns plus a <code>JSON_UNQUOTE(JSON_EXTRACT(data, \'$.data.template.name\'))</code> extract — the full JSON blob is never pulled into PHP (H6).</li>
  <li>Fixed: <code>check_database_upgrade()</code> short-circuits on a <code>dataflair_schema_ok_v{VERSION}</code> transient (12 h TTL, busts on version bump) (H9). Every front-end request no longer re-runs the migration logic.</li>
  <li>Fixed: <code>clear_tracker_transients()</code> is now a chunked DELETE loop with <code>LIMIT 1000</code> (H10), optionally yielding to a <code>WallClockBudget</code>. Replaces the prior single unbounded DELETE that could blow <code>max_allowed_packet</code> and deadlock under row-based replication.</li>
  <li>Fixed: plugin no longer issues wholesale <code>TRUNCATE</code> on <code>wp_dataflair_*</code> (H11). <code>TRUNCATE</code> is not safely replicable across all managed-MySQL providers. Replaced with <code>delete_all_paginated()</code>, a binlog-safe chunked DELETE helper with a hardcoded table whitelist.</li>
  <li>Fixed: REST <code>/toplists/{id}/casinos</code> now paginates (H12). <code>?per_page</code> default 20, max 100; <code>?page</code> 1-based. Default per-item shape is lean: <code>{id, name, rating, offer_text, logo_url}</code>. <code>?full=1</code> preserves the legacy verbose shape for the block editor. <code>X-WP-Total</code> / <code>X-WP-TotalPages</code> headers included.</li>
  <li>Added: new tests — <code>CronRemovedTest</code>, <code>SyncApiSizeCapTest</code>, <code>LogoSizeCapTest</code>, <code>WallClockBudgetTest</code>, <code>RenderBatchQueryCountTest</code>, <code>ClearTransientsChunkedTest</code>, <code>RestCasinosPaginationTest</code>. Full suite: 234 tests, 527 assertions.</li>
</ul>

<h4>1.10.8</h4>
<ul>
  <li>Fixed: critical — casino-card rendering is now fully read-only. The render chain no longer sideloads brand logos at page-view time and no longer auto-creates review CPT rows. These two render-time writes were the root cause of memory-exhaustion fatals on sites with a 1 GB PHP memory limit.</li>
  <li>Added: pre-computed <code>local_logo_url</code> and <code>cached_review_post_id</code> columns on <code>wp_dataflair_brands</code>. The render template reads them directly; sync populates <code>local_logo_url</code> and the new CLI backfills <code>cached_review_post_id</code>.</li>
  <li>Added: WP-CLI command <code>wp dataflair reconcile-reviews [--batch=500] [--dry-run]</code> to link existing brand rows to their published review posts. Run once after upgrade.</li>
  <li>Added: <code>do_action(\'dataflair_brand_logo_stored\', $brand_id, $local_url, $remote_url)</code> hook fires when a logo is stored at sync time, so themes can react without coupling to private render internals.</li>
  <li>Added: regression tests <code>RenderIsReadOnlyTest</code> and <code>CasinoCardUsesPrecomputedLogoTest</code> permanently enforce the read-only render invariant.</li>
  <li>Changed: minimum PHP bumped to 8.1 and minimum WordPress to 6.3 to match the supported runtime on target sites.</li>
</ul>

<h4>1.10.7</h4>
<ul>
  <li>Fixed: "Fetch All Toplists from API" no longer returns 500 on heavy pages. Bulk list fetch dropped from per_page=20 to per_page=10 to stay inside the DataFlair API\'s ~15s serializer budget (verified across all 18 pages / 175 toplists, heaviest page ~13.6s).</li>
  <li>Improved: per-ID fallback splitter rewritten for speed — skips the redundant per_page=10 retry level, uses no-retry 15s-timeout slices, and a 30s hard deadline on the whole fallback. Previous version could stall ~3 minutes on a failing page; worst case is now ~25-30s.</li>
  <li>Added: "skipped" response shape so one unrecoverable page no longer halts the whole sync. The admin UI advances past skipped pages and reports them in the final summary.</li>
  <li>Added: retry with exponential backoff (1s, 2s) on transient WP_Error and 5xx responses in the API helper, with configurable timeout and max-retries arguments.</li>
</ul>

<h4>1.10.6</h4>
<ul>
  <li>Improved: Redesigned the "Synced Toplists" admin table for a cleaner look with a wider Name column, removing redundant Slug, Locked, Sync Health, and Shortcode columns.</li>
  <li>Improved: Optimized the "Fetch All Toplists from API" process by batching requests with full item inclusion, dramatically reducing sync time and preventing API timeouts.</li>
</ul>

<h4>1.10.5</h4>
<ul>
  <li>Fixed: pinned Composer platform requirement to PHP 8.3.16 so that legacy deployment scripts running composer install without --no-dev do not fail resolving PHP 8.4-only development dependencies like doctrine/instantiator 2.1.0</li>
</ul>

<h4>1.10.4</h4>
<ul>
  <li>Added: Composer convenience scripts install-prod and install-dev in composer.json for explicit production vs development dependency installs</li>
  <li>Improved: composer.json now defaults to dist installs with optimized autoloading to reduce deployment variance</li>
  <li>Fixed: production guidance now enforces --no-dev installs to prevent PHPUnit/dev dependencies from being installed on PHP 8.3 servers</li>
</ul>

<h4>1.10.3</h4>
<ul>
  <li>Fixed: release packaging now uses production-only Composer dependencies (no --dev) so PHPUnit and other dev packages are not shipped to production servers</li>
  <li>Improved: vendor/composer metadata regenerated in no-dev mode for PHP 8.3 compatibility on production installs</li>
</ul>

<h4>1.10.2</h4>
<ul>
  <li>Added: Gutenberg layout option "Accordion Tables (Testing)" that renders each brand as an accordion with two compact data tables to avoid horizontal scrolling during QA</li>
  <li>Fixed: card layout now resolves page-level block pros/cons overrides with stable and legacy key formats, matching table layout behavior</li>
  <li>Improved: card details now surface the resolved Pros and Cons list so page-level overrides are visible during testing</li>
</ul>

<h4>1.10.1</h4>
<ul>
  <li>Fixed: Gutenberg pros and cons overrides now use stable brand and item identifiers from synced toplist data instead of position-only keys, preventing custom copy from drifting after reorder or refresh</li>
  <li>Improved: synced casino items now carry itemId and brandId values in API v2 parsing to support reliable editor override matching</li>
  <li>Added: PHPUnit coverage for both data.items and data.listItems payloads to verify itemId and brandId mapping</li>
</ul>

<h4>1.10.0</h4>
<ul>
  <li>Fixed: Read Review link on casino cards only appears when a published review exists or a manual review URL override is set (hidden for draft-only reviews)</li>
  <li>Fixed: review CPT resolution finds published posts by _review_brand_id when the WordPress slug differs from the API brand slug, and no longer lets a plugin draft at the base slug hide the live published review (for example …-india)</li>
  <li>Improved: new auto-created review drafts store _review_brand_id from api_brand_id when id is absent</li>
  <li>Added: E2E test script tests/e2e/test-read-review-link.php (draft vs published, slug mismatch, draft+published shadow case)</li>
</ul>

<h4>1.9.9</h4>
<ul>
  <li>Added: Brand::whereJson() query helper for JSON field filtering in the Brand model</li>
  <li>Added: brands table migration for external_id_virtual generated column with idx_external_id_virtual index for fast externalId lookups</li>
  <li>Improved: whereJson SQL builder now supports JSON_EXTRACT path/value comparisons for data.externalId-style queries</li>
</ul>

<h4>1.9.8</h4>
<ul>
  <li>Fixed: ProductTypeLabels and DataIntegrityChecker now use the DataFlair\\Toplists\\Models namespace to comply with Composer PSR-4 autoloading</li>
  <li>Improved: backward compatibility aliases keep legacy class references working while enabling PSR-4 class resolution</li>
</ul>

<h4>1.9.7</h4>
<ul>
  <li>Fixed: restored Composer PSR-4 autoload mapping for DataFlair model classes in composer.json</li>
  <li>Improved: regenerated Composer autoload metadata to include DataFlair\\Toplists\\Models namespace resolution</li>
</ul>

<h4>1.9.6</h4>
<ul>
  <li>Fixed: Gutenberg Pros and Cons inspector copy now references review CPT defaults instead of API-default replacement wording</li>
  <li>Added: regression test for review pros fallback that ignores draft slug matches and prefers published _review_brand_id matches</li>
  <li>Added: integration coverage to verify ExternalId is preserved in stored toplist and brand JSON payloads</li>
</ul>

<h4>1.9.4</h4>
<ul>
  <li>Fixed: casino override key generation now sanitizes brand names to match Gutenberg editor brandSlug behavior</li>
  <li>Improved: pros and cons overrides now resolve reliably for brands whose API slugs contain special characters (for example, dots)</li>
</ul>

<h4>1.9.3</h4>
<ul>
  <li>Added: E2E test suite — brand sync (9 assertions), toplist sync (16 assertions), cron jobs (14 assertions)</li>
  <li>Added: run.sh test orchestrator with auto-detection for Docker/wp-env, production WP-CLI, and CI environments</li>
  <li>Added: BrandModelTest.php — 100% coverage for Brand model</li>
  <li>Added: ProductTypeLabelsTest.php — 100% coverage for ProductTypeLabels</li>
  <li>Fixed: constant definitions wrapped in if(!defined()) guards to prevent redefinition on test bootstrap</li>
  <li>Fixed: deprecated ReflectionProperty::setAccessible(true) calls removed from ToplistModelTest (PHP 8.5)</li>
  <li>Improved: @codeCoverageIgnore added to admin HTML methods and casino card renderer</li>
  <li>Improved: vendor/ cleaned to production-only dependencies (dev packages removed)</li>
</ul>

<h4>1.9.1</h4>
<ul>
  <li>Added: View Details popup on plugins.php now shows full description and changelog</li>
  <li>Added: CLAUDE.md with release checklist and permanent rsync rule</li>
  <li>Updated: README.md fully rewritten with complete feature documentation</li>
</ul>

<h4>1.9.0</h4>
<ul>
  <li>Added: automatic plugin updates via GitHub releases (plugin-update-checker v5.6)</li>
  <li>Added: promo code display with copy-to-clipboard button on toplist casino cards</li>
  <li>Added: per-brand review URL override in DataFlair, Brands admin</li>
  <li>Fixed: global $wpdb missing in card renderer, review URL override was silently failing</li>
  <li>Fixed: draft/preview post URLs replaced with clean /reviews/{slug}/ fallback</li>
  <li>Fixed: undefined $product_type and $labels PHP warnings in card template</li>
</ul>

<h4>1.8.1</h4>
<ul>
  <li>Added: review URL input field in Brands table, locks after save, unlocks on Edit</li>
</ul>

<h4>1.8.0</h4>
<ul>
  <li>Added: review URL override column in brands table</li>
  <li>Added: Composer autoload infrastructure</li>
</ul>

<h4>1.7.0</h4>
<ul>
  <li>Added: DataFlair API v2 brands sync</li>
  <li>Added: compare preview before overwriting synced data</li>
  <li>Fixed: block REST API endpoint</li>
</ul>

<h4>1.6.0</h4>
<ul>
  <li>Added: editions support for geo-market toplists</li>
  <li>Fixed: critical sync failure on missing table</li>
</ul>

<h4>1.5.0</h4>
<ul>
  <li>Added: snapshot support, data integrity checker, API preview tab</li>
</ul>

<h4>1.4.0</h4>
<ul>
  <li>Added: /api/v1/brands endpoint support with 8 new brand fields</li>
  <li>Fixed: self-healing cron</li>
</ul>
        ',
    ];

    return $res;
}
// @codeCoverageIgnoreEnd

/**
 * Main DataFlair Plugin Class
 */
class DataFlair_Toplists {

    private static $instance = null;

    /**
     * Phase 2 — extracted collaborators. Lazy-instantiated via getter so
     * construction stays side-effect-free and the plugin boots identically
     * to v1.11.2 for existing callers. Every new-class in src/ is accessed
     * exclusively through its getter below; never read the property directly.
     *
     * @var \DataFlair\Toplists\Http\HttpClientInterface|null
     */
    private $api_client = null;

    /** @var \DataFlair\Toplists\Http\LogoDownloaderInterface|null */
    private $logo_downloader = null;

    /** @var \DataFlair\Toplists\Database\BrandsRepositoryInterface|null */
    private $brands_repo = null;

    /** @var \DataFlair\Toplists\Database\ToplistsRepositoryInterface|null */
    private $toplists_repo = null;

    /** @var \DataFlair\Toplists\Database\AlternativesRepositoryInterface|null */
    private $alternatives_repo = null;

    /**
     * Phase 3 — sync services. Lazy-instantiated. See src/Sync/*.
     *
     * @var \DataFlair\Toplists\Sync\ToplistSyncServiceInterface|null
     */
    private $toplist_sync_service = null;

    /** @var \DataFlair\Toplists\Sync\BrandSyncServiceInterface|null */
    private $brand_sync_service = null;

    /** @var \DataFlair\Toplists\Sync\AlternativesSyncServiceInterface|null */
    private $alternatives_sync_service = null;

    /**
     * Phase 4 — renderers. Lazy-instantiated. See src/Frontend/Render/*.
     *
     * @var \DataFlair\Toplists\Frontend\Render\CardRendererInterface|null
     */
    private $card_renderer = null;

    /** @var \DataFlair\Toplists\Frontend\Render\TableRendererInterface|null */
    private $table_renderer = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->init_hooks();
    }

    /**
     * Lazy accessor for the Phase 2 HTTP client.
     * Filterable via `dataflair_api_client` so test harnesses and downstream
     * consumers can inject a fake. The filter MUST return an instance of
     * {@see \DataFlair\Toplists\Http\HttpClientInterface}; other values are
     * rejected and the default `ApiClient` is used.
     *
     * @return \DataFlair\Toplists\Http\HttpClientInterface
     */
    private function api_client() {
        if ($this->api_client instanceof \DataFlair\Toplists\Http\HttpClientInterface) {
            return $this->api_client;
        }
        $default = new \DataFlair\Toplists\Http\ApiClient();
        $maybe   = function_exists('apply_filters')
            ? apply_filters('dataflair_api_client', $default)
            : $default;
        $this->api_client = ($maybe instanceof \DataFlair\Toplists\Http\HttpClientInterface)
            ? $maybe
            : $default;
        return $this->api_client;
    }

    /**
     * Lazy accessor for the Phase 2 logo downloader.
     * Filterable via `dataflair_logo_downloader`.
     *
     * @return \DataFlair\Toplists\Http\LogoDownloaderInterface
     */
    private function logo_downloader() {
        if ($this->logo_downloader instanceof \DataFlair\Toplists\Http\LogoDownloaderInterface) {
            return $this->logo_downloader;
        }
        $default = new \DataFlair\Toplists\Http\LogoDownloader();
        $maybe   = function_exists('apply_filters')
            ? apply_filters('dataflair_logo_downloader', $default)
            : $default;
        $this->logo_downloader = ($maybe instanceof \DataFlair\Toplists\Http\LogoDownloaderInterface)
            ? $maybe
            : $default;
        return $this->logo_downloader;
    }

    /**
     * @return \DataFlair\Toplists\Database\BrandsRepositoryInterface
     */
    private function brands_repo() {
        if ($this->brands_repo instanceof \DataFlair\Toplists\Database\BrandsRepositoryInterface) {
            return $this->brands_repo;
        }
        $default = new \DataFlair\Toplists\Database\BrandsRepository();
        $maybe   = function_exists('apply_filters')
            ? apply_filters('dataflair_brands_repository', $default)
            : $default;
        $this->brands_repo = ($maybe instanceof \DataFlair\Toplists\Database\BrandsRepositoryInterface)
            ? $maybe
            : $default;
        return $this->brands_repo;
    }

    /**
     * @return \DataFlair\Toplists\Database\ToplistsRepositoryInterface
     */
    private function toplists_repo() {
        if ($this->toplists_repo instanceof \DataFlair\Toplists\Database\ToplistsRepositoryInterface) {
            return $this->toplists_repo;
        }
        $default = new \DataFlair\Toplists\Database\ToplistsRepository();
        $maybe   = function_exists('apply_filters')
            ? apply_filters('dataflair_toplists_repository', $default)
            : $default;
        $this->toplists_repo = ($maybe instanceof \DataFlair\Toplists\Database\ToplistsRepositoryInterface)
            ? $maybe
            : $default;
        return $this->toplists_repo;
    }

    /**
     * @return \DataFlair\Toplists\Database\AlternativesRepositoryInterface
     */
    private function alternatives_repo() {
        if ($this->alternatives_repo instanceof \DataFlair\Toplists\Database\AlternativesRepositoryInterface) {
            return $this->alternatives_repo;
        }
        $default = new \DataFlair\Toplists\Database\AlternativesRepository();
        $maybe   = function_exists('apply_filters')
            ? apply_filters('dataflair_alternatives_repository', $default)
            : $default;
        $this->alternatives_repo = ($maybe instanceof \DataFlair\Toplists\Database\AlternativesRepositoryInterface)
            ? $maybe
            : $default;
        return $this->alternatives_repo;
    }

    /**
     * Lazy accessor for the Phase 3 toplist sync service.
     * Filterable via `dataflair_toplist_sync_service`.
     *
     * @return \DataFlair\Toplists\Sync\ToplistSyncServiceInterface
     */
    private function toplist_sync_service() {
        if ($this->toplist_sync_service instanceof \DataFlair\Toplists\Sync\ToplistSyncServiceInterface) {
            return $this->toplist_sync_service;
        }

        $token   = trim((string) get_option('dataflair_api_token'));
        $logger  = \DataFlair\Toplists\Logging\LoggerFactory::get();
        $default = new \DataFlair\Toplists\Sync\ToplistSyncService(
            $this->api_client(),
            new \DataFlair\Toplists\Sync\GodClassToplistPersister($this),
            $logger,
            $token,
            $this->get_api_base_url(),
            \Closure::fromCallable([$this, 'build_detailed_api_error'])
        );
        $maybe = function_exists('apply_filters')
            ? apply_filters('dataflair_toplist_sync_service', $default)
            : $default;
        $this->toplist_sync_service = ($maybe instanceof \DataFlair\Toplists\Sync\ToplistSyncServiceInterface)
            ? $maybe
            : $default;
        return $this->toplist_sync_service;
    }

    /**
     * Lazy accessor for the Phase 3 brand sync service.
     * Filterable via `dataflair_brand_sync_service`.
     *
     * @return \DataFlair\Toplists\Sync\BrandSyncServiceInterface
     */
    private function brand_sync_service() {
        if ($this->brand_sync_service instanceof \DataFlair\Toplists\Sync\BrandSyncServiceInterface) {
            return $this->brand_sync_service;
        }

        $token   = trim((string) get_option('dataflair_api_token'));
        $logger  = \DataFlair\Toplists\Logging\LoggerFactory::get();
        $urlFn   = function ($page) { return $this->get_brands_api_url((int) $page); };
        $default = new \DataFlair\Toplists\Sync\BrandSyncService(
            $this->api_client(),
            $this->logo_downloader(),
            $this->brands_repo(),
            $logger,
            $token,
            $urlFn,
            \Closure::fromCallable([$this, 'build_detailed_api_error'])
        );
        $maybe = function_exists('apply_filters')
            ? apply_filters('dataflair_brand_sync_service', $default)
            : $default;
        $this->brand_sync_service = ($maybe instanceof \DataFlair\Toplists\Sync\BrandSyncServiceInterface)
            ? $maybe
            : $default;
        return $this->brand_sync_service;
    }

    /**
     * Lazy accessor for the Phase 3 alternatives sync service.
     * Filterable via `dataflair_alternatives_sync_service`.
     *
     * @return \DataFlair\Toplists\Sync\AlternativesSyncServiceInterface
     */
    private function alternatives_sync_service() {
        if ($this->alternatives_sync_service instanceof \DataFlair\Toplists\Sync\AlternativesSyncServiceInterface) {
            return $this->alternatives_sync_service;
        }
        $default = new \DataFlair\Toplists\Sync\AlternativesSyncService(
            $this->alternatives_repo(),
            \DataFlair\Toplists\Logging\LoggerFactory::get()
        );
        $maybe = function_exists('apply_filters')
            ? apply_filters('dataflair_alternatives_sync_service', $default)
            : $default;
        $this->alternatives_sync_service = ($maybe instanceof \DataFlair\Toplists\Sync\AlternativesSyncServiceInterface)
            ? $maybe
            : $default;
        return $this->alternatives_sync_service;
    }

    /**
     * Lazy accessor for the Phase 4 card renderer.
     * Filterable via `dataflair_card_renderer`. Filter returns that do not
     * implement {@see \DataFlair\Toplists\Frontend\Render\CardRendererInterface}
     * are rejected and the default is used.
     *
     * @return \DataFlair\Toplists\Frontend\Render\CardRendererInterface
     */
    private function card_renderer() {
        if ($this->card_renderer instanceof \DataFlair\Toplists\Frontend\Render\CardRendererInterface) {
            return $this->card_renderer;
        }
        $default = new \DataFlair\Toplists\Frontend\Render\CardRenderer(
            $this->brands_repo(),
            \DataFlair\Toplists\Logging\LoggerFactory::get()
        );
        $maybe = function_exists('apply_filters')
            ? apply_filters('dataflair_card_renderer', $default)
            : $default;
        $this->card_renderer = ($maybe instanceof \DataFlair\Toplists\Frontend\Render\CardRendererInterface)
            ? $maybe
            : $default;
        return $this->card_renderer;
    }

    /**
     * Lazy accessor for the Phase 4 table (accordion) renderer.
     * Filterable via `dataflair_table_renderer`.
     *
     * @return \DataFlair\Toplists\Frontend\Render\TableRendererInterface
     */
    private function table_renderer() {
        if ($this->table_renderer instanceof \DataFlair\Toplists\Frontend\Render\TableRendererInterface) {
            return $this->table_renderer;
        }
        $default = new \DataFlair\Toplists\Frontend\Render\TableRenderer();
        $maybe   = function_exists('apply_filters')
            ? apply_filters('dataflair_table_renderer', $default)
            : $default;
        $this->table_renderer = ($maybe instanceof \DataFlair\Toplists\Frontend\Render\TableRendererInterface)
            ? $maybe
            : $default;
        return $this->table_renderer;
    }

    private function init_hooks() {
        // Activation/Deactivation
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
        
        // Check and upgrade database schema
        add_action('plugins_loaded', array($this, 'check_database_upgrade'));
        
        // Admin menu
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('wp_ajax_dataflair_save_settings', array($this, 'ajax_save_settings'));
        add_action('wp_ajax_dataflair_fetch_all_toplists', array($this, 'ajax_fetch_all_toplists'));
        add_action('wp_ajax_dataflair_sync_toplists_batch', array($this, 'ajax_sync_toplists_batch'));
        add_action('wp_ajax_dataflair_fetch_all_brands', array($this, 'ajax_fetch_all_brands'));
        add_action('wp_ajax_dataflair_sync_brands_batch', array($this, 'ajax_sync_brands_batch'));
        add_action('wp_ajax_dataflair_get_alternative_toplists', array($this, 'ajax_get_alternative_toplists'));
        add_action('wp_ajax_dataflair_save_alternative_toplist', array($this, 'ajax_save_alternative_toplist'));
        add_action('wp_ajax_dataflair_delete_alternative_toplist', array($this, 'ajax_delete_alternative_toplist'));
        add_action('wp_ajax_dataflair_get_available_geos', array($this, 'ajax_get_available_geos'));
        add_action('wp_ajax_dataflair_api_preview', array($this, 'ajax_api_preview'));
        add_action('wp_ajax_dataflair_save_review_url', array($this, 'ajax_save_review_url'));
        
        // Shortcode
        add_shortcode('dataflair_toplist', array($this, 'toplist_shortcode'));
        
        // Gutenberg Block
        add_action('init', array($this, 'register_block'));
        
        // REST API for block editor
        add_action('rest_api_init', array($this, 'register_rest_routes'));
        
        // Redirect handler for /go/ campaign links
        add_action('template_redirect', array($this, 'handle_campaign_redirect'));

        // Cron registration was removed in v1.11.0 (Phase 0B H1). DataFlair
        // sync now runs only when an operator triggers it from the admin
        // Tools page or via WP-CLI. Legacy cron events are cleared once at
        // upgrade time by the `dataflair_cron_cleared_v1_11` gate in
        // upgrade_database().

        // Enqueue frontend styles and scripts
        add_action('wp_enqueue_scripts', array($this, 'enqueue_frontend_assets'));
        
        // Enqueue admin scripts
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        
        // Enqueue block editor assets
        add_action('enqueue_block_editor_assets', array($this, 'enqueue_editor_assets'));
        
        // Check if shortcode/block is used and enqueue Alpine.js if needed
        add_action('wp_footer', array($this, 'maybe_enqueue_alpine'), 5);
        add_action('wp_footer', array($this, 'enqueue_promo_copy_script'), 20);

        // Also check in widgets and other areas
        add_filter('widget_text', array($this, 'check_widget_for_shortcode'), 10, 2);

        // Admin notice: plain permalinks break the REST API (and the Gutenberg block)
        add_action('admin_notices', array($this, 'maybe_notice_plain_permalinks'));
    }
    
    /**
     * Show an admin notice if plain permalinks are active (REST API won't work).
     */
    public function maybe_notice_plain_permalinks() {
        if (empty(get_option('permalink_structure'))) {
            echo '<div class="notice notice-error"><p>'
               . '<strong>DataFlair:</strong> The Gutenberg block requires '
               . 'pretty permalinks. Go to <a href="' . admin_url('options-permalink.php') . '">'
               . 'Settings &rarr; Permalinks</a> and choose any option other than Plain.'
               . '</p></div>';
        }
    }

    /**
     * Plugin activation
     */
    public function activate() {
        global $wpdb;
        $table_name = $wpdb->prefix . DATAFLAIR_TABLE_NAME;
        $brands_table_name = $wpdb->prefix . DATAFLAIR_BRANDS_TABLE_NAME;
        $alternative_toplists_table = $wpdb->prefix . DATAFLAIR_ALTERNATIVE_TOPLISTS_TABLE_NAME;
        $charset_collate = $wpdb->get_charset_collate();
        
        // Check if JSON type is supported
        $supports_json = $this->supports_json_type();
        $data_type = $supports_json ? 'JSON' : 'longtext';
        
        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            api_toplist_id bigint(20) NOT NULL,
            name varchar(255) NOT NULL,
            slug varchar(255) DEFAULT NULL,
            current_period varchar(100) DEFAULT NULL,
            published_at datetime DEFAULT NULL,
            item_count int(11) NOT NULL DEFAULT 0,
            locked_count int(11) NOT NULL DEFAULT 0,
            sync_warnings text DEFAULT NULL,
            data $data_type NOT NULL,
            version varchar(50) DEFAULT NULL,
            last_synced datetime NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY api_toplist_id (api_toplist_id)
        ) $charset_collate;";
        
        $brands_sql = "CREATE TABLE IF NOT EXISTS $brands_table_name (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            api_brand_id bigint(20) NOT NULL,
            name varchar(255) NOT NULL,
            slug varchar(255) NOT NULL,
            status varchar(50) NOT NULL,
            product_types text,
            licenses text,
            top_geos text,
            offers_count int(11) DEFAULT 0,
            trackers_count int(11) DEFAULT 0,
            classification_types VARCHAR(500) NOT NULL DEFAULT '',
            review_url_override VARCHAR(500) DEFAULT NULL,
            data $data_type NOT NULL,
            last_synced datetime NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY api_brand_id (api_brand_id)
        ) $charset_collate;";
        
        $alternative_toplists_sql = "CREATE TABLE IF NOT EXISTS $alternative_toplists_table (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            toplist_id bigint(20) NOT NULL,
            geo varchar(255) NOT NULL,
            alternative_toplist_id bigint(20) NOT NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY toplist_geo (toplist_id, geo),
            KEY toplist_id (toplist_id),
            KEY alternative_toplist_id (alternative_toplist_id)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        dbDelta($brands_sql);
        dbDelta($alternative_toplists_sql);
        $this->ensure_brands_external_id_index();
        
        // NOTE: cron scheduling removed in v1.11.0 (Phase 0B H1). Legacy
        // cron events are cleared once at upgrade time by the
        // `dataflair_cron_cleared_v1_11` gate in upgrade_database().
    }
    
    /**
     * Plugin deactivation
     */
    public function deactivate() {
        wp_clear_scheduled_hook('dataflair_sync_cron');
        wp_clear_scheduled_hook('dataflair_brands_sync_cron');
    }
    
    /**
     * Check and upgrade database schema if needed
     */
    public function check_database_upgrade() {
        $db_version = get_option('dataflair_db_version', '1.0');
        $current_version = '1.11'; // v1.11: Phase 0B H1 clears legacy cron + misc safety rails

        // Phase 0B H9: short-circuit the whole upgrade/self-heal path on warm
        // hits via a 12h transient keyed by the current schema version. The
        // transient is busted automatically whenever $current_version changes.
        // Saves ~4 SHOW TABLES + column-introspection queries on every request.
        $schema_ok_key = 'dataflair_schema_ok_v' . $current_version;
        if (get_transient($schema_ok_key) === '1') {
            return;
        }

        if (version_compare($db_version, $current_version, '<')) {
            $this->upgrade_database();
            update_option('dataflair_db_version', $current_version);
        }

        // H1: clear legacy cron events exactly once. Gated by a persistent
        // option so the clear survives restart loops and can't re-run. Safe
        // to call on every request — after the one-time clear the option is
        // set and this short-circuits.
        if (get_option('dataflair_cron_cleared_v1_11') !== '1') {
            wp_clear_scheduled_hook('dataflair_sync_cron');
            wp_clear_scheduled_hook('dataflair_brands_sync_cron');
            update_option('dataflair_cron_cleared_v1_11', '1');
        }

        // Phase 1 — option rename migration. The `dataflair_last_*_cron_run`
        // names were kept as option keys after the cron machinery was deleted
        // in v1.11.0; rename them to the more accurate `dataflair_last_*_sync`
        // now that we have an observability release to carry the change.
        // One-time, gated so reverting + re-upgrading is idempotent.
        if (get_option('dataflair_options_renamed_v1_11_2') !== '1') {
            foreach (array(
                'dataflair_last_toplists_cron_run' => 'dataflair_last_toplists_sync',
                'dataflair_last_brands_cron_run'   => 'dataflair_last_brands_sync',
            ) as $legacy => $new) {
                $legacy_value = get_option($legacy);
                if ($legacy_value !== false && get_option($new) === false) {
                    update_option($new, $legacy_value);
                }
                // Intentionally keep the legacy row until one release later;
                // read paths fall back gracefully. Phase 2 or later can delete.
            }
            update_option('dataflair_options_renamed_v1_11_2', '1');
        }

        // Self-heal: ensure all tables exist even if activation hook never fired
        // (happens when plugin is deployed by copying files rather than via WP Admin)
        $this->ensure_tables_exist();

        // Migrate to JSON type if supported
        $this->migrate_to_json_type();

        // Phase 0B H9: mark schema healthy for 12h. Next request short-circuits.
        set_transient($schema_ok_key, '1', 12 * HOUR_IN_SECONDS);
    }

    /**
     * Ensure all plugin tables exist. Safe to call on every request — only
     * runs DDL if a table is actually missing. Covers manual file deploys
     * where register_activation_hook never fires.
     */
    private function ensure_tables_exist() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        $supports_json   = $this->supports_json_type();
        $data_type       = $supports_json ? 'JSON' : 'longtext';

        $table_name      = $wpdb->prefix . DATAFLAIR_TABLE_NAME;
        $brands_table    = $wpdb->prefix . DATAFLAIR_BRANDS_TABLE_NAME;

        $missing = false;
        if ( $wpdb->get_var( "SHOW TABLES LIKE '$table_name'" ) !== $table_name ) {
            $missing = true;
        }
        if ( $wpdb->get_var( "SHOW TABLES LIKE '$brands_table'" ) !== $brands_table ) {
            $missing = true;
        }

        if ( ! $missing ) {
            // Tables exist, but ensure generated externalId index is present.
            $this->ensure_brands_external_id_index();
            return;
        }

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            api_toplist_id bigint(20) NOT NULL,
            name varchar(255) NOT NULL,
            slug varchar(255) DEFAULT NULL,
            current_period varchar(100) DEFAULT NULL,
            published_at datetime DEFAULT NULL,
            item_count int(11) NOT NULL DEFAULT 0,
            locked_count int(11) NOT NULL DEFAULT 0,
            sync_warnings text DEFAULT NULL,
            data $data_type NOT NULL,
            version varchar(50) DEFAULT NULL,
            last_synced datetime NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY api_toplist_id (api_toplist_id)
        ) $charset_collate;";

        $brands_sql = "CREATE TABLE IF NOT EXISTS $brands_table (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            api_brand_id bigint(20) NOT NULL,
            name varchar(255) NOT NULL,
            slug varchar(255) NOT NULL,
            status varchar(50) NOT NULL,
            product_types text,
            licenses text,
            top_geos text,
            offers_count int(11) DEFAULT 0,
            trackers_count int(11) DEFAULT 0,
            classification_types VARCHAR(500) NOT NULL DEFAULT '',
            review_url_override VARCHAR(500) DEFAULT NULL,
            local_logo_url VARCHAR(500) DEFAULT NULL,
            cached_review_post_id BIGINT(20) UNSIGNED DEFAULT NULL,
            data $data_type NOT NULL,
            last_synced datetime NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY api_brand_id (api_brand_id)
        ) $charset_collate;";

        dbDelta( $sql );
        dbDelta( $brands_sql );
        $this->ensure_brands_external_id_index();

        error_log( 'DataFlair: ensure_tables_exist() ran dbDelta — tables were missing.' );
    }
    
    /**
     * Upgrade database schema
     */
    private function upgrade_database() {
        global $wpdb;
        $table_name             = $wpdb->prefix . DATAFLAIR_TABLE_NAME;
        $brands_table_name      = $wpdb->prefix . DATAFLAIR_BRANDS_TABLE_NAME;
        $charset_collate        = $wpdb->get_charset_collate();

        // ── Toplists table: add snapshot + integrity columns (v1.5) ──
        $tl_table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'") === $table_name;
        if ($tl_table_exists) {
            $tl_columns = $wpdb->get_col("DESCRIBE $table_name");

            if (!in_array('slug', $tl_columns)) {
                $wpdb->query("ALTER TABLE $table_name ADD COLUMN slug VARCHAR(255) DEFAULT NULL AFTER name");
            }
            if (!in_array('current_period', $tl_columns)) {
                $wpdb->query("ALTER TABLE $table_name ADD COLUMN current_period VARCHAR(100) DEFAULT NULL AFTER slug");
            } else {
                // v1.6: widen current_period from VARCHAR(7) to VARCHAR(100) for edition labels
                $wpdb->query("ALTER TABLE $table_name MODIFY COLUMN current_period VARCHAR(100) DEFAULT NULL");
            }
            if (!in_array('published_at', $tl_columns)) {
                $wpdb->query("ALTER TABLE $table_name ADD COLUMN published_at DATETIME DEFAULT NULL AFTER current_period");
            }
            if (!in_array('item_count', $tl_columns)) {
                $wpdb->query("ALTER TABLE $table_name ADD COLUMN item_count INT DEFAULT 0 AFTER published_at");
            }
            if (!in_array('locked_count', $tl_columns)) {
                $wpdb->query("ALTER TABLE $table_name ADD COLUMN locked_count INT DEFAULT 0 AFTER item_count");
            }
            if (!in_array('sync_warnings', $tl_columns)) {
                $wpdb->query("ALTER TABLE $table_name ADD COLUMN sync_warnings TEXT DEFAULT NULL AFTER locked_count");
            }

            // Add slug index if it doesn't already exist
            $idx = $wpdb->get_results("SHOW INDEX FROM $table_name WHERE Key_name = 'idx_slug'");
            if (empty($idx)) {
                $wpdb->query("CREATE INDEX idx_slug ON $table_name (slug)");
            }

            error_log('DataFlair: Toplists table upgraded to v1.5 (snapshot + integrity columns)');
        }

        // ── Brands table: add columns if missing (v1.2 compat) ──
        $brands_table_exists = $wpdb->get_var("SHOW TABLES LIKE '$brands_table_name'") === $brands_table_name;

        if ($brands_table_exists) {
            $columns = $wpdb->get_col("DESCRIBE $brands_table_name");

            if (!in_array('product_types', $columns)) {
                $wpdb->query("ALTER TABLE $brands_table_name ADD COLUMN product_types text AFTER status");
            }
            if (!in_array('licenses', $columns)) {
                $wpdb->query("ALTER TABLE $brands_table_name ADD COLUMN licenses text AFTER product_types");
            }
            if (!in_array('top_geos', $columns)) {
                $wpdb->query("ALTER TABLE $brands_table_name ADD COLUMN top_geos text AFTER licenses");
            }
            if (!in_array('offers_count', $columns)) {
                $wpdb->query("ALTER TABLE $brands_table_name ADD COLUMN offers_count int(11) DEFAULT 0 AFTER top_geos");
            }
            if (!in_array('trackers_count', $columns)) {
                $wpdb->query("ALTER TABLE $brands_table_name ADD COLUMN trackers_count int(11) DEFAULT 0 AFTER offers_count");
            }

            if (!in_array('classification_types', $columns)) {
                $wpdb->query("ALTER TABLE $brands_table_name ADD COLUMN classification_types VARCHAR(500) NOT NULL DEFAULT '' AFTER trackers_count");
            }

            error_log('DataFlair: Database schema upgraded to version 1.5');
        } else {
            // Table doesn't exist, create it with full schema
            $brands_sql = "CREATE TABLE IF NOT EXISTS $brands_table_name (
                id bigint(20) NOT NULL AUTO_INCREMENT,
                api_brand_id bigint(20) NOT NULL,
                name varchar(255) NOT NULL,
                slug varchar(255) NOT NULL,
                status varchar(50) NOT NULL,
                product_types text,
                licenses text,
                top_geos text,
                offers_count int(11) DEFAULT 0,
                trackers_count int(11) DEFAULT 0,
                classification_types VARCHAR(500) NOT NULL DEFAULT '',
                review_url_override VARCHAR(500) DEFAULT NULL,
                local_logo_url VARCHAR(500) DEFAULT NULL,
                cached_review_post_id BIGINT(20) UNSIGNED DEFAULT NULL,
                data longtext NOT NULL,
                last_synced datetime NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY api_brand_id (api_brand_id)
            ) $charset_collate;";

            require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
            dbDelta($brands_sql);

            error_log('DataFlair: Brands table created with full schema');
        }

        // ── Brands table: v1.8 — add review_url_override column ──
        $brands_table_exists_v18 = $wpdb->get_var("SHOW TABLES LIKE '$brands_table_name'") === $brands_table_name;
        if ($brands_table_exists_v18) {
            $col_exists = $wpdb->get_var("SHOW COLUMNS FROM $brands_table_name LIKE 'review_url_override'");
            if (!$col_exists) {
                $wpdb->query("ALTER TABLE $brands_table_name ADD COLUMN review_url_override VARCHAR(500) DEFAULT NULL");
                error_log('DataFlair: Brands table upgraded to v1.8 (review_url_override column added)');
            }
        }

        // ── Brands table: v1.10 — add local_logo_url + cached_review_post_id columns (Phase 0A H0) ──
        // Pre-computed values populated at sync time so render_casino_card() never has to
        // sideload a logo or look up a review CPT on a cold page view (Sigma OOM root cause).
        $brands_table_exists_v110 = $wpdb->get_var("SHOW TABLES LIKE '$brands_table_name'") === $brands_table_name;
        if ($brands_table_exists_v110) {
            $local_logo_url_exists = $wpdb->get_var("SHOW COLUMNS FROM $brands_table_name LIKE 'local_logo_url'");
            if (!$local_logo_url_exists) {
                $wpdb->query("ALTER TABLE $brands_table_name ADD COLUMN local_logo_url VARCHAR(500) DEFAULT NULL");
                error_log('DataFlair: Brands table upgraded to v1.10 (local_logo_url column added)');
            }

            $cached_review_post_id_exists = $wpdb->get_var("SHOW COLUMNS FROM $brands_table_name LIKE 'cached_review_post_id'");
            if (!$cached_review_post_id_exists) {
                $wpdb->query("ALTER TABLE $brands_table_name ADD COLUMN cached_review_post_id BIGINT(20) UNSIGNED DEFAULT NULL");
                error_log('DataFlair: Brands table upgraded to v1.10 (cached_review_post_id column added)');
            }
        }

        // Ensure externalId generated column/index exists on brands table.
        $this->ensure_brands_external_id_index();

        // Check if alternative toplists table exists, create if not
        $this->ensure_alternative_toplists_table();
    }

    /**
     * Ensure brands table has a generated externalId column + index for JSON filtering.
     *
     * Creates:
     *  - external_id_virtual VARCHAR(50) GENERATED ALWAYS AS (JSON_UNQUOTE(JSON_EXTRACT(data, '$.externalId'))) STORED
     *  - idx_external_id_virtual index
     */
    private function ensure_brands_external_id_index() {
        global $wpdb;
        $brands_table_name = $wpdb->prefix . DATAFLAIR_BRANDS_TABLE_NAME;

        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$brands_table_name'") === $brands_table_name;
        if (!$table_exists) {
            return;
        }

        // 1) Add generated column if missing.
        $col_exists = $wpdb->get_var("SHOW COLUMNS FROM $brands_table_name LIKE 'external_id_virtual'");
        if (!$col_exists) {
            $wpdb->query(
                "ALTER TABLE $brands_table_name
                 ADD COLUMN external_id_virtual VARCHAR(50)
                 GENERATED ALWAYS AS (JSON_UNQUOTE(JSON_EXTRACT(data, '$.externalId'))) STORED"
            );
            if ($wpdb->last_error) {
                error_log('DataFlair: Failed adding external_id_virtual column: ' . $wpdb->last_error);
            }
        }

        // 2) Add index if missing.
        $idx_exists = $wpdb->get_var("SHOW INDEX FROM $brands_table_name WHERE Key_name = 'idx_external_id_virtual'");
        if (!$idx_exists) {
            $wpdb->query("CREATE INDEX idx_external_id_virtual ON $brands_table_name (external_id_virtual)");
            if ($wpdb->last_error) {
                error_log('DataFlair: Failed creating idx_external_id_virtual index: ' . $wpdb->last_error);
            }
        }
    }
    
    /**
     * Ensure alternative toplists table exists
     */
    private function ensure_alternative_toplists_table() {
        global $wpdb;
        $alternative_toplists_table = $wpdb->prefix . DATAFLAIR_ALTERNATIVE_TOPLISTS_TABLE_NAME;
        $charset_collate = $wpdb->get_charset_collate();
        
        $alt_table_exists = $wpdb->get_var("SHOW TABLES LIKE '$alternative_toplists_table'") === $alternative_toplists_table;
        
        if (!$alt_table_exists) {
            $alternative_toplists_sql = "CREATE TABLE IF NOT EXISTS $alternative_toplists_table (
                id bigint(20) NOT NULL AUTO_INCREMENT,
                toplist_id bigint(20) NOT NULL,
                geo varchar(255) NOT NULL,
                alternative_toplist_id bigint(20) NOT NULL,
                created_at datetime NOT NULL,
                updated_at datetime NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY toplist_geo (toplist_id, geo),
                KEY toplist_id (toplist_id),
                KEY alternative_toplist_id (alternative_toplist_id)
            ) $charset_collate;";
            
            require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
            dbDelta($alternative_toplists_sql);
            
            error_log('DataFlair: Alternative toplists table created');
        }
    }
    
    // NOTE: add_custom_cron_schedules() and ensure_cron_scheduled() were
    // removed in v1.11.0 (Phase 0B H1). Auto-sync cron is gone — sync now
    // runs only when an operator triggers it from the admin Tools page or
    // via WP-CLI. Legacy cron events are cleared once at upgrade time by
    // the `dataflair_cron_cleared_v1_11` gate in upgrade_database().

    /**
     * Add admin menu
     */
    public function add_admin_menu() {
        add_menu_page(
            'DataFlair',
            'DataFlair',
            'manage_options',
            'dataflair-toplists',
            array($this, 'settings_page'),
            'dashicons-list-view',
            30
        );
        
        // Add Toplists submenu (this will replace the default first submenu item)
        add_submenu_page(
            'dataflair-toplists',
            'Toplists',
            'Toplists',
            'manage_options',
            'dataflair-toplists',
            array($this, 'settings_page')
        );
        
        add_submenu_page(
            'dataflair-toplists',
            'Brands',
            'Brands',
            'manage_options',
            'dataflair-brands',
            array($this, 'brands_page')
        );
        
        // Add Tests submenu (only for development)
        if (defined('WP_DEBUG') && WP_DEBUG) {
            add_submenu_page(
                'dataflair-toplists',
                'Tests',
                'Tests',
                'manage_options',
                'dataflair-tests',
                array($this, 'tests_page')
            );
        }
        
    }
    
    /**
     * Tests page
     *
     * @codeCoverageIgnore
     */
    public function tests_page() {
        $test_file = isset($_GET['test']) ? sanitize_text_field($_GET['test']) : 'all';
        $tests_dir = DATAFLAIR_PLUGIN_DIR . 'tests/';
        
        ?>
        <div class="wrap">
            <h1>🧪 Dataflair Plugin Tests</h1>
            <p>Run tests to verify plugin functionality.</p>
            
            <div style="margin: 20px 0;">
                <a href="<?php echo admin_url('admin.php?page=dataflair-tests&test=all'); ?>" class="button button-primary">Run All Tests</a>
                <a href="<?php echo admin_url('admin.php?page=dataflair-tests&test=logo'); ?>" class="button">Logo Download Test</a>
                <a href="<?php echo admin_url('admin.php?page=dataflair-tests&test=logo-url'); ?>" class="button">Logo URL Structure Test</a>
                <a href="<?php echo admin_url('admin.php?page=dataflair-tests&test=brand'); ?>" class="button">Brand Data Test</a>
                <a href="<?php echo admin_url('admin.php?page=dataflair-tests&test=toplist'); ?>" class="button">Toplist Fetch Test</a>
                <a href="<?php echo admin_url('admin.php?page=dataflair-tests&test=toplist-render'); ?>" class="button">Toplist Render Test</a>
                <a href="<?php echo admin_url('admin.php?page=dataflair-tests&test=api-edge'); ?>" class="button">API Edge Cases Test</a>
                <a href="<?php echo admin_url('admin.php?page=dataflair-tests&test=cron'); ?>" class="button">Cron Jobs Test</a>
            </div>
            
            <hr>
            
            <?php
            if ($test_file === 'all') {
                include $tests_dir . 'run-all-tests.php';
            } elseif ($test_file === 'logo') {
                include $tests_dir . 'test-logo-download.php';
            } elseif ($test_file === 'logo-url') {
                include $tests_dir . 'test-logo-url.php';
            } elseif ($test_file === 'brand') {
                include $tests_dir . 'test-brand-data.php';
            } elseif ($test_file === 'toplist') {
                include $tests_dir . 'test-toplist-fetch.php';
            } elseif ($test_file === 'toplist-render') {
                include $tests_dir . 'test-toplist-render.php';
            } elseif ($test_file === 'api-edge') {
                include $tests_dir . 'test-api-edge-cases.php';
            } elseif ($test_file === 'cron') {
                include $tests_dir . 'test-cron.php';
            } else {
                echo '<p>Invalid test selected.</p>';
            }
            ?>
        </div>
        <?php
    }
    
    /**
     * Register settings
     */
    public function register_settings() {
        $args = array(
            'sanitize_callback' => null,
            'default' => null,
        );
        
        register_setting('dataflair_settings', 'dataflair_api_token', $args);
        register_setting('dataflair_settings', 'dataflair_api_base_url', $args);
        // Keep dataflair_api_endpoints for internal use (populated by fetch_all_toplists)
        register_setting('dataflair_settings', 'dataflair_api_endpoints', $args);
        // API base URL - can be auto-detected or manually set
        register_setting('dataflair_settings', 'dataflair_api_base_url', $args);

        // HTTP Basic Auth for staging/protected environments
        register_setting('dataflair_settings', 'dataflair_http_auth_user', $args);
        register_setting('dataflair_settings', 'dataflair_http_auth_pass', $args);

        // Default customization settings
        register_setting('dataflair_settings', 'dataflair_ribbon_bg_color', $args);
        register_setting('dataflair_settings', 'dataflair_ribbon_text_color', $args);
        register_setting('dataflair_settings', 'dataflair_cta_bg_color', $args);
        register_setting('dataflair_settings', 'dataflair_cta_text_color', $args);
        
    }
    
    /**
     * AJAX save settings handler
     */
    public function ajax_save_settings() {
        check_ajax_referer('dataflair_save_settings', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Unauthorized'));
        }
        
        // Save API settings
        if (isset($_POST['dataflair_api_token'])) {
            // Trim whitespace and preserve token value - don't use sanitize_text_field as it may modify special characters
            $token = trim($_POST['dataflair_api_token']);
            update_option('dataflair_api_token', $token);
        }
        
        // Save API base URL if manually set
        if (isset($_POST['dataflair_api_base_url'])) {
            $base_url = trim($_POST['dataflair_api_base_url']);
            if (!empty($base_url)) {
                // Clean the URL: remove trailing slashes, sanitize
                $base_url = rtrim(esc_url_raw($base_url), '/');
                // Strip any path segments after /api/v1 (e.g. /api/v1/toplists → /api/v1)
                $base_url = preg_replace('#(/api/v\d+)/.*$#', '$1', $base_url);
                update_option('dataflair_api_base_url', $base_url);
            } else {
                // Empty = clear the stored value so auto-detect kicks in
                delete_option('dataflair_api_base_url');
            }
        }

        // Save HTTP Basic Auth credentials (for staging environments)
        if (isset($_POST['dataflair_http_auth_user'])) {
            update_option('dataflair_http_auth_user', sanitize_text_field($_POST['dataflair_http_auth_user']));
        }
        if (isset($_POST['dataflair_http_auth_pass'])) {
            // Don't sanitize password — it may contain special characters
            update_option('dataflair_http_auth_pass', trim($_POST['dataflair_http_auth_pass']));
        }

        // Save Brands API version (v1 or v2 only — default v1 for safety)
        $version = isset($_POST['dataflair_brands_api_version'])
            && $_POST['dataflair_brands_api_version'] === 'v2' ? 'v2' : 'v1';
        update_option('dataflair_brands_api_version', $version);

        // Save customization settings
        if (isset($_POST['dataflair_ribbon_bg_color'])) {
            update_option('dataflair_ribbon_bg_color', sanitize_text_field($_POST['dataflair_ribbon_bg_color']));
        }
        
        if (isset($_POST['dataflair_ribbon_text_color'])) {
            update_option('dataflair_ribbon_text_color', sanitize_text_field($_POST['dataflair_ribbon_text_color']));
        }
        
        if (isset($_POST['dataflair_cta_bg_color'])) {
            update_option('dataflair_cta_bg_color', sanitize_text_field($_POST['dataflair_cta_bg_color']));
        }
        
        if (isset($_POST['dataflair_cta_text_color'])) {
            update_option('dataflair_cta_text_color', sanitize_text_field($_POST['dataflair_cta_text_color']));
        }
        
        wp_send_json_success(array('message' => 'Settings saved successfully.'));
    }
    
    /**
     * Enqueue admin scripts
     */
    public function enqueue_admin_scripts($hook) {
        // Only load on our settings pages
        if ($hook !== 'toplevel_page_dataflair-toplists' && $hook !== 'dataflair_page_dataflair-brands') {
            return;
        }
        
        // Enqueue Select2 CSS and JS (brands page + toplists page)
        if ($hook === 'dataflair_page_dataflair-brands' || $hook === 'toplevel_page_dataflair-toplists') {
            // Select2 CSS
            wp_enqueue_style(
                'select2',
                'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css',
                array(),
                '4.1.0'
            );
            
            // Select2 JS
            wp_enqueue_script(
                'select2',
                'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js',
                array('jquery'),
                '4.1.0',
                true
            );
        }
        
        // Use file modification time as version to prevent caching issues
        $admin_js_version = file_exists(DATAFLAIR_PLUGIN_DIR . 'assets/admin.js') 
            ? filemtime(DATAFLAIR_PLUGIN_DIR . 'assets/admin.js') 
            : DATAFLAIR_VERSION;
        
        wp_enqueue_script(
            'dataflair-admin',
            DATAFLAIR_PLUGIN_URL . 'assets/admin.js',
            array('jquery'),
            $admin_js_version,
            true
        );
        
        wp_localize_script('dataflair-admin', 'dataflairAdmin', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('dataflair_save_settings'),
            'fetchNonce' => wp_create_nonce('dataflair_fetch_all_toplists'),
            'syncToplistsBatchNonce' => wp_create_nonce('dataflair_sync_toplists_batch'),
            'fetchBrandsNonce' => wp_create_nonce('dataflair_fetch_all_brands'),
            'syncBrandsBatchNonce' => wp_create_nonce('dataflair_sync_brands_batch')
        ));
    }
    
    /**
     * Settings page HTML
     *
     * @codeCoverageIgnore
     */
    public function settings_page() {
        global $wpdb;
        $table_name = $wpdb->prefix . DATAFLAIR_TABLE_NAME;

        // Phase 0B H6: project lean columns + JSON-extract the one data path
        // the settings list needs (template.name) so we never pull the full
        // payload blob. Prior `SELECT *` could page-fault the whole toplist
        // table into memory whenever an admin clicked Settings.
        $toplists = $wpdb->get_results(
            "SELECT id, api_toplist_id, name, slug, version, status,
                    last_synced, item_count, locked_count, sync_warnings,
                    current_period,
                    JSON_UNQUOTE(JSON_EXTRACT(data, '$.data.template.name')) AS template_name
             FROM $table_name
             ORDER BY api_toplist_id ASC"
        );
        
        // Get current tab from URL
        $current_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'api';
        
        ?>
        <div class="wrap">
            <h1>DataFlair Toplists Settings</h1>
            
            <?php settings_errors(); ?>
            
            <nav class="nav-tab-wrapper">
                <a href="?page=dataflair-toplists&tab=api" class="nav-tab <?php echo $current_tab === 'api' ? 'nav-tab-active' : ''; ?>">
                    API Settings
                </a>
                <a href="?page=dataflair-toplists&tab=customizations" class="nav-tab <?php echo $current_tab === 'customizations' ? 'nav-tab-active' : ''; ?>">
                    Customizations
                </a>
                <a href="?page=dataflair-toplists&tab=api_preview" class="nav-tab <?php echo $current_tab === 'api_preview' ? 'nav-tab-active' : ''; ?>">
                    API Preview
                </a>
            </nav>
            
            <form id="dataflair-settings-form">
                
                <?php if ($current_tab === 'api'): ?>
                    <!-- API Settings Tab -->
                    <style>
                        #dataflair_api_base_url::placeholder {
                            color: #d3d3d3;
                        }
                        #dataflair_api_base_url::-webkit-input-placeholder {
                            color: #d3d3d3;
                        }
                        #dataflair_api_base_url::-moz-placeholder {
                            color: #d3d3d3;
                            opacity: 1;
                        }
                        #dataflair_api_base_url:-ms-input-placeholder {
                            color: #d3d3d3;
                        }
                    </style>
                    <div class="tab-content">
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="dataflair_api_token">API Bearer Token</label>
                        </th>
                        <td>
                            <input type="text" 
                                   id="dataflair_api_token" 
                                   name="dataflair_api_token" 
                                   value="<?php echo esc_attr(get_option('dataflair_api_token')); ?>" 
                                   class="regular-text">
                            <p class="description">Your DataFlair API bearer token</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="dataflair_api_base_url">API Base URL</label>
                        </th>
                        <td>
                            <input type="text"
                                   id="dataflair_api_base_url"
                                   name="dataflair_api_base_url"
                                   value="<?php echo esc_attr(get_option('dataflair_api_base_url', '')); ?>"
                                   class="regular-text"
                                   placeholder="https://tenant.dataflair.ai/api/v1">
                            <p class="description">
                                Your DataFlair API base URL (e.g., https://tenant.dataflair.ai/api/v1).
                                Leave empty to auto-detect from token or stored endpoints.
                                <?php
                                $current_base = get_option('dataflair_api_base_url');
                                if (!empty($current_base)) {
                                    echo '<br><strong>Current: ' . esc_html($current_base) . '</strong>';
                                }
                                ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Brands API Version</th>
                        <td>
                            <label>
                                <input type="radio" name="dataflair_brands_api_version"
                                       value="v1" <?php checked(get_option('dataflair_brands_api_version','v1'), 'v1'); ?>>
                                V1 <span style="color:#646970;">(default)</span>
                            </label>
                            &nbsp;&nbsp;
                            <label>
                                <input type="radio" name="dataflair_brands_api_version"
                                       value="v2" <?php checked(get_option('dataflair_brands_api_version','v1'), 'v2'); ?>>
                                V2
                            </label>
                            <p class="description">
                                V2 includes classificationTypes, 15 multi-vertical brand fields
                                (sports, poker, sweeps-coins) and unified offer types.
                                Requires DataFlair API &ge; v2.
                            </p>
                        </td>
                    </tr>
                </table>

                <?php submit_button('Save Settings', 'primary', 'submit', false, array('id' => 'dataflair-save-settings')); ?>
                <span id="dataflair-save-message" style="margin-left: 10px;"></span>
                    </div>

            <hr>

            <h2>Sync Toplists</h2>
                    <button type="button" id="dataflair-fetch-all-toplists" class="button button-primary">
                        Fetch All Toplists from API
                    </button>
                    <span id="dataflair-fetch-message" style="margin-left: 10px;"></span>
                    <div id="dataflair-toplist-sync-progress" style="display: none; margin-top: 15px; max-width: 400px; background: #f0f0f1; border-radius: 3px; height: 20px; overflow: hidden; position: relative;">
                        <div id="dataflair-toplist-progress-bar" style="background: #2271b1; width: 0%; height: 100%; transition: width 0.3s ease;"></div>
                        <div id="dataflair-toplist-progress-text" style="position: absolute; top: 0; left: 0; width: 100%; text-align: center; color: #fff; font-size: 12px; line-height: 20px; font-weight: 600; text-shadow: 0 0 2px rgba(0,0,0,0.5);">0%</div>
                    </div>
                    <p class="description">Fetches all toplists from the DataFlair API. Existing toplists will be updated.</p>
                    <p class="description">Sync runs only when triggered here or via WP-CLI. <?php echo esc_html($this->format_last_sync_label('dataflair_last_toplists_sync')); ?></p>
            
            <hr>
            
            <h2>Synced Toplists</h2>
            <?php if ($toplists): ?>
                <?php
                // Collect unique template names for the filter dropdown
                $all_templates = array();
                foreach ($toplists as $tl) {
                    $tl_data = json_decode($tl->data, true);
                    $tname = isset($tl_data['data']['template']['name']) ? $tl_data['data']['template']['name'] : '';
                    if ($tname) $all_templates[] = $tname;
                }
                $all_templates = array_values(array_unique($all_templates));
                sort($all_templates);
                ?>

                <!-- Template filter -->
                <div class="dataflair-filters" style="margin-bottom: 16px;">
                    <div class="filter-row">
                        <div class="filter-group" style="max-width: 320px;">
                            <label style="font-weight:600;">Template</label>
                            <select id="dataflair-filter-template" class="dataflair-toplist-select2" style="width:100%;">
                                <?php foreach ($all_templates as $tname): ?>
                                    <option value="<?php echo esc_attr($tname); ?>"><?php echo esc_html($tname); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="filter-group filter-actions" style="flex:0; align-self:flex-end;">
                            <button type="button" id="dataflair-clear-toplist-filters" class="button">Clear</button>
                        </div>
                        <div class="filter-group" style="flex:0; align-self:flex-end; white-space:nowrap;">
                            <span id="dataflair-toplists-count" style="color:#646970;">Showing <?php echo count($toplists); ?> toplists</span>
                        </div>
                    </div>
                </div>

                <table class="wp-list-table widefat fixed striped dataflair-toplists-table">
                    <thead>
                        <tr>
                            <th style="width: 40px;"></th>
                            <th>WP ID</th>
                            <th>API ID</th>
                            <th style="width: 40%;">Name</th>
                            <th class="sortable-toplist">
                                <a href="#" class="toplist-sort-link" data-sort="template">Template <span class="toplist-sort-indicator"></span></a>
                            </th>
                            <th>Period</th>
                            <th>Version</th>
                            <th class="sortable-toplist">
                                <a href="#" class="toplist-sort-link" data-sort="items">Items <span class="toplist-sort-indicator"></span></a>
                            </th>
                            <th class="sortable-toplist">
                                <a href="#" class="toplist-sort-link" data-sort="last_synced">Last Synced <span class="toplist-sort-indicator"></span></a>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($toplists as $toplist):
                            // Phase 0B H6: template_name is JSON-extracted in SELECT; $toplist->data no longer populated.
                            $template_name = isset($toplist->template_name) ? (string) $toplist->template_name : '';

                            // Prefer extracted columns; fall back to 0 if legacy rows predate the extracted columns.
                            $items_count  = isset($toplist->item_count)   ? (int) $toplist->item_count   : 0;
                            $locked_count = isset($toplist->locked_count) ? (int) $toplist->locked_count : 0;

                            // Sync health
                            $sync_warnings_raw  = isset($toplist->sync_warnings) ? $toplist->sync_warnings : null;
                            $sync_warnings_arr  = (!empty($sync_warnings_raw)) ? json_decode($sync_warnings_raw, true) : [];
                            $warning_count      = is_array($sync_warnings_arr) ? count($sync_warnings_arr) : 0;
                            $last_synced_ts     = $toplist->last_synced ? strtotime($toplist->last_synced) : 0;
                            $is_stale           = $last_synced_ts && (time() - $last_synced_ts) > 3600;

                            if ($is_stale) {
                                $health_html = '<span style="color:#d63638;" title="Last sync was over 1 hour ago">&#128308; Stale</span>';
                            } elseif ($warning_count > 0) {
                                $health_html = '<a href="#" class="toplist-warnings-toggle" data-toplist-id="' . esc_attr($toplist->id) . '" style="color:#dba617; text-decoration:none;" title="Click to view warnings">&#9888;&#65039; ' . $warning_count . ' warning' . ($warning_count !== 1 ? 's' : '') . '</a>';
                            } else {
                                $health_html = '<span style="color:#00a32a;" title="All data validated OK">&#9989;</span>';
                            }
                        ?>
                        <tr class="toplist-row"
                            data-toplist-id="<?php echo esc_attr($toplist->id); ?>"
                            data-template="<?php echo esc_attr($template_name); ?>"
                            data-items="<?php echo esc_attr($items_count); ?>"
                            data-last-synced="<?php echo esc_attr($toplist->last_synced); ?>">
                            <td>
                                <button type="button" class="toplist-toggle-btn" title="View Details">
                                    <span class="dashicons dashicons-arrow-right"></span>
                                </button>
                            </td>
                            <td><?php echo esc_html($toplist->id); ?></td>
                            <td><?php echo esc_html($toplist->api_toplist_id); ?></td>
                            <td><?php echo esc_html($toplist->name); ?></td>
                            <td><?php echo esc_html($template_name ?: '—'); ?></td>
                            <td><?php echo isset($toplist->current_period) && !empty($toplist->current_period) ? esc_html($toplist->current_period) : '<span style="color:#999;">—</span>'; ?></td>
                            <td><?php echo esc_html($toplist->version); ?></td>
                            <td><?php echo esc_html($items_count); ?></td>
                            <td><?php echo esc_html($toplist->last_synced); ?></td>
                        </tr>
                        <?php if ($warning_count > 0): ?>
                        <tr class="toplist-warnings-row" id="warnings-<?php echo esc_attr($toplist->id); ?>" style="display:none;">
                            <td colspan="9" style="padding: 0;">
                                <div style="padding: 12px 20px; background: #fff8e5; border-left: 4px solid #dba617;">
                                    <strong style="color:#dba617;">&#9888;&#65039; Sync warnings for <?php echo esc_html($toplist->name); ?>:</strong>
                                    <ul style="margin: 8px 0 0 20px; padding: 0;">
                                        <?php foreach ($sync_warnings_arr as $w): ?>
                                        <li style="font-family: monospace; font-size: 12px; margin: 2px 0;"><?php echo esc_html($w); ?></li>
                                        <?php endforeach; ?>
                                    </ul>
                                </div>
                            </td>
                        </tr>
                        <?php endif; ?>
                        <tr class="toplist-accordion-content" data-toplist-id="<?php echo esc_attr($toplist->id); ?>" style="display: none;">
                            <td colspan="9" style="padding: 0;">
                                <div class="toplist-accordion-inner" style="padding: 20px; background: #f9f9f9; border-left: 4px solid #0073aa;">
                                    <h3 style="margin-top: 0;">Alternative Toplists for Different Geos</h3>
                                    <p class="description">Set alternative toplists to show when a user from a specific geo visits a page where this toplist is displayed.</p>
                                    
                                    <div class="alternative-toplists-list"></div>
                                    
                                    <div class="add-alternative-toplist" style="margin-top: 20px; padding: 15px; background: white; border: 1px solid #ddd;">
                                        <h4 style="margin-top: 0;">Add Alternative Toplist</h4>
                                        <table class="form-table" style="margin: 0;">
                                            <tr>
                                                <th scope="row"><label>Geo / Market</label></th>
                                                <td>
                                                    <select class="alt-geo-select" style="min-width: 200px;">
                                                        <option value="">Select a geo...</option>
                                                    </select>
                                                    <p class="description">Select the geo/market for which to show an alternative toplist.</p>
                                                </td>
                                            </tr>
                                            <tr>
                                                <th scope="row"><label>Alternative Toplist</label></th>
                                                <td>
                                                    <select class="alt-toplist-select" style="min-width: 300px;">
                                                        <option value="">Select a toplist...</option>
                                                        <?php foreach ($toplists as $alt_toplist): ?>
                                                            <option value="<?php echo esc_attr($alt_toplist->id); ?>">
                                                                <?php echo esc_html($alt_toplist->name); ?> (ID: <?php echo esc_html($alt_toplist->api_toplist_id); ?>)
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                    <p class="description">Select which toplist to show for users from the selected geo.</p>
                                                </td>
                                            </tr>
                                        </table>
                                        <button type="button" class="button button-primary save-alternative-toplist">Add Alternative</button>
                                        <span class="alt-save-message" style="margin-left: 10px;"></span>
                                    </div>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p>No toplists synced yet. Click "Fetch All Toplists from API" to discover and sync available toplists.</p>
            <?php endif; ?>
            
            <hr>
            
            <h2>Shortcode Usage</h2>
            <p>Use the shortcode with these parameters:</p>
            <ul>
                <li><strong>id</strong> (required unless <em>slug</em> is used): API toplist ID</li>
                <li><strong>slug</strong> (optional): Toplist slug (alternative to id)</li>
                <li><strong>title</strong> (optional): Custom title for the toplist</li>
                <li><strong>limit</strong> (optional): Number of casinos to show (default: all)</li>
            </ul>
            <p>By ID: <code>[dataflair_toplist id="3" title="Best UK Casinos" limit="5"]</code></p>
            <p>By slug: <code>[dataflair_toplist slug="brazil-casinos"]</code></p>

            <script>
            jQuery(document).ready(function($) {
                $(document).on('click', '.toplist-warnings-toggle', function(e) {
                    e.preventDefault();
                    var id = $(this).data('toplist-id');
                    $('#warnings-' + id).toggle();
                });
            });
            </script>
                    
                <?php elseif ($current_tab === 'customizations'): ?>
                    <!-- Customizations Tab -->
                    <div class="tab-content">
                        <h2>Default Customization Options</h2>
                        <p class="description">These settings will be used as defaults for all toplist blocks. You can override them per block in the block settings. Use Tailwind CSS color classes (e.g., "brand-600", "blue-500", "bg-[#ff0000]").</p>
                        
                        <table class="form-table">
                            <tr>
                                <th scope="row">
                                    <label for="dataflair_ribbon_bg_color">Ribbon Background Color</label>
                                </th>
                                <td>
                                    <input type="text" 
                                           id="dataflair_ribbon_bg_color" 
                                           name="dataflair_ribbon_bg_color" 
                                           value="<?php echo esc_attr(get_option('dataflair_ribbon_bg_color', 'brand-600')); ?>" 
                                           class="regular-text"
                                           placeholder="brand-600">
                                    <p class="description">Default background color for the "Our Top Choice" ribbon (e.g., "brand-600", "blue-500", "bg-[#ff0000]")</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="dataflair_ribbon_text_color">Ribbon Text Color</label>
                                </th>
                                <td>
                                    <input type="text" 
                                           id="dataflair_ribbon_text_color" 
                                           name="dataflair_ribbon_text_color" 
                                           value="<?php echo esc_attr(get_option('dataflair_ribbon_text_color', 'white')); ?>" 
                                           class="regular-text"
                                           placeholder="white">
                                    <p class="description">Default text color for the ribbon (e.g., "white", "gray-900", "text-[#ffffff]")</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="dataflair_cta_bg_color">CTA Button Background Color</label>
                                </th>
                                <td>
                                    <input type="text" 
                                           id="dataflair_cta_bg_color" 
                                           name="dataflair_cta_bg_color" 
                                           value="<?php echo esc_attr(get_option('dataflair_cta_bg_color', 'brand-600')); ?>" 
                                           class="regular-text"
                                           placeholder="brand-600">
                                    <p class="description">Default background color for the "Visit Site" button (e.g., "brand-600", "green-500", "bg-[#00ff00]")</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="dataflair_cta_text_color">CTA Button Text Color</label>
                                </th>
                                <td>
                                    <input type="text" 
                                           id="dataflair_cta_text_color" 
                                           name="dataflair_cta_text_color" 
                                           value="<?php echo esc_attr(get_option('dataflair_cta_text_color', 'white')); ?>" 
                                           class="regular-text"
                                           placeholder="white">
                                    <p class="description">Default text color for the CTA button (e.g., "white", "gray-900", "text-[#ffffff]")</p>
                                </td>
                            </tr>
                        </table>
                        
                        <?php submit_button('Save Settings', 'primary', 'submit', false, array('id' => 'dataflair-save-settings-custom')); ?>
                        <span id="dataflair-save-message-custom" style="margin-left: 10px;"></span>
                    </div>
                <?php elseif ($current_tab === 'api_preview'): ?>
                    <!-- API Preview Tab -->
                    <div class="tab-content">
                        <h2>API Response Preview</h2>
                        <p class="description">Fetch a live response from the DataFlair API using your stored token. Select an endpoint and click <strong>Fetch Preview</strong> to inspect the raw JSON.</p>

                        <?php
                        $token = trim(get_option('dataflair_api_token', ''));
                        $base_url = $this->get_api_base_url();
                        if (empty($token)): ?>
                            <div class="notice notice-warning inline"><p>No API token configured. Set your token on the <a href="?page=dataflair-toplists&tab=api">API Settings</a> tab first.</p></div>
                        <?php else: ?>
                        <!-- Mode toggle -->
                        <div style="margin-bottom:16px;">
                            <label>
                                <input type="radio" name="df-preview-mode" value="single" checked> Single endpoint
                            </label>
                            &nbsp;&nbsp;
                            <label>
                                <input type="radio" name="df-preview-mode" value="compare"> Compare V1 vs V2
                            </label>
                        </div>
                        <!-- Single mode panel -->
                        <div id="df-single-panel">
                        <table class="form-table">
                            <tr>
                                <th scope="row"><label for="df-preview-endpoint">Endpoint</label></th>
                                <td>
                                    <select id="df-preview-endpoint" style="min-width:320px;">
                                        <option value="toplists">GET /toplists (list all)</option>
                                        <option value="toplists/custom">GET /toplists/{id} (single — enter ID below)</option>
                                        <option value="brands">GET /brands (list all)</option>
                                        <option value="brands/custom">GET /brands/{id} (single — enter ID below)</option>
                                    </select>
                                </td>
                            </tr>
                            <tr id="df-preview-id-row" style="display:none;">
                                <th scope="row"><label for="df-preview-id">Resource ID</label></th>
                                <td><input type="number" id="df-preview-id" class="small-text" placeholder="42"></td>
                            </tr>
                            <tr>
                                <th scope="row"></th>
                                <td>
                                    <button type="button" id="df-preview-fetch" class="button button-primary">Fetch Preview</button>
                                    <span id="df-preview-status" style="margin-left:10px;"></span>
                                </td>
                            </tr>
                        </table>
                        </div><!-- /#df-single-panel -->
                        <!-- Compare mode panel -->
                        <div id="df-compare-panel" style="display:none;">
                            <table class="form-table">
                                <tr>
                                    <th scope="row">Endpoint</th>
                                    <td>
                                        <select id="df-compare-endpoint">
                                            <option value="brands">brands</option>
                                        </select>
                                        <button type="button" id="df-compare-run" class="button button-primary">Run Comparison</button>
                                        <span id="df-compare-status" style="margin-left:10px;"></span>
                                    </td>
                                </tr>
                            </table>
                            <div id="df-compare-result" style="display:none; margin-top:16px;">
                                <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px;">
                                    <div>
                                        <strong id="df-v1-label" style="font-family:monospace;font-size:12px;color:#666;"></strong>
                                        <pre id="df-v1-json" style="background:#1e1e1e;color:#d4d4d4;padding:16px;border-radius:4px;max-height:400px;overflow:auto;font-size:11px;white-space:pre-wrap;word-break:break-all;margin:6px 0;"></pre>
                                        <button type="button" class="button button-secondary button-small df-copy-btn" data-target="df-v1-json">Copy V1</button>
                                    </div>
                                    <div>
                                        <strong id="df-v2-label" style="font-family:monospace;font-size:12px;color:#666;"></strong>
                                        <pre id="df-v2-json" style="background:#1e1e1e;color:#d4d4d4;padding:16px;border-radius:4px;max-height:400px;overflow:auto;font-size:11px;white-space:pre-wrap;word-break:break-all;margin:6px 0;"></pre>
                                        <button type="button" class="button button-secondary button-small df-copy-btn" data-target="df-v2-json">Copy V2</button>
                                    </div>
                                </div>
                                <div id="df-compare-diff" style="margin-top:12px; padding:12px; background:#f0f7ff; border-left:4px solid #0073aa; font-size:12px;"></div>
                            </div>
                        </div><!-- /#df-compare-panel -->

                        <div id="df-preview-result" style="display:none;margin-top:16px;">
                            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;">
                                <strong id="df-preview-url-label" style="font-family:monospace;font-size:12px;color:#666;"></strong>
                                <button type="button" id="df-preview-copy" class="button button-secondary button-small">Copy JSON</button>
                            </div>
                            <pre id="df-preview-json" style="
                                background:#1e1e1e;
                                color:#d4d4d4;
                                padding:16px;
                                border-radius:4px;
                                max-height:600px;
                                overflow:auto;
                                font-size:12px;
                                line-height:1.5;
                                white-space:pre-wrap;
                                word-break:break-all;
                            "></pre>
                        </div>

                        <script>
                        (function($){
                            var nonce = '<?php echo wp_create_nonce('dataflair_api_preview'); ?>';
                            var $endpoint = $('#df-preview-endpoint');
                            var $idRow    = $('#df-preview-id-row');
                            var $idInput  = $('#df-preview-id');
                            var $fetch    = $('#df-preview-fetch');
                            var $status   = $('#df-preview-status');
                            var $result   = $('#df-preview-result');
                            var $json     = $('#df-preview-json');
                            var $urlLabel = $('#df-preview-url-label');
                            var $copy     = $('#df-preview-copy');

                            $endpoint.on('change', function(){
                                var v = $(this).val();
                                $idRow.toggle(v === 'toplists/custom' || v === 'brands/custom');
                            });

                            $fetch.on('click', function(){
                                var ep = $endpoint.val();
                                var resourceId = $idInput.val().trim();

                                if ((ep === 'toplists/custom' || ep === 'brands/custom') && !resourceId) {
                                    alert('Please enter a resource ID.');
                                    return;
                                }

                                $fetch.prop('disabled', true);
                                $status.text('Fetching…');
                                $result.hide();

                                $.post(ajaxurl, {
                                    action:      'dataflair_api_preview',
                                    _ajax_nonce: nonce,
                                    endpoint:    ep,
                                    resource_id: resourceId
                                }, function(res){
                                    $fetch.prop('disabled', false);
                                    if (res.success) {
                                        var elapsed = res.data.elapsed ? '  ' + res.data.elapsed : '';
                                        $status.css('color','green').text('✔ ' + res.data.status + elapsed);
                                        $urlLabel.text(res.data.url);
                                        $json.text(res.data.body);
                                        $result.show();
                                    } else {
                                        $status.css('color','red').text('✖ ' + (res.data || 'Unknown error'));
                                    }
                                }).fail(function(){
                                    $fetch.prop('disabled', false);
                                    $status.css('color','red').text('✖ AJAX request failed');
                                });
                            });

                            $copy.on('click', function(){
                                var text = $json.text();
                                navigator.clipboard.writeText(text).then(function(){
                                    $copy.text('Copied!');
                                    setTimeout(function(){ $copy.text('Copy JSON'); }, 2000);
                                });
                            });

                            // Mode toggle: single vs compare
                            $('input[name="df-preview-mode"]').on('change', function(){
                                var mode = $(this).val();
                                if (mode === 'compare') {
                                    $('#df-single-panel').hide();
                                    $('#df-compare-panel').show();
                                } else {
                                    $('#df-single-panel').show();
                                    $('#df-compare-panel').hide();
                                }
                            });

                            // Copy buttons in compare panel
                            $(document).on('click', '.df-copy-btn', function(){
                                var targetId = $(this).data('target');
                                var text = $('#' + targetId).text();
                                var $btn = $(this);
                                navigator.clipboard.writeText(text).then(function(){
                                    $btn.text('Copied!');
                                    setTimeout(function(){ $btn.text($btn.data('target') === 'df-v1-json' ? 'Copy V1' : 'Copy V2'); }, 2000);
                                });
                            });

                            // Run comparison
                            $('#df-compare-run').on('click', function(){
                                var $btn = $(this);
                                var $status = $('#df-compare-status');
                                $btn.prop('disabled', true);
                                $status.text('Fetching V1…');
                                $('#df-compare-result').hide();

                                // Call V1 (brands)
                                $.post(ajaxurl, {
                                    action: 'dataflair_api_preview',
                                    _ajax_nonce: nonce,
                                    endpoint: 'brands'
                                }, function(v1res){
                                    if (!v1res.success) {
                                        $status.css('color','red').text('✖ V1 failed: ' + (v1res.data || 'error'));
                                        $btn.prop('disabled', false);
                                        return;
                                    }
                                    $status.text('Fetching V2…');

                                    // Call V2 (brands_v2)
                                    $.post(ajaxurl, {
                                        action: 'dataflair_api_preview',
                                        _ajax_nonce: nonce,
                                        endpoint: 'brands_v2'
                                    }, function(v2res){
                                        $btn.prop('disabled', false);
                                        if (!v2res.success) {
                                            $status.css('color','red').text('✖ V2 failed: ' + (v2res.data || 'error'));
                                            return;
                                        }

                                        // Display results
                                        var v1elapsed = v1res.data.elapsed ? '  ' + v1res.data.elapsed : '';
                                        var v2elapsed = v2res.data.elapsed ? '  ' + v2res.data.elapsed : '';
                                        $('#df-v1-label').text('GET ' + v1res.data.url + '  ✔ ' + v1res.data.status + v1elapsed);
                                        $('#df-v2-label').text('GET ' + v2res.data.url + '  ✔ ' + v2res.data.status + v2elapsed);
                                        $('#df-v1-json').text(v1res.data.body);
                                        $('#df-v2-json').text(v2res.data.body);

                                        // Compute field diff
                                        var diffHtml = '';
                                        try {
                                            var v1data = JSON.parse(v1res.data.body);
                                            var v2data = JSON.parse(v2res.data.body);
                                            var v1brand = (v1data.data && v1data.data[0]) ? v1data.data[0] : null;
                                            var v2brand = (v2data.data && v2data.data[0]) ? v2data.data[0] : null;
                                            if (v1brand && v2brand) {
                                                var v1keys = Object.keys(v1brand);
                                                var v2keys = Object.keys(v2brand);
                                                var brandOnlyV2 = v2keys.filter(function(k){ return v1keys.indexOf(k) === -1; });
                                                diffHtml += '<strong>Fields only in V2 (brand):</strong> ' + (brandOnlyV2.length ? brandOnlyV2.join(', ') : 'none') + '<br>';

                                                var v1offer = (v1brand.offers && v1brand.offers[0]) ? v1brand.offers[0] : null;
                                                var v2offer = (v2brand.offers && v2brand.offers[0]) ? v2brand.offers[0] : null;
                                                if (v1offer && v2offer) {
                                                    var v1offerKeys = Object.keys(v1offer);
                                                    var v2offerKeys = Object.keys(v2offer);
                                                    var offerOnlyV2 = v2offerKeys.filter(function(k){ return v1offerKeys.indexOf(k) === -1; });
                                                    diffHtml += '<strong>Fields only in V2 (offer):</strong> ' + (offerOnlyV2.length ? offerOnlyV2.join(', ') : 'none');
                                                }
                                            }
                                        } catch(e) {
                                            diffHtml = 'Could not compute diff: ' + e.message;
                                        }
                                        $('#df-compare-diff').html(diffHtml || 'No differences found.');
                                        $('#df-compare-result').show();
                                        $status.css('color','green').text('✔ Comparison complete');
                                    }).fail(function(){
                                        $btn.prop('disabled', false);
                                        $status.css('color','red').text('✖ V2 AJAX request failed');
                                    });
                                }).fail(function(){
                                    $btn.prop('disabled', false);
                                    $status.css('color','red').text('✖ V1 AJAX request failed');
                                });
                            });
                        })(jQuery);
                        </script>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </form>

            <style>
                /* Toplist Accordion Styles */
                .dataflair-toplists-table th.sortable-toplist { padding: 0; }
                .toplist-sort-link {
                    display: flex;
                    align-items: center;
                    justify-content: space-between;
                    padding: 8px 10px;
                    text-decoration: none;
                    color: inherit;
                    white-space: nowrap;
                }
                .toplist-sort-link:hover { background: #f0f0f1; }
                .toplist-sort-indicator { font-size: 10px; margin-left: 4px; color: #2271b1; }
                .dataflair-toplists-table tbody tr.toplist-row { transition: opacity 0.15s ease; }
                .toplist-toggle-btn {
                    background: none;
                    border: none;
                    cursor: pointer;
                    padding: 4px;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    transition: all 0.2s;
                }
                .toplist-toggle-btn:hover {
                    background: #f0f0f1;
                    border-radius: 3px;
                }
                .toplist-toggle-btn .dashicons {
                    transition: transform 0.2s;
                }
                .toplist-accordion-inner {
                    animation: slideIn 0.2s ease-out;
                }
                @keyframes slideIn {
                    from {
                        opacity: 0;
                        transform: translateY(-10px);
                    }
                    to {
                        opacity: 1;
                        transform: translateY(0);
                    }
                }
            </style>
        </div>
        <?php
    }
    
    /**
     * Check if a URL points to a local development domain (no SSL).
     *
     * @param string $url The URL to check
     * @return bool True if local dev domain
     */
    private function is_local_url($url) {
        $parsed = parse_url($url);
        $host = isset($parsed['host']) ? $parsed['host'] : '';
        return (
            preg_match('/\.(test|local|localhost|invalid|example)$/i', $host) ||
            $host === 'localhost' ||
            $host === '127.0.0.1' ||
            $host === '::1'
        );
    }

    /**
     * Force HTTPS on a URL unless it's a local dev domain.
     * Production/staging servers redirect HTTP→HTTPS which strips Authorization headers.
     *
     * @param string $url The URL to upgrade
     * @return string The URL, with https:// if non-local
     */
    private function maybe_force_https($url) {
        if (!$this->is_local_url($url)) {
            $url = preg_replace('#^http://#i', 'https://', $url);
        }
        return $url;
    }

    /**
     * Get the brands API URL for the given page, respecting the selected API version.
     * Toplists always use v1 — only brands sync uses this helper.
     *
     * @param int $page Page number
     * @return string Full brands URL with page parameter
     */
    private function get_brands_api_url($page) {
        $version = get_option('dataflair_brands_api_version', 'v1');
        $base    = $this->get_api_base_url();  // e.g. .../api/v1
        if ($version === 'v2') {
            $base = preg_replace('#/api/v\d+$#', '/api/v2', $base);
        }
        return rtrim($base, '/') . '/brands?page=' . intval($page);
    }

    /**
     * Get API base URL - auto-detect from stored endpoints or use default
     *
     * @return string API base URL
     */
    private function get_api_base_url() {
        // First, try to get from stored option (manually set or auto-detected)
        $base_url = get_option('dataflair_api_base_url');
        if (!empty($base_url)) {
            $base_url = $this->maybe_force_https($base_url);
            // Safety: strip anything after /api/v1 (e.g. /api/v1/toplists → /api/v1)
            $base_url = preg_replace('#(/api/v\d+)/.*$#', '$1', $base_url);
            return rtrim($base_url, '/');
        }
        
        // Try to extract from stored endpoints
        $endpoints = get_option('dataflair_api_endpoints');
        if (!empty($endpoints)) {
            $endpoints_array = array_filter(array_map('trim', explode("\n", $endpoints)));
            if (!empty($endpoints_array)) {
                $first_endpoint = $endpoints_array[0];
                // Extract base URL from endpoint (e.g., https://tenant.dataflair.ai/api/v1/toplists/3)
                if (preg_match('#^(https?://[^/]+/api/v\d+)/#', $first_endpoint, $matches)) {
                    $base_url = $matches[1];
                    $base_url = $this->maybe_force_https($base_url);
                    // Store it for future use
                    update_option('dataflair_api_base_url', $base_url);
                    return rtrim($base_url, '/');
                }
            }
        }
        
        // Fallback to default
        return 'https://sigma.dataflair.ai/api/v1';
    }

    /**
     * Make an authenticated API GET request, handling both Bearer token and HTTP Basic Auth.
     *
     * @param string $url    The API endpoint URL
     * @param string $token  The Bearer token
     * @param int    $timeout Timeout in seconds
     * @return array|WP_Error The response
     */
    /**
     * GET a DataFlair API URL with retry + transient-failure handling.
     *
     * Phase 0B changes:
     *   - Default timeout lowered 30 → 12 s (H13). Shared hosts hit 30 s
     *     FastCGI limits; 12 s gives the caller time to bail cooperatively.
     *   - Response hard-capped at 15 MB via `limit_response_size` + stream-
     *     to-temp (H2). Oversized payloads return a WP_Error with code
     *     `dataflair_response_too_large` so callers can surface a clean
     *     structured error instead of OOMing on json_decode().
     *   - Optional WallClockBudget narrows the per-attempt timeout to
     *     whichever is smaller: the caller-supplied $timeout or budget
     *     remaining (H13). When budget is exhausted the call short-circuits
     *     with `dataflair_budget_exhausted`.
     *
     * @param string                                         $url
     * @param string                                         $token
     * @param int                                            $timeout       Seconds; default 12 (was 30 pre-0B).
     * @param int                                            $max_retries
     * @param \DataFlair\Toplists\Support\WallClockBudget|null $budget      Optional cooperative wall-clock budget.
     * @return array|\WP_Error                                              wp_remote_get-shaped array or WP_Error.
     */
    private function api_get($url, $token, $timeout = 12, $max_retries = 2, $budget = null) {
        // Phase 2 — delegate to the extracted HttpClientInterface. All
        // retry/backoff/size-cap/budget/telemetry logic now lives in
        // `DataFlair\Toplists\Http\ApiClient`. Return shape unchanged:
        // array|WP_Error, byte-identical to pre-Phase-2 callers.
        return $this->api_client()->get($url, $token, $timeout, $max_retries, $budget);
    }

    /**
     * Detect if WordPress is running inside a Docker container.
     *
     * @return bool
     */
    private function is_running_in_docker() {
        // Method 1: Check for .dockerenv file (most reliable)
        if (file_exists('/.dockerenv')) {
            return true;
        }
        // Method 2: Check cgroup (Linux containers)
        if (is_readable('/proc/1/cgroup')) {
            $cgroup = file_get_contents('/proc/1/cgroup');
            if (strpos($cgroup, 'docker') !== false || strpos($cgroup, 'kubepods') !== false) {
                return true;
            }
        }
        // Method 3: Check if host.docker.internal resolves (Docker Desktop for Mac/Windows)
        $resolved = gethostbyname('host.docker.internal');
        if ($resolved !== 'host.docker.internal') {
            return true;
        }
        return false;
    }

    /**
     * Build a detailed, human-readable error message for failed API responses.
     * Helps diagnose whether it's HTTP Basic Auth, Bearer token, server error, etc.
     *
     * @param int          $status_code     HTTP status code
     * @param string       $body            Response body
     * @param array|object $headers         Response headers
     * @param string       $url             The requested URL
     * @return string Detailed error message
     */
    private function build_detailed_api_error($status_code, $body, $headers, $url) {
        $parsed = parse_url($url);
        $host = isset($parsed['host']) ? $parsed['host'] : 'unknown';

        // Try to decode JSON body for structured error info
        $json = json_decode($body, true);
        $api_message = '';
        if (is_array($json) && isset($json['message'])) {
            $api_message = $json['message'];
        }

        // Check for WWW-Authenticate header — tells us if it's HTTP Basic Auth or Bearer
        $www_auth = '';
        if (is_object($headers) && isset($headers['www-authenticate'])) {
            $www_auth = $headers['www-authenticate'];
        } elseif (is_array($headers) && isset($headers['www-authenticate'])) {
            $www_auth = $headers['www-authenticate'];
        }

        // Check content-type to see if the response is HTML (web server error page) vs JSON (API error)
        $content_type = '';
        if (is_object($headers) && isset($headers['content-type'])) {
            $content_type = $headers['content-type'];
        } elseif (is_array($headers) && isset($headers['content-type'])) {
            $content_type = $headers['content-type'];
        }
        $is_html_response = (stripos($content_type, 'text/html') !== false);

        switch ($status_code) {
            case 401:
                // Distinguish between HTTP Basic Auth 401 and API Bearer 401
                if (stripos($www_auth, 'Basic') !== false) {
                    $has_http_auth = !empty(trim(get_option('dataflair_http_auth_user', '')));
                    if ($has_http_auth) {
                        return 'HTTP Basic Auth failed (401). Your staging username/password was rejected by the web server at ' . $host . '. '
                             . 'Check that the HTTP Auth Username and Password in plugin settings match your .htpasswd or nginx auth_basic credentials. '
                             . 'This is the web server blocking the request before it reaches the DataFlair API.';
                    } else {
                        return 'HTTP Basic Auth required (401). The server at ' . $host . ' requires HTTP Basic Authentication (e.g. .htpasswd). '
                             . 'This is common on staging environments. Go to DataFlair plugin settings and fill in the "HTTP Auth Username" and "HTTP Auth Password" fields. '
                             . 'These are your web server credentials — not your DataFlair API token.';
                    }
                } elseif (stripos($www_auth, 'Bearer') !== false || !empty($api_message)) {
                    return 'API authentication failed (401). The DataFlair API rejected your Bearer token. '
                         . 'API says: "' . ($api_message ?: 'Unauthenticated') . '". '
                         . 'Possible causes: (1) Token is expired or revoked — generate a new one in DataFlair > Configuration > API Credentials. '
                         . '(2) Token is an API Key (dfk_) instead of a Plugin Token (dfp_) — only dfp_ tokens work for this plugin. '
                         . '(3) Token was copy-pasted with extra spaces or line breaks — re-copy it carefully. '
                         . 'Token starts with: ' . substr(trim(get_option('dataflair_api_token', '')), 0, 10) . '...';
                } elseif ($is_html_response) {
                    return 'Authentication failed (401) — the server at ' . $host . ' returned an HTML page instead of a JSON API response. '
                         . 'This usually means the web server itself (nginx/Apache) is blocking the request before it reaches the DataFlair API. '
                         . 'Most likely cause: HTTP Basic Auth (.htpasswd) is enabled on staging. '
                         . 'Go to plugin settings and fill in the "HTTP Auth Username" and "HTTP Auth Password" fields.';
                } else {
                    return 'Authentication failed (401) at ' . $host . '. '
                         . 'Could not determine the specific cause. Response body: ' . substr($body, 0, 300) . '. '
                         . 'Check: (1) Is staging behind HTTP Basic Auth? Add credentials in plugin settings. '
                         . '(2) Is your dfp_ token valid and not expired? (3) Is the API Base URL correct?';
                }

            case 403:
                return 'Access forbidden (403). The server accepted your credentials but your token does not have permission to access this resource. '
                     . 'API says: "' . ($api_message ?: 'Forbidden') . '". '
                     . 'Check that your API credential in DataFlair has the correct permissions and is marked as active.';

            case 404:
                return 'Endpoint not found (404) at ' . $url . '. '
                     . 'This usually means the API Base URL is wrong or the route does not exist. '
                     . 'Expected format: https://tenant.dataflair.ai/api/v1. '
                     . 'Currently configured: ' . get_option('dataflair_api_base_url', '(not set)');

            case 419:
                return 'CSRF token mismatch (419). The API returned a Laravel session error. '
                     . 'This should not happen for API routes. Check that the API Base URL points to /api/v1 routes, not web routes.';

            case 429:
                return 'Rate limited (429). Too many requests to the DataFlair API. '
                     . 'API says: "' . ($api_message ?: 'Too Many Requests') . '". '
                     . 'Wait a few minutes and try again, or check your API credential rate limit settings.';

            case 500:
                return 'Server error (500). The DataFlair API encountered an internal error. '
                     . 'This is a server-side issue, not a plugin configuration problem. '
                     . 'API says: "' . ($api_message ?: substr($body, 0, 200)) . '". '
                     . 'Contact DataFlair support if this persists.';

            case 502:
            case 503:
            case 504:
                return 'Server unavailable (' . $status_code . '). The DataFlair API at ' . $host . ' is temporarily unavailable. '
                     . 'This could be a deployment in progress, server overload, or infrastructure issue. Try again in a few minutes.';

            default:
                return 'Unexpected HTTP ' . $status_code . ' from ' . $host . '. '
                     . ($api_message ? 'API says: "' . $api_message . '". ' : '')
                     . 'Response body: ' . substr($body, 0, 300);
        }
    }

    /**
     * AJAX handler to fetch all toplists from API and sync them
     */
    public function ajax_fetch_all_toplists() {
        check_ajax_referer('dataflair_fetch_all_toplists', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Unauthorized'));
        }
        
        $token = trim(get_option('dataflair_api_token'));
        if (empty($token)) {
            wp_send_json_error(array('message' => 'API token not configured. Please set your API token first.'));
        }
        
        // Return initial response - JavaScript will handle batch processing
        wp_send_json_success(array(
            'message' => 'Starting batch sync...',
            'start_batch' => true
        ));
    }
    
    /**
     * AJAX handler to sync toplists in batches
     */
    public function ajax_sync_toplists_batch() {
        // Phase 3 — thin delegator. Nonce + capability + token precheck stay
        // at the AJAX gate. Everything below moves into ToplistSyncService,
        // which preserves every Phase 0B / Phase 1 invariant byte-for-byte.
        check_ajax_referer('dataflair_sync_toplists_batch', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Unauthorized'));
        }

        $token = trim(get_option('dataflair_api_token'));
        if (empty($token)) {
            wp_send_json_error(array('message' => 'API token not configured. Please set your API token first.'));
        }

        $page    = isset($_POST['page']) ? intval($_POST['page']) : 1;
        $request = \DataFlair\Toplists\Sync\SyncRequest::toplists($page);
        $result  = $this->toplist_sync_service()->syncPage($request);

        if ($result->success) {
            wp_send_json_success($result->toArray());
        }
        wp_send_json_error(array('message' => $result->message));
    }

    // NOTE: sync_toplists_page_per_id() moved to DataFlair\Toplists\Sync\ToplistSyncService
    // in v1.12.1 (Phase 3). The progressive-split fallback logic lives there now.
    //
    // NOTE: cron_sync_toplists(), cron_sync_brands(), sync_all_toplists(),
    // sync_all_brands() were removed in v1.11.0 (Phase 0B H1). Auto-sync is
    // gone — paginated batch sync from the admin Tools page (or WP-CLI) is
    // now the only way to refresh toplists and brands.

    /**
     * Clear all DataFlair tracker transients.
     *
     * Phase 0B H10: chunked DELETE loop with LIMIT 1000 so a site that has
     * accumulated tens of thousands of tracker transients (seen on Sigma)
     * can't blow a single-query binlog / replication / MySQL packet ceiling.
     * Optional WallClockBudget lets a caller bail cleanly when the sync loop
     * needs to return control to the AJAX admin-JS driver.
     *
     * @param \DataFlair\Toplists\Support\WallClockBudget|null $budget
     * @return int Total number of option rows deleted.
     */
    private function clear_tracker_transients($budget = null) {
        global $wpdb;
        $chunk = 1000;
        $total = 0;

        foreach (
            array(
                '_transient_dataflair_tracker_%',
                '_transient_timeout_dataflair_tracker_%',
            ) as $pattern
        ) {
            while (true) {
                if ($budget !== null && $budget->exceeded(1.0)) break;
                $deleted = $wpdb->query(
                    $wpdb->prepare(
                        "DELETE FROM {$wpdb->options}
                         WHERE option_name LIKE %s
                         ORDER BY option_id
                         LIMIT %d",
                        $pattern,
                        $chunk
                    )
                );
                if ($deleted === false) break;
                $total += (int) $deleted;
                if ((int) $deleted < $chunk) break;
            }
        }

        error_log('DataFlair: Cleared ' . $total . ' tracker transient rows before sync');
        return $total;
    }
    
    /**
     * Phase 0B H11: Paginated table wipe that replaces TRUNCATE.
     *
     * TRUNCATE is implicitly committed (cannot be rolled back), cannot be
     * replicated under STATEMENT-based binlog, and on some managed MySQL
     * hosts it forces a metadata lock that blocks concurrent reads for
     * seconds. A chunked DELETE is binlog-safe, cancellable, and stays
     * inside the MySQL packet size even on multi-million-row tables.
     *
     * Hardens against SQL injection by whitelisting the table against the
     * plugin's known prefix, since MySQL doesn't allow placeholders in the
     * identifier position.
     *
     * @param string $table Fully-qualified table name.
     * @param int    $chunk Rows to delete per statement (default 500).
     * @return int Total rows deleted across all chunks.
     */
    private function delete_all_paginated($table, $chunk = 500) {
        global $wpdb;

        $allowed = array(
            $wpdb->prefix . DATAFLAIR_TABLE_NAME,
            $wpdb->prefix . DATAFLAIR_BRANDS_TABLE_NAME,
            $wpdb->prefix . DATAFLAIR_ALTERNATIVE_TOPLISTS_TABLE_NAME,
        );
        if (!in_array($table, $allowed, true)) {
            return 0;
        }

        $chunk = max(50, min(5000, (int) $chunk));
        $total = 0;
        while (true) {
            $deleted = $wpdb->query(
                $wpdb->prepare("DELETE FROM $table LIMIT %d", $chunk)
            );
            if ($deleted === false) break;
            $total += (int) $deleted;
            if ((int) $deleted < $chunk) break;
        }
        return $total;
    }

    /**
     * Phase 0B H5: Aggregate DISTINCT values from a comma-separated text
     * column on the brands table — used to populate filter dropdowns without
     * parsing every brand's JSON `data` blob in PHP.
     *
     * @param string $brands_table Fully-qualified brands table name.
     * @param string $column       Lean CSV column on the brands table.
     * @return string[]
     */
    private function collect_distinct_csv_values($brands_table, $column) {
        global $wpdb;
        $allowed = array('licenses', 'top_geos', 'product_types');
        if (!in_array($column, $allowed, true)) return array();
        $rows = $wpdb->get_col("SELECT DISTINCT $column FROM $brands_table WHERE $column IS NOT NULL AND $column != ''");
        $values = array();
        foreach ($rows as $csv) {
            foreach (array_map('trim', explode(',', (string) $csv)) as $v) {
                if ($v !== '') $values[$v] = true;
            }
        }
        $values = array_keys($values);
        sort($values);
        return $values;
    }

    /**
     * Brands page HTML
     *
     * @codeCoverageIgnore
     */
    public function brands_page() {
        global $wpdb;
        $brands_table_name = $wpdb->prefix . DATAFLAIR_BRANDS_TABLE_NAME;

        // Phase 0B H5: server-side pagination caps the number of rows (and
        // data-blob JSON) pulled into PHP at any one time. Previously the page
        // loaded every brand (500+) with its full `data` column into memory —
        // ~40MB+ of blobs, and an obvious latent-OOM hotspot on admin pages.
        $per_page   = 50;
        $paged      = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $offset     = ($paged - 1) * $per_page;
        $total_brands = (int) $wpdb->get_var("SELECT COUNT(*) FROM $brands_table_name");
        $total_pages  = max(1, (int) ceil($total_brands / $per_page));

        // Lean projection: everything the list row needs, minus the heavy `data` blob.
        $brands = $wpdb->get_results($wpdb->prepare(
            "SELECT id, api_brand_id, name, slug, product_types, licenses, top_geos,
                    offers_count, trackers_count, last_synced, review_url_override
             FROM $brands_table_name
             ORDER BY name ASC
             LIMIT %d OFFSET %d",
            $per_page,
            $offset
        ));

        // Batched fetch of data blobs for the current page only (one extra SELECT).
        $data_by_api_brand_id = array();
        if (!empty($brands)) {
            $api_brand_ids = array();
            foreach ($brands as $b) {
                if (!empty($b->api_brand_id)) $api_brand_ids[] = intval($b->api_brand_id);
            }
            if (!empty($api_brand_ids)) {
                $placeholders = implode(',', array_fill(0, count($api_brand_ids), '%d'));
                $data_rows = $wpdb->get_results($wpdb->prepare(
                    "SELECT api_brand_id, data FROM $brands_table_name WHERE api_brand_id IN ($placeholders)",
                    $api_brand_ids
                ));
                foreach ((array) $data_rows as $row) {
                    $data_by_api_brand_id[intval($row->api_brand_id)] = $row->data;
                }
            }
            foreach ($brands as $b) {
                $b->data = $data_by_api_brand_id[intval($b->api_brand_id)] ?? '{}';
            }
        }
        
        ?>
        <div class="wrap">
            <h1>DataFlair Brands</h1>
            
            <?php settings_errors(); ?>
            
            <p>Manage brands from the DataFlair API. Only active brands are synced.</p>
            
            <h2>Sync Brands</h2>
            <button type="button" id="dataflair-fetch-all-brands" class="button button-primary">
                Sync Brands from API
            </button>
            <span id="dataflair-fetch-brands-message" style="margin-left: 10px;"></span>
            
            <!-- Progress Bar -->
            <div id="dataflair-sync-progress" style="display: none; margin-top: 15px;">
                <div style="background: #f0f0f1; border-radius: 4px; height: 24px; position: relative; overflow: hidden; border: 1px solid #dcdcde;">
                    <div id="dataflair-progress-bar" style="background: linear-gradient(90deg, #2271b1 0%, #135e96 100%); height: 100%; width: 0%; transition: width 0.3s ease; display: flex; align-items: center; justify-content: center;">
                        <span id="dataflair-progress-text" style="color: white; font-size: 12px; font-weight: 600; position: absolute; left: 50%; transform: translateX(-50%);"></span>
                    </div>
                </div>
            </div>
            
            <p class="description">Fetches all active brands from the DataFlair API in batches of 15. Existing brands will be updated.</p>
            <p class="description">Sync runs only when triggered here or via WP-CLI. <?php echo esc_html($this->format_last_sync_label('dataflair_last_brands_sync')); ?></p>
            
            <hr>
            
            <h2>Synced Brands (showing <?php echo count($brands); ?> of <?php echo esc_html($total_brands); ?>)</h2>
            <?php if ($brands): ?>
                <?php
                // Phase 0B H5: distinct-value queries against lean CSV columns
                // (licenses, top_geos) instead of parsing every brand's `data`
                // JSON blob in PHP. Payment-method filter still populates from
                // the current page's data blobs only — sufficient for admin UX,
                // and keeps paymentMethods out of the aggregate query path.
                $all_licenses        = $this->collect_distinct_csv_values($brands_table_name, 'licenses');
                $all_geos            = $this->collect_distinct_csv_values($brands_table_name, 'top_geos');
                $all_payment_methods = array();

                foreach ($brands as $brand) {
                    $data = json_decode($brand->data, true);
                    if (!empty($data['paymentMethods']) && is_array($data['paymentMethods'])) {
                        $all_payment_methods = array_merge($all_payment_methods, $data['paymentMethods']);
                    }
                }
                $all_payment_methods = array_unique($all_payment_methods);
                sort($all_payment_methods);
                ?>
                
                <!-- Filters -->
                <div class="dataflair-filters">
                    <div class="filter-row">
                        <!-- License Filter -->
                        <div class="filter-group">
                            <label>Licenses</label>
                            <select id="dataflair-filter-licenses" class="dataflair-select2" multiple="multiple" data-filter-type="licenses" style="width: 100%;">
                                <?php foreach ($all_licenses as $license): ?>
                                    <option value="<?php echo esc_attr($license); ?>"><?php echo esc_html($license); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <!-- Geo Filter -->
                        <div class="filter-group">
                            <label>Top Geos</label>
                            <select id="dataflair-filter-top-geos" class="dataflair-select2" multiple="multiple" data-filter-type="top_geos" style="width: 100%;">
                                <?php foreach ($all_geos as $geo): ?>
                                    <option value="<?php echo esc_attr($geo); ?>"><?php echo esc_html($geo); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <!-- Payment Filter -->
                        <div class="filter-group">
                            <label>Payment Methods</label>
                            <select id="dataflair-filter-payment-methods" class="dataflair-select2" multiple="multiple" data-filter-type="payment_methods" style="width: 100%;">
                                <?php foreach ($all_payment_methods as $method): ?>
                                    <option value="<?php echo esc_attr($method); ?>"><?php echo esc_html($method); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <!-- Actions -->
                        <div class="filter-group filter-actions">
                            <button type="button" id="dataflair-clear-all-filters" class="button">Clear All Filters</button>
                            <span id="dataflair-brands-count">Showing <?php echo count($brands); ?> brands</span>
                        </div>
                    </div>
                </div>
                
                <table class="wp-list-table widefat striped dataflair-brands-table">
                    <thead>
                        <tr>
                            <th style="width: 3%;"></th>
                            <th style="width: 5%;">ID</th>
                            <th style="width: 8%;">Logo</th>
                            <th style="width: 14%;" class="sortable">
                                <a href="#" class="sort-link" data-sort="name">
                                    Brand Name
                                    <span class="sorting-indicator">
                                        <span class="dashicons dashicons-sort"></span>
                                    </span>
                                </a>
                            </th>
                            <th style="width: 10%;">Product Type</th>
                            <th style="width: 12%;">Licenses</th>
                            <th style="width: 16%;">Top Geos</th>
                            <th style="width: 7%;" class="sortable">
                                <a href="#" class="sort-link" data-sort="offers">
                                    Offers
                                    <span class="sorting-indicator">
                                        <span class="dashicons dashicons-sort"></span>
                                    </span>
                                </a>
                            </th>
                            <th style="width: 7%;" class="sortable">
                                <a href="#" class="sort-link" data-sort="trackers">
                                    Trackers
                                    <span class="sorting-indicator">
                                        <span class="dashicons dashicons-sort"></span>
                                    </span>
                                </a>
                            </th>
                            <th style="width: 13%;">Last Synced</th>
                            <th style="width: 15%;">Review URL</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($brands as $index => $brand): 
                            $data = json_decode($brand->data, true);
                            $brand_id = 'brand-' . $brand->api_brand_id;
                            
                            // Prepare filter data attributes
                            $licenses_json = !empty($data['licenses']) ? json_encode($data['licenses']) : '[]';
                            
                            $geos = array();
                            if (!empty($data['topGeos']['countries'])) {
                                $geos = array_merge($geos, $data['topGeos']['countries']);
                            }
                            if (!empty($data['topGeos']['markets'])) {
                                $geos = array_merge($geos, $data['topGeos']['markets']);
                            }
                            $geos_json = json_encode($geos);
                            
                            $payments_json = !empty($data['paymentMethods']) ? json_encode($data['paymentMethods']) : '[]';
                        ?>
                        <tr class="brand-row" 
                            data-brand-name="<?php echo esc_attr($brand->name); ?>"
                            data-offers-count="<?php echo esc_attr($brand->offers_count); ?>"
                            data-trackers-count="<?php echo esc_attr($brand->trackers_count); ?>"
                            data-brand-data='<?php echo esc_attr(json_encode(array(
                                'licenses' => !empty($data['licenses']) ? $data['licenses'] : array(),
                                'topGeos' => $geos,
                                'paymentMethods' => !empty($data['paymentMethods']) ? $data['paymentMethods'] : array()
                            ))); ?>'>
                            <td class="toggle-cell">
                                <button type="button" class="brand-toggle" aria-expanded="false">
                                    <span class="dashicons dashicons-arrow-right-alt2"></span>
                                </button>
                            </td>
                            <td><?php echo esc_html($brand->api_brand_id); ?></td>
                            <td class="brand-logo-cell">
                                <?php 
                                // Get logo URL - prioritize local_logo
                                $logo_url = '';
                                if (!empty($data['local_logo'])) {
                                    $logo_url = $data['local_logo'];
                                } else {
                                    // Fallback to external logo with nested structure support
                                    $logo_keys = array('logo', 'brandLogo', 'logoUrl', 'image');
                                    foreach ($logo_keys as $key) {
                                        if (!empty($data[$key])) {
                                            if (is_array($data[$key])) {
                                                // Check for nested logo object with rectangular/square
                                                $logo_url = $data[$key]['rectangular'] ?? 
                                                           $data[$key]['square'] ?? 
                                                           $data[$key]['url'] ?? 
                                                           $data[$key]['src'] ?? '';
                                            } else {
                                                $logo_url = $data[$key];
                                            }
                                            if ($logo_url) break;
                                        }
                                    }
                                }
                                
                                if ($logo_url && !is_array($logo_url)): ?>
                                    <img src="<?php echo esc_url($logo_url); ?>" 
                                         alt="<?php echo esc_attr($brand->name); ?>" 
                                         class="brand-logo-thumb"
                                         onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                                    <div class="brand-logo-placeholder" style="display: none;">
                                        <?php echo esc_html(strtoupper(substr($brand->name, 0, 2))); ?>
                                    </div>
                                <?php else: ?>
                                    <div class="brand-logo-placeholder">
                                        <?php echo esc_html(strtoupper(substr($brand->name, 0, 2))); ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <strong><?php echo esc_html($brand->name); ?></strong>
                                <div class="row-actions">
                                    <span class="slug"><?php echo esc_html($brand->slug); ?></span>
                                </div>
                            </td>
                            <td><?php echo esc_html($brand->product_types ?: '—'); ?></td>
                            <td>
                                <?php 
                                $licenses = $brand->licenses;
                                if (strlen($licenses) > 25) {
                                    echo '<span title="' . esc_attr($licenses) . '">' . esc_html(substr($licenses, 0, 25)) . '...</span>';
                                } else {
                                    echo esc_html($licenses ?: '—');
                                }
                                ?>
                            </td>
                            <td>
                                <?php 
                                $top_geos = $brand->top_geos;
                                if (strlen($top_geos) > 35) {
                                    echo '<span title="' . esc_attr($top_geos) . '">' . esc_html(substr($top_geos, 0, 35)) . '...</span>';
                                } else {
                                    echo esc_html($top_geos ?: '—');
                                }
                                ?>
                            </td>
                            <td style="text-align: center;">
                                <?php if ($brand->offers_count > 0): ?>
                                    <span class="count"><?php echo esc_html($brand->offers_count); ?></span>
                                <?php else: ?>
                                    <span style="color: #999;">0</span>
                                <?php endif; ?>
                            </td>
                            <td style="text-align: center;">
                                <?php if ($brand->trackers_count > 0): ?>
                                    <span class="count"><?php echo esc_html($brand->trackers_count); ?></span>
                                <?php else: ?>
                                    <span style="color: #999;">0</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo esc_html(date('Y-m-d H:i', strtotime($brand->last_synced))); ?></td>
                            <?php
                                $has_override = !empty($brand->review_url_override);
                            ?>
                            <td>
                                <input type="text"
                                       class="dataflair-review-url-input"
                                       data-brand-id="<?php echo esc_attr($brand->api_brand_id); ?>"
                                       value="<?php echo esc_attr($brand->review_url_override ?? ''); ?>"
                                       placeholder="/reviews/brand-slug/"
                                       style="width:220px;<?php echo $has_override ? ' background:#f0f0f0; color:#777;' : ''; ?>"
                                       <?php echo $has_override ? 'disabled' : ''; ?> />
                                <button class="button dataflair-save-review-url" data-brand-id="<?php echo esc_attr($brand->api_brand_id); ?>" data-mode="<?php echo $has_override ? 'edit' : 'save'; ?>">
                                    <?php echo $has_override ? 'Edit' : 'Save'; ?>
                                </button>
                            </td>
                        </tr>

                        <!-- Expandable Details Row -->
                        <tr class="brand-details" id="<?php echo esc_attr($brand_id); ?>" style="display: none;">
                            <td colspan="10">
                                <div class="brand-details-content">
                                    <div class="details-grid">
                                        <div class="detail-section">
                                            <h4>Payment Methods</h4>
                                            <div class="detail-content">
                                                <?php 
                                                if (!empty($data['paymentMethods']) && is_array($data['paymentMethods'])) {
                                                    echo '<div class="badge-list">';
                                                    foreach (array_slice($data['paymentMethods'], 0, 15) as $method) {
                                                        echo '<span class="badge">' . esc_html($method) . '</span>';
                                                    }
                                                    if (count($data['paymentMethods']) > 15) {
                                                        $remaining = array_slice($data['paymentMethods'], 15);
                                                        $tooltip = esc_attr(implode(', ', $remaining));
                                                        echo '<span class="badge more with-tooltip" title="' . $tooltip . '">+' . (count($data['paymentMethods']) - 15) . ' more</span>';
                                                    }
                                                    echo '</div>';
                                                } else {
                                                    echo '<span class="no-data">No payment methods</span>';
                                                }
                                                ?>
                                            </div>
                                        </div>
                                        
                                        <div class="detail-section">
                                            <h4>Currencies</h4>
                                            <div class="detail-content">
                                                <?php 
                                                if (!empty($data['currencies']) && is_array($data['currencies'])) {
                                                    echo '<div class="badge-list">';
                                                    foreach ($data['currencies'] as $currency) {
                                                        echo '<span class="badge">' . esc_html($currency) . '</span>';
                                                    }
                                                    echo '</div>';
                                                } else {
                                                    echo '<span class="no-data">No currencies specified</span>';
                                                }
                                                ?>
                                            </div>
                                        </div>
                                        
                                        <div class="detail-section">
                                            <h4>Rating</h4>
                                            <div class="detail-content">
                                                <?php 
                                                $rating = isset($data['rating']) ? $data['rating'] : null;
                                                if ($rating) {
                                                    echo '<span class="rating">' . esc_html($rating) . ' / 5</span>';
                                                } else {
                                                    echo '<span class="no-data">Not rated</span>';
                                                }
                                                ?>
                                            </div>
                                        </div>
                                        
                                        <div class="detail-section full-width">
                                            <h4>Game Providers</h4>
                                            <div class="detail-content">
                                                <?php 
                                                if (!empty($data['gameProviders']) && is_array($data['gameProviders'])) {
                                                    echo '<div class="badge-list">';
                                                    foreach ($data['gameProviders'] as $provider) {
                                                        echo '<span class="badge">' . esc_html($provider) . '</span>';
                                                    }
                                                    echo '</div>';
                                                } else {
                                                    echo '<span class="no-data">No game providers specified</span>';
                                                }
                                                ?>
                                            </div>
                                        </div>
                                        
                                        <div class="detail-section full-width">
                                            <h4>Restricted Countries</h4>
                                            <div class="detail-content restricted-countries">
                                                <?php 
                                                if (!empty($data['restrictedCountries']) && is_array($data['restrictedCountries'])) {
                                                    echo '<div class="badge-list">';
                                                    foreach (array_slice($data['restrictedCountries'], 0, 20) as $country) {
                                                        echo '<span class="badge badge-gray">' . esc_html($country) . '</span>';
                                                    }
                                                    if (count($data['restrictedCountries']) > 20) {
                                                        $remaining = array_slice($data['restrictedCountries'], 20);
                                                        $tooltip = esc_attr(implode(', ', $remaining));
                                                        echo '<span class="badge badge-gray more with-tooltip" title="' . $tooltip . '">+' . (count($data['restrictedCountries']) - 20) . ' more</span>';
                                                    }
                                                    echo '</div>';
                                                } else {
                                                    echo '<span class="no-data">No restrictions</span>';
                                                }
                                                ?>
                                            </div>
                                        </div>
                                        
                                        <div class="detail-section">
                                            <h4>Customer Support</h4>
                                            <div class="detail-content">
                                                <?php 
                                                $hasCustomerSupport = !empty($data['languages']['customerSupport']) && is_array($data['languages']['customerSupport']) && count($data['languages']['customerSupport']) > 0;
                                                if ($hasCustomerSupport) {
                                                    echo '<span class="support-available">Available</span><br>';
                                                    echo '<div class="badge-list" style="margin-top: 6px;">';
                                                    foreach ($data['languages']['customerSupport'] as $lang) {
                                                        echo '<span class="badge">' . esc_html($lang) . '</span>';
                                                    }
                                                    echo '</div>';
                                                } else {
                                                    echo '<span class="no-data">Not specified</span>';
                                                }
                                                ?>
                                            </div>
                                        </div>
                                        
                                        <div class="detail-section">
                                            <h4>Live Chat</h4>
                                            <div class="detail-content">
                                                <?php 
                                                $hasLiveChat = !empty($data['languages']['livechat']) && is_array($data['languages']['livechat']) && count($data['languages']['livechat']) > 0;
                                                if ($hasLiveChat) {
                                                    echo '<span class="support-available">Available</span><br>';
                                                    echo '<div class="badge-list" style="margin-top: 6px;">';
                                                    foreach ($data['languages']['livechat'] as $lang) {
                                                        echo '<span class="badge">' . esc_html($lang) . '</span>';
                                                    }
                                                    echo '</div>';
                                                } else {
                                                    echo '<span class="no-data">Not available</span>';
                                                }
                                                ?>
                                            </div>
                                        </div>
                                        
                                        <div class="detail-section full-width">
                                            <h4>Website Languages</h4>
                                            <div class="detail-content">
                                                <?php 
                                                if (!empty($data['languages']['website']) && is_array($data['languages']['website'])) {
                                                    echo '<div class="badge-list">';
                                                    foreach ($data['languages']['website'] as $lang) {
                                                        echo '<span class="badge">' . esc_html($lang) . '</span>';
                                                    }
                                                    echo '</div>';
                                                } else {
                                                    echo '<span class="no-data">Not specified</span>';
                                                }
                                                ?>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <hr class="details-separator">
                                    
                                    <div class="offers-section">
                                        <h4>Offers (<?php echo $brand->offers_count; ?>)</h4>
                                        <?php if (!empty($data['offers']) && is_array($data['offers'])): ?>
                                            <div class="offers-list">
                                                <?php foreach ($data['offers'] as $offer): ?>
                                                    <div class="offer-item-inline">
                                                        <span class="offer-type-badge"><?php echo esc_html($offer['offerTypeName'] ?? 'Unknown'); ?></span>
                                                        <span class="offer-separator">:</span>
                                                        <span class="offer-text-inline"><?php echo esc_html($offer['offerText'] ?? 'No description'); ?></span>
                                                        <span class="offer-separator">:</span>
                                                        <span class="offer-geo-inline">
                                                            <?php 
                                                            $geos = array();
                                                            if (!empty($offer['geos']['countries'])) {
                                                                $geos = array_merge($geos, $offer['geos']['countries']);
                                                            }
                                                            if (!empty($offer['geos']['markets'])) {
                                                                $geos = array_merge($geos, $offer['geos']['markets']);
                                                            }
                                                            echo $geos ? esc_html(implode(', ', $geos)) : 'All Geos';
                                                            ?>
                                                        </span>
                                                        <span class="offer-separator">:</span>
                                                        <span class="offer-tracker-inline">
                                                            <?php 
                                                            $trackers = isset($offer['trackers']) && is_array($offer['trackers']) ? $offer['trackers'] : array();
                                                            if (count($trackers) > 0) {
                                                                echo '<span class="tracker-count-inline">' . count($trackers) . ' tracker' . (count($trackers) > 1 ? 's' : '') . '</span>';
                                                            } else {
                                                                echo '<span class="no-trackers-inline">No trackers</span>';
                                                            }
                                                            ?>
                                                        </span>
                                                        <span class="offer-separator">|</span>
                                                        <span class="offer-extra-info">
                                                            <?php 
                                                            $extras = array();
                                                            if (!empty($offer['bonus_wagering_requirement'])) {
                                                                $extras[] = 'Wagering: ' . esc_html($offer['bonus_wagering_requirement']) . 'x';
                                                            }
                                                            if (!empty($offer['minimum_deposit'])) {
                                                                $extras[] = 'Min Deposit: $' . esc_html($offer['minimum_deposit']);
                                                            }
                                                            if (!empty($offer['bonus_code'])) {
                                                                $extras[] = 'Code: ' . esc_html($offer['bonus_code']);
                                                            }
                                                            if (!empty($offer['has_free_spins'])) {
                                                                $extras[] = '<span class="badge-free-spins">Free Spins</span>';
                                                            }
                                                            echo implode(' | ', $extras);
                                                            ?>
                                                        </span>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php else: ?>
                                            <p class="no-data">No offers available</p>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                
                <!-- Phase 0B H5: server-side pagination. Client-side filter
                     pagination below still operates within the server page. -->
                <?php
                $page_url = add_query_arg(null, null);
                $first_url = remove_query_arg('paged', $page_url);
                $prev_url  = $paged > 1 ? add_query_arg('paged', $paged - 1, $page_url) : '#';
                $next_url  = $paged < $total_pages ? add_query_arg('paged', $paged + 1, $page_url) : '#';
                $last_url  = $total_pages > 1 ? add_query_arg('paged', $total_pages, $page_url) : '#';
                ?>
                <div class="tablenav top" style="margin-top: 12px;">
                    <div class="tablenav-pages">
                        <span class="displaying-num"><?php echo esc_html($total_brands); ?> brands total &mdash; page <?php echo esc_html($paged); ?> of <?php echo esc_html($total_pages); ?></span>
                        <span class="pagination-links" style="margin-left: 10px;">
                            <a class="first-page button <?php echo $paged <= 1 ? 'disabled' : ''; ?>" href="<?php echo esc_url($paged > 1 ? $first_url : '#'); ?>"><span aria-hidden="true">«</span></a>
                            <a class="prev-page button <?php echo $paged <= 1 ? 'disabled' : ''; ?>" href="<?php echo esc_url($prev_url); ?>"><span aria-hidden="true">‹</span></a>
                            <span class="paging-input"> Page <?php echo esc_html($paged); ?> of <?php echo esc_html($total_pages); ?> </span>
                            <a class="next-page button <?php echo $paged >= $total_pages ? 'disabled' : ''; ?>" href="<?php echo esc_url($next_url); ?>"><span aria-hidden="true">›</span></a>
                            <a class="last-page button <?php echo $paged >= $total_pages ? 'disabled' : ''; ?>" href="<?php echo esc_url($last_url); ?>"><span aria-hidden="true">»</span></a>
                        </span>
                    </div>
                </div>

                <!-- Pagination (client-side filter pagination within server page) -->
                <div class="tablenav bottom">
                    <div class="tablenav-pages">
                        <span class="displaying-num" id="brands-total-count"><?php echo count($brands); ?> items on page</span>
                        <span class="pagination-links" style="margin-right: 10px;">
                            <label for="items-per-page-selector" style="margin-right: 5px;">Filter view:</label>
                            <select id="items-per-page-selector" style="margin-right: 10px;">
                                <option value="10">10</option>
                                <option value="20" selected>20</option>
                                <option value="50">50</option>
                            </select>
                        </span>
                        <span class="pagination-links">
                            <a class="first-page button" href="#" id="pagination-first" disabled>
                                <span class="screen-reader-text">First page</span>
                                <span aria-hidden="true">«</span>
                            </a>
                            <a class="prev-page button" href="#" id="pagination-prev" disabled>
                                <span class="screen-reader-text">Previous page</span>
                                <span aria-hidden="true">‹</span>
                            </a>
                            <span class="paging-input">
                                <label for="current-page-selector" class="screen-reader-text">Current Page</label>
                                <input class="current-page" id="current-page-selector" type="text" name="paged" value="1" size="2" aria-describedby="table-paging">
                                <span class="tablenav-paging-text"> of <span class="total-pages" id="total-pages">1</span></span>
                            </span>
                            <a class="next-page button" href="#" id="pagination-next">
                                <span class="screen-reader-text">Next page</span>
                                <span aria-hidden="true">›</span>
                            </a>
                            <a class="last-page button" href="#" id="pagination-last">
                                <span class="screen-reader-text">Last page</span>
                                <span aria-hidden="true">»</span>
                            </a>
                        </span>
                    </div>
                </div>

                <script>
                jQuery(document).on('click', '.dataflair-save-review-url', function() {
                    var btn   = jQuery(this);
                    var brandId = btn.data('brand-id');
                    var input = jQuery('.dataflair-review-url-input[data-brand-id="' + brandId + '"]');
                    var mode  = btn.data('mode') || 'save';

                    // Edit mode: unlock the field for editing
                    if (mode === 'edit') {
                        input.prop('disabled', false).css({ background: '', color: '' }).focus();
                        btn.text('Save').data('mode', 'save');
                        return;
                    }

                    // Save mode: persist and lock
                    var url = input.val();
                    btn.text('Saving...').prop('disabled', true);
                    jQuery.post(ajaxurl, {
                        action: 'dataflair_save_review_url',
                        brand_id: brandId,
                        review_url: url,
                        nonce: '<?php echo wp_create_nonce("dataflair_save_review_url"); ?>'
                    }, function(response) {
                        if (response.success) {
                            btn.text('Saved ✓').prop('disabled', false);
                            setTimeout(function() {
                                input.prop('disabled', true).css({ background: '#f0f0f0', color: '#777' });
                                btn.text('Edit').data('mode', 'edit');
                            }, 1000);
                        } else {
                            btn.text('Error').prop('disabled', false);
                            setTimeout(function() { btn.text('Save'); }, 2000);
                        }
                    });
                });
                </script>

                <style>
                    /* Select2 styling for WordPress admin */
                    .select2-container {
                        z-index: 999999;
                    }
                    .select2-container--default .select2-selection--multiple {
                        border: 1px solid #8c8f94;
                        border-radius: 4px;
                        min-height: 30px;
                        padding: 2px;
                    }
                    .select2-container--default .select2-selection--multiple .select2-selection__choice {
                        background-color: #2271b1;
                        border: 1px solid #2271b1;
                        color: #fff;
                        padding: 2px 6px;
                        margin: 2px;
                        border-radius: 3px;
                        display: inline-flex;
                        align-items: center;
                    }
                    .select2-container--default .select2-selection--multiple .select2-selection__choice__remove {
                        /* Select2 v4.1+ renders this as a <button> — reset browser defaults */
                        border: none;
                        background: rgba(255, 255, 255, 0.25);
                        padding: 0;
                        margin-right: 0;
                        color: #fff;
                        cursor: pointer;
                        font-size: 13px;
                        font-weight: 700;
                        line-height: 1;
                        display: inline-flex;
                        align-items: center;
                        justify-content: center;
                        width: 16px;
                        height: 16px;
                        border-radius: 50%;
                        flex-shrink: 0;
                    }
                    .select2-container--default .select2-selection--multiple .select2-selection__choice__remove:hover {
                        background: rgba(255, 255, 255, 0.45);
                        color: #fff;
                    }
                    /* Inner ×  span — strip any inherited spacing so button stays square */
                    .select2-container--default .select2-selection--multiple .select2-selection__choice__remove span {
                        display: flex;
                        align-items: center;
                        justify-content: center;
                        line-height: 1;
                        margin: 0;
                        padding: 0;
                    }
                    /* Display label — own the gap from the × button */
                    .select2-container--default .select2-selection--multiple .select2-selection__choice__display {
                        padding-left: 15px;
                    }
                    .select2-container--default .select2-search--inline .select2-search__field {
                        margin-top: 2px;
                        padding: 2px;
                    }
                    .filter-group .select2-container {
                        margin-top: 5px;
                    }
                    
                    /* Filters */
                    .dataflair-filters {
                        background: #f9f9f9;
                        border: 1px solid #dcdcde;
                        border-radius: 6px;
                        padding: 20px;
                        margin-bottom: 20px;
                        box-shadow: 0 1px 3px rgba(0,0,0,0.05);
                    }
                    .filter-row {
                        display: flex;
                        align-items: flex-end;
                        gap: 16px;
                        flex-wrap: wrap;
                    }
                    .filter-group {
                        display: flex;
                        flex-direction: column;
                        gap: 8px;
                        flex: 1;
                        min-width: 220px;
                        max-width: 280px;
                    }
                    .filter-group > label {
                        font-weight: 600;
                        font-size: 13px;
                        color: #1d2327;
                        letter-spacing: 0;
                    }
                    
                    /* Custom Multiselect */
                    .custom-multiselect {
                        position: relative;
                        flex: 1;
                    }
                    .multiselect-toggle {
                        width: 100%;
                        padding: 8px 12px;
                        background: white;
                        border: 1px solid #8c8f94;
                        border-radius: 4px;
                        font-size: 13px;
                        text-align: left;
                        cursor: pointer;
                        display: flex;
                        justify-content: space-between;
                        align-items: center;
                        transition: all 0.2s;
                        min-height: 38px;
                        box-shadow: 0 1px 2px rgba(0,0,0,0.05);
                    }
                    .multiselect-toggle:hover {
                        border-color: #2271b1;
                        box-shadow: 0 1px 3px rgba(0,0,0,0.1);
                    }
                    .multiselect-toggle .dashicons {
                        font-size: 18px;
                        width: 18px;
                        height: 18px;
                        transition: transform 0.2s;
                        color: #8c8f94;
                        flex-shrink: 0;
                        margin-left: 8px;
                    }
                    .custom-multiselect.open .multiselect-toggle .dashicons {
                        transform: rotate(180deg);
                        color: #2271b1;
                    }
                    .custom-multiselect.open .multiselect-toggle {
                        border-color: #2271b1;
                        box-shadow: 0 0 0 1px #2271b1;
                    }
                    .selected-text {
                        overflow: hidden;
                        text-overflow: ellipsis;
                        white-space: nowrap;
                        flex: 1;
                    }
                    
                    /* Dropdown */
                    .multiselect-dropdown {
                        position: absolute;
                        top: 100%;
                        left: 0;
                        right: 0;
                        background: white;
                        border: 1px solid #2271b1;
                        border-radius: 3px;
                        margin-top: 4px;
                        box-shadow: 0 2px 8px rgba(0,0,0,0.1);
                        z-index: 1000;
                        display: none;
                    }
                    .custom-multiselect.open .multiselect-dropdown {
                        display: block;
                    }
                    
                    /* Search */
                    .multiselect-search {
                        padding: 8px;
                        border-bottom: 1px solid #dcdcde;
                    }
                    .multiselect-search input {
                        width: 100%;
                        padding: 4px 8px;
                        border: 1px solid #8c8f94;
                        border-radius: 3px;
                        font-size: 12px;
                    }
                    .multiselect-search input:focus {
                        border-color: #2271b1;
                        outline: none;
                    }
                    
                    /* Actions */
                    .multiselect-actions {
                        padding: 6px 8px;
                        border-bottom: 1px solid #dcdcde;
                        display: flex;
                        gap: 12px;
                        font-size: 11px;
                    }
                    .multiselect-actions a {
                        color: #2271b1;
                        text-decoration: none;
                    }
                    .multiselect-actions a:hover {
                        text-decoration: underline;
                    }
                    
                    /* Options */
                    .multiselect-options {
                        max-height: 200px;
                        overflow-y: auto;
                        padding: 4px;
                    }
                    .multiselect-option {
                        display: flex;
                        align-items: center;
                        padding: 6px 8px;
                        cursor: pointer;
                        border-radius: 2px;
                        font-size: 12px;
                        margin: 0;
                    }
                    .multiselect-option:hover {
                        background: #f0f0f1;
                    }
                    .multiselect-option input[type="checkbox"] {
                        margin: 0 8px 0 0;
                        cursor: pointer;
                    }
                    .multiselect-option span {
                        flex: 1;
                    }
                    .multiselect-option.hidden {
                        display: none;
                    }
                    
                    /* Filter Actions */
                    .filter-actions {
                        display: flex;
                        flex-direction: row;
                        gap: 16px;
                        align-items: center;
                        margin-left: auto;
                    }
                    .filter-actions > label {
                        display: none;
                    }
                    #clear-filters {
                        white-space: nowrap;
                        font-size: 13px;
                        padding: 8px 16px;
                        height: 38px;
                        line-height: 1.5;
                    }
                    #filter-count {
                        font-size: 13px;
                        color: #1d2327;
                        font-weight: 600;
                        white-space: nowrap;
                    }
                    .brand-row.filtered-out,
                    .brand-details.filtered-out {
                        display: none !important;
                    }
                    
                    @media screen and (max-width: 1200px) {
                        .filter-row {
                            flex-wrap: wrap;
                        }
                        .filter-group {
                            min-width: 180px;
                        }
                        .filter-actions {
                            width: 100%;
                            margin-left: 0;
                            margin-top: 8px;
                            justify-content: space-between;
                        }
                    }
                    
                    @media screen and (max-width: 782px) {
                        .filter-group {
                            min-width: 100%;
                            max-width: 100%;
                        }
                        .filter-actions {
                            flex-direction: column;
                            align-items: flex-start;
                            gap: 8px;
                        }
                    }
                    
                    /* Sortable Headers */
                    /* Smooth row visibility transitions */
                    .dataflair-brands-table tbody tr.brand-row {
                        transition: opacity 0.15s ease;
                    }
                    .dataflair-brands-table {
                        transition: opacity 0.1s ease;
                    }
                    .dataflair-brands-table thead th.sortable {
                        padding: 0;
                    }
                    .dataflair-brands-table .sort-link {
                        display: flex;
                        align-items: center;
                        justify-content: space-between;
                        padding: 8px 10px;
                        color: #2c3338;
                        text-decoration: none;
                        cursor: pointer;
                        transition: background-color 0.2s;
                    }
                    .dataflair-brands-table .sort-link:hover {
                        background-color: #f0f0f1;
                    }
                    .sorting-indicator {
                        display: inline-flex;
                        align-items: center;
                        margin-left: 4px;
                    }
                    .sorting-indicator .dashicons {
                        font-size: 16px;
                        width: 16px;
                        height: 16px;
                        color: #a7aaad;
                    }
                    .sort-link.asc .dashicons-sort,
                    .sort-link.desc .dashicons-sort {
                        display: none;
                    }
                    .sort-link.asc .sorting-indicator::after {
                        content: '\f142';
                        font-family: dashicons;
                        font-size: 16px;
                        color: #2271b1;
                    }
                    .sort-link.desc .sorting-indicator::after {
                        content: '\f140';
                        font-family: dashicons;
                        font-size: 16px;
                        color: #2271b1;
                    }
                    
                    /* Brand Rows */
                    .brand-row {
                        border-bottom: 2px solid #dcdcde;
                    }
                    .brand-row:last-of-type {
                        border-bottom: none;
                    }
                    .brand-row.page-hidden,
                    .brand-details.page-hidden {
                        display: none !important;
                    }
                    
                    /* Pagination */
                    .tablenav.bottom {
                        margin-top: 10px;
                    }
                    .tablenav-pages {
                        float: right;
                    }
                    .tablenav-pages .pagination-links {
                        margin-left: 10px;
                    }
                    .tablenav-pages .button[disabled] {
                        color: #a7aaad;
                        cursor: default;
                        pointer-events: none;
                    }
                    .current-page {
                        width: 40px;
                        text-align: center;
                    }
                    
                    .dataflair-brands-table .row-actions {
                        color: #999;
                        font-size: 12px;
                        padding: 2px 0 0;
                    }
                    .dataflair-brands-table .count {
                        display: inline-block;
                        background: #2271b1;
                        color: white;
                        padding: 2px 8px;
                        border-radius: 10px;
                        font-size: 11px;
                        font-weight: 600;
                    }
                    
                    /* Brand Logo */
                    .brand-logo-cell {
                        text-align: center;
                        padding: 8px !important;
                    }
                    .brand-logo-thumb {
                        width: 60px;
                        height: 45px;
                        object-fit: contain;
                        background: #1a1a1a;
                        border-radius: 6px;
                        padding: 6px;
                        display: inline-block;
                    }
                    .brand-logo-placeholder {
                        width: 60px;
                        height: 45px;
                        display: inline-flex;
                        align-items: center;
                        justify-content: center;
                        background: linear-gradient(135deg, #352d67 0%, #5a4fa5 100%);
                        color: #fff;
                        font-size: 14px;
                        font-weight: 700;
                        border-radius: 6px;
                        text-transform: uppercase;
                        letter-spacing: 1px;
                    }
                    
                    /* Toggle Button */
                    .toggle-cell {
                        padding: 8px 4px !important;
                    }
                    .brand-toggle {
                        background: none;
                        border: none;
                        cursor: pointer;
                        padding: 4px;
                        display: flex;
                        align-items: center;
                        justify-content: center;
                        transition: transform 0.2s ease;
                    }
                    .brand-toggle:hover {
                        background: #f0f0f1;
                        border-radius: 3px;
                    }
                    .brand-toggle .dashicons {
                        transition: transform 0.2s ease;
                        font-size: 20px;
                        width: 20px;
                        height: 20px;
                    }
                    .brand-toggle[aria-expanded="true"] .dashicons {
                        transform: rotate(90deg);
                    }
                    
                    /* Details Row */
                    .brand-details td {
                        padding: 0 !important;
                        background: #f9f9f9;
                        border-top: none !important;
                    }
                    .brand-details-content {
                        padding: 20px;
                    }
                    
                    /* Details Grid */
                    .details-grid {
                        display: grid;
                        grid-template-columns: repeat(3, 1fr);
                        gap: 20px;
                        margin-bottom: 20px;
                    }
                    .detail-section.full-width {
                        grid-column: 1 / -1;
                    }
                    .detail-section h4 {
                        margin: 0 0 10px 0;
                        color: #1d2327;
                        font-size: 13px;
                        font-weight: 600;
                        text-transform: uppercase;
                        letter-spacing: 0.5px;
                    }
                    .detail-content {
                        font-size: 13px;
                        line-height: 1.6;
                    }
                    
                    /* Badges */
                    .badge-list {
                        display: flex;
                        flex-wrap: wrap;
                        gap: 6px;
                    }
                    .badge {
                        display: inline-block;
                        padding: 4px 10px;
                        background: #e0e0e0;
                        border-radius: 12px;
                        font-size: 11px;
                        font-weight: 500;
                        color: #2c3338;
                    }
                    .badge-gray {
                        background: #f0f0f1;
                        color: #646970;
                    }
                    .badge.more {
                        background: #2271b1;
                        color: white;
                    }
                    .badge.with-tooltip {
                        cursor: help;
                        position: relative;
                    }
                    .badge.with-tooltip:hover {
                        background: #135e96;
                    }
                    .no-data {
                        color: #999;
                        font-style: italic;
                    }
                    .rating {
                        font-size: 16px;
                        font-weight: 600;
                        color: #2271b1;
                    }
                    
                    /* Separator */
                    .details-separator {
                        margin: 20px 0;
                        border: none;
                        border-top: 2px solid #dcdcde;
                    }
                    
                    /* Offers Section */
                    .offers-section h4 {
                        margin: 0 0 12px 0;
                        color: #1d2327;
                        font-size: 14px;
                        font-weight: 600;
                    }
                    .offers-list {
                        display: flex;
                        flex-direction: column;
                        gap: 8px;
                    }
                    .offer-item-inline {
                        display: flex;
                        align-items: center;
                        gap: 8px;
                        padding: 8px 12px;
                        background: white;
                        border: 1px solid #dcdcde;
                        border-radius: 3px;
                        font-size: 12px;
                        line-height: 1.4;
                        flex-wrap: wrap;
                    }
                    .offer-type-badge {
                        display: inline-flex;
                        padding: 3px 10px;
                        background: #2271b1;
                        color: white;
                        border-radius: 10px;
                        font-size: 11px;
                        font-weight: 600;
                        text-transform: uppercase;
                        white-space: nowrap;
                    }
                    .offer-separator {
                        color: #8c8f94;
                        font-weight: 600;
                    }
                    .offer-text-inline {
                        font-weight: 500;
                        color: #1d2327;
                    }
                    .offer-geo-inline {
                        color: #646970;
                    }
                    .offer-tracker-inline {
                        color: #646970;
                    }
                    .tracker-count-inline {
                        background: #00a32a;
                        color: white;
                        padding: 2px 8px;
                        border-radius: 10px;
                        font-weight: 600;
                        font-size: 11px;
                    }
                    .no-trackers-inline {
                        color: #999;
                        font-style: italic;
                    }
                    .offer-extra-info {
                        color: #646970;
                        font-size: 11px;
                    }
                    .badge-free-spins {
                        background: #00a32a;
                        color: white;
                        padding: 2px 8px;
                        border-radius: 10px;
                        font-weight: 600;
                        font-size: 10px;
                        text-transform: uppercase;
                    }
                    .support-available {
                        color: #00a32a;
                        font-weight: 600;
                        font-size: 13px;
                    }
                    
                    @media screen and (max-width: 782px) {
                        .details-grid {
                            grid-template-columns: 1fr;
                        }
                    }
                </style>
            <?php else: ?>
                <p>No brands synced yet. Click "Sync Brands from API" to fetch active brands.</p>
            <?php endif; ?>
            
            <hr>
            
            <h2>Brand Details</h2>
            <p>Brands are read-only and automatically synced from the DataFlair API. They are used for managing casino brands across your site.</p>
        </div>
        <?php
    }
    
    /**
     * Get last brands cron execution time
     */
    /**
     * Returns human-readable relative time string for a Unix timestamp.
     * e.g. "just now", "3 minutes ago", "2 hours ago"
     */
    private function time_ago( $timestamp ) {
        $diff = time() - $timestamp;

        if ( $diff < 10 )                    return 'just now';
        if ( $diff < 60 )                    return $diff . ' seconds ago';
        if ( $diff < 120 )                   return '1 minute ago';
        if ( $diff < 3600 )                  return floor( $diff / 60 ) . ' minutes ago';
        if ( $diff < 7200 )                  return '1 hour ago';
        if ( $diff < 86400 )                 return floor( $diff / 3600 ) . ' hours ago';
        return date( 'Y-m-d H:i', $timestamp );
    }

    /**
     * Returns a future-relative label, e.g. "in 3 minutes", "in 45 seconds"
     */
    private function time_until( $timestamp ) {
        $diff = $timestamp - time();

        if ( $diff <= 0 )       return 'any moment';
        if ( $diff < 60 )       return 'in ' . $diff . ' seconds';
        if ( $diff < 120 )      return 'in 1 minute';
        if ( $diff < 3600 )     return 'in ' . floor( $diff / 60 ) . ' minutes';
        if ( $diff < 7200 )     return 'in 1 hour';
        return 'in ' . floor( $diff / 3600 ) . ' hours';
    }

    // NOTE: get_last_brands_cron_time() removed in v1.11.0 (Phase 0B H1).
    // Admin UI now calls format_last_sync_label() below.

    /**
     * AJAX handler to sync brands in batches (one page at a time)
     */
    public function ajax_sync_brands_batch() {
        // Phase 3 — thin delegator. Nonce + capability + token precheck stay
        // at the AJAX gate. Everything below moves into BrandSyncService, which
        // preserves every Phase 0B / Phase 1 / Phase 0A invariant byte-for-byte
        // (15 MB/12 s HTTP cap, 3 MB/8 s logo cap, 25 s budget with 3 s
        // headroom, H4 memory cleanup, paginated page-1 DELETE,
        // dataflair_brand_logo_stored hook, dataflair_sync_batch_*/
        // dataflair_sync_item_failed telemetry).
        check_ajax_referer('dataflair_sync_brands_batch', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Unauthorized'));
        }

        $token = trim(get_option('dataflair_api_token'));
        if (empty($token)) {
            wp_send_json_error(array('message' => 'API token not configured. Please set your API token first.'));
        }

        $page    = isset($_POST['page']) ? intval($_POST['page']) : 1;
        $request = \DataFlair\Toplists\Sync\SyncRequest::brands($page);
        $result  = $this->brand_sync_service()->syncPage($request);

        if ($result->success) {
            wp_send_json_success($result->toArray());
        }
        wp_send_json_error(array('message' => $result->message));
    }
    
    /**
     * AJAX handler to fetch all brands (kept for backward compatibility, now triggers batch sync)
     */
    public function ajax_fetch_all_brands() {
        check_ajax_referer('dataflair_fetch_all_brands', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Unauthorized'));
        }
        
        $token = trim(get_option('dataflair_api_token'));
        if (empty($token)) {
            wp_send_json_error(array('message' => 'API token not configured. Please set your API token first.'));
        }
        
        // Return initial response - JavaScript will handle batch processing
        wp_send_json_success(array(
            'message' => 'Starting batch sync...',
            'start_batch' => true
        ));
    }
    
    /**
     * Download and save brand logo locally.
     *
     * Fires the `dataflair_brand_logo_stored` action on every successful return
     * (both cached-hit and freshly-downloaded paths). Sigma theme subscribes to
     * this hook; see Phase 0A H0 in docs/plans.
     *
     * Action signature:
     *   do_action('dataflair_brand_logo_stored',
     *       int    $brand_id,     // wp_dataflair_brands.api_brand_id
     *       string $local_url,    // absolute URL to the stored logo
     *       string $remote_url    // upstream URL we fetched from
     *   );
     *
     * @param array  $brand_data Brand data from API
     * @param string $brand_slug Brand slug for filename
     * @return string|false Local URL to saved logo or false on failure
     */
    private function download_brand_logo($brand_data, $brand_slug) {
        // Phase 2 — delegate to the extracted LogoDownloaderInterface. All
        // Phase 0B H3 invariants (3 MB size cap, 8 s timeout, HEAD-first,
        // dataflair_brand_logo_stored hook, 7-day reuse window) preserved.
        return $this->logo_downloader()->download($brand_data, (string) $brand_slug);
    }

    // NOTE: sync_brands_page() moved to DataFlair\Toplists\Sync\BrandSyncService
    // in v1.12.1 (Phase 3). All Phase 0B / Phase 1 / Phase 0A invariants are
    // preserved byte-for-byte inside the service.
    //
    // NOTE: sync_all_brands() removed in v1.11.0 (Phase 0B H1). Batched
    // sync via ajax_sync_brands_batch is now the only path — cron is gone.

    /**
     * AJAX handler to get alternative toplists for a toplist
     */
    public function ajax_get_alternative_toplists() {
        check_ajax_referer('dataflair_save_settings', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Unauthorized'));
        }
        
        $toplist_id = isset($_POST['toplist_id']) ? intval($_POST['toplist_id']) : 0;
        
        if (!$toplist_id) {
            wp_send_json_error(array('message' => 'Invalid toplist ID'));
        }
        
        global $wpdb;
        $alt_table = $wpdb->prefix . DATAFLAIR_ALTERNATIVE_TOPLISTS_TABLE_NAME;
        
        // Ensure table exists
        $this->ensure_alternative_toplists_table();
        
        $alternatives = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $alt_table WHERE toplist_id = %d ORDER BY geo ASC",
            $toplist_id
        ), ARRAY_A);
        
        wp_send_json_success(array('alternatives' => $alternatives));
    }
    
    /**
     * AJAX handler to save an alternative toplist mapping
     */
    public function ajax_save_alternative_toplist() {
        check_ajax_referer('dataflair_save_settings', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Unauthorized'));
        }
        
        $toplist_id = isset($_POST['toplist_id']) ? intval($_POST['toplist_id']) : 0;
        $geo = isset($_POST['geo']) ? sanitize_text_field($_POST['geo']) : '';
        $alternative_toplist_id = isset($_POST['alternative_toplist_id']) ? intval($_POST['alternative_toplist_id']) : 0;
        
        error_log('DataFlair: Save alternative toplist - toplist_id: ' . $toplist_id . ', geo: ' . $geo . ', alt_toplist_id: ' . $alternative_toplist_id);
        
        if (!$toplist_id || !$geo || !$alternative_toplist_id) {
            wp_send_json_error(array('message' => 'Missing required parameters'));
        }
        
        global $wpdb;
        $alt_table = $wpdb->prefix . DATAFLAIR_ALTERNATIVE_TOPLISTS_TABLE_NAME;
        
        // Ensure table exists
        $this->ensure_alternative_toplists_table();
        
        // Check if mapping already exists
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM $alt_table WHERE toplist_id = %d AND geo = %s",
            $toplist_id,
            $geo
        ));
        
        if ($existing) {
            // Update existing mapping
            $result = $wpdb->update(
                $alt_table,
                array(
                    'alternative_toplist_id' => $alternative_toplist_id,
                    'updated_at' => current_time('mysql')
                ),
                array('id' => $existing->id),
                array('%d', '%s'),
                array('%d')
            );
            error_log('DataFlair: Update result: ' . ($result !== false ? 'success' : 'failed') . ' - ' . $wpdb->last_error);
        } else {
            // Insert new mapping
            $result = $wpdb->insert(
                $alt_table,
                array(
                    'toplist_id' => $toplist_id,
                    'geo' => $geo,
                    'alternative_toplist_id' => $alternative_toplist_id,
                    'created_at' => current_time('mysql'),
                    'updated_at' => current_time('mysql')
                ),
                array('%d', '%s', '%d', '%s', '%s')
            );
            error_log('DataFlair: Insert result: ' . ($result !== false ? 'success' : 'failed') . ' - ' . $wpdb->last_error);
        }
        
        if ($result !== false) {
            wp_send_json_success(array('message' => 'Alternative toplist saved successfully'));
        } else {
            wp_send_json_error(array('message' => 'Failed to save alternative toplist: ' . $wpdb->last_error));
        }
    }
    
    /**
     * AJAX handler to delete an alternative toplist mapping
     */
    public function ajax_delete_alternative_toplist() {
        check_ajax_referer('dataflair_save_settings', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Unauthorized'));
        }
        
        $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
        
        if (!$id) {
            wp_send_json_error(array('message' => 'Invalid ID'));
        }
        
        global $wpdb;
        $alt_table = $wpdb->prefix . DATAFLAIR_ALTERNATIVE_TOPLISTS_TABLE_NAME;
        
        // Ensure table exists
        $this->ensure_alternative_toplists_table();
        
        $result = $wpdb->delete($alt_table, array('id' => $id), array('%d'));
        
        if ($result !== false) {
            wp_send_json_success(array('message' => 'Alternative toplist deleted successfully'));
        } else {
            wp_send_json_error(array('message' => 'Failed to delete alternative toplist'));
        }
    }
    
    /**
     * AJAX handler to get available geos from toplists data
     */
    public function ajax_get_available_geos() {
        check_ajax_referer('dataflair_save_settings', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Unauthorized'));
        }
        
        global $wpdb;
        $toplists_table = $wpdb->prefix . DATAFLAIR_TABLE_NAME;
        
        // Ensure table exists
        $this->ensure_alternative_toplists_table();
        
        // Get all toplists and extract unique geos from their data
        $toplists = $wpdb->get_results("SELECT data FROM $toplists_table", ARRAY_A);
        
        $all_geos = array();
        
        foreach ($toplists as $toplist) {
            if (!empty($toplist['data'])) {
                $toplist_data = json_decode($toplist['data'], true);
                
                // Get geo name from toplist data
                // Format: "geo": { "geo_type": "country", "name": "United Kingdom" }
                if (isset($toplist_data['data']['geo']['name'])) {
                    $geo_name = $toplist_data['data']['geo']['name'];
                    if (!in_array($geo_name, $all_geos)) {
                        $all_geos[] = $geo_name;
                    }
                }
            }
        }
        
        sort($all_geos);
        
        wp_send_json_success(array('geos' => $all_geos));
    }

    /**
     * AJAX: Fetch a live API response for the admin preview tab.
     * Uses the stored token and base URL — no credentials are returned to the browser.
     */
    public function ajax_api_preview() {
        check_ajax_referer('dataflair_api_preview', '_ajax_nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $token = trim(get_option('dataflair_api_token', ''));
        if (empty($token)) {
            wp_send_json_error('No API token configured.');
        }

        $endpoint_key = isset($_POST['endpoint']) ? sanitize_text_field($_POST['endpoint']) : '';
        $resource_id  = isset($_POST['resource_id']) ? absint($_POST['resource_id']) : 0;

        $base_url = rtrim($this->get_api_base_url(), '/');
        $start = microtime(true);

        switch ($endpoint_key) {
            case 'toplists':
                $url = $base_url . '/toplists';
                break;
            case 'toplists/custom':
                if (!$resource_id) {
                    wp_send_json_error('Resource ID required for single toplist.');
                }
                $url = $base_url . '/toplists/' . $resource_id;
                break;
            case 'brands':
                $url = $base_url . '/brands';
                break;
            case 'brands/custom':
                if (!$resource_id) {
                    wp_send_json_error('Resource ID required for single brand.');
                }
                $url = $base_url . '/brands/' . $resource_id;
                break;
            case 'brands_v2':
                $v2_base = preg_replace('#/api/v\d+$#', '/api/v2', $base_url);
                $url = rtrim($v2_base, '/') . '/brands';
                break;
            default:
                wp_send_json_error('Unknown endpoint.');
        }

        $response = $this->api_get($url, $token);

        if (is_wp_error($response)) {
            wp_send_json_error($response->get_error_message());
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $raw_body    = wp_remote_retrieve_body($response);

        // Pretty-print JSON if possible
        $decoded = json_decode($raw_body, true);
        $pretty  = ($decoded !== null) ? json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : $raw_body;

        $elapsed = round((microtime(true) - $start) * 1000) . 'ms';

        wp_send_json_success(array(
            'url'     => $url,
            'status'  => $status_code . ' ' . get_status_header_desc($status_code),
            'body'    => $pretty,
            'elapsed' => $elapsed,
        ));
    }

    /**
     * Save a per-brand review URL override via AJAX.
     *
     * @return void
     */
    public function ajax_save_review_url(): void {
        check_ajax_referer('dataflair_save_review_url', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }
        global $wpdb;
        $brand_id   = intval($_POST['brand_id'] ?? 0);
        $review_url = sanitize_text_field($_POST['review_url'] ?? '');
        if (!$brand_id) {
            wp_send_json_error('Invalid brand ID');
        }
        $table = $wpdb->prefix . DATAFLAIR_BRANDS_TABLE_NAME;
        $wpdb->update(
            $table,
            ['review_url_override' => $review_url ?: null],
            ['api_brand_id' => $brand_id],
            ['%s'],
            ['%d']
        );
        wp_send_json_success();
    }

    /**
     * Discover all toplist endpoints by paginating through the /toplists index.
     *
     * The v2 API paginates (default 15/page). This method walks every page and
     * collects the full list of toplist show-endpoints. Returns an array of
     * endpoint URLs, or an empty array on failure.
     *
     * @param string $token Bearer token
     * @return string[] Toplist endpoint URLs
     */
    private function discover_toplist_endpoints($token) {
        $base_url = $this->get_api_base_url();
        $endpoints = array();
        $current_page = 1;
        $last_page = 1;

        do {
            $list_url = $base_url . '/toplists?per_page=15&page=' . $current_page;
            $response = $this->api_get($list_url, $token);

            if (is_wp_error($response)) {
                error_log('DataFlair discover_toplist_endpoints error (page ' . $current_page . '): ' . $response->get_error_message());
                break;
            }

            $status_code = wp_remote_retrieve_response_code($response);
            if ($status_code !== 200) {
                error_log('DataFlair discover_toplist_endpoints: HTTP ' . $status_code . ' on page ' . $current_page);
                break;
            }

            $data = json_decode(wp_remote_retrieve_body($response), true);
            if (json_last_error() !== JSON_ERROR_NONE || !isset($data['data'])) {
                error_log('DataFlair discover_toplist_endpoints: invalid JSON on page ' . $current_page);
                break;
            }

            foreach ($data['data'] as $toplist) {
                if (isset($toplist['id'])) {
                    $endpoints[] = $base_url . '/toplists/' . $toplist['id'];
                }
            }

            // Laravel paginated response includes meta.last_page
            if (isset($data['meta']['last_page'])) {
                $last_page = (int) $data['meta']['last_page'];
            }

            $current_page++;
        } while ($current_page <= $last_page);

        return $endpoints;
    }

    /**
     * Fetch and store single toplist
     */
    private function fetch_and_store_toplist($endpoint, $token) {
        global $wpdb;
        $table_name = $wpdb->prefix . DATAFLAIR_TABLE_NAME;

        $response = $this->api_get($endpoint, $token);

        if (is_wp_error($response)) {
            $error_message = 'DataFlair API Error for ' . $endpoint . ': ' . $response->get_error_message();
            error_log($error_message);
            add_settings_error('dataflair_messages', 'dataflair_api_error', $error_message, 'error');
            return false;
        }

        $status_code     = wp_remote_retrieve_response_code($response);
        $body            = wp_remote_retrieve_body($response);
        $response_headers = wp_remote_retrieve_headers($response);

        // Log response for debugging
        error_log('DataFlair API Response Code: ' . $status_code . ' for endpoint: ' . $endpoint);

        if ($status_code !== 200) {
            $error_message = $this->build_detailed_api_error($status_code, $body, $response_headers, $endpoint);
            error_log('DataFlair fetch_and_store_toplist error: ' . $error_message);
            add_settings_error('dataflair_messages', 'dataflair_api_error', $error_message, 'error');
            return false;
        }

        $response_data = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $error_message = 'DataFlair JSON Parse Error: ' . json_last_error_msg() . ' for ' . $endpoint;
            error_log($error_message);
            add_settings_error('dataflair_messages', 'dataflair_json_error', $error_message, 'error');
            return false;
        }

        if (!isset($response_data['data']['id'])) {
            $error_message = 'DataFlair API Error: Invalid response format for ' . $endpoint . '. Response: ' . substr($body, 0, 300);
            error_log($error_message);
            add_settings_error('dataflair_messages', 'dataflair_format_error', $error_message, 'error');
            return false;
        }

        $toplist_data = $response_data['data'];

        return $this->store_toplist_data($toplist_data, $body);
    }
    
    /**
     * Store toplist data directly (used when full data is already fetched in bulk)
     */
    private function store_toplist_data($toplist_data, $body) {
        global $wpdb;
        $table_name = $wpdb->prefix . DATAFLAIR_TABLE_NAME;

        if (!isset($toplist_data['id'])) {
            $error_message = 'DataFlair API Error: Invalid response format. Response: ' . substr($body, 0, 300);
            error_log($error_message);
            add_settings_error('dataflair_messages', 'dataflair_format_error', $error_message, 'error');
            return false;
        }

        // ── Run data integrity validation ──
        require_once DATAFLAIR_PLUGIN_DIR . 'includes/DataIntegrityChecker.php';
        $integrity = DataFlair_DataIntegrityChecker::validate($toplist_data);

        // Log warnings to PHP error log for monitoring (first 5 only to avoid log spam)
        if (!empty($integrity['warnings'])) {
            error_log(sprintf(
                '[DataFlair Sync] Toplist #%d (%s): %d warning(s) — %s',
                $toplist_data['id'],
                $toplist_data['name'] ?? 'unknown',
                count($integrity['warnings']),
                implode('; ', array_slice($integrity['warnings'], 0, 5))
            ));
        }

        $api_id  = $toplist_data['id'];
        $name    = $toplist_data['name'] ?? '';
        $version = $toplist_data['version'] ?? '';

        // Build the full data row (warnings are informational — they NEVER block the sync)
        $data_row = array(
            'name'           => $name,
            'slug'           => $toplist_data['slug'] ?? null,
            'current_period' => $toplist_data['currentPeriod'] ?? null,
            'published_at'   => isset($toplist_data['publishedAt']) ? date('Y-m-d H:i:s', strtotime($toplist_data['publishedAt'])) : null,
            'item_count'     => $integrity['item_count'],
            'locked_count'   => $integrity['locked_count'],
            'sync_warnings'  => !empty($integrity['warnings']) ? wp_json_encode($integrity['warnings']) : null,
            'data'           => $body,
            'version'        => $version,
            'last_synced'    => current_time('mysql'),
        );

        // Insert or update by api_toplist_id (upsert key never changes)
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM $table_name WHERE api_toplist_id = %d",
            $api_id
        ));

        // Format types matching $data_row key order:
        // name(%s), slug(%s), current_period(%s), published_at(%s),
        // item_count(%d), locked_count(%d), sync_warnings(%s),
        // data(%s), version(%s), last_synced(%s)
        $update_formats = array('%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s');

        if ($existing) {
            $result = $wpdb->update(
                $table_name,
                $data_row,
                array('api_toplist_id' => $api_id),
                $update_formats,
                array('%d')
            );

            if ($result === false) {
                $error_message = sprintf(
                    'DataFlair DB Update Error for toplist #%d: %s',
                    $api_id,
                    $wpdb->last_error ?: 'Unknown error'
                );
                error_log($error_message);
                add_settings_error('dataflair_messages', 'dataflair_db_error', $error_message, 'error');
                return false;
            }
        } else {
            $data_row['api_toplist_id'] = $api_id;
            // Append api_toplist_id format (%d) to the end
            $insert_formats = array_merge($update_formats, array('%d'));

            $result = $wpdb->insert(
                $table_name,
                $data_row,
                $insert_formats
            );

            if ($result === false) {
                $error_message = sprintf(
                    'DataFlair DB Insert Error for toplist #%d: %s',
                    $api_id,
                    $wpdb->last_error ?: 'Unknown error'
                );
                error_log($error_message);
                add_settings_error('dataflair_messages', 'dataflair_db_error', $error_message, 'error');
                return false;
            }
        }

        return true;
    }

    /**
     * Shortcode handler
     */
    public function toplist_shortcode($atts) {
        // Phase 1 — observability: capture render wall time + item count.
        $render_t0 = microtime(true);

        // Extract shortcode-specific attributes
        $shortcode_defaults = array(
            'id'    => '',  // Primary — looks up by api_toplist_id
            'slug'  => '',  // Optional — looks up by slug column
            'title' => '',
            'limit' => 0,
            'layout' => 'cards',
        );

        // Merge with defaults but preserve all other attributes (for customization)
        $atts = wp_parse_args($atts, $shortcode_defaults);

        do_action('dataflair_render_started', array(
            'toplist_id' => (int) ($atts['id'] ?? 0),
            'slug'       => (string) ($atts['slug'] ?? ''),
            'layout'     => (string) ($atts['layout'] ?? 'cards'),
        ));

        if (empty($atts['id']) && empty($atts['slug'])) {
            return '<p style="color: red;">DataFlair Error: Toplist ID or slug is required</p>';
        }

        global $wpdb;
        $table_name = $wpdb->prefix . DATAFLAIR_TABLE_NAME;

        // Look up toplist by slug first, then by API ID
        if (!empty($atts['slug'])) {
            $toplist = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM $table_name WHERE slug = %s LIMIT 1",
                $atts['slug']
            ));
        } else {
            $toplist = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM $table_name WHERE api_toplist_id = %d",
                intval($atts['id'])
            ));
        }
        
        if (!$toplist) {
            $identifier = !empty($atts['slug'])
                ? 'slug "' . esc_html($atts['slug']) . '"'
                : 'ID ' . esc_html($atts['id']);
            return '<p style="color: red;">DataFlair Error: Toplist ' . $identifier . ' not found. Please sync first.</p>';
        }
        
        $data = json_decode($toplist->data, true);
        
        if (!isset($data['data']['items'])) {
            return '<p style="color: red;">DataFlair Error: Invalid toplist data</p>';
        }
        
        // Check if data is stale (older than 3 days)
        $last_synced = strtotime($toplist->last_synced);
        $is_stale = (time() - $last_synced) > (3 * 24 * 60 * 60);
        
        $items = $data['data']['items'];
        
        // Apply limit
        if ($atts['limit'] > 0) {
            $items = array_slice($items, 0, $atts['limit']);
        }
        
        // Use custom title or default
        $title = !empty($atts['title']) ? $atts['title'] : $data['data']['name'];
        
        // Extract customization attributes (all attributes except shortcode-specific ones)
        $customizations = $atts;
        $pros_cons_data = isset($customizations['prosCons']) ? $customizations['prosCons'] : array();
        unset($customizations['id'], $customizations['title'], $customizations['limit'], $customizations['layout'], $customizations['prosCons']);

        if (isset($atts['layout']) && $atts['layout'] === 'table') {
            $table_html = $this->render_toplist_table($items, $title, $is_stale, $last_synced, $pros_cons_data);
            do_action('dataflair_render_finished', array(
                'toplist_id'  => (int) ($atts['id'] ?? 0),
                'item_count'  => count($items),
                'elapsed_ms'  => (int) round((microtime(true) - $render_t0) * 1000),
                'layout'      => 'table',
            ));
            return $table_html;
        }

        ob_start();
        ?>
        <div class="dataflair-toplist">
            <?php if ($is_stale): ?>
                <div class="dataflair-notice">
                    ⚠️ This data was last updated on <?php echo date('M d, Y', $last_synced); ?>. Using cached version.
                </div>
            <?php endif; ?>
            
            <?php if (!empty($title)): ?>
            <h2 class="dataflair-title"><?php echo esc_html($title); ?></h2>
            <?php endif; ?>
            
                        <?php
            // Phase 0B H7: prefetch every card's brand row in one (or at most
            // three) SQL round-trip instead of 5 cascading per-card queries.
            $brand_meta_map = $this->prefetch_brand_metas_for_items($items);
            foreach ($items as $item):
                echo $this->render_casino_card($item, $atts['id'], $customizations, $pros_cons_data, $brand_meta_map);
            endforeach; ?>
        </div>
        <?php
        $html = ob_get_clean();

        do_action('dataflair_render_finished', array(
            'toplist_id'  => (int) ($atts['id'] ?? 0),
            'item_count'  => count($items),
            'elapsed_ms'  => (int) round((microtime(true) - $render_t0) * 1000),
            'layout'      => 'cards',
        ));

        return $html;
    }

    /**
     * Render a simplified table layout for block testing.
     *
     * @param array $items
     * @param string $title
     * @param bool $is_stale
     * @param int $last_synced
     * @param array $pros_cons_data
     * @return string
     */
    private function render_toplist_table($items, $title, $is_stale, $last_synced, $pros_cons_data = array()) {
        // Phase 4 — delegate to TableRenderer. Filterable via `dataflair_table_renderer`.
        return $this->table_renderer()->render(
            new \DataFlair\Toplists\Frontend\Render\ViewModels\ToplistTableVM(
                (array) $items,
                (string) $title,
                (bool) $is_stale,
                (int) $last_synced,
                (array) $pros_cons_data
            )
        );
    }

    /**
     * Resolve pros/cons for the table row, honoring block-level overrides first.
     *
     * @param array $item
     * @param array $pros_cons_data
     * @return array{pros:array,cons:array}
     */
    private function resolve_pros_cons_for_table_item($item, $pros_cons_data) {
        $fallback = array(
            'pros' => array(),
            'cons' => array(),
        );

        if (!empty($item['pros']) && is_array($item['pros'])) {
            $fallback['pros'] = array_values(array_filter(array_map('trim', $item['pros']), function($value) {
                return $value !== '';
            }));
        }
        if (!empty($item['cons']) && is_array($item['cons'])) {
            $fallback['cons'] = array_values(array_filter(array_map('trim', $item['cons']), function($value) {
                return $value !== '';
            }));
        }

        if (empty($pros_cons_data) || !is_array($pros_cons_data)) {
            return $fallback;
        }

        $brand = isset($item['brand']) && is_array($item['brand']) ? $item['brand'] : array();
        $brand_name = isset($brand['name']) ? (string) $brand['name'] : '';
        $brand_slug = sanitize_title($brand_name);
        $position = isset($item['position']) ? (int) $item['position'] : 0;
        $item_id = isset($item['id']) ? (int) $item['id'] : 0;
        $brand_id = 0;

        if (!empty($brand['id'])) {
            $brand_id = (int) $brand['id'];
        } elseif (!empty($brand['api_brand_id'])) {
            $brand_id = (int) $brand['api_brand_id'];
        } elseif (!empty($item['brandId'])) {
            $brand_id = (int) $item['brandId'];
        }

        $candidate_keys = array();
        if ($brand_id > 0) {
            $candidate_keys[] = 'casino-brand-' . $brand_id;
        }
        if ($item_id > 0) {
            $candidate_keys[] = 'casino-item-' . $item_id;
        }
        if (!empty($brand_slug)) {
            $candidate_keys[] = 'casino-slug-' . $brand_slug;
            $candidate_keys[] = 'casino-' . $position . '-' . $brand_slug;
        }

        foreach ($candidate_keys as $candidate_key) {
            if (empty($pros_cons_data[$candidate_key]) || !is_array($pros_cons_data[$candidate_key])) {
                continue;
            }

            $override = $pros_cons_data[$candidate_key];
            return array(
                'pros' => !empty($override['pros']) && is_array($override['pros']) ? array_values(array_filter(array_map('trim', $override['pros']), function($value) {
                    return $value !== '';
                })) : $fallback['pros'],
                'cons' => !empty($override['cons']) && is_array($override['cons']) ? array_values(array_filter(array_map('trim', $override['cons']), function($value) {
                    return $value !== '';
                })) : $fallback['cons'],
            );
        }

        return $fallback;
    }
    
    /**
     * Find an existing review CPT when the post slug differs from the API brand slug
     * (e.g. live URL /reviews/1xbet-sportsbook-india/ vs brand slug 1xbet-sportsbook).
     * Matches _review_brand_id to api_brand_id / id — same idea as render-casino-card pros fallback.
     *
     * @return WP_Post|null
     */
    private function find_review_post_by_brand_meta(array $brand) {
        global $wpdb;

        $ids = array();
        foreach (array('api_brand_id', 'id') as $key) {
            if (! empty($brand[ $key ])) {
                $v = intval($brand[ $key ]);
                if ($v > 0) {
                    $ids[ $v ] = true;
                }
            }
        }
        if (empty($ids)) {
            return null;
        }

        $bid_list = array_keys( $ids );
        // Direct SQL avoids third-party pre_get_posts / meta_query quirks; match string or numeric meta_value.
        $in_placeholders = implode( ',', array_fill( 0, count( $bid_list ), '%s' ) );
        $sql               = "SELECT p.ID, p.post_status FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_review_brand_id'
            WHERE p.post_type = 'review'
            AND p.post_status IN ('publish','draft','pending','future','private')
            AND pm.meta_value IN ($in_placeholders)
            ORDER BY p.post_modified DESC";

        $rows = $wpdb->get_results( $wpdb->prepare( $sql, ...array_map( 'strval', $bid_list ) ) );

        if ( empty( $rows ) && count( $bid_list ) === 1 ) {
            // Some sites store _review_brand_id as integer-ish without strict string match.
            $one = (int) $bid_list[0];
            $sql2 = "SELECT p.ID, p.post_status FROM {$wpdb->posts} p
                INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_review_brand_id'
                WHERE p.post_type = 'review'
                AND p.post_status IN ('publish','draft','pending','future','private')
                AND CAST(pm.meta_value AS UNSIGNED) = %d
                ORDER BY p.post_modified DESC";
            $rows = $wpdb->get_results( $wpdb->prepare( $sql2, $one ) );
        }

        if (empty($rows)) {
            return null;
        }

        foreach ($rows as $row) {
            if ('publish' === $row->post_status) {
                return get_post( (int) $row->ID );
            }
        }

        return get_post( (int) $rows[0]->ID );
    }

    /**
     * Get or create review post for a casino brand
     * Auto-creates draft review if it doesn't exist
     *
     * @param array $brand Brand data from API
     * @param array $item Full toplist item data
     * @return int|false Post ID of review, or false on failure
     */
    private function get_or_create_review_post($brand, $item) {
        // Check if review post type exists
        if (!post_type_exists('review')) {
            error_log('DataFlair: Review post type not registered');
            return false;
        }
        
        $brand_slug = !empty($brand['slug']) ? $brand['slug'] : sanitize_title($brand['name']);
        $brand_name = !empty($brand['name']) ? $brand['name'] : 'Unknown Casino';
        
        // Exact slug match: only trust it if published. Otherwise a plugin-created draft at
        // {slug} blocks discovery of the live review at {slug}-india (same _review_brand_id).
        $existing_review = get_page_by_path($brand_slug, OBJECT, 'review');
        if ($existing_review instanceof WP_Post && 'publish' === $existing_review->post_status) {
            return $existing_review->ID;
        }

        // Published review may use a different slug (e.g. 1xbet-sportsbook-india vs API 1xbet-sportsbook)
        $by_meta = $this->find_review_post_by_brand_meta($brand);
        if ($by_meta instanceof WP_Post && 'publish' === $by_meta->post_status) {
            return $by_meta->ID;
        }

        if ($existing_review instanceof WP_Post) {
            return $existing_review->ID;
        }

        if ($by_meta instanceof WP_Post) {
            return $by_meta->ID;
        }
        
        // Auto-create draft review post
        $review_data = array(
            'post_title'   => $brand_name . ' Review',
            'post_name'    => $brand_slug,
            'post_content' => '',
            'post_status'  => 'draft',
            'post_type'    => 'review',
            'post_author'  => get_current_user_id() ?: 1,
        );
        
        $review_id = wp_insert_post($review_data);
        
        if (is_wp_error($review_id)) {
            error_log('DataFlair: Failed to create review post: ' . $review_id->get_error_message());
            return false;
        }
        
        // Populate meta fields with brand data (prefer id; else api_brand_id so _review_brand_id matches toplist JSON)
        $brand_id_for_meta = ! empty( $brand['id'] ) ? intval( $brand['id'] ) : ( ! empty( $brand['api_brand_id'] ) ? intval( $brand['api_brand_id'] ) : 0 );
        if ( $brand_id_for_meta > 0 ) {
            update_post_meta( $review_id, '_review_brand_id', $brand_id_for_meta );
        }
        
        // Extract and save logo URL
        $logo_url = '';
        if (!empty($brand['logo'])) {
            if (is_array($brand['logo'])) {
                $logo_url = $brand['logo']['rectangular'] ?? $brand['logo']['square'] ?? $brand['logo']['url'] ?? '';
            } else {
                $logo_url = $brand['logo'];
            }
        }
        
        // Save all review meta fields
        update_post_meta($review_id, '_review_brand_name', $brand_name);
        update_post_meta($review_id, '_review_logo', $logo_url);
        update_post_meta($review_id, '_review_url', !empty($item['offer']['tracking_url']) ? $item['offer']['tracking_url'] : '');
        update_post_meta($review_id, '_review_rating', !empty($item['rating']) ? $item['rating'] : (!empty($brand['rating']) ? $brand['rating'] : ''));
        update_post_meta($review_id, '_review_bonus', !empty($item['offer']['offerText']) ? $item['offer']['offerText'] : '');
        
        // Save payment methods
        $payments = array();
        if (!empty($item['paymentMethods'])) {
            $payments = is_array($item['paymentMethods']) ? $item['paymentMethods'] : explode(',', $item['paymentMethods']);
        } elseif (!empty($brand['paymentMethods'])) {
            $payments = is_array($brand['paymentMethods']) ? $brand['paymentMethods'] : explode(',', $brand['paymentMethods']);
        }
        update_post_meta($review_id, '_review_payments', implode(', ', $payments));
        
        // Save licenses
        $licenses = array();
        if (!empty($brand['licenses'])) {
            $licenses = is_array($brand['licenses']) ? $brand['licenses'] : explode(',', $brand['licenses']);
        }
        update_post_meta($review_id, '_review_licenses', implode(', ', $licenses));
        
        error_log('DataFlair: Auto-created draft review post #' . $review_id . ' for ' . $brand_name);
        
        return $review_id;
    }
    
    /**
     * Phase 0B H7: Prefetch brand metadata for every item in a toplist in a
     * single (or at most three) SQL round-trip instead of the five cascading
     * $wpdb->prepare calls render_casino_card() previously ran per item.
     *
     * Returns ['ids' => [api_brand_id => row], 'slugs' => [...], 'names' => [...]]
     * so render_casino_card() can resolve each card's brand via a cheap map
     * lookup matching the legacy cascade order.
     *
     * @param array $items Items array from the toplist payload.
     * @return array{ids: array<int,object>, slugs: array<string,object>, names: array<string,object>}
     */
    private function prefetch_brand_metas_for_items(array $items) {
        global $wpdb;
        $brands_table = $wpdb->prefix . DATAFLAIR_BRANDS_TABLE_NAME;

        $wanted_ids = array();
        $wanted_slugs = array();
        $wanted_names = array();
        foreach ($items as $item) {
            $brand = isset($item['brand']) && is_array($item['brand']) ? $item['brand'] : array();
            if (!empty($brand['api_brand_id'])) $wanted_ids[intval($brand['api_brand_id'])] = true;
            if (!empty($brand['id']))            $wanted_ids[intval($brand['id'])] = true;
            if (!empty($brand['slug']))          $wanted_slugs[(string) $brand['slug']] = true;
            if (!empty($brand['name']))          $wanted_names[(string) $brand['name']] = true;
        }

        $by_id = array();
        $by_slug = array();
        $by_name = array();
        $columns = 'api_brand_id, slug, name, local_logo_url, cached_review_post_id, review_url_override';

        if (!empty($wanted_ids)) {
            // Phase 2 — H7 api_brand_id IN (...) batched fetch delegates to BrandsRepository.
            // Repository returns ARRAY_A keyed by api_brand_id; recast to objects to keep
            // downstream callers (render_casino_card, lookup_brand_meta_from_map) byte-compatible.
            $id_list = array_keys($wanted_ids);
            foreach ($this->brands_repo()->findManyByApiBrandIds($id_list) as $api_id => $row_array) {
                $row = (object) $row_array;
                $by_id[(int) $api_id] = $row;
                if (!empty($row->slug) && !isset($by_slug[(string) $row->slug])) $by_slug[(string) $row->slug] = $row;
                if (!empty($row->name) && !isset($by_name[(string) $row->name])) $by_name[(string) $row->name] = $row;
            }
        }

        $missing_slugs = array_diff_key($wanted_slugs, $by_slug);
        if (!empty($missing_slugs)) {
            $slug_list = array_keys($missing_slugs);
            $placeholders = implode(',', array_fill(0, count($slug_list), '%s'));
            $sql = $wpdb->prepare(
                "SELECT $columns FROM $brands_table WHERE slug IN ($placeholders)",
                $slug_list
            );
            foreach ((array) $wpdb->get_results($sql) as $row) {
                if (!empty($row->api_brand_id) && !isset($by_id[intval($row->api_brand_id)])) $by_id[intval($row->api_brand_id)] = $row;
                if (!empty($row->slug)) $by_slug[(string) $row->slug] = $row;
                if (!empty($row->name) && !isset($by_name[(string) $row->name])) $by_name[(string) $row->name] = $row;
            }
        }

        $missing_names = array_diff_key($wanted_names, $by_name);
        if (!empty($missing_names)) {
            $name_list = array_keys($missing_names);
            $placeholders = implode(',', array_fill(0, count($name_list), '%s'));
            $sql = $wpdb->prepare(
                "SELECT $columns FROM $brands_table WHERE name IN ($placeholders)",
                $name_list
            );
            foreach ((array) $wpdb->get_results($sql) as $row) {
                if (!empty($row->api_brand_id) && !isset($by_id[intval($row->api_brand_id)])) $by_id[intval($row->api_brand_id)] = $row;
                if (!empty($row->slug) && !isset($by_slug[(string) $row->slug])) $by_slug[(string) $row->slug] = $row;
                if (!empty($row->name)) $by_name[(string) $row->name] = $row;
            }
        }

        return array('ids' => $by_id, 'slugs' => $by_slug, 'names' => $by_name);
    }

    /**
     * Phase 0B H7: Resolve a single item's brand row from the prefetched map
     * using the same cascading preference the legacy per-card queries used.
     *
     * @param array $brand    The item's brand payload (api_brand_id/id/slug/name).
     * @param array $meta_map Output of prefetch_brand_metas_for_items().
     * @return object|null
     */
    private function lookup_brand_meta_from_map(array $brand, array $meta_map) {
        if (!empty($brand['api_brand_id']) && isset($meta_map['ids'][intval($brand['api_brand_id'])])) {
            return $meta_map['ids'][intval($brand['api_brand_id'])];
        }
        if (!empty($brand['id']) && isset($meta_map['ids'][intval($brand['id'])])) {
            return $meta_map['ids'][intval($brand['id'])];
        }
        if (!empty($brand['slug']) && isset($meta_map['slugs'][(string) $brand['slug']])) {
            return $meta_map['slugs'][(string) $brand['slug']];
        }
        if (!empty($brand['name']) && isset($meta_map['names'][(string) $brand['name']])) {
            return $meta_map['names'][(string) $brand['name']];
        }
        return null;
    }

    /**
     * Phase 0B H8: Batched review-post lookup. Given a list of api_brand_ids,
     * returns [api_brand_id => post_id] for brands whose review CPT is
     * published but does not yet have cached_review_post_id populated on the
     * brands table. Intended as a defensive backstop only — normal render
     * paths read cached_review_post_id directly and never hit this code.
     *
     * @param int[] $brand_ids
     * @return array<int,int>
     */
    private function find_review_posts_by_brand_metas(array $brand_ids) {
        // Phase 2 — delegate to BrandsRepository. H8 INNER JOIN logic preserved.
        return $this->brands_repo()->findReviewPostsByApiBrandIds($brand_ids);
    }

    /**
     * Render individual casino card
     * Uses the new structured template for better layout
     *
     * @codeCoverageIgnore
     */
    private function render_casino_card($item, $toplist_id, $customizations = array(), $pros_cons_data = array(), $brand_meta_map = null) {
        // Phase 4 — delegate to CardRenderer. Filterable via `dataflair_card_renderer`.
        // The pre-Phase-0A legacy fallback (sideload logos + auto-create
        // review CPTs at render time) is gone — it was unreachable once the
        // bundled template existed on disk and violated the Phase 0A
        // read-only contract enforced by RenderIsReadOnlyTest.
        return $this->card_renderer()->render(
            new \DataFlair\Toplists\Frontend\Render\ViewModels\CasinoCardVM(
                (array) $item,
                (int) $toplist_id,
                (array) $customizations,
                (array) $pros_cons_data,
                is_array($brand_meta_map) ? $brand_meta_map : null
            )
        );
    }
    
    /**
     * Enqueue frontend styles
     */
    public function enqueue_frontend_assets() {
        // Use file modification time as version to prevent caching issues
        $style_css_version = file_exists(DATAFLAIR_PLUGIN_DIR . 'assets/style.css') 
            ? filemtime(DATAFLAIR_PLUGIN_DIR . 'assets/style.css') 
            : DATAFLAIR_VERSION;
        
        wp_enqueue_style(
            'dataflair-toplists',
            DATAFLAIR_PLUGIN_URL . 'assets/style.css',
            array(),
            $style_css_version
        );
    }
    
    /**
     * Static flag to track if we've already checked for Alpine.js
     */
    private static $alpine_checked = false;
    private static $shortcode_used = false;
    
    /**
     * Check widget content for shortcode usage
     */
    public function check_widget_for_shortcode($text, $instance = null) {
        if (has_shortcode($text, 'dataflair_toplist')) {
            self::$shortcode_used = true;
        }
        return $text;
    }
    
    /**
     * Check if Alpine.js is already loaded and enqueue if needed
     */
    public function maybe_enqueue_alpine() {
        // Avoid checking multiple times
        if (self::$alpine_checked) {
            return;
        }
        
        self::$alpine_checked = true;
        
        // Check if shortcode or block is used on the page
        global $post;
        $has_shortcode = false;
        $has_block = false;
        
        // Check main post content
        if ($post) {
            $has_shortcode = has_shortcode($post->post_content, 'dataflair_toplist');
            $has_block = has_block('dataflair-toplists/toplist', $post);
        }
        
        // Check if widget shortcode was found
        if (self::$shortcode_used) {
            $has_shortcode = true;
        }
        
        // Check all posts in query (for archive pages, etc.)
        if (!$has_shortcode && !$has_block) {
            global $wp_query;
            if ($wp_query && !empty($wp_query->posts)) {
                foreach ($wp_query->posts as $query_post) {
                    if (has_shortcode($query_post->post_content, 'dataflair_toplist') ||
                        has_block('dataflair-toplists/toplist', $query_post)) {
                        $has_shortcode = true;
                        break;
                    }
                }
            }
        }
        
        // If no shortcode/block found, don't enqueue
        if (!$has_shortcode && !$has_block) {
            return;
        }
        
        // Check if Alpine.js is already enqueued by checking common handles
        $alpine_handles = array('alpinejs', 'alpine', 'alpine-js', 'alpine.js');
        $alpine_enqueued = false;
        
        foreach ($alpine_handles as $handle) {
            if (wp_script_is($handle, 'enqueued') || wp_script_is($handle, 'registered')) {
                $alpine_enqueued = true;
                break;
            }
        }
        
        // Also check if Alpine is loaded via inline script or other methods
        // by checking if it's in the global scripts queue
        if (!$alpine_enqueued) {
            global $wp_scripts;
            if ($wp_scripts && !empty($wp_scripts->queue)) {
                foreach ($wp_scripts->queue as $queued_handle) {
                    $script = $wp_scripts->registered[$queued_handle] ?? null;
                    if ($script && isset($script->src) && (
                        strpos($script->src, 'alpine') !== false ||
                        strpos($script->src, 'alpinejs') !== false
                    )) {
                        $alpine_enqueued = true;
                        break;
                    }
                }
            }
        }
        
        // Check if Alpine is loaded via theme or other plugins by checking script dependencies
        if (!$alpine_enqueued && isset($wp_scripts)) {
            foreach ($wp_scripts->registered as $handle => $script) {
                if (isset($script->deps) && is_array($script->deps)) {
                    foreach ($script->deps as $dep) {
                        if (in_array($dep, $alpine_handles)) {
                            $alpine_enqueued = true;
                            break 2;
                        }
                    }
                }
            }
        }
        
        // If Alpine.js is not found, enqueue it from CDN
        if (!$alpine_enqueued) {
            // Allow filtering the Alpine.js URL (for custom CDN or local version)
            $alpine_url = apply_filters('dataflair_alpinejs_url', 'https://cdn.jsdelivr.net/npm/alpinejs@3.13.5/dist/cdn.min.js');
            
            wp_enqueue_script(
                'alpinejs',
                $alpine_url,
                array(),
                '3.13.5',
                true
            );
            
            // Add defer attribute for better performance
            add_filter('script_loader_tag', array($this, 'add_alpine_defer_attribute'), 10, 2);
        }
    }
    
    /**
     * Output promo code copy-to-clipboard JS once per page footer.
     * Handles all .promo-code-copy buttons rendered by the toplist card template.
     */
    public function enqueue_promo_copy_script() {
        ?>
        <script>
        (function() {
            function initPromoCopy() {
                document.querySelectorAll('.promo-code-copy').forEach(function(btn) {
                    if (btn.dataset.promoBound) return;
                    btn.dataset.promoBound = '1';
                    btn.addEventListener('click', function() {
                        var code = btn.getAttribute('data-code');
                        navigator.clipboard.writeText(code).then(function() {
                            btn.classList.add('copied');
                            btn.querySelector('.promo-code-value').textContent = 'Copied!';
                            setTimeout(function() {
                                btn.classList.remove('copied');
                                btn.querySelector('.promo-code-value').textContent = code;
                            }, 2000);
                        });
                    });
                });
            }
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', initPromoCopy);
            } else {
                initPromoCopy();
            }
        })();
        </script>
        <?php
    }

    /**
     * Add defer attribute to Alpine.js script tag
     */
    public function add_alpine_defer_attribute($tag, $handle) {
        if ('alpinejs' === $handle) {
            return str_replace(' src', ' defer src', $tag);
        }
        return $tag;
    }
    
    /**
     * Build the "Last sync" label for the admin UI. Reads the given option
     * (still named `dataflair_last_*_cron_run` for backward compat — option
     * rename lands in Phase 1 via a dedicated migration).
     */
    /**
     * Accepts either the legacy `dataflair_last_*_cron_run` name or the new
     * `dataflair_last_*_sync` name and returns a human "Last sync: ..." label.
     *
     * Phase 1 renamed the stored option; the admin pages still pass the
     * legacy key for one release, so this helper falls back through:
     *   1. the key it was given
     *   2. the new-name twin (if caller passed legacy)
     *   3. the legacy-name twin (if caller passed new)
     */
    private function format_last_sync_label($option_key) {
        $last_run = get_option($option_key);

        if (!$last_run) {
            $alt = null;
            if (strpos($option_key, '_cron_run') !== false) {
                $alt = str_replace('_cron_run', '_sync', $option_key);
            } elseif (strpos($option_key, '_sync') !== false) {
                $alt = str_replace('_sync', '_cron_run', $option_key);
            }
            if ($alt) {
                $last_run = get_option($alt);
            }
        }

        return 'Last sync: ' . ($last_run ? $this->time_ago($last_run) : 'never');
    }


    /**
     * Register Gutenberg block
     */
    public function register_block() {
        if (!function_exists('register_block_type')) {
            return;
        }
        
        // Check for built block first, then source
        $block_json = DATAFLAIR_PLUGIN_DIR . 'build/block.json';
        if (!file_exists($block_json)) {
            $block_json = DATAFLAIR_PLUGIN_DIR . 'src/block.json';
        }
        
        if (file_exists($block_json)) {
            register_block_type($block_json, array(
                'render_callback' => array($this, 'render_block'),
                'version' => DATAFLAIR_VERSION
            ));
        }
    }
    
    /**
     * Enqueue editor assets for Gutenberg block
     */
    public function enqueue_editor_assets() {
        $editor_style = DATAFLAIR_PLUGIN_URL . 'assets/editor.css';
        wp_enqueue_style(
            'dataflair-toplist-editor',
            $editor_style,
            array(),
            DATAFLAIR_VERSION
        );
    }
    
    /**
     * Handle campaign redirect from /go/?campaign=campaign-name
     * Performs 301 redirect to tracker URL stored in transient
     */
    public function handle_campaign_redirect() {
        // Check if this is a /go/ request with campaign parameter
        if (!isset($_GET['campaign']) || empty($_GET['campaign'])) {
            return;
        }
        
        // Only handle if URL path is /go/ or contains /go
        $request_uri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';
        $parsed_url = parse_url($request_uri);
        $path = isset($parsed_url['path']) ? $parsed_url['path'] : '';
        
        // Check if path contains /go (handles /go/, /go, /go?campaign=, etc.)
        if (strpos($path, '/go') === false) {
            return;
        }
        
        $campaign_name = sanitize_text_field($_GET['campaign']);
        
        if (empty($campaign_name)) {
            // Invalid campaign name, return 404
            status_header(404);
            nocache_headers();
            return;
        }
        
        // Look up tracker URL from transient
        $transient_key = 'dataflair_tracker_' . md5($campaign_name);
        $tracker_url = get_transient($transient_key);
        
        if (empty($tracker_url) || !filter_var($tracker_url, FILTER_VALIDATE_URL)) {
            // Campaign not found or invalid URL, return 404
            status_header(404);
            nocache_headers();
            return;
        }
        
        // Perform 301 redirect to tracker URL
        wp_redirect($tracker_url, 301);
        exit;
    }
    
    /**
     * Render callback for the block
     */
    public function render_block($attributes) {
        // Get default values from settings (if set)
        $ribbon_bg_default = get_option('dataflair_ribbon_bg_color', 'brand-600');
        $ribbon_text_default = get_option('dataflair_ribbon_text_color', 'white');
        $cta_bg_default = get_option('dataflair_cta_bg_color', 'brand-600');
        $cta_text_default = get_option('dataflair_cta_text_color', 'white');
        
        // Default values from block.json (with settings as fallback)
        $defaults = array(
            'toplistId' => '',
            'title' => '',
            'limit' => 0,
            'layout' => 'cards',
            'ribbonBgColor' => $ribbon_bg_default,
            'ribbonTextColor' => $ribbon_text_default,
            'ribbonText' => 'Our Top Choice',
            'rankBgColor' => 'gray-100',
            'rankTextColor' => 'gray-900',
            'rankBorderRadius' => 'rounded',
            'brandLinkColor' => 'brand-600',
            'bonusLabelStyle' => 'text-gray-600',
            'bonusTextStyle' => 'text-gray-900 text-lg leading-6 font-semibold',
            'featureCheckBg' => 'green-100',
            'featureCheckColor' => 'green-600',
            'featureTextColor' => 'gray-600',
            'ctaBgColor' => $cta_bg_default,
            'ctaHoverBgColor' => 'brand-700',
            'ctaTextColor' => $cta_text_default,
            'ctaBorderRadius' => 'rounded',
            'ctaShadow' => 'shadow-md',
            'metricLabelStyle' => 'text-gray-600',
            'metricValueStyle' => 'text-gray-900 font-semibold',
            'rgBorderColor' => 'gray-300',
            'rgTextColor' => 'gray-600'
        );
        
        // Merge attributes with defaults (attributes take precedence)
        $atts = wp_parse_args($attributes, $defaults);
        
        $toplist_id = $atts['toplistId'];
        
        if (empty($toplist_id)) {
            return '<p>' . esc_html__('Please configure the toplist ID in the block settings.', 'dataflair-toplists') . '</p>';
        }
        
        // Pass all attributes to shortcode (using merged values)
        $shortcode_atts = array(
            'id' => $toplist_id,
            'title' => $atts['title'],
            'limit' => intval($atts['limit']),
            'layout' => $atts['layout'],
            'ribbonBgColor' => $atts['ribbonBgColor'],
            'ribbonTextColor' => $atts['ribbonTextColor'],
            'ribbonText' => $atts['ribbonText'],
            'rankBgColor' => $atts['rankBgColor'],
            'rankTextColor' => $atts['rankTextColor'],
            'rankBorderRadius' => $atts['rankBorderRadius'],
            'brandLinkColor' => $atts['brandLinkColor'],
            'bonusLabelStyle' => $atts['bonusLabelStyle'],
            'bonusTextStyle' => $atts['bonusTextStyle'],
            'featureCheckBg' => $atts['featureCheckBg'],
            'featureCheckColor' => $atts['featureCheckColor'],
            'featureTextColor' => $atts['featureTextColor'],
            'ctaBgColor' => $atts['ctaBgColor'],
            'ctaHoverBgColor' => $atts['ctaHoverBgColor'],
            'ctaTextColor' => $atts['ctaTextColor'],
            'ctaBorderRadius' => $atts['ctaBorderRadius'],
            'ctaShadow' => $atts['ctaShadow'],
            'metricLabelStyle' => $atts['metricLabelStyle'],
            'metricValueStyle' => $atts['metricValueStyle'],
            'rgBorderColor' => $atts['rgBorderColor'],
            'rgTextColor' => $atts['rgTextColor'],
            'prosCons' => isset($atts['prosCons']) ? $atts['prosCons'] : array()
        );
        
        return $this->toplist_shortcode($shortcode_atts);
    }
    
    /**
     * Register REST API routes for block editor
     */
    public function register_rest_routes() {
        register_rest_route('dataflair/v1', '/toplists', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_toplists_rest'),
            'permission_callback' => function() {
                return current_user_can('edit_posts');
            }
        ));
        
        register_rest_route('dataflair/v1', '/toplists/(?P<id>\d+)/casinos', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_toplist_casinos_rest'),
            'permission_callback' => function() {
                return current_user_can('edit_posts');
            },
            'args' => array(
                'id' => array(
                    'required' => true,
                    'type' => 'integer',
                ),
                'page' => array(
                    'required' => false,
                    'type'     => 'integer',
                    'default'  => 1,
                    'minimum'  => 1,
                ),
                'per_page' => array(
                    'required' => false,
                    'type'     => 'integer',
                    'default'  => 20,
                    'minimum'  => 1,
                    'maximum'  => 100,
                ),
                'full' => array(
                    'required' => false,
                    'type'     => 'integer',
                    'default'  => 0,
                    'enum'     => array(0, 1),
                ),
            ),
        ));

        register_rest_route('dataflair/v1', '/health', array(
            'methods'             => 'GET',
            'callback'            => function() {
                global $wpdb;
                $table = $wpdb->prefix . DATAFLAIR_TABLE_NAME;
                $count = $wpdb->get_var("SELECT COUNT(*) FROM $table");
                return rest_ensure_response(array(
                    'status'     => 'ok',
                    'toplists'   => (int) $count,
                    'plugin_ver' => DATAFLAIR_VERSION,
                    'db_error'   => $wpdb->last_error ?: null,
                ));
            },
            'permission_callback' => function() { return current_user_can('manage_options'); },
        ));
    }
    
    /**
     * REST API callback to get available toplists
     */
    public function get_toplists_rest() {
        try {
            global $wpdb;
            $table_name = $wpdb->prefix . DATAFLAIR_TABLE_NAME;

            $toplists = $wpdb->get_results("SELECT api_toplist_id, name, slug FROM $table_name ORDER BY api_toplist_id ASC");

            if ($wpdb->last_error) {
                return new WP_Error('db_error', $wpdb->last_error, array('status' => 500));
            }

            $options = array();
            foreach ($toplists as $toplist) {
                $suffix = !empty($toplist->slug)
                    ? ' [' . $toplist->slug . ']'
                    : ' (ID: ' . $toplist->api_toplist_id . ')';
                $options[] = array(
                    'value' => (string) $toplist->api_toplist_id,
                    'label' => $toplist->name . $suffix,
                );
            }

            return rest_ensure_response($options);
        } catch (\Exception $e) {
            return new WP_Error('exception', $e->getMessage(), array('status' => 500));
        }
    }
    
    /**
     * REST API callback to get casinos for a toplist.
     *
     * H12 (Phase 0B): paginate + lean per-item payload.
     *   - ?per_page default 20, max 100.
     *   - ?page default 1.
     *   - Default lean shape: {id, name, rating, offer_text, logo_url}.
     *   - ?full=1 preserves the legacy verbose shape (block editor uses it).
     *   - Emits X-WP-Total / X-WP-TotalPages headers.
     *
     * The legacy version decoded the full toplist JSON blob and returned
     * every item for every call. On a toplist with 200 items on a 1 GB
     * memory cap, that round-trip alone was ~40 MB of transient PHP memory
     * per concurrent REST call. Pagination + lean projection lands the
     * common case under 200 KB.
     */
    public function get_toplist_casinos_rest($request) {
        global $wpdb;
        $table_name = $wpdb->prefix . DATAFLAIR_TABLE_NAME;
        $toplist_id = intval($request['id']);
        $page       = max(1, intval($request->get_param('page')));
        $per_page   = min(100, max(1, intval($request->get_param('per_page'))));
        $full       = (int) $request->get_param('full') === 1;

        $toplist = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_name WHERE api_toplist_id = %d",
            $toplist_id
        ));

        if (!$toplist) {
            return new WP_Error('not_found', 'Toplist not found', array('status' => 404));
        }

        $data = json_decode($toplist->data, true);

        // Support multiple known data shapes (editions model added listItems).
        $items = null;
        if (isset($data['data']['items'])) {
            $items = $data['data']['items'];
        } elseif (isset($data['data']['listItems'])) {
            $items = $data['data']['listItems'];
        } elseif (isset($data['listItems'])) {
            $items = $data['listItems'];
        }

        if (empty($items) || !is_array($items)) {
            $response = rest_ensure_response(array());
            $response->header('X-WP-Total', '0');
            $response->header('X-WP-TotalPages', '0');
            return $response;
        }

        $total       = count($items);
        $total_pages = (int) ceil($total / $per_page);
        $offset      = ($page - 1) * $per_page;
        $page_items  = array_slice($items, $offset, $per_page);

        // Prefetch brand metas once for the paged slice so logo_url is filled
        // without N extra queries.
        $brand_meta_map = $full ? null : $this->prefetch_brand_metas_for_items($page_items);

        $casinos = array();
        foreach ($page_items as $item) {
            $brand_name = '';
            if (isset($item['brand']['name'])) {
                $brand_name = $item['brand']['name'];
            } elseif (isset($item['brandName'])) {
                $brand_name = $item['brandName'];
            }
            if (empty($brand_name)) {
                continue;
            }

            $brand_id = 0;
            if (isset($item['brand']['id'])) {
                $brand_id = intval($item['brand']['id']);
            } elseif (isset($item['brandId'])) {
                $brand_id = intval($item['brandId']);
            }

            if ($full) {
                // Legacy verbose shape — block editor + any downstream
                // consumer that still needs the full payload.
                $casinos[] = array(
                    'itemId'    => isset($item['id']) ? intval($item['id']) : 0,
                    'brandId'   => $brand_id,
                    'position'  => isset($item['position']) ? $item['position'] : 0,
                    'brandName' => $brand_name,
                    'brandSlug' => sanitize_title($brand_name),
                    'pros'      => !empty($item['pros']) ? $item['pros'] : array(),
                    'cons'      => !empty($item['cons']) ? $item['cons'] : array(),
                );
                continue;
            }

            // Lean H12 shape — the only fields most callers need.
            $rating     = isset($item['rating']) ? (float) $item['rating'] : 0.0;
            $offer      = isset($item['offer']) && is_array($item['offer']) ? $item['offer'] : array();
            $offer_text = isset($offer['offerText']) ? (string) $offer['offerText'] : '';

            $logo_url  = '';
            $brand_row = array(
                'api_brand_id' => $brand_id,
                'name'         => $brand_name,
                'slug'         => sanitize_title($brand_name),
            );
            $meta = $this->lookup_brand_meta_from_map($brand_row, $brand_meta_map ?: array());
            if (!empty($meta->local_logo_url)) {
                $logo_url = (string) $meta->local_logo_url;
            }

            $casinos[] = array(
                'id'         => isset($item['id']) ? intval($item['id']) : 0,
                'name'       => $brand_name,
                'rating'     => $rating,
                'offer_text' => $offer_text,
                'logo_url'   => $logo_url,
            );
        }

        $response = rest_ensure_response($casinos);
        $response->header('X-WP-Total', (string) $total);
        $response->header('X-WP-TotalPages', (string) $total_pages);
        return $response;
    }
    
    /**
     * Check if MySQL/MariaDB supports JSON data type
     * 
     * @return bool
     */
    private function supports_json_type() {
        global $wpdb;
        
        // Get MySQL version
        $version = $wpdb->get_var("SELECT VERSION()");
        
        if (empty($version)) {
            return false;
        }
        
        // Check if it's MariaDB
        if (stripos($version, 'mariadb') !== false) {
            // MariaDB 10.2.7+ supports JSON
            preg_match('/(\d+)\.(\d+)\.(\d+)/', $version, $matches);
            if (!empty($matches)) {
                $major = (int)$matches[1];
                $minor = (int)$matches[2];
                $patch = (int)$matches[3];
                return ($major > 10) || ($major == 10 && $minor > 2) || ($major == 10 && $minor == 2 && $patch >= 7);
            }
        } else {
            // MySQL 5.7.8+ supports JSON
            preg_match('/(\d+)\.(\d+)\.(\d+)/', $version, $matches);
            if (!empty($matches)) {
                $major = (int)$matches[1];
                $minor = (int)$matches[2];
                $patch = (int)$matches[3];
                return ($major > 5) || ($major == 5 && $minor > 7) || ($major == 5 && $minor == 7 && $patch >= 8);
            }
        }
        
        return false;
    }
    
    /**
     * Migrate data fields from longtext to JSON type
     */
    public function migrate_to_json_type() {
        // Guard: skip entirely if migration already completed.
        // MariaDB reports JSON columns as "longtext" in INFORMATION_SCHEMA, so
        // checking DATA_TYPE would always return false — causing an infinite loop.
        if (get_option('dataflair_json_migration_done')) {
            return;
        }

        global $wpdb;

        if (!$this->supports_json_type()) {
            // Mark done so we stop trying on every cron tick.
            update_option('dataflair_json_migration_done', '1');
            error_log('DataFlair: JSON type not supported by MySQL/MariaDB version. Staying on longtext.');
            return;
        }

        $table_name = $wpdb->prefix . DATAFLAIR_TABLE_NAME;
        $brands_table_name = $wpdb->prefix . DATAFLAIR_BRANDS_TABLE_NAME;

        $toplists_migrated = false;
        $brands_migrated   = false;

        // Migrate toplists table
        {
            // First, validate all JSON data
            $invalid_rows = $wpdb->get_results(
                "SELECT id FROM $table_name WHERE data IS NOT NULL AND data != ''",
                ARRAY_A
            );
            
            $valid_count = 0;
            $invalid_count = 0;
            
            foreach ($invalid_rows as $row) {
                $data = $wpdb->get_var($wpdb->prepare(
                    "SELECT data FROM $table_name WHERE id = %d",
                    $row['id']
                ));
                
                // Validate JSON
                json_decode($data);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $valid_count++;
                } else {
                    $invalid_count++;
                    error_log('DataFlair: Invalid JSON in toplist ID ' . $row['id'] . ': ' . json_last_error_msg());
                }
            }
            
            if ($invalid_count === 0) {
                $wpdb->query("ALTER TABLE $table_name MODIFY COLUMN data JSON NOT NULL");
                $toplists_migrated = true;
                error_log('DataFlair: Successfully migrated toplists data column to JSON type');
            } else {
                error_log("DataFlair: Cannot migrate toplists table - found $invalid_count invalid JSON rows");
            }
        }

        // Migrate brands table
        {
            $invalid_rows = $wpdb->get_results(
                "SELECT id FROM $brands_table_name WHERE data IS NOT NULL AND data != ''",
                ARRAY_A
            );

            $valid_count   = 0;
            $invalid_count = 0;

            foreach ($invalid_rows as $row) {
                $data = $wpdb->get_var($wpdb->prepare(
                    "SELECT data FROM $brands_table_name WHERE id = %d",
                    $row['id']
                ));

                json_decode($data);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $valid_count++;
                } else {
                    $invalid_count++;
                    error_log('DataFlair: Invalid JSON in brand ID ' . $row['id'] . ': ' . json_last_error_msg());
                }
            }

            if ($invalid_count === 0) {
                $wpdb->query("ALTER TABLE $brands_table_name MODIFY COLUMN data JSON NOT NULL");
                $brands_migrated = true;
                error_log('DataFlair: Successfully migrated brands data column to JSON type');
            } else {
                error_log("DataFlair: Cannot migrate brands table - found $invalid_count invalid JSON rows");
            }
        }

        // Mark migration done so it never runs again.
        // Run regardless of success — on MariaDB the column stays "longtext" even
        // after ALTER TABLE, so we must not rely on DATA_TYPE to detect completion.
        update_option('dataflair_json_migration_done', '1');
    }
}

// Initialize plugin
DataFlair_Toplists::get_instance();

// Register WP-CLI commands (Phase 0A H0 + Phase 0.5 perf rig).
if (defined('WP_CLI') && WP_CLI) {
    require_once DATAFLAIR_PLUGIN_DIR . 'includes/Cli/ReconcileReviewsCommand.php';
    \WP_CLI::add_command(
        'dataflair reconcile-reviews',
        \DataFlair\Toplists\Cli\ReconcileReviewsCommand::class
    );

    require_once DATAFLAIR_PLUGIN_DIR . 'includes/Cli/PerfSeedCommand.php';
    \WP_CLI::add_command(
        'dataflair perf:seed',
        \DataFlair\Toplists\Cli\PerfSeedCommand::class
    );

    require_once DATAFLAIR_PLUGIN_DIR . 'includes/Cli/PerfRunCommand.php';
    \WP_CLI::add_command(
        'dataflair perf:run',
        \DataFlair\Toplists\Cli\PerfRunCommand::class
    );

    require_once DATAFLAIR_PLUGIN_DIR . 'includes/Cli/LogsCommand.php';
    \WP_CLI::add_command(
        'dataflair logs',
        \DataFlair\Toplists\Cli\LogsCommand::class
    );
}
