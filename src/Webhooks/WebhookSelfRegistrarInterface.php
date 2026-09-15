<?php
/**
 * Contract for self-service webhook registration, so callers (e.g.
 * SaveSettingsHandler) can depend on the abstraction rather than the
 * concrete HTTP-calling implementation.
 *
 * @package DataFlair\Toplists\Webhooks
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Webhooks;

interface WebhookSelfRegistrarInterface
{
    public function register(string $receiverUrl): bool;
}
