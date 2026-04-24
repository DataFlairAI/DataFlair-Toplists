<?php
/**
 * Narrow persistence contract used by ToplistSyncService.
 *
 * The implementation is the god-class's existing store_toplist_data() and
 * fetch_and_store_toplist() methods (Phase 3 leaves them untouched). Phase 4
 * extracts them fully into the repository layer; the interface means that
 * extraction won't require changing the service.
 *
 * @package DataFlair\Toplists\Sync
 * @since   1.12.1 (Phase 3)
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Sync;

interface ToplistPersisterInterface
{
    /**
     * Store a single toplist record. $rawJson is the canonical
     * {"data": {...}} envelope the v1/v2 adapter expects.
     *
     * @param array<string,mixed> $toplist
     */
    public function store(array $toplist, string $rawJson): bool;

    /**
     * Fetch a single toplist by its /toplists/{id} endpoint and persist.
     */
    public function fetchAndStore(string $endpoint, string $token): bool;
}
