<?php
/**
 * Phase 6 — pins the H12 pagination + lean/full shape contract of
 * GET /wp-json/dataflair/v1/toplists/{id}/casinos.
 *
 * This is execution-path coverage for what RestCasinosPaginationTest used to
 * scan structurally. The integration test kept a structural contract on the
 * god-class file shape; now the controller lives in its own class and we can
 * test actual behaviour:
 *
 *   - Unknown toplist id → WP_Error with status 404.
 *   - Toplist whose payload has zero items → empty response + 0/0 headers.
 *   - Default lean shape: `{id, name, rating, offer_text, logo_url}`.
 *   - `?full=1` returns the legacy verbose shape
 *     (`{itemId, brandId, position, brandName, brandSlug, pros, cons}`).
 *   - Pagination: per_page is clamped to 1..100, page is clamped to >=1,
 *     and `array_slice()` drives the window.
 *   - X-WP-Total + X-WP-TotalPages headers set on every response.
 *   - Supports payload shapes `{data.items}`, `{data.listItems}`, `{listItems}`.
 *   - Brand metadata lookup uses the injected closure (no $wpdb coupling).
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Tests\Unit\Rest\Controllers;

use DataFlair\Toplists\Database\ToplistsRepositoryInterface;
use DataFlair\Toplists\Logging\NullLogger;
use DataFlair\Toplists\Rest\Controllers\CasinosController;
use PHPUnit\Framework\TestCase;

require_once DATAFLAIR_PLUGIN_DIR . 'includes/Logging/LoggerInterface.php';
require_once DATAFLAIR_PLUGIN_DIR . 'includes/Logging/NullLogger.php';
require_once DATAFLAIR_PLUGIN_DIR . 'src/Database/ToplistsRepositoryInterface.php';
require_once DATAFLAIR_PLUGIN_DIR . 'src/Rest/Controllers/CasinosController.php';
require_once __DIR__ . '/RestControllerTestStubs.php';

final class CasinosControllerTest extends TestCase
{
    public function test_unknown_toplist_returns_wp_error_404(): void
    {
        $controller = $this->buildController(fn(int $id) => null);
        $result     = $controller->listForToplist($this->request(['id' => 99, 'page' => 1, 'per_page' => 20, 'full' => 0]));

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('not_found', $result->get_error_code());
        $this->assertSame(404, $result->data['status']);
    }

    public function test_empty_payload_returns_empty_array_and_zero_headers(): void
    {
        $controller = $this->buildController(fn(int $id) => [
            'data' => json_encode(['data' => ['items' => []]]),
        ]);

        $response = $controller->listForToplist($this->request(['id' => 1, 'page' => 1, 'per_page' => 20, 'full' => 0]));

        $this->assertSame([], $response->get_data());
        $this->assertSame('0', $response->headers['X-WP-Total']);
        $this->assertSame('0', $response->headers['X-WP-TotalPages']);
    }

    public function test_default_lean_shape_projects_to_id_name_rating_offer_logo(): void
    {
        $payload = [
            'data' => [
                'items' => [
                    [
                        'id'     => 101,
                        'rating' => 4.7,
                        'brand'  => ['id' => 501, 'name' => 'Acme Casino'],
                        'offer'  => ['offerText' => '100% up to €500'],
                    ],
                ],
            ],
        ];
        $controller = $this->buildController(
            fn(int $id) => ['data' => json_encode($payload)],
            /* prefetch */ fn(array $items): array => ['ids' => []],
            /* lookup   */ fn(array $brand, array $map): ?object => (object) ['local_logo_url' => 'https://cdn.example/acme.png'],
        );

        $response = $controller->listForToplist($this->request(['id' => 1, 'page' => 1, 'per_page' => 20, 'full' => 0]));

        $this->assertSame(
            [[
                'id'         => 101,
                'name'       => 'Acme Casino',
                'rating'     => 4.7,
                'offer_text' => '100% up to €500',
                'logo_url'   => 'https://cdn.example/acme.png',
            ]],
            $response->get_data()
        );
        $this->assertSame('1', $response->headers['X-WP-Total']);
        $this->assertSame('1', $response->headers['X-WP-TotalPages']);
    }

    public function test_full_param_preserves_legacy_verbose_shape(): void
    {
        $payload = [
            'data' => [
                'items' => [
                    [
                        'id'       => 202,
                        'position' => 2,
                        'brand'    => ['id' => 777, 'name' => 'Zeta Bet'],
                        'pros'     => ['Great UI'],
                        'cons'     => ['Slow withdrawals'],
                    ],
                ],
            ],
        ];
        $controller = $this->buildController(fn(int $id) => ['data' => json_encode($payload)]);

        $response = $controller->listForToplist($this->request(['id' => 1, 'page' => 1, 'per_page' => 20, 'full' => 1]));

        $this->assertSame(
            [[
                'itemId'    => 202,
                'brandId'   => 777,
                'position'  => 2,
                'brandName' => 'Zeta Bet',
                'brandSlug' => 'zeta-bet',
                'pros'      => ['Great UI'],
                'cons'      => ['Slow withdrawals'],
            ]],
            $response->get_data()
        );
    }

    public function test_pagination_slices_items_for_the_current_page_and_sets_total_pages(): void
    {
        $items = [];
        for ($i = 1; $i <= 25; $i++) {
            $items[] = [
                'id'     => $i,
                'rating' => 4.0,
                'brand'  => ['id' => 100 + $i, 'name' => 'Brand ' . $i],
                'offer'  => ['offerText' => 'offer ' . $i],
            ];
        }
        $controller = $this->buildController(
            fn(int $id) => ['data' => json_encode(['data' => ['items' => $items]])],
            fn(array $items): array => [],
            fn(array $brand, array $map): ?object => null,
        );

        $pageTwo = $controller->listForToplist($this->request(['id' => 1, 'page' => 2, 'per_page' => 10, 'full' => 0]));

        $data = $pageTwo->get_data();
        $this->assertCount(10, $data);
        $this->assertSame(11, $data[0]['id']);
        $this->assertSame(20, $data[9]['id']);
        $this->assertSame('25', $pageTwo->headers['X-WP-Total']);
        $this->assertSame('3',  $pageTwo->headers['X-WP-TotalPages']);
    }

    public function test_per_page_is_clamped_between_1_and_100(): void
    {
        $items = array_map(
            fn(int $i) => [
                'id'     => $i,
                'rating' => 4.0,
                'brand'  => ['id' => $i, 'name' => 'B' . $i],
                'offer'  => ['offerText' => 'x'],
            ],
            range(1, 150)
        );
        $controller = $this->buildController(
            fn(int $id) => ['data' => json_encode(['data' => ['items' => $items]])],
            fn(array $items): array => [],
            fn(array $brand, array $map): ?object => null,
        );

        $over = $controller->listForToplist($this->request(['id' => 1, 'page' => 1, 'per_page' => 999, 'full' => 0]));
        $this->assertCount(100, $over->get_data(), 'per_page > 100 must be clamped to 100');

        $under = $controller->listForToplist($this->request(['id' => 1, 'page' => 1, 'per_page' => 0, 'full' => 0]));
        $this->assertCount(1, $under->get_data(), 'per_page < 1 must be clamped to 1');
    }

    public function test_accepts_alternate_payload_shapes_data_listItems_and_listItems(): void
    {
        $item = [
            'id'        => 1,
            'rating'    => 4.0,
            'brandName' => 'EditionsBrand',
            'brandId'   => 42,
            'offer'     => ['offerText' => 'editions'],
        ];

        $dataListItems = $this->buildController(
            fn(int $id) => ['data' => json_encode(['data' => ['listItems' => [$item]]])],
            fn(array $items): array => [],
            fn(array $brand, array $map): ?object => null,
        );
        $topLevelListItems = $this->buildController(
            fn(int $id) => ['data' => json_encode(['listItems' => [$item]])],
            fn(array $items): array => [],
            fn(array $brand, array $map): ?object => null,
        );

        $a = $dataListItems->listForToplist($this->request(['id' => 1, 'page' => 1, 'per_page' => 20, 'full' => 0]));
        $b = $topLevelListItems->listForToplist($this->request(['id' => 1, 'page' => 1, 'per_page' => 20, 'full' => 0]));

        $this->assertSame('EditionsBrand', $a->get_data()[0]['name']);
        $this->assertSame('EditionsBrand', $b->get_data()[0]['name']);
    }

    public function test_items_without_a_brand_name_are_skipped(): void
    {
        $payload = [
            'data' => [
                'items' => [
                    ['id' => 1, 'rating' => 4.0, 'offer' => ['offerText' => 'no-brand']],
                    ['id' => 2, 'rating' => 4.0, 'brand' => ['id' => 7, 'name' => 'Keepme'], 'offer' => ['offerText' => 'ok']],
                ],
            ],
        ];
        $controller = $this->buildController(
            fn(int $id) => ['data' => json_encode($payload)],
            fn(array $items): array => [],
            fn(array $brand, array $map): ?object => null,
        );

        $response = $controller->listForToplist($this->request(['id' => 1, 'page' => 1, 'per_page' => 20, 'full' => 0]));

        $data = $response->get_data();
        $this->assertCount(1, $data);
        $this->assertSame('Keepme', $data[0]['name']);
    }

    private function buildController(
        \Closure $findByApiToplistId,
        ?\Closure $prefetch = null,
        ?\Closure $lookup = null
    ): CasinosController {
        $repo = new class($findByApiToplistId) implements ToplistsRepositoryInterface {
            public function __construct(private \Closure $finder) {}
            public function findByApiToplistId(int $api_toplist_id): ?array { return ($this->finder)($api_toplist_id); }
            public function findBySlug(string $slug): ?array { return null; }
            public function upsert(array $row) { return false; }
            public function deleteByApiToplistId(int $api_toplist_id): bool { return true; }
            public function collectGeoNames(): array { return []; }
            public function listAllForOptions(): array { return []; }
            public function countAll(): int { return 0; }
        };

        return new CasinosController(
            $repo,
            $prefetch ?? fn(array $items): array => [],
            $lookup   ?? fn(array $brand, array $map): ?object => null,
            new NullLogger()
        );
    }

    /**
     * @param array<string,mixed> $params
     */
    private function request(array $params): \WP_REST_Request
    {
        return new \WP_REST_Request($params);
    }
}
