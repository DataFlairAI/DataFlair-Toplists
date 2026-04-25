<?php
/**
 * Plugin-info (`plugins_api`) filter — WPPB-style class encapsulation.
 *
 * WordPress fires `plugins_api` when the "View details" popup opens on
 * `wp-admin/plugins.php`. Before v2.1.1 the hundreds of lines of embedded
 * description + changelog HTML lived inline in `dataflair-toplists.php`.
 * This class owns the filter registration, the plugin-information payload,
 * and the text content — keeping the main bootstrap file small and giving
 * the content a single, unit-testable home.
 *
 * Single responsibility: build the `stdClass` plugin-information response
 * for the `dataflair-toplists` slug. Everything else (activation, schema,
 * hooks) lives elsewhere.
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Admin;

final class PluginInfoFilter
{
    public const SLUG = 'dataflair-toplists';

    /**
     * Register the filter with WordPress. Called from Plugin::boot().
     * Idempotent — calling it twice is safe because WordPress dedupes
     * `(hook, callback, priority)` tuples.
     */
    public function register(): void
    {
        if (!function_exists('add_filter')) {
            return;
        }
        add_filter('plugins_api', [$this, 'filter'], 20, 3);
    }

    /**
     * Filter callback. Short-circuits unless WordPress is asking for
     * our plugin's information popup, then returns the fully-populated
     * `stdClass` WordPress expects.
     *
     * @param  mixed  $res    Either `false` (WP default) or a prior
     *                        filter result — we replace it when the slug
     *                        matches.
     * @param  string $action The `plugins_api` action name.
     * @param  object $args   Request args; `$args->slug` tells us which
     *                        plugin's details are being requested.
     * @return mixed          Either the original `$res` (pass-through)
     *                        or a populated `stdClass` for our slug.
     */
    public function filter($res, $action, $args)
    {
        if ($action !== 'plugin_information') {
            return $res;
        }
        if (!isset($args->slug) || $args->slug !== self::SLUG) {
            return $res;
        }

        return $this->buildResponse();
    }

    /**
     * Build the plugin-information response object. Pure function — no
     * side effects, no WordPress API calls beyond reading the version
     * constant. Exposed as a public entry point for unit tests so the
     * response shape can be asserted without a `plugins_api` fixture.
     */
    public function buildResponse(): \stdClass
    {
        $res = new \stdClass();
        $res->name          = 'DataFlair Toplists';
        $res->slug          = self::SLUG;
        $res->version       = defined('DATAFLAIR_VERSION') ? DATAFLAIR_VERSION : 'unknown';
        $res->author        = '<a href="https://dataflair.ai">DataFlair</a>';
        $res->homepage      = 'https://dataflair.ai';
        $res->requires      = '6.3';
        $res->tested        = '6.9';
        $res->requires_php  = '8.1';

        $res->sections = [
            'description' => $this->descriptionHtml(),
            'changelog'   => $this->changelogHtml(),
        ];

        return $res;
    }

    private function descriptionHtml(): string
    {
        return '
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
        ';
    }

    private function changelogHtml(): string
    {
        return '
<h4>2.1.6</h4>
<ul>
  <li><strong>Phase 9.10 — sync pipeline helpers extraction.</strong> Seven god-class methods leave for dedicated single-responsibility classes under <code>DataFlair\\Toplists\\Sync\\</code> and <code>DataFlair\\Toplists\\Database\\</code>. The toplist endpoint walker, single-toplist fetcher, row writer, transient sweeper, paginated table delete, JSON CSV value collector, and the logo-sync wrapper all become small, isolated, lint-green units.</li>
  <li><strong>Seven new classes.</strong> <code>EndpointDiscovery</code> walks <code>/toplists?per_page=15&amp;page=N</code> via <code>meta.last_page</code> and returns show-endpoint URLs (closure-injected base URL today, replaced by <code>ApiBaseUrlDetector</code> in v2.1.7). <code>ToplistFetcher</code> owns the GET → JSON parse → <code>data.id</code> guard pipeline; delegates persist to <code>ToplistDataStore</code>. <code>ToplistDataStore</code> owns the <code>wp_dataflair_toplists</code> upsert: integrity validation, ten-column row map, format strings, <code>api_toplist_id</code> upsert key. <code>TransientCleaner</code> chunks the <code>_transient_dataflair_tracker_%</code> + <code>_transient_timeout_dataflair_tracker_%</code> sweep at 1,000 rows/statement (H10) and accepts an optional <code>WallClockBudget</code> for cooperative bail-out. <code>PaginatedDeleter</code> wipes a whitelisted DataFlair table at clamped 50–5,000 rows/chunk (H11; replaces <code>TRUNCATE</code> on managed-MySQL hosts). <code>JsonValueCollector</code> emits sorted, unique, trimmed values from CSV-shaped columns (<code>licenses</code>, <code>top_geos</code>, <code>product_types</code>) — column whitelist enforced. <code>LogoSync</code> is the sync-side thin wrapper around <code>LogoDownloaderInterface</code>.</li>
  <li><strong>Main file trim.</strong> <code>dataflair-toplists.php</code> drops by ~167 LOC (1,939 → 1,772). Seven god-class methods become one-line delegators wired through lazy <code>Container</code> getters. Closure-based DI (<code>\\Closure::fromCallable([$this, ...])</code>) keeps still-private god-class helpers (<code>get_api_base_url</code>, <code>build_detailed_api_error</code>) wired without breaking the strangler-fig contract — both extract to <code>Http\\</code> classes in v2.1.7.</li>
  <li><strong>Phase 0B H10 + H11 invariants preserved.</strong> The chunked transient sweep regex assertion (<code>LIMIT %d</code>) and the chunked DELETE shape are both pinned by the migrated <code>ClearTransientsChunkedTest</code> against the new class sources. <code>PaginatedDeleter</code> rejects non-whitelisted tables, returning zero without touching <code>$wpdb</code>. <code>TransientCleaner</code> bails before the first query when the budget is already exhausted.</li>
  <li><strong>Render-time read-only invariant preserved.</strong> None of the new classes are reachable from the casino-card render path. <code>RenderIsReadOnlyTest</code> continues to enforce this.</li>
  <li><strong>New tests.</strong> 25 new unit tests pin the contracts: <code>EndpointDiscoveryTest</code>, <code>ToplistFetcherTest</code>, <code>ToplistDataStoreTest</code>, <code>TransientCleanerTest</code>, <code>PaginatedDeleterTest</code>, <code>JsonValueCollectorTest</code>. Tests pin happy-path delegation, error short-circuits, JSON parse failure, missing-id rejection, insert vs update path selection, $wpdb error propagation, chunk clamping, whitelist enforcement, pagination via <code>meta.last_page</code>, and budget bail-out. Full suite: <strong>503 tests, 1,151 assertions, all green</strong>.</li>
  <li><strong>No public contract change.</strong> The dispatch into <code>store_toplist_data()</code> / <code>fetch_and_store_toplist()</code> / <code>discover_toplist_endpoints()</code> / <code>clear_tracker_transients()</code> / <code>delete_all_paginated()</code> / <code>collect_distinct_csv_values()</code> / <code>download_brand_logo()</code> on the god-class still hits the same call signatures and produces the same side effects (<code>error_log</code>, <code>add_settings_error</code>) byte-for-byte. <code>GodClassToplistPersister</code> still satisfies <code>ToplistPersisterInterface</code> via closure-bind onto the now-trivial god-class delegators. AJAX endpoints, REST endpoints, sync semantics: identical.</li>
</ul>

<h4>2.1.5</h4>
<ul>
  <li><strong>Phase 9.9 — review post manager + brand-meta extraction.</strong> Seven helper methods leave the god-class for dedicated single-responsibility classes under <code>DataFlair\\Toplists\\Frontend\\Content\\</code> and <code>DataFlair\\Toplists\\Frontend\\Render\\</code>. The on-demand review-CPT manager, brand-meta prefetcher (H7), batched review-post finder (H8), and the relative-time admin label all become testable, single-purpose units.</li>
  <li><strong>Six new classes.</strong> <code>ReviewPostFinder</code> owns the slug-tolerant direct-SQL lookup. <code>ReviewPostManager</code> owns the get-or-create flow, including the eight <code>_review_*</code> meta writes. <code>ReviewPostBatchFinder</code> wraps the H8 batched lookup. <code>BrandMetaPrefetcher</code> owns the H7 prefetch pipeline (one IN(…) batch via <code>BrandsRepository::findManyByApiBrandIds</code>, plus inline IN(…) for slug + name fallbacks). <code>BrandMetaLookup</code> owns the cascading per-card resolution from the prefetched map. <code>SyncLabelFormatter</code> owns the legacy/new option-name fallback for the "Last sync: …" admin labels.</li>
  <li><strong>Main file trim.</strong> <code>dataflair-toplists.php</code> drops by ~200 LOC in this phase. Six god-class methods become one-line delegators wired through lazy <code>Container</code> getters; <code>resolve_pros_cons_for_table_item()</code> deleted outright (the trait-based <code>ProsConsResolver</code> was already authoritative).</li>
  <li><strong>Render-time read-only invariant preserved.</strong> <code>ReviewPostManager</code> remains never-called from the casino-card render path — only from sync, WP-CLI reconcile, and admin paths. <code>RenderIsReadOnlyTest</code> continues to enforce this.</li>
  <li><strong>New tests.</strong> 26 new tests pin the contracts: <code>BrandMetaLookupTest</code>, <code>BrandMetaPrefetcherTest</code>, <code>SyncLabelFormatterTest</code>, <code>ReviewPostFinderTest</code>, <code>ReviewPostBatchFinderTest</code>, <code>ReviewPostManagerTest</code>. Tests pin cascade order, repo delegation, option-name fallback, slug-tolerant SQL, draft-vs-published preference, and meta-write fidelity. Full suite: <strong>478 tests, 1,111 assertions, all green</strong>.</li>
  <li><strong>No public contract change.</strong> The H7/H8 SQL batch shape, the brand-meta map structure, the auto-create draft flow, and every <code>_review_*</code> meta key remain identical to v2.1.4.</li>
</ul>

<h4>2.1.4</h4>
<ul>
  <li><strong>Phase 9.8 — frontend assets + Alpine.js extraction.</strong> The five frontend asset methods leave the god-class for dedicated single-responsibility classes under <code>DataFlair\\Toplists\\Frontend\\Assets\\</code>. Each registers its own WordPress hook via <code>register()</code>; <code>Plugin::registerHooks()</code> wires them.</li>
  <li><strong>Five new classes.</strong> <code>StylesEnqueuer</code> owns the <code>dataflair-toplists</code> stylesheet enqueue with filemtime cache busting. <code>AlpineJsEnqueuer</code> owns the conditional CDN load decision (shortcode/block detection across post + queried posts + widgets, plus the four-way Alpine-already-loaded check). <code>PromoCopyScript</code> owns the once-per-page copy-to-clipboard footer script (<code>dataset.promoBound</code> guard preserved). <code>AlpineDeferAttribute</code> filters <code>script_loader_tag</code> to add <code>defer</code> only when this plugin enqueued Alpine itself. <code>WidgetShortcodeDetector</code> sets the cross-class flag from <code>widget_text</code>.</li>
  <li><strong>Main file trim.</strong> <code>dataflair-toplists.php</code> drops from ~2,347 → ~2,165 LOC (−182). Four hook registrations and five method bodies removed.</li>
  <li><strong>No public contract change.</strong> Alpine 3.13.5 still loaded from the same jsDelivr URL, still filterable via <code>dataflair_alpinejs_url</code>, still gated by the same shortcode/block detection. Stylesheet handle, footer-script ordering (priority 5 / 20), and DOM markup byte-identical.</li>
  <li><strong>New tests.</strong> 18 new tests pin the contracts: <code>StylesEnqueuerTest</code>, <code>AlpineJsEnqueuerTest</code>, <code>PromoCopyScriptTest</code>, <code>AlpineDeferAttributeTest</code>, <code>WidgetShortcodeDetectorTest</code>. Full suite: <strong>452 tests, 1,062 assertions, all green</strong>.</li>
</ul>

<h4>2.1.3</h4>
<ul>
  <li><strong>Phase 9.7 — AJAX handler extraction.</strong> The eleven remaining <code>ajax_*</code> methods leave the god-class for dedicated single-responsibility handler classes under <code>DataFlair\\Toplists\\Admin\\Ajax\\</code>. Each handler implements <code>AjaxHandlerInterface::handle(array $request): array</code>; nonce + capability checks remain centralised in <code>AjaxRouter</code>. Action names, request shapes, and response payloads preserved byte-for-byte.</li>
  <li><strong>Directory rename.</strong> <code>src/Admin/Handlers/</code> → <code>src/Admin/Ajax/</code> per the v2.1.x plan delta map. Namespace declarations and use statements updated across <code>AdminBootstrap</code> and the existing handler suite.</li>
  <li><strong>Eleven handlers de-stubbed.</strong> <code>SaveSettingsHandler</code>, <code>FetchAllToplistsHandler</code>, <code>SyncToplistsBatchHandler</code>, <code>SyncBrandsBatchHandler</code>, <code>FetchAllBrandsHandler</code>, <code>GetAlternativeToplistsHandler</code>, <code>SaveAlternativeToplistHandler</code>, <code>DeleteAlternativeToplistHandler</code>, <code>GetAvailableGeosHandler</code>, <code>ApiPreviewHandler</code>, <code>SaveReviewUrlHandler</code> now host the real implementations — the god-class methods are gone.</li>
  <li><strong>Main file trim.</strong> <code>dataflair-toplists.php</code> drops from ~2,773 → ~2,347 LOC. <code>grep -c "function ajax_" dataflair-toplists.php</code> now returns 0.</li>
  <li><strong>No public contract change.</strong> Every <code>wp_ajax_dataflair_*</code> action remains identical to v2.1.2: same nonce names, same expected POST keys, same JSON response shape. Frontend admin JS unchanged.</li>
  <li><strong>Tests stay green.</strong> Full suite: <strong>434 tests, 1,028 assertions, all green</strong>.</li>
</ul>

<h4>2.1.2</h4>
<ul>
  <li><strong>Phase 9.6 — admin UI extraction.</strong> The two largest remaining inline page bodies leave the god-class. <code>settings_page()</code> (~705 LOC) is now owned by <code>DataFlair\\Toplists\\Admin\\Pages\\SettingsPage</code>; <code>brands_page()</code> (~1,237 LOC) is now owned by <code>DataFlair\\Toplists\\Admin\\Pages\\BrandsPage</code>. Markup, jQuery wiring, AJAX endpoints, pagination shape, and filter dropdowns preserved byte-for-byte — verified by manual smoke on the two pages and by reflection-based contract tests.</li>
  <li><strong>Closure injection over hard wiring.</strong> The pages take typed <code>\\Closure</code> dependencies in their constructors so they can call back into the still-private god-class helpers (<code>get_api_base_url</code>, <code>format_last_sync_label</code>, <code>collect_distinct_csv_values</code>) without inheriting the singleton — the strangler-fig pattern continues, no surface area added to the deprecated class.</li>
  <li><strong>Three new admin registrars extracted.</strong> <code>DataFlair\\Toplists\\Admin\\MenuRegistrar</code> owns <code>add_menu_page</code> + the two <code>add_submenu_page</code> calls. <code>DataFlair\\Toplists\\Admin\\SettingsRegistrar</code> owns the nine <code>register_setting</code> calls (the duplicate <code>dataflair_api_base_url</code> registration is preserved for byte parity). <code>DataFlair\\Toplists\\Admin\\Notices\\PermalinkNotice</code> owns the plain-permalinks admin warning. Each one self-registers via <code>register()</code>; <code>Plugin::registerHooks()</code> wires them through the lazy <code>Container</code>.</li>
  <li><strong>Main file trim.</strong> <code>dataflair-toplists.php</code> drops from 4,855 → ~2,773 LOC in this phase — the largest single phase by LOC of the v2.1.x continuation arc.</li>
  <li><strong>New tests.</strong> 22 new tests pin the contracts: <code>SettingsPageTest</code>, <code>BrandsPageTest</code>, <code>MenuRegistrarTest</code>, <code>SettingsRegistrarTest</code>, <code>PermalinkNoticeTest</code>. Reflection-based contract tests cover constructor signatures, interface conformance, render-method shape, and class finality without re-rendering the 1,200+ LOC HTML bodies inside PHPUnit. Full suite: <strong>434 tests, 1,028 assertions, all green</strong>.</li>
  <li><strong>No public contract change.</strong> Every admin URL, AJAX action, settings option name, capability check, nonce, page slug, and submenu position remains identical to v2.1.1.</li>
</ul>

<h4>2.1.1</h4>
<ul>
  <li><strong>Phase 9.5 — WPPB-style decoupling.</strong> Internal refactor: the 5,671-line <code>dataflair-toplists.php</code> god file now shrinks to a thin WordPress Plugin Boilerplate-shaped bootstrap. New classes own the concerns that were previously embedded inline — <code>DataFlair\\Toplists\\Admin\\PluginInfoFilter</code> (the 400-line <code>plugins_api</code> description + changelog block), <code>DataFlair\\Toplists\\Lifecycle\\Activator</code> / <code>Deactivator</code> (WPPB static activation seams), <code>DataFlair\\Toplists\\Database\\SchemaMigrator</code> (<code>check_database_upgrade</code> + <code>ensure_tables_exist</code> + <code>upgrade_database</code> + index/JSON migrations), <code>DataFlair\\Toplists\\I18n</code> (<code>load_plugin_textdomain</code> — previously missing entirely), and <code>DataFlair\\Toplists\\UpdateChecker\\GithubUpdateChecker</code> (plugin-update-checker bootstrap).</li>
  <li><strong>No public contract change.</strong> Every hook, shortcode, block, REST endpoint, AJAX action, option name, table column, and CLI command continues to work byte-for-byte. Strangler-fig preserved — <code>DataFlair_Toplists::activate()</code>, <code>deactivate()</code>, <code>check_database_upgrade()</code>, <code>supports_json_type()</code>, <code>migrate_to_json_type()</code>, and the private schema helpers all remain as thin delegators into the new classes. The strict-deprecation opt-out filter <code>dataflair_strict_deprecation</code> stays in place.</li>
  <li><strong>Main file trim.</strong> <code>dataflair-toplists.php</code> drops from ~5,671 lines to the single-digit-hundreds — header, constants, autoloader, activation hook registrations, and <code>Plugin::boot()</code> call. The remaining god-class methods (shortcode, helpers) continue to shrink during the v2.1.x line before the v3.0.0 class-symbol removal.</li>
  <li><strong>New — i18n wiring.</strong> The <code>load_plugin_textdomain</code> call was not present before. It now fires on <code>init</code> through <code>DataFlair\\Toplists\\I18n</code>, pointing at the <code>languages/</code> folder so translators have a real home to drop <code>.mo</code> / <code>.po</code> files.</li>
  <li><strong>New tests.</strong> <code>PluginInfoFilterTest</code>, <code>ActivatorTest</code>, <code>DeactivatorTest</code>, <code>SchemaMigratorTest</code>, <code>I18nTest</code>, <code>GithubUpdateCheckerTest</code> pin the WPPB contract — filter registration, response shape, activation idempotency, migration transient gating, textdomain path.</li>
</ul>

<h4>2.1.0</h4>
<ul>
  <li><strong>Phase 9 — strict deprecation default-on.</strong> The v2.0.x migration window closes. Any call to <code>DataFlair_Toplists::get_instance()</code> from outside <code>DATAFLAIR_PLUGIN_DIR</code> now emits <code>E_USER_DEPRECATED</code> once per unique caller file/line per request, pointing to <code>\\DataFlair\\Toplists\\Plugin::boot()</code>.</li>
  <li>Internal god-class call sites inside <code>dataflair-toplists.php</code> (hook-dispatch re-entry, extracted delegators) are filtered out of the notice emission so downstream <code>error_log</code> sees signal, not noise.</li>
  <li>Sites still on the legacy entry point can silence the notices temporarily with <code>add_filter(\'dataflair_strict_deprecation\', \'__return_false\');</code> — this remains a supported opt-out for the v2.1.x line. Planned removal of the class symbol entirely is tracked for <strong>v3.0.0</strong> once the remaining ~80 god-class methods (shortcode, schema upgrades, private DB helpers) have extracted in v2.1.x point releases.</li>
  <li><strong>New test:</strong> <code>ShimForwardingTest</code> — pins the default-on behaviour, the filter-off opt-out, the per-caller de-duplication, and the internal-caller filtering so the signal-to-noise contract can\'t silently regress. Full suite: <strong>409 tests, all green</strong>.</li>
  <li><strong>UPGRADING.md:</strong> refreshed with the v2.1.0 strict-mode guidance, the extraction trajectory for v2.1.x, and the v3.0.0 removal commitment.</li>
</ul>

<h4>2.0.0</h4>
<ul>
  <li><strong>Phase 8 — canonical bootstrap seam.</strong> <code>DataFlair\\Toplists\\Plugin::boot()</code> is now the canonical entry point for the plugin. The plugin file calls <code>Plugin::boot()</code> directly; the boot routine is idempotent and internally still calls <code>DataFlair_Toplists::get_instance()</code> to preserve every existing hook registration.</li>
  <li>Added: <code>DataFlair\\Toplists\\Container</code> — hand-written lazy service container (<code>register</code> / <code>set</code> / <code>get</code> / <code>has</code>, zero external dependencies). Services are resolved on first <code>get()</code> and memoised. Currently wires the <code>logger</code> service; downstream integrators can call <code>Plugin::boot()-&gt;container()-&gt;set(\'logger\', new MySentryLogger())</code> to override before any sync or render.</li>
  <li>Added: <code>Plugin::resetForTests()</code> — test-only seam for PHPUnit tear-down; no production code should call it.</li>
  <li><strong>Deprecation — not removal yet.</strong> <code>DataFlair_Toplists</code> is marked <code>@deprecated 2.0.0</code>; <code>DataFlair_Toplists::get_instance()</code> continues to work through the v2.0.x line. Strict-mode notices are opt-in: <code>add_filter(\'dataflair_strict_deprecation\', \'__return_true\')</code> to enable <code>E_USER_DEPRECATED</code> emission. Removal tracked for v2.1.0.</li>
  <li><strong>Migration guide:</strong> see <code>UPGRADING.md</code>. Strangler-fig preserved — the god-class still owns hook registrations and every existing call site keeps working byte-for-byte.</li>
  <li><strong>New tests:</strong> <code>ContainerTest</code> (lazy resolution, memoisation, factory re-registration invalidation), <code>PluginBootTest</code> (idempotent <code>boot()</code>, legacy singleton preserved, container accessor).</li>
  <li><strong>Why a major bump:</strong> new canonical public API (<code>Plugin::boot()</code>), formal deprecation window opens, downstream integrators should migrate within the v2.0.x line.</li>
</ul>

<h4>1.15.1</h4>
<ul>
  <li><strong>Phase 7 — block registrars extracted.</strong> <code>register_block_type</code> for the <code>dataflair-toplists/toplist</code> block is now owned by <code>DataFlair\Toplists\Block\BlockRegistrar</code>. The render callback moved to <code>DataFlair\Toplists\Block\ToplistBlock</code> (closure-based DI for the shortcode renderer + option reader keeps it <code>$wpdb</code>-free). Editor CSS enqueue moved to <code>DataFlair\Toplists\Block\EditorAssets</code>. Block metadata path resolution (<code>build/block.json</code> → <code>src/block.json</code> fallback), block attributes, shortcode delegation, and <code>prosCons</code> pass-through all preserved byte-for-byte.</li>
  <li>Added: <code>DataFlair\\Toplists\\Block\\BlockBootstrap</code> — single wiring seam. The god-class calls <code>$this-&gt;block_bootstrap()-&gt;boot()-&gt;register()</code> from <code>init_hooks()</code>; <code>register()</code> installs both the <code>init</code> and <code>enqueue_block_editor_assets</code> hooks.</li>
  <li>Added: PSR-4 autoload entry for <code>DataFlair\\Toplists\\Block\\</code> → <code>src/Block/</code>.</li>
  <li><strong>New tests:</strong> <code>BlockRegistrarTest</code> (4), <code>ToplistBlockTest</code> (6), <code>EditorAssetsTest</code> (1), backed by namespace-local WP function stubs in <code>BlockTestStubs.php</code>. Suite now at 392 tests / 915 assertions, all green.</li>
  <li><strong>Internal:</strong> <code>register_block()</code>, <code>render_block($attributes)</code>, and <code>enqueue_editor_assets()</code> on the god-class are now thin delegators. No behavioural change for block editor users.</li>
</ul>

<h4>1.15.0</h4>
<ul>
  <li><strong>Phase 6 — REST endpoints extracted.</strong> The three <code>/wp-json/dataflair/v1/*</code> routes are now owned by <code>DataFlair\Toplists\Rest\RestRouter</code>. Per-route logic lives in dedicated controllers: <code>ToplistsController</code>, <code>CasinosController</code>, and <code>HealthController</code>. The <code>dataflair/v1</code> namespace, URL shapes, response shapes, and permission contracts are preserved byte-for-byte.</li>
  <li><strong>H12 pagination contract now execution-path tested.</strong> The <code>/toplists/{id}/casinos</code> endpoint\'s <code>?page</code>, <code>?per_page</code> (default 20, max 100), <code>?full=1</code> escape hatch, and <code>X-WP-Total</code> / <code>X-WP-TotalPages</code> headers are now pinned by unit tests on the controller itself, not just a structural scan of the plugin file.</li>
  <li><strong>ToplistsRepository grew two lean methods.</strong> <code>listAllForOptions()</code> and <code>countAll()</code> replace ad-hoc <code>$wpdb->get_results</code> / <code>$wpdb->get_var</code> calls in REST handlers — every REST read path now routes through the repository.</li>
  <li><strong>New tests:</strong> <code>RestRouterTest</code>, <code>ToplistsControllerTest</code>, <code>CasinosControllerTest</code>, <code>HealthControllerTest</code>, plus two new <code>ToplistsRepository</code> tests for the new methods. Suite now at 381 tests / 887 assertions, all green. No behavioural changes for downstream consumers.</li>
  <li><strong>Internal:</strong> <code>dataflair-toplists.php</code> <code>register_rest_routes()</code> / <code>get_toplists_rest()</code> / <code>get_toplist_casinos_rest()</code> are now thin delegators. PSR-4 autoload extended with <code>DataFlair\Toplists\Rest\\</code>.</li>
</ul>

<h4>1.14.0</h4>
<ul>
  <li><strong>Phase 5 — admin pages + AJAX router extracted.</strong> Every admin-side AJAX action is now registered through a single <code>AjaxRouter</code> that owns nonce + capability checks centrally, dispatches to one handler class per action, and wraps the structured response in <code>wp_send_json_*</code>. No public contract change: every <code>wp_ajax_dataflair_*</code> action name, nonce action, payload shape, and admin-JS integration preserved byte-for-byte.</li>
  <li>Added: <code>DataFlair\\Toplists\\Admin\\AjaxRouter</code>. Per-action routing table (<code>handler</code>, <code>nonce</code>, <code>capability</code>), <code>check_ajax_referer()</code> + <code>current_user_can()</code> gate before any handler runs, <code>try/catch</code> around handler invocation so a thrown <code>Throwable</code> becomes a logged <code>ajax.router.handler_threw</code> warning + <code>wp_send_json_error</code> instead of a 500. <code>getRegisteredActions()</code> exposes the registration set for tests + introspection.</li>
  <li>Added: <code>DataFlair\\Toplists\\Admin\\AjaxHandlerInterface</code> — single-method contract (<code>handle(array $request): array</code>) returning <code>[\'success\' =&gt; bool, \'data\' =&gt; array|null]</code>. Eleven concrete handlers implement it.</li>
  <li>Added: <code>DataFlair\\Toplists\\Admin\\Assets\\AdminAssetsRegistrar</code> — the <code>admin_enqueue_scripts</code> filter registration now lives in a dedicated registrar class.</li>
  <li>Added: <code>DataFlair\\Toplists\\Admin\\Pages\\PageInterface</code> + <code>SettingsPage</code> + <code>BrandsPage</code> thin delegator seams.</li>
  <li>Added: <code>DataFlair\\Toplists\\Admin\\AdminBootstrap</code> — single wiring seam.</li>
  <li>Added: repository extensions used by the handlers — <code>AlternativesRepository::deleteById</code>, <code>ToplistsRepository::collectGeoNames</code>, <code>BrandsRepository::updateReviewUrlOverrideByApiBrandId</code>.</li>
  <li>Removed: <code>includes/render-casino-card.php</code> forwarding shim (deprecated in v1.13.0 with an explicit one-release removal notice).</li>
  <li>Added: 17 new tests. Full suite: <strong>358 tests, 823 assertions, all green</strong>.</li>
</ul>

<h4>1.13.0</h4>
<ul>
  <li><strong>Phase 4 — rendering + ViewModels extracted.</strong> The casino-card and toplist-table renderers are now owned by dedicated classes. The casino-card template has moved from <code>includes/render-casino-card.php</code> to <code>views/frontend/casino-card.php</code>; the old path stays as a forwarding shim for one release (deleted in Phase 5). No public contract change: <code>render_casino_card()</code> and <code>render_toplist_table()</code> on the god-class retain their signatures and continue to return byte-identical HTML.</li>
  <li>Added: <code>DataFlair\\Toplists\\Frontend\\Render\\CardRenderer</code> implementing <code>CardRendererInterface</code>. Preserves every Phase 0A / 0B / Phase 1 invariant — read-only, precomputed <code>local_logo_url</code> consumed verbatim, <code>cached_review_post_id</code> preferred over <code>WP_Query</code>, prefetched <code>brand_meta_map</code> wins over per-card repository calls.</li>
  <li>Added: <code>DataFlair\\Toplists\\Frontend\\Render\\TableRenderer</code> implementing <code>TableRendererInterface</code>.</li>
  <li>Added: 17 new tests. Full suite: <strong>341 tests, 793 assertions, all green</strong>.</li>
</ul>

<h4>1.12.1</h4>
<ul>
  <li><strong>Phase 3 — sync services extracted.</strong> The toplist and brand sync pipelines are now owned by dedicated service classes; the god-class AJAX handlers shrink to 5–20 line delegators. No public contract change.</li>
  <li>Added: <code>DataFlair\\Toplists\\Sync\\ToplistSyncService</code>, <code>BrandSyncService</code>, <code>AlternativesSyncService</code> plus <code>SyncRequest</code> / <code>SyncResult</code> value objects.</li>
  <li>Added: 38 new tests. Full suite: <strong>324 tests, 753 assertions, all green</strong>.</li>
</ul>

<h4>1.12.0</h4>
<ul>
  <li><strong>Phase 2 — repositories + HTTP client extracted.</strong> First real strangler-fig phase of the refactor arc. The god-class keeps every public method signature intact; the implementations now delegate through typed, testable collaborators.</li>
  <li>Added: new <code>src/</code> tree — <code>src/Http/{ApiClient, LogoDownloader}</code> and <code>src/Database/{ToplistsRepository, BrandsRepository, AlternativesRepository}</code>.</li>
  <li>Added: 39 new tests. Full suite: <strong>286 tests, 645 assertions, all green</strong>.</li>
</ul>

<h4>1.11.2</h4>
<ul>
  <li><strong>Phase 1 — observability foundation.</strong> Pluggable <code>DataFlair\Toplists\Logging\LoggerInterface</code> (PSR-3-shaped, hand-written). Six stable telemetry hooks. WP-CLI <code>wp dataflair logs</code> command.</li>
  <li>Total suite: 247 tests, 566 assertions, all green.</li>
</ul>

<h4>1.11.1</h4>
<ul>
  <li><strong>Phase 0.5 — perf rig + CI gate.</strong> WP-CLI <code>wp dataflair perf:seed</code> and <code>perf:run</code> commands. <code>composer perf</code> script. GitHub Actions <code>perf-gate</code> workflow.</li>
</ul>

<h4>1.11.0</h4>
<ul>
  <li><strong>Phase 0B safety rails.</strong> Twelve latent-OOM, timeout-cap, and memory-hygiene fixes. Removed WP-cron auto-sync (H1), added <code>WallClockBudget</code> (H13), HTTP size caps (H2/H3), memory hygiene (H4), batched render queries (H7/H8), paginated admin pages (H5/H6), schema transient (H9), chunked transient sweep (H10), paginated DELETE (H11), REST pagination (H12).</li>
</ul>

<h4>1.10.8</h4>
<ul>
  <li>Fixed: critical — casino-card rendering is now fully read-only. The render chain no longer sideloads brand logos at page-view time and no longer auto-creates review CPT rows. These two render-time writes were the root cause of memory-exhaustion fatals on sites with a 1 GB PHP memory limit.</li>
  <li>Added: pre-computed <code>local_logo_url</code> and <code>cached_review_post_id</code> columns on <code>wp_dataflair_brands</code>.</li>
  <li>Added: WP-CLI command <code>wp dataflair reconcile-reviews [--batch=500] [--dry-run]</code>.</li>
  <li>Changed: minimum PHP bumped to 8.1 and minimum WordPress to 6.3.</li>
</ul>

<h4>1.10.7</h4>
<ul>
  <li>Fixed: "Fetch All Toplists from API" no longer returns 500 on heavy pages. Bulk list fetch dropped from per_page=20 to per_page=10.</li>
  <li>Improved: per-ID fallback splitter rewritten for speed.</li>
  <li>Added: retry with exponential backoff (1s, 2s) on transient WP_Error and 5xx responses.</li>
</ul>

<h4>1.9.0</h4>
<ul>
  <li>Added: automatic plugin updates via GitHub releases (plugin-update-checker v5.6)</li>
  <li>Added: promo code display with copy-to-clipboard button on toplist casino cards</li>
  <li>Added: per-brand review URL override in DataFlair, Brands admin</li>
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

<h4>1.5.0</h4>
<ul>
  <li>Added: snapshot support, data integrity checker, API preview tab</li>
</ul>

<h4>1.4.0</h4>
<ul>
  <li>Added: /api/v1/brands endpoint support with 8 new brand fields</li>
  <li>Fixed: self-healing cron</li>
</ul>
        ';
    }
}
