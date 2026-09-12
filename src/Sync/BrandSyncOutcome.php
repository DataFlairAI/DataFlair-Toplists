<?php
/**
 * Result of BrandSyncService::syncOne(). Deliberately not SyncResult — that
 * class mirrors the paginated AJAX handler response shape (page/lastPage/
 * nextPage), which has no meaningful values for a single-brand fetch.
 *
 * @package DataFlair\Toplists\Sync
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Sync;

final class BrandSyncOutcome
{
    private function __construct(
        public readonly string $status,
        public readonly int $apiBrandId,
        public readonly string $message = ''
    ) {
    }

    public static function active(int $apiBrandId): self
    {
        return new self('active', $apiBrandId);
    }

    public static function inactive(int $apiBrandId): self
    {
        return new self('inactive', $apiBrandId);
    }

    public static function gone(int $apiBrandId): self
    {
        return new self('gone', $apiBrandId);
    }

    public static function failed(int $apiBrandId, string $message): self
    {
        return new self('failed', $apiBrandId, $message);
    }
}
