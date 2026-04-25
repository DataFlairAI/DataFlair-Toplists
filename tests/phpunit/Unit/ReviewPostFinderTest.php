<?php
/**
 * Phase 9.9 — Pins ReviewPostFinder slug-tolerant lookup behaviour.
 */

declare(strict_types=1);

namespace {
    if (!class_exists('WP_Post', false)) {
        class WP_Post
        {
            public int $ID = 0;
            public string $post_status = 'publish';
            public string $post_modified = '';

            public function __construct($id = 0, $status = 'publish', $modified = '')
            {
                $this->ID = (int) $id;
                $this->post_status = (string) $status;
                $this->post_modified = (string) $modified;
            }
        }
    }
}

namespace DataFlair\Toplists\Tests\Unit\Frontend\Content {

use Brain\Monkey;
use Brain\Monkey\Functions;
use DataFlair\Toplists\Frontend\Content\ReviewPostFinder;
use Mockery as M;
use PHPUnit\Framework\TestCase;

require_once DATAFLAIR_PLUGIN_DIR . 'src/Frontend/Content/ReviewPostFinder.php';

final class ReviewPostFinderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        global $wpdb;
        $wpdb = M::mock('wpdb');
        $wpdb->posts = 'wp_posts';
        $wpdb->postmeta = 'wp_postmeta';
        $wpdb->shouldReceive('prepare')->andReturnUsing(static function ($sql, ...$args) {
            return $sql;
        });
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        M::close();
        parent::tearDown();
    }

    public function test_returns_null_when_brand_has_no_ids(): void
    {
        $finder = new ReviewPostFinder();
        $this->assertNull($finder->findByBrandMeta([]));
    }

    public function test_returns_published_post_when_join_finds_one(): void
    {
        global $wpdb;
        $wpdb->shouldReceive('get_results')->once()->andReturn([
            (object) ['ID' => 555, 'post_status' => 'publish'],
        ]);

        $expected = new \WP_Post();
        $expected->ID = 555;
        Functions\expect('get_post')
            ->once()
            ->with(555)
            ->andReturn($expected);

        $finder = new ReviewPostFinder();
        $out = $finder->findByBrandMeta(['api_brand_id' => 100]);

        $this->assertSame($expected, $out);
    }

    public function test_falls_back_to_cast_unsigned_when_first_query_empty_and_single_id(): void
    {
        global $wpdb;
        $wpdb->shouldReceive('get_results')
            ->twice()
            ->andReturn([], [(object) ['ID' => 777, 'post_status' => 'publish']]);

        $stub = new \WP_Post();
        $stub->ID = 777;
        Functions\expect('get_post')->once()->with(777)->andReturn($stub);

        $finder = new ReviewPostFinder();
        $out = $finder->findByBrandMeta(['api_brand_id' => 999]);

        $this->assertNotNull($out);
        $this->assertSame(777, $out->ID);
    }

    public function test_returns_first_non_publish_when_no_published_match(): void
    {
        global $wpdb;
        $wpdb->shouldReceive('get_results')->once()->andReturn([
            (object) ['ID' => 100, 'post_status' => 'draft'],
            (object) ['ID' => 101, 'post_status' => 'pending'],
        ]);

        $stub = new \WP_Post();
        $stub->ID = 100;
        Functions\expect('get_post')->once()->with(100)->andReturn($stub);

        $finder = new ReviewPostFinder();
        $out = $finder->findByBrandMeta(['api_brand_id' => 50, 'id' => 51]);

        $this->assertNotNull($out);
        $this->assertSame(100, $out->ID);
    }
}

}
