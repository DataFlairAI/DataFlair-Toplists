# DataFlair Toplists WordPress Plugin

WordPress plugin to fetch and display casino toplists and brands from the DataFlair API, with full sync management, geo-aware rendering, Gutenberg block support, and a comprehensive test suite.

---

## 📋 Features

- **Toplists Management** — Fetch, store, and display casino toplists from the DataFlair API
- **Brands Management** — Sync active brands with full relationship data (payments, currencies, games, languages, geos)
- **Alternative Toplists** — Geo-specific fallback toplists for better regional conversion
- **Logo Caching** — Logos downloaded and stored locally to reduce API calls
- **Gutenberg Block** — `dataflair-toplists/toplist` block with full visual customisation panel
- **Shortcode Support** — `[dataflair_toplist id="X"]` works everywhere
- **Self-Healing Cron** — Brands sync every 15 min, toplists every 2 days; cron schedule auto-repairs if broken
- **Comprehensive Test Suite** — 6 test modules runnable directly from the admin panel
- **Advanced Filtering & Sorting** — Filter brands by license, geo, payment method; sort by name/offers/trackers
- **Accordion UI** — Expandable brand rows showing full relationship details

---

## 🔌 API Endpoints Used

| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET` | `/api/v1/toplists` | List of toplists for the site |
| `GET` | `/api/v1/toplists/{id}` | Single toplist with items |
| `GET` | `/api/v1/brands` | Paginated active brands with all relationships |

All endpoints require a `dfp_` Plugin Token as a Bearer header. See **Configuration** below.

### Brand API Response Shape

The `/api/v1/brands` endpoint returns all active brands with the following fields:

```json
{
  "id": 1,
  "name": "Casino Name",
  "slug": "casino-name",
  "rating": 4.5,
  "brandStatus": "Active",
  "brandType": "Casino",
  "logo": { "rectangular": "https://...", "square": "https://...", "backgroundColor": "#fff" },
  "productTypes": ["Casino", "Live Casino"],
  "licenses": ["MGA", "UKGC"],
  "paymentMethods": ["VISA", "Mastercard", "Skrill"],
  "currencies": ["EUR", "GBP", "USD"],
  "gameTypes": ["Slots", "Live Casino", "Table Games"],
  "gameProviders": ["NetEnt", "Evolution"],
  "languages": {
    "website": ["English", "German"],
    "support": ["English"],
    "livechat": ["English", "German"]
  },
  "topGeos": { "countries": ["United Kingdom"], "markets": ["Western Europe"] },
  "restrictedCountries": ["United States", "France"],
  "offers": [
    {
      "id": 10,
      "offerText": "100% up to €200",
      "trackers": [{ "id": 5, "trackerLink": "https://go.example.com/..." }]
    }
  ]
}
```

---

## 🚀 Installation

### Via Git

```bash
cd wp-content/plugins/
git clone git@github.com:DataFlairAI/DataFlair-Toplists.git
```

Activate the plugin in **WordPress Admin → Plugins**.

### Manual Upload

Upload the plugin folder to `wp-content/plugins/dataflair-toplists/` and activate.

### Build Assets (Gutenberg Block)

```bash
npm install
npm run build
```

---

## ⚙️ Configuration

### 1. Generate a Plugin Token

In your DataFlair tenant, generate a **Plugin Token** (`dfp_…`). This is different from an API key — it is scoped to a single WordPress site.

> **Via Tinker (dev/staging):**
> ```bash
> # Find the tenant (domain stored as short slug, e.g. "sigma")
> $tenant = \App\Models\Tenant::whereHas('domains', fn($q) => $q->where('domain', 'sigma'))->first();
> tenancy()->initialize($tenant);
> $cred = \App\Models\Tenant\API\SiteApiCredential::first();
> $plain = \App\Models\Tenant\API\SiteApiCredential::generatePlainToken();
> $cred->plain_token_hash = \App\Models\Tenant\API\SiteApiCredential::hashPlainToken($plain);
> $cred->save();
> echo 'New token: ' . $plain;
> ```

### 2. Enter Settings in WordPress

Go to **DataFlair → Settings**:

| Field | Example | Notes |
|-------|---------|-------|
| API Bearer Token | `dfp_test_abc123…` | Must start with `dfp_` |
| API Base URL | `https://sigma.dataflair.ai/api/v1` | No trailing slash |
| API Endpoints | `https://sigma.dataflair.ai/api/v1/toplists/3` | One per line |

### 3. Fetch Data

- Click **Fetch All Toplists** to pull toplists into the DB
- Click **Sync All Brands** to pull brands (or wait for the 15-minute cron)

---

## 📝 Shortcode

```
[dataflair_toplist id="3"]
[dataflair_toplist id="3" title="Best UK Casinos"]
[dataflair_toplist id="3" title="Top 5" limit="5"]
```

| Attribute | Required | Description |
|-----------|----------|-------------|
| `id` | ✅ | DataFlair API toplist ID |
| `title` | ❌ | Overrides toplist name |
| `limit` | ❌ | Max number of casino cards to render |

---

## 🧱 Gutenberg Block

Use the **DataFlair Toplist** block in the block editor. Select a toplist from the dropdown (populated from synced toplists via the REST API) and customise colours, CTA text, ribbons, and ranking badges directly in the block sidebar.

The block renders using the same `toplist_shortcode()` function — output is identical to the shortcode.

---

## 🔄 Data Synchronisation

### Cron Schedule

| Job | Hook | Frequency | What it does |
|-----|------|-----------|-------------|
| Toplists | `dataflair_sync_cron` | Twice daily | Re-fetches all configured toplist endpoints |
| Brands | `dataflair_brands_sync_cron` | Every 15 min | Pulls active brands from `/api/v1/brands` |

> **Note:** WordPress pseudo-cron fires on page load. For reliable scheduling in production, add a real server cron:
> ```bash
> */5 * * * * curl -s https://yoursite.com/wp-cron.php?doing_wp_cron > /dev/null
> ```

### Self-Healing Cron

The `dataflair_15min` custom schedule is registered via the `cron_schedules` filter. Plugin activation fires before filters run, which caused the brands cron to be stored with an unknown interval and never fire.

The plugin now runs `ensure_cron_scheduled()` on every `init` — when filters are already active. It inspects the stored cron array and automatically reschedules the brands cron with the correct interval if it detects the broken state.

### Stale Data Warning

If a toplist hasn't been synced in more than 3 days, a banner appears above the toplist:
```
⚠️ This data was last updated on Mar 01, 2026. Using cached version.
```

---

## 🌍 Alternative Toplists

Set geo-specific fallback toplists for better regional conversion.

**Setup:** Go to **DataFlair → Toplists**, expand a toplist row, select a geo and an alternative toplist, and click **Add Alternative**.

**How it works:** When a user visits a page with toplist #1 and the plugin detects they are from Canada, it automatically displays the toplist configured as the Canada alternative instead.

---

## 🗄️ Database Tables

| Table | Purpose |
|-------|---------|
| `wp_dataflair_toplists` | Toplist JSON blobs from API |
| `wp_dataflair_brands` | Brand relationship data synced from `/api/v1/brands` |
| `wp_dataflair_alternative_toplists` | Geo → alternative toplist mappings |

---

## 🧪 Test Suite

Tests are accessible directly in the admin panel at **DataFlair → Tests**. No WP-CLI or server access required.

### Available Tests

| Button | File | What it covers |
|--------|------|----------------|
| Run All Tests | `run-all-tests.php` | Runs all test files in sequence |
| Logo Download Test | `test-logo-download.php` | Logo URL resolution and local caching |
| Brand Data Test | `test-brand-data.php` | All 14+ brand API fields, false-positive check (inactive brands excluded), pagination meta, empty arrays not null, alphabetical order |
| Toplist Fetch Test | `test-toplist-fetch.php` | Toplist API fetch and DB storage |
| Toplist Render Test | `test-toplist-render.php` | Shortcode (valid/invalid/missing ID), limit, custom title, stale data warning, geo edge cases (country/market/global null), Gutenberg block delegation, REST endpoints for block editor, casino card rendering |
| API Edge Cases Test | `test-api-edge-cases.php` | Auth failures (no token, wrong token, garbage, dfp_ wrong value), Content-Type header, null fields, per_page cap at 100, invalid per_page values, 404 for non-existent toplist, connection error handling |
| Cron Jobs Test | `test-cron.php` | Custom schedule registered, both hooks scheduled, next run timing, manually fires both crons and verifies DB `last_synced` updates, graceful skip with no token |

### Running via WP-CLI

```bash
docker exec <wordpress-container> wp eval-file wp-content/plugins/dataflair-toplists/tests/run-all-tests.php --allow-root
```

---

## 📁 File Structure

```
dataflair-toplists/
├── dataflair-toplists.php      Main plugin file (v1.4.0)
├── assets/
│   ├── admin.js                Admin panel JavaScript
│   └── style.css               Frontend casino card styles
├── build/                      Compiled Gutenberg block assets
├── includes/
│   └── render-casino-card.php  Casino card HTML template
├── src/                        Gutenberg block source (JS/JSX)
├── tests/
│   ├── run-all-tests.php
│   ├── test-logo-download.php
│   ├── test-brand-data.php
│   ├── test-toplist-fetch.php
│   ├── test-toplist-render.php
│   ├── test-api-edge-cases.php
│   └── test-cron.php
├── uninstall.php
└── README.md
```

---

## 🐛 Debugging

### Common Errors

| Error | Cause | Fix |
|-------|-------|-----|
| `Toplist ID is required` | Missing `id` in shortcode | Use `[dataflair_toplist id="3"]` |
| `Toplist ID X not found` | Not synced to DB | Run Fetch All Toplists |
| `Invalid toplist data` | Corrupted JSON in DB | Re-sync the toplist |
| `401 Unauthenticated` | Token invalid/expired | Regenerate token via Tinker (see Configuration) |
| Brands cron stuck | Activation-time schedule bug | Fixed in v1.4.0 — reload any page to self-heal |

### WordPress Debug Log

```php
// wp-config.php
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
define('WP_DEBUG_DISPLAY', false);
// Logs at: wp-content/debug.log
```

---

## 🔐 Security

- Nonce verification on all AJAX actions
- `manage_options` capability check on all admin routes
- SQL injection prevention via `$wpdb->prepare()`
- XSS protection via `esc_html()` / `esc_attr()` / `esc_url()`
- Plugin Token (`dfp_`) scoped to a single site — tenancy is the isolation boundary
- Direct file execution blocked via `ABSPATH` check

---

## 📦 Releases

Follows [Semantic Versioning](https://semver.org/).

| Version | Highlights |
|---------|-----------|
| **v1.4.0** | `/api/v1/brands` endpoint support; 8 new brand fields (paymentMethods, currencies, gameTypes, gameProviders, languages, restrictedCountries); self-healing cron fix; 3 new test modules (toplist-render, api-edge-cases, cron); HTTP auth fields removed from settings |
| v1.3.3 | Toplist template filter, column sorting, admin polish |
| v1.3.x | Admin UI improvements, filter performance, cron timestamps |
| v1.0.6 | Logo download and caching, initial test suite |

To release a new version:
```bash
# 1. Bump version in dataflair-toplists.php (header + DATAFLAIR_VERSION constant)
# 2. Update README version table
git add -A
git commit -m "release: v1.x.x — short description"
git tag v1.x.x
git push origin main --tags
```

---

## 🛠️ Development

### Requirements
- PHP 8.0+
- WordPress 6.0+
- Node.js 18+ & npm (for Gutenberg block)

### Setup

```bash
npm install
npm run build        # production build
npm run start        # watch mode
```

---

## 📄 License

GPL v2 or later

---

**Version:** 1.4.0 | **Requires WordPress:** 6.0+ | **Requires PHP:** 8.0+ | **Tested up to:** 6.7
