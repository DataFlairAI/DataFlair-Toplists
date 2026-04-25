<?php
/**
 * Phase 9.5 — WPPB-style schema migrator.
 *
 * Consolidates every DDL path the plugin has ever used:
 *
 *   - Activation-time `CREATE TABLE IF NOT EXISTS` for the three custom
 *     tables (toplists, brands, alternative-toplists).
 *   - Runtime `check_database_upgrade` self-heal: runs on `plugins_loaded`
 *     and silently re-creates missing tables if an operator file-deployed
 *     the plugin (and thus never fired the activation hook).
 *   - Incremental `ALTER TABLE … ADD COLUMN` upgrades for schema
 *     revisions v1.2 → v1.11 (columns for product_types, licenses,
 *     top_geos, offers_count, trackers_count, classification_types,
 *     review_url_override, local_logo_url, cached_review_post_id, the
 *     generated externalId index, etc.).
 *   - JSON-type migration for MySQL 5.7.8+/MariaDB 10.2.7+ — validates
 *     existing rows, then flips the `data` column type.
 *   - One-time option renames + cron-hook clears left over from the
 *     pre-v1.11.0 cron-removal project.
 *
 * Single responsibility: own DDL. Read/write of rows is BrandsRepository
 * / ToplistsRepository / AlternativesRepository — this class never
 * selects or updates data rows.
 *
 * Previously spread across ~350 lines of `DataFlair_Toplists` god-class
 * methods (`activate`, `check_database_upgrade`, `ensure_tables_exist`,
 * `upgrade_database`, `ensure_brands_external_id_index`,
 * `ensure_alternative_toplists_table`, `supports_json_type`,
 * `migrate_to_json_type`). The god-class keeps public shims that forward
 * here so downstream code calling those methods still works.
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Database;

final class SchemaMigrator
{
    /**
     * The current schema version, stored in `wp_options` under
     * `dataflair_db_version`. Bump this when any DDL in this file
     * changes; the upgrade path (`upgradeDatabase`) will run once per
     * site on the next request after the bump.
     */
    public const CURRENT_VERSION = '1.11';

    /**
     * Hook into `plugins_loaded` to run `checkDatabaseUpgrade` on every
     * request (short-circuited via transient — see implementation).
     */
    public function register(): void
    {
        if (!function_exists('add_action')) {
            return;
        }
        add_action('plugins_loaded', [$this, 'checkDatabaseUpgrade']);
    }

    /**
     * Activation-time table creation. Called from
     * `register_activation_hook` via {@see \DataFlair\Toplists\Lifecycle\Activator}.
     */
    public function createTables(): void
    {
        global $wpdb;

        $toplists_table            = $wpdb->prefix . \DATAFLAIR_TABLE_NAME;
        $brands_table              = $wpdb->prefix . \DATAFLAIR_BRANDS_TABLE_NAME;
        $alternative_toplists_table = $wpdb->prefix . \DATAFLAIR_ALTERNATIVE_TOPLISTS_TABLE_NAME;
        $charset_collate           = $wpdb->get_charset_collate();

        $data_type = $this->supportsJsonType() ? 'JSON' : 'longtext';

        $toplists_sql = "CREATE TABLE IF NOT EXISTS $toplists_table (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            api_toplist_id bigint(20) NOT NULL,
            name varchar(255) NOT NULL,
            slug varchar(255) DEFAULT NULL,
            current_period varchar(100) DEFAULT NULL,
            published_at datetime DEFAULT NULL,
            item_count int(11) NOT NULL DEFAULT 0,
            locked_count int(11) NOT NULL DEFAULT 0,
            sync_warnings text DEFAULT NULL,
            data $data_type NOT NULL,
            version varchar(50) DEFAULT NULL,
            last_synced datetime NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY api_toplist_id (api_toplist_id)
        ) $charset_collate;";

        $brands_sql = "CREATE TABLE IF NOT EXISTS $brands_table (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            api_brand_id bigint(20) NOT NULL,
            name varchar(255) NOT NULL,
            slug varchar(255) NOT NULL,
            status varchar(50) NOT NULL,
            product_types text,
            licenses text,
            top_geos text,
            offers_count int(11) DEFAULT 0,
            trackers_count int(11) DEFAULT 0,
            classification_types VARCHAR(500) NOT NULL DEFAULT '',
            review_url_override VARCHAR(500) DEFAULT NULL,
            data $data_type NOT NULL,
            last_synced datetime NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY api_brand_id (api_brand_id)
        ) $charset_collate;";

        $alternatives_sql = "CREATE TABLE IF NOT EXISTS $alternative_toplists_table (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            toplist_id bigint(20) NOT NULL,
            geo varchar(255) NOT NULL,
            alternative_toplist_id bigint(20) NOT NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY toplist_geo (toplist_id, geo),
            KEY toplist_id (toplist_id),
            KEY alternative_toplist_id (alternative_toplist_id)
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($toplists_sql);
        dbDelta($brands_sql);
        dbDelta($alternatives_sql);

        $this->ensureBrandsExternalIdIndex();
    }

    /**
     * Runtime self-heal + incremental-upgrade entry point. Registered on
     * `plugins_loaded`. Short-circuits on warm hits via a 12-hour
     * transient keyed by the current schema version so the schema
     * introspection doesn't run on every request.
     */
    public function checkDatabaseUpgrade(): void
    {
        $db_version       = get_option('dataflair_db_version', '1.0');
        $current_version  = self::CURRENT_VERSION;

        // Phase 0B H9: short-circuit on warm hit.
        $schema_ok_key = 'dataflair_schema_ok_v' . $current_version;
        if (get_transient($schema_ok_key) === '1') {
            return;
        }

        if (version_compare($db_version, $current_version, '<')) {
            $this->upgradeDatabase();
            update_option('dataflair_db_version', $current_version);
        }

        // H1 (v1.11.0): clear legacy cron events exactly once. Gated by a
        // persistent option so the clear survives restart loops.
        if (get_option('dataflair_cron_cleared_v1_11') !== '1') {
            wp_clear_scheduled_hook('dataflair_sync_cron');
            wp_clear_scheduled_hook('dataflair_brands_sync_cron');
            update_option('dataflair_cron_cleared_v1_11', '1');
        }

        // Phase 1 — option rename migration. `dataflair_last_*_cron_run`
        // -> `dataflair_last_*_sync`. One-time, gated so idempotent.
        if (get_option('dataflair_options_renamed_v1_11_2') !== '1') {
            foreach ([
                'dataflair_last_toplists_cron_run' => 'dataflair_last_toplists_sync',
                'dataflair_last_brands_cron_run'   => 'dataflair_last_brands_sync',
            ] as $legacy => $new) {
                $legacy_value = get_option($legacy);
                if ($legacy_value !== false && get_option($new) === false) {
                    update_option($new, $legacy_value);
                }
            }
            update_option('dataflair_options_renamed_v1_11_2', '1');
        }

        // Self-heal: create tables if activation never fired (file deploy).
        $this->ensureTablesExist();

        // Migrate to JSON type if supported.
        $this->migrateToJsonType();

        // Mark schema healthy for 12h — next request short-circuits.
        set_transient($schema_ok_key, '1', 12 * HOUR_IN_SECONDS);
    }

    /**
     * Ensure all plugin tables exist. Safe to call on every request —
     * only runs DDL if a table is actually missing. Covers manual file
     * deploys where `register_activation_hook` never fires.
     */
    public function ensureTablesExist(): void
    {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        $data_type       = $this->supportsJsonType() ? 'JSON' : 'longtext';

        $table_name   = $wpdb->prefix . \DATAFLAIR_TABLE_NAME;
        $brands_table = $wpdb->prefix . \DATAFLAIR_BRANDS_TABLE_NAME;

        $missing = false;
        if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") !== $table_name) {
            $missing = true;
        }
        if ($wpdb->get_var("SHOW TABLES LIKE '$brands_table'") !== $brands_table) {
            $missing = true;
        }

        if (!$missing) {
            $this->ensureBrandsExternalIdIndex();
            return;
        }

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            api_toplist_id bigint(20) NOT NULL,
            name varchar(255) NOT NULL,
            slug varchar(255) DEFAULT NULL,
            current_period varchar(100) DEFAULT NULL,
            published_at datetime DEFAULT NULL,
            item_count int(11) NOT NULL DEFAULT 0,
            locked_count int(11) NOT NULL DEFAULT 0,
            sync_warnings text DEFAULT NULL,
            data $data_type NOT NULL,
            version varchar(50) DEFAULT NULL,
            last_synced datetime NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY api_toplist_id (api_toplist_id)
        ) $charset_collate;";

        $brands_sql = "CREATE TABLE IF NOT EXISTS $brands_table (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            api_brand_id bigint(20) NOT NULL,
            name varchar(255) NOT NULL,
            slug varchar(255) NOT NULL,
            status varchar(50) NOT NULL,
            product_types text,
            licenses text,
            top_geos text,
            offers_count int(11) DEFAULT 0,
            trackers_count int(11) DEFAULT 0,
            classification_types VARCHAR(500) NOT NULL DEFAULT '',
            review_url_override VARCHAR(500) DEFAULT NULL,
            local_logo_url VARCHAR(500) DEFAULT NULL,
            cached_review_post_id BIGINT(20) UNSIGNED DEFAULT NULL,
            data $data_type NOT NULL,
            last_synced datetime NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY api_brand_id (api_brand_id)
        ) $charset_collate;";

        dbDelta($sql);
        dbDelta($brands_sql);
        $this->ensureBrandsExternalIdIndex();

        error_log('DataFlair: ensureTablesExist() ran dbDelta — tables were missing.');
    }

    /**
     * Incremental schema upgrades for v1.2 through v1.11.
     */
    private function upgradeDatabase(): void
    {
        global $wpdb;
        $table_name        = $wpdb->prefix . \DATAFLAIR_TABLE_NAME;
        $brands_table_name = $wpdb->prefix . \DATAFLAIR_BRANDS_TABLE_NAME;
        $charset_collate   = $wpdb->get_charset_collate();

        // ── Toplists table: snapshot + integrity columns (v1.5) ──
        if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") === $table_name) {
            $tl_columns = $wpdb->get_col("DESCRIBE $table_name");

            if (!in_array('slug', $tl_columns)) {
                $wpdb->query("ALTER TABLE $table_name ADD COLUMN slug VARCHAR(255) DEFAULT NULL AFTER name");
            }
            if (!in_array('current_period', $tl_columns)) {
                $wpdb->query("ALTER TABLE $table_name ADD COLUMN current_period VARCHAR(100) DEFAULT NULL AFTER slug");
            } else {
                // v1.6: widen current_period from VARCHAR(7) to VARCHAR(100).
                $wpdb->query("ALTER TABLE $table_name MODIFY COLUMN current_period VARCHAR(100) DEFAULT NULL");
            }
            if (!in_array('published_at', $tl_columns)) {
                $wpdb->query("ALTER TABLE $table_name ADD COLUMN published_at DATETIME DEFAULT NULL AFTER current_period");
            }
            if (!in_array('item_count', $tl_columns)) {
                $wpdb->query("ALTER TABLE $table_name ADD COLUMN item_count INT DEFAULT 0 AFTER published_at");
            }
            if (!in_array('locked_count', $tl_columns)) {
                $wpdb->query("ALTER TABLE $table_name ADD COLUMN locked_count INT DEFAULT 0 AFTER item_count");
            }
            if (!in_array('sync_warnings', $tl_columns)) {
                $wpdb->query("ALTER TABLE $table_name ADD COLUMN sync_warnings TEXT DEFAULT NULL AFTER locked_count");
            }

            $idx = $wpdb->get_results("SHOW INDEX FROM $table_name WHERE Key_name = 'idx_slug'");
            if (empty($idx)) {
                $wpdb->query("CREATE INDEX idx_slug ON $table_name (slug)");
            }

            error_log('DataFlair: Toplists table upgraded to v1.5 (snapshot + integrity columns)');
        }

        // ── Brands table: v1.2 columns ──
        $brands_table_exists = $wpdb->get_var("SHOW TABLES LIKE '$brands_table_name'") === $brands_table_name;

        if ($brands_table_exists) {
            $columns = $wpdb->get_col("DESCRIBE $brands_table_name");

            if (!in_array('product_types', $columns)) {
                $wpdb->query("ALTER TABLE $brands_table_name ADD COLUMN product_types text AFTER status");
            }
            if (!in_array('licenses', $columns)) {
                $wpdb->query("ALTER TABLE $brands_table_name ADD COLUMN licenses text AFTER product_types");
            }
            if (!in_array('top_geos', $columns)) {
                $wpdb->query("ALTER TABLE $brands_table_name ADD COLUMN top_geos text AFTER licenses");
            }
            if (!in_array('offers_count', $columns)) {
                $wpdb->query("ALTER TABLE $brands_table_name ADD COLUMN offers_count int(11) DEFAULT 0 AFTER top_geos");
            }
            if (!in_array('trackers_count', $columns)) {
                $wpdb->query("ALTER TABLE $brands_table_name ADD COLUMN trackers_count int(11) DEFAULT 0 AFTER offers_count");
            }
            if (!in_array('classification_types', $columns)) {
                $wpdb->query("ALTER TABLE $brands_table_name ADD COLUMN classification_types VARCHAR(500) NOT NULL DEFAULT '' AFTER trackers_count");
            }

            error_log('DataFlair: Database schema upgraded to version 1.5');
        } else {
            // First-install path: create with full schema.
            $brands_sql = "CREATE TABLE IF NOT EXISTS $brands_table_name (
                id bigint(20) NOT NULL AUTO_INCREMENT,
                api_brand_id bigint(20) NOT NULL,
                name varchar(255) NOT NULL,
                slug varchar(255) NOT NULL,
                status varchar(50) NOT NULL,
                product_types text,
                licenses text,
                top_geos text,
                offers_count int(11) DEFAULT 0,
                trackers_count int(11) DEFAULT 0,
                classification_types VARCHAR(500) NOT NULL DEFAULT '',
                review_url_override VARCHAR(500) DEFAULT NULL,
                local_logo_url VARCHAR(500) DEFAULT NULL,
                cached_review_post_id BIGINT(20) UNSIGNED DEFAULT NULL,
                data longtext NOT NULL,
                last_synced datetime NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY api_brand_id (api_brand_id)
            ) $charset_collate;";

            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
            dbDelta($brands_sql);

            error_log('DataFlair: Brands table created with full schema');
        }

        // ── Brands table: v1.8 — review_url_override column ──
        if ($wpdb->get_var("SHOW TABLES LIKE '$brands_table_name'") === $brands_table_name) {
            if (!$wpdb->get_var("SHOW COLUMNS FROM $brands_table_name LIKE 'review_url_override'")) {
                $wpdb->query("ALTER TABLE $brands_table_name ADD COLUMN review_url_override VARCHAR(500) DEFAULT NULL");
                error_log('DataFlair: Brands table upgraded to v1.8 (review_url_override column added)');
            }
        }

        // ── Brands table: v1.10 — local_logo_url + cached_review_post_id (Phase 0A H0) ──
        if ($wpdb->get_var("SHOW TABLES LIKE '$brands_table_name'") === $brands_table_name) {
            if (!$wpdb->get_var("SHOW COLUMNS FROM $brands_table_name LIKE 'local_logo_url'")) {
                $wpdb->query("ALTER TABLE $brands_table_name ADD COLUMN local_logo_url VARCHAR(500) DEFAULT NULL");
                error_log('DataFlair: Brands table upgraded to v1.10 (local_logo_url column added)');
            }
            if (!$wpdb->get_var("SHOW COLUMNS FROM $brands_table_name LIKE 'cached_review_post_id'")) {
                $wpdb->query("ALTER TABLE $brands_table_name ADD COLUMN cached_review_post_id BIGINT(20) UNSIGNED DEFAULT NULL");
                error_log('DataFlair: Brands table upgraded to v1.10 (cached_review_post_id column added)');
            }
        }

        $this->ensureBrandsExternalIdIndex();
        $this->ensureAlternativeToplistsTable();
    }

    /**
     * Ensure brands table has a generated externalId column + index for
     * JSON filtering.
     */
    public function ensureBrandsExternalIdIndex(): void
    {
        global $wpdb;
        $brands_table_name = $wpdb->prefix . \DATAFLAIR_BRANDS_TABLE_NAME;

        if ($wpdb->get_var("SHOW TABLES LIKE '$brands_table_name'") !== $brands_table_name) {
            return;
        }

        if (!$wpdb->get_var("SHOW COLUMNS FROM $brands_table_name LIKE 'external_id_virtual'")) {
            $wpdb->query(
                "ALTER TABLE $brands_table_name
                 ADD COLUMN external_id_virtual VARCHAR(50)
                 GENERATED ALWAYS AS (JSON_UNQUOTE(JSON_EXTRACT(data, '$.externalId'))) STORED"
            );
            if ($wpdb->last_error) {
                error_log('DataFlair: Failed adding external_id_virtual column: ' . $wpdb->last_error);
            }
        }

        if (!$wpdb->get_var("SHOW INDEX FROM $brands_table_name WHERE Key_name = 'idx_external_id_virtual'")) {
            $wpdb->query("CREATE INDEX idx_external_id_virtual ON $brands_table_name (external_id_virtual)");
            if ($wpdb->last_error) {
                error_log('DataFlair: Failed creating idx_external_id_virtual index: ' . $wpdb->last_error);
            }
        }
    }

    /**
     * Ensure the alternative toplists table exists.
     */
    public function ensureAlternativeToplistsTable(): void
    {
        global $wpdb;
        $alternative_toplists_table = $wpdb->prefix . \DATAFLAIR_ALTERNATIVE_TOPLISTS_TABLE_NAME;
        $charset_collate           = $wpdb->get_charset_collate();

        if ($wpdb->get_var("SHOW TABLES LIKE '$alternative_toplists_table'") === $alternative_toplists_table) {
            return;
        }

        $alternative_toplists_sql = "CREATE TABLE IF NOT EXISTS $alternative_toplists_table (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            toplist_id bigint(20) NOT NULL,
            geo varchar(255) NOT NULL,
            alternative_toplist_id bigint(20) NOT NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY toplist_geo (toplist_id, geo),
            KEY toplist_id (toplist_id),
            KEY alternative_toplist_id (alternative_toplist_id)
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($alternative_toplists_sql);

        error_log('DataFlair: Alternative toplists table created');
    }

    /**
     * Detect whether the MySQL/MariaDB server supports a JSON column type.
     *
     *   - MariaDB: 10.2.7+
     *   - MySQL: 5.7.8+
     */
    public function supportsJsonType(): bool
    {
        global $wpdb;

        $version = $wpdb->get_var('SELECT VERSION()');
        if (empty($version)) {
            return false;
        }

        if (stripos($version, 'mariadb') !== false) {
            preg_match('/(\d+)\.(\d+)\.(\d+)/', $version, $matches);
            if (!empty($matches)) {
                $major = (int) $matches[1];
                $minor = (int) $matches[2];
                $patch = (int) $matches[3];
                return ($major > 10)
                    || ($major == 10 && $minor > 2)
                    || ($major == 10 && $minor == 2 && $patch >= 7);
            }
            return false;
        }

        preg_match('/(\d+)\.(\d+)\.(\d+)/', $version, $matches);
        if (!empty($matches)) {
            $major = (int) $matches[1];
            $minor = (int) $matches[2];
            $patch = (int) $matches[3];
            return ($major > 5)
                || ($major == 5 && $minor > 7)
                || ($major == 5 && $minor == 7 && $patch >= 8);
        }

        return false;
    }

    /**
     * Migrate the `data` column from longtext to JSON where supported.
     *
     * Gated by the `dataflair_json_migration_done` option — MariaDB
     * reports JSON columns as "longtext" in INFORMATION_SCHEMA, so we
     * can't rely on DATA_TYPE introspection to detect completion.
     */
    public function migrateToJsonType(): void
    {
        if (get_option('dataflair_json_migration_done')) {
            return;
        }

        global $wpdb;

        if (!$this->supportsJsonType()) {
            update_option('dataflair_json_migration_done', '1');
            error_log('DataFlair: JSON type not supported by MySQL/MariaDB version. Staying on longtext.');
            return;
        }

        $table_name        = $wpdb->prefix . \DATAFLAIR_TABLE_NAME;
        $brands_table_name = $wpdb->prefix . \DATAFLAIR_BRANDS_TABLE_NAME;

        // Toplists table.
        $invalid_rows = $wpdb->get_results(
            "SELECT id FROM $table_name WHERE data IS NOT NULL AND data != ''",
            ARRAY_A
        );
        $invalid_count = 0;
        foreach ($invalid_rows as $row) {
            $data = $wpdb->get_var($wpdb->prepare(
                "SELECT data FROM $table_name WHERE id = %d",
                $row['id']
            ));
            json_decode($data);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $invalid_count++;
                error_log('DataFlair: Invalid JSON in toplist ID ' . $row['id'] . ': ' . json_last_error_msg());
            }
        }
        if ($invalid_count === 0) {
            $wpdb->query("ALTER TABLE $table_name MODIFY COLUMN data JSON NOT NULL");
            error_log('DataFlair: Successfully migrated toplists data column to JSON type');
        } else {
            error_log("DataFlair: Cannot migrate toplists table - found $invalid_count invalid JSON rows");
        }

        // Brands table.
        $invalid_rows = $wpdb->get_results(
            "SELECT id FROM $brands_table_name WHERE data IS NOT NULL AND data != ''",
            ARRAY_A
        );
        $invalid_count = 0;
        foreach ($invalid_rows as $row) {
            $data = $wpdb->get_var($wpdb->prepare(
                "SELECT data FROM $brands_table_name WHERE id = %d",
                $row['id']
            ));
            json_decode($data);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $invalid_count++;
                error_log('DataFlair: Invalid JSON in brand ID ' . $row['id'] . ': ' . json_last_error_msg());
            }
        }
        if ($invalid_count === 0) {
            $wpdb->query("ALTER TABLE $brands_table_name MODIFY COLUMN data JSON NOT NULL");
            error_log('DataFlair: Successfully migrated brands data column to JSON type');
        } else {
            error_log("DataFlair: Cannot migrate brands table - found $invalid_count invalid JSON rows");
        }

        update_option('dataflair_json_migration_done', '1');
    }
}
