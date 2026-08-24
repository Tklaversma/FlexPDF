<?php

declare(strict_types=1);

namespace FlexPDF\Engine;

/**
 * Arabic contextual shaping and bidirectional text handling.
 *
 * Arabic letters change shape according to what they join to on either side.
 * Rather than driving OpenType GSUB, this maps each letter to the equivalent
 * codepoint in Arabic Presentation Forms-B (U+FE70 to U+FEFF), which most fonts
 * carrying Arabic also carry. That covers the joining behaviour and the
 * required lam-alef ligatures without a shaping engine.
 *
 * Bidi is a practical subset of UAX #9: strong types set the base direction,
 * numbers and neutrals resolve against their surroundings, and runs are
 * reordered for display. It is not the full algorithm: no explicit
 * embedding controls, no isolates, no mirroring of paired brackets beyond
 * the common set.
 */
final class BidiText
{
    /** base => [isolated, final, initial, medial]; 0 means the form doesn't exist. */
    private const array FORMS = [
        0x0621 => [0xFE80, 0, 0, 0],
        0x0622 => [0xFE81, 0xFE82, 0, 0],
        0x0623 => [0xFE83, 0xFE84, 0, 0],
        0x0624 => [0xFE85, 0xFE86, 0, 0],
        0x0625 => [0xFE87, 0xFE88, 0, 0],
        0x0626 => [0xFE89, 0xFE8A, 0xFE8B, 0xFE8C],
        0x0627 => [0xFE8D, 0xFE8E, 0, 0],
        0x0628 => [0xFE8F, 0xFE90, 0xFE91, 0xFE92],
        0x0629 => [0xFE93, 0xFE94, 0, 0],
        0x062A => [0xFE95, 0xFE96, 0xFE97, 0xFE98],
        0x062B => [0xFE99, 0xFE9A, 0xFE9B, 0xFE9C],
        0x062C => [0xFE9D, 0xFE9E, 0xFE9F, 0xFEA0],
        0x062D => [0xFEA1, 0xFEA2, 0xFEA3, 0xFEA4],
        0x062E => [0xFEA5, 0xFEA6, 0xFEA7, 0xFEA8],
        0x062F => [0xFEA9, 0xFEAA, 0, 0],
        0x0630 => [0xFEAB, 0xFEAC, 0, 0],
        0x0631 => [0xFEAD, 0xFEAE, 0, 0],
        0x0632 => [0xFEAF, 0xFEB0, 0, 0],
        0x0633 => [0xFEB1, 0xFEB2, 0xFEB3, 0xFEB4],
        0x0634 => [0xFEB5, 0xFEB6, 0xFEB7, 0xFEB8],
        0x0635 => [0xFEB9, 0xFEBA, 0xFEBB, 0xFEBC],
        0x0636 => [0xFEBD, 0xFEBE, 0xFEBF, 0xFEC0],
        0x0637 => [0xFEC1, 0xFEC2, 0xFEC3, 0xFEC4],
        0x0638 => [0xFEC5, 0xFEC6, 0xFEC7, 0xFEC8],
        0x0639 => [0xFEC9, 0xFECA, 0xFECB, 0xFECC],
        0x063A => [0xFECD, 0xFECE, 0xFECF, 0xFED0],
        0x0641 => [0xFED1, 0xFED2, 0xFED3, 0xFED4],
        0x0642 => [0xFED5, 0xFED6, 0xFED7, 0xFED8],
        0x0643 => [0xFED9, 0xFEDA, 0xFEDB, 0xFEDC],
        0x0644 => [0xFEDD, 0xFEDE, 0xFEDF, 0xFEE0],
        0x0645 => [0xFEE1, 0xFEE2, 0xFEE3, 0xFEE4],
        0x0646 => [0xFEE5, 0xFEE6, 0xFEE7, 0xFEE8],
        0x0647 => [0xFEE9, 0xFEEA, 0xFEEB, 0xFEEC],
        0x0648 => [0xFEED, 0xFEEE, 0, 0],
        0x0649 => [0xFEEF, 0xFEF0, 0, 0],
        0x064A => [0xFEF1, 0xFEF2, 0xFEF3, 0xFEF4],
    ];

    /** lam + alef => [isolated, final] */
    private const array LAM_ALEF = [
        0x0622 => [0xFEF5, 0xFEF6],
        0x0623 => [0xFEF7, 0xFEF8],
        0x0625 => [0xFEF9, 0xFEFA],
        0x0627 => [0xFEFB, 0xFEFC],
    ];

    /** Combining marks are invisible to joining. */
    private static function isTransparent(int $cp): bool
    {
        return ($cp >= 0x064B && $cp <= 0x065F)
            || $cp === 0x0670
            || ($cp >= 0x06D6 && $cp <= 0x06ED)
            || ($cp >= 0x0610 && $cp <= 0x061A);
    }

    /** Can this letter join to the one after it? */
    private static function joinsForward(int $cp): bool
    {
        return isset(self::FORMS[$cp]) && self::FORMS[$cp][2] !== 0;
    }

    /** Can this letter join to the one before it? */
    private static function joinsBackward(int $cp): bool
    {
        return isset(self::FORMS[$cp]) && self::FORMS[$cp][1] !== 0;
    }

    public static function isArabic(int $cp): bool
    {
        return ($cp >= 0x0600 && $cp <= 0x06FF)
            || ($cp >= 0x0750 && $cp <= 0x077F)
            || ($cp >= 0xFB50 && $cp <= 0xFDFF)
            || ($cp >= 0xFE70 && $cp <= 0xFEFF);
    }

    public static function isHebrew(int $cp): bool
    {
        return ($cp >= 0x0590 && $cp <= 0x05FF) || ($cp >= 0xFB1D && $cp <= 0xFB4F);
    }

    public static function isRtl(int $cp): bool
    {
        return self::isArabic($cp) || self::isHebrew($cp);
    }

    public static function containsRtl(string $utf8): bool
    {
        return array_any(TrueTypeFont::codepoints($utf8), fn($cp) => self::isRtl($cp));
    }

    /** Base paragraph direction from the first strong character (UAX #9 P2). */
    public static function baseDirection(string $utf8): string
    {
        foreach (TrueTypeFont::codepoints($utf8) as $cp) {
            if (self::isRtl($cp)) {
                return 'rtl';
            }

            if (($cp >= 0x41 && $cp <= 0x5A) || ($cp >= 0x61 && $cp <= 0x7A) || $cp >= 0xC0) {
                return 'ltr';
            }
        }

        return 'ltr';
    }

    /**
     * Replace Arabic letters with their contextual presentation forms and
     * fold lam-alef pairs into their ligatures. Order is unchanged.
     */
    public static function shape(string $utf8): string
    {
        $cps = TrueTypeFont::codepoints($utf8);

        if ($cps === []) {
            return $utf8;
        }

        $out   = [];
        $count = count($cps);

        for ($i = 0; $i < $count; $i++) {
            $cp = $cps[$i];

            if (self::isTransparent($cp)) {
                $out[] = $cp;
                continue;
            }

            if (!isset(self::FORMS[$cp])) {
                $out[] = $cp;
                continue;
            }

            // Previous and next visible letters, skipping combining marks.
            $prev = null;

            for ($j = $i - 1; $j >= 0; $j--) {
                if (!self::isTransparent($cps[$j])) {
                    $prev = $cps[$j];
                    break;
                }
            }

            $next      = null;
            $nextIndex = null;

            for ($j = $i + 1; $j < $count; $j++) {
                if (!self::isTransparent($cps[$j])) {
                    $next      = $cps[$j];
                    $nextIndex = $j;
                    break;
                }
            }

            // Lam followed by alef is a required ligature, not two glyphs.
            if ($cp === 0x0644 && $next !== null && isset(self::LAM_ALEF[$next])) {
                $joinedBefore = $prev !== null && self::joinsForward($prev);
                $out[]        = self::LAM_ALEF[$next][$joinedBefore ? 1 : 0];
                $i            = $nextIndex;

                continue;
            }

            $linkBefore = $prev !== null && self::joinsForward($prev) && self::joinsBackward($cp);
            $linkAfter  = $next !== null && self::joinsBackward($next) && self::joinsForward($cp);

            $form = match (true) {
                $linkBefore && $linkAfter => 3, // medial
                $linkBefore               => 1, // final
                $linkAfter                => 2, // initial
                default                   => 0, // isolated
            };

            $glyph = self::FORMS[$cp][$form];
            $out[] = $glyph !== 0 ? $glyph : self::FORMS[$cp][0];
        }

        return self::encode($out);
    }

    /**
     * Reorder one directional segment for display. RTL segments are emitted
     * right-to-left; embedded digits and Latin stay left-to-right within them.
     */
    public static function reorder(string $utf8): string
    {
        $cps = TrueTypeFont::codepoints($utf8);

        if ($cps === []) {
            return $utf8;
        }

        // Split into maximal runs of "RTL-ish" and "LTR-ish" characters,
        // letting neutrals attach to the run on their left.
        $runs       = [];
        $current    = [];
        $currentRtl = null;

        foreach ($cps as $cp) {
            $type = self::directionOf($cp);

            if ($type === null) {
                $current[] = $cp; // neutral: stays with the current run
                continue;
            }

            $rtl = $type === 'rtl';

            if ($currentRtl === null) {
                $currentRtl = $rtl;
            } elseif ($rtl !== $currentRtl) {
                $runs[]     = [$currentRtl, $current];
                $current    = [];
                $currentRtl = $rtl;
            }

            $current[] = $cp;
        }

        if ($current !== []) {
            $runs[] = [$currentRtl ?? false, $current];
        }

        $out = [];

        foreach (array_reverse($runs) as [$rtl, $chars]) {
            // Trailing neutrals of an RTL run belong on its left once flipped.
            $out = array_merge($out, $rtl ? array_reverse($chars) : $chars);
        }

        return self::encode($out);
    }

    /** 'rtl', 'ltr', or null for neutral characters. */
    private static function directionOf(int $cp): ?string
    {
        if (self::isRtl($cp)) {
            return 'rtl';
        }

        if (($cp >= 0x30 && $cp <= 0x39)) {
            return 'ltr';
        }

        if (($cp >= 0x41 && $cp <= 0x5A) || ($cp >= 0x61 && $cp <= 0x7A)) {
            return 'ltr';
        }

        if ($cp >= 0xC0 && $cp < 0x0590) {
            return 'ltr';
        }

        if ($cp >= 0x0700 && !self::isRtl($cp)) {
            return 'ltr';
        }

        return null;
    }

    /** Shape then reorder, which is what a renderer needs before measuring. */
    public static function process(string $utf8): string
    {
        if (!self::containsRtl($utf8)) {
            return $utf8;
        }

        return self::reorder(self::shape($utf8));
    }

    /** Mirrored pairs swap when they sit in an RTL run. */
    private const array MIRRORED = [
        0x28 => 0x29,
        0x29 => 0x28,
        0x5B => 0x5D,
        0x5D => 0x5B,
        0x7B => 0x7D,
        0x7D => 0x7B,
        0x3C => 0x3E,
        0x3E => 0x3C,
        0xAB => 0xBB,
        0xBB => 0xAB,
    ];

    public static function mirror(int $cp): int
    {
        return self::MIRRORED[$cp] ?? $cp;
    }

    /** @var array<int,int[]>|null presentation form => base codepoint(s) */
    private static ?array $reverse = null;

    /**
     * Map a presentation form back to the letters it stands for, so the
     * ToUnicode CMap keeps the text searchable and copyable as real Arabic
     * rather than as shaping artifacts.
     *
     * @return int[]
     */
    public static function toBaseCodepoints(int $cp): array
    {
        if (self::$reverse === null) {
            self::$reverse = [];

            foreach (self::FORMS as $base => $forms) {
                foreach ($forms as $form) {
                    if ($form !== 0) {
                        self::$reverse[$form] = [$base];
                    }
                }
            }

            foreach (self::LAM_ALEF as $alef => $forms) {
                foreach ($forms as $form) {
                    self::$reverse[$form] = [0x0644, $alef];
                }
            }
        }

        return self::$reverse[$cp] ?? [$cp];
    }

    /** @param int[] $cps */
    private static function encode(array $cps): string
    {
        $out = '';

        foreach ($cps as $cp) {
            if ($cp < 0x80) {
                $out .= chr($cp);
            } elseif ($cp < 0x800) {
                $out .= chr(0xC0 | $cp >> 6) . chr(0x80 | $cp & 0x3F);
            } elseif ($cp < 0x10000) {
                $out .= chr(0xE0 | $cp >> 12) . chr(0x80 | ($cp >> 6) & 0x3F) . chr(0x80 | $cp & 0x3F);
            } else {
                $out .= chr(0xF0 | $cp >> 18) . chr(0x80 | ($cp >> 12) & 0x3F)
                    . chr(0x80 | ($cp >> 6) & 0x3F) . chr(0x80 | $cp & 0x3F);
            }
        }

        return $out;
    }
}
