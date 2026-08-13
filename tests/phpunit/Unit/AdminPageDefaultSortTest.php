<?php
/**
 * Pins default admin-list sorting for the Toplists and Brands pages.
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Admin\Pages {

    if (!function_exists(__NAMESPACE__ . '\\admin_url')) {
        function admin_url(string $path = '', string $scheme = 'admin'): string
        {
            return 'http://example.test/wp-admin/' . ltrim($path, '/');
        }
    }

    if (!function_exists(__NAMESPACE__ . '\\esc_attr')) {
        function esc_attr($value): string
        {
            return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        }
    }

    if (!function_exists(__NAMESPACE__ . '\\esc_html')) {
        function esc_html($value): string
        {
            return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        }
    }

    if (!function_exists(__NAMESPACE__ . '\\esc_html__')) {
        function esc_html__(string $value): string
        {
            return $value;
        }
    }

    if (!function_exists(__NAMESPACE__ . '\\esc_url')) {
        function esc_url($value): string
        {
            return (string) $value;
        }
    }

    if (!function_exists(__NAMESPACE__ . '\\sanitize_key')) {
        function sanitize_key($value): string
        {
            return preg_replace('/[^a-z0-9_\\-]/', '', strtolower((string) $value)) ?? '';
        }
    }

    if (!function_exists(__NAMESPACE__ . '\\settings_errors')) {
        function settings_errors(): void
        {
        }
    }

    if (!function_exists(__NAMESPACE__ . '\\number_format_i18n')) {
        function number_format_i18n($number): string
        {
            return number_format((float) $number, 0, '.', ',');
        }
    }

    if (!function_exists(__NAMESPACE__ . '\\wp_create_nonce')) {
        function wp_create_nonce(string $action): string
        {
            return 'nonce-' . $action;
        }
    }
}

namespace DataFlair\Toplists\Tests\Unit\Admin {

    use DataFlair\Toplists\Admin\Pages\BrandsPage;
    use DataFlair\Toplists\Admin\Pages\ToplistsListPage;
    use DataFlair\Toplists\Database\BrandsPage as BrandsPageDTO;
    use DataFlair\Toplists\Database\BrandsQuery;
    use DataFlair\Toplists\Database\BrandsRepositoryInterface;
    use Mockery as M;
    use PHPUnit\Framework\TestCase;

    require_once DATAFLAIR_PLUGIN_DIR . 'src/Database/BrandsRepositoryInterface.php';
    require_once DATAFLAIR_PLUGIN_DIR . 'src/Database/BrandsPage.php';
    require_once DATAFLAIR_PLUGIN_DIR . 'src/Database/BrandsQuery.php';
    require_once DATAFLAIR_PLUGIN_DIR . 'src/Admin/Pages/PageInterface.php';
    require_once DATAFLAIR_PLUGIN_DIR . 'src/Admin/Pages/BrandsPage.php';
    require_once DATAFLAIR_PLUGIN_DIR . 'src/Admin/Pages/ToplistsListPage.php';

    final class AdminPageDefaultSortTest extends TestCase
    {
        protected function tearDown(): void
        {
            M::close();
            parent::tearDown();
        }

        public function test_toplists_page_defaults_to_newest_last_synced_rows(): void
        {
            $originalWpdb = $GLOBALS['wpdb'] ?? null;
            $originalGet  = $_GET;

            $wpdb = M::mock('wpdb');
            $wpdb->prefix = 'wp_';
            $orderedByLastSynced = false;
            $wpdb->shouldReceive('get_var')
                ->once()
                ->with('SELECT COUNT(*) FROM wp_dataflair_toplists')
                ->andReturn('0');
            $wpdb->shouldReceive('prepare')
                ->once()
                ->with(M::on(static function (string $sql) use (&$orderedByLastSynced): bool {
                    $orderedByLastSynced = str_contains($sql, 'ORDER BY last_synced DESC, id DESC');
                    return $orderedByLastSynced;
                }), 25, 0)
                ->andReturn('prepared-toplists-query');
            $wpdb->shouldReceive('get_results')
                ->once()
                ->with('prepared-toplists-query')
                ->andReturn([]);
            $wpdb->shouldReceive('get_results')
                ->once()
                ->with(M::on(static fn(string $sql): bool => str_contains($sql, 'ORDER BY name ASC')))
                ->andReturn([]);
            $wpdb->shouldReceive('get_col')->once()->andReturn([]);

            $GLOBALS['wpdb'] = $wpdb;
            $_GET = [];

            ob_start();
            try {
                (new ToplistsListPage(static fn(string $option): string => 'never'))->render();
            } finally {
                ob_end_clean();
                if ($originalWpdb === null) {
                    unset($GLOBALS['wpdb']);
                } else {
                    $GLOBALS['wpdb'] = $originalWpdb;
                }
                $_GET = $originalGet;
            }

            $this->assertTrue($orderedByLastSynced);
        }

        public function test_brands_page_defaults_to_newest_last_synced_rows(): void
        {
            $originalGet = $_GET;

            $repo = $this->createMock(BrandsRepositoryInterface::class);
            $repo->expects($this->once())
                ->method('findPaginated')
                ->with($this->callback(static function (BrandsQuery $query): bool {
                    return $query->sortBy === BrandsQuery::SORT_LAST_SYNCED
                        && $query->sortDir === BrandsQuery::DIR_DESC;
                }))
                ->willReturn(new BrandsPageDTO([], 0, 1, 25));
            $repo->method('collectDistinctValuesForFilter')->willReturn([]);

            $_GET = [];

            ob_start();
            try {
                (new BrandsPage($repo, static fn(string $option): string => 'never'))->render();
            } finally {
                ob_end_clean();
                $_GET = $originalGet;
            }
        }
    }
}
