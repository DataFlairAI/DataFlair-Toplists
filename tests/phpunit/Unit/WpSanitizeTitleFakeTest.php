<?php
/**
 * Direct compatibility coverage for the shared WordPress sanitize_title fake.
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once DATAFLAIR_PLUGIN_DIR . 'tests/phpunit/WpSanitizeTitleFake.php';

final class WpSanitizeTitleFakeTest extends TestCase
{
    /**
     * Outputs pinned against sanitize_title() on the local WordPress 6.9.4 install.
     *
     * @dataProvider wordpressCasesProvider
     */
    public function test_matches_wordpress_default_save_context(string $input, string $expected): void
    {
        $this->assertSame($expected, WpSanitizeTitleFake::sanitize($input));
    }

    public function test_normalizes_decomposed_accents_when_intl_is_available(): void
    {
        if (!function_exists('normalizer_is_normalized') || !function_exists('normalizer_normalize')) {
            $this->markTestSkipped('ext-intl is unavailable; WordPress also skips NFC normalization.');
        }

        $this->assertSame('bjorn-casino', WpSanitizeTitleFake::sanitize("Bjo\u{0308}rn Casino"));
    }

    /**
     * @return array<string,array{string,string}>
     */
    public function wordpressCasesProvider(): array
    {
        return [
            'brand accents and punctuation' => [
                "Björn's Casino & Café_Royale...Deluxe",
                'bjorns-casino-cafe_royale-deluxe',
            ],
            'non-Latin text is URI encoded' => [
                '東京 カジノ',
                '%e6%9d%b1%e4%ba%ac-%e3%82%ab%e3%82%b8%e3%83%8e',
            ],
            'valid percent octet is preserved and bare percent is removed' => [
                '100% Casino %2F VIP',
                '100-casino-%2f-vip',
            ],
            'save-context punctuation is normalized' => [
                'Rock – Roll — Live × 2',
                'rock-roll-live-x-2',
            ],
            'Romanian letters and currency signs are transliterated' => [
                'Șansă Țară €100 £50',
                'sansa-tara-e100-50',
            ],
            'HTML entities follow the save-context rules' => [
                'A&nbsp;B &copy; C',
                'a-b-c',
            ],
        ];
    }
}
