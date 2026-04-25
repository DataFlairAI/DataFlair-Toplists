<?php
/**
 * Phase 9.6 (admin UX redesign) — pins LogsTailHandler contract.
 *
 * Verifies: WP_DEBUG_LOG guard, missing file path, and DataFlair line
 * filtering + parsing (ts / level / message extraction).
 *
 * Tests that require WP_DEBUG_LOG to be defined run in separate processes
 * so each gets a pristine constant state.
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Tests\Unit\Admin\Ajax;

use Brain\Monkey;
use DataFlair\Toplists\Admin\Ajax\LogsTailHandler;
use PHPUnit\Framework\TestCase;

require_once DATAFLAIR_PLUGIN_DIR . 'src/Admin/AjaxHandlerInterface.php';
require_once DATAFLAIR_PLUGIN_DIR . 'src/Admin/Ajax/LogsTailHandler.php';

final class LogsTailHandlerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_returns_notice_when_wp_debug_log_not_defined(): void
    {
        if (defined('WP_DEBUG_LOG')) {
            $this->markTestSkipped('WP_DEBUG_LOG already defined in this process.');
        }

        $result = (new LogsTailHandler())->handle([]);

        $this->assertTrue($result['success']);
        $this->assertSame([], $result['data']['entries']);
        $this->assertNotEmpty($result['data']['notice']);
        $this->assertStringContainsStringIgnoringCase('WP_DEBUG_LOG', $result['data']['notice']);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_returns_notice_when_wp_debug_log_false(): void
    {
        define('WP_DEBUG_LOG', false);
        define('WP_CONTENT_DIR', '/tmp');

        require_once DATAFLAIR_PLUGIN_DIR . 'src/Admin/AjaxHandlerInterface.php';
        require_once DATAFLAIR_PLUGIN_DIR . 'src/Admin/Ajax/LogsTailHandler.php';

        $result = (new LogsTailHandler())->handle([]);

        $this->assertTrue($result['success']);
        $this->assertSame([], $result['data']['entries']);
        $this->assertNotEmpty($result['data']['notice']);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_returns_notice_when_log_file_not_readable(): void
    {
        define('WP_DEBUG_LOG', true);
        define('WP_CONTENT_DIR', '/tmp/dataflair-nonexistent-' . uniqid());

        require_once DATAFLAIR_PLUGIN_DIR . 'src/Admin/AjaxHandlerInterface.php';
        require_once DATAFLAIR_PLUGIN_DIR . 'src/Admin/Ajax/LogsTailHandler.php';

        $result = (new LogsTailHandler())->handle([]);

        $this->assertTrue($result['success']);
        $this->assertSame([], $result['data']['entries']);
        $this->assertStringContainsStringIgnoringCase('not found', $result['data']['notice']);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_filters_and_parses_dataflair_lines(): void
    {
        $tmpDir  = sys_get_temp_dir();
        $logFile = $tmpDir . '/dataflair-test-debug-' . uniqid() . '.log';

        $lines = [
            '[25-Apr-2026 14:05:22 UTC] [DataFlair][INFO] Sync complete — 42 brands',
            '[25-Apr-2026 14:05:23 UTC] [DataFlair][ERROR] DB write failed',
            '[25-Apr-2026 14:05:24 UTC] Some unrelated WordPress notice',
            '[DataFlair][WARN] No token configured',
        ];
        file_put_contents($logFile, implode(PHP_EOL, $lines) . PHP_EOL);

        define('WP_DEBUG_LOG', $logFile);
        define('WP_CONTENT_DIR', $tmpDir);

        require_once DATAFLAIR_PLUGIN_DIR . 'src/Admin/AjaxHandlerInterface.php';
        require_once DATAFLAIR_PLUGIN_DIR . 'src/Admin/Ajax/LogsTailHandler.php';

        $result = (new LogsTailHandler())->handle([]);

        unlink($logFile);

        $this->assertTrue($result['success']);
        $entries = $result['data']['entries'];

        // 3 DataFlair lines; 1 unrelated line excluded
        $this->assertCount(3, $entries);

        // Newest-first: last DataFlair line (WARN) comes first
        $this->assertSame('warn', $entries[0]['level']);
        $this->assertSame('error', $entries[1]['level']);
        $this->assertSame('info', $entries[2]['level']);

        // Messages extracted correctly
        $this->assertStringContainsString('Sync complete', $entries[2]['message']);
        $this->assertStringContainsString('DB write failed', $entries[1]['message']);
        $this->assertStringContainsString('No token', $entries[0]['message']);

        // Timestamp parsed for lines that have one
        $this->assertNotEmpty($entries[1]['ts']);
        $this->assertStringContainsString('Apr', $entries[1]['ts']);

        $this->assertFalse($result['data']['truncated']);
        $this->assertSame(3, $result['data']['total']);
    }
}
