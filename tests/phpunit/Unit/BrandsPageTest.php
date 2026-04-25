<?php
/**
 * Phase 9.6 (admin UX redesign) — pins BrandsPage contract.
 *
 * Updated for the Phase 2 rewrite: constructor now accepts
 * (BrandsRepositoryInterface, \Closure) instead of two closures.
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Tests\Unit\Admin;

use DataFlair\Toplists\Admin\Pages\BrandsPage;
use DataFlair\Toplists\Admin\Pages\PageInterface;
use DataFlair\Toplists\Database\BrandsRepositoryInterface;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionNamedType;

require_once DATAFLAIR_PLUGIN_DIR . 'src/Database/BrandsRepositoryInterface.php';
require_once DATAFLAIR_PLUGIN_DIR . 'src/Database/BrandsPage.php';
require_once DATAFLAIR_PLUGIN_DIR . 'src/Database/BrandsQuery.php';
require_once DATAFLAIR_PLUGIN_DIR . 'src/Admin/Pages/PageInterface.php';
require_once DATAFLAIR_PLUGIN_DIR . 'src/Admin/Pages/BrandsPage.php';

final class BrandsPageTest extends TestCase
{
    public function test_implements_page_interface(): void
    {
        $repo = $this->createStub(BrandsRepositoryInterface::class);
        $page = new BrandsPage($repo, static fn(string $option) => 'never');
        $this->assertInstanceOf(PageInterface::class, $page);
    }

    public function test_constructor_first_param_is_repo_interface(): void
    {
        $reflection  = new ReflectionClass(BrandsPage::class);
        $constructor = $reflection->getConstructor();
        $this->assertNotNull($constructor);

        $params = $constructor->getParameters();
        $this->assertCount(2, $params);

        $firstType = $params[0]->getType();
        $this->assertInstanceOf(ReflectionNamedType::class, $firstType);
        $this->assertSame(BrandsRepositoryInterface::class, $firstType->getName());

        $secondType = $params[1]->getType();
        $this->assertInstanceOf(ReflectionNamedType::class, $secondType);
        $this->assertSame(\Closure::class, $secondType->getName());
    }

    public function test_render_method_exists_and_is_void(): void
    {
        $reflection = new ReflectionClass(BrandsPage::class);
        $this->assertTrue($reflection->hasMethod('render'));
        $method     = $reflection->getMethod('render');
        $this->assertTrue($method->isPublic());
        $returnType = $method->getReturnType();
        $this->assertInstanceOf(ReflectionNamedType::class, $returnType);
        $this->assertSame('void', $returnType->getName());
    }

    public function test_class_is_final(): void
    {
        $this->assertTrue((new ReflectionClass(BrandsPage::class))->isFinal());
    }
}
