# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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

[1.2.0]: https://github.com/dataflair/toplists/releases/tag/v1.2.0
[1.1.0]: https://github.com/dataflair/toplists/releases/tag/v1.1.0
[1.0.0]: https://github.com/dataflair/toplists/releases/tag/v1.0.0

