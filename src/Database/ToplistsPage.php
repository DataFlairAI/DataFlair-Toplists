<?php
/**
 * Phase 9.6 (admin UX redesign) — Typed result DTO for a paginated toplist query.
 *
 * Returned by ToplistsRepository::findPaginated(). Rows are plain arrays;
 * callers must not mutate this object.
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Database;

final class ToplistsPage
{
    /**
     * @param array<int,array<string,mixed>> $rows
     */
    public function __construct(
        public readonly array $rows,
        public readonly int   $total,
        public readonly int   $page,
        public readonly int   $perPage
    ) {}

    public function pageCount(): int
    {
        return $this->perPage > 0 ? (int) ceil($this->total / $this->perPage) : 0;
    }
}
