<?php
/**
 * WebhookEventsRepositoryTest — idempotency ledger for the webhook
 * receiver. Mirrors ToplistsRepositoryTest's Mockery('wpdb') pattern.
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Tests\Unit\Webhooks;

use Brain\Monkey;
use Brain\Monkey\Functions;
use DataFlair\Toplists\Webhooks\WebhookEventsRepository;
use Mockery as M;
use PHPUnit\Framework\TestCase;

require_once DATAFLAIR_PLUGIN_DIR . 'src/Webhooks/WebhookEventsRepositoryInterface.php';
require_once DATAFLAIR_PLUGIN_DIR . 'src/Webhooks/WebhookEventsRepository.php';

final class WebhookEventsRepositoryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        Functions\when('current_time')->justReturn('2026-09-12 12:00:00');
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        M::close();
        parent::tearDown();
    }

    private function makeWpdb(): object
    {
        $wpdb = M::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive('prepare')->andReturnUsing(function ($sql, ...$args) {
            $flat = (count($args) === 1 && is_array($args[0])) ? $args[0] : $args;
            return vsprintf(str_replace(['%d', '%s', '%f'], ['%s', '%s', '%s'], $sql), $flat);
        });

        return $wpdb;
    }

    public function test_has_processed_returns_true_for_a_known_delivery_id(): void
    {
        $wpdb = $this->makeWpdb();
        $wpdb->shouldReceive('get_row')->once()->andReturn(['delivery_id' => 'abc-123']);

        $repo = new WebhookEventsRepository($wpdb);
        $this->assertTrue($repo->hasProcessed('abc-123'));
    }

    public function test_has_processed_returns_false_for_an_unknown_delivery_id(): void
    {
        $wpdb = $this->makeWpdb();
        $wpdb->shouldReceive('get_row')->once()->andReturn(null);

        $repo = new WebhookEventsRepository($wpdb);
        $this->assertFalse($repo->hasProcessed('never-seen'));
    }

    public function test_record_processed_inserts_a_row_and_returns_true(): void
    {
        $wpdb = $this->makeWpdb();
        $wpdb->shouldReceive('insert')
            ->once()
            ->with(
                'wp_dataflair_webhook_events',
                M::on(function ($data) {
                    return $data['delivery_id'] === 'abc-123'
                        && $data['event_type'] === 'toplist.published'
                        && !empty($data['processed_at']);
                }),
                ['%s', '%s', '%s']
            )
            ->andReturn(1);

        $repo = new WebhookEventsRepository($wpdb);
        $this->assertTrue($repo->recordProcessed('abc-123', 'toplist.published'));
    }

    public function test_record_processed_returns_false_when_the_insert_fails(): void
    {
        // e.g. a race: two deliveries with the same id hit the PK constraint.
        $wpdb = $this->makeWpdb();
        $wpdb->shouldReceive('insert')->once()->andReturn(false);

        $repo = new WebhookEventsRepository($wpdb);
        $this->assertFalse($repo->recordProcessed('abc-123', 'toplist.published'));
    }
}
