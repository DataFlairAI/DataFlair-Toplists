<?php
/**
 * Phase 9.11 — Pins Support\UrlTransformer behaviour: rewrite http://
 * to https:// for non-local hosts; leave local URLs untouched.
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Tests\Unit\Support;

use DataFlair\Toplists\Support\UrlTransformer;
use DataFlair\Toplists\Support\UrlValidator;
use PHPUnit\Framework\TestCase;

require_once DATAFLAIR_PLUGIN_DIR . 'src/Support/UrlValidator.php';
require_once DATAFLAIR_PLUGIN_DIR . 'src/Support/UrlTransformer.php';

final class UrlTransformerTest extends TestCase
{
    public function test_http_public_url_is_upgraded_to_https(): void
    {
        $transformer = new UrlTransformer(new UrlValidator());
        $this->assertSame(
            'https://sigma.dataflair.ai/api/v1',
            $transformer->maybeForceHttps('http://sigma.dataflair.ai/api/v1')
        );
    }

    public function test_https_public_url_is_left_alone(): void
    {
        $transformer = new UrlTransformer(new UrlValidator());
        $this->assertSame(
            'https://sigma.dataflair.ai/api/v1',
            $transformer->maybeForceHttps('https://sigma.dataflair.ai/api/v1')
        );
    }

    public function test_local_test_tld_is_left_as_http(): void
    {
        $transformer = new UrlTransformer(new UrlValidator());
        $this->assertSame(
            'http://strike-odds.test/api/v1',
            $transformer->maybeForceHttps('http://strike-odds.test/api/v1')
        );
    }

    public function test_localhost_is_left_as_http(): void
    {
        $transformer = new UrlTransformer(new UrlValidator());
        $this->assertSame(
            'http://localhost:8080/wp-json/',
            $transformer->maybeForceHttps('http://localhost:8080/wp-json/')
        );
    }

    public function test_uppercase_http_scheme_is_normalised(): void
    {
        $transformer = new UrlTransformer(new UrlValidator());
        $this->assertSame(
            'https://example.com/path',
            $transformer->maybeForceHttps('HTTP://example.com/path')
        );
    }
}
