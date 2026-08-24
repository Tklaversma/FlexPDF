<?php

declare(strict_types=1);

namespace FlexPDF\Engine;

/** A styled span of text within a paragraph. */
final class InlineRun
{
    public function __construct(
        public string $text,
        public float $fontSize = 9.0,
        public bool $bold = false,
        public array $color = [0.12, 0.13, 0.16],
        public float $lineHeight = 1.35,
        public string $fontFamily = 'Helvetica',
        public bool $isBreak = false,
        public bool $italic = false,
        public string $verticalAlign = 'baseline', // baseline | super | sub | top | bottom
        public string $direction = 'auto',         // auto | ltr | rtl
        public string $hyphens = 'manual',         // none | manual | auto
        public string $whiteSpace = 'normal',      // normal | nowrap | pre | pre-wrap | pre-line
        public string $wordBreak = 'normal',       // normal | break-all | keep-all
        public string $overflowWrap = 'normal',    // normal | break-word | anywhere
        public string $textDecoration = 'none',    // none | underline | line-through | overline
        public float $letterSpacing = 0.0,
        public float $wordSpacing = 0.0,

        /*
         * The enclosing <a href>, if any. It rides on the run rather than on a
         * box because a link is inline: it has no box, and it may wrap across
         * lines. Carrying it here means each laid-out piece contributes its own
         * clickable rectangle, which is what a browser does too.
         */
        public ?string $href = null,

        /*
         * `visibility` is inherited but a descendant may turn it back on, and
         * a span inside a hidden paragraph is a run rather than a box, so the
         * flag has to travel with the run. A hidden run still measures and
         * still occupies its line: only the painting is skipped.
         */
        public bool $visible = true,

        /*
         * An atomic inline-level box: `display: inline-block`, and a replaced
         * element such as an `<img>`. It sits on the line as one unbreakable
         * item, so it travels as a run rather than as a sibling box: that is
         * what puts it *on* the line instead of on a line of its own. The run
         * carries no text, its advance is the box's margin box, and the box is
         * laid out before the line is built.
         */
        public ?Node $box = null,

        /*
         * `font-variant: small-caps`. Set on the span while it is being built
         * and read once, when the run is split: the pieces that come out
         * carry the flag only where they hold capitals standing in for
         * lowercase letters, which is what the writer needs to map them back
         * to the letters the author wrote.
         */
        public bool $smallCaps = false,

        /*
         * Which letters the caps axis shrinks: `small` the lower-case ones,
         * `all-small` every letter, `unicase` the upper-case ones. Read only
         * while the run is being split, and empty where the axis is `normal`.
         */
        public string $capsMode = '',

        /*
         * This piece continues the word the previous one started, so the line
         * breaker must not break in front of it. Splitting a run for small
         * caps is the only thing that sets it, and without it `Hamburger`
         * would gain a break opportunity after its capital.
         */
        public bool $joinsPrevious = false,

        /*
         * The OpenType features this run is shaped with, as sorted `tag=value`
         * pairs. `font-kerning`, `font-variant-ligatures`,
         * `font-variant-numeric` and `font-feature-settings` all resolve into
         * this one string, so measuring and drawing ask the face the same
         * question and a run built in PHP kerns like one built from HTML.
         */
        public string $fontFeatures = OpenTypeLayout::DEFAULT_KEY,

        /*
         * `font-stretch` as a percentage of normal. It selects a face rather
         * than transforming one, so it belongs beside the family and the two
         * booleans rather than in the feature string.
         */
        public float $fontStretch = 100.0,

        /*
         * `disc`, `circle` or `square` where this run is a list marker of one
         * of the three shapes CSS names, and null everywhere else.
         *
         * A browser draws those three rather than setting a character, so the
         * run's own text is only what a face would have offered and is not
         * what lands on the paper. Both spellings read it: an `outside` marker
         * hangs beside the line and an `inside` one takes an advance on it,
         * and neither shows a glyph. {@see markerMetrics}.
         */
        public ?string $markerShape = null,

        /*
         * The `list-style-image` layer this run is a marker for, and its box,
         * where the item spells its marker `inside`. Null everywhere else.
         *
         * An `outside` marker image hangs beside the line and travels on the
         * item's own node; an `inside` one is ON the line, so it takes an
         * advance like any other inline thing and has to travel on a run.
         * {@see markerMetrics}.
         *
         * @var array{image:?PdfImage,svg:?SvgDocument,gradient:?array}|null
         */
        public ?array $markerImage = null,
        public float $markerImageWidth = 0.0,
        public float $markerImageHeight = 0.0,

        /*
         * True where this run IS a list marker, whichever of the three shapes
         * a marker can take.
         *
         * The other three fields above each answer for one kind: a shape sets
         * `markerShape`, a picture sets `markerImage`, and an `<ol>`'s number
         * sets neither, because it is ordinary text and the only thing that
         * distinguishes it from the item's own words is that
         * {@see HtmlBuilder::listMarker} made it. So no combination of those
         * three can be asked "is this a marker", and a tagged document has to
         * ask exactly that: Chrome puts all three in a `Lbl` inside the `LI`
         * and the number is the one that was folded into the item's own mark.
         * Defect FS.
         */
        public bool $listMarker = false,
    ) {}

    /**
     * The inline boxes this run sits inside, outermost first.
     *
     * An inline element has no box in the tree: it is flattened into runs. Its
     * rect therefore has to travel on them, and it has to travel as a *stack*
     * rather than as one entry, because Chrome paints an outer band whole and
     * the nested one over it. Collapsing the two to one band per run notches
     * the outer one around the inner, which is right for an opaque fill and
     * wrong for a translucent one.
     *
     * `above` and `below` are the font box of the element that declared the
     * entry, not the line box and not the run's own font: Chrome fills 18 rows
     * for a `font-size: 20px` span on a `line-height: 16px` paragraph and 11
     * for a 12px one, and `<span style="background:red">a <b
     * style="font-size:8px">b</b></span>` fills 10.500pt over all of it.
     *
     * `id` is the element instance, so two siblings styled identically stay two
     * boxes; comparing the entries by value would merge them into one rect.
     *
     * @var list<array{
     *     id:int,
     *     ink:bool,
     *     color:array{0:float,1:float,2:float,3?:float}|null,
     *     above:float,
     *     below:float,
     *     padTop:float,
     *     padRight:float,
     *     padBottom:float,
     *     padLeft:float,
     *     border:array<string,array{width:float,style:string,color:array<int,float>}>|null
     * }>
     */
    public array $boxes = [];

    /**
     * `text-decoration-color`, or null where the line takes the text's own
     * color, which is what `currentcolor` means and is the initial value.
     *
     * @var array{0:float,1:float,2:float,3?:float}|null
     */
    public ?array $decorationColor = null;

    /**
     * `text-decoration-thickness` in points, or null for `auto`, which is the
     * tenth of the font size the writer has always used.
     */
    public ?float $decorationThickness = null;

    /**
     * `text-decoration-style`: `solid`, `double`, `dotted`, `dashed` or
     * `wavy`. The initial value is `solid`, which is the one line the writer
     * has always drawn.
     */
    public string $decorationStyle = 'solid';

    /**
     * `text-underline-offset` in points, or null for `auto`.
     *
     * A declared offset is measured from the alphabetic baseline to the **top**
     * of the underline, which is what Chrome does and what
     * `RY-deco-derive.html` reads off its own stream on five values: 0, 3px,
     * 6px, 12px and -3px put the line's top edge at exactly that many pixels
     * below the baseline. It reaches an underline alone: the same page carries
     * an overline and a line-through with the offset declared beside their own
     * controls and all four sit at the same place.
     */
    public ?float $underlineOffset = null;

    /**
     * How far an inline box pushes the line's advance in front of its first
     * fragment, and after its last one.
     *
     * Only at the element's real start and end edges: CSS's default
     * `box-decoration-break: slice` puts neither in the middle of an element
     * that wraps, which is what Chrome paints.
     *
     * @param array{padLeft:float,padRight:float,border:array<string,array{width:float,style:string,color:array<int,float>}>|null} $box
     */
    public static function leftEdge(array $box): float
    {
        return $box['padLeft'] + ($box['border']['left']['width'] ?? 0.0);
    }

    /** @param array{padLeft:float,padRight:float,border:array<string,array{width:float,style:string,color:array<int,float>}>|null} $box */
    public static function rightEdge(array $box): float
    {
        return $box['padRight'] + ($box['border']['right']['width'] ?? 0.0);
    }

    /**
     * How far above the line's baseline this run sits, in points, positive
     * upwards.
     *
     * It is resolved where the run is built rather than computed from
     * `$verticalAlign` here, because `super` and `sub` are a fraction of the
     * *parent's* font size and a run does not know its parent: a span at 24px
     * inside a 12px paragraph is raised the same 5px as one at 6px is. It
     * also accumulates down the inline tree, so a word inside a raised
     * element is raised with it whether or not it asks to be.
     */
    public float $baselineShift = 0.0;

    /**
     * The same run as it is styled on the block's first line, or null where
     * no `::first-line` applies.
     *
     * Which words end up on the first line is only known once the line has
     * been broken, and the styling changes where it breaks, so the formatter
     * measures and paints through this variant until the first line closes and
     * through the run itself afterwards. It carries no text of its own: the
     * text comes from the token either way, and only the style is read here.
     */
    public ?InlineRun $firstLine = null;

    /**
     * A `text-transform` the first line asks for that the run's own text has
     * not already been through.
     *
     * Every other property `::first-line` may set is read off the run at
     * measuring time, so swapping the run is enough. This one rewrites the
     * text, and the text on a line comes from the token rather than from the
     * run, so it has to be applied where the swap happens. It is `none` on
     * every run but a first-line variant that asks for something different
     * from what its own element already did.
     */
    public string $firstLineTransform = 'none';

    /**
     * The same run as `::first-letter` styles it, set on the one run that can
     * hold the block's first letter. The letter is split off it at build time,
     * so nothing downstream has to know the pseudo-element exists.
     */
    public ?InlineRun $firstLetter = null;

    /** `text-transform` as CSS applies it, and the only place that does it. */
    public static function transform(string $text, string $mode): string
    {
        return match ($mode) {
            'uppercase'  => mb_strtoupper($text),
            'lowercase'  => mb_strtolower($text),
            'capitalize' => mb_convert_case(mb_strtolower($text), MB_CASE_TITLE),
            default      => $text,
        };
    }

    /** Whether a line may break at a space inside this run. */
    public function wraps(): bool
    {
        return $this->whiteSpace !== 'nowrap' && $this->whiteSpace !== 'pre';
    }

    /** Whether runs of whitespace collapse to one space, as in normal flow. */
    public function collapsesWhitespace(): bool
    {
        return $this->whiteSpace === 'normal' || $this->whiteSpace === 'nowrap';
    }

    /** Whether a newline in the source is a line break rather than a space. */
    public function keepsNewlines(): bool
    {
        return $this->whiteSpace !== 'normal' && $this->whiteSpace !== 'nowrap';
    }

    /** Whether an over-long word may be split mid-word to fit. */
    public function breaksWords(): bool
    {
        return $this->wordBreak === 'break-all'
            || $this->overflowWrap === 'break-word'
            || $this->overflowWrap === 'anywhere';
    }

    /**
     * `font-synthesis`, the declaration as written, lowercased.
     *
     * The initial value lets the writer embolden and slant a face the family
     * does not carry; `none` forbids both, and naming one axis forbids the
     * other. Only `weight` and `style` reach anything here: small capitals are
     * synthesized by `font-variant-caps` and always have been, which round 28
     * measured against Chrome and found exact.
     */
    public string $fontSynthesis = 'weight style small-caps';

    /** @var array<string, Font|TrueTypeFont> */
    private static array $faceCache = [];

    /** @var array<string, array{0:bool,1:bool}> */
    private static array $synthCache = [];

    public static function clearFontCache(): void
    {
        self::$faceCache  = [];
        self::$synthCache = [];
    }

    /**
     * Whether this run's face has to be emboldened and slanted by the writer,
     * because the family carries no such face and `font-synthesis` allows it.
     *
     * @return array{0:bool,1:bool} embolden, slant
     */
    public function synthesis(): array
    {
        $key = $this->fontFamily . ':' . (int) $this->bold . ':' . (int) $this->italic
            . ':' . $this->fontStretch . ':' . $this->fontSynthesis;

        return self::$synthCache[$key] ??= (function (): array {
            [$bold, $italic] = FontRegistry::default()->synthesis(
                $this->fontFamily,
                $this->bold,
                $this->italic,
                $this->fontStretch,
            );

            $allowed = preg_split('/\s+/', $this->fontSynthesis) ?: [];

            return [
                $bold && in_array('weight', $allowed, true),
                $italic && in_array('style', $allowed, true),
            ];
        })();
    }

    /** Resolved face for this run. May be base-14 or an embedded TrueType. */
    public function font(): Font|TrueTypeFont
    {
        $key = $this->fontFamily . ':' . (int) $this->bold . ':' . (int) $this->italic
            . ':' . $this->fontStretch;

        return self::$faceCache[$key]
            ??= FontRegistry::default()->get(
                $this->fontFamily,
                $this->bold,
                $this->italic,
                $this->fontStretch,
            );
    }

    /** One CSS pixel in points, which is the grid a marker's box lands on. */
    private const float CSS_PIXEL = 0.75;

    /**
     * What Chrome leaves after an `inside` marker, beyond the marker's own
     * shape and one em, in CSS pixels.
     *
     * A constant rather than a fraction of anything: the same 1 at eighteen
     * font sizes on DejaVu and eighteen on Helvetica. Which side of the
     * marker's box it belongs to cannot be read off a page, because the shape
     * starts at the content edge either way.
     */
    private const float INSIDE_MARKER_PAD = 1.0;

    /**
     * What a browser leaves between a marker's box and the item's own content,
     * in CSS pixels.
     *
     * **One constant for three questions**, which is why it lives beside the
     * metrics rather than in the painter that first needed it. It is the gap
     * an `outside` shape marker hangs left of the content edge by
     * (`SQ-marker-metrics.html`, the same 7 at every size from 8px to 64px),
     * the gap an `outside` marker image hangs by (`RM-list-image.html`, the
     * same at fourteen sizes), and the gap an `inside` marker image leaves
     * before the item's text (`ST-marker-image.html`, exact at three font
     * sizes on a picture whose box grows with the font and three on one whose
     * box does not, six points on one term).
     */
    public const float MARKER_GAP = 7.0;

    /**
     * The three numbers a `disc`, `circle` or `square` marker is drawn from,
     * in points.
     *
     * All of it comes off the ascent in whole CSS pixels, and the integer
     * division is Chrome's own rather than tidiness: rounding instead moves
     * the bullet a pixel at seven of nineteen sizes.
     *
     *     box       the marker's own box, two thirds of the ascent. An
     *               `outside` marker hangs its box a constant 7 CSS pixels
     *               left of the content edge, and the shape's center sits half
     *               a box above the baseline.
     *     shape     the drawing inside that box, half the box and a pixel.
     *     rise      how far ABOVE the baseline the shape's TOP sits, in whole
     *               CSS pixels, which is defect GF and is the one number here
     *               that is not simply half of another.
     *     advance   what an `inside` marker takes on its line, which is the
     *               shape, one em and one pixel. Everything after it on the
     *               line moves by this much, which is why it is layout rather
     *               than ink: `SR-marker-inside.html`.
     *
     * **`rise` is half the box plus half the shape, and the whole of GF is
     * what happens when that lands on an exact half.** It does so at twelve of
     * the 23 ascents `SZ-marker-rounding.html` reaches over two faces, and
     * Chrome resolves the tie in BOTH directions: up at an ascent of 9, 15,
     * 33, 45 and 51, down at 10, 11, 17, 22, 41, 52 and 59. Rounding the sum
     * one way puts the bullet a pixel wrong at the other five. **The tie goes
     * up exactly where the box is even and the ascent is odd**, which is the
     * rule below and is exact at all 23. The step of two that made this look
     * unfittable falls straight out of it: an ascent of 51 and one of 52 give
     * the same box and the same shape, so the rise falls by one where the
     * ascent rises by one, and the marker's top moves two.
     *
     * A marker image answers the same three and answers them off its own box
     * rather than off the ascent, because a picture's size is the picture's.
     * Its advance is that box plus {@see MARKER_GAP}, which is the same gap
     * the `outside` spelling hangs by: the row is one constant in one place
     * rather than two that can drift apart.
     *
     * @return array{box:float,shape:float,rise:float,advance:float}
     */
    public function markerMetrics(): array
    {
        if ($this->markerImage !== null) {
            return [
                'box'     => $this->markerImageWidth,
                'shape'   => $this->markerImageWidth,
                'rise'    => $this->markerImageWidth,
                'advance' => $this->markerImageWidth + self::MARKER_GAP * self::CSS_PIXEL,
            ];
        }

        $px      = $this->fontSize / self::CSS_PIXEL;
        $ascent  = (int) $this->font()->pixelAscent($this->fontSize);
        $box     = intdiv($ascent * 2, 3);
        $shape   = intdiv($box + 1, 2);
        $rise    = intdiv($box + $shape, 2);

        if (($box + $shape) % 2 === 1 && $box % 2 === 0 && $ascent % 2 === 1) {
            $rise++;
        }

        return [
            'box'     => $box * self::CSS_PIXEL,
            'shape'   => $shape * self::CSS_PIXEL,
            'rise'    => $rise * self::CSS_PIXEL,
            'advance' => ($shape + $px + self::INSIDE_MARKER_PAD) * self::CSS_PIXEL,
        ];
    }
}
