<?php
/**
 * Phase 9.11 — Pins Http\ApiErrorFormatter behaviour across the
 * status-code switch: 401 (Basic vs Bearer vs HTML), 403, 404,
 * 419, 429, 500, 502/503/504, default.
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Tests\Unit\Http;

use Brain\Monkey;
use Brain\Monkey\Functions;
use DataFlair\Toplists\Http\ApiErrorFormatter;
use PHPUnit\Framework\TestCase;

require_once DATAFLAIR_PLUGIN_DIR . 'src/Http/ApiErrorFormatter.php';

final class ApiErrorFormatterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        Functions\when('get_option')->alias(function ($key, $default = '') {
            return $default;
        });
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_basic_auth_401_with_no_creds_prompts_for_credentials(): void
    {
        $formatter = new ApiErrorFormatter();
        $result = $formatter->format(
            401,
            '',
            ['www-authenticate' => 'Basic realm="Staging"'],
            'https://staging.example/api/v1/toplists'
        );

        $this->assertStringContainsString('HTTP Basic Auth required (401)', $result);
        $this->assertStringContainsString('staging.example', $result);
    }

    public function test_basic_auth_401_with_stored_creds_says_creds_were_rejected(): void
    {
        Functions\when('get_option')->alias(function ($key, $default = '') {
            if ($key === 'dataflair_http_auth_user') return 'admin';
            return $default;
        });

        $formatter = new ApiErrorFormatter();
        $result = $formatter->format(
            401,
            '',
            ['www-authenticate' => 'Basic realm="Staging"'],
            'https://staging.example/api/v1/toplists'
        );

        $this->assertStringContainsString('HTTP Basic Auth failed (401)', $result);
        $this->assertStringContainsString('rejected by the web server', $result);
    }

    public function test_bearer_401_returns_token_guidance(): void
    {
        $formatter = new ApiErrorFormatter();
        $result = $formatter->format(
            401,
            '{"message":"Unauthenticated."}',
            ['www-authenticate' => 'Bearer'],
            'https://api.example/api/v1/toplists'
        );

        $this->assertStringContainsString('API authentication failed (401)', $result);
        $this->assertStringContainsString('Unauthenticated.', $result);
        $this->assertStringContainsString('dfp_', $result);
    }

    public function test_html_401_explains_web_server_block(): void
    {
        $formatter = new ApiErrorFormatter();
        $result = $formatter->format(
            401,
            '<html>...</html>',
            ['content-type' => 'text/html; charset=utf-8'],
            'https://staging.example/api/v1/toplists'
        );

        $this->assertStringContainsString('returned an HTML page', $result);
        $this->assertStringContainsString('HTTP Basic Auth', $result);
    }

    public function test_403_includes_api_message(): void
    {
        $formatter = new ApiErrorFormatter();
        $result = $formatter->format(403, '{"message":"No tenant access"}', [], 'https://api.example/api/v1/toplists');

        $this->assertStringContainsString('Access forbidden (403)', $result);
        $this->assertStringContainsString('No tenant access', $result);
    }

    public function test_404_quotes_the_url(): void
    {
        $formatter = new ApiErrorFormatter();
        $url = 'https://api.example/api/v1/missing';
        $result = $formatter->format(404, '', [], $url);

        $this->assertStringContainsString('Endpoint not found (404)', $result);
        $this->assertStringContainsString($url, $result);
    }

    public function test_419_is_csrf_message(): void
    {
        $formatter = new ApiErrorFormatter();
        $this->assertStringContainsString(
            'CSRF token mismatch (419)',
            $formatter->format(419, '', [], 'https://api.example/api/v1/toplists')
        );
    }

    public function test_429_includes_rate_limit_guidance(): void
    {
        $formatter = new ApiErrorFormatter();
        $result = $formatter->format(429, '{"message":"Too many"}', [], 'https://api.example/api/v1/toplists');
        $this->assertStringContainsString('Rate limited (429)', $result);
        $this->assertStringContainsString('Too many', $result);
    }

    public function test_500_uses_api_message_or_body_excerpt(): void
    {
        $formatter = new ApiErrorFormatter();
        $result = $formatter->format(500, 'fatal error', [], 'https://api.example/api/v1/toplists');
        $this->assertStringContainsString('Server error (500)', $result);
        $this->assertStringContainsString('fatal error', $result);
    }

    public function test_503_collapses_to_unavailable_message(): void
    {
        $formatter = new ApiErrorFormatter();
        $this->assertStringContainsString(
            'Server unavailable (503)',
            $formatter->format(503, '', [], 'https://api.example/api/v1/toplists')
        );
    }

    public function test_504_collapses_to_unavailable_message(): void
    {
        $formatter = new ApiErrorFormatter();
        $this->assertStringContainsString(
            'Server unavailable (504)',
            $formatter->format(504, '', [], 'https://api.example/api/v1/toplists')
        );
    }

    public function test_default_branch_quotes_status_and_body(): void
    {
        $formatter = new ApiErrorFormatter();
        $result = $formatter->format(418, 'short', [], 'https://api.example/api/v1/toplists');
        $this->assertStringContainsString('Unexpected HTTP 418', $result);
        $this->assertStringContainsString('short', $result);
    }
}
