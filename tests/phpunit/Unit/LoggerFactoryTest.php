<?php
/**
 * Phase 1 — LoggerFactory contract pin.
 *
 * Locks in:
 *  - default implementation is ErrorLogLogger
 *  - `dataflair_logger` filter swaps the implementation
 *  - a non-LoggerInterface filter return is rejected and the default kept
 *  - the factory caches per-request (filter runs once per reset)
 *  - ErrorLogLogger respects the configured minimum level
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Tests\Unit\Logging;

use Brain\Monkey;
use Brain\Monkey\Filters;
use DataFlair\Toplists\Logging\ErrorLogLogger;
use DataFlair\Toplists\Logging\LoggerFactory;
use DataFlair\Toplists\Logging\LoggerInterface;
use DataFlair\Toplists\Logging\NullLogger;
use PHPUnit\Framework\TestCase;

require_once DATAFLAIR_PLUGIN_DIR . 'includes/Logging/LoggerInterface.php';
require_once DATAFLAIR_PLUGIN_DIR . 'includes/Logging/NullLogger.php';
require_once DATAFLAIR_PLUGIN_DIR . 'includes/Logging/ErrorLogLogger.php';
require_once DATAFLAIR_PLUGIN_DIR . 'includes/Logging/SentryLogger.php';
require_once DATAFLAIR_PLUGIN_DIR . 'includes/Logging/LoggerFactory.php';

final class LoggerFactoryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        LoggerFactory::reset();
    }

    protected function tearDown(): void
    {
        LoggerFactory::reset();
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_default_is_error_log_logger(): void
    {
        Filters\expectApplied('dataflair_logger')
            ->once()
            ->andReturnUsing(static fn($default) => $default);

        Filters\expectApplied('dataflair_logger_level')
            ->andReturnUsing(static fn($default) => $default);

        $logger = LoggerFactory::get();
        $this->assertInstanceOf(ErrorLogLogger::class, $logger);
    }

    public function test_filter_swaps_implementation(): void
    {
        Filters\expectApplied('dataflair_logger_level')
            ->andReturnUsing(static fn($default) => $default);

        Filters\expectApplied('dataflair_logger')
            ->once()
            ->andReturn(new NullLogger());

        $logger = LoggerFactory::get();
        $this->assertInstanceOf(NullLogger::class, $logger);
    }

    public function test_filter_returning_non_logger_falls_back_to_default(): void
    {
        Filters\expectApplied('dataflair_logger_level')
            ->andReturnUsing(static fn($default) => $default);

        Filters\expectApplied('dataflair_logger')
            ->once()
            ->andReturn('not-a-logger');

        $logger = LoggerFactory::get();
        $this->assertInstanceOf(ErrorLogLogger::class, $logger);
    }

    public function test_factory_caches_per_request(): void
    {
        Filters\expectApplied('dataflair_logger_level')
            ->andReturnUsing(static fn($default) => $default);

        // Only one filter application expected despite two get() calls.
        Filters\expectApplied('dataflair_logger')
            ->once()
            ->andReturnUsing(static fn($default) => $default);

        $a = LoggerFactory::get();
        $b = LoggerFactory::get();
        $this->assertSame($a, $b);
    }

    public function test_null_logger_is_fully_silent(): void
    {
        $logger = new NullLogger();
        foreach (['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug'] as $method) {
            $this->assertNull($logger->$method('msg'));
        }
    }

    public function test_error_log_logger_respects_minimum_level(): void
    {
        Filters\expectApplied('dataflair_logger_level')
            ->andReturnUsing(static fn($default) => $default);

        $logger = new ErrorLogLogger('warning');

        // Below threshold: no error_log call should happen. We test this
        // by capturing via error_log destination (syslog by default;
        // we can ini_set an isolated file).
        $tmp = tempnam(sys_get_temp_dir(), 'dflogtest');
        $prev = ini_set('error_log', $tmp);

        try {
            $logger->info('below-threshold');
            $logger->warning('at-threshold');
            $logger->error('above-threshold');
        } finally {
            if ($prev !== false) {
                ini_set('error_log', $prev);
            }
        }

        $contents = file_get_contents($tmp);
        @unlink($tmp);

        $this->assertIsString($contents);
        $this->assertStringNotContainsString('below-threshold', $contents);
        $this->assertStringContainsString('at-threshold', $contents);
        $this->assertStringContainsString('above-threshold', $contents);
        $this->assertStringContainsString('[DataFlair][WARNING]', $contents);
        $this->assertStringContainsString('[DataFlair][ERROR]', $contents);
    }

    public function test_error_log_logger_appends_context_as_json(): void
    {
        Filters\expectApplied('dataflair_logger_level')
            ->andReturnUsing(static fn($default) => $default);

        $logger = new ErrorLogLogger('debug');

        $tmp = tempnam(sys_get_temp_dir(), 'dflogtest');
        $prev = ini_set('error_log', $tmp);

        try {
            $logger->info('sync.started', ['page' => 3, 'per_page' => 10]);
        } finally {
            if ($prev !== false) {
                ini_set('error_log', $prev);
            }
        }

        $contents = file_get_contents($tmp);
        @unlink($tmp);

        $this->assertStringContainsString('sync.started', $contents);
        $this->assertStringContainsString('"page":3', $contents);
        $this->assertStringContainsString('"per_page":10', $contents);
    }

    public function test_logger_interface_contract_shape(): void
    {
        $iface = new \ReflectionClass(LoggerInterface::class);
        $methods = array_map(fn($m) => $m->getName(), $iface->getMethods());
        sort($methods);
        $this->assertSame(
            ['alert', 'critical', 'debug', 'emergency', 'error', 'info', 'notice', 'warning'],
            $methods
        );
    }
}
