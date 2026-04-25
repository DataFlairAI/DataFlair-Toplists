<?php
/**
 * Phase 8 — Plugin bootstrap seam contract pin.
 *
 * Locks in:
 *  - `Plugin::boot()` is idempotent — two calls return the same instance and
 *    do not double-register hooks.
 *  - `Plugin::instance()` returns the booted instance (or null if not booted).
 *  - `Plugin::resetForTests()` nulls the static singleton (test-only seam).
 *  - The container is wired with a `logger` service.
 *  - Booting keeps the legacy `DataFlair_Toplists::get_instance()` alive.
 *
 * The test pre-declares a minimal `\DataFlair_Toplists` fake in the global
 * namespace before requiring `Plugin.php`, so we never have to load the
 * 5,500-line plugin file to exercise the bootstrap seam.
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Filters;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/PluginBootTestStubs.php';
require_once DATAFLAIR_PLUGIN_DIR . 'src/Container.php';
require_once DATAFLAIR_PLUGIN_DIR . 'includes/Logging/LoggerInterface.php';
require_once DATAFLAIR_PLUGIN_DIR . 'includes/Logging/NullLogger.php';
require_once DATAFLAIR_PLUGIN_DIR . 'includes/Logging/ErrorLogLogger.php';
require_once DATAFLAIR_PLUGIN_DIR . 'includes/Logging/SentryLogger.php';
require_once DATAFLAIR_PLUGIN_DIR . 'includes/Logging/LoggerFactory.php';
require_once DATAFLAIR_PLUGIN_DIR . 'src/Plugin.php';

use DataFlair\Toplists\Container;
use DataFlair\Toplists\Logging\LoggerFactory;
use DataFlair\Toplists\Logging\LoggerInterface;
use DataFlair\Toplists\Plugin;

final class PluginBootTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        Plugin::resetForTests();
        LoggerFactory::reset();
        \PluginBootTestStubs::reset();

        // LoggerFactory::get() will run through this filter; return the default
        // so the factory resolves to ErrorLogLogger without touching error_log
        // during the test (we only assert instanceof).
        Filters\expectApplied('dataflair_logger')
            ->andReturnUsing(static fn($default) => $default);
        Filters\expectApplied('dataflair_logger_level')
            ->andReturnUsing(static fn($default) => $default);
    }

    protected function tearDown(): void
    {
        Plugin::resetForTests();
        LoggerFactory::reset();
        \PluginBootTestStubs::reset();
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_boot_returns_plugin_instance(): void
    {
        $plugin = Plugin::boot();

        $this->assertInstanceOf(Plugin::class, $plugin);
    }

    public function test_boot_is_idempotent(): void
    {
        $first  = Plugin::boot();
        $second = Plugin::boot();

        $this->assertSame($first, $second, 'Plugin::boot() must be idempotent.');
        $this->assertSame(
            1,
            \PluginBootTestStubs::$getInstanceCalls,
            'DataFlair_Toplists::get_instance() should construct the legacy singleton exactly once across repeated boot() calls.'
        );
    }

    public function test_instance_is_null_before_boot(): void
    {
        $this->assertNull(Plugin::instance());
    }

    public function test_instance_returns_booted_singleton(): void
    {
        $booted   = Plugin::boot();
        $accessed = Plugin::instance();

        $this->assertSame($booted, $accessed);
    }

    public function test_reset_for_tests_clears_the_singleton(): void
    {
        Plugin::boot();
        $this->assertNotNull(Plugin::instance());

        Plugin::resetForTests();

        $this->assertNull(Plugin::instance());
    }

    public function test_container_exposes_logger_service(): void
    {
        $plugin    = Plugin::boot();
        $container = $plugin->container();

        $this->assertInstanceOf(Container::class, $container);
        $this->assertTrue($container->has('logger'));
        $this->assertInstanceOf(LoggerInterface::class, $container->get('logger'));
    }

    public function test_downstream_can_override_a_container_service(): void
    {
        $plugin = Plugin::boot();
        $fake   = new class implements LoggerInterface {
            public function emergency(string $message, array $context = []): void {}
            public function alert(string $message, array $context = []): void {}
            public function critical(string $message, array $context = []): void {}
            public function error(string $message, array $context = []): void {}
            public function warning(string $message, array $context = []): void {}
            public function notice(string $message, array $context = []): void {}
            public function info(string $message, array $context = []): void {}
            public function debug(string $message, array $context = []): void {}
        };

        $plugin->container()->set('logger', $fake);

        $this->assertSame($fake, $plugin->container()->get('logger'));
    }
}
