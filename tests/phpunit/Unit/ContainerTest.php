<?php
/**
 * Phase 8 — Container contract pin.
 *
 * Locks in:
 *  - lazy resolution (factory fires once, on first get())
 *  - memoisation (second get() returns the exact same instance)
 *  - set() overrides a prior resolution outright
 *  - register() invalidates a memoised instance so the next get() re-resolves
 *  - has() covers both registered factories and pre-set instances
 *  - get() throws a clear RuntimeException for unknown ids
 *  - factories receive the container itself so they can pull sub-deps
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Tests\Unit;

use DataFlair\Toplists\Container;
use PHPUnit\Framework\TestCase;

require_once DATAFLAIR_PLUGIN_DIR . 'src/Container.php';

final class ContainerTest extends TestCase
{
    public function test_get_resolves_a_registered_factory(): void
    {
        $c = new Container();
        $c->register('greeter', static fn() => new \stdClass());

        $instance = $c->get('greeter');

        $this->assertInstanceOf(\stdClass::class, $instance);
    }

    public function test_factory_is_only_invoked_once(): void
    {
        $c      = new Container();
        $calls  = 0;
        $c->register('greeter', static function () use (&$calls) {
            $calls++;
            return new \stdClass();
        });

        $first  = $c->get('greeter');
        $second = $c->get('greeter');

        $this->assertSame($first, $second, 'Container must memoise the first resolution.');
        $this->assertSame(1, $calls, 'Factory must be invoked exactly once.');
    }

    public function test_set_overrides_a_resolved_instance(): void
    {
        $c = new Container();
        $c->register('greeter', static fn() => (object) ['who' => 'factory']);
        $c->get('greeter'); // force a resolution

        $fake = (object) ['who' => 'fake'];
        $c->set('greeter', $fake);

        $this->assertSame($fake, $c->get('greeter'));
    }

    public function test_reregister_invalidates_memoised_instance(): void
    {
        $c = new Container();
        $c->register('greeter', static fn() => (object) ['who' => 'old']);
        $first = $c->get('greeter');

        $c->register('greeter', static fn() => (object) ['who' => 'new']);
        $second = $c->get('greeter');

        $this->assertNotSame($first, $second);
        $this->assertSame('new', $second->who);
    }

    public function test_has_reports_factories_and_resolved_instances(): void
    {
        $c = new Container();
        $this->assertFalse($c->has('nope'));

        $c->register('greeter', static fn() => new \stdClass());
        $this->assertTrue($c->has('greeter'));

        $c->set('preset', new \stdClass());
        $this->assertTrue($c->has('preset'));
    }

    public function test_get_throws_for_unknown_id(): void
    {
        $c = new Container();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/no factory registered for "missing"/');

        $c->get('missing');
    }

    public function test_factory_receives_container_for_sub_dependencies(): void
    {
        $c = new Container();
        $c->register('dep', static fn() => 'dep-value');
        $c->register('svc', static fn(Container $inner) => new class ($inner->get('dep')) {
            public function __construct(public string $dep) {}
        });

        $svc = $c->get('svc');

        $this->assertSame('dep-value', $svc->dep);
    }
}
