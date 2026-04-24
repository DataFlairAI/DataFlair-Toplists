<?php
/**
 * Unit test for ToplistTableVM — value object with readonly fields.
 *
 * Phase 4 — verifies the accordion-template-seam contract.
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Tests\Unit;

use DataFlair\Toplists\Frontend\Render\ViewModels\ToplistTableVM;
use PHPUnit\Framework\TestCase;

final class ToplistTableVMTest extends TestCase
{
    public function test_constructs_with_all_fields(): void
    {
        $vm = new ToplistTableVM(
            [['brand' => ['name' => 'Acme'], 'position' => 1]],
            'Best Casinos',
            true,
            1710000000,
            ['casino-brand-1' => ['pros' => ['Good'], 'cons' => []]]
        );

        $this->assertCount(1, $vm->items);
        $this->assertSame('Best Casinos', $vm->title);
        $this->assertTrue($vm->isStale);
        $this->assertSame(1710000000, $vm->lastSynced);
        $this->assertArrayHasKey('casino-brand-1', $vm->prosConsData);
    }

    public function test_defaults_pros_cons_to_empty(): void
    {
        $vm = new ToplistTableVM([], '', false, 0);
        $this->assertSame([], $vm->prosConsData);
    }

    public function test_readonly_fields_cannot_be_mutated(): void
    {
        $vm = new ToplistTableVM([], 't', false, 0);

        $this->expectException(\Error::class);
        /** @phpstan-ignore-next-line testing readonly enforcement */
        $vm->title = 'replaced';
    }
}
