<?php
/**
 * H1 regression test: cron is gone.
 *
 * In v1.11.0 (Phase 0B H1) the plugin's auto-sync cron machinery was
 * deleted. Sync now runs only when an operator triggers it from the
 * admin Tools page or via WP-CLI. Legacy cron events registered by
 * prior installs are cleared exactly once, gated by the persistent
 * `dataflair_cron_cleared_v1_11` option.
 *
 * This is a structural source-scan test: we confirm the plugin file
 * no longer *defines* any of the removed methods, no longer *registers*
 * the deleted hooks, and that `check_database_upgrade()` contains the
 * one-time legacy-cron clear gate.
 */
use PHPUnit\Framework\TestCase;

class CronRemovedTest extends TestCase {

    private string $source = '';

    protected function setUp(): void {
        parent::setUp();
        $path = DATAFLAIR_PLUGIN_DIR . 'dataflair-toplists.php';
        $this->source = (string) file_get_contents($path);
        $this->assertNotSame('', $this->source, "Plugin file must be readable at {$path}.");

        // Phase 9.5 (v2.1.1): the legacy-cron clear gate moved from the
        // god-class's check_database_upgrade() into SchemaMigrator. Append
        // its source so the structural scans below still see the clears.
        $migrator = DATAFLAIR_PLUGIN_DIR . 'src/Database/SchemaMigrator.php';
        if (is_readable($migrator)) {
            $this->source .= "\n" . (string) file_get_contents($migrator);
        }
    }

    // ── No cron methods defined ──────────────────────────────────────────

    public function test_add_custom_cron_schedules_is_no_longer_defined(): void {
        $this->assertDoesNotMatchRegularExpression(
            '/function\s+add_custom_cron_schedules\s*\(/',
            $this->source,
            'add_custom_cron_schedules() must be removed in Phase 0B H1.'
        );
    }

    public function test_ensure_cron_scheduled_is_no_longer_defined(): void {
        $this->assertDoesNotMatchRegularExpression(
            '/function\s+ensure_cron_scheduled\s*\(/',
            $this->source,
            'ensure_cron_scheduled() must be removed in Phase 0B H1.'
        );
    }

    public function test_cron_sync_toplists_is_no_longer_defined(): void {
        $this->assertDoesNotMatchRegularExpression(
            '/function\s+cron_sync_toplists\s*\(/',
            $this->source,
            'cron_sync_toplists() must be removed in Phase 0B H1.'
        );
    }

    public function test_cron_sync_brands_is_no_longer_defined(): void {
        $this->assertDoesNotMatchRegularExpression(
            '/function\s+cron_sync_brands\s*\(/',
            $this->source,
            'cron_sync_brands() must be removed in Phase 0B H1.'
        );
    }

    public function test_sync_all_toplists_is_no_longer_defined(): void {
        $this->assertDoesNotMatchRegularExpression(
            '/function\s+sync_all_toplists\s*\(/',
            $this->source,
            'sync_all_toplists() must be removed in Phase 0B H1.'
        );
    }

    public function test_sync_all_brands_is_no_longer_defined(): void {
        $this->assertDoesNotMatchRegularExpression(
            '/function\s+sync_all_brands\s*\(/',
            $this->source,
            'sync_all_brands() must be removed in Phase 0B H1.'
        );
    }

    public function test_get_last_cron_time_is_no_longer_defined(): void {
        $this->assertDoesNotMatchRegularExpression(
            '/function\s+get_last_cron_time\s*\(/',
            $this->source,
            'get_last_cron_time() must be removed in Phase 0B H1.'
        );
    }

    public function test_get_last_brands_cron_time_is_no_longer_defined(): void {
        $this->assertDoesNotMatchRegularExpression(
            '/function\s+get_last_brands_cron_time\s*\(/',
            $this->source,
            'get_last_brands_cron_time() must be removed in Phase 0B H1.'
        );
    }

    // ── No cron hook registrations remain ────────────────────────────────

    public function test_no_add_action_registers_dataflair_sync_cron(): void {
        $this->assertDoesNotMatchRegularExpression(
            '/add_action\(\s*[\'"]dataflair_sync_cron[\'"]/',
            $this->source,
            'add_action() for dataflair_sync_cron must not be registered.'
        );
    }

    public function test_no_add_action_registers_dataflair_brands_sync_cron(): void {
        $this->assertDoesNotMatchRegularExpression(
            '/add_action\(\s*[\'"]dataflair_brands_sync_cron[\'"]/',
            $this->source,
            'add_action() for dataflair_brands_sync_cron must not be registered.'
        );
    }

    public function test_no_add_filter_registers_cron_schedules(): void {
        $this->assertDoesNotMatchRegularExpression(
            '/add_filter\(\s*[\'"]cron_schedules[\'"]/',
            $this->source,
            'add_filter() for cron_schedules must not be registered (custom interval gone).'
        );
    }

    public function test_no_wp_schedule_event_remains(): void {
        $this->assertStringNotContainsString(
            'wp_schedule_event(',
            $this->source,
            'wp_schedule_event() must not be called anywhere — cron is gone.'
        );
    }

    // ── Migration gate exists ────────────────────────────────────────────

    public function test_upgrade_gate_option_is_present_in_source(): void {
        $this->assertStringContainsString(
            'dataflair_cron_cleared_v1_11',
            $this->source,
            'Persistent one-time gate option dataflair_cron_cleared_v1_11 must be referenced.'
        );
    }

    public function test_upgrade_gate_clears_legacy_toplists_cron(): void {
        $this->assertMatchesRegularExpression(
            '/wp_clear_scheduled_hook\(\s*[\'"]dataflair_sync_cron[\'"]\s*\)/',
            $this->source,
            'check_database_upgrade() must call wp_clear_scheduled_hook for dataflair_sync_cron.'
        );
    }

    public function test_upgrade_gate_clears_legacy_brands_cron(): void {
        $this->assertMatchesRegularExpression(
            '/wp_clear_scheduled_hook\(\s*[\'"]dataflair_brands_sync_cron[\'"]\s*\)/',
            $this->source,
            'check_database_upgrade() must call wp_clear_scheduled_hook for dataflair_brands_sync_cron.'
        );
    }

    // ── Admin UI no longer advertises "auto-sync" ────────────────────────

    public function test_admin_ui_does_not_claim_auto_sync(): void {
        $this->assertStringNotContainsString(
            'Auto-sync runs',
            $this->source,
            'Admin UI must not claim "Auto-sync runs …" — cron is gone in v1.11.0.'
        );
    }
}
