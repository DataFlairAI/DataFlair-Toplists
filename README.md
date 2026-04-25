# DataFlair Toplists

A WordPress plugin that connects your site to the [DataFlair](https://dataflair.ai) affiliate management platform and renders fully styled casino and sportsbook toplist blocks.

---

## What is DataFlair?

DataFlair is a white-label iGaming data service built for casino and sportsbook affiliate publishers. It lets you manage your entire brand catalogue, bonus offers, promo codes, affiliate tracking links, geo rules, and rating data in one central dashboard, then distribute that data to all your WordPress affiliate sites simultaneously.

This plugin is the WordPress-side receiver. It syncs your toplists and brands from the DataFlair API, stores them locally in custom database tables, and renders fully styled casino and sportsbook comparison cards on any page or post, with no live API calls on the front end.

---

## How It Works

1. Your DataFlair account holds your brand catalogue and toplist configurations (e.g. "Top 10 Casinos in India", "Best Sportsbooks in Italy").
2. The plugin syncs this data from the DataFlair API on a configurable schedule and caches it locally.
3. You place the DataFlair Gutenberg block or `[dataflair_toplist id="123"]` shortcode on any page.
4. The plugin renders the full toplist from cached data, fast and with no live API dependency on page load.

---

## Features

### Toplist Sync
- Syncs from DataFlair API v1 and v2
- Full sync and incremental sync modes with conflict detection and preview before overwriting
- Supports multiple geo-editions, rotation schedules, and locked item positions
- Stores complete offer, tracker, and geo data as JSON for flexible querying
- Paginated API fetch handles large brand catalogues automatically

### Brand Management
- Syncs your full brand catalogue into a local database table
- Stores name, slug, logo, star rating, licenses, payment methods, classification types, and restricted countries
- Admin screen at **DataFlair → Brands** shows all synced brands with their affiliate data
- **Review URL Override:** per-brand custom review URL that wins over all automatic URL generation across the entire site. Field locks after saving and unlocks on Edit.

### Casino Card Rendering
- Renders fully styled casino cards showing: brand logo, name, star rating, bonus offer text, promo code copy button, feature list, affiliate CTA, and Read Review link (the Read Review control appears only when a published review exists or a manual review URL override is set)
- Promo codes render as a pill-shaped copy-to-clipboard button, matching the design of standalone review pages
- Review URL resolution priority: manual override, published review post permalink, auto-generated `/reviews/{slug}/`, affiliate CTA link; published reviews are also matched by `_review_brand_id` when the live review slug differs from the API slug (for example `…-india` vs base slug)
- Supports multiple product types (casino, sportsbook, poker) with type-aware labels
- **Render-time read-only guarantee (1.10.8+):** the render chain never issues HTTP, never sideloads media, and never writes to the `review` CPT. Logo URLs and review-post IDs are pre-computed at sync time on `wp_dataflair_brands` (`local_logo_url`, `cached_review_post_id`) and read verbatim by the template. Enforced by `RenderIsReadOnlyTest`.

### WP-CLI
- `wp dataflair reconcile-reviews [--batch=500] [--dry-run]` — backfills `cached_review_post_id` for existing brand rows. Run once after upgrading to 1.10.8.

### Gutenberg Block & Shortcode
- Native WordPress block with inspector controls for toplist selection, item limit, and display options
- Includes a testing-focused accordion table layout option to inspect synced data without wide horizontal-scroll tables
- Server-side rendered, always reflects live synced data
- Pros and cons overrides in the block editor use stable brand and item IDs when available, so custom copy survives reordered toplists and refreshed sync payloads
- Shortcode: `[dataflair_toplist id="123" limit="10"]` works anywhere

### Automatic Updates
- Self-updating via GitHub releases, no WordPress.org required
- WordPress shows native update notifications when a new release is published on GitHub
- Powered by [plugin-update-checker](https://github.com/YahnisElsts/plugin-update-checker) v5.6, release-based (not branch-based)

### Alternative Toplists
- Geo-specific fallback toplists for better regional conversion
- Set per-country or per-market alternatives in the admin panel

### Admin Interface
- **DataFlair → Toplists:** view all synced toplists, trigger manual sync, preview before committing
- **DataFlair → Brands:** full brand table with review URL override editing
- **DataFlair → Settings:** configure API endpoint, API key, sync frequency, and feature flags
- REST API endpoints for the block editor (`/wp-json/dataflair/v1/toplists`, `/wp-json/dataflair/v1/casinos`)

### Security
- Nonce verification on all AJAX actions
- `manage_options` capability check on all admin routes
- SQL injection prevention via `$wpdb->prepare()`
- XSS protection via `esc_html()`, `esc_attr()`, `esc_url()`
- Direct file execution blocked via `ABSPATH` check

---

## Installation

1. Upload the `dataflair-toplists` folder to `/wp-content/plugins/`
2. Activate the plugin in **Plugins → Installed Plugins**
3. Go to **DataFlair → Settings** and enter your DataFlair API key and endpoint
4. Click **Sync Now** to pull your first batch of toplists and brands

Dependencies (`vendor/`) are committed to the repository, so no `composer install` is needed on the server.

---

## Usage

### Gutenberg Block

Add the **DataFlair Toplist** block to any page or post. In the block inspector, select a toplist and set an item limit.

### Shortcode

```
[dataflair_toplist id="123" limit="10"]
```

| Attribute | Required | Description |
|-----------|----------|-------------|
| `id` | Yes | The DataFlair API toplist ID |
| `limit` | No | Maximum number of brands to display |
| `title` | No | Override the toplist display title |

---

## Requirements

- WordPress 6.3+
- PHP 8.1+
- MySQL 5.7+ or MariaDB 10.3+ (for JSON column support)

---

## Database Tables

| Table | Purpose |
|-------|---------|
| `wp_dataflair_toplists` | Toplist JSON blobs synced from API |
| `wp_dataflair_brands` | Brand catalogue synced from API |
| `wp_dataflair_alternative_toplists` | Geo to alternative toplist mappings |

---

## Development

### Requirements

- PHP 8.1+
- Node.js 18+ and npm (for Gutenberg block compilation)

### Build Assets

```bash
npm install
npm run build    # production build
npm run start    # watch mode for development
```

### Tests

```bash
./vendor/bin/phpunit
```

PHPUnit suite covering sync logic, brand management, REST API, auto-update wiring, and casino card rendering.

---

## File Structure

```
dataflair-toplists/
├── dataflair-toplists.php          Main plugin file
├── composer.json
├── composer.lock
├── vendor/                         Composer dependencies (committed)
├── build/                          Compiled Gutenberg block assets
├── includes/
│   ├── render-casino-card.php      Casino card HTML template (read-only)
│   ├── ProductTypeLabels.php       Label map for product types
│   ├── DataIntegrityChecker.php    Validates API response structure
│   └── Cli/
│       └── ReconcileReviewsCommand.php  wp dataflair reconcile-reviews
├── src/                            Gutenberg block source (JS/JSX)
├── tests/
│   └── phpunit/                    PHPUnit test suite
├── docs/
│   └── plans/                      Parked feature plans
└── README.md
```

---

## Upgrading

### To 2.1.8

2.1.8 is the Phase 9.12 **shortcode + campaign redirect extraction** release. The two remaining public entry points leave `dataflair-toplists.php` for dedicated single-responsibility classes under `src/Frontend/Shortcode/` and `src/Frontend/Redirect/`. No operator action required, no DB migration, no config change.

What moved out of `dataflair-toplists.php`:

- `Frontend\Shortcode\ToplistShortcode` — replaces inline `toplist_shortcode()`. Validates attrs, performs the slug-or-id `ToplistsRepository` lookup, runs the H7 batched `BrandMetaPrefetcher` once before iterating items, then dispatches each item to `CardRenderer::render(CasinoCardVM)` (cards layout) or to `TableRenderer::render(ToplistTableVM)` (table layouts).
- `Frontend\Shortcode\ShortcodeRegistrar` — owns the `add_shortcode('dataflair_toplist', …)` registration. Wired through a generic `callable` so heavy-weight orchestrator construction is deferred to first shortcode invocation — every other plugin has had a chance to register its `dataflair_card_renderer` / `dataflair_table_renderer` filters before the renderer resolves.
- `Frontend\Redirect\CampaignRedirectHandler` — replaces inline `handle_campaign_redirect()`. Hooks `template_redirect`, validates the campaign param, increments the per-campaign hit-counter transient, then `wp_safe_redirect()`s to the brand affiliate URL.

What stays the same:

- The shortcode HTML output is byte-identical on tier-S, tier-Sigma, and tier-L fixtures.
- `[dataflair_toplist]` shortcode name, attribute schema, and DOM markup unchanged.
- Campaign redirect URL pattern (`/go/?campaign=…`) and hit-counter transient unchanged.
- Phase 0B invariants (render-time read-only, H7 prefetch ordering, no cron) untouched.
- No public option, table, AJAX action, or REST route changed.

Tests: 21 new unit tests pin attribute validation, repository lookup, prefetch ordering, dispatch, the registrar's `function_exists` guard, the campaign happy path, the invalid-campaign 404, the already-redirected guard, and the per-campaign hit-counter transient. Suite size 553 → 574 tests, 1,270 assertions, all green. `dataflair-toplists.php` LOC drops by ~177.

The two extracted god-class methods remain as one-line delegators through v2.1.x. They are deleted in **v3.0.0 (Phase 9.13 — god-class symbol removal)**.

### To 2.1.7

2.1.7 is the Phase 9.11 **HTTP / URL / Support utility extraction** release. Seven stateless helpers leave `dataflair-toplists.php` for dedicated single-responsibility classes under `src/Http/` and `src/Support/`. No operator action required, no DB migration, no config change.

What moved out of `dataflair-toplists.php`:

- `Support\UrlValidator` — replaces inline `is_local_url()`. Classifies hosts as local-dev (`.test`, `.local`, `.localhost`, `.invalid`, `.example`, plus `localhost` / `127.0.0.1`).
- `Support\UrlTransformer` — replaces inline `maybe_force_https()`. Rewrites `http://` → `https://` for non-local URLs (the production redirect strips `Authorization` headers; up-front HTTPS avoids the rewrite).
- `Support\EnvironmentDetector` — replaces inline `is_running_in_docker()`. Three-way detection: `/.dockerenv`, `/proc/1/cgroup` keywords, `host.docker.internal` DNS resolution. Currently unused after Phase 0A but kept as a discrete unit.
- `Http\ApiBaseUrlDetector` — replaces inline `get_api_base_url()`. Three-tier resolution: stored option → endpoints option (with cache-back) → `https://sigma.dataflair.ai/api/v1` fallback. Strips trailing path beyond `/api/vN`.
- `Http\BrandsApiUrlBuilder` — replaces inline `get_brands_api_url()`. Respects `dataflair_brands_api_version` (v1 default, v2 opt-in); appends `?page=N`.
- `Http\ApiErrorFormatter` — replaces inline `build_detailed_api_error()`. Owns the long status-code switch (401 Basic vs Bearer vs HTML, 403/404/419/429/500/502-504/default), producing actionable admin-UI guidance.
- `Support\RelativeTimeFormatter` — replaces inline `time_ago()` and `time_until()`. Emits `"3 minutes ago"` / `"in 3 minutes"` labels.

What stays the same:

- The eight extracted god-class methods remain as one-line delegators — call sites and behaviour unchanged.
- Phase 0B invariants (HTTPS-force, render-time read-only, no cron) untouched.
- No public option, table, shortcode, AJAX action, or REST route changed.

Tests: 50 new unit tests pin behaviour at the boundary. Suite size 503 → 553 tests, 1,213 assertions, all green. `dataflair-toplists.php` LOC drops 1,772 → 1,700.

The eight god-class delegators stay in place through v2.1.x. They are deleted in **v3.0.0 (Phase 9.13 — god-class symbol removal)**.

### To 2.1.6

2.1.6 is the Phase 9.10 **sync pipeline helpers extraction** release. Seven helper methods leave `dataflair-toplists.php` for dedicated single-responsibility classes under `src/Sync/` and `src/Database/`. No operator action required, no DB migration, no config change.

What moved out of `dataflair-toplists.php`:

- `EndpointDiscovery` (`src/Sync/`) — replaces inline `discover_toplist_endpoints()`. Walks `/toplists?per_page=15&page=N` until `meta.last_page` is reached, returns the show-endpoint URLs. Closure-injected base URL today; replaced by `Http\ApiBaseUrlDetector` in v2.1.7.
- `ToplistFetcher` (`src/Sync/`) — replaces inline `fetch_and_store_toplist()`. Owns the GET → JSON parse → `data.id` guard pipeline; delegates the actual upsert to `ToplistDataStore`.
- `ToplistDataStore` (`src/Database/`) — replaces inline `store_toplist_data()`. Owns the `wp_dataflair_toplists` upsert: integrity validation via `DataFlair_DataIntegrityChecker`, the canonical ten-column row map, format strings, and the `api_toplist_id` upsert key.
- `TransientCleaner` (`src/Sync/`) — replaces inline `clear_tracker_transients()`. Phase 0B H10. Chunked DELETE at 1,000 rows/statement against both `_transient_dataflair_tracker_%` + `_transient_timeout_dataflair_tracker_%`. Accepts an optional `WallClockBudget` for cooperative bail-out.
- `PaginatedDeleter` (`src/Database/`) — replaces inline `delete_all_paginated()`. Phase 0B H11. Whitelist-enforced chunked DELETE at clamped 50–5,000 rows/chunk; replaces `TRUNCATE TABLE` (which is not safely replicable on managed-MySQL hosts).
- `JsonValueCollector` (`src/Database/`) — replaces inline `collect_distinct_csv_values()`. Column-whitelisted DISTINCT collector for CSV-shaped columns (`licenses`, `top_geos`, `product_types`); trims, dedups, sorts.
- `LogoSync` (`src/Sync/`) — replaces inline `download_brand_logo()` thin wrapper. Sync-side facade onto the existing `Http\LogoDownloader`.

Render-time read-only invariant preserved: none of the new classes are reachable from the casino-card render path. `RenderIsReadOnlyTest` continues to enforce this.

`dataflair-toplists.php` drops by ~167 LOC in this phase (1,939 → 1,772). The seven god-class methods become one-line delegators wired through lazy `Container` getters. Closure-based DI (`\Closure::fromCallable([$this, ...])`) keeps still-private god-class helpers (`get_api_base_url`, `build_detailed_api_error`) wired through without breaking the strangler-fig contract — both extract to `Http\` classes in v2.1.7. Test suite: **503 tests, 1,151 assertions, all green** (+25 new unit tests).

### To 2.1.5

2.1.5 is the Phase 9.9 **review post manager + brand-meta extraction** release. Seven helper methods leave `dataflair-toplists.php` for dedicated single-responsibility classes under `src/Frontend/Content/` and `src/Frontend/Render/`. No operator action required, no DB migration, no config change.

What moved out of `dataflair-toplists.php`:

- `ReviewPostFinder` (`src/Frontend/Content/`) — replaces inline `find_review_post_by_brand_meta()`. Owns the slug-tolerant direct-SQL join lookup that finds an existing review CPT when the post slug differs from the API brand slug.
- `ReviewPostManager` (`src/Frontend/Content/`) — replaces inline `get_or_create_review_post()`. Owns the get-or-create flow: slug match → brand-meta finder → auto-create draft, plus the eight `_review_*` meta writes.
- `ReviewPostBatchFinder` (`src/Frontend/Content/`) — replaces inline `find_review_posts_by_brand_metas()`. Wraps the H8 batched lookup, delegating to `BrandsRepository::findReviewPostsByApiBrandIds`.
- `BrandMetaPrefetcher` (`src/Frontend/Render/`) — replaces inline `prefetch_brand_metas_for_items()`. Owns the H7 prefetch pipeline: a single IN(…) batch via `BrandsRepository::findManyByApiBrandIds` plus inline IN(…) for slug + name fallbacks.
- `BrandMetaLookup` (`src/Frontend/Render/`) — replaces inline `lookup_brand_meta_from_map()`. Pure helper. Owns the cascading per-card resolution from the prefetched map (`api_brand_id` → `id` → `slug` → `name`).
- `SyncLabelFormatter` (`src/Frontend/Render/`) — replaces inline `format_last_sync_label()`. Owns the legacy/new option-name fallback and the relative-time math behind the "Last sync: …" admin labels.

Render-time read-only invariant preserved: `ReviewPostManager` is **not** called from the casino-card render path — only from sync, WP-CLI reconcile, and admin paths. `RenderIsReadOnlyTest` continues to enforce this.

`dataflair-toplists.php` drops by ~200 LOC in this phase. The seven god-class methods become one-line delegators wired through lazy `Container` getters. `resolve_pros_cons_for_table_item()` deleted outright (the trait-based `ProsConsResolver` was already authoritative). Test suite: **478 tests, 1,111 assertions, all green** (+26 new tests).

### To 2.1.4

2.1.4 is the Phase 9.8 **frontend assets + Alpine.js extraction** release. The five frontend asset methods leave `dataflair-toplists.php` for dedicated single-responsibility classes under `src/Frontend/Assets/`. No operator action required, no DB migration, no config change.

What moved out of `dataflair-toplists.php` into `src/Frontend/Assets/`:

- `StylesEnqueuer` — replaces inline `enqueue_frontend_assets()`. Owns the `dataflair-toplists` stylesheet handle with filemtime cache busting.
- `AlpineJsEnqueuer` — replaces inline `maybe_enqueue_alpine()`. Owns the conditional CDN-load decision: shortcode + block detection across the current post, queried posts, and widgets; four-way already-enqueued check before falling through to the CDN.
- `PromoCopyScript` — replaces inline `enqueue_promo_copy_script()`. Owns the once-per-page copy-to-clipboard footer script. The `dataset.promoBound` guard preserved verbatim.
- `AlpineDeferAttribute` — replaces inline `add_alpine_defer_attribute()`. Filters `script_loader_tag` to add `defer` only when the plugin enqueued Alpine itself (theme/other-plugin Alpine instances are not modified).
- `WidgetShortcodeDetector` — replaces inline `check_widget_for_shortcode()`. Sets the cross-class shortcode-used flag from the `widget_text` filter.

Hook ordering preserved byte-for-byte: stylesheet at `wp_enqueue_scripts`, Alpine at `wp_footer` priority 5, promo-copy at `wp_footer` priority 20, widget detection at `widget_text` priority 10. Alpine URL still filterable via `dataflair_alpinejs_url`.

`dataflair-toplists.php` shrinks from ~2,347 → ~2,165 LOC (−182). Test suite: 452 tests, 1,062 assertions, all green (+18 new tests).

### To 2.1.3

2.1.3 is the Phase 9.7 **AJAX handler extraction** release. The eleven remaining `ajax_*` methods leave `dataflair-toplists.php` for dedicated single-responsibility handler classes under `src/Admin/Ajax/`. No operator action required, no DB migration, no config change — every `wp_ajax_dataflair_*` action remains identical to v2.1.2 (same nonce names, same expected POST keys, same JSON response shape).

What moved out of `dataflair-toplists.php` into `src/Admin/Ajax/`:

- `SaveSettingsHandler`, `FetchAllToplistsHandler`, `SyncToplistsBatchHandler`, `SyncBrandsBatchHandler`, `FetchAllBrandsHandler` — sync- and settings-related AJAX surfaces.
- `GetAlternativeToplistsHandler`, `SaveAlternativeToplistHandler`, `DeleteAlternativeToplistHandler`, `GetAvailableGeosHandler` — alternative-toplist CRUD + geo discovery surface.
- `ApiPreviewHandler` — admin API preview tab.
- `SaveReviewUrlHandler` — per-brand review URL override save.

Each handler implements `AjaxHandlerInterface::handle(array $request): array`. Nonce + capability checks remain centralised in `AjaxRouter`. The directory `src/Admin/Handlers/` was renamed to `src/Admin/Ajax/` per the v2.1.x plan delta map; namespace declarations and use statements were updated accordingly.

`dataflair-toplists.php` shrinks from ~2,773 → ~2,347 LOC. `grep -c "function ajax_" dataflair-toplists.php` now returns 0. Full test suite: 434 tests, 1,028 assertions, all green.

### To 2.1.2

2.1.2 is the Phase 9.6 **admin UI extraction** release. The two largest remaining inline page bodies — `settings_page()` (~705 LOC) and `brands_page()` (~1,237 LOC) — leave the god-class for dedicated owners under `src/Admin/Pages/`. Three more admin-side registrars come along for the ride. No operator action required, no DB migration, no config change — every admin URL, AJAX action, settings option name, capability check, nonce, page slug, and submenu position is preserved byte-for-byte.

What moved out of `dataflair-toplists.php` into `src/`:

- `\DataFlair\Toplists\Admin\Pages\SettingsPage` — replaces inline `settings_page()`. Constructor takes typed `\Closure` dependencies (`apiBaseUrlResolver`, `lastSyncLabelFormatter`) so the still-private god-class helpers are reachable without exposing a wider class surface.
- `\DataFlair\Toplists\Admin\Pages\BrandsPage` — replaces inline `brands_page()`. Same closure-injection pattern (`distinctCsvValuesCollector`, `lastSyncLabelFormatter`). Markup, jQuery wiring, AJAX endpoints, pagination shape, and filter-dropdown shape preserved byte-for-byte.
- `\DataFlair\Toplists\Admin\MenuRegistrar` — owns `add_menu_page` + the two `add_submenu_page` calls. Top-level menu icon (`dashicons-list-view`) and position (30) unchanged.
- `\DataFlair\Toplists\Admin\SettingsRegistrar` — owns the nine `register_setting` calls under the `dataflair_settings` group. The duplicate `dataflair_api_base_url` registration in v2.1.1 is preserved here for byte parity.
- `\DataFlair\Toplists\Admin\Notices\PermalinkNotice` — owns the plain-permalinks admin warning that prompts the operator to flip permalink mode in WP settings.

`Plugin::registerHooks()` now wires all five new admin classes through the lazy `Container`. Strangler-fig contract on `DataFlair_Toplists::get_instance()` is untouched. Suite at **434 tests, 1,028 assertions, all green**.

### To 2.1.1

2.1.1 is the Phase 9.5 **WPPB-style bootstrap decoupling** release. The 5,600-line god-class gave up five single-responsibility chunks to their own dedicated classes under `src/`, following the WordPress Plugin Boilerplate layout. No operator action required, no DB migration, no config change — every hook, shortcode, block, REST route, AJAX action, and option is preserved byte-for-byte.

What moved out of `dataflair-toplists.php` into `src/`:

- `\DataFlair\Toplists\Admin\PluginInfoFilter` — the `plugins_api` "View details" popup (description, changelog, banners).
- `\DataFlair\Toplists\UpdateChecker\GithubUpdateChecker` — the YahnisElsts PUC v5 bootstrap + `enableReleaseAssets()` wiring.
- `\DataFlair\Toplists\I18n` — `load_plugin_textdomain` on `init` (previously the textdomain was declared but never actually loaded).
- `\DataFlair\Toplists\Lifecycle\{Activator, Deactivator}` — `register_activation_hook` / `register_deactivation_hook` targets. The god-class `activate()` / `deactivate()` methods are now thin delegators.
- `\DataFlair\Toplists\Database\SchemaMigrator` — `check_database_upgrade()`, `ensure_tables_exist()`, `ensure_brands_external_id_index()`, `ensure_alternative_toplists_table()`, `supports_json_type()`, `migrate_to_json_type()`. All god-class method signatures remain as delegators for backwards compat with any downstream caller.

`Plugin::boot()` now owns the one-per-request wiring of all four registrars (`PluginInfoFilter`, `I18n`, `GithubUpdateChecker`, `SchemaMigrator`). The strangler-fig contract on `DataFlair_Toplists::get_instance()` is untouched — every deprecated path still resolves.

### To 2.1.0

2.1.0 closes the v2.0.x migration window on `DataFlair_Toplists::get_instance()`. Strict-deprecation warnings are now **default-on**: any call to `get_instance()` from outside `DATAFLAIR_PLUGIN_DIR` emits `E_USER_DEPRECATED` once per unique caller file/line per request, pointing to `\DataFlair\Toplists\Plugin::boot()`. Internal callers (the god-class's own hook-dispatch re-entry, extracted delegators under `src/`) are filtered out so `error_log` sees signal, not noise.

Sites that are mid-migration can silence the notices without breaking anything:

```php
add_filter('dataflair_strict_deprecation', '__return_false');
```

This remains a supported opt-out for the v2.1.x line. The class symbol itself stays in place — planned removal is tracked for **v3.0.0**, after the remaining ~80 god-class methods (shortcode, schema upgrades, private DB helpers) extract incrementally during v2.1.x point releases.

### To 2.0.0

2.0.0 is the Phase 8 **canonical bootstrap seam** release. The plugin gains a new entry point, `\DataFlair\Toplists\Plugin::boot()`, backed by a hand-written lazy service container (`\DataFlair\Toplists\Container`). The legacy `DataFlair_Toplists::get_instance()` entry point is **deprecated but fully functional** through the entire v2.0.x line — the god-class continues to own WordPress hook registrations as a strangler-fig shim. **Scheduled removal: v2.1.0.**

- No operator action required for day-to-day use. Every hook, option, table, shortcode, block, REST route, and AJAX action is preserved byte-for-byte.
- **Downstream integrators** calling `DataFlair_Toplists::get_instance()` from a theme, child plugin, or mu-plugin have the entire v2.0.x line to migrate. See [UPGRADING.md](UPGRADING.md) for the full migration guide.

Recommended pattern:

```php
// Legacy (v1.x → v2.0.x, works but deprecated)
$legacy = DataFlair_Toplists::get_instance();

// Canonical (v2.0.0+)
$plugin    = \DataFlair\Toplists\Plugin::boot();
$container = $plugin->container();
$logger    = $container->get('logger');
```

Strict-mode deprecation notices (`E_USER_DEPRECATED`) are **opt-in** via `add_filter('dataflair_strict_deprecation', '__return_true');` — off by default so sites that haven't migrated yet aren't flooded with notices on every internal hook dispatch.

### To 1.15.1

1.15.1 is the Phase 7 **block registrars** release. No operator action required, no DB migration, no config change. The public Gutenberg block contract is preserved byte-for-byte:

- Block name `dataflair-toplists/toplist` — unchanged.
- Block attributes — unchanged (sourced from `build/block.json`, falls back to `src/block.json`).
- Block render output — byte-identical to v1.15.0 for every attribute combination (the render callback still delegates to `[dataflair_toplist]` shortcode under the hood).
- Editor CSS handle `dataflair-toplist-editor` — unchanged.

Internal refactor only: `register_block_type` is now owned by `DataFlair\Toplists\Block\BlockRegistrar`, the render callback lives on `DataFlair\Toplists\Block\ToplistBlock`, and the editor-assets enqueue lives on `DataFlair\Toplists\Block\EditorAssets`. The god-class's `register_block()`, `render_block($attributes)`, and `enqueue_editor_assets()` methods are thin delegators — any downstream code still holding references to those callables continues to work unchanged.

### To 1.15.0

1.15.0 is the Phase 6 **REST endpoint extraction** release. No operator action required, no DB migration, no config change. The public REST surface is preserved byte-for-byte:

- `GET /wp-json/dataflair/v1/toplists` — unchanged response shape (`[{value, label}, …]`).
- `GET /wp-json/dataflair/v1/toplists/{id}/casinos` — H12 pagination unchanged (`?page`, `?per_page` default 20 max 100, `?full=1` for the legacy verbose shape), `X-WP-Total` + `X-WP-TotalPages` headers emitted on every response.
- `GET /wp-json/dataflair/v1/health` — unchanged `{status, toplists, plugin_ver, db_error}` envelope, still `manage_options`-gated.

Internal refactor only: the three routes are now registered by `DataFlair\Toplists\Rest\RestRouter` and served by per-endpoint controllers under `DataFlair\Toplists\Rest\Controllers\*`. The god-class's `register_rest_routes()`, `get_toplists_rest()`, and `get_toplist_casinos_rest()` methods are now thin delegators — any downstream code still holding references to those callables continues to work unchanged.

### To 1.14.0

1.14.0 is the Phase 5 **admin pages + AJAX router** release. No operator action required, no DB migration, no option rename — every `wp_ajax_dataflair_*` action name, nonce action, payload shape, and admin-JS integration is preserved byte-for-byte. The god-class's 11 `ajax_*` methods remain in place (they will stay until Phase 8 — shim birth) so any downstream code that invoked them directly continues to work.

**Breaking for direct-include integrators only:** the casino-card template forwarding shim at `includes/render-casino-card.php` — deprecated in v1.13.0 with an explicit one-release removal notice — is **deleted in 1.14.0**. Downstream themes or plugins that were still including the old path must update to the new location at `views/frontend/casino-card.php`. Shortcode users, block users, and anyone consuming the rendered HTML are unaffected.

Downstream integrators who want to supply a custom AJAX handler can replace the handler's dependencies via the existing repository/service filters (`dataflair_brands_repository`, `dataflair_toplists_repository`, `dataflair_alternatives_repository`, `dataflair_toplist_sync_service`, `dataflair_brand_sync_service`, `dataflair_api_client`). Swapping the `AdminBootstrap` itself is intentionally not exposed — the bootstrap is a thin wiring seam, not a public contract.

### To 1.13.0

1.13.0 is the Phase 4 **rendering + ViewModels** release. No operator action required, no DB migration, no option rename — the god-class render methods retain their signatures and return byte-identical HTML. The casino-card template has moved from `includes/render-casino-card.php` to `views/frontend/casino-card.php`, but the old path is preserved as a forwarding shim for one release. Downstream integrators who include the template directly should update to the new path before 1.14.0 ships.

Downstream integrators who want to supply a custom renderer can now register one via the `dataflair_card_renderer` or `dataflair_table_renderer` filter. Implement the matching interface (`CardRendererInterface` or `TableRendererInterface`) and return your instance; the plugin will consume it in place of the default. Filter returns that do not implement the documented interface are rejected and the default kept.

### To 1.11.2

1.11.2 is the Phase 1 **observability foundation** release. Additive only — the old code paths continue to work unchanged. No operator action required.

Downstream integrators who want to capture structured DataFlair events in Sentry or another log aggregator can now register a custom logger via the `dataflair_logger` filter. Implement `DataFlair\Toplists\Logging\LoggerInterface` (8 PSR-3-style methods) and return your instance from the filter; subsequent sync, render, and HTTP calls will route through it. The `dataflair_logger_level` filter controls the minimum level (default: `notice`). Out-of-the-box behaviour is unchanged: the default `ErrorLogLogger` writes to the same `error_log()` destination the plugin used before.

Six telemetry hooks are now emitted at named call sites — `dataflair_sync_batch_started`, `dataflair_sync_batch_finished`, `dataflair_sync_item_failed`, `dataflair_render_started`, `dataflair_render_finished`, `dataflair_http_call`. Structured payloads include `elapsed_seconds`, `memory_peak`, pagination, and HTTP size/status. These are the stable telemetry points every later extraction phase will preserve byte-for-byte.

A one-time option rename migration (gated by `dataflair_options_renamed_v1_11_2`) copies `dataflair_last_toplists_cron_run` to `dataflair_last_toplists_sync` (and brands equivalent). The legacy names continue to be written in parallel for one release; `format_last_sync_label()` falls back to the legacy name when the new one is empty.

New WP-CLI tail: `wp dataflair logs [--since=15m] [--level=warning] [--limit=200]`.

### To 1.11.1

1.11.1 is the Phase 0.5 **perf-rig + CI gate** release. Pure internal tooling — no production-facing behaviour change. Upgrade is a drop-in.

Operators who want to run the perf gate locally need WP-CLI on `$PATH` and can then invoke `composer perf` from the plugin root. See `docs/PERF.md` for thresholds, tiers, scenarios, and how to read the probe output. CI runs the gate on every PR targeting `epic/refactor-april` or `main`.

### To 1.11.0

1.11.0 is the Phase 0B **safety-rails** release. It removes the WP-cron auto-sync machinery entirely — sync now runs only when an operator triggers it from the admin **Tools** page or via WP-CLI. A one-time migration clears legacy cron schedules from prior installs (gated by the persistent option `dataflair_cron_cleared_v1_11`); nothing on the operator's side to do.

No database migration is required beyond the automatic `check_database_upgrade()` pass, which is now gated by the `dataflair_schema_ok_v{VERSION}` transient (12 h) so it does not re-run on every page load.

### To 1.10.8

After updating the plugin files, run the reconcile CLI **once** on the target site to backfill the new `cached_review_post_id` column:

```bash
wp dataflair reconcile-reviews --dry-run   # preview
wp dataflair reconcile-reviews             # execute
```

Brands that already match a published review post will be linked. Brands without a published review are left unlinked; they will be linked automatically on the next sync once their review CPT is published.

---

## Changelog

### 2.1.8
- **Phase 9.12 — shortcode + campaign redirect extraction.** The two remaining public entry points leave the god-class for dedicated single-responsibility classes under `DataFlair\Toplists\Frontend\Shortcode\` and `DataFlair\Toplists\Frontend\Redirect\`.
- Added: `ToplistShortcode`, `ShortcodeRegistrar` (`src/Frontend/Shortcode/`), `CampaignRedirectHandler` (`src/Frontend/Redirect/`).
- Refactored: `toplist_shortcode()` and `handle_campaign_redirect()` on the god-class are now one-line delegators. `add_shortcode('dataflair_toplist', …)` and `add_action('template_redirect', …)` registrations move to `Plugin::registerHooks()` via dedicated registrars.
- Deferred-callable wiring: the shortcode is registered through a generic `callable` so heavy-weight orchestrator construction is deferred to first invocation — every other plugin has had a chance to register its `dataflair_card_renderer` / `dataflair_table_renderer` filters before the renderer resolves through the lazy `Container`.
- Render-time read-only invariant preserved: `ToplistShortcode::render()` still passes the H7 prefetched brand-meta map into every `CasinoCardVM`. `RenderIsReadOnlyTest` + `RenderBatchQueryCountTest` continue to enforce this — both H* invariants now point to the new class file at the source-scan layer.
- No public contract change: shortcode HTML is byte-identical, campaign redirect URL pattern (`/go/?campaign=…`) and per-campaign hit-counter transient unchanged.
- Tests: 21 new unit tests pin attribute validation, repository lookup-by-slug-or-id, H7 prefetch ordering, dispatch into table vs cards rendering, the registrar's `function_exists` guard, the campaign happy path, the invalid-campaign 404, the already-redirected guard, and the per-campaign hit-counter transient. Total suite: **574 tests, 1,270 assertions, all green**.
- LOC: `dataflair-toplists.php` drops by ~177.

### 2.1.7
- **Phase 9.11 — HTTP / URL / Support utility extraction.** Seven stateless helpers leave the god-class for dedicated single-responsibility classes under `DataFlair\Toplists\Http\` and `DataFlair\Toplists\Support\`.
- Added: `UrlValidator`, `UrlTransformer`, `EnvironmentDetector`, `RelativeTimeFormatter` (all under `src/Support/`). `ApiBaseUrlDetector`, `BrandsApiUrlBuilder`, `ApiErrorFormatter` (all under `src/Http/`).
- Refactored: `is_local_url()`, `maybe_force_https()`, `is_running_in_docker()`, `get_api_base_url()`, `get_brands_api_url()`, `build_detailed_api_error()`, `time_ago()`, `time_until()` are now one-line delegators wired through lazy `Container` getters. Closure-based DI from Phase 9.10 continues to resolve through these delegators.
- Phase 0B invariants preserved: render is still read-only; cron is still removed; HTTPS-force, Docker detection, and human-readable error formatting all behave exactly as before.
- Tests: 50 new unit tests pin behaviour at the boundary (`UrlValidatorTest`, `UrlTransformerTest`, `EnvironmentDetectorTest`, `ApiBaseUrlDetectorTest`, `BrandsApiUrlBuilderTest`, `ApiErrorFormatterTest`, `RelativeTimeFormatterTest`). Total suite: 553 tests, 1,213 assertions, all green.
- LOC: `dataflair-toplists.php` drops 1,772 → 1,700.

### 2.1.6
- **Phase 9.10 — sync pipeline helpers extraction.** Seven god-class methods leave for dedicated single-responsibility classes under `DataFlair\Toplists\Sync\` and `DataFlair\Toplists\Database\`. The toplist endpoint walker, single-toplist fetcher, row writer, transient sweeper, paginated table delete, JSON CSV value collector, and the logo-sync wrapper all become small, isolated units.
- Added: `EndpointDiscovery`, `ToplistFetcher`, `LogoSync`, `TransientCleaner` (all under `src/Sync/`). `ToplistDataStore`, `PaginatedDeleter`, `JsonValueCollector` (all under `src/Database/`).
- Removed inline: `discover_toplist_endpoints()`, `fetch_and_store_toplist()`, `store_toplist_data()`, `download_brand_logo()`, `clear_tracker_transients()`, `delete_all_paginated()`, `collect_distinct_csv_values()` — all now one-line delegators wired through lazy `Container` getters.
- Phase 0B H10 + H11 invariants preserved: chunked transient sweep with `LIMIT 1000`, `PaginatedDeleter` rejects non-whitelisted tables, `WallClockBudget` cooperative bail-out before the first query when budget is exhausted.
- Render-time read-only invariant preserved: none of the new classes are reachable from the casino-card render path. `RenderIsReadOnlyTest` continues to enforce this.
- No public contract change. The dispatch into the seven god-class methods still produces the same side effects (`error_log`, `add_settings_error`) byte-for-byte. `GodClassToplistPersister` still satisfies `ToplistPersisterInterface` via closure-bind onto the now-trivial god-class delegators.
- Main file size: `dataflair-toplists.php` drops from 1,939 → 1,772 LOC (−167).
- Tests: 25 new unit tests; full suite **503 tests, 1,151 assertions, all green**.

### 2.1.5
- **Phase 9.9 — review post manager + brand-meta extraction.** Seven helper methods leave the god-class for dedicated single-responsibility classes under `DataFlair\Toplists\Frontend\Content\` and `DataFlair\Toplists\Frontend\Render\`. The on-demand review-CPT manager, brand-meta prefetcher (H7), batched review-post finder (H8), and the relative-time admin label all become testable, single-purpose units.
- Added: `ReviewPostFinder`, `ReviewPostManager`, `ReviewPostBatchFinder` (all under `src/Frontend/Content/`). `BrandMetaPrefetcher`, `BrandMetaLookup`, `SyncLabelFormatter` (all under `src/Frontend/Render/`).
- Removed: `find_review_post_by_brand_meta()`, `get_or_create_review_post()`, `find_review_posts_by_brand_metas()`, `prefetch_brand_metas_for_items()`, `lookup_brand_meta_from_map()`, `format_last_sync_label()`, `resolve_pros_cons_for_table_item()` from the god-class. Six become one-line delegators wired through lazy `Container` getters; `resolve_pros_cons_for_table_item()` deleted outright (the trait-based `ProsConsResolver` was already authoritative).
- Render-time read-only invariant preserved: `ReviewPostManager` is **not** called from the casino-card render path — only from sync, WP-CLI reconcile, and admin paths. `RenderIsReadOnlyTest` continues to enforce this.
- No public contract change. The H7/H8 SQL batch shape, the brand-meta map structure, the auto-create draft flow, and every `_review_*` meta key remain identical to v2.1.4.
- Main file size: `dataflair-toplists.php` drops by ~200 LOC.
- Tests: 26 new tests; full suite **478 tests, 1,111 assertions, all green**.

### 2.1.4
- **Phase 9.8 — frontend assets + Alpine.js extraction.** The five frontend asset methods leave the god-class for dedicated classes under `DataFlair\Toplists\Frontend\Assets\`. Each registers its own WordPress hook via `register()`; `Plugin::registerHooks()` wires them.
- Added: `StylesEnqueuer`, `AlpineJsEnqueuer`, `PromoCopyScript`, `AlpineDeferAttribute`, `WidgetShortcodeDetector` — all under `src/Frontend/Assets/`.
- Removed: `enqueue_frontend_assets()`, `maybe_enqueue_alpine()`, `enqueue_promo_copy_script()`, `add_alpine_defer_attribute()`, `check_widget_for_shortcode()` from the god-class. Removed: 4 corresponding `add_action`/`add_filter` registrations from `init_hooks()`.
- No public contract change. Alpine 3.13.5 still loaded from the same jsDelivr URL, still filterable via `dataflair_alpinejs_url`. Stylesheet handle and footer-script ordering (priority 5 / 20) byte-identical.
- Main file size: `dataflair-toplists.php` drops from ~2,347 → ~2,165 LOC (−182).
- Tests: 18 new tests; full suite 452 tests, 1,062 assertions, all green.

### 2.1.3
- **Phase 9.7 — AJAX handler extraction.** The eleven remaining `ajax_*` methods leave the god-class for dedicated single-responsibility handler classes under `DataFlair\Toplists\Admin\Ajax\`. Each implements `AjaxHandlerInterface::handle(array $request): array`; `AjaxRouter` retains centralised nonce + capability checks.
- Added (de-stubbed): `SaveSettingsHandler`, `FetchAllToplistsHandler`, `SyncToplistsBatchHandler`, `SyncBrandsBatchHandler`, `FetchAllBrandsHandler`, `GetAlternativeToplistsHandler`, `SaveAlternativeToplistHandler`, `DeleteAlternativeToplistHandler`, `GetAvailableGeosHandler`, `ApiPreviewHandler`, `SaveReviewUrlHandler` — all under `src/Admin/Ajax/`.
- Renamed: `src/Admin/Handlers/` → `src/Admin/Ajax/` per the v2.1.x plan delta map. Namespace declarations and use statements updated across the existing handler suite, `AdminBootstrap`, and the test suite.
- Removed: every `function ajax_*` body from `dataflair-toplists.php`. `grep -c "function ajax_" dataflair-toplists.php` returns 0.
- No public contract change. Every `wp_ajax_dataflair_*` action name, nonce, expected POST key set, and JSON response shape remains identical to v2.1.2. Frontend admin JS unchanged.
- Main file size: `dataflair-toplists.php` drops from ~2,773 → ~2,347 LOC.
- Tests: full suite 434 tests, 1,028 assertions, all green.

### 2.1.2
- **Phase 9.6 — admin UI extraction.** `dataflair-toplists.php` shrank by ~2,082 lines (43%) as the two largest inline admin page bodies and three admin-side registrars extracted into dedicated classes under `src/Admin/`. The main plugin file dropped from 4,855 → ~2,773 LOC — the largest single phase by LOC of the v2.1.x continuation arc.
- Added: `\DataFlair\Toplists\Admin\Pages\SettingsPage` — replaces inline `settings_page()` (~705 LOC of HTML, jQuery, AJAX wiring, fetch-all progress block). Constructor accepts two typed `\Closure` dependencies (`apiBaseUrlResolver`, `lastSyncLabelFormatter`) so the page can call back into still-private god-class helpers without inheriting the singleton. `render()` returns void; class is final.
- Added: `\DataFlair\Toplists\Admin\Pages\BrandsPage` — replaces inline `brands_page()` (~1,237 LOC of brand-table rendering, pagination, filter-dropdown collection, AJAX endpoints). Constructor accepts `distinctCsvValuesCollector` + `lastSyncLabelFormatter` closures. Markup, jQuery wiring, and pagination shape preserved byte-for-byte.
- Added: `\DataFlair\Toplists\Admin\MenuRegistrar` — owns `add_menu_page` + the two `add_submenu_page` calls. Top-level menu icon (`dashicons-list-view`) and position (30) unchanged. Top-level slug routes to `[$settings, 'render']`; brands submenu routes to `BrandsPage::render`; toplists submenu retained.
- Added: `\DataFlair\Toplists\Admin\SettingsRegistrar` — owns the nine `register_setting` calls under the `dataflair_settings` group: `dataflair_api_token`, `dataflair_api_base_url` (registered twice for byte parity), `dataflair_api_endpoints`, `dataflair_http_auth_user`, `dataflair_http_auth_pass`, `dataflair_ribbon_bg_color`, `dataflair_ribbon_text_color`, `dataflair_cta_bg_color`, `dataflair_cta_text_color`. Hooked on `admin_init`.
- Added: `\DataFlair\Toplists\Admin\Notices\PermalinkNotice` — owns the plain-permalinks admin warning. Renders the `notice notice-error` markup pointing at `options-permalink.php` only when `permalink_structure` is empty. Hooked on `admin_notices`.
- Changed: `Plugin::registerHooks()` now wires all five new admin classes through the lazy `Container`. The god-class `init_hooks()` no longer calls `add_admin_menu`, `register_settings`, `enqueue_admin_scripts`, or `maybe_notice_plain_permalinks` directly — those registrations moved to the new owners.
- Changed: the god-class `settings_page()`, `brands_page()`, `add_admin_menu()`, `register_settings()`, `enqueue_admin_scripts()`, and `maybe_notice_plain_permalinks()` methods are deleted. Closure injection means the page classes still reach the helpers they need without re-introducing public surface area on the deprecated singleton.
- Added: 22 new tests across `SettingsPageTest`, `BrandsPageTest`, `MenuRegistrarTest`, `SettingsRegistrarTest`, `PermalinkNoticeTest`. Reflection-based contract tests pin constructor signatures, interface conformance, render-method shape, and class finality without re-rendering the 1,200+ LOC HTML bodies inside PHPUnit (cheaper and tighter than full integration tests for two pages already covered by manual smoke). Full suite: **434 tests, 1,028 assertions, all green**.
- Why a patch bump (2.1.1 → 2.1.2): zero behavioural change for end users. Every admin URL, AJAX action, settings option name, capability check, nonce, page slug, and submenu position is preserved byte-for-byte. The new classes are strangler-fig delegates; the `DataFlair_Toplists::get_instance()` strict-deprecation contract from 2.1.0 is untouched.

### 2.1.1
- **Phase 9.5 — WPPB-style bootstrap decoupling.** `dataflair-toplists.php` shrank by ~860 lines (15%) as five responsibilities extracted into dedicated classes under `src/`, following the WordPress Plugin Boilerplate layout. The main plugin file now owns only the bootstrap constants, `composer` autoload, WPPB `register_activation_hook` / `register_deactivation_hook`, and `\DataFlair\Toplists\Plugin::boot(__FILE__)`.
- Added: `\DataFlair\Toplists\Admin\PluginInfoFilter` — `plugins_api` "View details" popup (description, full changelog, banners). Registers on `plugins_api` with priority 10, 3 args.
- Added: `\DataFlair\Toplists\UpdateChecker\GithubUpdateChecker` — YahnisElsts PUC v5 bootstrap. Constructor takes `$pluginFile, $repoUrl, $slug` with repo/slug defaults. `register()` short-circuits when PucFactory is absent (e.g. unit tests without vendor tree) or when `WP_PLUGIN_DIR` is undefined. Calls `buildUpdateChecker()` + `enableReleaseAssets()` — contract preserved byte-for-byte.
- Added: `\DataFlair\Toplists\I18n` — `load_plugin_textdomain('dataflair-toplists', false, …/languages)` on `init`. Fixes a latent bug where the textdomain header was declared but never actually loaded, so translations would have silently failed.
- Added: `\DataFlair\Toplists\Lifecycle\Activator` + `Deactivator` — WPPB-style static `::activate()` / `::deactivate()` hooked via `register_activation_hook(__FILE__, …)` / `register_deactivation_hook(__FILE__, …)` in the main plugin file. Activator runs the schema migrator; Deactivator clears legacy `dataflair_sync_cron` and `dataflair_brands_sync_cron` hooks (idempotent — they were removed in v1.11.0).
- Added: `\DataFlair\Toplists\Database\SchemaMigrator` — owns `checkDatabaseUpgrade()`, `createTables()`, `ensureTablesExist()`, `ensureBrandsExternalIdIndex()`, `ensureAlternativeToplistsTable()`, `supportsJsonType()`, `migrateToJsonType()`. Preserves the H9 schema-ok-v transient short-circuit and the H1 legacy-cron clear gate (`dataflair_cron_cleared_v1_11`). Registers on `plugins_loaded` @ priority 5 to run before the god-class's other hooks.
- Changed: `Plugin::boot(?string $pluginFile = null)` — now accepts the plugin file path at boot time and exposes it via `Plugin::pluginFile()` so extracted registrars can wire hooks like `plugin_basename($pluginFile)` without hand-computing paths. Fallback reads `DATAFLAIR_PLUGIN_DIR . 'dataflair-toplists.php'` for legacy bootstraps.
- Changed: the god-class methods `activate()`, `deactivate()`, `check_database_upgrade()`, `ensure_alternative_toplists_table()`, `supports_json_type()`, `migrate_to_json_type()` are now thin delegators to the extracted classes. Method signatures and public visibility preserved so any downstream caller continues to resolve.
- Fixed: `GithubUpdateChecker::register()` guards against `WP_PLUGIN_DIR` being undefined (PUC reads it unconditionally), so unit-test harnesses that don't load WordPress don't fatal.
- Updated: `AutoUpdateTest` now searches `src/UpdateChecker/GithubUpdateChecker.php` for the PUC bootstrap substrings (`PucFactory::buildUpdateChecker`, repo URL, `enableReleaseAssets`) since they migrated out of the main plugin file. `ClearTransientsChunkedTest::test_check_database_upgrade_uses_schema_ok_transient` now extracts the method body from `SchemaMigrator::checkDatabaseUpgrade()` instead of the main file. `CronRemovedTest` appends `SchemaMigrator` source to its scan so the legacy-cron clear assertions still hit. Full suite: **412 tests, 946 assertions, all green**.
- Why a patch bump (2.1.0 → 2.1.1): zero behavioural change for end users. Every hook, option, table, shortcode, block, REST route, and AJAX action is preserved byte-for-byte. The new classes are strangler-fig delegates; the `DataFlair_Toplists::get_instance()` strict-deprecation contract from 2.1.0 is untouched.

### 2.1.0
- **Phase 9 — strict deprecation default-on.** The v2.0.x migration window on `DataFlair_Toplists::get_instance()` closes. Downstream calls from outside `DATAFLAIR_PLUGIN_DIR` now emit `E_USER_DEPRECATED` once per unique caller file/line per request, pointing to `\DataFlair\Toplists\Plugin::boot()`.
- Per-caller de-duplication — a static guard keyed on `file:line` prevents the notice from firing more than once per caller per request, even as the god-class re-enters `get_instance()` dozens of times during hook dispatch.
- Internal caller filtering — any call originating inside `DATAFLAIR_PLUGIN_DIR` (including extracted `src/` classes that still walk through the singleton during the strangler-fig transition) is filtered out of the notice emission. Only genuine downstream callers see the warning.
- Filter opt-out remains supported: `add_filter('dataflair_strict_deprecation', '__return_false');` silences all notices.
- Added: **6 new tests** — `ShimForwardingTest` (default-on contract, filter opt-out, per-caller de-dup, different-callers-each-fire-once, internal-callers-filtered-out, singleton preserved). Driven by a testable companion class (`DataFlair_Toplists_Phase9_Shim`) that mirrors the v2.1.0 `get_instance()` logic byte-for-byte so we don't have to load the 5,600-line plugin file to exercise the contract. Full suite: **412 tests, 944 assertions, all green**.
- Refreshed `UPGRADING.md` with v2.1.0 strict-mode guidance, the extraction trajectory for v2.1.x, and the v3.0.0 class-symbol-removal commitment.
- Scope note: Phase 9 as originally written called for full god-class deletion. Reality of the extraction arc is that ~80 methods (shortcode, schema upgrades, DB helpers) remain un-extracted, so v2.1.0 ships the **signal escalation** (strict-on-by-default) and defers class-symbol removal to v3.0.0 after those methods extract in v2.1.x point releases. No downstream break, no change in hook behaviour.

### 2.0.0
- **Phase 8 — canonical bootstrap seam.** `\DataFlair\Toplists\Plugin::boot()` is now the canonical entry point for the plugin. The plugin file calls `Plugin::boot()` directly; the boot routine is idempotent and internally still calls `DataFlair_Toplists::get_instance()` to preserve every existing hook registration.
- Added: `\DataFlair\Toplists\Container` — hand-written lazy service container (`register` / `set` / `get` / `has`, zero external dependencies, no Symfony, no Pimple, no PSR-Container). Services are resolved on first `get()` and memoised. `register()` invalidates a prior memoised instance so test harnesses can swap factories mid-request. Factories receive the container itself so they can pull sub-dependencies.
- Added: `\DataFlair\Toplists\Plugin` — final class with static `boot()` / `instance()` / `resetForTests()` seams. The container is built once per request and currently wires the `logger` service. Downstream integrators override services with `Plugin::boot()->container()->set('logger', new MySentryLogger())`.
- Deprecation — **not removal yet**: `DataFlair_Toplists` is marked `@deprecated 2.0.0`; `DataFlair_Toplists::get_instance()` continues to work through the v2.0.x line. Strict-mode notices are opt-in via `add_filter('dataflair_strict_deprecation', '__return_true')`. Removal tracked for v2.1.0.
- Added: `UPGRADING.md` — full migration guide covering the recommended `Plugin::boot()` pattern, strict-mode notices, and container overrides.
- Added: PSR-4 autoload catch-all entry for `DataFlair\Toplists\` → `src/` (specific sub-namespaces retain their own entries for longest-prefix match).
- Added: **14 new tests** — `ContainerTest` (7 tests: lazy resolution, memoisation, set override, re-register invalidation, has reporting, unknown-id throws, factory receives container for sub-deps), `PluginBootTest` (7 tests: boot returns Plugin instance, idempotent, instance null before boot, instance returns booted singleton, resetForTests clears, container exposes logger, downstream can override a container service). Full suite: **406 tests, 938 assertions, all green**.
- Why a major bump: new canonical public API (`Plugin::boot()`), formal deprecation window opens on `DataFlair_Toplists`, downstream integrators should migrate within the v2.0.x line. No runtime behaviour changed for end users.

### 1.15.1
- **Phase 7 — block registrars extracted.** `register_block_type` for the `dataflair-toplists/toplist` block is now owned by `DataFlair\Toplists\Block\BlockRegistrar`. The render callback moved to `DataFlair\Toplists\Block\ToplistBlock` (closure-based DI for the shortcode renderer + option reader keeps it `$wpdb`-free). Editor CSS enqueue moved to `DataFlair\Toplists\Block\EditorAssets`. Block metadata path resolution (`build/block.json` → `src/block.json` fallback), block attributes, shortcode delegation, and `prosCons` pass-through all preserved byte-for-byte.
- Added: `DataFlair\Toplists\Block\BlockBootstrap` — single wiring seam. The god-class calls `$this->block_bootstrap()->boot()->register()` from `init_hooks()`; `register()` installs both the `init` and `enqueue_block_editor_assets` hooks.
- Added: PSR-4 autoload entry for `DataFlair\Toplists\Block\` → `src/Block/`.
- Added: **11 new block tests** — `BlockRegistrarTest` (init + editor-assets hook wiring, `register_block_type` fires with `build/block.json`, falls back to `src/block.json`, silently no-ops when neither exists), `ToplistBlockTest` (empty-state help text on missing `toplistId`, null-attributes handling, option-reader defaults, user attributes override defaults, `limit` coerced to int, `prosCons` pass-through, return value verbatim from the shortcode closure), `EditorAssetsTest` (editor stylesheet handle + URL + version). Full suite: **392 tests, 915 assertions, all green**.
- Changed: `register_block()`, `render_block($attributes)`, and `enqueue_editor_assets()` on the god-class are now thin delegators. No behavioural change for block editor users.

### 1.15.0
- **Phase 6 — REST endpoints extracted.** The three `/wp-json/dataflair/v1/*` routes are now owned by `DataFlair\Toplists\Rest\RestRouter`, registered from a dedicated `RestBootstrap` seam, and dispatched to per-route controllers. Public REST contract — URL shapes, response envelopes, permission callbacks, header emission — preserved byte-for-byte.
- Added: `DataFlair\Toplists\Rest\RestRouter` — single owner of `register_rest_route()` for the `dataflair/v1` namespace. Central `canEditPosts()` and `canManageOptions()` permission callbacks replace the three inline closures the god-class used.
- Added: `DataFlair\Toplists\Rest\Controllers\ToplistsController` — serves `GET /toplists`. Lean `{value, label}` envelope with slug-or-ID suffix, repository-backed, `Throwable`s are caught and translated to `WP_Error` with status 500.
- Added: `DataFlair\Toplists\Rest\Controllers\CasinosController` — serves `GET /toplists/{id}/casinos`. H12 pagination, lean default shape, `?full=1` legacy shape, `X-WP-Total` + `X-WP-TotalPages` headers. Brand metadata prefetch is injected via `\Closure` so the controller is $wpdb-free.
- Added: `DataFlair\Toplists\Rest\Controllers\HealthController` — serves `GET /health`. Returns `{status, toplists, plugin_ver, db_error}` exactly as before; counts delegated to `ToplistsRepository::countAll()`.
- Added: `DataFlair\Toplists\Rest\RestBootstrap` — thin wiring class that instantiates the three controllers and hands them to `RestRouter`. Lazy — nothing is constructed until `rest_api_init` fires.
- Added: repository extensions — `ToplistsRepository::listAllForOptions(): array` (lean projection `api_toplist_id, name, slug` ordered by `api_toplist_id ASC`) and `ToplistsRepository::countAll(): int`. Every REST read path now routes through the repository; the god-class's `register_rest_routes()`, `get_toplists_rest()`, `get_toplist_casinos_rest()` methods are thin delegators.
- Added: PSR-4 autoload entry for `DataFlair\Toplists\Rest\` → `src/Rest/`.
- Changed: the three inline closure + array-callable REST registrations in the god-class collapse to a single `$this->rest_bootstrap()->boot()->register()` call.
- Added: 20 new tests — `RestRouterTest` (three routes on the right namespace, HTTP methods, pagination arg defaults + bounds, permission callbacks), `ToplistsControllerTest` (value-label pairs, slug-vs-ID suffix, empty repo, `Throwable` → `WP_Error`), `CasinosControllerTest` (not-found → 404, empty-items response + 0/0 headers, lean shape verbatim, `?full=1` legacy shape, pagination slice + total-pages maths, per_page clamping 1..100, alternate payload shapes `{data.items}` / `{data.listItems}` / `{listItems}`, items without a brand name are skipped), `HealthControllerTest` (ok-envelope, `$wpdb->last_error` surfaced when set), and `ToplistsRepositoryTest` extensions for the two new repo methods. Full suite: **381 tests, 887 assertions, all green**.
- Changed: the Phase 0B `RestCasinosPaginationTest` integration test now scans the extracted `RestRouter` + `CasinosController` source files instead of the god-class body. The H12 structural guard rails stay alive through the refactor, while execution-path coverage lives in the new `CasinosControllerTest`.

### 1.14.0
- **Phase 5 — admin pages + AJAX router extracted.** Every admin-side AJAX action is now registered through a single `AjaxRouter` that owns nonce + capability checks centrally, dispatches to one handler class per action, and wraps the structured response in `wp_send_json_*`. No public contract change: every `wp_ajax_dataflair_*` action name, nonce action, payload shape, and admin-JS integration preserved byte-for-byte.
- Added: `DataFlair\Toplists\Admin\AjaxRouter` — per-action routing table (`handler`, `nonce`, `capability`), `check_ajax_referer()` + `current_user_can()` gate before any handler runs, `try/catch` around handler invocation so a thrown `Throwable` becomes a logged `ajax.router.handler_threw` warning + `wp_send_json_error` instead of a 500.
- Added: `DataFlair\Toplists\Admin\AjaxHandlerInterface` — single-method contract (`handle(array $request): array`) returning `['success' => bool, 'data' => array|null]`. Eleven concrete handlers implement it: `SaveSettingsHandler`, `FetchAllToplistsHandler`, `SyncToplistsBatchHandler`, `FetchAllBrandsHandler`, `SyncBrandsBatchHandler`, `GetAlternativeToplistsHandler`, `SaveAlternativeToplistHandler`, `DeleteAlternativeToplistHandler`, `GetAvailableGeosHandler`, `ApiPreviewHandler`, `SaveReviewUrlHandler`.
- Added: `DataFlair\Toplists\Admin\Assets\AdminAssetsRegistrar` — the `admin_enqueue_scripts` filter registration now lives in a dedicated registrar class. Select2 + `dataflair-admin` bundle enqueue + five `wp_localize_script` nonces preserved byte-for-byte.
- Added: `DataFlair\Toplists\Admin\Pages\PageInterface` + `SettingsPage` + `BrandsPage` thin delegator seams. The 700-line `settings_page()` and 1,200-line `brands_page()` HTML bodies stay on the god-class for one more release and render through an injected `\Closure`; a follow-up moves the HTML into `views/admin/`.
- Added: `DataFlair\Toplists\Admin\AdminBootstrap` — single wiring seam that instantiates the router, registers all 11 handlers with their matching nonce actions, and exposes `registerAssets()`. The god-class calls `$this->admin_bootstrap()->boot()` + `->registerAssets()` from `init_hooks()`.
- Added: repository extensions used by the handlers — `AlternativesRepository::deleteById(int $id): bool`, `ToplistsRepository::collectGeoNames(): array` (parses `data.data.geo.name` out of every toplist's JSON blob, dedups, sorts alphabetically), and `BrandsRepository::updateReviewUrlOverrideByApiBrandId(int $api_brand_id, ?string $url): bool`.
- Added: PSR-4 autoload entry for `DataFlair\Toplists\Admin\` → `src/Admin/`.
- Changed: the eleven `add_action('wp_ajax_dataflair_*', …)` registrations in `init_hooks()` are now a single `$this->admin_bootstrap()->boot();` call. The legacy `ajax_*` methods on the god-class remain (kept until Phase 8 — shim birth). The single `add_action('admin_enqueue_scripts', …)` line becomes `$this->admin_bootstrap()->registerAssets();`.
- Removed: `includes/render-casino-card.php` forwarding shim (deprecated in v1.13.0 with an explicit one-release removal notice). Template lives at `views/frontend/casino-card.php`.
- Added: 17 new tests — `AjaxRouterTest` (unknown-action guard, nonce failure, cap denial, successful wrap, exception translation, `$_GET`+`$_POST` merge, registration listing), `GetAvailableGeosHandlerTest`, `SaveSettingsHandlerTest` (token trimmed not sanitised, password trimmed only, brands-api-version whitelisted, empty base URL deletes option, base URL pinned to `/api/vN`, colour fields sanitised, absent fields not written). Full suite: **358 tests, 823 assertions, all green**.

### 1.13.0
- **Phase 4 — rendering + ViewModels extracted.** Casino-card and toplist-table render paths are now owned by dedicated classes. The casino-card template moved from `includes/render-casino-card.php` to `views/frontend/casino-card.php`; the old path stays as a forwarding shim for one release (deleted in Phase 5). No public contract change — `render_casino_card()` and `render_toplist_table()` on the god-class retain their signatures and return byte-identical HTML.
- Added: `DataFlair\Toplists\Frontend\Render\CardRenderer` implementing `CardRendererInterface`. Wraps the casino-card template-include path through an immutable `CasinoCardVM` ViewModel (readonly `item`, `toplistId`, `customizations`, `prosConsData`, `brandMetaMap`). Preserves every Phase 0A / 0B / Phase 1 invariant — read-only (no `wp_remote_*`, no `wp_insert_post`, no `wp_handle_sideload`, no `update_option`, no `update_post_meta`), precomputed `local_logo_url` verbatim, `cached_review_post_id` preferred over `WP_Query`, prefetched `brand_meta_map` wins over per-card repository calls (H7 contract), and the `dataflair_review_url` filter fires on the resolved URL. The god-class delegator drops the legacy pre-Phase-0A fallback (it was unreachable in practice and violated the read-only contract).
- Added: `DataFlair\Toplists\Frontend\Render\TableRenderer` implementing `TableRendererInterface`. Wraps the block-editor debug `layout=table` accordion through the immutable `ToplistTableVM` ViewModel (readonly `items`, `title`, `isStale`, `lastSynced`, `prosConsData`). HTML output is byte-identical to the god-class method.
- Added: `DataFlair\Toplists\Frontend\Render\ProsConsResolver` trait shared by both renderers, keeping `resolve_pros_cons_for_table_item()` on `$this` so the template's call surface is unchanged when `$this` rebinds to the renderer instead of the god-class.
- Added: lazy filter-based DI for the renderers — `dataflair_card_renderer`, `dataflair_table_renderer`. Filter returns that do not implement the documented interface are rejected and the default kept.
- Added: `BrandsRepository::findByName(string $name)` — backs the legacy per-card name-based review-URL fallback cascade used by `CardRenderer` when the caller passes a null `brand_meta_map`.
- Added: PSR-4 autoload entry for `DataFlair\Toplists\Frontend\` → `src/Frontend/`.
- Changed: the god-class `render_casino_card()` shrinks from 638 lines to a 15-line delegator; `render_toplist_table()` from 136 lines to an 11-line delegator. Plugin file drops from 6,411 to 5,663 lines. The template path `includes/render-casino-card.php` still works through the forwarding shim.
- Added: 17 new tests — `CasinoCardVMTest` + `ToplistTableVMTest` (readonly enforcement, defaults, full construction), `CardRendererTest` (Brain Monkey integration: map path does not query the repo, null-map falls back to `findByApiBrandId`, `dataflair_review_url` filter fires with the right initial URL in both paths), `TableRendererTest` (accordion wrapper, stale-notice gating, title omission, pros/cons propagation, offer fields rendered). Full suite: **341 tests, 793 assertions, all green**.

### 1.12.1
- **Phase 3 — sync services extracted.** Continues the strangler-fig arc started in Phase 2. The toplist and brand sync pipelines are now owned by dedicated service classes; the god-class AJAX handlers shrink to 5–20 line delegators. No public contract change: every AJAX endpoint, every response shape, every filter, every action hook, every option name preserved byte-for-byte.
- Added: `DataFlair\Toplists\Sync\ToplistSyncService` implementing `ToplistSyncServiceInterface`. Owns the full bulk happy-path + progressive per-ID fallback (per_page=10 → per_page=5 × 2 → per_page=1 × 5), the 30 s hard deadline, the JSON decode + invalid-body error paths, the page-1 paginated `DELETE FROM wp_dataflair_toplists` reset, the `dataflair_toplists_batch_last_page` transient, and the legacy `dataflair_last_toplists_sync` / `dataflair_last_toplists_cron_run` option writes.
- Added: `DataFlair\Toplists\Sync\BrandSyncService` implementing `BrandSyncServiceInterface`. Owns the 13-column brand upsert, the Active status filter, the page-1 paginated `DELETE FROM wp_dataflair_brands`, the 25 s `WallClockBudget` with 3 s headroom, the H4 `unset()` + `gc_collect_cycles()` memory hygiene, the `download_brand_logo()` invocation (with its 3 MB / 8 s cap + HEAD-before-GET), and the `dataflair_brand_logo_stored` action hook at store time.
- Added: `DataFlair\Toplists\Sync\AlternativesSyncService` implementing `AlternativesSyncServiceInterface`. Covers the alternative-toplists CRUD surface with input guards (`toplist_id > 0`, save requires `toplist_id` + `geo`) and logger-side warnings on rejected input.
- Added: immutable value objects `SyncRequest` (readonly `type`, `page`, `perPage`, `budgetSeconds`; factories `toplists()` / `brands()` honour H13 budget defaults of 25.0 s, per_page 10 / 5) and `SyncResult` (readonly `success`, `page`, `lastPage`, `synced`, `errors`, `partial`, `isComplete`, `nextPage`; `toArray()` preserves every legacy AJAX key so the admin JS continues to work verbatim).
- Added: lazy filter-based DI for services — `dataflair_toplist_sync_service`, `dataflair_brand_sync_service`, `dataflair_alternatives_sync_service`. Non-interface filter returns are rejected.
- Added: PSR-4 autoload entry for `DataFlair\Toplists\Sync\` → `src/Sync/`.
- Changed: god-class AJAX handlers (`ajax_sync_toplists_batch`, `ajax_sync_brands_batch`, alternatives save/delete) are now thin delegators — nonce + capability + token precheck stay at the AJAX gate, everything below forwards into the service. Removed ~520 lines of now-dead private methods (`sync_toplists_page_per_id`, `sync_brands_page`, helpers). God-class shrinks to 6,343 lines.
- Added: 38 new tests — `ToplistSyncServiceTest`, `BrandSyncServiceTest`, `AlternativesSyncServiceTest`, `SyncRequestTest`, `SyncResultTest`. Full suite: **324 tests, 753 assertions, all green**.

### 1.12.0
- **Phase 2 — repositories + HTTP client extracted.** First real strangler-fig phase of the refactor arc. The god-class keeps every public method signature intact; the implementations now delegate through typed, testable collaborators.
- Added: new `src/` tree. `src/Http/{ApiClient, LogoDownloader}` implement `HttpClientInterface` + `LogoDownloaderInterface`. `src/Database/{ToplistsRepository, BrandsRepository, AlternativesRepository}` implement matching interfaces — all Phase 0B / Phase 1 invariants preserved (15 MB response cap, 12 s timeout, 3 MB logo cap, 8 s logo timeout, HEAD-before-GET, 7-day reuse window, `dataflair_http_call` telemetry, `dataflair_brand_logo_stored` hook).
- Changed: `api_get()` and `download_brand_logo()` are now 1–5 line delegators forwarding to `ApiClient` / `LogoDownloader`. No public contract change. Same arguments, same return shapes.
- Added: lazy filter-based DI via `dataflair_api_client`, `dataflair_logo_downloader`, `dataflair_brands_repo`, `dataflair_toplists_repo`, `dataflair_alternatives_repo`. Any filter return that does not implement the documented interface is rejected and the default is kept.
- Changed: H7 (batched `api_brand_id IN (...)` brand lookup) and H8 (batched review-post JOIN) now route through `BrandsRepository::findManyByApiBrandIds()` and `BrandsRepository::findReviewPostsByApiBrandIds()`. Behaviour byte-identical; only the callsite changes.
- Added: PSR-4 autoload entries for `DataFlair\Toplists\Database\` → `src/Database/` and `DataFlair\Toplists\Http\` → `src/Http/`.
- Added: 39 new tests — `ApiClientTest`, `LogoDownloaderTest`, `BrandsRepositoryTest`, `ToplistsRepositoryTest`, `AlternativesRepositoryTest`. The pre-existing `SyncApiSizeCapTest` and `LogoSizeCapTest` were retargeted from god-class method scans to `src/Http/*` source scans. Full suite: **286 tests, 645 assertions, all green**.
- Tooling: `patchwork.json` adds `sleep` to `redefinable-internals` so Brain Monkey can stub retry backoff in unit tests.

### 1.11.2
- **Phase 1 — observability foundation.** Lands before any extraction phase so every subsequent refactor ships with a contract for structured logging + telemetry in place. Consumers (Sentry on Sigma, stdout on local, file on shared hosts) are swappable without touching plugin code.
- Added: pluggable `DataFlair\Toplists\Logging\LoggerInterface` (PSR-3-shaped, hand-written — no `psr/log` dependency). 8 methods accepting `(string $message, array $context = [])`.
- Added: three bundled implementations — `NullLogger` (no-op), `ErrorLogLogger` (writes to `error_log()` with `[DataFlair][LEVEL]` prefix + JSON-encoded context), and a `SentryLogger` stub for downstream subclasses.
- Added: `LoggerFactory::get()` resolves the active logger via `apply_filters('dataflair_logger', …)`. Caches per-request. Non-`LoggerInterface` filter return values are rejected and the default is kept. Minimum level filterable via `dataflair_logger_level` (default: `notice`).
- Added: six stable telemetry hooks emitted at named call sites — `dataflair_sync_batch_started`, `dataflair_sync_batch_finished`, `dataflair_sync_item_failed`, `dataflair_render_started`, `dataflair_render_finished`, `dataflair_http_call`. Structured payloads include `elapsed_seconds`, `memory_peak`, `page`, `per_page`, `budget_seconds`.
- Added: WP-CLI command `wp dataflair logs [--since=15m] [--level=warning] [--limit=200]`. Tails the active logger; for `ErrorLogLogger` stream-reads the last 512 KB of the `error_log` destination, filters by `[DataFlair]` tag + level + time window. Custom loggers register their own tail via the `dataflair_logs_tail` filter.
- Changed: option rename migration (one-time, gated by `dataflair_options_renamed_v1_11_2`). `dataflair_last_toplists_cron_run` becomes `dataflair_last_toplists_sync`; brands equivalent. Legacy names continue to be written in parallel for one release so downstream readers keep working.
- Tests: +13 new tests covering logger interface, factory resolution + caching, ErrorLogLogger behaviour, WP-CLI `logs` command. Total suite: 247 tests, 566 assertions, all green.

### 1.11.1
- **Phase 0.5 — perf rig + CI gate.** Internal tooling release. No production-facing behaviour change. Gives the plugin a deterministic, repeatable perf harness so every subsequent refactor phase ships with a mechanical proof that it does not re-introduce the Sigma OOM.
- Added: WP-CLI command `wp dataflair perf:seed --tier={S|Sigma|L|XL|P}`. Generates deterministic synthetic toplists + brands at five tier sizes — from 10 toplists / 50 brands (tier S, smoke) up to 2,000 toplists / 5,000 brands (tier P, punishing).
- Added: WP-CLI command `wp dataflair perf:run --tier=Sigma --scenario={render|rest|admin|sync}`. Captures peak RSS, wall time, and query count, fails non-zero on threshold breach (default 512 MB peak, 5 s wall).
- Added: drop-in MU-plugin `mu-plugins/dataflair-perf-probe.php` that emits a single-line peak-memory / wall-time / query-count stanza on every WP request. Dependency-free.
- Added: `composer perf` script that wires seed + run into a single invocation. Gracefully no-ops with a hint when WP-CLI is not on `$PATH`.
- Added: GitHub Actions workflow `.github/workflows/perf-gate.yml` running the Sigma-tier render scenario on every PR targeting `epic/refactor-april` or `main`, under `memory_limit=1G`. The `skip-perf-gate` label bypasses the gate.
- Added: `docs/PERF.md` — thresholds, tiers, scenarios, local run guide, probe output format, fatal-reproduction steps, env vars, and label-bypass policy.

### 1.11.0
- **Phase 0B safety rails — defense-in-depth follow-up to 1.10.8.** Twelve latent-OOM, timeout-cap, and memory-hygiene fixes across sync, render, admin, REST, and migration paths. No behaviour change on the happy path.
- Removed: WP-cron auto-sync machinery (H1). `dataflair_sync_cron` and `dataflair_brands_sync_cron` are gone. Sync now runs only when an operator triggers it from the admin Tools page or via WP-CLI. A one-time migration clears legacy cron schedules from prior installs, gated by the persistent option `dataflair_cron_cleared_v1_11`.
- Added: `WallClockBudget` primitive (H13). Sync handlers take a 25 s budget with 3 s headroom; on exhaustion they return `partial: true` and the admin JS re-issues the same page with exponential backoff (1 s → 2 s → 4 s → cap 8 s, max 10 consecutive partials per page).
- Fixed: HTTP size caps. `api_get()` streams to a temp file with a 15 MB body cap and 12 s default timeout (H2). `download_brand_logo()` caps at 3 MB with an 8 s timeout and does a `HEAD` before `GET` to short-circuit oversized downloads (H3).
- Fixed: `unset()` + `gc_collect_cycles()` after each item inside the remaining sync batches (H4). Prevents PHP from carrying forward 200–500 MB of decoded JSON rows through an admin-triggered bulk sync.
- Fixed: casino-card render no longer fires N per-card `$wpdb->prepare` cascades (H7). The shortcode handler prefetches every card's brand row in at most three `IN(...)` queries and passes the map into `render_casino_card()`. Review-post lookup gets the same batched treatment (H8).
- Fixed: `brands` admin page paginates server-side at 50/page with a lean column projection and pulls data blobs only for the current slice (H5). Filter dropdowns now use `SELECT DISTINCT` on CSV columns instead of parsing every blob. `settings` page projects to a lean set of columns plus a `JSON_UNQUOTE(JSON_EXTRACT(...))` extract — the full JSON blob is never pulled into PHP (H6).
- Fixed: `check_database_upgrade()` short-circuits on a `dataflair_schema_ok_v{VERSION}` transient (12 h TTL, busts on version bump) (H9).
- Fixed: `clear_tracker_transients()` is now a chunked DELETE loop with `LIMIT 1000`, optionally yielding to a `WallClockBudget` (H10). Replaces the prior single unbounded DELETE.
- Fixed: plugin no longer issues `TRUNCATE TABLE` on `wp_dataflair_*` (H11). Replaced with `delete_all_paginated()`, a binlog-safe chunked DELETE helper with a hardcoded table whitelist.
- Fixed: REST `/toplists/{id}/casinos` now paginates (H12). `?per_page` default 20, max 100; `?page` 1-based. Default per-item shape is lean: `{id, name, rating, offer_text, logo_url}`. `?full=1` preserves the legacy verbose shape for the block editor. `X-WP-Total` / `X-WP-TotalPages` headers included.
- Added: new tests — `CronRemovedTest`, `SyncApiSizeCapTest`, `LogoSizeCapTest`, `WallClockBudgetTest`, `RenderBatchQueryCountTest`, `ClearTransientsChunkedTest`, `RestCasinosPaginationTest`. Full suite: 234 tests, 527 assertions.

### 1.10.8
- Fixed: critical — casino-card rendering is now fully read-only. The render chain no longer sideloads brand logos at page-view time and no longer auto-creates `review` CPT rows. These two render-time writes were the root cause of memory-exhaustion fatals on sites with a 1 GB PHP memory limit.
- Added: pre-computed `local_logo_url` and `cached_review_post_id` columns on `wp_dataflair_brands`. The render template reads them directly; sync populates `local_logo_url` and the new CLI backfills `cached_review_post_id`.
- Added: WP-CLI command `wp dataflair reconcile-reviews [--batch=500] [--dry-run]` to link existing brand rows to their published review posts. Run once after upgrade.
- Added: `do_action('dataflair_brand_logo_stored', $brand_id, $local_url, $remote_url)` hook fires when a logo is stored at sync time, so themes can react without coupling to private render internals.
- Added: regression tests `RenderIsReadOnlyTest` and `CasinoCardUsesPrecomputedLogoTest` permanently enforce the read-only render invariant.
- Changed: minimum PHP bumped to 8.1 and minimum WordPress to 6.3 to match the supported runtime on target sites.

### 1.10.7
- Fixed: "Fetch All Toplists from API" no longer returns 500 on heavy pages. Bulk list fetch dropped from `per_page=20` to `per_page=10` to stay inside the DataFlair API's ~15s serializer budget (verified across all 18 pages / 175 toplists, heaviest page ~13.6s).
- Improved: per-ID fallback splitter rewritten for speed — skips the redundant `per_page=10` retry level, uses no-retry 15s-timeout slices, and a 30s hard deadline on the whole fallback. Previous version could stall ~3 minutes on a failing page; worst case is now ~25-30s.
- Added: `skipped` response shape so one unrecoverable page no longer halts the whole sync. The admin UI advances past skipped pages and reports them in the final summary.
- Added: retry with exponential backoff (1s, 2s) on transient `WP_Error` and 5xx responses in `api_get()`, with configurable `timeout` and `max_retries` arguments.

### 1.10.6
- Improved: Redesigned the "Synced Toplists" admin table for a cleaner look with a wider Name column, removing redundant Slug, Locked, Sync Health, and Shortcode columns.
- Improved: Optimized the "Fetch All Toplists from API" process by batching requests with full item inclusion, dramatically reducing sync time and preventing API timeouts.

### 1.10.5
- Fixed: pinned Composer platform requirement to PHP 8.3.16 so that legacy deployment scripts running `composer install` without `--no-dev` do not fail resolving PHP 8.4-only development dependencies like `doctrine/instantiator` 2.1.0

### 1.10.4
- Added: Composer convenience scripts `install-prod` and `install-dev` in `composer.json` for explicit production vs development dependency installs
- Improved: `composer.json` now defaults to `dist` installs with optimized autoloading to reduce deployment variance
- Fixed: production install guidance now enforces `--no-dev` to prevent PHPUnit/dev dependencies from being installed on PHP 8.3 servers

### 1.10.3
- Fixed: release packaging now uses production-only Composer dependencies (no `--dev`) so PHPUnit and other dev packages are not shipped to production servers
- Improved: `vendor/composer` metadata regenerated in no-dev mode for PHP 8.3 compatibility on production installs

### 1.10.2
- Added: Gutenberg layout option "Accordion Tables (Testing)" that renders each brand as an accordion with two compact data tables to avoid horizontal scrolling during QA
- Fixed: card layout now resolves page-level block pros/cons overrides with stable and legacy key formats, matching table layout behavior
- Improved: card details now surface the resolved Pros and Cons list so page-level overrides are visible during testing

### 1.10.1
- Fixed: Gutenberg pros and cons overrides now use stable brand and item identifiers from synced toplist data instead of position-only keys, preventing custom copy from drifting after reorder or refresh
- Improved: synced casino items now carry `itemId` and `brandId` values in API v2 parsing to support reliable editor override matching
- Added: PHPUnit coverage for both `data.items` and `data.listItems` payloads to verify `itemId` and `brandId` mapping

### 1.10.0
- Fixed: Read Review link on casino cards only appears when a published review exists or a manual review URL override is set (hidden for draft-only reviews)
- Fixed: review CPT resolution finds published posts by `_review_brand_id` when the WordPress slug differs from the API brand slug; a plugin-created draft at the base slug no longer hides the live published review (for example `…-india`)
- Improved: new auto-created review drafts store `_review_brand_id` from `api_brand_id` when `id` is absent
- Added: E2E test `tests/e2e/test-read-review-link.php` (draft vs published, slug mismatch, draft + published shadow case)

### 1.9.9
- Added: `Brand::whereJson()` query helper for JSON field filtering in the Brand model
- Added: brands table migration for `external_id_virtual` generated column with `idx_external_id_virtual` index for faster externalId lookups
- Improved: whereJson SQL builder now supports JSON path/value comparisons for `data.externalId` style queries

### 1.9.8
- Fixed: `ProductTypeLabels` and `DataIntegrityChecker` now use the `DataFlair\\Toplists\\Models` namespace for Composer PSR-4 compliance
- Improved: added backward compatibility aliases so legacy global class references continue to work

### 1.9.7
- Fixed: restored Composer PSR-4 autoload mapping for DataFlair model classes in `composer.json`
- Improved: regenerated Composer autoload metadata to include `DataFlair\\Toplists\\Models` namespace resolution

### 1.9.6
- Fixed: Gutenberg Pros and Cons inspector copy now references review CPT defaults instead of API-default replacement wording
- Added: regression test for review pros fallback that ignores draft slug matches and prefers published `_review_brand_id` matches
- Added: integration coverage to verify `ExternalId` is preserved in stored toplist and brand JSON payloads

### 1.9.4
- Fixed: casino override key generation now sanitizes brand names to match Gutenberg editor brandSlug behavior
- Improved: pros and cons overrides now resolve reliably for brands whose API slugs contain special characters (for example, dots)

### 1.9.3
- Added: E2E test suite — brand sync (9 assertions), toplist sync (16 assertions), cron jobs (14 assertions)
- Added: `run.sh` test orchestrator with auto-detection for Docker/wp-env, production WP-CLI, and CI environments
- Added: `BrandModelTest.php` — 100% coverage for Brand model
- Added: `ProductTypeLabelsTest.php` — 100% coverage for ProductTypeLabels
- Fixed: constant definitions wrapped in `if(!defined())` guards to prevent fatal redefinition on test bootstrap
- Fixed: deprecated `ReflectionProperty::setAccessible(true)` calls removed from ToplistModelTest (PHP 8.5)
- Improved: `@codeCoverageIgnore` added to admin HTML methods and casino card renderer
- Improved: `vendor/` cleaned to production-only dependencies (dev packages removed)

### 1.9.1
- Added: View Details popup on plugins.php shows full description and changelog
- Added: CLAUDE.md with release checklist and permanent rsync rule
- Updated: README.md fully rewritten with complete feature documentation

### 1.9.0
- Added: automatic plugin updates via GitHub releases (plugin-update-checker v5.6)
- Added: promo code display with copy-to-clipboard button on toplist casino cards
- Added: per-brand review URL override in DataFlair, Brands admin
- Fixed: `global $wpdb` missing in card renderer, review URL override was silently failing
- Fixed: draft/preview post URLs replaced with clean `/reviews/{slug}/` fallback
- Fixed: undefined `$product_type` and `$labels` PHP warnings in card template

### 1.8.1
- Added: review URL input field in Brands table, locks after save, unlocks on Edit

### 1.8.0
- Added: review URL override column in brands table
- Added: Composer autoload infrastructure

### 1.7.0
- Added: DataFlair API v2 brands sync
- Added: compare preview before overwriting synced data
- Fixed: block REST API endpoint

### 1.6.0
- Added: editions support for geo-market toplists
- Fixed: critical sync failure on missing table

### 1.5.0
- Added: snapshot support, data integrity checker, API preview tab, toplist slug column

### 1.4.0
- Added: `/api/v1/brands` endpoint support
- Added: 8 new brand fields (paymentMethods, currencies, gameTypes, gameProviders, languages, restrictedCountries)
- Fixed: self-healing cron

---

## License

GPL v2 or later

**Version:** 2.1.8 | **Requires WordPress:** 6.3+ | **Requires PHP:** 8.1+ | **Tested up to:** 6.9
