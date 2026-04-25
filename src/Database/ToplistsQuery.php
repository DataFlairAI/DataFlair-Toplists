<?php
/**
 * Phase 9.6 (admin UX redesign) — Typed query DTO for the toplists admin list.
 *
 * Consumed by ToplistsRepository::findPaginated(). All values are safe to pass
 * directly to wpdb prepared statements after construction.
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Database;

final class ToplistsQuery
{
    public readonly string $search;
    public readonly string $sortBy;
    public readonly string $sortDir;
    public readonly int    $page;
    public readonly int    $perPage;

    private const ALLOWED_SORT = ['api_toplist_id', 'name', 'slug', 'item_count', 'last_synced'];

    public function __construct(
        string $search  = '',
        string $sortBy  = 'api_toplist_id',
        string $sortDir = 'asc',
        int    $page    = 1,
        int    $perPage = 25
    ) {
        $this->search  = trim($search);
        $this->sortBy  = in_array($sortBy, self::ALLOWED_SORT, true) ? $sortBy : 'api_toplist_id';
        $this->sortDir = strtolower($sortDir) === 'desc' ? 'DESC' : 'ASC';
        $this->page    = max(1, $page);
        $this->perPage = min(100, max(1, $perPage));
    }

    public static function fromArray(array $data): self
    {
        return new self(
            search:  (string) ($data['search']   ?? ''),
            sortBy:  (string) ($data['sort_by']  ?? 'api_toplist_id'),
            sortDir: (string) ($data['sort_dir'] ?? 'asc'),
            page:    (int)    ($data['page']     ?? 1),
            perPage: (int)    ($data['per_page'] ?? 25),
        );
    }
}
