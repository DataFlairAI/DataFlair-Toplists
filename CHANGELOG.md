# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

> **Note:** Live 2.x release notes also live in `README.md` (Changelog) and the `plugins_api` block in `src/Admin/PluginInfoFilter.php`. Keep those in sync when cutting a release.

## [2.4.1] - 2026-09-16

### Fixed
- **Fatal error rendering the toplist block/shortcode on Roots/Acorn (Sage-based) themes.** The Alpine.js already-loaded detection called `strpos()` directly on every queued script's `->src`, assuming it is always a plain string. Acorn-based themes register compiled assets with `->src` as an asset value object instead of a string; `strpos()` threw a `TypeError` that the theme's Blade layer turned into a fatal error on every page rendering the block or shortcode — effectively the whole toplist feature on such a theme (`AlpineJsEnqueuer`). Found live during QA on a Roots/Acorn client site; reproduced in an isolated regression test (same `TypeError`, no live site needed) before fixing. Fix coerces via `__toString()` when the object supports it and skips the entry otherwise, instead of fataling.

### Tests
- Two new cases pin both the non-Stringable-safe-skip and the Stringable-object-still-detected paths. Full suite: 968 tests green.

## [2.4.0] - 2026-09-15

### Added
- **Webhook sync.** DataFlair pushes toplist and brand changes to the site the moment they happen, instead of waiting for the next scheduled sync. A new "Enable webhook sync" checkbox on Settings › API Connection self-registers the site with DataFlair automatically (reusing the existing API token, no separate credential to manage) and shows live status underneath the checkbox: receiving, no activity yet, or a rejected-delivery reason. Deliveries are HMAC-SHA256 signed against a per-site secret the plugin generates once on first enable and never regenerates on later saves. New route: `POST /wp-json/dataflair/v1/webhooks` — idempotent (a retried or duplicate delivery is a safe no-op via a new `wp_dataflair_webhook_events` ledger table) and tenant-scoped (rejects a delivery meant for a different DataFlair tenant). `toplist.published` re-fetches just that one toplist; `brand.status_changed`/`brand.updated` re-fetches just that one brand and updates its local active/disabled state to match.

### Fixed
Found during the pre-release max-effort review, before this reached any real site:
- **Saving Settings from any tab other than API Connection no longer silently disables webhook sync.** The webhook checkbox only exists in that tab's markup; the shared save handler used by every tab's Save button now only touches the webhook option when the request actually came from the tab that owns it (`SaveSettingsHandler`, `assets/admin.js`).
- **Closed a replay-protection gap on the webhook receiver.** Delivery freshness is now checked against the timestamp inside the signed payload, not an HTTP header that was never part of the signature — a captured delivery could previously be replayed by attaching a freshly forged header (`WebhookController`).
- **The webhook tenant-isolation check now fails closed** instead of silently skipping the check when the site's own configured API base URL can't be resolved to a host (`WebhookController`).
- **A failed webhook idempotency-ledger write is now logged** instead of silently discarded (`WebhookController`).
- Local/Docker debug logging no longer mislabels a webhook self-registration call as a plain API fetch (`ApiClient`).

Found only by a live round-trip against a real WordPress install (an actual DataFlair backend delivering a real signed webhook), not by the mocked test suite — same root cause as the 2.3.3 `TestsRunner` incident below:
- **Webhook self-registration returned a 419 for every real caller.** `POST /api/v1/webhooks/subscribe` (the DataFlair backend side) was never added to the CSRF exemption list, so a plugin calling it over plain HTTP with no Laravel session always failed. Fixed on the DataFlair side; nothing to update here, but self-registration from this version now completes successfully against a patched backend.
- **The whole REST API (toplists, casinos, health, webhooks — not just the new route) fatally errored on every request.** `rest_bootstrap()` wired the webhook receiver's toplist-persistence dependency to `ToplistFetcher` (the paginated batch-sync class) instead of the `ToplistPersisterInterface` adapter `RestBootstrap` actually requires — a `TypeError` on every `rest_api_init`. Both classes happen to share a `fetchAndStore()` method name, which is how the mismatch slipped through review.

### Tests
- New coverage for the webhook receiver (signature verification, idempotency, tenant guard, event routing, replay rejection), the self-registration flow, and the settings save-isolation fix. Full suite: 936 tests green.
- The two live-only findings above are structurally invisible to this plugin's mocked PHPUnit suite (per `ShimForwardingTest`'s own docblock, the 5,600-line god class is deliberately never loaded in tests) and to the DataFlair backend's feature tests (Laravel's CSRF middleware unconditionally no-ops while running tests). Both were caught, and re-verified as fixed, only by an actual delivery from a running DataFlair instance to a running WordPress site.

## [2.3.3] - 2026-09-10

### Fixed
- **Fatal error on Tools › Tests & Diagnostics** (`ArgumentCountError` in `TestsRunner::__construct()`), found by a live WordPress smoke test after merge, not by the 874 mocked unit tests: `ToolsPage::renderTestsTab()` built its own `new TestsRunner()` with zero arguments, missed when `TestsRunner`'s constructor gained a required `ApiBaseUrlDetector` parameter elsewhere in this release. Verified fixed under a real WordPress 7.1 install (Docker), every admin page this release touches (Settings, Tools × all 3 tabs, Dashboard, Brands, Toplists), logged in, clicking through. `Tested up to` updated to 7.1 accordingly.
- Settings › API Connection no longer implies brand sync uses v1 while V2 is selected. The tab echoed the stored base URL verbatim as "Current", so it read `/api/v1` even though `BrandsApiUrlBuilder` rewrites the version at sync time (Sigma read this as a broken sync during their first integration pass). That line is gone (the field already shows the saved value); the Brands API Version row now states the exact URL brand sync calls with the saved settings, via `BrandsApiUrlBuilder::effectiveBase()`, which now rewrites symmetrically in both directions so the radio is authoritative even if the stored URL was set manually. Test Connection is labelled as hitting the toplists endpoint (always v1), and now actually enforces v1 instead of trusting the raw stored value.
- **Brand and toplist sync buttons now refuse to run when the API Base URL isn't configured**, instead of falling through to `ApiBaseUrlDetector`'s hard-coded fallback host with a real bearer token. Found in a second max-review pass: the "nothing is configured" copy above was true for the label but not for the buttons underneath it.
- The admin API preview's forced-V2 rewrite (`brands_v2`, `brands/custom`) is restored for base URLs without a literal `/api/` segment, via a new `UrlTransformer::forceApiVersion()` used only by that tool. It was lost when the rewrite was first consolidated onto the conservative `withApiVersion()` brand sync needs.
- The 404 error message no longer shows a stale "Currently configured" URL alongside the real one that just failed. It only cites the URL that was actually called.
- The Dashboard health tile, Tools diagnostics, and Test Connection now all agree with Settings about whether the API is configured (via `ApiBaseUrlDetector::isConfigured()`, which also checks the endpoints-cache tier), instead of each hand-rolling a check that only looked at the base-URL option.
- `Admin\Pages\Tools\ToolsPage`'s API Preview tab no longer writes an option on a plain page load. It was the same "GET must not persist" bug this release fixed in Settings, left live on a sibling page via an unused variable.

### Changed
- The `/api/vN` rewrite has one owner, `UrlTransformer::withApiVersion()`, shared by brand sync and (via the new `forceApiVersion()`) the admin API preview. `withApiVersion()` rewrites the `/api/vN` form only, so brand-sync traffic is unchanged for URLs it doesn't recognise; `forceApiVersion()` additionally falls back to a bare `/vN` for the preview tool, whose job is guaranteeing a version.
- `ApiBaseUrlDetector::detect()` accepts `$persist = false`; Settings uses it so a plain GET never writes the cache-back option. `ApiBaseUrlDetector::isConfigured()` decides when nothing is configured, checking both the base-URL option and the endpoints cache, and is now shared by Settings, the sync-trigger guards, the Dashboard tile, Tools diagnostics, and Test Connection.
- `SettingsPage` takes a single closure; the two it never called were removed.

### Tests
- `BrandsApiUrlBuilderTest`: `effectiveBase()` for v2 with a stored v1 URL and the reverse downgrade, both mutation-verified. `ApiBaseUrlDetectorTest`: `detect(false)` never calls `update_option` (mutation-verified); four `isConfigured()` cases. `UrlTransformerTest`: four `withApiVersion()` cases plus three `forceApiVersion()` cases (mutation-verified). New `FetchAllBrandsHandlerTest`, `FetchAllToplistsHandlerTest`, `BulkResyncToplistsHandlerTest`; `BulkResyncBrandsHandlerTest` extended. Each pins the not-configured guard, mutation-verified on the brands handler and proven by a sync-service stub that throws if called, on the toplists handler.

## [2.3.2] - 2026-09-05

### Fixed
- Block-level pros/cons overrides no longer vanish after a toplist reorder. Legacy Gutenberg keys (`casino-{position}-{slug}`) are resolved at any position for the brand on the frontend, and the block editor auto-migrates them to stable brand/item/slug keys when casinos load. The toplist id is unchanged across reorder versions; only ranks move.

### Tests
- `ProsConsResolverDriftTest`: reorder survival, stable-key precedence over legacy keys, sanitized brand-name slug matching.

## [2.3.1] - 2026-09-05

### Fixed
- Gutenberg ServerSideRender preview in the WP 6.3+ iframed block canvas: enqueue `editor.css` via `enqueue_block_assets` (admin-only) so styles reach the editor iframe. Previously `enqueue_block_editor_assets` left `editor.css` in the parent chrome only, which produced a huge ribbon SVG and a broken “OUR TOP CHOICE” layout in the block editor.
- Ribbon star containment in `assets/editor.css`: `.ribbon-star` / `svg.ribbon-star` capped with `max-width` / ~18px sizing so admin CSS resets cannot blow out the SVG.

### Changed
- Related implementation and tests: `src/Block/BlockRegistrar.php`, `src/Block/EditorAssets.php`, `assets/editor.css`, `tests/phpunit/Unit/BlockRegistrarTest.php`, `tests/phpunit/Unit/EditorAssetsTest.php`, `tests/phpunit/Unit/BlockTestStubs.php`.

### Notes
- **Deploy:** Production deploys must use `composer install --no-dev` or `composer run install-prod`. Never ship a partial or development Composer `vendor/` tree — a broken vendor that still required mockery caused a production critical error (unrelated to the editor CSS fix itself).

## [1.3.0] - 2024-12-XX

### Added
- Logo download and local caching functionality
- Automatic logo download when rendering toplists
- Logo storage in theme's `assets/logos/` directory
- Test suite for logo downloads, brand data, and toplist fetching
- Test admin page (DataFlair → Tests) for running tests
- Logo URL structure verification tests
- API-based brand data testing

### Changed
- Toplist rendering now downloads logos locally before display
- Reduced API calls by caching logos locally
- Improved logo URL handling in casino card rendering

### Technical
- Added `strikeodds_download_and_save_logo()` function integration
- Enhanced `render_casino_card()` to download logos automatically
- Created comprehensive test suite in `tests/` directory

## [1.2.0] - 2024-11-25

### Added
- Alternative toplists feature for geo-specific content delivery
- Accordion UI for toplists management page
- Geo selector populated from toplist data
- Automatic database table creation on first use
- Alternative toplist mappings with CRUD operations
- Debug logging for troubleshooting
- Composer package support
- Enhanced README with composer installation instructions
- CHANGELOG.md for version tracking

### Changed
- Updated database schema to version 1.2
- Improved admin.js to support toplist accordions
- Enhanced error handling in AJAX operations
- Improved filter and pagination functionality

### Fixed
- JavaScript error with undefined `filterBrands` function
- Database table creation issues
- Sorting pagination behavior

## [1.1.0] - 2024-11-20

### Added
- Brands management functionality
- Brand syncing from DataFlair API (every 15 minutes)
- Batch synchronization with progress indicator
- Brand accordion with detailed information
- Advanced filtering system (licenses, geos, payment methods)
- Multiselect dropdowns with search functionality
- Sorting by brand name, offers, and trackers count
- Pagination (50 brands per page)
- Offer details display with all relevant fields
- Customer support and language information
- Hover tooltips for truncated data
- Horizontal separators between brand rows

### Changed
- Database schema upgraded to support brands
- Admin interface enhanced for brands page
- Menu structure: DataFlair main menu with Toplists and Brands submenus

### Fixed
- Brand name sorting issues
- Database column compatibility
- Filter layout on large screens

## [1.0.0] - 2024-11-01

### Added
- Initial plugin release
- Toplists fetching from DataFlair API
- Custom database table for toplists storage
- Admin settings page with API token configuration
- Manual and automatic toplist synchronization (every 2 days)
- Shortcode support `[dataflair_toplist]`
- Gutenberg block for toplists
- Frontend styling with modern design
- Customization options (ribbon colors, CTA colors)
- REST API endpoints
- Alpine.js integration for interactive features
- Casino card rendering with:
  - Position badges
  - Logo display
  - License information
  - Bonus details
  - Wagering requirements
  - Minimum deposit
  - CTA buttons
  - Pros/cons expandable section

### Technical
- WordPress 5.0+ compatibility
- PHP 7.4+ requirement
- Custom cron schedules
- Database upgrade system
- Activation/deactivation hooks
- Uninstall cleanup script

[2.3.2]: https://github.com/DataFlairAI/DataFlair-Toplists/releases/tag/v2.3.2
[2.3.1]: https://github.com/DataFlairAI/DataFlair-Toplists/releases/tag/v2.3.1
[1.2.0]: https://github.com/dataflair/toplists/releases/tag/v1.2.0
[1.1.0]: https://github.com/dataflair/toplists/releases/tag/v1.1.0
[1.0.0]: https://github.com/dataflair/toplists/releases/tag/v1.0.0

