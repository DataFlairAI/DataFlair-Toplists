<?php
/**
 * Phase 9.8 — Pins AlpineDeferAttribute behaviour.
 */

declare(strict_types=1);

namespace DataFlair\Toplists\Tests\Unit\Frontend\Assets;

use DataFlair\Toplists\Frontend\Assets\AlpineDeferAttribute;
use PHPUnit\Framework\TestCase;

require_once DATAFLAIR_PLUGIN_DIR . 'src/Frontend/Assets/AlpineDeferAttribute.php';

final class AlpineDeferAttributeTest extends TestCase
{
    public function test_inserts_defer_for_alpine_handle(): void
    {
        $tag = '<script src="https://cdn.example/alpine.js"></script>';
        $out = (new AlpineDeferAttribute())->filter($tag, 'alpinejs');

        $this->assertSame('<script defer src="https://cdn.example/alpine.js"></script>', $out);
    }

    public function test_passes_other_handles_through_untouched(): void
    {
        $tag = '<script src="/wp-content/plugins/jquery.js"></script>';
        $out = (new AlpineDeferAttribute())->filter($tag, 'jquery');

        $this->assertSame($tag, $out);
    }

    public function test_returns_value_unchanged_when_not_a_string(): void
    {
        $out = (new AlpineDeferAttribute())->filter(false, 'alpinejs');

        $this->assertFalse($out);
    }
}
