<?php

declare(strict_types=1);

namespace FlexPDF\Engine;

use RuntimeException;

/**
 * The face reached for characters the face a document asked for cannot draw,
 * and the split of a run of text into the pieces each face draws.
 *
 * **The fallback happens per character, not per family.** {@see
 * FontRegistry::resolveFamily()} resolves a `font-family` list to ONE family
 * and stops, which is right: that is what the property means. But a family that
 * resolves is not a family that can draw every character of the document.
 * `font-family: Arial` resolves to base-14 Helvetica, Helvetica is written with
 * WinAnsi, and so Polish, Greek, Cyrillic, Hebrew and Arabic all came out as
 * `?`. A family-level fallback cannot fix that, because nothing missed at the
 * family level.
 *
 * **A face that carries every character of a run is not consulted here at
 * all.** {@see segments()} returns null in that case and every caller keeps its
 * original single-face path, so a document that renders correctly today is
 * written byte for byte as it was.
 */
final class FontFallback
{
    /** @var array<string,TrueTypeFont|false> parsed faces, false where the file is absent */
    private array $faces = [];

    /** @param array<string,string> $paths keyed by slot */
    public function __construct(
        private readonly array $paths,
        public readonly FontReport $report,
    ) {}

    /**
     * The fallback every face consults, or null when the caller turned it off.
     *
     * It is process-wide state, and deliberately so: the face classes are built
     * in a dozen places, some of them default parameter values, and a fallback
     * only half of them could see would measure a document one way and paint it
     * another. {@see FontRegistry::reset()} clears it and
     * {@see FontRegistry::default()} installs it, which is the same lifetime
     * the registry itself already has.
     */
    private static ?self $active = null;

    public static function active(): ?self
    {
        return self::$active;
    }

    public static function install(?self $fallback): void
    {
        self::$active = $fallback;

        // Every memoised width in the process was measured against a different
        // answer to "can this face draw this character", so none of them is
        // still true.
        InlineRun::clearFontCache();
    }

    /** Where the bundled faces live, so a caller can name them or replace them. */
    public static function bundledDirectory(): string
    {
        return dirname(__DIR__, 2) . '/resources/fonts';
    }

    /**
     * The real family name of the bundled face.
     *
     * Registered under the name the font itself carries. `/BaseFont` in the
     * written file carries the family the caller asked for, so a face
     * registered under an invented name would ship renamed, and this font's
     * licence permits modification only under a different name. Shipping it
     * under its own name is both the honest and the permitted spelling.
     */
    public const string FAMILY = 'DejaVu Sans';

    /**
     * The DejaVu Sans that ships with the package.
     *
     * Measured with this engine's own parser, DejaVu Sans is the one candidate
     * that carries Latin-1, Latin Extended-A and B, Greek, Cyrillic, Hebrew AND
     * Arabic in a single file: 256 of 256 Cyrillic, 135 of 144 Greek, the
     * Hebrew alphabet, 165 of 256 Arabic and 141 of 144 Arabic Presentation
     * Forms-B. DejaVu Serif carries 0 of 112 Hebrew and 0 of 256 Arabic, which
     * is why the serif face is not the one bundled. Arabic matters more here
     * than it would elsewhere, because bidirectional text and contextual
     * shaping are this engine's own work, and a fallback that cannot draw
     * Arabic leaves that work unreachable by default.
     *
     * All four slots are bundled rather than two. The engine can synthesize an
     * oblique, but it decides that per RUN from the family the document named,
     * so a real Helvetica-Oblique primary would leave a Cyrillic fallback
     * standing upright in the middle of slanted text.
     */
    public static function bundled(FontReport $report): self
    {
        $dir = self::bundledDirectory();

        return new self([
            'regular'     => $dir . '/DejaVuSans.ttf',
            'bold'        => $dir . '/DejaVuSans-Bold.ttf',
            'italic'      => $dir . '/DejaVuSans-Oblique.ttf',
            'bold-italic' => $dir . '/DejaVuSans-BoldOblique.ttf',
        ], $report);
    }

    /**
     * The bundled slots to try for a request, degrading one axis at a time.
     *
     * The same order {@see FontRegistry::get()} uses, for the same reason: a
     * regular face of the right family answers better than no face at all.
     *
     * @return list<string>
     */
    private static function slotOrder(bool $bold, bool $italic): array
    {
        return match (true) {
            $bold && $italic => ['bold-italic', 'bold', 'italic', 'regular'],
            $bold            => ['bold', 'bold-italic', 'regular', 'italic'],
            $italic          => ['italic', 'bold-italic', 'regular', 'bold'],
            default          => ['regular', 'italic', 'bold', 'bold-italic'],
        };
    }

    /**
     * One bundled slot, parsed on first use.
     *
     * Lazily, because the four files are about 2.6 MB and parsing one costs
     * real time. A document that never leaves Latin-1 never touches them, and
     * that is almost every document.
     */
    private function slot(string $slot): ?TrueTypeFont
    {
        if (isset($this->faces[$slot])) {
            return $this->faces[$slot] ?: null;
        }

        $path = $this->paths[$slot] ?? null;

        if ($path === null || ! is_file($path)) {
            $this->faces[$slot] = false;

            return null;
        }

        $suffix = match ($slot) {
            'bold'        => '-Bold',
            'italic'      => '-Oblique',
            'bold-italic' => '-BoldOblique',
            default       => '',
        };

        $face = new TrueTypeFont(
            $path,
            str_replace(' ', '', self::FAMILY) . $suffix,
            $slot === 'bold' || $slot === 'bold-italic',
            $slot === 'italic' || $slot === 'bold-italic',
            isFallback: true,
        );

        return ($this->faces[$slot] = $face);
    }

    /**
     * The bundled face that can draw this character, or null when none can.
     *
     * **The degradation is per character and not per slot**, which the oblique
     * faces are the reason for: DejaVu Sans Oblique and Bold Oblique carry
     * Cyrillic, Greek and Hebrew but **0 of 256 Arabic**, where the upright
     * faces carry 165. Choosing the slot once and stopping would leave Arabic
     * inside an `<em>` undrawable, so an italic request that misses falls
     * through to the upright face for that character alone. Arabic has no
     * italic tradition, so upright is also the right shape to land on.
     */
    public function faceFor(int $codepoint, bool $bold, bool $italic): ?TrueTypeFont
    {
        foreach (self::slotOrder($bold, $italic) as $slot) {
            $face = $this->slot($slot);

            if ($face !== null && $face->carries($codepoint)) {
                return $face;
            }
        }

        return null;
    }

    /** Every bundled face parsed so far, so a caller can register them. */
    public function loaded(): array
    {
        return array_values(array_filter($this->faces));
    }

    /**
     * Every bundled slot that parsed, keyed by slot name.
     *
     * For a caller that wants to register the bundle under its own family name
     * rather than reach it one character at a time, which is what
     * {@see FontRegistry::has()} does the first time a document names
     * {@see FAMILY}. All four are parsed here rather than one at a time,
     * because a document that names the family can ask for any of the four and
     * a bold slot left unregistered would be emboldened out of the regular
     * instead of drawn.
     *
     * @return array<string,TrueTypeFont>
     */
    public function slots(): array
    {
        $out = [];

        foreach (array_keys($this->paths) as $slot) {
            $face = $this->slot($slot);

            if ($face !== null) {
                $out[$slot] = $face;
            }
        }

        return $out;
    }

    /**
     * A run of text cut into the pieces each face draws, or **null when the
     * face the document asked for can draw all of it**.
     *
     * The null is the whole of the byte-safety promise: every caller checks for
     * it and takes its original single-face path, so nothing about a document
     * that renders correctly today changes.
     *
     * A character the primary can draw is always drawn by the primary, so
     * `Привет world` keeps `world` in the face the page asked for rather than
     * dragging it into the fallback. The one exception is whitespace BETWEEN
     * two fallback pieces, which is handed to the fallback as well: an Arabic
     * phrase has to reach the shaper as one string, and cutting it at every
     * space would shape each word as though it stood alone.
     *
     * @return list<array{0:Font|TrueTypeFont,1:string}>|null
     */
    public function segments(Font|TrueTypeFont $primary, string $text): ?array
    {
        if ($text === '') {
            return null;
        }

        $codepoints = TrueTypeFont::codepoints($text);
        $owners     = [];
        $switched   = false;

        foreach ($codepoints as $index => $codepoint) {
            if ($primary->carries($codepoint)) {
                $owners[$index] = $primary;

                continue;
            }

            $face = $this->faceFor($codepoint, $primary->bold, $primary->italic);

            if ($face !== null) {
                $owners[$index] = $face;
                $switched       = true;

                continue;
            }

            // Neither the primary nor any bundled slot has it. It stays with
            // the primary and is painted as whatever that encoding substitutes,
            // which is what happened before this class existed. The difference
            // is that the caller now hears about it.
            $owners[$index] = $primary;
            $this->report->noteCodepoint($codepoint);
        }

        if (! $switched) {
            return null;
        }

        $this->keepPhrasesWhole($codepoints, $owners, $primary);

        return self::group($codepoints, $owners);
    }

    /**
     * Whitespace sitting between two pieces drawn by the SAME fallback face
     * joins them.
     *
     * Without this an Arabic or Hebrew sentence arrives at the shaper one word
     * at a time, because every space between the words is a character the
     * primary can draw and would otherwise be handed back to it.
     *
     * @param list<int>                        $codepoints
     * @param array<int,Font|TrueTypeFont>     $owners
     */
    private function keepPhrasesWhole(array $codepoints, array &$owners, Font|TrueTypeFont $primary): void
    {
        $count = count($codepoints);

        for ($i = 0; $i < $count; $i++) {
            if ($owners[$i] !== $primary || ! self::isSpace($codepoints[$i])) {
                continue;
            }

            $end = $i;

            while ($end + 1 < $count && $owners[$end + 1] === $primary && self::isSpace($codepoints[$end + 1])) {
                $end++;
            }

            $before = $i > 0 ? $owners[$i - 1] : $primary;
            $after  = $end + 1 < $count ? $owners[$end + 1] : $primary;

            if ($before !== $primary && $before === $after) {
                for ($j = $i; $j <= $end; $j++) {
                    $owners[$j] = $before;
                }
            }

            $i = $end;
        }
    }

    private static function isSpace(int $codepoint): bool
    {
        return $codepoint === 0x20 || $codepoint === 0x09 || $codepoint === 0xA0;
    }

    /**
     * Consecutive characters with the same owner become one piece of text.
     *
     * @param list<int>                    $codepoints
     * @param array<int,Font|TrueTypeFont> $owners
     *
     * @return list<array{0:Font|TrueTypeFont,1:string}>
     */
    private static function group(array $codepoints, array $owners): array
    {
        $out    = [];
        $face   = $owners[0];
        $buffer = '';

        foreach ($codepoints as $index => $codepoint) {
            if ($owners[$index] !== $face) {
                $out[]  = [$face, $buffer];
                $face   = $owners[$index];
                $buffer = '';
            }

            $buffer .= self::utf8($codepoint);
        }

        $out[] = [$face, $buffer];

        return $out;
    }

    /** A code point back to the bytes it was decoded from. */
    private static function utf8(int $codepoint): string
    {
        $char = mb_chr($codepoint, 'UTF-8');

        if ($char === false) {
            throw new RuntimeException("cannot encode code point $codepoint");
        }

        return $char;
    }
}
