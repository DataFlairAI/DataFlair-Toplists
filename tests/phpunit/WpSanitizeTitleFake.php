<?php
/**
 * Shared, WordPress-accurate `sanitize_title()` fake for PHPUnit test doubles.
 *
 * Real WordPress hooks the `sanitize_title` filter to `sanitize_title_with_dashes()`
 * by default (wp-includes/default-filters.php), so a plain `sanitize_title($x)`
 * call is `remove_accents($x)` run through that dash-normalizing pipeline
 * (wp-includes/formatting.php). This class mirrors the default `save` path used
 * by this plugin without booting WordPress in the unit suite.
 *
 * Centralized here after a code review found five independent namespace-local
 * `sanitize_title` stubs (one per test namespace, required by the
 * Brain\Monkey/Patchwork namespace-fallback trick — see
 * tests/phpunit/Unit/SyncFunctionStubs.php's docblock) had each approximated
 * WordPress differently and drifted from real behaviour: no accent
 * transliteration, wrong ampersand/apostrophe/underscore handling, no
 * consecutive-dash collapsing. That matters because
 * {@see \DataFlair\Toplists\Frontend\Render\ProsConsResolver::resolve_pros_cons_for_table_item()}
 * calls `sanitize_title($brand_name)` to build the lookup key matched against
 * block-level pros/cons override data — a fake that disagrees with production
 * WordPress can mask a real key-matching bug or bake a wrong expected slug
 * into a test.
 *
 * Scope: transliterates Latin-1 Supplement + Latin Extended-A, plus the
 * non-locale-specific Extended-B and currency entries used by WordPress. This covers
 * French, German, Spanish, Portuguese, Italian, Nordic, Turkish, Polish,
 * Czech, Romanian, and Baltic accented characters — the realistic range for
 * casino/betting brand names. Remaining Unicode is encoded with WordPress's
 * URI-byte behavior, and existing percent octets plus save-context punctuation
 * are normalized the same way as `sanitize_title_with_dashes()`.
 *
 * Verified against a live WordPress 6.9.4 install (strike-odds.test) via
 * `wp eval-file` for: accented names, ampersands, apostrophes, underscores,
 * and consecutive punctuation.
 */

declare(strict_types=1);

if (!class_exists('WpSanitizeTitleFake')) {
    /**
     * @psalm-suppress UnusedClass
     */
    final class WpSanitizeTitleFake
    {
        /**
         * Verbatim copy of the Latin-1 Supplement + Latin Extended-A entries
         * from WordPress core's remove_accents() (wp-includes/formatting.php).
         *
         * @var array<string,string>
         */
        private const ACCENT_MAP = [
            // Decompositions for Latin-1 Supplement.
            'ª' => 'a',
            'º' => 'o',
            'À' => 'A',
            'Á' => 'A',
            'Â' => 'A',
            'Ã' => 'A',
            'Ä' => 'A',
            'Å' => 'A',
            'Æ' => 'AE',
            'Ç' => 'C',
            'È' => 'E',
            'É' => 'E',
            'Ê' => 'E',
            'Ë' => 'E',
            'Ì' => 'I',
            'Í' => 'I',
            'Î' => 'I',
            'Ï' => 'I',
            'Ð' => 'D',
            'Ñ' => 'N',
            'Ò' => 'O',
            'Ó' => 'O',
            'Ô' => 'O',
            'Õ' => 'O',
            'Ö' => 'O',
            'Ù' => 'U',
            'Ú' => 'U',
            'Û' => 'U',
            'Ü' => 'U',
            'Ý' => 'Y',
            'Þ' => 'TH',
            'ß' => 's',
            'à' => 'a',
            'á' => 'a',
            'â' => 'a',
            'ã' => 'a',
            'ä' => 'a',
            'å' => 'a',
            'æ' => 'ae',
            'ç' => 'c',
            'è' => 'e',
            'é' => 'e',
            'ê' => 'e',
            'ë' => 'e',
            'ì' => 'i',
            'í' => 'i',
            'î' => 'i',
            'ï' => 'i',
            'ð' => 'd',
            'ñ' => 'n',
            'ò' => 'o',
            'ó' => 'o',
            'ô' => 'o',
            'õ' => 'o',
            'ö' => 'o',
            'ø' => 'o',
            'ù' => 'u',
            'ú' => 'u',
            'û' => 'u',
            'ü' => 'u',
            'ý' => 'y',
            'þ' => 'th',
            'ÿ' => 'y',
            'Ø' => 'O',
            // Decompositions for Latin Extended-A.
            'Ā' => 'A',
            'ā' => 'a',
            'Ă' => 'A',
            'ă' => 'a',
            'Ą' => 'A',
            'ą' => 'a',
            'Ć' => 'C',
            'ć' => 'c',
            'Ĉ' => 'C',
            'ĉ' => 'c',
            'Ċ' => 'C',
            'ċ' => 'c',
            'Č' => 'C',
            'č' => 'c',
            'Ď' => 'D',
            'ď' => 'd',
            'Đ' => 'D',
            'đ' => 'd',
            'Ē' => 'E',
            'ē' => 'e',
            'Ĕ' => 'E',
            'ĕ' => 'e',
            'Ė' => 'E',
            'ė' => 'e',
            'Ę' => 'E',
            'ę' => 'e',
            'Ě' => 'E',
            'ě' => 'e',
            'Ĝ' => 'G',
            'ĝ' => 'g',
            'Ğ' => 'G',
            'ğ' => 'g',
            'Ġ' => 'G',
            'ġ' => 'g',
            'Ģ' => 'G',
            'ģ' => 'g',
            'Ĥ' => 'H',
            'ĥ' => 'h',
            'Ħ' => 'H',
            'ħ' => 'h',
            'Ĩ' => 'I',
            'ĩ' => 'i',
            'Ī' => 'I',
            'ī' => 'i',
            'Ĭ' => 'I',
            'ĭ' => 'i',
            'Į' => 'I',
            'į' => 'i',
            'İ' => 'I',
            'ı' => 'i',
            'Ĳ' => 'IJ',
            'ĳ' => 'ij',
            'Ĵ' => 'J',
            'ĵ' => 'j',
            'Ķ' => 'K',
            'ķ' => 'k',
            'ĸ' => 'k',
            'Ĺ' => 'L',
            'ĺ' => 'l',
            'Ļ' => 'L',
            'ļ' => 'l',
            'Ľ' => 'L',
            'ľ' => 'l',
            'Ŀ' => 'L',
            'ŀ' => 'l',
            'Ł' => 'L',
            'ł' => 'l',
            'Ń' => 'N',
            'ń' => 'n',
            'Ņ' => 'N',
            'ņ' => 'n',
            'Ň' => 'N',
            'ň' => 'n',
            'ŉ' => 'n',
            'Ŋ' => 'N',
            'ŋ' => 'n',
            'Ō' => 'O',
            'ō' => 'o',
            'Ŏ' => 'O',
            'ŏ' => 'o',
            'Ő' => 'O',
            'ő' => 'o',
            'Œ' => 'OE',
            'œ' => 'oe',
            'Ŕ' => 'R',
            'ŕ' => 'r',
            'Ŗ' => 'R',
            'ŗ' => 'r',
            'Ř' => 'R',
            'ř' => 'r',
            'Ś' => 'S',
            'ś' => 's',
            'Ŝ' => 'S',
            'ŝ' => 's',
            'Ş' => 'S',
            'ş' => 's',
            'Š' => 'S',
            'š' => 's',
            'Ţ' => 'T',
            'ţ' => 't',
            'Ť' => 'T',
            'ť' => 't',
            'Ŧ' => 'T',
            'ŧ' => 't',
            'Ũ' => 'U',
            'ũ' => 'u',
            'Ū' => 'U',
            'ū' => 'u',
            'Ŭ' => 'U',
            'ŭ' => 'u',
            'Ů' => 'U',
            'ů' => 'u',
            'Ű' => 'U',
            'ű' => 'u',
            'Ų' => 'U',
            'ų' => 'u',
            'Ŵ' => 'W',
            'ŵ' => 'w',
            'Ŷ' => 'Y',
            'ŷ' => 'y',
            'Ÿ' => 'Y',
            'Ź' => 'Z',
            'ź' => 'z',
            'Ż' => 'Z',
            'ż' => 'z',
            'Ž' => 'Z',
            'ž' => 'z',
            'ſ' => 's',
            // Non-locale-specific Latin Extended-B entries.
            'Ə' => 'E',
            'ǝ' => 'e',
            'Ș' => 'S',
            'ș' => 's',
            'Ț' => 'T',
            'ț' => 't',
            // Currency signs.
            '€' => 'E',
            '£' => '',
        ];

        /**
         * Mirror `sanitize_title($title, '', 'save')` with WordPress's default filter.
         */
        public static function sanitize(string $title): string
        {
            $title = self::removeAccents($title);
            $title = strip_tags($title);

            // Preserve valid escaped octets while removing every other percent sign.
            $title = preg_replace('|%([a-fA-F0-9][a-fA-F0-9])|', '---$1---', $title) ?? '';
            $title = str_replace('%', '', $title);
            $title = preg_replace('|---([a-fA-F0-9][a-fA-F0-9])---|', '%$1', $title) ?? '';

            if (preg_match('//u', $title) === 1) {
                if (function_exists('mb_strtolower')) {
                    $title = mb_strtolower($title, 'UTF-8');
                }
                $title = self::utf8UriEncode($title, 200);
            }

            $title = strtolower($title);
            $title = str_replace(
                ['%c2%a0', '%e2%80%91', '%e2%80%93', '%e2%80%94'],
                '-',
                $title
            );
            $title = str_replace(
                ['&nbsp;', '&#8209;', '&#160;', '&ndash;', '&#8211;', '&mdash;', '&#8212;'],
                '-',
                $title
            );
            $title = str_replace('/', '-', $title);
            $title = str_replace(
                [
                    '%c2%ad', '%c2%a1', '%c2%bf', '%c2%ab', '%c2%bb',
                    '%e2%80%b9', '%e2%80%ba', '%e2%80%98', '%e2%80%99',
                    '%e2%80%9c', '%e2%80%9d', '%e2%80%9a', '%e2%80%9b',
                    '%e2%80%9e', '%e2%80%9f', '%e2%80%a2', '%c2%a9',
                    '%c2%ae', '%c2%b0', '%e2%80%a6', '%e2%84%a2',
                    '%c2%b4', '%cb%8a', '%cc%81', '%cd%81', '%cc%80',
                    '%cc%84', '%cc%8c', '%e2%80%8b', '%e2%80%8c',
                    '%e2%80%8d', '%e2%80%8e', '%e2%80%8f', '%e2%80%aa',
                    '%e2%80%ab', '%e2%80%ac', '%e2%80%ad', '%e2%80%ae',
                    '%ef%bb%bf', '%ef%bf%bc',
                ],
                '',
                $title
            );
            $title = str_replace(
                [
                    '%e2%80%80', '%e2%80%81', '%e2%80%82', '%e2%80%83',
                    '%e2%80%84', '%e2%80%85', '%e2%80%86', '%e2%80%87',
                    '%e2%80%88', '%e2%80%89', '%e2%80%8a', '%e2%80%a8',
                    '%e2%80%a9', '%e2%80%af',
                ],
                '-',
                $title
            );
            $title = str_replace('%c3%97', 'x', $title);

            $title = preg_replace('/&.+?;/', '', $title) ?? '';
            $title = str_replace('.', '-', $title);
            $title = preg_replace('/[^%a-z0-9 _-]/', '', $title) ?? '';
            $title = preg_replace('/\s+/', '-', $title) ?? '';
            $title = preg_replace('/-+/', '-', $title) ?? '';
            return trim($title, '-');
        }

        private static function utf8UriEncode(string $value, int $length): string
        {
            $encoded = '';
            $encodedLength = 0;
            $byteLength = strlen($value);

            for ($index = 0; $index < $byteLength; $index++) {
                $byte = ord($value[$index]);
                if ($byte < 128) {
                    if ($length > 0 && $encodedLength + 1 > $length) {
                        break;
                    }
                    $encoded .= chr($byte);
                    $encodedLength++;
                    continue;
                }

                $octets = $byte < 224 ? 2 : ($byte < 240 ? 3 : 4);
                $encodedOctetLength = $octets * 3;
                if ($index + $octets > $byteLength
                    || ($length > 0 && $encodedLength + $encodedOctetLength > $length)
                ) {
                    break;
                }

                for ($offset = 0; $offset < $octets; $offset++) {
                    $encoded .= '%' . dechex(ord($value[$index + $offset]));
                }
                $encodedLength += $encodedOctetLength;
                $index += $octets - 1;
            }

            return $encoded;
        }

        private static function removeAccents(string $text): string
        {
            if (!preg_match('/[\x80-\xff]/', $text)) {
                return $text;
            }

            if (preg_match('//u', $text) === 1
                && function_exists('normalizer_is_normalized')
                && function_exists('normalizer_normalize')
                && !normalizer_is_normalized($text)
            ) {
                $normalized = normalizer_normalize($text);
                if (is_string($normalized)) {
                    $text = $normalized;
                }
            }

            return strtr($text, self::ACCENT_MAP);
        }
    }
}
