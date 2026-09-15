<?php
/**
 * Verifies an inbound webhook delivery's HMAC signature.
 *
 * @package DataFlair\Toplists\Webhooks
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Webhooks;

interface WebhookSignatureVerifierInterface
{
    /**
     * True when $signatureHeader is a valid HMAC-SHA256 of $rawBody under $secret.
     */
    public function verify(string $rawBody, string $signatureHeader, string $secret): bool;
}
