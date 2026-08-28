<?php
/**
 * API Contract Safety — contract version awareness.
 *
 * The backend publishes what it serves at `/api/vN/meta`: the contract
 * revision (semver, additive-only within a version), which contracts it
 * supports, and the latest published plugin version. Without this, a tenant
 * has no way to learn that the API moved except by something breaking.
 *
 * This class fetches that, remembers what the admin has already seen, and
 * reports what is new. Nothing here can fail a sync: a backend without the
 * endpoint (every version before the handshake shipped) simply yields null
 * and the plugin behaves exactly as it did before.
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Sync;

use DataFlair\Toplists\Http\HttpClientInterface;

final class ContractVersion
{
    public const OPTION = 'dataflair_contract_version';

    /**
     * Read `/meta` for the contract the given base URL points at.
     *
     * @return array{rev: string, supported: array<int,string>, using: string, latest_plugin: string}|null
     */
    public static function fetch(HttpClientInterface $http, string $baseUrl, string $token): ?array
    {
        $base     = rtrim($baseUrl, '/');
        $response = $http->get($base . '/meta', $token, 5, 0);

        if (is_wp_error($response) || (int) wp_remote_retrieve_response_code($response) !== 200) {
            return null;
        }

        $decoded = json_decode((string) wp_remote_retrieve_body($response), true);
        if (!is_array($decoded)) {
            return null;
        }

        $supported = [];
        foreach ((array) ($decoded['supported_contracts'] ?? []) as $contract) {
            if (is_string($contract) && preg_match('/^v\d+$/', $contract)) {
                $supported[] = $contract;
            }
        }

        // `using` is derived from the URL WE requested, never from the
        // response. A backend reporting the newest contract it serves would
        // otherwise suppress the very "a newer version exists" notice this
        // feature is for, and would tell the admin they are on a version they
        // are not.
        $using = preg_match('#/api/(v\d+)$#i', $base, $m) ? strtolower($m[1]) : '';
        if ($using === '') {
            return null;
        }

        return [
            'rev'           => is_scalar($decoded['contract_rev'] ?? null) ? (string) $decoded['contract_rev'] : '',
            'supported'     => $supported,
            'using'         => $using,
            'latest_plugin' => is_scalar($decoded['latest_plugin_version'] ?? null) ? (string) $decoded['latest_plugin_version'] : '',
        ];
    }

    /** Persist the latest reading, preserving what the admin has acknowledged. */
    public static function record(array $meta): void
    {
        $stored = get_option(self::OPTION);
        $stored = is_array($stored) ? $stored : [];

        $rev       = (string) ($meta['rev'] ?? '');
        $supported = (array) ($meta['supported'] ?? []);

        // Seed the baseline HERE, on the first recording, not on the first
        // admin page view. A site synced by cron for a fortnight before
        // anyone opens wp-admin would otherwise baseline against whatever
        // the API had drifted to and never announce the move.
        $seenRev       = (string) ($stored['seen_rev'] ?? '');
        $seenSupported = (array) ($stored['seen_supported'] ?? []);
        if ($seenRev === '' && !array_key_exists('seen_supported', $stored)) {
            $seenRev       = $rev;
            $seenSupported = $supported;
        }

        update_option(self::OPTION, [
            'rev'            => $rev,
            'supported'      => $supported,
            'using'          => (string) ($meta['using'] ?? ''),
            'latest_plugin'  => (string) ($meta['latest_plugin'] ?? ''),
            'seen_rev'       => $seenRev,
            'seen_supported' => $seenSupported,
            'checked_at'     => time(),
        ]);
    }

    /**
     * Tolerant read of the recorded state for display surfaces (the health
     * probe, diagnostics). Mirrors ContractMismatch::entries(): a corrupted
     * option can never fatal or warn its way into a monitoring endpoint.
     *
     * @return array{using: string, rev: string, supported: array<int,string>}
     */
    public static function profile(): array
    {
        $state = get_option(self::OPTION);
        $state = is_array($state) ? $state : [];

        $supported = [];
        foreach ((array) ($state['supported'] ?? []) as $contract) {
            if (is_string($contract) && $contract !== '') {
                $supported[] = $contract;
            }
        }

        return [
            'using'     => is_scalar($state['using'] ?? null) ? (string) $state['using'] : '',
            'rev'       => is_scalar($state['rev'] ?? null) ? (string) $state['rev'] : '',
            'supported' => array_values($supported),
        ];
    }

    /**
     * What the admin has not seen yet, or null when there is nothing new.
     *
     * Two kinds of news, both worth surfacing and neither an error:
     *   - the contract revision moved (additive change inside this version)
     *   - a newer API version exists than the one this plugin is pinned to
     *
     * @return array{rev: string, previous: string, newer_versions: array<int,string>, using: string}|null
     */
    public static function pending(): ?array
    {
        $state = get_option(self::OPTION);
        if (!is_array($state) || ($state['rev'] ?? '') === '') {
            return null;
        }

        // Read-only: the baseline is seeded by record(), so asking what is
        // pending never consumes the answer. A second caller (diagnostics,
        // a status command) must be able to ask the same question safely.
        if (!array_key_exists('seen_supported', $state)) {
            return null; // pre-baseline state written by an older version
        }

        $rev      = (string) $state['rev'];
        $seenRev  = (string) ($state['seen_rev'] ?? '');
        $using    = (string) ($state['using'] ?? '');
        $newer    = self::newerThan($using, (array) ($state['supported'] ?? []));
        $seenNew  = self::newerThan($using, (array) ($state['seen_supported'] ?? []));

        $revIsNew   = $seenRev !== '' && $rev !== $seenRev;
        $versionNew = array_values(array_diff($newer, $seenNew)) !== [];

        if (!$revIsNew && !$versionNew) {
            return null;
        }

        return [
            'rev'            => $rev,
            'previous'       => $seenRev,
            'newer_versions' => $newer,
            'using'          => $using,
        ];
    }

    /** Mark the current reading as seen. */
    public static function acknowledge(): void
    {
        $state = get_option(self::OPTION);
        if (!is_array($state)) {
            return;
        }

        $state['seen_rev']       = (string) ($state['rev'] ?? '');
        $state['seen_supported'] = (array) ($state['supported'] ?? []);
        update_option(self::OPTION, $state);
    }

    /**
     * Supported contracts numerically newer than the one in use.
     *
     * @param array<int,string> $supported
     * @return array<int,string>
     */
    private static function newerThan(string $using, array $supported): array
    {
        $current = (int) ltrim($using, 'vV');
        if ($current <= 0) {
            return [];
        }

        $newer = [];
        foreach ($supported as $contract) {
            if ((int) ltrim((string) $contract, 'vV') > $current) {
                $newer[] = (string) $contract;
            }
        }
        sort($newer);

        return $newer;
    }
}
