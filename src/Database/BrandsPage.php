<?php
/**
 * Result envelope for {@see BrandsRepository::findPaginated()}.
 *
 * Holds the page of rows, the total row count matching the filter set, and
 * the offset/per_page that produced the slice. Page metadata is computed
 * by the consumer (BrandsPage admin UI) to keep this DTO trivially testable.
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Database;

final class BrandsPage
{
    /**
     * @param array<int, array<string, mixed>> $rows
     */
    public function __construct(
        public readonly array $rows,
        public readonly int $total,
        public readonly int $page,
        public readonly int $perPage
    ) {}

    public function pageCount(): int
    {
        if ($this->total <= 0 || $this->perPage <= 0) {
            return 0;
        }
        return (int) ceil($this->total / $this->perPage);
    }
}
