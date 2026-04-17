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

- WordPress 5.8+
- PHP 7.4+
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

- PHP 7.4+
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
│   ├── render-casino-card.php      Casino card HTML template
│   ├── ProductTypeLabels.php       Label map for product types
│   └── DataIntegrityChecker.php    Validates API response structure
├── src/                            Gutenberg block source (JS/JSX)
├── tests/
│   └── phpunit/                    PHPUnit test suite
├── docs/
│   └── plans/                      Parked feature plans
└── README.md
```

---

## Changelog

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

**Version:** 1.10.6 | **Requires WordPress:** 5.8+ | **Requires PHP:** 7.4+ | **Tested up to:** 6.9
