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

    /**
     * Reordering a toplist keeps the same toplist id but changes positions.
     * Legacy Gutenberg keys were `casino-{position}-{slug}` — the frontend
     * must still resolve overrides saved under the old position.
     */
    public function test_legacy_override_survives_position_reorder(): void
    {
        $result = $this->resolver->resolve_pros_cons_for_table_item(
            [
                'position' => 4,
                'brand' => [
                    'id' => 99,
                    'name' => 'BC.GAME',
                    'slug' => 'bc-game',
                ],
                'pros' => [],
                'cons' => [],
            ],
            [
                // Saved when this brand was still #2 — never migrated to stable key.
                'casino-2-bc-game' => [
                    'pros' => ['Fast crypto payouts', 'Great odds'],
                    'cons' => ['Limited support hours'],
                ],
            ]
        );

        $this->assertSame(['Fast crypto payouts', 'Great odds'], $result['pros']);
        $this->assertSame(['Limited support hours'], $result['cons']);
    }

    public function test_stable_brand_key_wins_over_legacy_position_key(): void
    {
        $result = $this->resolver->resolve_pros_cons_for_table_item(
            [
                'position' => 4,
                'brand' => [
                    'id' => 99,
                    'name' => 'BC.GAME',
                ],
                'pros' => [],
                'cons' => [],
            ],
            [
                'casino-brand-99' => [
                    'pros' => ['Stable key pro'],
                    'cons' => [],
                ],
                'casino-2-bc-game' => [
                    'pros' => ['Legacy key pro'],
                    'cons' => [],
                ],
            ]
        );

        $this->assertSame(['Stable key pro'], $result['pros']);
    }

    public function test_legacy_override_matches_sanitized_brand_name_slug(): void
    {
        $result = $this->resolver->resolve_pros_cons_for_table_item(
            [
                'position' => 1,
                'brand' => [
                    'name' => 'Stake Casino',
                ],
                'pros' => [],
                'cons' => [],
            ],
            [
                'casino-7-stake-casino' => [
                    'pros' => ['Kept after reorder'],
                    'cons' => [],
                ],
            ]
        );

        $this->assertSame(['Kept after reorder'], $result['pros']);
    }
}
}
