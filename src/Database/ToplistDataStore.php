<?php
/**
 * Phase 9.10 — Toplist row writer.
 *
 * Owns the upsert against `wp_dataflair_toplists`: data integrity
 * validation via DataFlair_DataIntegrityChecker, the canonical column
 * map (`name`, `slug`, `current_period`, `published_at`, `item_count`,
 * `locked_count`, `sync_warnings`, `data`, `version`, `last_synced`),
 * and the `api_toplist_id` upsert key.
 *
 * Phase 0B integrity warnings are logged but never block the sync —
 * that invariant has held since v1.11.0 and is preserved here.
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Database;

class ToplistDataStore
{
    /**
     * Upsert a single toplist payload into `wp_dataflair_toplists`.
     *
     * @param array<string,mixed> $toplistData Decoded `data` envelope.
     * @param string              $rawJson     The original response body
     *                                         we cache verbatim in the
     *                                         `data` column.
     */
    public function store(array $toplistData, string $rawJson): bool
    {
        global $wpdb;
        $tableName = $wpdb->prefix . DATAFLAIR_TABLE_NAME;

        if (!isset($toplistData['id'])) {
            $errorMessage = 'DataFlair API Error: Invalid response format. Response: ' . substr($rawJson, 0, 300);
            error_log($errorMessage);
            add_settings_error('dataflair_messages', 'dataflair_format_error', $errorMessage, 'error');
            return false;
        }

        require_once DATAFLAIR_PLUGIN_DIR . 'includes/DataIntegrityChecker.php';
        $integrity = \DataFlair_DataIntegrityChecker::validate($toplistData);

        if (!empty($integrity['warnings'])) {
            error_log(sprintf(
                '[DataFlair Sync] Toplist #%d (%s): %d warning(s) — %s',
                $toplistData['id'],
                $toplistData['name'] ?? 'unknown',
                count($integrity['warnings']),
                implode('; ', array_slice($integrity['warnings'], 0, 5))
            ));
        }

        $apiId = $toplistData['id'];
        $name = $toplistData['name'] ?? '';
        $version = $toplistData['version'] ?? '';

        $dataRow = [
            'name'           => $name,
            'slug'           => $toplistData['slug'] ?? null,
            'current_period' => $toplistData['currentPeriod'] ?? null,
            'published_at'   => isset($toplistData['publishedAt']) ? date('Y-m-d H:i:s', strtotime($toplistData['publishedAt'])) : null,
            'item_count'     => $integrity['item_count'],
            'locked_count'   => $integrity['locked_count'],
            'sync_warnings'  => !empty($integrity['warnings']) ? wp_json_encode($integrity['warnings']) : null,
            'data'           => $rawJson,
            'version'        => $version,
            'last_synced'    => current_time('mysql'),
        ];

        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM $tableName WHERE api_toplist_id = %d",
            $apiId
        ));

        // Format types matching $dataRow key order:
        // name(%s), slug(%s), current_period(%s), published_at(%s),
        // item_count(%d), locked_count(%d), sync_warnings(%s),
        // data(%s), version(%s), last_synced(%s)
        $updateFormats = ['%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s'];

        if ($existing) {
            $result = $wpdb->update(
                $tableName,
                $dataRow,
                ['api_toplist_id' => $apiId],
                $updateFormats,
                ['%d']
            );
            if ($result === false) {
                $errorMessage = sprintf(
                    'DataFlair DB Update Error for toplist #%d: %s',
                    $apiId,
                    $wpdb->last_error ?: 'Unknown error'
                );
                error_log($errorMessage);
                add_settings_error('dataflair_messages', 'dataflair_db_error', $errorMessage, 'error');
                return false;
            }
        } else {
            $dataRow['api_toplist_id'] = $apiId;
            $insertFormats = array_merge($updateFormats, ['%d']);
            $result = $wpdb->insert($tableName, $dataRow, $insertFormats);
            if ($result === false) {
                $errorMessage = sprintf(
                    'DataFlair DB Insert Error for toplist #%d: %s',
                    $apiId,
                    $wpdb->last_error ?: 'Unknown error'
                );
                error_log($errorMessage);
                add_settings_error('dataflair_messages', 'dataflair_db_error', $errorMessage, 'error');
                return false;
            }
        }

        return true;
    }
}
