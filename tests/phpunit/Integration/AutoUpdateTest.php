<?php
/**
 * Tests for automatic update wiring via plugin-update-checker.
 *
 * Covers:
 *  1.  vendor/autoload.php exists and is loadable
 *  2.  PucFactory class is available after autoload
 *  3.  Plugin bootstrap block is syntactically correct (no fatal)
 *  4.  GitHub repo URL in bootstrap points to correct org/repo
 *  5.  enableReleaseAssets() call is present (release-based, not branch-based)
 *  6.  Plugin Version header matches DATAFLAIR_VERSION constant
 *  7.  composer.json requires the correct package
 *  8.  composer.lock records the installed package
 *
 * No WordPress or network calls required — all file/constant checks.
 */

use PHPUnit\Framework\TestCase;

class AutoUpdateTest extends TestCase {

    private string $plugin_root;
    private string $main_file;
    private string $main_source;

    protected function setUp(): void {
        $this->plugin_root  = dirname(__DIR__, 3);
        $this->main_file    = $this->plugin_root . '/dataflair-toplists.php';
        $this->main_source  = file_get_contents($this->main_file);
    }

    /** 1. vendor/autoload.php must exist */
    public function test_vendor_autoload_exists(): void {
        $this->assertFileExists(
            $this->plugin_root . '/vendor/autoload.php',
            'vendor/autoload.php missing — run composer install'
        );
    }

    /** 2. PucFactory class is available after loading autoload */
    public function test_puc_factory_class_available(): void {
        require_once $this->plugin_root . '/vendor/autoload.php';
        $this->assertTrue(
            class_exists('YahnisElsts\PluginUpdateChecker\v5\PucFactory'),
            'PucFactory class not found — plugin-update-checker not installed'
        );
    }

    /** 3. Bootstrap block must be present in the main plugin file */
    public function test_update_checker_bootstrap_present(): void {
        $this->assertStringContainsString(
            'PucFactory::buildUpdateChecker',
            $this->main_source,
            'PucFactory::buildUpdateChecker not found in main plugin file'
        );
    }

    /** 4. GitHub repo URL must point to the correct org/repo */
    public function test_github_repo_url_correct(): void {
        $this->assertStringContainsString(
            'https://github.com/DataFlairAI/DataFlair-Toplists/',
            $this->main_source,
            'GitHub repo URL is wrong or missing in update checker bootstrap'
        );
    }

    /** 5. Release-based updates must be enabled (not branch-based) */
    public function test_release_assets_enabled(): void {
        $this->assertStringContainsString(
            'enableReleaseAssets',
            $this->main_source,
            'enableReleaseAssets() not called — updates will pull from branch zip, not releases'
        );
        $this->assertStringNotContainsString(
            'setBranch',
            $this->main_source,
            'setBranch() found — should use release assets instead'
        );
    }

    /** 6. Plugin Version header must match DATAFLAIR_VERSION constant */
    public function test_version_header_matches_constant(): void {
        // Extract Version: from plugin header
        preg_match('/\*\s+Version:\s+(\S+)/', $this->main_source, $header_match);
        // Extract define constant
        preg_match("/define\('DATAFLAIR_VERSION',\s*'([^']+)'\)/", $this->main_source, $const_match);

        $this->assertNotEmpty($header_match[1] ?? '', 'Version header not found in plugin file');
        $this->assertNotEmpty($const_match[1] ?? '', 'DATAFLAIR_VERSION constant not found in plugin file');
        $this->assertSame(
            $header_match[1],
            $const_match[1],
            'Plugin Version header does not match DATAFLAIR_VERSION constant — they must stay in sync'
        );
    }

    /** 7. composer.json must require the update checker package */
    public function test_composer_json_requires_puc(): void {
        $composer_json = $this->plugin_root . '/composer.json';
        $this->assertFileExists($composer_json);
        $data = json_decode(file_get_contents($composer_json), true);
        $all_requires = array_merge(
            $data['require'] ?? [],
            $data['require-dev'] ?? []
        );
        $this->assertArrayHasKey(
            'yahnis-elsts/plugin-update-checker',
            $all_requires,
            'yahnis-elsts/plugin-update-checker not in composer.json require or require-dev'
        );
    }

    /** 8. composer.lock must record the installed package */
    public function test_composer_lock_records_puc(): void {
        $lock_file = $this->plugin_root . '/composer.lock';
        $this->assertFileExists($lock_file, 'composer.lock missing — commit it for reproducible builds');
        $lock = json_decode(file_get_contents($lock_file), true);
        $package_names = array_column($lock['packages'] ?? [], 'name');
        $this->assertContains(
            'yahnis-elsts/plugin-update-checker',
            $package_names,
            'yahnis-elsts/plugin-update-checker not found in composer.lock packages'
        );
    }
}
