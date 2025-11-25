# DataFlair Toplists WordPress Plugin

WordPress plugin to fetch and display casino toplists and brands from DataFlair API with comprehensive management features.

## 📋 Features

- **Toplists Management**: Fetch and display casino toplists from DataFlair API
- **Brands Management**: Sync and manage active brands with detailed information
- **Alternative Toplists**: Set geo-specific alternative toplists for better user experience
- **Accordion UI**: Expandable details for brands and toplists
- **Advanced Filtering**: Filter brands by licenses, geos, and payment methods
- **Sorting & Pagination**: Sort by name, offers, trackers with 50 items per page
- **API Integration**: Automatic sync with DataFlair API every 15 minutes (brands) and 2 days (toplists)
- **Custom Database Tables**: Optimized storage for toplists, brands, and alternative mappings
- **Gutenberg Block**: Display toplists using WordPress block editor
- **Shortcode Support**: Use `[dataflair_toplist]` shortcode anywhere

## 🎯 Core Features Explained

### Brands Management

The **Brands** feature provides comprehensive management of casino brands synced from the DataFlair API. This powerful tool helps you maintain an up-to-date database of all active casino brands with detailed information.

#### What is the Brands Table?

The brands table (`dataflair_brands`) is a custom database table that stores detailed information about each casino brand, including:

- **Basic Information**:
  - Brand name and slug
  - Brand status (Active/Inactive)
  - Product types (Casino, Sportsbook, etc.)
  
- **Licensing & Compliance**:
  - License information (e.g., "MGA", "UKGC", "Curaçao eGaming")
  - Restricted countries
  
- **Geographic Targeting**:
  - Top geos (countries and markets where the brand performs best)
  - Available markets for each offer
  
- **Payment Information**:
  - Supported payment methods (VISA, Mastercard, Skrill, Crypto, etc.)
  - Supported currencies
  
- **Offers & Tracking**:
  - Number of active offers
  - Number of tracking links
  - Detailed offer information including:
    - Offer type (No Deposit Bonus, Deposit Bonus, Free Spins, etc.)
    - Offer text and bonus amounts
    - Wagering requirements
    - Minimum deposit
    - Bonus codes
    - Free spins availability
    - Geographic availability per offer
  
- **Operational Details**:
  - Customer support availability and languages
  - Live chat availability and languages
  - Website available languages
  - Game providers
  - Rating information

#### How Brands Syncing Works

1. **Automatic Sync**: Every 15 minutes, the plugin automatically syncs with the DataFlair API
2. **Manual Sync**: Administrators can trigger a manual sync from the Brands page
3. **Batch Processing**: Syncs 15 brands per batch with a visual progress indicator
4. **Active Brands Only**: Only brands with `brandStatus: "Active"` are stored
5. **Smart Updates**: Existing brands are updated, new brands are added

#### Using the Brands Interface

Navigate to **DataFlair → Brands** to access:

- **Searchable Table**: View all synced brands in a paginated table (50 per page)
- **Advanced Filtering**: Filter by:
  - Licenses (e.g., show only MGA-licensed casinos)
  - Top Geos (e.g., show only brands targeting UK or Canada)
  - Payment Methods (e.g., show only brands accepting cryptocurrency)
- **Sorting Options**:
  - Brand name (A-Z or Z-A)
  - Number of offers (ascending/descending)
  - Number of trackers (ascending/descending)
- **Expandable Details**: Click the chevron icon to expand and view:
  - Payment methods
  - Currencies
  - Restricted countries
  - Game providers
  - Support languages
  - Individual offers with tracking links

#### Use Cases for Brands Table

- **Content Creation**: Find casinos matching specific criteria for content creation
- **Geo-Targeting**: Identify which brands are available in specific markets
- **Offer Management**: Track which brands have the best offers for your audience
- **Compliance**: Filter by license type for regulatory requirements
- **Performance Tracking**: Monitor number of offers and trackers per brand

### Alternative Toplists

The **Alternative Toplists** feature is designed to improve user experience by showing region-specific casino lists to visitors based on their geographic location.

#### What are Alternative Toplists?

Alternative toplists allow you to configure fallback or region-specific toplists that will be displayed to users from different geographic regions. This is particularly useful when:

- A user visits a page featuring casinos that are **not available in their region**
- You want to show **region-optimized casino lists** for better conversion rates
- You need to comply with **regional regulations** by showing only licensed casinos for specific regions

#### How Alternative Toplists Work

1. **Primary Toplist**: You have a main toplist (e.g., "Best UK Online Casinos")
2. **Geo Detection**: When a user visits your page, their geographic location is detected
3. **Smart Replacement**: If the user is from a different region (e.g., Canada), the plugin automatically shows the alternative toplist configured for that region (e.g., "Best Canadian Online Casinos")
4. **Seamless Experience**: The swap happens automatically without any user intervention

#### Setting Up Alternative Toplists

Navigate to **DataFlair → Toplists** and follow these steps:

1. **Expand Toplist Details**:
   - Click the chevron icon (▶) next to any toplist to expand its settings
   
2. **Select Geo/Market**:
   - Choose a geographic region from the dropdown (populated from your synced toplists)
   - Examples: "United Kingdom", "Canada", "Germany", "Australia"
   
3. **Choose Alternative Toplist**:
   - Select which toplist should be shown to users from that region
   - The dropdown shows all your synced toplists with their IDs
   
4. **Add Mapping**:
   - Click "Add Alternative" to save the mapping
   - The system stores this in the `dataflair_alternative_toplists` table
   
5. **Manage Existing Mappings**:
   - View all configured alternative toplists in the table
   - Delete mappings that are no longer needed

#### Alternative Toplists Database Table

The plugin creates a dedicated table (`dataflair_alternative_toplists`) to store these mappings:

- **toplist_id**: The primary toplist ID
- **geo**: The geographic region (e.g., "United Kingdom", "Canada")
- **alternative_toplist_id**: The toplist to show for that geo
- **created_at/updated_at**: Timestamps for tracking

Each primary toplist can have multiple alternative mappings (one per geo), and the system ensures no duplicate mappings for the same toplist-geo combination.

#### Use Cases for Alternative Toplists

1. **Geographic Restrictions**:
   - Primary: "Best UKGC Casinos" (UK users)
   - Alternative for Canada: "Best Canadian Casinos"
   - Alternative for Germany: "Best German-Licensed Casinos"

2. **Market Optimization**:
   - Show market-specific bonuses and offers
   - Display casinos with better conversion rates per region
   - Highlight locally popular payment methods

3. **Regulatory Compliance**:
   - Ensure users only see casinos licensed in their jurisdiction
   - Automatically comply with regional gambling regulations
   - Prevent showing restricted casinos to users in blocked regions

4. **Language & Currency**:
   - Show casinos that support the user's local language
   - Display casinos accepting the user's local currency
   - Better user experience with localized content

#### Example Configuration

```
Primary Toplist: "Top 10 UK Online Casinos" (ID: 1)
├── Alternative for "Canada" → "Top 10 Canadian Casinos" (ID: 5)
├── Alternative for "Germany" → "Top 10 German Casinos" (ID: 8)
├── Alternative for "Australia" → "Top 10 Australian Casinos" (ID: 12)
└── Alternative for "India" → "Top 10 Indian Casinos" (ID: 15)
```

When a Canadian user visits a page showing Toplist #1, they automatically see Toplist #5 instead.

## 📁 File Structure

```
dataflair-toplists/
├── dataflair-toplists.php  (Main plugin file)
├── composer.json           (Composer package configuration)
├── assets/
│   ├── admin.js           (Admin interface JavaScript)
│   └── style.css          (Frontend styles)
├── build/                 (Gutenberg block build files)
├── src/                   (Gutenberg block source)
├── uninstall.php         (Cleanup on uninstall)
└── README.md
```

## 🚀 Installation

### Method 1: Via Composer (Recommended)

If you're using Composer to manage your WordPress plugins:

```bash
# Navigate to your WordPress installation
cd /path/to/wordpress

# Install the plugin
composer require dataflair/toplists
```

Or add to your `composer.json`:

```json
{
    "require": {
        "dataflair/toplists": "^1.2"
    }
}
```

Then run:
```bash
composer install
```

### Method 2: Manual Installation

1. **Create Plugin Directory**
   ```bash
   cd wp-content/plugins/
   mkdir dataflair-toplists
   cd dataflair-toplists
   ```

2. **Upload Files**
   - Upload all plugin files to the `dataflair-toplists` directory
   - Ensure the directory structure matches the file structure above

3. **Activate Plugin**
   - Go to WordPress Admin → Plugins
   - Find "DataFlair Toplists"
   - Click "Activate"

## ⚙️ Configuration

### Step 1: Add API Token
1. Go to **DataFlair** menu in WordPress admin
2. Enter your Bearer Token in the "API Bearer Token" field
3. The token will be automatically added as a query parameter to API requests

### Step 2: Add API Endpoints
1. In the "API Endpoints" textarea, add your toplists URLs (one per line):
   ```
   https://tenant.dataflair.ai/api/v1/toplists/3

2. Click "Save Settings"

### Step 3: Sync Data
1. Click the "Sync Now" button
2. The plugin will fetch all configured toplists
3. View synced toplists in the table below

## 📝 Shortcode Usage

### Basic Shortcode
```
[dataflair_toplist id="3"]
```

### With Custom Title
```
[dataflair_toplist id="3" title="Best UK Casinos"]
```

### With Limit
```
[dataflair_toplist id="3" title="Top 5 Casinos" limit="5"]
```

### Parameters
- **id** (required): API toplist ID (not WordPress database ID)
- **title** (optional): Custom title for the toplist
- **limit** (optional): Number of casinos to display

## 🔄 Data Synchronization

### Automatic Sync
- Plugin automatically syncs every 2 days via WordPress Cron
- Next sync time displayed in admin panel

### Manual Sync
- Click "Sync Now" button in admin panel anytime
- Useful after adding new endpoints or updating data

### ID Mapping
- **Critical Feature**: Plugin maintains mapping between API IDs and WordPress database IDs
- Example: API toplist ID `3` might be stored as WordPress ID `5`
- Shortcode always uses API ID, plugin handles the mapping automatically
- You never need to worry about internal database IDs

## 🎨 Frontend Display

The plugin displays toplists as responsive tables with:
- Casino position (#)
- Casino name with licenses
- Bonus offer with wagering requirements and minimum deposit
- "Play Now" CTA button (ready for affiliate tracking)

### Stale Data Notice
If data hasn't been synced in 3+ days, a warning appears:
```
⚠️ This data was last updated on Oct 15, 2024. Using cached version.
```

## 🔧 Customization

### Styling
Edit `assets/style.css` to customize:
- Table colors and layout
- Button styles
- Responsive breakpoints
- Typography

### Affiliate Links
Currently, CTA buttons use `#` as placeholder. To add tracking:

1. **Option A: Direct Link in Offer Data**
   - If your API provides tracking URLs, modify line in `toplist_shortcode()`:
   ```php
   <a href="<?php echo esc_url($offer['tracking_url']); ?>" ...>
   ```

2. **Option B: Custom Tracking Function**
   - Add tracking parameter logic in the plugin
   - Example: `?ref=yoursite&casino=<?php echo $brand['id']; ?>`

## 🐛 Debugging

### Error Messages
Plugin displays errors directly on the page for debugging:

**"Toplist ID is required"**
- Missing `id` parameter in shortcode
- Fix: `[dataflair_toplist id="3"]`

**"Toplist ID X not found. Please sync first."**
- API toplist not synced to database
- Fix: Go to admin panel and click "Sync Now"

**"Invalid toplist data"**
- Corrupted data in database
- Fix: Re-sync the toplist

### Check WordPress Error Log
```php
// Enable WordPress debugging in wp-config.php
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
define('WP_DEBUG_DISPLAY', false);

// Check logs at: wp-content/debug.log
```

### Common Issues

**1. API Token Not Working**
- Verify token is correct in DataFlair account
- Check if token is passed as query parameter: `?token=YOUR_TOKEN`

**2. Cron Not Running**
- Check if WordPress cron is working: `wp cron event list` (WP-CLI)
- Test manual sync first
- Consider using real cron jobs for production

**3. Shortcode Shows Nothing**
- Check if toplist is synced (view synced toplists table in admin)
- Verify you're using the API ID, not WordPress database ID
- Clear WordPress cache if using caching plugin

## 📊 Database Structure

**Table Name**: `wp_dataflair_toplists`

| Column | Type | Description |
|--------|------|-------------|
| id | bigint(20) | WordPress auto-increment ID |
| api_toplist_id | bigint(20) | DataFlair API toplist ID (unique) |
| name | varchar(255) | Toplist name |
| data | longtext | Full JSON response from API |
| version | varchar(50) | API version timestamp |
| last_synced | datetime | Last sync timestamp |

## 🔐 Security Features

- ✅ Nonce verification for manual sync
- ✅ Capability checks (`manage_options`)
- ✅ SQL injection prevention (prepared statements)
- ✅ XSS protection (proper escaping)
- ✅ Direct file access prevention

## 🚀 Production Deployment

### Before Going Live

1. **Test on Staging**
   - Verify all endpoints work
   - Test shortcodes on different pages
   - Check mobile responsiveness

2. **Set Up Real Cron**
   - Disable WordPress cron in `wp-config.php`:
     ```php
     define('DISABLE_WP_CRON', true);
     ```
   - Add server cron job:
     ```bash
     */30 * * * * curl https://yoursite.com/wp-cron.php?doing_wp_cron
     ```

3. **Performance Optimization**
   - Data is cached in database (no API calls on page load)
   - Consider using object caching (Redis/Memcached)
   - Use CDN for assets

4. **Monitoring**
   - Monitor `debug.log` for API errors
   - Set up alerts for failed syncs
   - Check database table size periodically

### Forge Deployment Notes

Since you're using Laravel Forge, the WordPress site should have:
- ✅ Proper PHP version (7.4+)
- ✅ HTTPS enabled
- ✅ Database backups configured
- ✅ Cron jobs properly set up

## 📈 Future Enhancements

Potential improvements for future versions:

1. **Gutenberg Block**
   - Visual toplist selector in block editor
   - Live preview in editor

2. **Logo Support**
   - If API provides logo URLs, add to display
   - Fallback to brand name

3. **Tracking Integration**
   - Add tracking URL field to offers
   - Support for multiple affiliate networks

4. **Cache Control**
   - Configurable cache duration
   - Per-toplist sync schedules

5. **Advanced Filtering**
   - Filter by payment methods
   - Filter by license
   - Sort by bonus amount

## 🆘 Support

For issues or questions:
1. Check the synced toplists table in admin
2. Review WordPress error logs
3. Test with a fresh sync
4. Verify API endpoint returns valid JSON

## 📦 Publishing to Packagist (For Maintainers)

To make this package available via Composer:

1. **Push to GitHub**
   ```bash
   git init
   git add .
   git commit -m "Initial commit"
   git remote add origin https://github.com/dataflair/toplists.git
   git push -u origin main
   ```

2. **Create GitHub Release**
   - Go to GitHub → Releases → Create a new release
   - Tag version: `v1.2.0`
   - Release title: `Version 1.2.0`
   - Add release notes

3. **Submit to Packagist**
   - Go to [packagist.org](https://packagist.org)
   - Sign in with GitHub
   - Click "Submit"
   - Enter repository URL: `https://github.com/dataflair/toplists`
   - Packagist will automatically track new releases

4. **Auto-Update Setup**
   - Add GitHub webhook in repository settings
   - Packagist will auto-update on new releases

## 🔄 Version Management

This package follows [Semantic Versioning](https://semver.org/):
- **MAJOR** version (1.x.x): Incompatible API changes
- **MINOR** version (x.2.x): New features, backwards compatible
- **PATCH** version (x.x.0): Bug fixes, backwards compatible

To release a new version:
```bash
# Update version in composer.json and dataflair-toplists.php
# Commit changes
git add composer.json dataflair-toplists.php
git commit -m "Bump version to 1.2.1"
git tag v1.2.1
git push origin main --tags
```

## 🛠️ Development

### Requirements
- PHP 7.4 or higher
- WordPress 5.0 or higher
- Composer (for dependency management)
- Node.js & npm (for building Gutenberg blocks)

### Setup
```bash
# Install PHP dependencies
composer install

# Install Node dependencies
npm install

# Build Gutenberg block
npm run build

# Development mode (watch for changes)
npm run start
```

### Running Tests
```bash
# Run PHPUnit tests (when implemented)
composer test

# Check code style
composer phpcs
```

## 📄 License

GPL v2 or later

---

**Version**: 1.2.0  
**Package**: dataflair/toplists  
**Requires WordPress**: 5.0+  
**Tested up to**: 6.4  
**Requires PHP**: 7.4+  
**License**: GPL-2.0-or-later