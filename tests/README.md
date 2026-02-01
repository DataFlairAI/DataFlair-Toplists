# Dataflair Toplists Plugin - Test Suite

This directory contains test files for the Dataflair Toplists plugin.

## Available Tests

### 1. `test-logo-download.php`
Tests logo download functionality:
- Checks if theme logo download function exists
- Verifies logos directory exists and is writable
- Fetches a test brand from database
- Extracts logo URL from brand data
- Downloads and saves logo locally
- Validates logo URLs

**How to run:**
- Via browser: `http://yoursite.com/wp-content/plugins/dataflair-toplists/tests/test-logo-download.php?run_test=1`
- Via WP-CLI: `wp eval-file wp-content/plugins/dataflair-toplists/tests/test-logo-download.php`

### 2. `test-brand-data.php`
Tests brand data extraction:
- Fetches a test brand from database
- Tests all data points (name, logo, URL, rating, licenses, payment methods)
- Tests offers data (bonus text, wagering, min deposit, free spins, expiry, bonus code, trackers)
- Validates nested data structures
- Provides summary of found/missing fields

**How to run:**
- Via browser: `http://yoursite.com/wp-content/plugins/dataflair-toplists/tests/test-brand-data.php?run_test=1`
- Via WP-CLI: `wp eval-file wp-content/plugins/dataflair-toplists/tests/test-brand-data.php`

### 3. `test-toplist-fetch.php`
Tests toplist fetching and rendering:
- Checks database table existence
- Fetches a toplist from database
- Parses toplist JSON data
- Analyzes toplist items
- Tests shortcode rendering
- Tests logo downloads for toplist items

**How to run:**
- Via browser: `http://yoursite.com/wp-content/plugins/dataflair-toplists/tests/test-toplist-fetch.php?run_test=1`
- Via WP-CLI: `wp eval-file wp-content/plugins/dataflair-toplists/tests/test-toplist-fetch.php`

## Running All Tests

You can run all tests at once using:

```bash
# Via WP-CLI
wp eval-file wp-content/plugins/dataflair-toplists/tests/test-logo-download.php
wp eval-file wp-content/plugins/dataflair-toplists/tests/test-brand-data.php
wp eval-file wp-content/plugins/dataflair-toplists/tests/test-toplist-fetch.php
```

Or create a test runner script (see `run-all-tests.php`).

## Recommended Additional Tests

### 4. Performance Tests
- Test logo download performance (time taken)
- Test toplist rendering performance
- Test database query performance
- Memory usage tests

### 5. Integration Tests
- Test shortcode with various attributes
- Test Gutenberg block rendering
- Test REST API endpoints
- Test admin AJAX handlers

### 6. Data Validation Tests
- Test data sanitization
- Test URL validation
- Test JSON parsing error handling
- Test missing data fallbacks

### 7. Cache Tests
- Test logo caching (don't re-download existing logos)
- Test toplist data caching
- Test transient storage

### 8. Error Handling Tests
- Test invalid brand IDs
- Test invalid toplist IDs
- Test network failures
- Test file permission errors
- Test invalid JSON data

### 9. Security Tests
- Test nonce validation
- Test user capability checks
- Test SQL injection prevention
- Test XSS prevention in output

### 10. API Tests
- Test API connection
- Test API response parsing
- Test API error handling
- Test rate limiting

## Test Environment Requirements

- WordPress installed and configured
- Dataflair plugin activated
- At least one brand synced in database
- At least one toplist synced in database
- Theme with `strikeodds_download_and_save_logo()` function available

## Notes

- Tests output HTML for browser viewing
- Tests can also be run via WP-CLI
- Tests use WordPress database and functions
- Tests check for required dependencies before running
- Tests provide detailed output with pass/fail indicators
