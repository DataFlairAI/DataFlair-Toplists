<?php
/**
 * Unit test for CasinoCardVM — value object with readonly fields.
 *
 * Phase 4 — verifies the template-seam contract: construction with all
 * permitted field shapes, readonly immutability, and default values.
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Tests\Unit;

use DataFlair\Toplists\Frontend\Render\ViewModels\CasinoCardVM;
use PHPUnit\Framework\TestCase;

final class CasinoCardVMTest extends TestCase
{
    public function test_constructs_with_all_fields(): void
    {
        $map = ['ids' => [], 'slugs' => [], 'names' => []];
        $vm = new CasinoCardVM(
            ['brand' => ['name' => 'Acme']],
            42,
            ['ribbonText' => 'Top'],
            ['casino-brand-1' => ['pros' => ['Fast'], 'cons' => ['Slow']]],
            $map
        );

        $this->assertSame(['brand' => ['name' => 'Acme']], $vm->item);
        $this->assertSame(42, $vm->toplistId);
        $this->assertSame(['ribbonText' => 'Top'], $vm->customizations);
        $this->assertSame(['casino-brand-1' => ['pros' => ['Fast'], 'cons' => ['Slow']]], $vm->prosConsData);
        $this->assertSame($map, $vm->brandMetaMap);
    }

    public function test_defaults_customizations_pros_cons_and_meta_map(): void
    {
        $vm = new CasinoCardVM(['brand' => ['name' => 'X']], 7);

        $this->assertSame([], $vm->customizations);
        $this->assertSame([], $vm->prosConsData);
        $this->assertNull($vm->brandMetaMap);
    }

    public function test_readonly_fields_cannot_be_mutated(): void
    {
        $vm = new CasinoCardVM(['brand' => ['name' => 'A']], 1);

        $this->expectException(\Error::class);
        /** @phpstan-ignore-next-line testing readonly enforcement */
        $vm->toplistId = 99;
    }
}
