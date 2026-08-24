<?php

declare(strict_types=1);

namespace FlexPDF\Engine;

use RuntimeException;

/**
 * Resolves (family, weight) to a concrete face.
 *
 * Base-14 faces need no embedding but can only encode WinAnsi. TrueType faces
 * are subset and embedded, and can encode anything in their cmap. The layout
 * engine treats both identically; only the writer cares about the difference.
 */
final class FontRegistry
{
    /** @var array<string, Font|TrueTypeFont> */
    private array $faces = [];

    private static ?self $default = null;

    public static function default(): self
    {
        if (self::$default === null) {
            self::$default = new self();
            self::$default->registerBase14();
            FontFallback::install(FontFallback::bundled(self::$default->report));
        }

        return self::$default;
    }

    /** Reset the shared registry, used by tests and by every render. */
    public static function reset(): void
    {
        self::$default = null;
        FontFallback::install(null);
        InlineRun::clearFontCache();
    }

    /**
     * What this document asked for and did not get.
     *
     * A `font-family` that named nothing, and a character no face on the page
     * could draw. Neither refuses by default: {@see FontReport::strict()} is
     * how a caller asks to be told instead.
     */
    private FontReport $report;

    public function __construct()
    {
        $this->report = new FontReport();
    }

    public function report(): FontReport
    {
        return $this->report;
    }

    /**
     * Turn the bundled per-character fallback off, or point it at other files.
     *
     * Off means a character the resolved face cannot draw goes back to being
     * painted as `?` on a base-14 face and as the face's own empty box on a
     * TrueType one, which is what every version before this one did.
     */
    public function fallback(?FontFallback $fallback): void
    {
        FontFallback::install($fallback);
    }

    /**
     * The widths registered for each (family, bold, italic) slot, so a request
     * for one this family does not have can be matched against the ones it
     * does.
     *
     * @var array<string, float[]>
     */
    private array $widths = [];

    /** CSS Fonts 4 section 5.2's keywords, as percentages of normal. */
    private const array WIDTH_KEYWORDS = [
        'ultra-condensed' => 50.0,
        'extra-condensed' => 62.5,
        'condensed'       => 75.0,
        'semi-condensed'  => 87.5,
        'normal'          => 100.0,
        'semi-expanded'   => 112.5,
        'expanded'        => 125.0,
        'extra-expanded'  => 150.0,
        'ultra-expanded'  => 200.0,
    ];

    /**
     * A `font-stretch` value as a percentage, keyword or number alike.
     *
     * Anything unreadable is `normal`, which is the property's own initial
     * value, so a typo picks the face a document with no declaration would get
     * rather than no face at all.
     */
    public static function width(string $value): float
    {
        $value = strtolower(trim($value));

        if (isset(self::WIDTH_KEYWORDS[$value])) {
            return self::WIDTH_KEYWORDS[$value];
        }

        if (preg_match('/^([\d.]+)%$/', $value, $m) === 1) {
            return max(1.0, min(1000.0, (float) $m[1]));
        }

        return 100.0;
    }

    /** Whether a `font-weight` value asks for the bold slot of a family. */
    public static function bold(string $value): bool
    {
        $value = strtolower(trim($value));

        return $value === 'bold' || $value === 'bolder' || (is_numeric($value) && (int) $value >= 600);
    }

    /** Whether a `font-style` value asks for the italic slot of a family. */
    public static function italic(string $value): bool
    {
        $value = strtolower(trim($value));

        return $value === 'italic' || $value === 'oblique';
    }

    /**
     * The face a computed style asks for, which is the four questions above
     * asked together.
     *
     * It lives here because two callers ask it: the builder, for every box and
     * every run, and {@see StyleResolver}, which needs the face an `ex` or a
     * `ch` is measured in. Two answers to it is how a length and the glyphs
     * beside it would come out of different faces.
     *
     * @param array<string,string> $computed
     */
    public function faceFor(array $computed): Font|TrueTypeFont
    {
        return $this->get(
            $this->resolveFamily($computed['font-family'] ?? 'Helvetica'),
            self::bold($computed['font-weight'] ?? 'normal'),
            self::italic($computed['font-style'] ?? 'normal'),
            self::width($computed['font-stretch'] ?? 'normal'),
        );
    }

    private static function slot(string $family, bool $bold, bool $italic): string
    {
        return strtolower($family) . ':' . (int) $bold . ':' . (int) $italic;
    }

    private static function key(string $family, bool $bold, bool $italic, float $width = 100.0): string
    {
        return self::slot($family, $bold, $italic) . ':' . rtrim(rtrim(number_format($width, 3, '.', ''), '0'), '.');
    }

    /**
     * The three base-14 families a document can ask for by name. Symbol and
     * ZapfDingbats are the other two Adobe faces and are deliberately absent:
     * neither is WinAnsi, so neither can be measured by the same path.
     *
     * @var array<string,array{0:string,1:string,2:string,3:string}>
     */
    private const array BASE_14 = [
        'helvetica' => ['Helvetica', 'Helvetica-Bold', 'Helvetica-Oblique', 'Helvetica-BoldOblique'],
        'times'     => ['Times-Roman', 'Times-Bold', 'Times-Italic', 'Times-BoldItalic'],
        'courier'   => ['Courier', 'Courier-Bold', 'Courier-Oblique', 'Courier-BoldOblique'],
        // Two slots of their own, so the names Chrome lays out with the real
        // macOS faces stop borrowing Adobe's vertical metrics. They still draw
        // with the base-14 glyphs: see the `pdf` entry in `Font::FACES`.
        'times new roman' => ['Times New Roman', 'Times New Roman-Bold', 'Times New Roman-Italic', 'Times New Roman-BoldItalic'],
        'courier new'     => ['Courier New', 'Courier New-Bold', 'Courier New-Oblique', 'Courier New-BoldOblique'],
    ];

    public function registerBase14(): void
    {
        // Through register() rather than straight into the map, or the width
        // index does not know about these four and `has('Times')` reads false.
        foreach (self::BASE_14 as $family => [$regular, $bold, $italic, $boldItalic]) {
            $this->register($family, false, new Font($regular, false), false);
            $this->register($family, true, new Font($bold, true), false);
            $this->register($family, false, new Font($italic, false, true), true);
            $this->register($family, true, new Font($boldItalic, true, true), true);
        }
    }

    /**
     * `$width` is the face's own `font-stretch`, as a percentage.
     *
     * **Two faces of one family at different widths are two faces**, and until
     * round 37 they were one: the key had no width in it, so a document that
     * declared a regular `@font-face` and a condensed one under the same family
     * name kept only the second and painted every word of the document
     * condensed. On `RW-font-width.html` that is a 197 pixel string coming out
     * 177 wide, with no declaration anywhere asking for it.
     */
    public function register(
        string $family,
        bool $bold,
        Font|TrueTypeFont $face,
        bool $italic = false,
        float $width = 100.0,
    ): void {
        $this->faces[self::key($family, $bold, $italic, $width)] = $face;

        $slot = self::slot($family, $bold, $italic);

        if (!in_array($width, $this->widths[$slot] ?? [], true)) {
            $this->widths[$slot][] = $width;
        }

        // A run remembers whether its face had to be synthesized, and this is
        // the one call that can make that answer stale: a document whose
        // second `@font-face` registers the bold of a family whose regular
        // came first would keep emboldening it by hand. Round 37 paid for the
        // same shape twice, in two caches keyed on a run's own style.
        InlineRun::clearFontCache();
    }

    /**
     * Register a TrueType family from files. Any slot may be omitted.
     *
     * **`$width` is the face's own `font-stretch`, and without it two widths
     * of one family registered through here were one face.** The `@font-face`
     * path has passed it since round 37, {@see Html::layout}, and this one
     * defaulted every call to 100, so a document that registered a regular and
     * a condensed file under the same name through the API kept only the
     * second and painted every word condensed. That is round 37's own RW on
     * the API path and it is defect HK: `TW-api-width.html` w0 and w1 are one
     * width apart in Chrome and were the same 108.984pt here.
     */
    public function registerTrueType(
        string $family,
        string $regularPath,
        ?string $boldPath = null,
        ?string $italicPath = null,
        ?string $boldItalicPath = null,
        float $width = 100.0,
    ): void {
        $this->register($family, false, new TrueTypeFont($regularPath, $family), false, $width);

        if ($boldPath !== null) {
            $this->register($family, true, new TrueTypeFont($boldPath, $family . '-Bold', true), false, $width);
        }

        if ($italicPath !== null) {
            $this->register($family, false, new TrueTypeFont($italicPath, $family . '-Italic', false, true), true, $width);
        }

        if ($boldItalicPath !== null) {
            $this->register($family, true, new TrueTypeFont($boldItalicPath, $family . '-BoldItalic', true, true), true, $width);
        }
    }

    public function has(string $family): bool
    {
        if (($this->widths[self::slot($family, false, false)] ?? []) !== []) {
            return true;
        }

        return $this->adoptBundled($family);
    }

    /** Whether the bundled family has already been registered under its name. */
    private bool $bundledAdopted = false;

    /**
     * The bundled fallback family, registered the first time a document names
     * it, and not before.
     *
     * `font-family: 'DejaVu Sans'` resolved to Helvetica until round 91, which
     * is the wrong answer for a face the package ships in the same checkout,
     * and a family name a stylesheet written for another renderer may already
     * carry.
     *
     * **Lazily, and only for this one name**, so that a document that never
     * mentions it neither parses 2.6 MB of font nor writes a byte differently.
     * The registered faces are the fallback's OWN instances rather than a
     * second parse of the same files: a document that both names the family and
     * falls through to it for a character its primary face cannot draw then
     * embeds one subset instead of two.
     *
     * The bundle has to be installed at the moment the name is resolved. A
     * caller that turns the fallback off with {@see fallback()} before the
     * first mention makes the name unreachable, which is the same answer as a
     * package with no bundled face at all.
     */
    private function adoptBundled(string $family): bool
    {
        if ($this->bundledAdopted) {
            return false;
        }

        if (strcasecmp(trim($family), FontFallback::FAMILY) !== 0) {
            return false;
        }

        $fallback = FontFallback::active();

        if ($fallback === null) {
            return false;
        }

        $this->bundledAdopted = true;

        foreach ($fallback->slots() as $slot => $face) {
            $this->register(
                FontFallback::FAMILY,
                $slot === 'bold' || $slot === 'bold-italic',
                $face,
                $slot === 'italic' || $slot === 'bold-italic',
            );
        }

        return ($this->widths[self::slot(FontFallback::FAMILY, false, false)] ?? []) !== [];
    }

    /**
     * The first family in a `font-family` list that resolves to a face we hold.
     *
     * Taking only the first name meant `font-family: Menlo, monospace` asked
     * for Menlo, missed, and fell back to Helvetica: the fallback list, which
     * is the whole point of the property, went unread. A generic keyword maps
     * to the base-14 face that stands for it.
     *
     * It lives here rather than in the builder because an SVG `<text>` asks
     * the same question about the same list, and two answers to it is how a
     * chart label ended up in a face the page never named.
     */
    public function resolveFamily(string $list): string
    {
        foreach (explode(',', $list) as $candidate) {
            $name = trim(trim($candidate), "\"'");

            if ($name === '') {
                continue;
            }

            if ($this->has($name)) {
                return $name;
            }

            $generic = self::GENERIC_FAMILIES[strtolower($name)] ?? null;

            if ($generic !== null && $this->has($generic)) {
                return $generic;
            }

            // Recorded rather than refused. An entry that misses is what a
            // fallback list is FOR, so this is information for the caller and
            // not an error: `->fontReport()` is where it surfaces.
            $this->report->noteFamily($name);
        }

        // Nothing in the list resolved, so the document is about to be drawn in
        // a face it never named. That is the one case strict mode refuses.
        $this->report->noteUnresolved($list);

        return 'Helvetica';
    }

    /**
     * Generic keywords, and the aliases that name the same design under a
     * different license, mapped to the base-14 face that stands in for them.
     * Anything else falls through to the next entry in the author's own list.
     */
    private const array GENERIC_FAMILIES = [
        'serif'           => 'Times',
        'times'           => 'Times',
        'times new roman' => 'times new roman',
        'monospace'       => 'Courier',
        'ui-monospace'    => 'Courier',
        'courier'         => 'Courier',
        'courier new'     => 'courier new',
        'sans-serif'      => 'Helvetica',
        'ui-sans-serif'   => 'Helvetica',
        'system-ui'       => 'Helvetica',
        'arial'           => 'Helvetica',
        'helvetica'       => 'Helvetica',
        'helvetica neue'  => 'Helvetica',
        'cursive'         => 'Helvetica',
        'fantasy'         => 'Helvetica',
    ];

    /**
     * The width to use for a slot, CSS Fonts 4 section 5.2's font-width
     * matching, which is not "the nearest one".
     *
     * At or below normal, narrower faces are tried first and in descending
     * order, then wider ones ascending. Above normal it is the other way
     * round. So `font-stretch: expanded` on a family holding a regular and a
     * condensed face picks the **regular**, and Chrome agrees on
     * `RW-font-width.html` `x3`.
     */
    private function matchWidth(string $slot, float $wanted): ?float
    {
        $available = $this->widths[$slot] ?? [];

        if ($available === []) {
            return null;
        }

        $preferred = array_values(array_filter(
            $available,
            static fn(float $w): bool => $wanted <= 100.0 ? $w <= $wanted : $w >= $wanted,
        ));

        $rest = array_values(array_filter(
            $available,
            static fn(float $w): bool => $wanted <= 100.0 ? $w > $wanted : $w < $wanted,
        ));

        if ($wanted <= 100.0) {
            rsort($preferred);
            sort($rest);
        } else {
            sort($preferred);
            rsort($rest);
        }

        return array_merge($preferred, $rest)[0] ?? null;
    }

    /**
     * Resolve a face, degrading one axis at a time: exact match, then drop
     * italic, then drop bold, then fall back to the base-14 equivalent. The
     * width is matched inside each of those slots rather than after them,
     * because a condensed regular is a better answer for a condensed request
     * than a normal-width italic is.
     */
    public function get(
        string $family,
        bool $bold,
        bool $italic = false,
        float $width = 100.0,
    ): Font|TrueTypeFont {
        foreach ([[$bold, $italic], [$bold, false], [false, $italic], [false, false]] as [$b, $i]) {
            $matched = $this->matchWidth(self::slot($family, $b, $i), $width);

            if ($matched !== null) {
                return $this->faces[self::key($family, $b, $i, $matched)];
            }
        }

        // The same lazy hook `has()` carries, for the callers that ask for a
        // face by name without asking whether it exists first. It can only
        // fire on one name and only once, so the path a document that names
        // anything else takes is the Helvetica loop below, unchanged.
        if ($this->adoptBundled($family)) {
            return $this->get($family, $bold, $italic, $width);
        }

        foreach ([[$bold, $italic], [$bold, false], [false, false]] as [$b, $i]) {
            $key = self::key('helvetica', $b, $i);

            if (isset($this->faces[$key])) {
                return $this->faces[$key];
            }
        }
        throw new RuntimeException("no face for $family");
    }

    /**
     * Which of the two axes `get()` had to drop, so the writer can synthesize
     * what the family does not carry.
     *
     * A browser emboldens and slants a face it does not have, and this engine
     * degraded to the regular one and painted it plain, which is
     * `font-synthesis: none` by accident: on `RW-font-synth.html` Chrome moves
     * **7.31 of 255** from its own control for a synthetic bold and **16.09**
     * for a synthetic oblique, and the pre-round engine moved 0.00 on both.
     *
     * It asks the same question `get()` asks and in the same order, so a
     * family whose exact slot exists needs neither, and one that falls all the
     * way through to Helvetica needs neither either: a base-14 bold is a real
     * face.
     *
     * @return array{0:bool,1:bool} embolden, slant
     */
    public function synthesis(
        string $family,
        bool $bold,
        bool $italic = false,
        float $width = 100.0,
    ): array {
        foreach ([[$bold, $italic], [$bold, false], [false, $italic], [false, false]] as [$b, $i]) {
            if ($this->matchWidth(self::slot($family, $b, $i), $width) !== null) {
                return [$bold && ! $b, $italic && ! $i];
            }
        }

        return [false, false];
    }

    /** @return array<string, Font|TrueTypeFont> */
    public function all(): array
    {
        return $this->faces;
    }
}
