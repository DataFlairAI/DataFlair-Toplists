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

    public function test_acquire_lock_returns_true_when_get_lock_succeeds(): void
    {
        $wpdb = $this->makeWpdb();
        $wpdb->shouldReceive('get_var')
            ->once()
            ->with(M::on(fn ($sql) => str_contains($sql, 'GET_LOCK') && str_contains($sql, 'dataflair_webhook_')))
            ->andReturn('1');

        $repo = new WebhookEventsRepository($wpdb);
        $this->assertTrue($repo->acquireLock('abc-123'));
    }

    public function test_acquire_lock_returns_false_when_another_connection_holds_it(): void
    {
        // GET_LOCK() returns 0 (not acquired within the timeout) rather than
        // an error - a real caller holds it, i.e. a genuine concurrent
        // duplicate delivery is being processed right now.
        $wpdb = $this->makeWpdb();
        $wpdb->shouldReceive('get_var')->once()->andReturn('0');

        $repo = new WebhookEventsRepository($wpdb);
        $this->assertFalse($repo->acquireLock('abc-123'));
    }

    public function test_acquire_lock_returns_false_on_a_null_result(): void
    {
        // GET_LOCK() returns NULL on error (e.g. out of memory for the lock
        // table) - fail closed, same as a contended lock, rather than treat
        // an error as "acquired".
        $wpdb = $this->makeWpdb();
        $wpdb->shouldReceive('get_var')->once()->andReturn(null);

        $repo = new WebhookEventsRepository($wpdb);
        $this->assertFalse($repo->acquireLock('abc-123'));
    }

    public function test_release_lock_calls_release_lock_for_the_same_delivery_id(): void
    {
        $wpdb = $this->makeWpdb();
        $wpdb->shouldReceive('query')
            ->once()
            ->with(M::on(fn ($sql) => str_contains($sql, 'RELEASE_LOCK') && str_contains($sql, 'dataflair_webhook_')));

        $repo = new WebhookEventsRepository($wpdb);
        $repo->releaseLock('abc-123');

        $this->addToAssertionCount(1); // Mockery expectation verified on tearDown
    }

    public function test_lock_name_is_stable_for_the_same_delivery_id(): void
    {
        // acquireLock() and releaseLock() must hash the same delivery_id to
        // the same lock name, or a release would never free the lock its
        // own acquire took. makeWpdb()'s prepare() stub is a plain
        // vsprintf() (no SQL quoting), so the substituted lock name appears
        // unquoted between the function's opening paren and the next comma
        // (GET_LOCK) or closing paren (RELEASE_LOCK).
        $wpdb = $this->makeWpdb();
        $seenNames = [];
        $wpdb->shouldReceive('get_var')->once()->andReturnUsing(function ($sql) use (&$seenNames) {
            preg_match('/GET_LOCK\(([^,]+),/', $sql, $m);
            $seenNames[] = $m[1] ?? null;
            return '1';
        });
        $wpdb->shouldReceive('query')->once()->andReturnUsing(function ($sql) use (&$seenNames) {
            preg_match('/RELEASE_LOCK\(([^)]+)\)/', $sql, $m);
            $seenNames[] = $m[1] ?? null;
            return true;
        });

        $repo = new WebhookEventsRepository($wpdb);
        $repo->acquireLock('abc-123');
        $repo->releaseLock('abc-123');

        $this->assertCount(2, $seenNames);
        $this->assertNotNull($seenNames[0]);
        $this->assertSame($seenNames[0], $seenNames[1]);
    }
}
