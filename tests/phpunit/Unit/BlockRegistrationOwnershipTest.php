<?php
/**
 * Regression guard — Gutenberg block registration has exactly one owner.
 *
 * The god-class's init_hooks() used to ALSO wire its own BlockBootstrap to
 * the `init` hook, duplicating Plugin::registerHooks(). Two independent
 * BlockRegistrar instances both attempting register_block_type() trips
 * WordPress's own duplicate-registration _doing_it_wrong() notice, which is
 * fatal on Roots/Acorn sites (Laravel's HandleExceptions elevates it to a
 * thrown ErrorException) and unconditionally double-enqueues the block
 * editor's CSS.
 *
 * dataflair-toplists.php is deliberately never require()'d in this suite
 * (see PluginBootTestStubs.php, ShimForwardingTestStubs.php) — its
 * top-level code fully boots the plugin and would need the entire
 * WordPress API surface stubbed just to load. This is static source
 * analysis instead: it reads the real file as text and checks the specific
 * duplicate-wiring pattern is gone, without executing it. Not a mirror —
 * it inspects the actual shipped artifact, so it catches a future
 * merge/cherry-pick/refactor that silently reintroduces the duplicate,
 * which nothing else in the suite would notice.
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class BlockRegistrationOwnershipTest extends TestCase
{
    private const DUPLICATE_CALL_PATTERN =
        '/\$this->block_bootstrap\(\)\s*->\s*boot\(\)\s*->\s*register\(\)/';

    public function test_god_class_init_hooks_does_not_wire_block_registration(): void
    {
        $source = file_get_contents(DATAFLAIR_PLUGIN_DIR . 'dataflair-toplists.php');
        $this->assertIsString($source);

        $body = $this->extractMethodBody($source, 'init_hooks');
        $this->assertNotSame(
            '',
            $body,
            'Could not locate init_hooks() — method may have been renamed/moved; update this test.'
        );

        $this->assertDoesNotMatchRegularExpression(
            self::DUPLICATE_CALL_PATTERN,
            $body,
            'init_hooks() must not call block_bootstrap()->boot()->register() — ' .
            'Plugin::registerHooks() is the sole owner of block `init` wiring. ' .
            'Reintroducing this double-registers the Gutenberg block.'
        );
    }

    public function test_plugin_registerhooks_remains_the_sole_owner(): void
    {
        $source = file_get_contents(DATAFLAIR_PLUGIN_DIR . 'src/Plugin.php');
        $this->assertIsString($source);

        $this->assertMatchesRegularExpression(
            '/new\s+BlockBootstrap\(/',
            $source,
            'Plugin::registerHooks() must remain the sole owner of block registration — ' .
            'if this fails, BOTH copies were removed and the block silently stopped registering.'
        );
    }

    /**
     * Brace-depth extraction of one method's body. Good enough for a single
     * known, brace-balanced method on a file we control — not a general PHP
     * parser. If this ever needs to be bulletproof against braces-in-strings,
     * switch to token_get_all() and walk tokens instead of characters.
     */
    private function extractMethodBody(string $source, string $methodName): string
    {
        if (!preg_match(
            '/function\s+' . preg_quote($methodName, '/') . '\s*\([^)]*\)\s*\{/',
            $source,
            $m,
            PREG_OFFSET_CAPTURE
        )) {
            return '';
        }

        $start = $m[0][1] + strlen($m[0][0]);
        $depth = 1;
        $i     = $start;
        $len   = strlen($source);
        while ($i < $len && $depth > 0) {
            if ($source[$i] === '{') { $depth++; }
            elseif ($source[$i] === '}') { $depth--; }
            $i++;
        }

        return substr($source, $start, $i - $start - 1);
    }
}
