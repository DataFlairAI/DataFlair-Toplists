<?php
/**
 * Phase 9.6 (admin UX redesign) — Return item-level detail for a toplist accordion row.
 *
 * Fetches the item summary from the stored data blob, resolves brand names and
 * logos via BrandsRepository, and flags each item as 'synced' or 'partial'.
 *
 * Input:  { api_toplist_id: int }
 * Output: { success: true, data: { items: [...], last_synced: string, api_toplist_id: int } }
 *
 * Item shape:
 *   { position, brand_id, brand_name, bonus_offer, status: 'synced'|'partial' }
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Admin\Ajax;

use DataFlair\Toplists\Admin\AjaxHandlerInterface;
use DataFlair\Toplists\Database\BrandsRepositoryInterface;
use DataFlair\Toplists\Database\ToplistsRepositoryInterface;

final class ToplistAccordionDetailsHandler implements AjaxHandlerInterface
{
    public function __construct(
        private ToplistsRepositoryInterface $toplists,
        private BrandsRepositoryInterface   $brands
    ) {}

    public function handle(array $request): array
    {
        $id = isset($request['api_toplist_id']) ? (int) $request['api_toplist_id'] : 0;
        if ($id <= 0) {
            return ['success' => false, 'data' => ['message' => 'Invalid api_toplist_id.']];
        }

        $row = $this->toplists->findByApiToplistId($id);
        if ($row === null) {
            return ['success' => false, 'data' => ['message' => 'Toplist not found.']];
        }

        $item_summaries = $this->toplists->findItemSummaryByApiToplistId($id);

        // Resolve brand names in one batch query.
        $brand_ids = array_values(array_unique(array_column($item_summaries, 'brand_id')));
        $brand_map = [];
        if (!empty($brand_ids)) {
            foreach ($this->brands->findManyByApiBrandIds($brand_ids) as $b) {
                $brand_map[(int) $b['api_brand_id']] = [
                    'name' => (string) ($b['name'] ?? ''),
                ];
            }
        }

        $items = [];
        foreach ($item_summaries as $item) {
            $brand_id   = $item['brand_id'];
            $brand_name = $brand_map[$brand_id]['name'] ?? $item['brand_name'] ?? '';
            $offer      = $item['bonus_offer'];
            $status     = ($brand_name !== '' && $offer !== '') ? 'synced' : 'partial';
            $items[]    = [
                'position'    => $item['position'],
                'brand_id'    => $brand_id,
                'brand_name'  => $brand_name,
                'bonus_offer' => $offer,
                'status'      => $status,
            ];
        }

        return ['success' => true, 'data' => [
            'api_toplist_id' => $id,
            'items'          => $items,
            'last_synced'    => (string) ($row['last_synced'] ?? ''),
        ]];
    }
}
