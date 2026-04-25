<?php
/**
 * Phase 9.9 — Pins ReviewPostManager get-or-create behaviour and meta writes.
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

use DataFlair\Toplists\Frontend\Content\ReviewPostFinder;
use DataFlair\Toplists\Frontend\Content\ReviewPostManager;
use DataFlair\Toplists\Logging\NullLogger;
use DataFlair\Toplists\Tests\ReviewContent\ReviewContentStubs as S;
use Mockery as M;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/ReviewContentTestStubs.php';
require_once DATAFLAIR_PLUGIN_DIR . 'includes/Logging/LoggerInterface.php';
require_once DATAFLAIR_PLUGIN_DIR . 'includes/Logging/NullLogger.php';
require_once DATAFLAIR_PLUGIN_DIR . 'src/Frontend/Content/ReviewPostFinder.php';
require_once DATAFLAIR_PLUGIN_DIR . 'src/Frontend/Content/ReviewPostManager.php';

final class ReviewPostManagerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        S::reset();
    }

    protected function tearDown(): void
    {
        M::close();
        parent::tearDown();
    }

    public function test_returns_false_when_review_post_type_missing(): void
    {
        S::$postTypeExists = static fn() => false;

        $finder = M::mock(ReviewPostFinder::class);
        $manager = new ReviewPostManager($finder, new NullLogger());

        $this->assertFalse($manager->getOrCreate(['name' => 'Betway'], []));
    }

    public function test_returns_existing_published_post_by_slug(): void
    {
        $existing = new \WP_Post();
        $existing->ID = 999;
        $existing->post_status = 'publish';
        S::$getPageByPath = static fn() => $existing;

        $finder = M::mock(ReviewPostFinder::class);
        $finder->shouldNotReceive('findByBrandMeta');

        $manager = new ReviewPostManager($finder, new NullLogger());
        $this->assertSame(999, $manager->getOrCreate(['slug' => 'betway', 'name' => 'Betway'], []));
    }

    public function test_falls_through_to_meta_finder_when_slug_match_is_draft(): void
    {
        $draft = new \WP_Post();
        $draft->ID = 111;
        $draft->post_status = 'draft';
        S::$getPageByPath = static fn() => $draft;

        $live = new \WP_Post();
        $live->ID = 222;
        $live->post_status = 'publish';

        $finder = M::mock(ReviewPostFinder::class);
        $finder->shouldReceive('findByBrandMeta')->once()->andReturn($live);

        $manager = new ReviewPostManager($finder, new NullLogger());
        $this->assertSame(222, $manager->getOrCreate(['slug' => 'betway', 'name' => 'Betway'], []));
    }

    public function test_returns_slug_draft_when_no_published_match_anywhere(): void
    {
        $draft = new \WP_Post();
        $draft->ID = 111;
        $draft->post_status = 'draft';
        S::$getPageByPath = static fn() => $draft;

        $finder = M::mock(ReviewPostFinder::class);
        $finder->shouldReceive('findByBrandMeta')->once()->andReturn(null);

        $manager = new ReviewPostManager($finder, new NullLogger());
        $this->assertSame(111, $manager->getOrCreate(['slug' => 'betway', 'name' => 'Betway'], []));
    }

    public function test_creates_draft_review_with_brand_meta_when_nothing_exists(): void
    {
        S::$wpInsertPost = static fn() => 444;

        $finder = M::mock(ReviewPostFinder::class);
        $finder->shouldReceive('findByBrandMeta')->once()->andReturn(null);

        $manager = new ReviewPostManager($finder, new NullLogger());
        $brand = [
            'slug' => 'betway',
            'name' => 'Betway',
            'api_brand_id' => 100,
            'logo' => ['rectangular' => 'https://cdn.example/logo.png'],
            'licenses' => ['MGA'],
        ];
        $item = [
            'rating' => 4.5,
            'offer' => ['tracking_url' => 'https://aff.example/betway', 'offerText' => '100% up to $200'],
            'paymentMethods' => ['Visa', 'Mastercard'],
        ];

        $this->assertSame(444, $manager->getOrCreate($brand, $item));

        $this->assertCount(1, S::$insertedPosts);
        $this->assertSame('review', S::$insertedPosts[0]['post_type']);
        $this->assertSame('draft', S::$insertedPosts[0]['post_status']);
        $this->assertSame('betway', S::$insertedPosts[0]['post_name']);

        $writes = [];
        foreach (S::$postMetaWrites as $w) {
            $writes[$w['key']] = $w['value'];
        }
        $this->assertSame(100, $writes['_review_brand_id']);
        $this->assertSame('Betway', $writes['_review_brand_name']);
        $this->assertSame('https://cdn.example/logo.png', $writes['_review_logo']);
        $this->assertSame('https://aff.example/betway', $writes['_review_url']);
        $this->assertSame(4.5, $writes['_review_rating']);
        $this->assertSame('100% up to $200', $writes['_review_bonus']);
        $this->assertSame('Visa, Mastercard', $writes['_review_payments']);
        $this->assertSame('MGA', $writes['_review_licenses']);
    }
}

}
