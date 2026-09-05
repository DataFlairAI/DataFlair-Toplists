# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

> **Note:** Live 2.x release notes also live in `README.md` (Changelog) and the `plugins_api` block in `src/Admin/PluginInfoFilter.php`. Keep those in sync when cutting a release.

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

