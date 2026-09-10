<?php
/**
 * Phase 9.6 — pins SettingsPage contract.
 *
 * The render body is byte-identical to the v2.1.1 god-class settings_page()
 * method (~705 LOC of HTML + WP API calls). Re-rendering it under PHPUnit
 * would require stubbing 40+ WordPress functions, every wpdb query path, and
 * the `$_GET`/`$_POST` superglobals — a maintenance burden far beyond the
 * value of asserting "this big string equals that big string". HTML-shape
 * regressions are caught by the manual byte-identity smoke on
 * strike-odds.test that the Phase 9.6 acceptance criteria mandate.
 *
 * What this unit test pins is the contract that future refactors must keep:
 *   1. The class lives in the Admin\Pages namespace.
 *   2. It implements PageInterface.
 *   3. The constructor accepts exactly one `\Closure` parameter
 *      (brandsEffectiveBaseResolver). Two earlier closures were removed in
 *      2.3.3 because render() never called them.
 *   4. `render()` exists, returns void, and is callable.
 *
 * Anything that breaks one of these breaks the wiring inside
 * `DataFlair_Toplists::settings_page_obj()` (the lazy getter that injects
 * closures bound to the legacy private helpers). Catching it at unit-test
 * time is cheaper than catching it via a fatal on Sigma admin load.
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Tests\Unit\Admin;

use DataFlair\Toplists\Admin\Pages\PageInterface;
use DataFlair\Toplists\Admin\Pages\SettingsPage;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionNamedType;

require_once DATAFLAIR_PLUGIN_DIR . 'src/Admin/Pages/PageInterface.php';
require_once DATAFLAIR_PLUGIN_DIR . 'src/Admin/Pages/SettingsPage.php';

final class SettingsPageTest extends TestCase
{
    public function test_implements_page_interface(): void
    {
        $page = new SettingsPage(static fn() => 'http://api.test/api/v1');
        $this->assertInstanceOf(PageInterface::class, $page);
    }

    public function test_constructor_accepts_one_closure_parameter(): void
    {
        $reflection  = new ReflectionClass(SettingsPage::class);
        $constructor = $reflection->getConstructor();
        $this->assertNotNull($constructor, 'SettingsPage must declare a constructor');

        $params = $constructor->getParameters();
        $this->assertCount(1, $params, 'constructor takes exactly 1 parameter');

        $type = $params[0]->getType();
        $this->assertInstanceOf(ReflectionNamedType::class, $type);
        $this->assertSame(\Closure::class, $type->getName());
        $this->assertSame('brandsEffectiveBaseResolver', $params[0]->getName());
    }

    public function test_render_method_exists_and_is_void(): void
    {
        $reflection = new ReflectionClass(SettingsPage::class);
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
        $reflection = new ReflectionClass(SettingsPage::class);
        $this->assertTrue(
            $reflection->isFinal(),
            'SettingsPage must be final — extension is the wrong reuse vector'
        );
    }
}
