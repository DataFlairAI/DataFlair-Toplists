<?php
/**
 * WebhookSignatureVerifierTest — mirrors DeliverWebhookJob's outbound
 * signing on the dataflair.ai-v2 side exactly: hash_hmac('sha256', $body,
 * $secret), hash_equals() comparison, fails closed when unconfigured.
 * Direct counterpart of PloiWebhookController::signatureIsValid() on the
 * Laravel side (also HMAC-SHA256 + hash_equals), just the receiving end.
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Tests\Unit\Webhooks;

use DataFlair\Toplists\Webhooks\WebhookSignatureVerifier;
use PHPUnit\Framework\TestCase;

require_once DATAFLAIR_PLUGIN_DIR . 'src/Webhooks/WebhookSignatureVerifier.php';

final class WebhookSignatureVerifierTest extends TestCase
{
    public function test_accepts_a_correctly_signed_body(): void
    {
        $body      = '{"event":"toplist.published"}';
        $secret    = 'shared-secret-value';
        $signature = hash_hmac('sha256', $body, $secret);

        $this->assertTrue((new WebhookSignatureVerifier())->verify($body, $signature, $secret));
    }

    public function test_rejects_a_signature_computed_with_the_wrong_secret(): void
    {
        $body      = '{"event":"toplist.published"}';
        $signature = hash_hmac('sha256', $body, 'the-real-secret');

        $this->assertFalse((new WebhookSignatureVerifier())->verify($body, $signature, 'a-different-secret'));
    }

    public function test_rejects_when_the_body_was_tampered_with_after_signing(): void
    {
        $signature = hash_hmac('sha256', '{"event":"toplist.published"}', 'shared-secret');

        $this->assertFalse((new WebhookSignatureVerifier())->verify('{"event":"brand.updated"}', $signature, 'shared-secret'));
    }

    public function test_rejects_an_empty_signature_header(): void
    {
        $body = '{"event":"toplist.published"}';

        $this->assertFalse((new WebhookSignatureVerifier())->verify($body, '', 'shared-secret'));
    }

    public function test_fails_closed_when_no_secret_is_configured(): void
    {
        $body      = '{"event":"toplist.published"}';
        $signature = hash_hmac('sha256', $body, '');

        $this->assertFalse((new WebhookSignatureVerifier())->verify($body, $signature, ''));
    }
}
