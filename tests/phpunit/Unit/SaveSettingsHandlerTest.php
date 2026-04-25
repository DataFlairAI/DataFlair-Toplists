<?php
/**
 * Phase 5 — pins SaveSettingsHandler sanitisation + persistence contract.
 *
 * Locks in the byte-for-byte behaviour migrated from
 * DataFlair_Toplists::ajax_save_settings():
 *   - api token trimmed (no sanitize_text_field — it mangles brackets)
 *   - http-basic-auth password trimmed only
 *   - http-basic-auth user sanitize_text_field
 *   - brands-api-version whitelisted to v1|v2 (default v1)
 *   - base URL esc_url_raw'd, trailing slash stripped, pinned to /api/vN
 *   - empty base URL deletes the option rather than storing ''
 *   - colour fields sanitize_text_field'd
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Tests\Unit\Admin\Ajax;

use DataFlair\Toplists\Admin\Ajax\SaveSettingsHandler;
use PHPUnit\Framework\TestCase;

require_once DATAFLAIR_PLUGIN_DIR . 'src/Admin/AjaxHandlerInterface.php';
require_once __DIR__ . '/SaveSettingsHandlerTestStubs.php';
require_once DATAFLAIR_PLUGIN_DIR . 'src/Admin/Ajax/SaveSettingsHandler.php';

final class SaveSettingsHandlerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        \SaveSettingsHandlerTestStubs::reset();
    }

    public function test_returns_success_envelope(): void
    {
        $result = (new SaveSettingsHandler())->handle([]);

        $this->assertTrue($result['success']);
        $this->assertSame('Settings saved successfully.', $result['data']['message']);
    }

    public function test_api_token_is_trimmed_only_not_sanitized(): void
    {
        (new SaveSettingsHandler())->handle([
            'dataflair_api_token' => "   token-with-brackets-[abc]   ",
        ]);

        $this->assertSame(
            'token-with-brackets-[abc]',
            \SaveSettingsHandlerTestStubs::$options['dataflair_api_token']
        );
    }

    public function test_http_basic_auth_password_is_trimmed_only(): void
    {
        (new SaveSettingsHandler())->handle([
            'dataflair_http_auth_pass' => "  p4ss%word  ",
        ]);

        $this->assertSame(
            'p4ss%word',
            \SaveSettingsHandlerTestStubs::$options['dataflair_http_auth_pass']
        );
    }

    public function test_brands_api_version_whitelisted_to_v1_or_v2(): void
    {
        (new SaveSettingsHandler())->handle(['dataflair_brands_api_version' => 'v2']);
        $this->assertSame('v2', \SaveSettingsHandlerTestStubs::$options['dataflair_brands_api_version']);

        (new SaveSettingsHandler())->handle(['dataflair_brands_api_version' => 'v99']);
        $this->assertSame('v1', \SaveSettingsHandlerTestStubs::$options['dataflair_brands_api_version']);
    }

    public function test_empty_base_url_deletes_the_option(): void
    {
        \SaveSettingsHandlerTestStubs::$options['dataflair_api_base_url'] = 'https://old.example/api/v1';
        (new SaveSettingsHandler())->handle(['dataflair_api_base_url' => '']);

        $this->assertArrayNotHasKey('dataflair_api_base_url', \SaveSettingsHandlerTestStubs::$options);
    }

    public function test_non_empty_base_url_is_trimmed_and_pinned_to_api_v_n(): void
    {
        (new SaveSettingsHandler())->handle([
            'dataflair_api_base_url' => 'https://api.dataflair.ai/api/v2/toplists/extra/',
        ]);

        $this->assertSame(
            'https://api.dataflair.ai/api/v2',
            \SaveSettingsHandlerTestStubs::$options['dataflair_api_base_url']
        );
    }

    public function test_colour_fields_are_sanitize_text_fielded(): void
    {
        (new SaveSettingsHandler())->handle([
            'dataflair_ribbon_bg_color'   => '#ffcc00',
            'dataflair_ribbon_text_color' => '#222222',
            'dataflair_cta_bg_color'      => '#00aaff',
            'dataflair_cta_text_color'    => '#000000',
        ]);

        $this->assertSame('#ffcc00', \SaveSettingsHandlerTestStubs::$options['dataflair_ribbon_bg_color']);
        $this->assertSame('#222222', \SaveSettingsHandlerTestStubs::$options['dataflair_ribbon_text_color']);
        $this->assertSame('#00aaff', \SaveSettingsHandlerTestStubs::$options['dataflair_cta_bg_color']);
        $this->assertSame('#000000', \SaveSettingsHandlerTestStubs::$options['dataflair_cta_text_color']);
    }

    public function test_fields_absent_from_request_are_not_written(): void
    {
        (new SaveSettingsHandler())->handle([]);

        // Only the whitelisted brands_api_version always writes (defaults v1).
        $this->assertSame(['dataflair_brands_api_version' => 'v1'], \SaveSettingsHandlerTestStubs::$options);
    }
}
