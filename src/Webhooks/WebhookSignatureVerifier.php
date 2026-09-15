<?php
/**
 * Verifies inbound webhook deliveries from dataflair.ai-v2.
 *
 * Direct mirror of DeliverWebhookJob::attemptDelivery() on the Laravel
 * side (HMAC-SHA256 of the raw body, compared here with hash_equals(),
 * fails closed when unconfigured) - this is the receiving end of the same
 * scheme that job signs with when sending.
 *
 * @package DataFlair\Toplists\Webhooks
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Webhooks;

final class WebhookSignatureVerifier implements WebhookSignatureVerifierInterface
{
    /**
     * True when $signatureHeader is a valid HMAC-SHA256 of $rawBody under $secret.
     */
    public function verify(string $rawBody, string $signatureHeader, string $secret): bool
    {
        if ($secret === '' || $signatureHeader === '') {
            return false;
        }

        $expected = hash_hmac('sha256', $rawBody, $secret);

        // SECURITY: hash_equals(), not ===. A short-circuiting string
        // comparison leaks how many leading bytes matched via response
        // timing, letting an attacker recover the correct signature one
        // byte at a time. hash_equals() runs in constant time regardless
        // of where the strings first differ.
        return hash_equals($expected, $signatureHeader);
    }
}
