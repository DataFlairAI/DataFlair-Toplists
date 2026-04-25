<?php
/**
 * Phase 9.6 — pins BrandsPage contract.
 *
 * Same rationale as SettingsPageTest: the render body is verbatim from the
 * v2.1.1 god-class `brands_page()` method (~1,237 LOC of HTML, jQuery, AJAX
 * wiring, and pagination). Re-rendering it under PHPUnit would mean stubbing
 * 50+ WP helpers and seeding a paginated brand fixture for every assertion,
 * to verify a string we already byte-identity-test on strike-odds.test.
 *
 * This unit test pins the public contract:
 *   1. Lives in the Admin\Pages namespace.
 *   2. Implements PageInterface.
 *   3. Constructor accepts exactly two `\Closure` parameters
 *      (distinctCsvValuesCollector, lastSyncLabelFormatter).
 *   4. `render()` exists, returns void, is callable.
 *   5. Class is final.
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Tests\Unit\Admin;

use DataFlair\Toplists\Admin\Pages\BrandsPage;
use DataFlair\Toplists\Admin\Pages\PageInterface;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionNamedType;

require_once DATAFLAIR_PLUGIN_DIR . 'src/Admin/Pages/PageInterface.php';
require_once DATAFLAIR_PLUGIN_DIR . 'src/Admin/Pages/BrandsPage.php';

final class BrandsPageTest extends TestCase
{
    public function test_implements_page_interface(): void
    {
        $page = new BrandsPage(
            static fn(string $table, string $column): array => [],
            static fn(string $option) => 'never'
        );
        $this->assertInstanceOf(PageInterface::class, $page);
    }

    public function test_constructor_accepts_two_closure_parameters(): void
    {
        $reflection  = new ReflectionClass(BrandsPage::class);
        $constructor = $reflection->getConstructor();
        $this->assertNotNull($constructor, 'BrandsPage must declare a constructor');

        $params = $constructor->getParameters();
        $this->assertCount(2, $params, 'constructor takes exactly 2 parameters');

        foreach ($params as $param) {
            $type = $param->getType();
            $this->assertInstanceOf(ReflectionNamedType::class, $type);
            $this->assertSame(\Closure::class, $type->getName());
        }

        $this->assertSame('distinctCsvValuesCollector', $params[0]->getName());
        $this->assertSame('lastSyncLabelFormatter', $params[1]->getName());
    }

    public function test_render_method_exists_and_is_void(): void
    {
        $reflection = new ReflectionClass(BrandsPage::class);
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
        $reflection = new ReflectionClass(BrandsPage::class);
        $this->assertTrue(
            $reflection->isFinal(),
            'BrandsPage must be final — extension is the wrong reuse vector'
        );
    }
}
