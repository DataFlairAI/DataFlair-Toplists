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
- Renders fully styled casino cards showing: brand logo, name, star rating, bonus offer text, promo code copy button, feature list, affiliate CTA, and Read Review link
- Promo codes render as a pill-shaped copy-to-clipboard button, matching the design of standalone review pages
- Review URL resolution priority: manual override, published review post permalink, auto-generated `/reviews/{slug}/`, affiliate CTA link
- Supports multiple product types (casino, sportsbook, poker) with type-aware labels

### Gutenberg Block & Shortcode
- Native WordPress block with inspector controls for toplist selection, item limit, and display options
- Server-side rendered, always reflects live synced data
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

82 tests covering sync logic, brand management, REST API, and auto-update wiring.

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

**Version:** 1.9.4 | **Requires WordPress:** 5.8+ | **Requires PHP:** 7.4+ | **Tested up to:** 6.9
