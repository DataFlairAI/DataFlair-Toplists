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
 *
 * State is one entry PER SYNC STREAM (toplists runs v1, brands may opt into
 * v2), so a toplists success can never hide a still-broken brands mismatch
 * or vice versa.
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Sync;

final class ContractMismatch
{
    public const OPTION = 'dataflair_contract_mismatch';

    /** Upstream text is untrusted: cap what we store and render. */
    private const MAX_MESSAGE_LENGTH = 300;

    /**
     * Detect a contract-mismatch rejection in an upstream response.
     *
     * Conservative on purpose: only a 409 whose JSON body carries
     * error_code=contract_mismatch counts, so an unrelated proxy or plugin
     * emitting a generic 409 can never pause sync with a misleading notice.
     * The message/min fields are sanitized here because they later reach
     * admin notices and AJAX responses rendered in wp-admin.
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

        $message = isset($decoded['message']) && is_scalar($decoded['message'])
            ? substr(trim(strip_tags((string) $decoded['message'])), 0, self::MAX_MESSAGE_LENGTH)
            : '';
        if ($message === '') {
            $message = 'The DataFlair API reported a plugin/API contract mismatch.';
        }

        $min = isset($decoded['min_plugin_version']) && is_scalar($decoded['min_plugin_version'])
            ? substr(trim(strip_tags((string) $decoded['min_plugin_version'])), 0, 32)
            : '';

        return ['message' => $message, 'min_plugin_version' => $min];
    }

    /**
     * The one admin-facing sentence for a mismatch, used identically by every
     * surface that reports it (bulk sync, per-ID resync, brands sync).
     *
     * @param array{message?: string, min_plugin_version?: string} $info
     */
    public static function describe(array $info): string
    {
        return 'DataFlair API contract mismatch: ' . (string) ($info['message'] ?? '')
            . ' ' . self::whatToDo((string) ($info['min_plugin_version'] ?? ''))
            . ' Your site continues to show the last synced data.';
    }

    /**
     * The action sentence every contract failure ends with. Two outcomes only:
     * a version mismatch the admin fixes by updating, or an API-side change
     * they cannot fix and should report. Never leave them without a next step.
     */
    public static function whatToDo(string $minPluginVersion): string
    {
        if ($minPluginVersion !== '') {
            return 'Update the DataFlair Toplists plugin to version ' . $minPluginVersion . ' or newer.';
        }

        // Name the actor. "until the API is fixed" leaves the reader guessing
        // whether they are the one who has to fix something.
        return 'This is a change on the DataFlair side, not a problem with your site or its settings. '
            . 'Send this message to DataFlair support. Syncing again will not help until DataFlair fixes it.';
    }

    /**
     * Persist a mismatch for one sync stream. Each stream owns its slot;
     * recording toplists state never disturbs a recorded brands state.
     *
     * @param array{message: string, min_plugin_version: string} $info
     * @param string $source 'toplists'|'brands'
     */
    public static function record(array $info, string $url, string $source): void
    {
        $state = get_option(self::OPTION);
        $state = is_array($state) ? $state : [];

        $state[$source] = [
            'message'            => (string) ($info['message'] ?? ''),
            'min_plugin_version' => (string) ($info['min_plugin_version'] ?? ''),
            'url'                => $url,
            'source'             => $source,
            'plugin_version'     => defined('DATAFLAIR_VERSION') ? DATAFLAIR_VERSION : '',
            'detected_at'        => time(),
        ];

        update_option(self::OPTION, $state);
    }

    /**
     * Called when one stream's sync completes cleanly: that stream's contract
     * works again. Other streams' entries are left untouched, and when
     * nothing is recorded no write is issued at all (this runs on every
     * successful sync).
     */
    public static function clear(string $source): void
    {
        $state = get_option(self::OPTION);
        if (!is_array($state) || !array_key_exists($source, $state)) {
            return;
        }

        unset($state[$source]);

        if ($state === []) {
            delete_option(self::OPTION);
        } else {
            update_option(self::OPTION, $state);
        }
    }

    /**
     * All recorded mismatches, keyed by source. Tolerates garbage shapes so
     * a corrupted option can never fatal the admin notice or health probe.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function entries(): array
    {
        $state = get_option(self::OPTION);
        if (!is_array($state)) {
            return [];
        }

        $entries = [];
        foreach ($state as $source => $entry) {
            if (is_array($entry) && isset($entry['message']) && is_string($entry['message']) && $entry['message'] !== '') {
                $entries[(string) $source] = $entry;
            }
        }

        return $entries;
    }
}
