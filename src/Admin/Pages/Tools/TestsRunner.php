<?php
/**
 * Phase 9.6 (admin UX redesign) — Tools page diagnostic test runner.
 *
 * Defines a registry of lightweight diagnostic tests (no live-API required
 * unless the caller is on a configured site). Each test is a closure that
 * returns { status: 'pass'|'fail'|'warn', message: string }. Results are
 * persisted in the `dataflair_test_results` option (autoload=no) keyed by
 * slug so the UI can show last-run timestamps without re-running on load.
 *
 * Diagnostic tests (do not call the remote API directly — safe for any env):
 *   api_connection  — token configured + a 1-second HEAD to the base URL
 *   db_brands       — brand count in wp_dataflair_brands
 *   db_toplists     — toplist count in wp_dataflair_toplists
 *   cron_brands     — dataflair_cron_sync_brands hook is scheduled
 *   cron_toplists   — dataflair_cron_sync_toplists hook is scheduled
 *   last_sync       — reports when brands/toplists were last synced
 *   db_schema       — dataflair_db_version option matches installed schema
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Admin\Pages\Tools;

final class TestsRunner
{
    public const OPTION_KEY = 'dataflair_test_results';

    /** @return array<string,array{label:string,description:string}> */
    public static function registry(): array
    {
        return [
            'api_connection' => [
                'label'       => 'API Connection',
                'description' => 'Verifies the API token is configured and the base URL is reachable (1 s timeout).',
            ],
            'db_brands' => [
                'label'       => 'Brands Table',
                'description' => 'Counts rows in wp_dataflair_brands. Warns if empty.',
            ],
            'db_toplists' => [
                'label'       => 'Toplists Table',
                'description' => 'Counts rows in wp_dataflair_toplists. Warns if empty.',
            ],
            'last_sync' => [
                'label'       => 'Last Sync Timestamps',
                'description' => 'Reports when brands and toplists were last successfully synced.',
            ],
            'db_schema' => [
                'label'       => 'DB Schema Version',
                'description' => 'Verifies dataflair_db_version option matches the expected installed schema.',
            ],
        ];
    }

    /**
     * Run a single diagnostic test by slug.
     *
     * @return array{slug:string,status:string,message:string,duration_ms:int,last_run_iso:string}
     */
    public function run(string $slug): array
    {
        $fn    = $this->testFunctions()[$slug] ?? null;
        if ($fn === null) {
            return $this->makeResult($slug, 'fail', 'Unknown test slug: ' . $slug, 0);
        }

        $start = microtime(true);
        try {
            ['status' => $status, 'message' => $message] = $fn();
        } catch (\Throwable $e) {
            $status  = 'fail';
            $message = 'Exception: ' . $e->getMessage();
        }
        $ms = (int) round((microtime(true) - $start) * 1000);

        $result = $this->makeResult($slug, $status, $message, $ms);
        $this->persist($slug, $result);

        $level = $status === 'pass' ? 'INFO' : ($status === 'fail' ? 'ERROR' : 'WARN');
        error_log("[DataFlair][{$level}] test.run slug={$slug} status={$status} duration_ms={$ms} message={$message}");

        return $result;
    }

    /**
     * Run all tests. Returns map of slug → result.
     *
     * @return array<string,array{slug:string,status:string,message:string,duration_ms:int,last_run_iso:string}>
     */
    public function runAll(): array
    {
        $out = [];
        foreach (array_keys(self::registry()) as $slug) {
            $out[$slug] = $this->run($slug);
        }
        return $out;
    }

    /**
     * Load all persisted results from the option store.
     *
     * @return array<string,array{slug:string,status:string,message:string,duration_ms:int,last_run_iso:string}>
     */
    public function loadAll(): array
    {
        $stored = get_option(self::OPTION_KEY, []);
        if (!is_array($stored)) {
            $stored = [];
        }

        $out = [];
        foreach (array_keys(self::registry()) as $slug) {
            $out[$slug] = $stored[$slug] ?? [
                'slug'         => $slug,
                'status'       => 'pending',
                'message'      => 'Not yet run.',
                'duration_ms'  => 0,
                'last_run_iso' => '',
            ];
        }
        return $out;
    }

    // ── Private helpers ──────────────────────────────────────────────────────

    private function makeResult(string $slug, string $status, string $message, int $ms): array
    {
        return [
            'slug'         => $slug,
            'status'       => $status,
            'message'      => $message,
            'duration_ms'  => $ms,
            'last_run_iso' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(\DateTimeInterface::ATOM),
        ];
    }

    private function persist(string $slug, array $result): void
    {
        $stored = get_option(self::OPTION_KEY, []);
        if (!is_array($stored)) {
            $stored = [];
        }
        $stored[$slug] = $result;
        update_option(self::OPTION_KEY, $stored, false);
    }

    /**
     * @return array<string, callable(): array{status:string,message:string}>
     */
    private function testFunctions(): array
    {
        return [
            'api_connection' => function (): array {
                $token    = trim((string) get_option('dataflair_api_token', ''));
                $base_url = trim((string) get_option('dataflair_api_base_url', ''));

                if ($token === '') {
                    return ['status' => 'fail', 'message' => 'API token is not configured.'];
                }
                if ($base_url === '') {
                    return ['status' => 'warn', 'message' => 'API token is set but no base URL configured — auto-detection will be used.'];
                }

                $ping_url = rtrim($base_url, '/') . '/toplists';
                $resp = wp_remote_head($ping_url, [
                    'timeout' => 3,
                    'headers' => ['Authorization' => 'Bearer ' . $token],
                    'sslverify' => false,
                ]);
                if (is_wp_error($resp)) {
                    return ['status' => 'warn', 'message' => 'Could not reach API: ' . $resp->get_error_message()];
                }
                $code = wp_remote_retrieve_response_code($resp);
                if ($code >= 200 && $code < 400) {
                    return ['status' => 'pass', 'message' => "API reachable (HTTP {$code})."];
                }
                return ['status' => 'warn', 'message' => "API responded HTTP {$code}."];
            },

            'db_brands' => function (): array {
                global $wpdb;
                $table = $wpdb->prefix . DATAFLAIR_BRANDS_TABLE_NAME;
                $count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
                if ($count === 0) {
                    return ['status' => 'warn', 'message' => 'Brands table is empty — sync brands from the API.'];
                }
                return ['status' => 'pass', 'message' => number_format($count) . ' brand(s) in DB.'];
            },

            'db_toplists' => function (): array {
                global $wpdb;
                $table = $wpdb->prefix . DATAFLAIR_TABLE_NAME;
                $count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
                if ($count === 0) {
                    return ['status' => 'warn', 'message' => 'Toplists table is empty — sync toplists from the API.'];
                }
                return ['status' => 'pass', 'message' => number_format($count) . ' toplist(s) in DB.'];
            },

            'last_sync' => function (): array {
                $brands    = get_option('dataflair_last_brands_sync');
                $toplists  = get_option('dataflair_last_toplists_sync');

                $bLabel = $brands   ? human_time_diff((int) $brands)   . ' ago' : 'never';
                $tLabel = $toplists ? human_time_diff((int) $toplists) . ' ago' : 'never';

                $status = ($brands && $toplists) ? 'pass' : 'warn';
                return [
                    'status'  => $status,
                    'message' => "Brands: {$bLabel}. Toplists: {$tLabel}.",
                ];
            },

            'db_schema' => function (): array {
                $installed = get_option('dataflair_db_version', '');
                $expected  = defined('DATAFLAIR_DB_VERSION') ? DATAFLAIR_DB_VERSION : '1.12';
                if ($installed === '') {
                    return ['status' => 'warn', 'message' => 'dataflair_db_version option not set — migrations may not have run.'];
                }
                if (version_compare($installed, $expected, '>=')) {
                    return ['status' => 'pass', 'message' => "DB schema v{$installed} — plugin requires v{$expected}. Up to date."];
                }
                return ['status' => 'warn', 'message' => "DB schema v{$installed} is older than required v{$expected} — deactivate/reactivate the plugin to run migrations."];
            },
        ];
    }
}
