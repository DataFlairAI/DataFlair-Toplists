<?php
/**
 * ActiveLogSource contract pin.
 *
 * Verifies the dispatch on the active logger (LoggerFactory::get()):
 *  - ErrorLogLogger: WP_DEBUG_LOG guard, preference for the live
 *    ini_get('error_log') destination over the WP_DEBUG_LOG/WP_CONTENT_DIR
 *    convention, and the not-readable guard.
 *  - FileLogger: not-readable guard, and inclusion of the rotated backup
 *    (<path>.1) alongside the live file when both exist.
 *  - NullLogger gets a distinct "disabled" message; any other unsupported
 *    logger gets the generic "not supported" message.
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Tests\Unit\Support;

use Brain\Monkey;
use Brain\Monkey\Filters;
use DataFlair\Toplists\Logging\ErrorLogLogger;
use DataFlair\Toplists\Logging\LoggerFactory;
use DataFlair\Toplists\Logging\NullLogger;
use DataFlair\Toplists\Logging\SentryLogger;
use DataFlair\Toplists\Support\ActiveLogSource;
use PHPUnit\Framework\TestCase;

require_once DATAFLAIR_PLUGIN_DIR . 'includes/Logging/LoggerInterface.php';
require_once DATAFLAIR_PLUGIN_DIR . 'includes/Logging/NullLogger.php';
require_once DATAFLAIR_PLUGIN_DIR . 'includes/Logging/SentryLogger.php';
require_once DATAFLAIR_PLUGIN_DIR . 'includes/Logging/ErrorLogLogger.php';
require_once DATAFLAIR_PLUGIN_DIR . 'includes/Logging/FileLogger.php';
require_once DATAFLAIR_PLUGIN_DIR . 'includes/Logging/LoggerFactory.php';
require_once DATAFLAIR_PLUGIN_DIR . 'includes/Support/ActiveLogSource.php';

final class ActiveLogSourceTest extends TestCase
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

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_error_log_logger_reports_notice_when_wp_debug_log_disabled(): void
    {
        define('WP_DEBUG_LOG', false);

        require_once DATAFLAIR_PLUGIN_DIR . 'includes/Logging/LoggerInterface.php';
        require_once DATAFLAIR_PLUGIN_DIR . 'includes/Logging/ErrorLogLogger.php';
        require_once DATAFLAIR_PLUGIN_DIR . 'includes/Logging/FileLogger.php';
        require_once DATAFLAIR_PLUGIN_DIR . 'includes/Logging/LoggerFactory.php';
        require_once DATAFLAIR_PLUGIN_DIR . 'includes/Support/ActiveLogSource.php';

        Filters\expectApplied('dataflair_logger')->andReturn(new ErrorLogLogger());

        $result = (new ActiveLogSource())->resolve();

        $this->assertSame([], $result['paths']);
        $this->assertTrue($result['filterMarker']);
        $this->assertStringContainsStringIgnoringCase('WP_DEBUG_LOG', $result['error']);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_error_log_logger_prefers_live_ini_error_log_over_wp_debug_log_path(): void
    {
        define('WP_DEBUG_LOG', true);
        define('WP_CONTENT_DIR', '/tmp/dataflair-nonexistent-' . uniqid());

        require_once DATAFLAIR_PLUGIN_DIR . 'includes/Logging/LoggerInterface.php';
        require_once DATAFLAIR_PLUGIN_DIR . 'includes/Logging/ErrorLogLogger.php';
        require_once DATAFLAIR_PLUGIN_DIR . 'includes/Logging/FileLogger.php';
        require_once DATAFLAIR_PLUGIN_DIR . 'includes/Logging/LoggerFactory.php';
        require_once DATAFLAIR_PLUGIN_DIR . 'includes/Support/ActiveLogSource.php';

        Filters\expectApplied('dataflair_logger')->andReturn(new ErrorLogLogger());

        // Simulates a host/mu-plugin repointing error_log() after WP's own
        // bootstrap set it from WP_DEBUG_LOG — the real destination should
        // win over the WP_CONTENT_DIR-derived guess (which points at a
        // directory that doesn't even exist here).
        $realDestination = tempnam(sys_get_temp_dir(), 'dflog');
        $prevIni = ini_set('error_log', $realDestination);

        try {
            $result = (new ActiveLogSource())->resolve();
        } finally {
            if ($prevIni !== false) {
                ini_set('error_log', $prevIni);
            }
            @unlink($realDestination);
        }

        $this->assertNull($result['error']);
        $this->assertSame([$realDestination], $result['paths']);
        $this->assertTrue($result['filterMarker']);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_error_log_logger_falls_back_to_wp_debug_log_path_when_ini_unusable(): void
    {
        $tmpDir  = sys_get_temp_dir();
        $logFile = $tmpDir . '/dataflair-test-debug-' . uniqid() . '.log';
        file_put_contents($logFile, "[DataFlair][INFO] hello\n");

        define('WP_DEBUG_LOG', $logFile);
        define('WP_CONTENT_DIR', $tmpDir);

        require_once DATAFLAIR_PLUGIN_DIR . 'includes/Logging/LoggerInterface.php';
        require_once DATAFLAIR_PLUGIN_DIR . 'includes/Logging/ErrorLogLogger.php';
        require_once DATAFLAIR_PLUGIN_DIR . 'includes/Logging/FileLogger.php';
        require_once DATAFLAIR_PLUGIN_DIR . 'includes/Logging/LoggerFactory.php';
        require_once DATAFLAIR_PLUGIN_DIR . 'includes/Support/ActiveLogSource.php';

        Filters\expectApplied('dataflair_logger')->andReturn(new ErrorLogLogger());

        // Empty ini error_log (common on a fresh dev box) — ActiveLogSource
        // must fall back to the WP_DEBUG_LOG string override rather than
        // reporting "not found".
        $prevIni = ini_set('error_log', '');

        try {
            $result = (new ActiveLogSource())->resolve();
        } finally {
            if ($prevIni !== false) {
                ini_set('error_log', $prevIni);
            }
            unlink($logFile);
        }

        $this->assertNull($result['error']);
        $this->assertSame([$logFile], $result['paths']);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_error_log_logger_reports_notice_when_path_not_readable(): void
    {
        define('WP_DEBUG_LOG', true);
        define('WP_CONTENT_DIR', '/tmp/dataflair-nonexistent-' . uniqid());

        require_once DATAFLAIR_PLUGIN_DIR . 'includes/Logging/LoggerInterface.php';
        require_once DATAFLAIR_PLUGIN_DIR . 'includes/Logging/ErrorLogLogger.php';
        require_once DATAFLAIR_PLUGIN_DIR . 'includes/Logging/FileLogger.php';
        require_once DATAFLAIR_PLUGIN_DIR . 'includes/Logging/LoggerFactory.php';
        require_once DATAFLAIR_PLUGIN_DIR . 'includes/Support/ActiveLogSource.php';

        Filters\expectApplied('dataflair_logger')->andReturn(new ErrorLogLogger());

        $prevIni = ini_set('error_log', '');
        try {
            $result = (new ActiveLogSource())->resolve();
        } finally {
            if ($prevIni !== false) {
                ini_set('error_log', $prevIni);
            }
        }

        $this->assertSame([], $result['paths']);
        $this->assertStringContainsStringIgnoringCase('not found', $result['error']);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_file_logger_reports_notice_when_path_not_readable(): void
    {
        define('WP_CONTENT_DIR', '/tmp/dataflair-nonexistent-' . uniqid());

        require_once DATAFLAIR_PLUGIN_DIR . 'includes/Logging/LoggerInterface.php';
        require_once DATAFLAIR_PLUGIN_DIR . 'includes/Logging/ErrorLogLogger.php';
        require_once DATAFLAIR_PLUGIN_DIR . 'includes/Logging/FileLogger.php';
        require_once DATAFLAIR_PLUGIN_DIR . 'includes/Logging/LoggerFactory.php';
        require_once DATAFLAIR_PLUGIN_DIR . 'includes/Support/ActiveLogSource.php';

        // No filter override: exercises the real default (FileLogger).
        $result = (new ActiveLogSource())->resolve();

        $this->assertSame([], $result['paths']);
        $this->assertFalse($result['filterMarker']);
        $this->assertStringContainsStringIgnoringCase('not found', $result['error']);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_file_logger_includes_rotated_backup_before_live_path(): void
    {
        $tmpDir = sys_get_temp_dir() . '/dataflair-test-' . uniqid();
        mkdir($tmpDir);
        define('WP_CONTENT_DIR', $tmpDir);

        require_once DATAFLAIR_PLUGIN_DIR . 'includes/Logging/LoggerInterface.php';
        require_once DATAFLAIR_PLUGIN_DIR . 'includes/Logging/ErrorLogLogger.php';
        require_once DATAFLAIR_PLUGIN_DIR . 'includes/Logging/FileLogger.php';
        require_once DATAFLAIR_PLUGIN_DIR . 'includes/Logging/LoggerFactory.php';
        require_once DATAFLAIR_PLUGIN_DIR . 'includes/Support/ActiveLogSource.php';

        $live    = $tmpDir . '/dataflair-sync.log';
        $rotated = $tmpDir . '/dataflair-sync.log.1';
        file_put_contents($live, "[2026-04-25 14:05:23 UTC][INFO] fresh\n");
        file_put_contents($rotated, "[2026-04-25 14:00:00 UTC][INFO] older\n");

        try {
            $result = (new ActiveLogSource())->resolve();
        } finally {
            unlink($live);
            unlink($rotated);
            rmdir($tmpDir);
        }

        $this->assertNull($result['error']);
        $this->assertSame([$rotated, $live], $result['paths']);
        $this->assertFalse($result['filterMarker']);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_file_logger_omits_rotated_path_when_it_does_not_exist(): void
    {
        $tmpDir = sys_get_temp_dir() . '/dataflair-test-' . uniqid();
        mkdir($tmpDir);
        define('WP_CONTENT_DIR', $tmpDir);

        require_once DATAFLAIR_PLUGIN_DIR . 'includes/Logging/LoggerInterface.php';
        require_once DATAFLAIR_PLUGIN_DIR . 'includes/Logging/ErrorLogLogger.php';
        require_once DATAFLAIR_PLUGIN_DIR . 'includes/Logging/FileLogger.php';
        require_once DATAFLAIR_PLUGIN_DIR . 'includes/Logging/LoggerFactory.php';
        require_once DATAFLAIR_PLUGIN_DIR . 'includes/Support/ActiveLogSource.php';

        $live = $tmpDir . '/dataflair-sync.log';
        file_put_contents($live, "[2026-04-25 14:05:23 UTC][INFO] fresh\n");

        try {
            $result = (new ActiveLogSource())->resolve();
        } finally {
            unlink($live);
            rmdir($tmpDir);
        }

        $this->assertSame([$live], $result['paths']);
    }

    public function test_null_logger_gets_a_disabled_message_not_unsupported(): void
    {
        Filters\expectApplied('dataflair_logger')->andReturn(new NullLogger());

        $result = (new ActiveLogSource())->resolve();

        $this->assertSame([], $result['paths']);
        $this->assertStringContainsString('disabled', $result['error']);
        $this->assertStringNotContainsString('does not support', $result['error']);
    }

    public function test_other_logger_gets_the_generic_unsupported_message(): void
    {
        Filters\expectApplied('dataflair_logger')->andReturn(new SentryLogger());

        $result = (new ActiveLogSource())->resolve();

        $this->assertSame([], $result['paths']);
        $this->assertStringContainsString('does not support', $result['error']);
        $this->assertStringContainsString('SentryLogger', $result['error']);
    }
}
