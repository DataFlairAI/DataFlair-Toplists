<?php
/**
 * Pins ToolsPage's contract. No test file existed for this class before a
 * live WordPress smoke test (Docker, WP 7.0, this plugin's actual main
 * branch) caught a fatal ArgumentCountError: renderTestsTab() built its own
 * `new TestsRunner()` with zero arguments after TestsRunner's constructor
 * gained a required ApiBaseUrlDetector parameter elsewhere in the same PR.
 * PHPUnit's 869 mocked tests never exercised this line. A reflection-only
 * pass at this class would NOT have caught it either — proven here by
 * mutation: reverting the fix and re-running left every reflection test
 * green, because they check ToolsPage's own constructor shape, not what a
 * private method builds inside itself. test_tests_tab_renders_without_a_fatal
 * is the one that actually boots render() and would fail loudly again.
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Tests\Unit\Admin;

use Brain\Monkey;
use Brain\Monkey\Functions;
use DataFlair\Toplists\Admin\Pages\PageInterface;
use DataFlair\Toplists\Admin\Pages\ToolsPage;
use DataFlair\Toplists\Http\ApiBaseUrlDetector;
use DataFlair\Toplists\Support\UrlTransformer;
use DataFlair\Toplists\Support\UrlValidator;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionNamedType;

require_once DATAFLAIR_PLUGIN_DIR . 'src/Support/UrlValidator.php';
require_once DATAFLAIR_PLUGIN_DIR . 'src/Support/UrlTransformer.php';
require_once DATAFLAIR_PLUGIN_DIR . 'src/Http/ApiBaseUrlDetector.php';
require_once DATAFLAIR_PLUGIN_DIR . 'src/Admin/Pages/Tools/TestsRunner.php';
require_once DATAFLAIR_PLUGIN_DIR . 'src/Admin/Pages/PageInterface.php';
require_once DATAFLAIR_PLUGIN_DIR . 'src/Admin/Pages/ToolsPage.php';
require_once __DIR__ . '/ToolsPageTestStubs.php';

final class ToolsPageTest extends TestCase
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

    private function detector(): ApiBaseUrlDetector
    {
        return new ApiBaseUrlDetector(new UrlTransformer(new UrlValidator()));
    }

    public function test_implements_page_interface(): void
    {
        $page = new ToolsPage(static fn() => 'http://api.test', $this->detector());
        $this->assertInstanceOf(PageInterface::class, $page);
    }

    public function test_constructor_accepts_a_closure_and_an_api_base_url_detector(): void
    {
        $reflection  = new ReflectionClass(ToolsPage::class);
        $constructor = $reflection->getConstructor();
        $this->assertNotNull($constructor, 'ToolsPage must declare a constructor');

        $params = $constructor->getParameters();
        $this->assertCount(2, $params, 'constructor takes exactly 2 parameters');

        $first = $params[0]->getType();
        $this->assertInstanceOf(ReflectionNamedType::class, $first);
        $this->assertSame(\Closure::class, $first->getName());
        $this->assertSame('apiBaseUrlResolver', $params[0]->getName());

        $second = $params[1]->getType();
        $this->assertInstanceOf(ReflectionNamedType::class, $second);
        $this->assertSame(ApiBaseUrlDetector::class, $second->getName());
        $this->assertSame('base', $params[1]->getName());
    }

    public function test_render_method_exists_and_is_void(): void
    {
        $reflection = new ReflectionClass(ToolsPage::class);
        $this->assertTrue($reflection->hasMethod('render'));

        $method = $reflection->getMethod('render');
        $this->assertTrue($method->isPublic());
        $this->assertFalse($method->isStatic());

        $returnType = $method->getReturnType();
        $this->assertInstanceOf(ReflectionNamedType::class, $returnType);
        $this->assertSame('void', $returnType->getName());
    }

    public function test_class_is_final(): void
    {
        $reflection = new ReflectionClass(ToolsPage::class);
        $this->assertTrue(
            $reflection->isFinal(),
            'ToolsPage must be final — extension is the wrong reuse vector'
        );
    }

    /**
     * Boots the real Tests & Diagnostics tab. Does not assert on the HTML
     * (that's a maintenance burden, not a regression guard) — only that it
     * doesn't throw. This is the test that actually would have caught the
     * ArgumentCountError this class's docblock describes.
     */
    public function test_tests_tab_renders_without_a_fatal(): void
    {
        Functions\when('sanitize_text_field')->returnArg();
        Functions\when('admin_url')->justReturn('http://api.test/wp-admin/admin-ajax.php');
        Functions\when('wp_create_nonce')->justReturn('nonce');
        Functions\when('human_time_diff')->justReturn('2 minutes');
        Functions\when('get_option')->justReturn([]);
        // esc_attr()/esc_html() are real namespace-scoped stubs in
        // ToolsPageTestStubs.php, not Brain\Monkey mocks — see that file's
        // docblock for why.

        $_GET['tab'] = 'tests';
        $page = new ToolsPage(static fn() => 'http://api.test', $this->detector());

        ob_start();
        try {
            $page->render();
            $output = ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();
            unset($_GET['tab']);
            $this->fail('ToolsPage::render() threw: ' . $e->getMessage());
        }
        unset($_GET['tab']);

        $this->assertStringContainsString('Tests &amp; Diagnostics', $output);
    }
}
