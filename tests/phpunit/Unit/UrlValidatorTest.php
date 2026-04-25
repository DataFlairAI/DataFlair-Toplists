<?php
/**
 * Phase 9.11 — Pins Support\UrlValidator behaviour: TLD whitelist
 * (.test/.local/.localhost/.invalid/.example), localhost / 127.0.0.1
 * / ::1 host-equality, public domain rejection.
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Tests\Unit\Support;

use DataFlair\Toplists\Support\UrlValidator;
use PHPUnit\Framework\TestCase;

require_once DATAFLAIR_PLUGIN_DIR . 'src/Support/UrlValidator.php';

final class UrlValidatorTest extends TestCase
{
    public function test_test_tld_is_local(): void
    {
        $validator = new UrlValidator();
        $this->assertTrue($validator->isLocal('http://strike-odds.test/wp-admin/'));
    }

    public function test_local_tld_is_local(): void
    {
        $validator = new UrlValidator();
        $this->assertTrue($validator->isLocal('http://my-site.local'));
    }

    public function test_invalid_tld_is_local(): void
    {
        $validator = new UrlValidator();
        $this->assertTrue($validator->isLocal('http://stub.invalid'));
    }

    public function test_example_tld_is_local(): void
    {
        $validator = new UrlValidator();
        $this->assertTrue($validator->isLocal('http://docs.example/path'));
    }

    public function test_localhost_host_is_local(): void
    {
        $validator = new UrlValidator();
        $this->assertTrue($validator->isLocal('http://localhost/wp-json/'));
    }

    public function test_loopback_ipv4_is_local(): void
    {
        $validator = new UrlValidator();
        $this->assertTrue($validator->isLocal('http://127.0.0.1:8080'));
    }

    // NOTE: parse_url('http://[::1]') returns host '[::1]' (with brackets),
    // so the literal `$host === '::1'` check never fires. Behaviour preserved
    // verbatim from the pre-Phase-9.11 god-class; documenting here so a
    // future PR can decide whether to strip brackets.

    public function test_public_domain_is_not_local(): void
    {
        $validator = new UrlValidator();
        $this->assertFalse($validator->isLocal('https://sigma.dataflair.ai/api/v1'));
    }

    public function test_subdomain_with_local_substring_is_not_local(): void
    {
        $validator = new UrlValidator();
        $this->assertFalse($validator->isLocal('https://localhost.example.com/api'));
    }
}
