<?php
/**
 * Self-service webhook registration. Called when the "Enable webhook sync"
 * checkbox is turned on (SaveSettingsHandler) - generates the local secret
 * once and calls POST {base}/webhooks/subscribe with it and this site's
 * receiver URL, authenticated with the plugin's existing API token (the
 * same one every other outbound call already uses).
 *
 * @package DataFlair\Toplists\Webhooks
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Webhooks;

use DataFlair\Toplists\Http\ApiBaseUrlDetector;
use DataFlair\Toplists\Http\HttpClientInterface;

final class WebhookSelfRegistrar implements WebhookSelfRegistrarInterface
{
    public function __construct(
        private HttpClientInterface $http,
        private ApiBaseUrlDetector $baseUrlDetector,
        private string $token
    ) {
    }

    public function register(string $receiverUrl): bool
    {
        // SECURITY: detectConfiguredHost() (not detect()), matching
        // WebhookController's receiver-side guard - detect() never returns
        // empty, it falls back to a hard-coded DataFlair host when
        // unconfigured, so an unguarded call here would silently subscribe
        // this site's receiver URL and freshly generated secret to that
        // fallback host instead of refusing outright.
        if ($this->baseUrlDetector->detectConfiguredHost(false) === null) {
            return false;
        }

        $secret = $this->secret();
        $subscribeUrl = rtrim($this->baseUrlDetector->detect(false), '/') . '/webhooks/subscribe';

        $response = $this->http->post($subscribeUrl, $this->token, [
            'url'    => $receiverUrl,
            'secret' => $secret,
        ]);

        if (is_wp_error($response)) {
            return false;
        }

        $status = (int) wp_remote_retrieve_response_code($response);

        return $status >= 200 && $status < 300;
    }

    /**
     * Generated once, on first use, and never regenerated on a later save -
     * only an explicit rotation (not built in this slice) would change it.
     */
    private function secret(): string
    {
        $existing = trim((string) get_option('dataflair_webhook_secret', ''));
        if ($existing !== '') {
            return $existing;
        }

        $generated = bin2hex(random_bytes(32));
        update_option('dataflair_webhook_secret', $generated);

        return $generated;
    }
}
