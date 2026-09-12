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
use DataFlair\Toplists\Webhooks\WebhookSelfRegistrarInterface;
use PHPUnit\Framework\TestCase;

require_once DATAFLAIR_PLUGIN_DIR . 'src/Admin/AjaxHandlerInterface.php';
require_once __DIR__ . '/SaveSettingsHandlerTestStubs.php';
require_once DATAFLAIR_PLUGIN_DIR . 'src/Webhooks/WebhookSelfRegistrarInterface.php';
require_once DATAFLAIR_PLUGIN_DIR . 'src/Admin/Ajax/SaveSettingsHandler.php';

final class SaveSettingsHandlerTest extends TestCase
{
    private FakeWebhookSelfRegistrarForSettings $webhookRegistrar;

    protected function setUp(): void
    {
        parent::setUp();
        \SaveSettingsHandlerTestStubs::reset();
        $this->webhookRegistrar = new FakeWebhookSelfRegistrarForSettings();
    }

    private function handler(): SaveSettingsHandler
    {
        return new SaveSettingsHandler($this->webhookRegistrar);
    }

    public function test_returns_success_envelope(): void
    {
        $result = ($this->handler())->handle([]);

        $this->assertTrue($result['success']);
        $this->assertSame('Settings saved successfully.', $result['data']['message']);
    }

    public function test_api_token_is_trimmed_only_not_sanitized(): void
    {
        ($this->handler())->handle([
            'dataflair_api_token' => "   token-with-brackets-[abc]   ",
        ]);

        $this->assertSame(
            'token-with-brackets-[abc]',
            \SaveSettingsHandlerTestStubs::$options['dataflair_api_token']
        );
    }

    public function test_http_basic_auth_password_is_trimmed_only(): void
    {
        ($this->handler())->handle([
            'dataflair_http_auth_pass' => "  p4ss%word  ",
        ]);

        $this->assertSame(
            'p4ss%word',
            \SaveSettingsHandlerTestStubs::$options['dataflair_http_auth_pass']
        );
    }

    public function test_brands_api_version_whitelisted_to_v1_or_v2(): void
    {
        ($this->handler())->handle(['dataflair_brands_api_version' => 'v2']);
        $this->assertSame('v2', \SaveSettingsHandlerTestStubs::$options['dataflair_brands_api_version']);

        ($this->handler())->handle(['dataflair_brands_api_version' => 'v99']);
        $this->assertSame('v1', \SaveSettingsHandlerTestStubs::$options['dataflair_brands_api_version']);
    }

    public function test_empty_base_url_deletes_the_option(): void
    {
        \SaveSettingsHandlerTestStubs::$options['dataflair_api_base_url'] = 'https://old.example/api/v1';
        ($this->handler())->handle(['dataflair_api_base_url' => '']);

        $this->assertArrayNotHasKey('dataflair_api_base_url', \SaveSettingsHandlerTestStubs::$options);
    }

    public function test_non_empty_base_url_is_trimmed_and_pinned_to_api_v_n(): void
    {
        ($this->handler())->handle([
            'dataflair_api_base_url' => 'https://api.dataflair.ai/api/v2/toplists/extra/',
        ]);

        $this->assertSame(
            'https://api.dataflair.ai/api/v2',
            \SaveSettingsHandlerTestStubs::$options['dataflair_api_base_url']
        );
    }

    public function test_colour_fields_are_sanitize_text_fielded(): void
    {
        ($this->handler())->handle([
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
        ($this->handler())->handle([]);

        // brands_api_version and webhook_enabled are the two fields that
        // always write with a default, matching the hidden-field checkbox
        // pattern: a real form submission always sends webhook_enabled
        // ('0' or '1'), it's never simply absent.
        $this->assertSame(
            ['dataflair_brands_api_version' => 'v1', 'dataflair_webhook_enabled' => '0'],
            \SaveSettingsHandlerTestStubs::$options
        );
    }

    public function test_webhook_checkbox_off_does_not_call_the_registrar(): void
    {
        ($this->handler())->handle(['dataflair_webhook_enabled' => '0']);

        $this->assertSame('0', \SaveSettingsHandlerTestStubs::$options['dataflair_webhook_enabled']);
        $this->assertNull($this->webhookRegistrar->calledWith);
    }

    public function test_webhook_checkbox_on_calls_the_registrar_with_the_receiver_url(): void
    {
        $result = ($this->handler())->handle(['dataflair_webhook_enabled' => '1']);

        $this->assertSame('1', \SaveSettingsHandlerTestStubs::$options['dataflair_webhook_enabled']);
        $this->assertSame('https://mysite.example/wp-json/dataflair/v1/webhooks', $this->webhookRegistrar->calledWith);
        $this->assertTrue($result['data']['webhook_registered']);
    }

    public function test_webhook_registration_failure_is_reported_but_settings_still_save(): void
    {
        $this->webhookRegistrar->result = false;

        $result = ($this->handler())->handle(['dataflair_webhook_enabled' => '1']);

        $this->assertTrue($result['success'], 'a failed registration must not fail the whole save');
        $this->assertFalse($result['data']['webhook_registered']);
    }

    public function test_delete_transient_does_not_touch_the_options_store(): void
    {
        \SaveSettingsHandlerTestStubs::$options['dataflair_api_health'] = 'unrelated-option-value';

        ($this->handler())->handle([]);

        // The unconditional delete_transient('dataflair_api_health') call at
        // the end of handle() must clear the transients store, not options —
        // even when an (unrelated in real WP) option happens to share the name.
        $this->assertArrayHasKey('dataflair_api_health', \SaveSettingsHandlerTestStubs::$options);
        $this->assertSame('unrelated-option-value', \SaveSettingsHandlerTestStubs::$options['dataflair_api_health']);
        $this->assertArrayNotHasKey('dataflair_api_health', \SaveSettingsHandlerTestStubs::$transients);
    }
}

final class FakeWebhookSelfRegistrarForSettings implements WebhookSelfRegistrarInterface
{
    public ?string $calledWith = null;
    public bool $result = true;

    public function register(string $receiverUrl): bool
    {
        $this->calledWith = $receiverUrl;

        return $this->result;
    }
}
