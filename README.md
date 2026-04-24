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

**Version:** 1.12.1 | **Requires WordPress:** 6.3+ | **Requires PHP:** 8.1+ | **Tested up to:** 6.9
