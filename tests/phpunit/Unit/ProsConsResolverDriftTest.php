<?php
/**
 * API Contract Safety P4 — ProsConsResolver must survive retyped entries.
 *
 * This trait runs in the REAL render path (CardRenderer/TableRenderer)
 * BEFORE the template's own guards, under strict_types: a non-string pros
 * entry used to be an uncaught trim() TypeError that white-screened every
 * page with a toplist.
 */

declare(strict_types=1);

// Namespace-local stub (SyncFunctionStubs pattern): the global
// sanitize_title() is defined by earlier-loaded test files, which Patchwork
// cannot redefine, so Brain Monkey is unusable for this function here.
namespace DataFlair\Toplists\Frontend\Render {
    if (!function_exists(__NAMESPACE__ . '\\sanitize_title')) {
        function sanitize_title($value)
        {
            // MUST byte-match the global stub in the Integration render tests:
            // this namespace-local version shadows it for CardRenderer etc.
            $value = strtolower(trim((string) $value));
            $value = str_replace('.', '-', $value);
            $value = preg_replace('/[^a-z0-9\-]+/', '-', $value);
            return trim((string) $value, '-');
        }
    }
}

namespace DataFlair\Toplists\Tests\Unit\Frontend {

use PHPUnit\Framework\TestCase;

require_once DATAFLAIR_PLUGIN_DIR . 'src/Frontend/Render/ProsConsResolver.php';

final class ProsConsResolverDriftTest extends TestCase
{
    private object $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new class {
            use \DataFlair\Toplists\Frontend\Render\ProsConsResolver;
        };
    }

    public function test_retyped_pros_entries_degrade_instead_of_fataling(): void
    {
        $result = $this->resolver->resolve_pros_cons_for_table_item([
            'pros' => [['nested' => 'object'], 'Real pro', null, 42, '  padded  '],
            'cons' => [['also' => 'nested'], 'Real con'],
        ], []);

        $this->assertSame(['Real pro', '42', 'padded'], $result['pros']);
        $this->assertSame(['Real con'], $result['cons']);
    }

    public function test_retyped_override_entries_degrade_instead_of_fataling(): void
    {
        $result = $this->resolver->resolve_pros_cons_for_table_item(
            ['brandId' => 42, 'pros' => [], 'cons' => []],
            ['casino-brand-42' => ['pros' => [['drifted'], 'Override pro'], 'cons' => []]]
        );

        $this->assertSame(['Override pro'], $result['pros']);
    }

    public function test_retyped_brand_name_does_not_warn_on_the_override_path(): void
    {
        // The resolver receives the RAW item; a retyped brand.name must not
        // emit an Array-to-string warning while building candidate keys.
        $warnings = [];
        set_error_handler(static function (int $errno, string $errstr) use (&$warnings): bool {
            $warnings[] = $errstr;
            return true;
        });
        try {
            $result = $this->resolver->resolve_pros_cons_for_table_item(
                ['brand' => ['name' => ['weird' => 'object']], 'pros' => [], 'cons' => []],
                ['casino-item-999' => ['pros' => ['Unmatched'], 'cons' => []]]
            );
        } finally {
            restore_error_handler();
        }

        $this->assertSame([], $warnings);
        $this->assertSame([], $result['pros']);
    }

    public function test_healthy_string_lists_are_unchanged(): void
    {
        $result = $this->resolver->resolve_pros_cons_for_table_item([
            'pros' => ['Fast payouts', 'Great odds'],
            'cons' => ['No live chat'],
        ], []);

        $this->assertSame(['Fast payouts', 'Great odds'], $result['pros']);
        $this->assertSame(['No live chat'], $result['cons']);
    }
}
}
