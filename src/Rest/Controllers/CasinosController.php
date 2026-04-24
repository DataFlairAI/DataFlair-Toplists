<?php
/**
 * Controller for GET /wp-json/dataflair/v1/toplists/{id}/casinos.
 *
 * H12 (Phase 0B) contract preserved verbatim:
 *   - `?per_page` default 20, max 100 (clamped server-side).
 *   - `?page` default 1, 1-indexed.
 *   - Default lean shape: `{id, name, rating, offer_text, logo_url}`.
 *   - `?full=1` preserves the legacy verbose shape the block editor consumes.
 *   - Emits `X-WP-Total` + `X-WP-TotalPages` pagination headers.
 *
 * Phase 6 — extracted from god-class `get_toplist_casinos_rest()`.
 * Brand metadata prefetch is still owned by the god-class (for now) and
 * injected via closures so the controller can stay zero-coupled to `$wpdb`.
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Rest\Controllers;

use DataFlair\Toplists\Database\ToplistsRepositoryInterface;
use DataFlair\Toplists\Logging\LoggerInterface;

final class CasinosController
{
    /**
     * @param \Closure(array<int,array<string,mixed>>):array<string,array<int|string,object>> $prefetchBrandMetas
     * @param \Closure(array<string,mixed>,array<string,array<int|string,object>>):?object   $lookupBrandMeta
     */
    public function __construct(
        private ToplistsRepositoryInterface $repo,
        private \Closure $prefetchBrandMetas,
        private \Closure $lookupBrandMeta,
        private LoggerInterface $logger
    ) {}

    /**
     * @return \WP_REST_Response|\WP_Error
     */
    public function listForToplist($request)
    {
        $toplist_id = (int) ($request['id'] ?? 0);
        $page       = max(1, (int) $request->get_param('page'));
        $per_page   = min(100, max(1, (int) $request->get_param('per_page')));
        $full       = (int) $request->get_param('full') === 1;

        $toplist = $this->repo->findByApiToplistId($toplist_id);
        if ($toplist === null) {
            return new \WP_Error('not_found', 'Toplist not found', ['status' => 404]);
        }

        $payload = json_decode((string) ($toplist['data'] ?? ''), true);

        $items = null;
        if (isset($payload['data']['items'])) {
            $items = $payload['data']['items'];
        } elseif (isset($payload['data']['listItems'])) {
            $items = $payload['data']['listItems'];
        } elseif (isset($payload['listItems'])) {
            $items = $payload['listItems'];
        }

        if (empty($items) || !is_array($items)) {
            $response = rest_ensure_response([]);
            $response->header('X-WP-Total', '0');
            $response->header('X-WP-TotalPages', '0');
            return $response;
        }

        $total       = count($items);
        $total_pages = (int) ceil($total / $per_page);
        $offset      = ($page - 1) * $per_page;
        $page_items  = array_slice($items, $offset, $per_page);

        $brand_meta_map = $full ? null : ($this->prefetchBrandMetas)($page_items);

        $casinos = [];
        foreach ($page_items as $item) {
            $brand_name = '';
            if (isset($item['brand']['name'])) {
                $brand_name = $item['brand']['name'];
            } elseif (isset($item['brandName'])) {
                $brand_name = $item['brandName'];
            }
            if ($brand_name === '') {
                continue;
            }

            $brand_id = 0;
            if (isset($item['brand']['id'])) {
                $brand_id = (int) $item['brand']['id'];
            } elseif (isset($item['brandId'])) {
                $brand_id = (int) $item['brandId'];
            }

            if ($full) {
                $casinos[] = [
                    'itemId'    => isset($item['id']) ? (int) $item['id'] : 0,
                    'brandId'   => $brand_id,
                    'position'  => $item['position'] ?? 0,
                    'brandName' => $brand_name,
                    'brandSlug' => sanitize_title($brand_name),
                    'pros'      => !empty($item['pros']) ? $item['pros'] : [],
                    'cons'      => !empty($item['cons']) ? $item['cons'] : [],
                ];
                continue;
            }

            $rating     = isset($item['rating']) ? (float) $item['rating'] : 0.0;
            $offer      = isset($item['offer']) && is_array($item['offer']) ? $item['offer'] : [];
            $offer_text = isset($offer['offerText']) ? (string) $offer['offerText'] : '';

            $logo_url  = '';
            $brand_row = [
                'api_brand_id' => $brand_id,
                'name'         => $brand_name,
                'slug'         => sanitize_title($brand_name),
            ];
            $meta = ($this->lookupBrandMeta)($brand_row, $brand_meta_map ?: []);
            if ($meta !== null && !empty($meta->local_logo_url)) {
                $logo_url = (string) $meta->local_logo_url;
            }

            $casinos[] = [
                'id'         => isset($item['id']) ? (int) $item['id'] : 0,
                'name'       => $brand_name,
                'rating'     => $rating,
                'offer_text' => $offer_text,
                'logo_url'   => $logo_url,
            ];
        }

        $response = rest_ensure_response($casinos);
        $response->header('X-WP-Total', (string) $total);
        $response->header('X-WP-TotalPages', (string) $total_pages);
        return $response;
    }
}
