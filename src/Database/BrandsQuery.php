<?php
/**
 * Phase 9.6 (admin UX redesign) — typed query DTO for the Brands list view.
 *
 * Carries search + multi-select filter + sort + pagination state from the
 * BrandsPage / BrandsQueryHandler boundary into BrandsRepository::findPaginated().
 * Keeps repository signatures small and avoids associative-array drift.
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Database;

final class BrandsQuery
{
    public const SORT_NAME            = 'name';
    public const SORT_OFFERS_COUNT    = 'offers_count';
    public const SORT_TRACKERS_COUNT  = 'trackers_count';
    public const SORT_LAST_SYNCED     = 'last_synced';
    public const SORT_API_BRAND_ID    = 'api_brand_id';

    public const DIR_ASC  = 'ASC';
    public const DIR_DESC = 'DESC';

    public string $search        = '';
    /** @var string[] */
    public array $licenses        = [];
    /** @var string[] */
    public array $geos            = [];
    /** @var string[] */
    public array $payments        = [];
    /** @var string[] */
    public array $productTypes    = [];
    public ?bool $disabled        = null; // null = both, true = only disabled, false = only enabled
    public string $sortBy        = self::SORT_NAME;
    public string $sortDir       = self::DIR_ASC;
    public int $page             = 1;
    public int $perPage          = 25;

    /**
     * Build from an associative array (typically the AJAX request payload).
     *
     * @param array<string, mixed> $input
     */
    public static function fromArray(array $input): self
    {
        $q = new self();
        $q->search       = isset($input['search']) ? trim((string) $input['search']) : '';
        $q->licenses     = self::asStringList($input['licenses']     ?? []);
        $q->geos         = self::asStringList($input['geos']         ?? []);
        $q->payments     = self::asStringList($input['payments']     ?? []);
        $q->productTypes = self::asStringList($input['product_types'] ?? []);
        if (array_key_exists('disabled', $input) && $input['disabled'] !== null && $input['disabled'] !== '') {
            $q->disabled = (bool) $input['disabled'];
        }
        $q->sortBy  = self::normalizeSort((string) ($input['sort_by'] ?? self::SORT_NAME));
        $q->sortDir = strtoupper((string) ($input['sort_dir'] ?? self::DIR_ASC)) === self::DIR_DESC ? self::DIR_DESC : self::DIR_ASC;
        $q->page    = max(1, (int) ($input['page'] ?? 1));
        $q->perPage = min(200, max(1, (int) ($input['per_page'] ?? 25)));
        return $q;
    }

    /**
     * @param mixed $raw
     * @return string[]
     */
    private static function asStringList($raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $v) {
            $s = trim((string) $v);
            if ($s !== '') {
                $out[] = $s;
            }
        }
        return array_values(array_unique($out));
    }

    private static function normalizeSort(string $raw): string
    {
        $allowed = [
            self::SORT_NAME,
            self::SORT_OFFERS_COUNT,
            self::SORT_TRACKERS_COUNT,
            self::SORT_LAST_SYNCED,
            self::SORT_API_BRAND_ID,
        ];
        return in_array($raw, $allowed, true) ? $raw : self::SORT_NAME;
    }

    public function offset(): int
    {
        return ($this->page - 1) * $this->perPage;
    }
}
