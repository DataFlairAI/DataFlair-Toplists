<?php
/**
 * API contract mismatch detection + persistence (Contract Safety plan P2).
 *
 * When the backend rejects a request with HTTP 409 and error_code
 * "contract_mismatch" (its handshake middleware saw an
 * X-DataFlair-Expected-Contract it cannot serve, or a plugin version below
 * its minimum), sync must stop before touching local data and the admin must
 * see a clear message. This class owns detecting that rejection and the
 * option the admin notice renders from. Backends without the handshake never
 * emit this shape, so on old backends the plugin behaves exactly as before.
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Sync;

final class ContractMismatch
{
    public const OPTION = 'dataflair_contract_mismatch';

    /**
     * Detect a contract-mismatch rejection in an upstream response.
     *
     * Conservative on purpose: only a 409 whose JSON body carries
     * error_code=contract_mismatch counts, so an unrelated proxy or plugin
     * emitting a generic 409 can never pause sync with a misleading notice.
     *
     * @return array{message: string, min_plugin_version: string}|null
     */
    public static function fromResponse(int $statusCode, string $body): ?array
    {
        if ($statusCode !== 409) {
            return null;
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded) || ($decoded['error_code'] ?? null) !== 'contract_mismatch') {
            return null;
        }

        return [
            'message'            => (string) ($decoded['message'] ?? 'The DataFlair API reported a plugin/API contract mismatch.'),
            'min_plugin_version' => (string) ($decoded['min_plugin_version'] ?? ''),
        ];
    }

    /**
     * Persist the mismatch so the admin notice can render it. One option,
     * overwritten on every detection: only the latest mismatch matters.
     *
     * @param array{message: string, min_plugin_version: string} $info
     * @param string $source Which sync stream hit the mismatch ('toplists'|'brands').
     */
    public static function record(array $info, string $url, string $source): void
    {
        update_option(self::OPTION, [
            'message'            => $info['message'],
            'min_plugin_version' => $info['min_plugin_version'],
            'url'                => $url,
            'source'             => $source,
            'plugin_version'     => defined('DATAFLAIR_VERSION') ? DATAFLAIR_VERSION : '',
            'detected_at'        => time(),
        ]);
    }

    /**
     * Called when a sync stream completes successfully: that stream's
     * contract works again. Only clears a mismatch recorded by the SAME
     * stream, so a toplists (v1) success can never hide a still-broken
     * brands (v2) mismatch or vice versa. Pass null to clear regardless.
     */
    public static function clear(?string $source = null): void
    {
        if ($source !== null) {
            $state          = get_option(self::OPTION);
            $recordedSource = is_array($state) ? ($state['source'] ?? null) : null;
            // A record with no source (defensive: record() always sets one)
            // is clearable by any stream.
            if ($recordedSource !== null && $recordedSource !== $source) {
                return;
            }
        }
        delete_option(self::OPTION);
    }
}
