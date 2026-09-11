<?php
/**
 * Sync request value object. Immutable input to the sync services.
 *
 * @package DataFlair\Toplists\Sync
 * @since   1.12.1 (Phase 3)
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Sync;

final class SyncRequest
{
    public const TYPE_TOPLISTS = 'toplists';
    public const TYPE_BRANDS   = 'brands';

    public function __construct(
        public readonly string $type,
        public readonly int $page,
        public readonly int $perPage,
        public readonly float $budgetSeconds,
        /** @var int[]|null When set, restricts a brands sync to just these api_brand_ids. */
        public readonly ?array $ids = null
    ) {}

    public static function toplists(int $page, int $perPage = 5, float $budgetSeconds = 60.0): self
    {
        return new self(self::TYPE_TOPLISTS, $page, $perPage, $budgetSeconds);
    }

    public static function brands(int $page, int $perPage = 25, float $budgetSeconds = 25.0): self
    {
        return new self(self::TYPE_BRANDS, $page, $perPage, $budgetSeconds);
    }

    /**
     * "Re-sync selected" - never wipes local rows first (unlike a full sync's
     * page-1 delete), it only fetches and upserts the given ids.
     *
     * @param int[] $ids
     */
    public static function brandsByIds(array $ids, int $page = 1, int $perPage = 25, float $budgetSeconds = 25.0): self
    {
        return new self(self::TYPE_BRANDS, $page, $perPage, $budgetSeconds, $ids);
    }
}
