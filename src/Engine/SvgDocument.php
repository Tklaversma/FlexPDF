<?php

declare(strict_types=1);

namespace FlexPDF\Engine;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;
use FlexPDF\Engine\Support\Deadline;

/**
 * A small SVG renderer.
 *
 * SVG and PDF share a path model (move, line, cubic bezier, close), so
 * shapes translate almost directly. The work is in parsing the path grammar
 * (which allows implicit repeats, omitted separators and relative variants),
 * converting arcs to beziers, and mapping the viewBox onto the target rect.
 */
final class SvgDocument
{
    /**
     * Every property this reader takes off an element, as a presentation
     * attribute or out of its `style=""`.
     *
     * It is public because a rule in the page's own `<style>` block reaches an
     * element inside an inline `<svg>` as well, and the only declarations
     * worth carrying across are the ones this list can use.
     * {@see HtmlBuilder::styleInlineSvg} is the caller, and a property in one
     * place and not the other is a declaration silently dropped.
     *
     * @var list<string>
     */
    public const array STYLE_PROPERTIES = [
        'fill',
        'font-family',
        'font-size',
        'font-weight',
        'font-style',
        'text-anchor',
        'stroke',
        'stroke-width',
        'opacity',
        'fill-opacity',
        'stroke-opacity',
        'stroke-linecap',
    ];

    /** The budget of the render currently painting this document. */
    private ?Deadline $budget = null;

    /**
     * The document's `<linearGradient>` definitions, by id, collected once on
     * first use. Defect DP.
     *
     * @var array<string,array>|null
     */
    private ?array $gradients = null;

    /**
     * The font properties the containing element hands down, for an `<svg>`
     * that is part of the page rather than a file the page points at.
     *
     * An inline `<svg>` is an element in the document tree, so CSS inheritance
     * carries `font-family`, `font-size`, `font-weight` and `font-style`
     * straight into it and on into every `<text>` inside. Without them a chart
     * label fell back to Helvetica whatever the page said, which is a face no
     * document embeds, so an archival render of an ordinary invoice with a
     * chart in it refused (defect DY).
     *
     * An `<img src="chart.svg">` and a `background-image: url(chart.svg)` are
     * **separate documents** and inherit nothing, which Chrome agrees with:
     * both take the reader's own default face. So this is seeded at the one
     * builder site that knows the markup came from the page itself, and stays
     * empty everywhere else.
     *
     * @var array<string,string>
     */
    private array $inheritedFont = [];

    private function __construct(
        private readonly DOMElement $root,
        public readonly float $width,
        public readonly float $height,
        /** @var array{0:float,1:float,2:float,3:float} */
        public readonly array $viewBox,
        /**
         * Whether the file gave a size of its own, rather than one read off
         * the `viewBox`.
         *
         * A `viewBox` is a coordinate system and a **ratio**, not a length:
         * CSS Images §4 gives an SVG that declares neither `width` nor
         * `height` an intrinsic ratio and no intrinsic size at all, so a
         * `width: auto` box carrying one fills its containing block and takes
         * its height from the ratio, where this engine read the viewBox as a
         * size in points (defect BG).
         */
        public readonly bool $hasIntrinsicSize = true,
        /**
         * Whether the file carries a `viewBox` at all, which is where the
         * ratio comes from. Without one and without a size there is neither,
         * and CSS Images §5.2's 300x150px default object size is the whole
         * answer.
         */
        public readonly bool $hasViewBox = true,
        /**
         * The file's own `preserveAspectRatio`, normalized to an alignment and
         * a meet-or-slice keyword, and `xMidYMid meet` where it declares none.
         *
         * It is the file's business and not the box's: `object-fit` picks the
         * region an `<img>` hands over and this decides what the drawing does
         * inside it, which is why `fill` and `contain` are one picture for
         * every SVG that takes the default. {@see render}.
         */
        public readonly string $aspectRatio = 'xMidYMid meet',
    ) {}

    /**
     * Hand the document the font properties of the box it sits in.
     *
     * The size is in **user units**, which is the coordinate system the
     * `viewBox` sets up and not the paper: an inherited 16px in a viewBox that
     * doubles its coordinates paints at 32px, which is Chrome's answer on
     * `RM-svg-font.html` `s12`. A CSS pixel is one user unit, so the caller
     * passes the computed size in pixels and the viewBox scales it from there.
     *
     * @param array<string,string> $font
     */
    public function inheritFont(array $font): void
    {
        $this->inheritedFont = $font;
    }

    public static function load(string $path): ?self
    {
        if (!is_file($path) || !is_readable($path)) {
            return null;
        }

        $raw = file_get_contents($path);

        return $raw === false ? null : self::parse($raw);
    }

    public static function parse(string $markup): ?self
    {
        if (!str_contains($markup, '<svg')) {
            return null;
        }

        $dom = new DOMDocument();

        libxml_use_internal_errors(true);

        $ok = $dom->loadXML($markup, LIBXML_NOERROR | LIBXML_NOWARNING);

        libxml_clear_errors();

        if (!$ok || !$dom->documentElement instanceof DOMElement) {
            return null;
        }

        $svg     = $dom->documentElement;
        $vbAttr  = trim($svg->getAttribute('viewBox'));
        $viewBox = null;

        // `DOMDocument::loadHTML` lowercases attribute names, so an inline
        // <svg> re-serialized out of the page's own DOM arrives spelled
        // `viewbox`. A `.svg` file is parsed as XML and keeps its case.
        if ($vbAttr === '') {
            $vbAttr = trim($svg->getAttribute('viewbox'));
        }

        if ($vbAttr !== '') {
            $parts = array_values(array_filter(preg_split('/[\s,]+/', $vbAttr) ?: [], 'strlen'));

            if (count($parts) === 4) {
                $viewBox = array_map('floatval', $parts);
            }
        }

        $w = self::lengthAttr($svg->getAttribute('width'));
        $h = self::lengthAttr($svg->getAttribute('height'));

        if ($viewBox === null) {
            $viewBox = [0.0, 0.0, $w ?? 100.0, $h ?? 100.0];
        }

        // Both axes have to come from the file for it to have an intrinsic
        // size: CSS Images §4's "intrinsic dimensions" are a pair, and one
        // length beside a ratio resolves the other axis rather than standing
        // in for it.
        $sized      = $w !== null && $h !== null;
        $hasViewBox = $vbAttr !== '';

        $w ??= $viewBox[2];
        $h ??= $viewBox[3];

        return new self($svg, $w, $h, $viewBox, $sized, $hasViewBox, self::aspectRatioAttr($svg));
    }

    /**
     * `preserveAspectRatio` off the root element, normalized.
     *
     * The optional `defer` keyword in front is dropped: it only ever meant
     * anything to a `<use>` referencing an image, which SVG 2 removed.
     * Anything unrecognized falls back to the initial value, because an
     * invalid value is not an instruction to stretch.
     */
    private static function aspectRatioAttr(DOMElement $svg): string
    {
        $raw = trim($svg->getAttribute('preserveAspectRatio'));

        if ($raw === '') {
            // The same lowercasing `viewBox` suffers when an inline <svg> is
            // re-serialized out of the page's own DOM.
            $raw = trim($svg->getAttribute('preserveaspectratio'));
        }

        $parts = array_values(array_filter(preg_split('/\s+/', $raw) ?: [], 'strlen'));

        if (($parts[0] ?? '') === 'defer') {
            array_shift($parts);
        }

        $align = $parts[0] ?? '';
        $known = [
            'none', 'xMinYMin', 'xMidYMin', 'xMaxYMin',
            'xMinYMid', 'xMidYMid', 'xMaxYMid',
            'xMinYMax', 'xMidYMax', 'xMaxYMax',
        ];

        if (!in_array($align, $known, true)) {
            return 'xMidYMid meet';
        }

        return $align === 'none' || ($parts[1] ?? 'meet') !== 'slice'
            ? $align . ' meet'
            : $align . ' slice';
    }

    private static function lengthAttr(string $v): ?float
    {
        $v = trim($v);

        if ($v === '' || str_ends_with($v, '%')) {
            return null;
        }

        return preg_match('/^-?[\d.]+/', $v, $m) ? (float) $m[0] : null;
    }

    /** Draw into the given rect, in the page's CSS-style coordinates. */
    public function render(Pdf $pdf, float $x, float $y, float $w, float $h): void
    {
        $this->budget = $pdf->budget();

        [$minX, $minY, $vbW, $vbH] = $this->viewBox;

        if ($vbW <= 0 || $vbH <= 0) {
            return;
        }

        [$align, $meetOrSlice] = explode(' ', $this->aspectRatio);

        // `none` scales the two axes apart, which is the only value that does,
        // and every other one picks one scale for both: the smaller under
        // `meet`, so the whole drawing fits and the region is not filled, and
        // the larger under `slice`, so the region is filled and the drawing is
        // cut. `SQ-svg-par.html` p1 against p4: both cover the box and the
        // source's own top band is 12 rows of it against 10.
        $scaleX = $w / $vbW;
        $scaleY = $h / $vbH;

        if ($align !== 'none') {
            $scaleX = $scaleY = $meetOrSlice === 'slice'
                ? max($scaleX, $scaleY)
                : min($scaleX, $scaleY);
        }

        $offsetX = $x + self::alignShift($align, 'x', $w - $vbW * $scaleX);
        $offsetY = $y + self::alignShift($align, 'y', $h - $vbH * $scaleY);

        $pdf->raw("q\n");

        // A `slice` drawing is bigger than the region it was given, and the
        // viewport is what stops it painting over the page around it.
        if ($meetOrSlice === 'slice') {
            $pdf->pushClip($x, $y, $w, $h);
        }

        // Map user units onto the page, flipping y once for the whole tree.\
        $pdf->raw(
            sprintf(
                "%.6f 0 0 %.6f %.4f %.4f cm\n",
                $scaleX,
                -$scaleY,
                $offsetX - $minX * $scaleX,
                $pdf->pageHeightValue() - $offsetY + $minY * $scaleY,
            ),
        );

        $pdf->raw(sprintf("%.4f w\n", 1.0));

        /*
         * `fill` starts black rather than following the page's `color`, which
         * is SVG's own initial value and what Chrome paints: a `<text>` inside
         * a red paragraph is black unless it asks for `currentColor`.
         */
        $this->renderNode($pdf, $this->root, $this->inheritedFont + [
            'fill'           => '#000000',
            'stroke'         => 'none',
            'stroke-width'   => '1',
            'opacity'        => '1',
            'fill-opacity'   => '1',
            'stroke-opacity' => '1',
        ]);

        if ($meetOrSlice === 'slice') {
            $pdf->pop();
        }

        $pdf->raw("Q\n");
    }

    /**
     * Where the spare room on one axis goes: all of it after the drawing under
     * `Min`, all of it in front under `Max`, and half each way under `Mid`.
     *
     * Under `slice` the spare room is negative, and the same three keywords
     * then decide which end of the drawing is cut.
     */
    private static function alignShift(string $align, string $axis, float $spare): float
    {
        if ($align === 'none') {
            return 0.0;
        }

        $part = $axis === 'x' ? substr($align, 0, 4) : substr($align, 4);

        return match ($part) {
            'xMin', 'YMin' => 0.0,
            'xMax', 'YMax' => $spare,
            default        => $spare / 2.0,
        };
    }

    /**
     * @param array<string,string> $inherited
     * @param float $folded an ancestor's `opacity` that folds into this
     *                      subtree's paint instead of opening a group of its
     *                      own. {@see needsOwnGroup}.
     */
    private function renderNode(Pdf $pdf, DOMElement $el, array $inherited, float $folded = 1.0): void
    {
        foreach ($el->childNodes as $child) {
            if (!$child instanceof DOMElement) {
                continue;
            }

            $this->budget?->check('svg painting');

            $style = $this->styleFor($child, $inherited);
            $tag   = strtolower($child->localName ?: $child->nodeName);

            if ($tag === 'defs' || $tag === 'title' || $tag === 'desc' || $tag === 'metadata') {
                continue;
            }

            $transform = trim($child->getAttribute('transform'));

            if ($transform !== '') {
                $pdf->raw("q\n" . $this->transformMatrix($transform));
            }

            $group = max(0.0, min(1.0, (float) ($style['opacity'] ?? '1'))) * $folded;
            $path  = $tag === 'g' || $tag === 'svg' || $tag === 'text'
                ? ''
                : $this->shapePath($child, $tag);

            // A group is only needed where the element has two drawings to
            // composite against each other before they meet the page. One
            // drawing has nothing to composite with, so its `opacity` folds
            // into its paint alpha and the file pays nothing: no form XObject,
            // no `/Group`, no extra object. That is most of them, and it
            // matters because a form XObject carries the page's whole resource
            // dictionary, so a thousand of them is a thousand copies of it.
            $needsGroup = $group < 1.0 && $this->needsOwnGroup($child, $tag, $style);

            $alpha = $needsGroup ? 1.0 : $group;

            if ($needsGroup) {
                $pdf->beginGroup();
            }

            if ($tag === 'g' || $tag === 'svg') {
                $this->renderNode($pdf, $child, $style, $alpha);
            } elseif ($tag === 'text') {
                $this->paintText($pdf, $child, $style, $alpha);
            } elseif ($path !== '' && !$this->paintWithGradient($pdf, $child, $tag, $style, $path, $alpha)) {
                $pdf->raw($this->paintOps($pdf, $style, $path, $needsGroup, $alpha));
            }

            if ($needsGroup) {
                $this->closeOpacityGroup($pdf, $group);
            }

            if ($transform !== '') {
                $pdf->raw("Q\n");
            }
        }
    }

    /**
     * The `<linearGradient>` elements the document defines, by id.
     *
     * SVG paints a shape with `fill="url(#id)"`, and an id that resolves to
     * nothing at all used to fall through to the `#000000` default: a chart's
     * bars came out solid black, which is the worst available answer on a
     * white page. Defect DP.
     *
     * Only the linear form is collected. A radial one, a pattern and a
     * `userSpaceOnUse` gradient all fall back to the **first stop's color**
     * rather than to black, which is wrong by a shade instead of wrong by a
     * bar.
     *
     * @return array<string,array{stops:list<array{0:float,1:array{0:float,1:float,2:float,3:float}}>,x1:float,y1:float,x2:float,y2:float,bbox:bool}>
     */
    private function collectGradients(DOMDocument $dom): array
    {
        $resolver  = new StyleResolver();
        $gradients = [];

        foreach ($dom->getElementsByTagName('*') as $el) {
            if (strtolower($el->localName ?: $el->nodeName) !== 'lineargradient') {
                continue;
            }

            $id = trim($el->getAttribute('id'));

            if ($id === '') {
                continue;
            }

            $stops = [];

            foreach ($el->childNodes as $child) {
                if (!$child instanceof DOMElement
                    || strtolower($child->localName ?: $child->nodeName) !== 'stop'
                ) {
                    continue;
                }

                $offset = trim($child->getAttribute('offset'));
                $offset = str_ends_with($offset, '%')
                    ? (float) rtrim($offset, '%') / 100.0
                    : (float) $offset;

                $colour = $resolver->color(trim($child->getAttribute('stop-color')) ?: '#000000')
                    ?? [0.0, 0.0, 0.0];
                $alpha = $child->hasAttribute('stop-opacity')
                    ? (float) $child->getAttribute('stop-opacity')
                    : 1.0;

                $stops[] = [max(0.0, min(1.0, $offset)), [$colour[0], $colour[1], $colour[2], $alpha]];
            }

            if (count($stops) < 2) {
                continue;
            }

            $number = static fn(string $a, float $d): float => $el->hasAttribute($a)
                ? (float) rtrim(trim($el->getAttribute($a)), '%')
                    * (str_ends_with(trim($el->getAttribute($a)), '%') ? 0.01 : 1.0)
                : $d;

            $gradients[$id] = [
                'stops' => $stops,
                'x1'    => $number('x1', 0.0),
                'y1'    => $number('y1', 0.0),
                'x2'    => $number('x2', 1.0),
                'y2'    => $number('y2', 0.0),
                'bbox'  => strtolower(trim($el->getAttribute('gradientUnits'))) !== 'userspaceonuse',
            ];
        }

        return $gradients;
    }

    /** The id in a `url(#id)` paint reference, or null where it is not one. */
    private static function paintReference(string $value): ?string
    {
        return preg_match('/^url\(\s*#([^)\s]+)\s*\)$/i', trim($value), $m) === 1 ? $m[1] : null;
    }

    /**
     * A shape's bounding box in user units, or null where this engine cannot
     * work one out without walking the path.
     *
     * @return array{0:float,1:float,2:float,3:float}|null
     */
    private function shapeBBox(DOMElement $el, string $tag): ?array
    {
        $n = fn(string $a, float $d = 0.0): float => $el->hasAttribute($a) ? (float) $el->getAttribute($a) : $d;

        switch ($tag) {
            case 'rect':
                return [$n('x'), $n('y'), $n('width'), $n('height')];

            case 'circle':
                $r = $n('r');

                return [$n('cx') - $r, $n('cy') - $r, 2 * $r, 2 * $r];

            case 'ellipse':
                $rx = $n('rx');
                $ry = $n('ry');

                return [$n('cx') - $rx, $n('cy') - $ry, 2 * $rx, 2 * $ry];

            case 'polygon':
            case 'polyline':
                $nums = preg_split('/[\s,]+/', trim($el->getAttribute('points'))) ?: [];
                $xs   = [];
                $ys   = [];

                for ($i = 0; $i + 1 < count($nums); $i += 2) {
                    $xs[] = (float) $nums[$i];
                    $ys[] = (float) $nums[$i + 1];
                }

                if ($xs === []) {
                    return null;
                }

                return [min($xs), min($ys), max($xs) - min($xs), max($ys) - min($ys)];

            default:
                return null;
        }
    }

    /**
     * Whether this style puts ink down twice on one shape, which is a live
     * fill and a live stroke together.
     *
     * Such a shape is the only leaf that needs a transparency group for its
     * `opacity`, because the stroke lies over the fill and the two have to
     * flatten before the page sees them: `SK-svg-opacity.html` k4 is Chrome
     * reading a flat quarter across the fill, the overlap and the stroke.
     *
     * @param array<string,string> $style
     */
    /**
     * Whether this element has to composite in a group of its own before its
     * `opacity` is applied, rather than folding the alpha into its paint.
     *
     * A container needed one whatever was under it, which is the case round 45
     * took out for a leaf and left standing for a `<g>`: a group holding **one**
     * drawing has nothing to composite that drawing against, so its alpha
     * travels down to whatever does the painting and no form XObject is
     * written at all.
     *
     * **Only this element's own children are counted, and that is the whole
     * of it.** Where the only child is another container the question is asked
     * again one level down with the alpha folded in, so the group opens at the
     * first level that really does draw twice and each element is asked once.
     * Walking the subtree from every level instead would be quadratic in a
     * chain of `<g>` elements, which an author writes for free.
     *
     * @param array<string,string> $style the element's own resolved style
     */
    private function needsOwnGroup(DOMElement $el, string $tag, array $style): bool
    {
        if ($tag !== 'g' && $tag !== 'svg') {
            return $this->paintsTwice($style);
        }

        $painting = 0;

        foreach ($el->childNodes as $child) {
            if (!$child instanceof DOMElement) {
                continue;
            }

            $childTag = strtolower($child->localName ?: $child->nodeName);

            if (in_array($childTag, ['defs', 'title', 'desc', 'metadata'], true)) {
                continue;
            }

            if (++$painting > 1) {
                return true;
            }
        }

        return false;
    }

    private function paintsTwice(array $style): bool
    {
        $live = static fn(string $paint): bool => $paint !== 'none' && $paint !== 'transparent';

        return $live(strtolower(trim($style['fill'] ?? '#000000')))
            && $live(strtolower(trim($style['stroke'] ?? 'none')));
    }

    /**
     * Draw what the element put in its group, composited once at its own
     * `opacity`.
     *
     * `opacity` is a group and not a paint alpha, which is what
     * `SK-svg-opacity.html` k4 and k8 say: a shape whose stroke lies over its
     * own fill in the same color reads a flat 25 percent everywhere on
     * Chrome, and two overlapping shapes under a `<g opacity>` do too. Fading
     * each drawing on its own paints the overlap twice and it comes out
     * darker. {@see Pdf::closeGroup} puts a subtree that draws once back on the
     * page as it was, so the ordinary filled shape pays nothing for this.
     */
    private function closeOpacityGroup(Pdf $pdf, float $alpha): void
    {
        // The group's own coordinates are this document's user units, not the
        // page's, because everything below {@see render}'s `cm` is. The box is
        // the viewBox with a whole viewBox of slack on every side, so a shape
        // that overflows the viewport is still inside the form's `/BBox`.
        [$minX, $minY, $vbW, $vbH] = $this->viewBox;

        ['name' => $name, 'inline' => $inline] = $pdf->closeGroup([0.0, 0.0, 0.0, 0.0], [
            $minX - $vbW,
            $minY - $vbH,
            $minX + $vbW * 2.0,
            $minY + $vbH * 2.0,
        ], $alpha);

        if ($name === null && $inline === '') {
            return;
        }

        $pdf->pushOpacity($alpha);
        $name === null ? $pdf->raw($inline) : $pdf->drawGroup($name);
        $pdf->pop();
    }

    /** @param array<string,string> $inherited @return array<string,string> */
    private function styleFor(DOMElement $el, array $inherited): array
    {
        // `opacity` is the one property here that does NOT inherit: it names a
        // group, and a group's children are inside it rather than each wearing
        // it. `fill-opacity` and `stroke-opacity` do inherit, which is
        // `SK-svg-opacity.html` k11 against k12.
        $style = $inherited;
        unset($style['opacity']);

        // A style="" attribute wins over presentation attributes
        foreach (self::STYLE_PROPERTIES as $prop) {
            if ($el->hasAttribute($prop)) {
                $style[$prop] = trim($el->getAttribute($prop));
            }
        }

        if ($el->hasAttribute('style')) {
            foreach (explode(';', $el->getAttribute('style')) as $decl) {
                $bits = explode(':', $decl, 2);

                if (count($bits) === 2) {
                    $style[strtolower(trim($bits[0]))] = trim($bits[1]);
                }
            }
        }

        return $style;
    }

    /**
     * An SVG `<text>` element, and the `<tspan>`s inside it.
     *
     * The element was not handled at all: it fell through the shape switch,
     * produced no path and painted nothing, so a chart's labels were absent
     * from the page **and** from the text layer. Defect DO.
     *
     * **A `<tspan>` carrying its own `x` starts a new text chunk**, which SVG
     * anchors on its own, and one without a position simply continues the run
     * with its own fill. Reading `textContent` instead ran the two together:
     * `row one` and `row two` came out as `row onerow two` on one line.
     *
     * @param array<string,string> $style
     */
    private function paintText(Pdf $pdf, DOMElement $el, array $style, float $folded = 1.0): void
    {
        $x = $el->hasAttribute('x') ? (float) $el->getAttribute('x') : 0.0;
        $y = $el->hasAttribute('y') ? (float) $el->getAttribute('y') : 0.0;

        /** @var list<array{text:string,style:array<string,string>,x:float,y:float,starts:bool}> $segments */
        $segments = [];

        $collect = function (DOMNode $node, array $inherited, float &$x, float &$y) use (&$collect, &$segments): void {
            foreach ($node->childNodes as $child) {
                if ($child instanceof DOMText) {
                    $text = preg_replace('/\s+/u', ' ', $child->textContent) ?? '';

                    if (trim($text) !== '') {
                        $segments[] = ['text' => $text, 'style' => $inherited, 'x' => $x, 'y' => $y, 'starts' => false];
                    }

                    continue;
                }

                if (!$child instanceof DOMElement
                    || strtolower($child->localName ?: $child->nodeName) !== 'tspan'
                ) {
                    continue;
                }

                $own    = $this->styleFor($child, $inherited);
                $starts = $child->hasAttribute('x') || $child->hasAttribute('y');

                if ($child->hasAttribute('x')) {
                    $x = (float) $child->getAttribute('x');
                }

                if ($child->hasAttribute('y')) {
                    $y = (float) $child->getAttribute('y');
                }

                $before = count($segments);
                $collect($child, $own, $x, $y);

                if ($starts && isset($segments[$before])) {
                    $segments[$before]['starts'] = true;
                }
            }
        };

        $collect($el, $style, $x, $y);

        if ($segments === []) {
            return;
        }

        // A chunk is a run of segments sharing one start position, and the
        // anchor moves the whole chunk rather than each piece of it.
        $chunk = [];

        foreach ($segments as $i => $segment) {
            if ($segment['starts'] && $chunk !== []) {
                $this->paintTextChunk($pdf, $chunk, $folded);
                $chunk = [];
            }

            $chunk[] = $segment;
        }

        $this->paintTextChunk($pdf, $chunk, $folded);
    }

    /**
     * One anchored run of text, drawn segment by segment from its own origin.
     *
     * @param list<array{text:string,style:array<string,string>,x:float,y:float,starts:bool}> $chunk
     */
    private function paintTextChunk(Pdf $pdf, array $chunk, float $folded = 1.0): void
    {
        if ($chunk === []) {
            return;
        }

        $widths = [];
        $total  = 0.0;

        foreach ($chunk as $i => $segment) {
            $size = (float) ($segment['style']['font-size'] ?? '16');
            $face = $this->faceFor($segment['style']);
            $widths[$i] = $size > 0.0
                ? $face->stringWidth($segment['text'], $size, OpenTypeLayout::DEFAULT_KEY)
                : 0.0;
            $total     += $widths[$i];
        }

        $anchor = strtolower(trim($chunk[0]['style']['text-anchor'] ?? 'start'));
        $x      = $chunk[0]['x'] - match ($anchor) {
            'middle' => $total / 2.0,
            'end'    => $total,
            default  => 0.0,
        };

        foreach ($chunk as $i => $segment) {
            $size = (float) ($segment['style']['font-size'] ?? '16');
            $fill = strtolower(trim($segment['style']['fill'] ?? '#000000'));

            if ($size > 0.0 && $fill !== 'none' && $fill !== 'transparent') {
                $colour = new StyleResolver()->color($fill) ?? [0.0, 0.0, 0.0];
                // A text run puts ink down once, so its `opacity` arrives
                // folded into `$folded` rather than as a group of its own.
                // Reading `$segment['style']['opacity']` here as well would
                // fade the run by its own square.
                $alpha = (float) ($segment['style']['fill-opacity'] ?? '1') * $folded;

                $pdf->drawTextInUserSpace(
                    $this->faceFor($segment['style']),
                    $segment['text'],
                    $size,
                    $x,
                    $segment['y'],
                    [$colour[0], $colour[1], $colour[2], $alpha],
                );
            }

            $x += $widths[$i];
        }
    }

    /**
     * The face an SVG style resolves to.
     *
     * @param array<string,string> $style
     */
    private function faceFor(array $style): Font|TrueTypeFont
    {
        $registry = FontRegistry::default();
        $weight   = strtolower(trim($style['font-weight'] ?? 'normal'));
        $slant    = strtolower(trim($style['font-style'] ?? 'normal'));

        return $registry->get(
            $registry->resolveFamily($style['font-family'] ?? 'Helvetica'),
            $weight === 'bold' || $weight === 'bolder' || (is_numeric($weight) && (float) $weight >= 600),
            $slant === 'italic' || $slant === 'oblique',
        );
    }

    /**
     * Paint one shape with the gradient its `fill` names, if it names one.
     *
     * PDF's `sh` paints the whole clip region, so the shape's own path becomes
     * the clip and the shading runs between the two points the gradient
     * declares. In the default `objectBoundingBox` units those are fractions
     * of the shape's own box, which is why a bounding box is needed and why a
     * `<path>`, whose box this engine would have to walk the curve to find,
     * takes the fallback instead.
     *
     * Returns false when the caller should paint the shape the ordinary way.
     *
     * @param array<string,string> $style
     */
    private function paintWithGradient(
        Pdf $pdf,
        DOMElement $el,
        string $tag,
        array $style,
        string $path,
        float $folded = 1.0,
    ): bool
    {
        $id = self::paintReference($style['fill'] ?? '');

        if ($id === null) {
            return false;
        }

        $this->gradients ??= $this->collectGradients($el->ownerDocument);
        $gradient = $this->gradients[$id] ?? null;

        if ($gradient === null) {
            return false;
        }

        $box = $this->shapeBBox($el, $tag);

        if ($box === null) {
            return false;
        }

        [$bx, $by, $bw, $bh] = $box;

        if ($bw <= 0.0 || $bh <= 0.0) {
            return false;
        }

        $scaleX = $gradient['bbox'] ? $bw : 1.0;
        $scaleY = $gradient['bbox'] ? $bh : 1.0;
        $originX = $gradient['bbox'] ? $bx : 0.0;
        $originY = $gradient['bbox'] ? $by : 0.0;

        // The shading is the shape's fill, so `fill-opacity` fades it, and the
        // stroke below carries its own. Both go inside the clip's own `q`/`Q`.
        $fillAlpha = $pdf->paintAlphaState((float) ($style['fill-opacity'] ?? '1') * $folded, 1.0);

        $pdf->raw("q\n" . $path . "W n\n");

        if ($fillAlpha !== null) {
            $pdf->raw(sprintf("/%s gs\n", $fillAlpha));
        }

        $pdf->shadeAxial(
            $gradient['stops'],
            $originX + $gradient['x1'] * $scaleX,
            $originY + $gradient['y1'] * $scaleY,
            $originX + $gradient['x2'] * $scaleX,
            $originY + $gradient['y2'] * $scaleY,
        );
        $pdf->raw("Q\n");

        // A stroke is the shape's own and is painted after the fill, exactly
        // as the ordinary path does it.
        $stroke = strtolower(trim($style['stroke'] ?? 'none'));

        if ($stroke !== 'none' && $stroke !== 'transparent') {
            $colour      = new StyleResolver()->color($stroke) ?? [0.0, 0.0, 0.0];
            $strokeAlpha = $pdf->paintAlphaState(1.0, (float) ($style['stroke-opacity'] ?? '1') * $folded);

            $pdf->raw($strokeAlpha === null ? '' : sprintf("q\n/%s gs\n", $strokeAlpha));
            $pdf->raw($path);
            $pdf->raw(sprintf("%.4f %.4f %.4f RG\n", $colour[0], $colour[1], $colour[2]));
            $pdf->raw(sprintf("%.4f w\n", (float) ($style['stroke-width'] ?? 1)));
            $pdf->raw("S\n");
            $pdf->raw($strokeAlpha === null ? '' : "Q\n");
        }

        return true;
    }

    /**
     * The operators that paint one shape: its colors, its line width and the
     * fill/stroke operator that finishes the path.
     *
     * `$grouped` says the caller has this shape inside a transparency group of
     * its own for an `opacity` below 1. A fill and a stroke that overlap have
     * to be two drawings there rather than one `B`, because `B` composites
     * them separately against the backdrop and the group exists precisely to
     * stop that; two drawings inside the group flatten to one before the
     * group's own alpha is applied, which is `SK-svg-opacity.html` k4. It also
     * costs the shape its `Pdf::drawsOnce()` shortcut, which is the point:
     * with one drawing there is nothing to composite and the group is dropped.
     *
     * @param array<string,string> $style
     */
    private function paintOps(Pdf $pdf, array $style, string $path, bool $grouped, float $folded): string
    {
        $resolver = new StyleResolver();
        $fill     = strtolower(trim($style['fill'] ?? '#000000'));
        $stroke   = strtolower(trim($style['stroke'] ?? 'none'));

        // An unresolved `url(#id)` paint falls back to the first stop of the
        // gradient it names, where there is one, rather than to the `#000000`
        // default: wrong by a shade instead of wrong by a bar. Defect DP.
        $reference = self::paintReference($fill);

        if ($reference !== null) {
            $stops = $this->gradients[$reference]['stops'] ?? null;
            $fill  = $stops === null
                ? 'none'
                : sprintf('#%02x%02x%02x', ...array_map(
                    static fn(float $c): int => (int) round(max(0.0, min(1.0, $c)) * 255),
                    array_slice($stops[0][1], 0, 3),
                ));
        }

        $hasFill   = $fill !== 'none' && $fill !== 'transparent';
        $hasStroke = $stroke !== 'none' && $stroke !== 'transparent';

        $ops = '';

        if ($hasFill) {
            $c   = $resolver->color($fill) ?? [0.0, 0.0, 0.0];
            $ops .= sprintf("%.4f %.4f %.4f rg\n", $c[0], $c[1], $c[2]);
        }

        if ($hasStroke) {
            $c   = $resolver->color($stroke) ?? [0.0, 0.0, 0.0];
            $ops .= sprintf("%.4f %.4f %.4f RG\n", $c[0], $c[1], $c[2]);
            $ops .= sprintf("%.4f w\n", (float) ($style['stroke-width'] ?? 1));
        }

        // `fill-opacity` and `stroke-opacity` are paint alphas rather than
        // groups, which is `SK-svg-opacity.html` k5 and k6: Chrome fades the
        // fill and leaves the stroke over it solid, then the other way round.
        // That is PDF's `/ca` and `/CA` exactly. The state is pushed and
        // popped around this one shape or it reaches every sibling after it.
        $alpha = $pdf->paintAlphaState(
            (float) ($style['fill-opacity'] ?? '1') * $folded,
            (float) ($style['stroke-opacity'] ?? '1') * $folded,
        );

        // `B` is one operator and two drawings, and no two readers agree what
        // it means once the two carry different alphas: pdfium paints the
        // overlap of a solid fill and a quarter-strength stroke at a quarter
        // rather than solid, which is `SK-svg-opacity.html` k6 and 1,296
        // pixels of it. Spelling it `f` then `S` says the same thing with no
        // room for a reading, and it is also what makes a grouped shape's two
        // drawings flatten inside the group rather than against the page (k4).
        $splitPaint = $hasFill && $hasStroke && ($grouped || $alpha !== null);

        $ops .= $splitPaint
            ? "f\n" . $path . "S\n"
            : match (true) {
                $hasFill && $hasStroke => "B\n",
                $hasFill               => "f\n",
                $hasStroke             => "S\n",
                default                => "n\n",
            };

        // The path comes FIRST and the colors after it, which is the order
        // this file has always written and is worth keeping to the byte: put
        // the colors in front and every document with an inline `<svg>` in it
        // is a different file for no reason anyone can see. A shape declaring
        // none of the three opacities writes exactly what it wrote before.
        return $alpha === null
            ? $path . $ops
            : sprintf("q\n/%s gs\n", $alpha) . $path . $ops . "Q\n";
    }

    private function shapePath(DOMElement $el, string $tag): string
    {
        $n = fn(string $a, float $d = 0.0): float => $el->hasAttribute($a) ? (float) $el->getAttribute($a) : $d;

        switch ($tag) {
            case 'rect':
                $x = $n('x');
                $y = $n('y');
                $w = $n('width');
                $h = $n('height');

                if ($w <= 0 || $h <= 0) {
                    return '';
                }

                $rx = $n('rx', $n('ry'));

                if ($rx > 0) {
                    return $this->roundedRect($x, $y, $w, $h, min($rx, $w / 2, $h / 2));
                }

                return sprintf("%.4f %.4f %.4f %.4f re\n", $x, $y, $w, $h);

            case 'circle':
                return $this->ellipse($n('cx'), $n('cy'), $n('r'), $n('r'));

            case 'ellipse':
                return $this->ellipse($n('cx'), $n('cy'), $n('rx'), $n('ry'));

            case 'line':
                return sprintf("%.4f %.4f m %.4f %.4f l\n", $n('x1'), $n('y1'), $n('x2'), $n('y2'));

            case 'polyline':
            case 'polygon':
                $pts = array_map(
                    'floatval',
                    array_values(
                        array_filter(
                            preg_split('/[\s,]+/', trim($el->getAttribute('points'))) ?: [],
                            'strlen',
                        ),
                    ),
                );

                if (count($pts) < 4) {
                    return '';
                }

                $out = sprintf("%.4f %.4f m\n", $pts[0], $pts[1]);

                for ($i = 2; $i + 1 < count($pts); $i += 2) {
                    $out .= sprintf("%.4f %.4f l\n", $pts[$i], $pts[$i + 1]);
                }

                return $out . ($tag === 'polygon' ? "h\n" : '');

            case 'path':
                return $this->parsePath($el->getAttribute('d'));
        }

        return '';
    }

    private function roundedRect(float $x, float $y, float $w, float $h, float $r): string
    {
        $k = $r * 0.5523;

        return sprintf("%.4f %.4f m\n", $x + $r, $y)
            . sprintf("%.4f %.4f l\n", $x + $w - $r, $y)
            . sprintf("%.4f %.4f %.4f %.4f %.4f %.4f c\n", $x + $w - $r + $k, $y, $x + $w, $y + $r - $k, $x + $w, $y + $r)
            . sprintf("%.4f %.4f l\n", $x + $w, $y + $h - $r)
            . sprintf("%.4f %.4f %.4f %.4f %.4f %.4f c\n", $x + $w, $y + $h - $r + $k, $x + $w - $r + $k, $y + $h, $x + $w - $r, $y + $h)
            . sprintf("%.4f %.4f l\n", $x + $r, $y + $h)
            . sprintf("%.4f %.4f %.4f %.4f %.4f %.4f c\n", $x + $r - $k, $y + $h, $x, $y + $h - $r + $k, $x, $y + $h - $r)
            . sprintf("%.4f %.4f l\n", $x, $y + $r)
            . sprintf("%.4f %.4f %.4f %.4f %.4f %.4f c\n", $x, $y + $r - $k, $x + $r - $k, $y, $x + $r, $y)
            . "h\n";
    }

    private function ellipse(float $cx, float $cy, float $rx, float $ry): string
    {
        if ($rx <= 0 || $ry <= 0) {
            return '';
        }

        $kx = $rx * 0.5523;
        $ky = $ry * 0.5523;

        return sprintf("%.4f %.4f m\n", $cx + $rx, $cy)
            . sprintf("%.4f %.4f %.4f %.4f %.4f %.4f c\n", $cx + $rx, $cy + $ky, $cx + $kx, $cy + $ry, $cx, $cy + $ry)
            . sprintf("%.4f %.4f %.4f %.4f %.4f %.4f c\n", $cx - $kx, $cy + $ry, $cx - $rx, $cy + $ky, $cx - $rx, $cy)
            . sprintf("%.4f %.4f %.4f %.4f %.4f %.4f c\n", $cx - $rx, $cy - $ky, $cx - $kx, $cy - $ry, $cx, $cy - $ry)
            . sprintf("%.4f %.4f %.4f %.4f %.4f %.4f c\n", $cx + $kx, $cy - $ry, $cx + $rx, $cy - $ky, $cx + $rx, $cy)
            . "h\n";
    }

    /**
     * The SVG path grammar: commands may repeat implicitly, separators are
     * optional, and lowercase variants are relative to the current point.
     */
    private function parsePath(string $d): string
    {
        $d = trim($d);

        if ($d === '') {
            return '';
        }

        preg_match_all('/([MmLlHhVvCcSsQqTtAaZz])|(-?(?:\d*\.\d+|\d+)(?:[eE][-+]?\d+)?)/', $d, $m, PREG_SET_ORDER);

        $tokens = [];

        foreach ($m as $t) {
            $tokens[] = ($t[1] ?? '') !== '' ? $t[1] : (float) $t[2];
        }

        $out       = '';
        $i         = 0;
        $cx        = $cy = 0.0; // current point
        $sx        = $sy = 0.0; // subpath start
        $lastCtrlX = $lastCtrlY = null;
        $cmd       = '';

        $next = function () use (&$tokens, &$i): float {
            return is_float($tokens[$i] ?? null) ? (float) $tokens[$i++] : 0.0;
        };

        $hasNumber = fn(): bool => isset($tokens[$i]) && is_float($tokens[$i]);

        $sinceCheck = 0;

        while ($i < count($tokens)) {
            // One `d` attribute can carry six figures of curves, so the whole
            // budget can be spent inside this single loop. Sampled for the
            // same reason layout samples it.
            if (++$sinceCheck >= 512) {
                $sinceCheck = 0;
                $this->budget?->check('svg path');
            }

            if (is_string($tokens[$i])) {
                $cmd = $tokens[$i++];
            } elseif ($cmd === '') {
                $i++;
                continue;
            } elseif ($cmd === 'M') {
                $cmd = 'L';
            } elseif ($cmd === 'm') {
                $cmd = 'l';
            }

            $rel   = ctype_lower($cmd);
            $upper = strtoupper($cmd);

            switch ($upper) {
                case 'M':
                    $x = $next();
                    $y = $next();

                    if ($rel) {
                        $x += $cx;
                        $y += $cy;
                    }

                    $out       .= sprintf("%.4f %.4f m\n", $x, $y);
                    $cx        = $sx = $x;
                    $cy        = $sy = $y;
                    $lastCtrlX = $lastCtrlY = null;

                    break;

                case 'L':
                    $x = $next();
                    $y = $next();

                    if ($rel) {
                        $x += $cx;
                        $y += $cy;
                    }

                    $out       .= sprintf("%.4f %.4f l\n", $x, $y);
                    $cx        = $x;
                    $cy        = $y;
                    $lastCtrlX = $lastCtrlY = null;
                    break;

                case 'H':
                    $x = $next();

                    if ($rel) {
                        $x += $cx;
                    }

                    $out       .= sprintf("%.4f %.4f l\n", $x, $cy);
                    $cx        = $x;
                    $lastCtrlX = $lastCtrlY = null;
                    break;

                case 'V':
                    $y = $next();

                    if ($rel) {
                        $y += $cy;
                    }

                    $out       .= sprintf("%.4f %.4f l\n", $cx, $y);
                    $cy        = $y;
                    $lastCtrlX = $lastCtrlY = null;
                    break;

                case 'C':
                    $x1 = $next();
                    $y1 = $next();
                    $x2 = $next();
                    $y2 = $next();
                    $x  = $next();
                    $y  = $next();

                    if ($rel) {
                        $x1 += $cx;
                        $y1 += $cy;
                        $x2 += $cx;
                        $y2 += $cy;
                        $x  += $cx;
                        $y  += $cy;
                    }

                    $out       .= sprintf("%.4f %.4f %.4f %.4f %.4f %.4f c\n", $x1, $y1, $x2, $y2, $x, $y);
                    $lastCtrlX = $x2;
                    $lastCtrlY = $y2;
                    $cx        = $x;
                    $cy        = $y;
                    break;

                case 'S':
                    $x2 = $next();
                    $y2 = $next();
                    $x  = $next();
                    $y  = $next();

                    if ($rel) {
                        $x2 += $cx;
                        $y2 += $cy;
                        $x  += $cx;
                        $y  += $cy;
                    }

                    // Reflect the previous control point through the current one
                    $x1        = $lastCtrlX === null ? $cx : 2 * $cx - $lastCtrlX;
                    $y1        = $lastCtrlY === null ? $cy : 2 * $cy - $lastCtrlY;
                    $out       .= sprintf("%.4f %.4f %.4f %.4f %.4f %.4f c\n", $x1, $y1, $x2, $y2, $x, $y);
                    $lastCtrlX = $x2;
                    $lastCtrlY = $y2;
                    $cx        = $x;
                    $cy        = $y;
                    break;

                case 'Q':
                case 'T':
                    if ($upper === 'Q') {
                        $qx = $next();
                        $qy = $next();
                        $x  = $next();
                        $y  = $next();

                        if ($rel) {
                            $qx += $cx;
                            $qy += $cy;
                            $x  += $cx;
                            $y  += $cy;
                        }
                    } else {
                        $x = $next();
                        $y = $next();

                        if ($rel) {
                            $x += $cx;
                            $y += $cy;
                        }

                        $qx = $lastCtrlX === null ? $cx : 2 * $cx - $lastCtrlX;
                        $qy = $lastCtrlY === null ? $cy : 2 * $cy - $lastCtrlY;
                    }

                    // Quadratic to cubic: control points sit two thirds of the way
                    $x1        = $cx + 2 / 3 * ($qx - $cx);
                    $y1        = $cy + 2 / 3 * ($qy - $cy);
                    $x2        = $x + 2 / 3 * ($qx - $x);
                    $y2        = $y + 2 / 3 * ($qy - $y);
                    $out       .= sprintf("%.4f %.4f %.4f %.4f %.4f %.4f c\n", $x1, $y1, $x2, $y2, $x, $y);
                    $lastCtrlX = $qx;
                    $lastCtrlY = $qy;
                    $cx        = $x;
                    $cy        = $y;
                    break;

                case 'A':
                    $rx    = abs($next());
                    $ry    = abs($next());
                    $rot   = $next();
                    $large = $next() != 0.0;
                    $sweep = $next() != 0.0;
                    $x     = $next();
                    $y     = $next();

                    if ($rel) {
                        $x += $cx;
                        $y += $cy;
                    }

                    $out       .= $this->arcToBezier($cx, $cy, $rx, $ry, $rot, $large, $sweep, $x, $y);
                    $cx        = $x;
                    $cy        = $y;
                    $lastCtrlX = $lastCtrlY = null;
                    break;

                case 'Z':
                    $out       .= "h\n";
                    $cx        = $sx;
                    $cy        = $sy;
                    $lastCtrlX = $lastCtrlY = null;
                    break;
            }

            if (!$hasNumber() && isset($tokens[$i]) && !is_string($tokens[$i])) {
                $i++;
            }
        }

        return $out;
    }

    /** Endpoint-parameterized arc to a sequence of cubic beziers (F.6.5). */
    private function arcToBezier(
        float $x1,
        float $y1,
        float $rx,
        float $ry,
        float $rotDeg,
        bool $large,
        bool $sweep,
        float $x2,
        float $y2,
    ): string {
        if ($rx <= 0 || $ry <= 0 || (abs($x1 - $x2) < 1e-9 && abs($y1 - $y2) < 1e-9)) {
            return sprintf("%.4f %.4f l\n", $x2, $y2);
        }

        $phi  = deg2rad($rotDeg);
        $cosP = cos($phi);
        $sinP = sin($phi);

        $dx  = ($x1 - $x2) / 2;
        $dy  = ($y1 - $y2) / 2;
        $x1p = $cosP * $dx + $sinP * $dy;
        $y1p = -$sinP * $dx + $cosP * $dy;

        // Scale the radii up if they can't span the endpoints.
        $lambda = ($x1p ** 2) / ($rx ** 2) + ($y1p ** 2) / ($ry ** 2);

        if ($lambda > 1) {
            $rx *= sqrt($lambda);
            $ry *= sqrt($lambda);
        }

        $sign = $large === $sweep ? -1 : 1;
        $num  = $rx ** 2 * $ry ** 2 - $rx ** 2 * $y1p ** 2 - $ry ** 2 * $x1p ** 2;
        $den  = $rx ** 2 * $y1p ** 2 + $ry ** 2 * $x1p ** 2;
        $co   = $den <= 0 ? 0.0 : $sign * sqrt(max(0.0, $num / $den));

        $cxp = $co * $rx * $y1p / $ry;
        $cyp = -$co * $ry * $x1p / $rx;
        $cx  = $cosP * $cxp - $sinP * $cyp + ($x1 + $x2) / 2;
        $cy  = $sinP * $cxp + $cosP * $cyp + ($y1 + $y2) / 2;

        $angle = static function (float $ux, float $uy, float $vx, float $vy): float {
            $dot = $ux * $vx + $uy * $vy;
            $len = sqrt(($ux ** 2 + $uy ** 2) * ($vx ** 2 + $vy ** 2));
            $a   = $len <= 0 ? 0.0 : acos(max(-1.0, min(1.0, $dot / $len)));

            return ($ux * $vy - $uy * $vx) < 0 ? -$a : $a;
        };

        $theta = $angle(1, 0, ($x1p - $cxp) / $rx, ($y1p - $cyp) / $ry);
        $delta = $angle(($x1p - $cxp) / $rx, ($y1p - $cyp) / $ry, (-$x1p - $cxp) / $rx, (-$y1p - $cyp) / $ry);

        if (!$sweep && $delta > 0) {
            $delta -= 2 * M_PI;
        }

        if ($sweep && $delta < 0) {
            $delta += 2 * M_PI;
        }

        $segments = max(1, (int) ceil(abs($delta) / (M_PI / 2)));
        $step     = $delta / $segments;
        $k        = 4 / 3 * tan($step / 4);

        $out = '';

        for ($s = 0; $s < $segments; $s++) {
            $t1 = $theta + $s * $step;
            $t2 = $t1 + $step;

            $p  = static function (float $t) use ($cx, $cy, $rx, $ry, $cosP, $sinP): array {
                $x = $rx * cos($t);
                $y = $ry * sin($t);

                return [$cx + $cosP * $x - $sinP * $y, $cy + $sinP * $x + $cosP * $y];
            };

            $dv = static function (float $t) use ($rx, $ry, $cosP, $sinP): array {
                $x = -$rx * sin($t);
                $y = $ry * cos($t);

                return [$cosP * $x - $sinP * $y, $sinP * $x + $cosP * $y];
            };

            [$px1, $py1] = $p($t1);
            [$px2, $py2] = $p($t2);
            [$dx1, $dy1] = $dv($t1);
            [$dx2, $dy2] = $dv($t2);

            $out .= sprintf(
                "%.4f %.4f %.4f %.4f %.4f %.4f c\n",
                $px1 + $k * $dx1,
                $py1 + $k * $dy1,
                $px2 - $k * $dx2,
                $py2 - $k * $dy2,
                $px2,
                $py2,
            );
        }

        return $out;
    }

    private function transformMatrix(string $transform): string
    {
        $out = '';

        if (!preg_match_all('/([a-zA-Z]+)\s*\(([^)]*)\)/', $transform, $m, PREG_SET_ORDER)) {
            return '';
        }

        foreach ($m as $match) {
            $args = array_map(
                'floatval',
                array_values(
                    array_filter(
                        preg_split('/[\s,]+/', trim($match[2])) ?: [],
                        'strlen',
                    ),
                ),
            );

            switch (strtolower($match[1])) {
                case 'translate':
                    $out .= sprintf("1 0 0 1 %.4f %.4f cm\n", $args[0] ?? 0, $args[1] ?? 0);
                    break;

                case 'scale':
                    $sx  = $args[0] ?? 1;
                    $out .= sprintf("%.6f 0 0 %.6f 0 0 cm\n", $sx, $args[1] ?? $sx);
                    break;

                case 'rotate':
                    $r = deg2rad($args[0] ?? 0);

                    if (isset($args[1], $args[2])) {
                        $out .= sprintf("1 0 0 1 %.4f %.4f cm\n", $args[1], $args[2]);
                    }

                    $out .= sprintf("%.6f %.6f %.6f %.6f 0 0 cm\n", cos($r), sin($r), -sin($r), cos($r));

                    if (isset($args[1], $args[2])) {
                        $out .= sprintf("1 0 0 1 %.4f %.4f cm\n", -$args[1], -$args[2]);
                    }
                    break;

                case 'matrix':
                    if (count($args) === 6) {
                        $out .= sprintf("%.6f %.6f %.6f %.6f %.6f %.6f cm\n", ...$args);
                    }
                    break;
            }
        }

        return $out;
    }
}
