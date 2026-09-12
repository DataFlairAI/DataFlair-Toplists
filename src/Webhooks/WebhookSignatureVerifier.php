<?php
/**
 * Verifies inbound webhook deliveries from dataflair.ai-v2.
 *
 * Direct mirror of PloiWebhookController::signatureIsValid() on the
 * Laravel side (HMAC-SHA256 of the raw body, hash_equals() comparison,
 * fails closed when unconfigured) - just the receiving end of the same
 * scheme DeliverWebhookJob signs with when sending.
 *
 * @package DataFlair\Toplists\Webhooks
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Webhooks;

final class WebhookSignatureVerifier
{
    public function verify(string $rawBody, string $signatureHeader, string $secret): bool
    {
        if ($secret === '' || $signatureHeader === '') {
            return false;
        }

        $expected = hash_hmac('sha256', $rawBody, $secret);

        return hash_equals($expected, $signatureHeader);
    }
}
