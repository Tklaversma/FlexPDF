<?php

declare(strict_types=1);

namespace FlexPDF\Engine;

use DOMElement;
use DOMNode;
use FlexPDF\Engine\Support\AssetPath;
use FlexPDF\Engine\Support\Limits;
use FlexPDF\Engine\Support\RemoteImages;

/**
 * The cascade: match selectors against the DOM, sort by origin/importance/
 * specificity/order, inherit what should inherit, and resolve declared
 * values into computed ones the layout engine can consume.
 */
final class StyleResolver
{
    /** Properties that inherit by default. */
    private const array INHERITED = [
        'color'       => true,
        'font-family' => true,
        'font-size'   => true,
        'font-weight' => true,
        'font-style'  => true,
        'line-height' => true,
        'text-align'  => true,
        'white-space' => true,
        'direction'   => true,
        'hyphens'     => true,
        'text-shadow' => true,
        'font-variant' => true,
        'font-variant-caps' => true,
        'font-variant-ligatures' => true,
        'font-variant-numeric' => true,
        'font-kerning' => true,
        'font-feature-settings' => true,
        'font-stretch' => true,
        'font-size-adjust' => true,
        'orphans'     => true,
        'widows'      => true,
        'text-transform' => true,
        'letter-spacing' => true,
        'word-spacing'   => true,
        'word-break'     => true,
        'overflow-wrap'  => true,
        // Not inherited in CSS, but it does propagate to in-flow descendants,
        // and for text that is the same thing. The two longhands that are read
        // travel the same way, or a `<span>` inside a decorated paragraph
        // would draw the paragraph's line in its own color.
        'text-decoration' => true,
        'text-decoration-color' => true,
        'text-decoration-thickness' => true,
        'text-decoration-style' => true,
        'text-underline-offset' => true,
        'font-synthesis'  => true,
        'list-style-type' => true,
        'list-style-position' => true,
        'list-style-image' => true,
        'visibility'      => true,
        'text-indent'     => true,
        // CSS 2.1 §17.6.1.1: set on the table, read by every cell in it.
        'empty-cells'     => true,
        // Not inherited in CSS either, and the used value does the same work:
        // CSS Paged Media 3 section 4.1 says `page: auto` takes the used value
        // of the parent, so a paragraph inside a `page: cover` box is on a
        // cover page too.
        'page'            => true,
        // Internal, and inherited because the whole point of it is to survive a
        // monospace generation unchanged. See {@see ORDINARY_SIZE}.
        self::ORDINARY_SIZE => true,
    ];

    /**
     * The size this chain would have with no monospace family anywhere in it,
     * in points, carried down beside the computed `font-size`.
     *
     * A single leading dash keeps it out of the `--` custom property space an
     * author writes in, and `compute()` assigns it after the declarations, so a
     * sheet naming it cannot change what a descendant reads.
     */
    private const string ORDINARY_SIZE = '-fp-ordinary-font-size';

    /**
     * The size a document starts at, in points. Chrome's is the `medium`
     * keyword at 16px; this engine's is 12px, declared by the UA sheet, and
     * that difference is the owner's and predates every round here.
     */
    private const float BASE_FONT_SIZE = 9.0;

    /**
     * The size a monospace box starts at instead, in points.
     *
     * Chrome's default fixed font size is **13px against its 16px default**, so
     * monospace text is 13/16 of body text there. This engine's base is 12px,
     * and the owner's call is that the monospace base keeps that proportion
     * rather than Chrome's absolute number: 12 x 13/16 = 9.75px = 7.3125pt.
     * A `<pre>` is therefore 7.313pt here against Chrome's 9.750, and 0.8125 of
     * its own body text exactly as Chrome's is of its own.
     */
    private const float MONOSPACE_BASE_FONT_SIZE = 7.3125;

    private const array INITIAL = [
        'display'               => 'block',
        'flex-direction'        => 'row',
        'flex-wrap'             => 'nowrap',
        'justify-content'       => 'normal',
        'align-items'           => 'stretch',
        'align-content'         => 'stretch',
        'align-self'            => 'auto',
        'justify-items'         => 'stretch',
        'justify-self'          => 'auto',
        'grid-template-columns' => 'none',
        'grid-template-rows'    => 'none',
        'grid-auto-columns'     => 'auto',
        'grid-auto-rows'        => 'auto',
        'grid-auto-flow'        => 'row',
        'column-count'          => 'auto',
        'column-width'          => 'auto',
        'column-fill'           => 'balance',
        'box-decoration-break'  => 'slice',
        'column-span'           => 'none',
        'column-rule-width'     => 'medium',
        'column-rule-style'     => 'none',
        'column-rule-color'     => 'currentcolor',
        'container-type'        => 'normal',
        'container-name'        => 'none',
        'grid-template-areas'   => 'none',
        'grid-column-start'     => 'auto',
        'grid-column-end'       => 'auto',
        'grid-row-start'        => 'auto',
        'grid-row-end'          => 'auto',
        'flex-grow'             => '0',
        'flex-shrink'           => '1',
        'flex-basis'            => 'auto',
        'order'                 => '0',
        'background-image'      => 'none',
        'background-repeat'     => 'repeat',
        'background-position'   => '0% 0%',
        'background-size'       => 'auto',
        'background-clip'       => 'border-box',
        'background-origin'     => 'padding-box',
        'gap'                   => '0',
        'color'                 => '#1f2124',
        'font-family'           => 'Helvetica',
        'font-size'             => '12px',
        'font-weight'           => 'normal',
        'font-style'            => 'normal',
        'line-height'           => 'normal',
        'text-align'            => 'left',
        'text-indent'           => '0',
        'outline-width'         => 'medium',
        'outline-style'         => 'none',
        'outline-color'         => 'currentcolor',
        'outline-offset'        => '0',
        'hyphens'               => 'manual',
        'direction'             => '',
        'float'                 => 'none',
        'clear'                 => 'none',
        'position'              => 'static',
        'z-index'               => 'auto',
        'opacity'               => '1',
        'overflow'              => 'visible',
        'overflow-x'            => 'visible',
        'overflow-y'            => 'visible',
        'visibility'            => 'visible',
        'mix-blend-mode'        => 'normal',
        'isolation'             => 'auto',
        'object-fit'            => 'fill',
        'object-position'       => '50% 50%',
        'clip-path'             => 'none',
        'text-shadow'           => 'none',
        'box-shadow'            => 'none',
        'aspect-ratio'          => 'auto',
        'font-variant'          => 'normal',
        'font-variant-caps'     => 'normal',
        'font-variant-ligatures' => 'normal',
        'font-variant-numeric'  => 'normal',
        'font-kerning'          => 'auto',
        'font-feature-settings' => 'normal',
        'font-stretch'          => 'normal',
        'font-size-adjust'      => 'none',
        'transform'             => 'none',
        'transform-origin'      => '50% 50%',
        'box-sizing'            => 'content-box',
        'white-space'           => 'normal',
        'text-transform'        => 'none',
        'letter-spacing'        => 'normal',
        'word-spacing'          => 'normal',
        'word-break'            => 'normal',
        'overflow-wrap'         => 'normal',
        'text-decoration'       => 'none',
        'text-decoration-color' => 'currentcolor',
        'text-decoration-thickness' => 'auto',
        'text-decoration-style' => 'solid',
        'text-underline-offset' => 'auto',
        'font-synthesis'        => 'weight style small-caps',
        'list-style-type'       => 'disc',
        'list-style-position'   => 'outside',
        'list-style-image'      => 'none',
        'mask-image'            => 'none',
        'mask-size'             => 'auto',
        'mask-repeat'           => 'repeat',
        'mask-position'         => '0% 0%',
        'mask-mode'             => 'match-source',
        'border-image-source'   => 'none',
        'border-image-slice'    => '100%',
        'border-image-width'    => '1',
        'border-image-outset'   => '0',
        'border-image-repeat'   => 'stretch',
        'break-inside'          => 'auto',
        'break-before'          => 'auto',
        'break-after'           => 'auto',
        'page-break-before'     => 'auto',
        'page-break-after'      => 'auto',
        'page-break-inside'     => 'auto',
        // CSS 2.1 §10.8.1's own initial value. It used to be `top` here, as a
        // convenience for the one reader that wants that default, and a table
        // cell keeps it: `buildCell()` maps everything but `middle` and
        // `bottom` to `top` either way. What the old initial cost was the two
        // line-relative keywords, because every ordinary run computed `top`
        // and there was no telling a declaration from the default. Defect AF.
        'vertical-align'        => 'baseline',
        'border-collapse'       => 'separate',
        'empty-cells'           => 'show',
        'table-layout'          => 'auto',
        'counter-reset'         => 'none',
        'counter-increment'     => 'none',
        'counter-set'           => 'none',
        'border-spacing'        => '0',
        'orphans'               => '2',
        'widows'                => '2',
    ];

    /**
     * Minimal UA stylesheet.
     *
     * The table `vertical-align` chain is HTML's rendering sheet with one
     * substitution. HTML writes
     * `thead, tbody, tfoot, table > tr { vertical-align: middle }` beside
     * `tr, td, th { vertical-align: inherit }`, and the child combinator is
     * there for a row that has no group around it. Chrome's parser generates
     * a `<tbody>` around every such row, so `table > tr` matches nothing at
     * all there: measured on `Y1-cell-valign-groups.html`, an author's
     * `table > tr { vertical-align: bottom }` leaves a bare-markup row's cell
     * centred. `DOMDocument` generates no group, so the same selector would
     * match every bare row here, and at specificity (0,0,2) it would also
     * outrank an author's own `tr { vertical-align: top }`, which this
     * cascade ranks below it for want of an origin. The table carries the
     * value instead, which is the box a bare row inherits from here and the
     * one the generated group would have inherited from there.
     *
     * That leaves one shape where the two disagree, `Y1`'s `j0`: an author
     * declaring `vertical-align` on a `<table>` whose rows have no `<tbody>`
     * reaches the cells here and is blocked by the generated group there.
     */
    private const string UA_CSS = <<<CSS
                             html, body, div, p, h1, h2, h3, h4, h5, h6, ul, ol, li, section,
                             article, header, footer, main, nav, aside, table, figure, blockquote,
                             pre, hr { display: block; }
                             span, a, b, strong, i, em, small, code, u, s, sub, sup, label,
                             abbr, cite, q, mark, time { display: inline; }
                             head, style, script, title, meta, link { display: none; }

                             body { margin: 8px; font-size: 12px; }
                             p { margin: 0 0 10px 0; }
                             h1 { font-size: 26px; font-weight: bold; margin: 0 0 12px 0; }
                             h2 { font-size: 20px; font-weight: bold; margin: 0 0 10px 0; }
                             h3 { font-size: 16px; font-weight: bold; margin: 0 0 8px 0; }
                             h4 { font-size: 14px; font-weight: bold; margin: 0 0 8px 0; }
                             h5, h6 { font-size: 12px; font-weight: bold; margin: 0 0 6px 0; }
                             b, strong, th { font-weight: bold; }
                             i, em { font-style: italic; }
                             small { font-size: smaller; }
                             sup { vertical-align: super; font-size: smaller; }
                             sub { vertical-align: sub; font-size: smaller; }
                             code, pre { font-family: monospace; }
                             pre { white-space: pre; margin: 1em 0; }
                             ul, ol { margin: 0 0 10px 0; padding-left: 22px; }
                             li { display: list-item; margin: 0 0 3px 0; }

                             /* HTML's own rendering sheet, section 15.3.7. A
                                nested list takes a marker type of its own, and
                                without these two rules a list three deep draws
                                one filled bullet at all three levels. The
                                selectors key on the depth of `ul` nesting and
                                not on the depth of the list, which is why `ol
                                ul` is a circle and `ul ol` is still decimal:
                                `SX-list-depth.html` b4 and b6 are the two
                                bands that say so. Defect FV. */
                             ul ul, ol ul { list-style-type: circle; }
                             ul ul ul, ul ol ul, ol ul ul,
                             ol ol ul { list-style-type: square; }
                             hr { height: 1px; background-color: #D6D8DC; margin: 10px 0; }
                             blockquote { margin: 0 0 10px 0; padding-left: 12px; }

                             table { display: table; border-collapse: separate; border-spacing: 2px;
                                     box-sizing: border-box; vertical-align: middle; }
                             thead { display: table-header-group; vertical-align: middle; }
                             tbody { display: table-row-group; vertical-align: middle; }
                             tfoot { display: table-footer-group; vertical-align: middle; }
                             tr { display: table-row; vertical-align: inherit; }
                             td, th { display: table-cell; padding: 1px; vertical-align: inherit; }
                             th { font-weight: bold; text-align: left; }
                             caption { display: block; }
                             col, colgroup { display: none; }

                             img, svg { display: inline-block; }

                             /* HTML's own rendering sheet, section 15.3.1. It
                                keys on an attribute, which nothing in a UA
                                sheet could do until defect CV taught the
                                parser to keep the punctuation an HTML5
                                tokeniser keeps: without that pre-pass a
                                fabricated `hidden` matched here and 141 of
                                8,000 documents lost glyphs to it. With it,
                                0 of 8,000 do. */
                             [hidden] { display: none; }

                             input, textarea, select, button {
                                 display: inline-block; border: 1px solid #767676;
                                 padding: 2px; font-size: 13.33px; line-height: 1.125;
                                 color: #000000; background-color: #ffffff; }
                             textarea { font-family: monospace; }
                             select { padding: 1px 2px; }
                             button { padding: 2px 6px; background-color: #efefef;
                                      text-align: center; }
                             CSS;

    /** @var CssRule[] */
    private array $rules = [];

    /**
     * Rules bucketed by the rightmost simple selector, so an element only
     * tests the handful that could possibly match it rather than every rule
     * in the sheet. Without this the cascade is O(elements x rules), which
     * on a long table costs more than layout itself.
     *
     * @var array{id:array<string,CssRule[]>,class:array<string,CssRule[]>,tag:array<string,CssRule[]>,any:CssRule[]}
     */
    private array $buckets = [
        'id'    => [],
        'class' => [],
        'tag'   => [],
        'any'   => [],
    ];

    /**
     * Which pseudo-elements any rule in the sheets names at all.
     *
     * Generating `::before` and `::after` costs two more cascades per element,
     * and in a text document most elements are inline ones. A document that
     * names neither pays this lookup instead.
     *
     * @var array<string,true>
     */
    private array $pseudoElements = [];

    public array $pageStyle = [];

    /**
     * The `@page` blocks that carry a selector, by selector.
     *
     * CSS Paged Media 3 section 3 qualifies a block to some of the pages, and
     * this engine honours `:first`, `:left` and `:right` in the block axis. The
     * declarations are kept apart from the unqualified block's rather than
     * merged into it, because merging them made `@page :first { margin: 60pt }`
     * the margin of every page.
     *
     * @var array<string,array<string,array{value:string,important:bool}>>
     */
    public array $pageSelectors = [];

    /**
     * The `@page` margin boxes, by box name without the `@`.
     *
     * @var array<string,array<string,array{value:string,important:bool}>>
     */
    public array $pageMargins = [];

    /**
     * The `@page` margin boxes a qualified or named block declares, by that
     * block's selector and then by box name.
     *
     * @var array<string,array<string,array<string,array{value:string,important:bool}>>>
     */
    public array $pageSelectorMargins = [];

    /** The page box `vw`/`vh` resolve against, in points. */
    public function viewport(float $width, float $height): void
    {
        $this->viewportWidth  = $width;
        $this->viewportHeight = $height;
    }

    /** @var array<int,array{family:string,src:string,bold:bool,italic:bool,width:float}> */
    public array $fontFaces = [];

    private float $rootFontSize = 12.0;

    /*
     * What `vw` and `vh` resolve against. For print the viewport is the page
     * box, so these are the page dimensions in points, not the content box:
     * a browser's `100vh` is the full sheet, margins included.
     */
    private float $viewportWidth = 595.28;

    private float $viewportHeight = 841.89;

    private readonly Limits $limits;

    /** The ceiling a used length may not pass, for code outside the cascade. */
    public function maxLength(): float
    {
        return $this->limits->maxLength;
    }

    /** The largest decoded size a raster picture may have, in bytes; zero is no ceiling. */
    public function maxImageBytes(): int
    {
        return $this->limits->maxImageBytes;
    }

    public function __construct(?Limits $limits = null, ?RemoteImages $remoteImages = null)
    {
        $this->limits       = $limits ?? new Limits();
        $this->remoteImages = $remoteImages ?? new RemoteImages();

        $ua = new CssParser();
        $ua->parse(self::UA_CSS);

        foreach ($ua->rules as $r) {
            $this->index($r);
        }
    }

    private function index(CssRule $rule): void
    {
        $this->rules[] = $rule;
        $key           = $rule->selector->parts[count($rule->selector->parts) - 1];

        if ($key->element !== null) {
            $this->pseudoElements[$key->element] = true;
        }

        if ($key->id !== null) {
            $this->buckets['id'][$key->id][] = $rule;
        } elseif ($key->classes !== []) {
            $this->buckets['class'][$key->classes[0]][] = $rule;
        } elseif ($key->tag !== null && $key->tag !== '*') {
            $this->buckets['tag'][$key->tag][] = $rule;
        } else {
            $this->buckets['any'][] = $rule;
        }
    }

    /** Whether any rule in the sheets names this pseudo-element. */
    public function declaresPseudoElement(string $pseudoElement): bool
    {
        return isset($this->pseudoElements[$pseudoElement]);
    }

    /** Whether any rule in the sheets sits inside an `@container` block. */
    public bool $hasContainerQueries = false;

    /**
     * The query containers the last layout produced, by the element that
     * establishes each one.
     *
     * A container query needs a used size and the cascade runs before layout
     * has one, so this map is empty on the first pass and every `@container`
     * rule is skipped. {@see Html::layout()} fills it from the boxes that pass
     * produced and builds again. **Empty is the right default rather than a
     * placeholder**: a document that establishes no container matches no
     * container query, which is defect EK's own answer.
     *
     * **Each entry holds its own element and the map is keyed by that
     * element's `spl_object_id()`.** Holding it is what makes the key mean
     * anything: a `DOMElement` wrapper is rebuilt whenever a walk reaches a
     * node whose previous wrapper has been freed, and PHP reuses object
     * handles, so an entry that does not own its element can end up answering
     * for a different one.
     *
     * @var array<int,array{element:DOMElement,names:string[],type:string,inline:float,block:?float}>
     */
    private array $containers = [];

    /**
     * @param array<int,array{element:DOMElement,names:string[],type:string,inline:float,block:?float}> $containers
     *        keyed by `spl_object_id()` of the element that establishes each
     */
    public function setContainers(array $containers): void
    {
        $this->containers = $containers;
    }

    /**
     * Whether a `cqw`, `cqh`, `cqi`, `cqb`, `cqmin` or `cqmax` has actually
     * been resolved, rather than whether one was declared somewhere.
     *
     * A container query unit needs a used size for the same reason a query
     * does, so a document that writes one needs the second layout pass even
     * when it writes no `@container` block at all. Counting the resolutions
     * rather than scanning the sheets is what makes this reach a unit written
     * in a `style` attribute, which never passes through {@see addStylesheet}.
     */
    public bool $usesContainerUnits = false;

    /**
     * The element whose lengths are being resolved.
     *
     * A viewport unit is one number for the whole document; a container query
     * unit is one per element, because it is a percentage of the nearest
     * ancestor container rather than of the page. This is that element, set by
     * the cascade and by the box walk, and it is what {@see containerAxis}
     * starts from.
     */
    private ?DOMElement $unitElement = null;

    /**
     * The face that element's `ex` and `ch` are measured in.
     *
     * CSS Values 4 section 6.1.2 makes both units a metric of the font in
     * effect: `ex` is its x-height and `ch` the advance of its `0`. They are
     * per element for the same reason a container query unit is, so they
     * travel with {@see $unitElement} and are set at the same two moments,
     * which makes them exactly as fresh as `cqw` already is here. Null is a
     * document that has not started, and then the two constants below are
     * Helvetica's own numbers, which is what an unstyled document is measured
     * in and what this engine used for every face before. Defect HD.
     */
    private Font|TrueTypeFont|null $unitFace = null;

    public function resolveLengthsFor(?DOMElement $el, Font|TrueTypeFont|null $face = null): void
    {
        $this->unitElement = $el;
        $this->unitFace    = $face;
    }

    /**
     * One `ex`: the x-height of the face in effect, in points.
     *
     * A base-14 Helvetica answers 0.523 of the size exactly, so a document
     * that names no face reads what it always read.
     */
    private function exUnit(float $fontSize): float
    {
        return $this->unitFace?->xHeight($fontSize) ?? 0.523 * $fontSize;
    }

    /**
     * One `ch`: the advance of `0` in the face in effect, in points.
     *
     * {@see HtmlBuilder} asks the same question of the same method for an
     * `<input size="20">`, which is a count of characters and therefore this
     * unit spelled as an attribute. Base-14 Helvetica answers 0.556 of the
     * size exactly.
     */
    private function chUnit(float $fontSize): float
    {
        return $this->unitFace?->stringWidth('0', $fontSize) ?? 0.556 * $fontSize;
    }

    /**
     * The query container's content box on one axis, CSS Containment 3
     * section 6, which a container query unit is one percent of.
     *
     * The container is the nearest ancestor that establishes one **and can
     * answer for the axis**, so a `cqb` looks straight past an `inline-size`
     * container to the nearest sized one, exactly as a block-axis query does
     * in {@see queryContainer}. An element is never its own query container.
     *
     * With no such ancestor the unit is the small viewport size for that axis,
     * which is what CSS asks for and what keeps `50cqw` a readable length in a
     * document that establishes no container rather than a dropped
     * declaration.
     */
    private function containerAxis(bool $inlineAxis): float
    {
        $this->usesContainerUnits = true;

        for ($node = $this->unitElement?->parentNode; $node instanceof DOMElement; $node = $node->parentNode) {
            $container = $this->containers[spl_object_id($node)] ?? null;

            if ($container === null) {
                continue;
            }

            if ($inlineAxis) {
                return $container['inline'];
            }

            if ($container['block'] !== null) {
                return $container['block'];
            }
        }

        return $inlineAxis ? $this->viewportWidth : $this->viewportHeight;
    }

    /**
     * Whether an element is inside every `@scope ... to (...)` limit wrapping
     * a rule.
     *
     * CSS Cascade 6 section 3.1: the scope is the scoping root's subtree less
     * the subtrees rooted at each scoping limit, and a limit element is itself
     * out of scope. Walking up from the element and stopping at the first
     * ancestor that is a scoping root is what keeps a `.footer` **above** the
     * root from cutting anything off.
     *
     * @param array<int,array{roots:Selector[],limits:Selector[]}> $bounds
     */
    private function inScope(DOMElement $el, array $bounds): bool
    {
        foreach ($bounds as $bound) {
            for ($node = $el; $node instanceof DOMElement; $node = $node->parentNode) {
                if (array_any($bound['roots'], fn(Selector $r): bool => $this->matches($node, $r))) {
                    break;
                }

                if (array_any($bound['limits'], fn(Selector $l): bool => $this->matches($node, $l))) {
                    return false;
                }
            }
        }

        return true;
    }

    /** @param ContainerQuery[] $queries */
    private function containerQueriesHold(DOMElement $el, array $queries): bool
    {
        if ($this->containers === []) {
            return false;
        }

        return array_all(
            $queries,
            fn(ContainerQuery $query): bool => $query->holds(
                $this->queryContainer($el, $query),
                $this,
                $this->rootFontSize,
            ),
        );
    }

    /**
     * The container a query asks about: the nearest ancestor that establishes
     * one, carries the name the query asked for, and has a type that can
     * answer it.
     *
     * CSS Containment 3 section 5.1 filters on the type as well as the name,
     * which is why a `min-height` query looks straight past an `inline-size`
     * container to the nearest sized one rather than reading false off the
     * nearer box. An element is never its own query container.
     *
     * A running header and a `@page` margin box are built from a document of
     * their own through this same resolver, so nothing in one of them can find
     * a container: the map holds the main document's elements and no element
     * of theirs is in it. That is the answer this engine gives rather than the
     * answer CSS would; a margin box is not part of the flow either way.
     *
     * @return ?array{element:DOMElement,names:string[],type:string,inline:float,block:?float}
     */
    private function queryContainer(DOMElement $el, ContainerQuery $query): ?array
    {
        $needsBlock = $query->readsBlockAxis();

        for ($node = $el->parentNode; $node instanceof DOMElement; $node = $node->parentNode) {
            $container = $this->containers[spl_object_id($node)] ?? null;

            if ($container === null) {
                continue;
            }

            if ($query->name !== null && !in_array($query->name, $container['names'], true)) {
                continue;
            }

            if ($needsBlock && $container['type'] !== 'size') {
                continue;
            }

            return $container;
        }

        return null;
    }

    /** @return CssRule[] rules that could match this element */
    private function candidates(DOMElement $el): array
    {
        $out = $this->buckets['any'];
        $tag = strtolower($el->nodeName);

        if (isset($this->buckets['tag'][$tag])) {
            $out = array_merge($out, $this->buckets['tag'][$tag]);
        }

        $classAttr = $el->getAttribute('class');

        if ($classAttr !== '') {
            foreach (preg_split('/\s+/', trim($classAttr)) ?: [] as $c) {
                if (isset($this->buckets['class'][$c])) {
                    $out = array_merge($out, $this->buckets['class'][$c]);
                }
            }
        }

        $id = $el->getAttribute('id');

        if ($id !== '' && isset($this->buckets['id'][$id])) {
            $out = array_merge($out, $this->buckets['id'][$id]);
        }

        return $out;
    }

    public function addStylesheet(string $css): void
    {
        $author = new CssParser();
        $author->parse($css);

        // Author rules come after UA rules, so they win ties on order too, but
        // it is the origin that decides between the two sheets.
        foreach ($author->rules as $r) {
            if ($r->queries !== []) {
                $this->hasContainerQueries = true;
            }

            $rule = new CssRule(
                $r->selector,
                $r->declarations,
                1_000_000 + $r->order,
                CssRule::ORIGIN_AUTHOR,
                $r->queries,
            );

            $rule->scopeBounds = $r->scopeBounds;

            $this->index($rule);
        }

        $this->pageStyle = array_merge($this->pageStyle, $author->page);

        foreach ($author->pageSelectors as $selector => $declarations) {
            $this->pageSelectors[$selector] = array_merge(
                $this->pageSelectors[$selector] ?? [],
                $declarations,
            );
        }

        foreach ($author->pageMargins as $box => $declarations) {
            $this->pageMargins[$box] = array_merge($this->pageMargins[$box] ?? [], $declarations);
        }

        foreach ($author->pageSelectorMargins as $selector => $boxes) {
            foreach ($boxes as $box => $declarations) {
                $this->pageSelectorMargins[$selector][$box] = array_merge(
                    $this->pageSelectorMargins[$selector][$box] ?? [],
                    $declarations,
                );
            }
        }

        foreach ($author->fontFaces as $face) {
            $family = trim($face['font-family']['value'] ?? '', " \"'");
            $src    = $face['src']['value'] ?? '';

            if ($family === '' || $src === '') {
                continue;
            }

            // Take the first url(...) that names a font file we can open.
            $path = '';

            if (preg_match_all('/url\(\s*[\"\']?([^)\"\']+)[\"\']?\s*\)/i', $src, $m)) {
                foreach ($m[1] as $candidate) {
                    $resolved = $this->resolvePath(trim($candidate));

                    if ($resolved !== null) {
                        $path = $resolved;
                        break;
                    }
                }
            }

            if ($path === '') {
                continue;
            }

            $weight = strtolower(trim($face['font-weight']['value'] ?? 'normal'));
            $style  = strtolower(trim($face['font-style']['value'] ?? 'normal'));

            $this->fontFaces[] = [
                'family' => $family,
                'src'    => $path,
                'bold'   => $weight === 'bold' || (is_numeric($weight) && (int) $weight >= 600),
                'italic' => $style === 'italic' || $style === 'oblique',
                // A face's own width, which is what makes two `@font-face`
                // blocks for one family two faces rather than one overwriting
                // the other.
                'width'  => FontRegistry::width($face['font-stretch']['value'] ?? 'normal'),
            ];
        }
    }

    /**
     * `linear-gradient(<angle|to <side>>, <stop>, ...)`, resolved to an angle
     * and a list of positioned color stops.
     *
     * CSS measures the angle clockwise from "up", so 0deg runs bottom to top
     * and 90deg left to right. Stops without a position are spread evenly
     * between their positioned neighbors, which is what makes the two-stop
     * spelling everyone writes mean 0% and 100%.
     *
     * @return array{angle:float,stops:array<int,array{0:float,1:array{0:float,1:float,2:float,3:float}}>}|null
     */
    /**
     * Parse any CSS gradient function into one shape the painter understands.
     *
     * A stop position is kept as written, a fraction or a length that only
     * means something once the gradient line has a length, because the
     * cascade has no box to resolve it against. A bare position between two
     * colors is an interpolation hint and travels with a null color.
     *
     * @return array{type:string,repeating:bool,angle:float,corner:?array,shape:string,extent:string,size:?array,at:?array,from:float,stops:array<int,array{0:float|string|null,1:array|null}>}|null
     */
    public function gradient(string $value, ?array $currentColor = null): ?array
    {
        if (preg_match('/^\s*(repeating-)?(linear|radial|conic)-gradient\(\s*(.*)\)\s*$/is', trim($value), $m) !== 1) {
            return null;
        }

        $gradient = [
            'type'      => strtolower($m[2]),
            'repeating' => $m[1] !== '',
            'angle'     => 180.0,
            'corner'    => null,
            'shape'     => 'ellipse',
            'extent'    => 'farthest-corner',
            'size'      => null,
            'at'        => null,
            'from'      => 0.0,
        ];

        $parts = $this->splitList($m[3]);

        if ($parts !== []) {
            $head = trim($parts[0]);

            if ($this->isGradientPrelude($gradient['type'], $head)) {
                array_shift($parts);
                $gradient = [...$gradient, ...$this->gradientPrelude($gradient['type'], $head)];
            }
        }

        $stops = [];

        foreach ($parts as $part) {
            foreach ($this->gradientStops($part, $currentColor) as $stop) {
                $stops[] = $stop;
            }

            // Each stop becomes a span of the PDF function that draws the
            // gradient, and the list length is the author's to choose, so it
            // is bounded like every other loop over untrusted input.
            if (count($stops) >= self::MAX_GRADIENT_STOPS) {
                break;
            }
        }

        // A hint on either end has nothing to interpolate between.
        while ($stops !== [] && $stops[0][1] === null) {
            array_shift($stops);
        }

        while ($stops !== [] && $stops[count($stops) - 1][1] === null) {
            array_pop($stops);
        }

        if (count($stops) < 2) {
            return null;
        }

        $gradient['stops'] = $stops;

        return $gradient;
    }

    /** Whether a gradient's first argument configures it rather than being a stop. */
    private function isGradientPrelude(string $type, string $head): bool
    {
        return match ($type) {
            'linear' => preg_match('/^(to\s+|-?[\d.]+(deg|grad|rad|turn)$)/i', $head) === 1,
            'radial' => preg_match('/^(circle|ellipse|closest-|farthest-|at\s|[\d.]+(px|pt|em|rem|%|in|cm|mm))/i', $head) === 1,
            default  => preg_match('/^(from\s|at\s)/i', $head) === 1,
        };
    }

    /** @return array<string,mixed> */
    private function gradientPrelude(string $type, string $head): array
    {
        if ($type === 'linear') {
            [$angle, $corner] = $this->gradientAngle($head);

            return ['angle' => $angle, 'corner' => $corner];
        }

        $out    = [];
        $tokens = $this->topLevelTokens($head);
        $sizes  = [];
        $at     = [];
        $inAt   = false;

        foreach ($tokens as $token) {
            $lower = strtolower($token);

            if ($lower === 'at') {
                $inAt = true;

                continue;
            }

            if ($inAt) {
                $at[] = $lower;

                continue;
            }

            if ($lower === 'from') {
                continue;
            }

            match (true) {
                $lower === 'circle' || $lower === 'ellipse' => $out['shape'] = $lower,
                in_array($lower, ['closest-side', 'closest-corner', 'farthest-side', 'farthest-corner'], true)
                    => $out['extent'] = $lower,
                $type === 'conic' => $out['from'] = $this->angleValue($token) ?? 0.0,
                default           => $sizes[] = $token,
            };
        }

        if ($sizes !== []) {
            $out['size']  = [$sizes[0], $sizes[1] ?? $sizes[0]];
            $out['shape'] = ($out['shape'] ?? '') !== '' ? $out['shape'] : (count($sizes) === 1 ? 'circle' : 'ellipse');
        }

        if ($at !== []) {
            $out['at'] = [$at[0], $at[1] ?? 'center'];
        }

        return $out;
    }

    /**
     * One comma-separated piece of a stop list.
     *
     * CSS lets a color carry two positions, which is shorthand for the same
     * color twice, and that is exactly how a hard-edged stripe is written.
     *
     * @return array<int, array{0:float|string|null,1:array|null}>
     */
    private function gradientStops(string $part, ?array $currentColor): array
    {
        $tokens    = $this->topLevelTokens($part);
        $positions = [];

        while ($tokens !== [] && $this->isStopPosition((string) end($tokens))) {
            array_unshift($positions, (string) array_pop($tokens));
        }

        if ($tokens === []) {
            // Nothing but a position: an interpolation hint.
            return $positions === [] ? [] : [[$this->stopPosition($positions[0]), null]];
        }

        $color = $this->rgba(implode(' ', $tokens), $currentColor, keepTransparent: true);

        if ($color === null) {
            return [];
        }

        if ($positions === []) {
            return [[null, $color]];
        }

        return array_map(
            fn(string $position): array => [$this->stopPosition($position), $color],
            $positions,
        );
    }

    private function isStopPosition(string $token): bool
    {
        return preg_match('/^-?[\d.]+(%|px|pt|em|rem|in|cm|mm|deg|grad|rad|turn)?$/i', $token) === 1;
    }

    /** A fraction where CSS gave one, otherwise the length, kept for the painter. */
    private function stopPosition(string $token): float|string
    {
        if (str_ends_with($token, '%')) {
            return (float) rtrim($token, '%') / 100.0;
        }

        $angle = $this->angleValue($token);

        return $angle === null ? $token : $angle / 360.0;
    }

    private function angleValue(string $token): ?float
    {
        if (preg_match('/^(-?[\d.]+)(deg|grad|rad|turn)$/i', trim($token), $m) !== 1) {
            return null;
        }

        return match (strtolower($m[2])) {
            'grad'  => (float) $m[1] * 0.9,
            'rad'   => rad2deg((float) $m[1]),
            'turn'  => (float) $m[1] * 360.0,
            default => (float) $m[1],
        };
    }

    /**
     * The gradient line's angle, and for a corner keyword the signs that let
     * the painter finish the job.
     *
     * `to bottom right` is **not** 135 degrees unless the box is square: CSS
     * puts the gradient line perpendicular to the diagonal joining the other
     * two corners, so it depends on the aspect ratio, which the cascade does
     * not know. Chrome draws a 200x60 box's `to bottom right` at 163.3
     * degrees, and passing the signs on is what lets the painter agree.
     *
     * @return array{0:float,1:array{0:float,1:float}|null}
     */
    private function gradientAngle(string $token): array
    {
        $token = strtolower(trim($token));

        if (str_starts_with($token, 'to ')) {
            $sides = preg_split('/\s+/', substr($token, 3)) ?: [];
            sort($sides);

            return match (implode(' ', $sides)) {
                'top'          => [0.0, null],
                'right'        => [90.0, null],
                'bottom'       => [180.0, null],
                'left'         => [270.0, null],
                'right top'    => [45.0, [1.0, -1.0]],
                'bottom right' => [135.0, [1.0, 1.0]],
                'bottom left'  => [225.0, [-1.0, 1.0]],
                'left top'     => [315.0, [-1.0, -1.0]],
                default        => [180.0, null],
            };
        }

        $number = (float) $token;

        return [
            match (true) {
                str_ends_with($token, 'grad') => $number * 0.9,
                str_ends_with($token, 'rad')  => $number * 180.0 / M_PI,
                str_ends_with($token, 'turn') => $number * 360.0,
                default                       => $number,
            },
            null,
        ];
    }

    /**
     * Split on commas that are not inside a function, so `rgb(0, 0, 0) 50%`
     * survives as one stop.
     *
     * @return string[]
     */
    public function splitList(string $value): array
    {
        $out     = [];
        $depth   = 0;
        $current = '';

        for ($i = 0, $len = strlen($value); $i < $len; $i++) {
            $c = $value[$i];

            if ($c === '(') {
                $depth++;
            } elseif ($c === ')') {
                $depth = max(0, $depth - 1);
            }

            if ($c === ',' && $depth === 0) {
                $out[]   = trim($current);
                $current = '';

                continue;
            }

            $current .= $c;
        }

        if (trim($current) !== '') {
            $out[] = trim($current);
        }

        return $out;
    }

    /**
     * The path inside the first `url()` of a value, or null when there is no
     * url in it. Quotes are optional in CSS and a `data:` URI carries its own
     * punctuation, so the body is taken up to the closing parenthesis.
     */
    public function urlValue(string $value): ?string
    {
        return preg_match('/url\(\s*["\']?([^)"\']+)["\']?\s*\)/i', $value, $m) === 1
            ? trim($m[1])
            : null;
    }

    /**
     * Whether a token in a shorthand names an image rather than a keyword.
     *
     * Only the spelling is read, not whether the file is there or the gradient
     * parses: a `list-style` token that says `url(...)` is the image slot of
     * the shorthand whatever comes of it, and a token that is not an image is
     * the type. Getting this wrong the other way would make
     * `list-style: url(dot.png)` set `list-style-type: url(dot.png)`.
     */
    public static function namesAnImage(string $token): bool
    {
        return str_starts_with($token, 'url(')
            || preg_match('/^(repeating-)?(linear|radial|conic)-gradient\(/i', $token) === 1;
    }

    // -----------------------------------------------------------------
    // matching
    // -----------------------------------------------------------------
    public function matches(DOMElement $el, Selector $sel): bool
    {
        $parts = $sel->parts;
        $i     = count($parts) - 1;

        if (!$this->matchesCompound($el, $parts[$i])) {
            return false;
        }

        $current = $el;

        for ($i--; $i >= 0; $i--) {
            $part   = $parts[$i + 1];
            $target = $parts[$i];

            if ($part->combinator === '>') {
                $parent = $current->parentNode;

                if (!$parent instanceof DOMElement || !$this->matchesCompound($parent, $target)) {
                    return false;
                }

                $current = $parent;
            } elseif ($part->combinator === '+') {
                $prev = $this->previousElement($current);

                if ($prev === null || !$this->matchesCompound($prev, $target)) {
                    return false;
                }

                $current = $prev;
            } else {
                // Descendant: walk up until something matches.
                $node  = $current->parentNode;
                $found = null;

                while ($node instanceof DOMElement) {
                    if ($this->matchesCompound($node, $target)) {
                        $found = $node;
                        break;
                    }

                    $node = $node->parentNode;
                }

                if ($found === null) {
                    return false;
                }

                $current = $found;
            }
        }

        return true;
    }

    private function matchesCompound(DOMElement $el, SelectorPart $p): bool
    {
        if ($p->tag !== null && $p->tag !== '*' && strtolower($el->nodeName) !== $p->tag) {
            return false;
        }

        if ($p->id !== null && $el->getAttribute('id') !== $p->id) {
            return false;
        }

        if ($p->classes !== []) {
            $classes = preg_split('/\s+/', trim($el->getAttribute('class'))) ?: [];

            if (array_any($p->classes, fn($c) => !in_array($c, $classes, true))) {
                return false;
            }
        }

        foreach ($p->attrs as [$name, $op, $value, $insensitive]) {
            if (!$el->hasAttribute($name)) {
                return false;
            }

            $actual = $el->getAttribute($name);

            // Selectors §6.3.3: the `i` flag folds ASCII case on the *value*
            // only. Folding both sides here is the same comparison and keeps
            // every operator below written once.
            if ($insensitive) {
                $actual = strtolower($actual);
                $value  = strtolower($value);
            }

            $ok = match ($op) {
                ''      => true,
                '='     => $actual === $value,
                '^='    => str_starts_with($actual, $value),
                '$='    => str_ends_with($actual, $value),
                '*='    => str_contains($actual, $value),
                '~='    => in_array($value, preg_split('/\s+/', trim($actual)) ?: [], true),
                default => false,
            };

            if (!$ok) {
                return false;
            }
        }

        return array_all($p->pseudos, fn($pseudo) => $this->matchesPseudo($el, $pseudo));
    }

    private function matchesPseudo(DOMElement $el, string $pseudo): bool
    {
        if (preg_match('/^([\w-]+)\((.*)\)$/s', $pseudo, $m)) {
            return $this->matchesFunctionalPseudo($el, strtolower($m[1]), trim($m[2]));
        }

        $typeIndex = fn(): int => $this->elementIndex($el, strtolower($el->nodeName));

        return match ($pseudo) {
            'first-child'   => $this->previousElement($el) === null,
            'last-child'    => $this->nextElement($el) === null,
            'only-child'    => $this->previousElement($el) === null && $this->nextElement($el) === null,
            'first-of-type' => $typeIndex() === 1,
            'last-of-type'  => $this->siblingsOfType($el) === $typeIndex(),
            'only-of-type'  => $this->siblingsOfType($el) === 1,
            'root'          => !$el->parentNode instanceof DOMElement,
            'empty'         => $this->isEmptyElement($el),
            // Interactive states never apply to a printed document, but the
            // rule that carries them must still be skipped rather than break
            // the whole selector.
            'hover', 'focus', 'active', 'visited', 'focus-visible',
            'focus-within', 'checked', 'disabled', 'target' => false,
            'enabled', 'any-link', 'link'                   => false,
            default                                         => false,
        };
    }

    private function matchesFunctionalPseudo(DOMElement $el, string $name, string $argument): bool
    {
        return match ($name) {
            'not'            => !$this->matchesAnyOf($el, $argument),
            'is', 'where',
            'matches', 'any' => $this->matchesAnyOf($el, $argument),
            'nth-child'      => $this->matchesNth($argument, $this->elementIndex($el)),
            'nth-last-child' => $this->matchesNth($argument, $this->siblingCount($el) - $this->elementIndex($el) + 1),
            'nth-of-type'    => $this->matchesNth($argument, $this->elementIndex($el, strtolower($el->nodeName))),
            'nth-last-of-type' => $this->matchesNth(
                $argument,
                $this->siblingsOfType($el) - $this->elementIndex($el, strtolower($el->nodeName)) + 1,
            ),
            'has'            => false,
            default          => false,
        };
    }

    /** Whether the element matches any selector in a comma-separated list. */
    private function matchesAnyOf(DOMElement $el, string $selectorList): bool
    {
        foreach (explode(',', $selectorList) as $candidate) {
            $selector = Selector::parse(trim($candidate));

            if ($selector !== null && $this->matches($el, $selector)) {
                return true;
            }
        }

        return false;
    }

    /** The `an+b` microsyntax, plus the `odd` and `even` keywords. */
    private function matchesNth(string $argument, int $index): bool
    {
        $argument = strtolower(str_replace(' ', '', $argument));

        if ($argument === 'odd') {
            return $index % 2 === 1;
        }

        if ($argument === 'even') {
            return $index % 2 === 0;
        }

        if (preg_match('/^[+-]?\d+$/', $argument)) {
            return $index === (int) $argument;
        }

        if (!preg_match('/^([+-]?\d*)n([+-]\d+)?$/', $argument, $m)) {
            return false;
        }

        $step   = $m[1] === '' || $m[1] === '+' ? 1 : ($m[1] === '-' ? -1 : (int) $m[1]);
        $offset = (int) ($m[2] ?? 0);

        if ($step === 0) {
            return $index === $offset;
        }

        // Integer arithmetic throughout: `(1 - 1) / 2` is int 0 while floor()
        // returns float 0.0, and those are not identical.
        $distance = $index - $offset;

        return $distance % $step === 0 && intdiv($distance, $step) >= 0;
    }

    private function isEmptyElement(DOMElement $el): bool
    {
        foreach ($el->childNodes as $child) {
            if ($child instanceof DOMElement) {
                return false;
            }

            if ($child->nodeValue !== null && trim($child->nodeValue) !== '') {
                return false;
            }
        }

        return true;
    }

    private function siblingCount(DOMElement $el): int
    {
        $parent = $el->parentNode;

        if (!$parent instanceof DOMNode) {
            return 1;
        }

        $count = 0;

        foreach ($parent->childNodes as $child) {
            if ($child instanceof DOMElement) {
                $count++;
            }
        }

        return $count;
    }

    private function siblingsOfType(DOMElement $el): int
    {
        $parent = $el->parentNode;

        if (!$parent instanceof DOMNode) {
            return 1;
        }

        $tag   = strtolower($el->nodeName);
        $count = 0;

        foreach ($parent->childNodes as $child) {
            if ($child instanceof DOMElement && strtolower($child->nodeName) === $tag) {
                $count++;
            }
        }

        return $count;
    }

    /** The element's 1-based position among its siblings, or among those of one tag. */
    private function elementIndex(DOMElement $el, ?string $ofType = null): int
    {
        $i = 1;
        $n = $this->previousElement($el);

        while ($n !== null) {
            if ($ofType === null || strtolower($n->nodeName) === $ofType) {
                $i++;
            }

            $n = $this->previousElement($n);
        }

        return $i;
    }

    private function previousElement(DOMNode $n): ?DOMElement
    {
        $p = $n->previousSibling;

        while ($p !== null && !$p instanceof DOMElement) {
            $p = $p->previousSibling;
        }

        return $p instanceof DOMElement ? $p : null;
    }

    private function nextElement(DOMNode $n): ?DOMElement
    {
        $p = $n->nextSibling;

        while ($p !== null && !$p instanceof DOMElement) {
            $p = $p->nextSibling;
        }

        return $p instanceof DOMElement ? $p : null;
    }

    // -----------------------------------------------------------------
    // cascade
    // -----------------------------------------------------------------

    /**
     * @param array<string,string> $inherited computed values from the parent
     *
     * @return array<string,string> declared+inherited values for this element
     */
    /**
     * The rules that win each property for an element or one of its
     * pseudo-elements, before anything is inherited.
     *
     * @return array<string,array{0:int,1:int,2:int,3:string}>
     */
    private function winningDeclarations(DOMElement $el, ?string $pseudoElement): array
    {
        $winning = []; // prop => [important, specificity, order, value]

        foreach ($this->candidates($el) as $rule) {
            $parts = $rule->selector->parts;

            // A rule written for ::before must not style the element itself.
            if (($parts[count($parts) - 1]->element ?? null) !== $pseudoElement) {
                continue;
            }

            if ($rule->queries !== [] && !$this->containerQueriesHold($el, $rule->queries)) {
                continue;
            }

            if ($rule->scopeBounds !== [] && !$this->inScope($el, $rule->scopeBounds)) {
                continue;
            }

            if (!$this->matches($el, $rule->selector)) {
                continue;
            }

            foreach ($rule->declarations as $prop => $d) {
                $candidate = [
                    self::cascadeRank($rule->origin, $d['important']),
                    $rule->selector->specificity,
                    $rule->order,
                ];

                if (!isset($winning[$prop]) || $candidate >= array_slice($winning[$prop], 0, 3)) {
                    $winning[$prop] = [$candidate[0], $candidate[1], $candidate[2], $d['value']];
                }
            }
        }

        return $winning;
    }

    /**
     * CSS Cascade §6.4.1's order of precedence, as one number to sort on.
     *
     * The four steps that exist here are UA, author, author `!important` and
     * UA `!important`, in that order. It is compared **before** specificity,
     * which is the whole point: `* { margin: 0; padding: 0 }` at (0,0,0) is
     * below every rule in the sheet above, so sorting on specificity first
     * left the UA `padding` on a `<td>` and the UA `padding-left` on a `<li>`
     * exactly where they were. The commonest reset in CSS did nothing to
     * `p`, `h1`-`h6`, `ul`, `ol`, `li`, `blockquote`, `td` or `th`.
     *
     * `!important` inverts the origins rather than merely outranking them, so
     * a UA `!important` declaration is the one thing an author cannot
     * overrule. Nothing in this UA sheet uses it today; the step is here so
     * that adding one means what it says.
     */
    private static function cascadeRank(int $origin, bool $important): int
    {
        if (!$important) {
            return $origin === CssRule::ORIGIN_AUTHOR ? 1 : 0;
        }

        return $origin === CssRule::ORIGIN_AUTHOR ? 2 : 3;
    }

    /**
     * Which properties a pseudo-element's own rules set, shorthands expanded.
     *
     * `cascade()` hands back a whole computed map, where a property nobody
     * declared reads exactly like one declared at its initial value. A
     * pseudo-element that may only set part of the property set has to tell
     * the two apart, or `p::first-line { color: red }` would quietly reset the
     * paragraph's `text-decoration` on its first line.
     *
     * `null` asks the same question about the element itself, which is what an
     * element inside an inline `<svg>` needs: only the declarations a rule
     * actually made for it may be carried into its `style` attribute, because
     * the whole computed map would write the page's own inherited values over
     * SVG's inheritance. {@see HtmlBuilder::styleInlineSvg}.
     *
     * @return array<string,true>
     */
    public function declaredProperties(DOMElement $el, ?string $pseudoElement): array
    {
        $winning = $this->winningDeclarations($el, $pseudoElement);

        if ($winning === []) {
            return [];
        }

        $declared = $this->expandShorthands(
            $this->dropOutrankedLonghands(array_map(static fn(array $w): string => $w[3], $winning), $winning),
        );

        return array_map(static fn(): bool => true, $declared);
    }

    /**
     * @param ?string $pseudoElement `before` or `after` to cascade that
     *                               pseudo-element instead of the element
     */
    public function cascade(DOMElement $el, array $inherited, ?string $pseudoElement = null): array
    {
        // `font-size` is the one length the cascade resolves itself, and it
        // can be written in `cqw` like any other.
        //
        // It can be written in `ex` too, and there the face is the PARENT's,
        // because a font size cannot be a metric of the font it is deciding.
        // CSS Values 4 section 6.1.2 says so in as many words, and `$inherited`
        // is that parent's own computed style.
        $this->resolveLengthsFor($el, FontRegistry::default()->faceFor($inherited));

        $winning = $this->winningDeclarations($el, $pseudoElement);

        // Inline style attribute beats everything but !important. It styles
        // the element, never one of its pseudo-elements. It is author-origin,
        // so it outranks the whole UA sheet before its specificity is even
        // read, and a UA `!important` still beats it.
        if ($pseudoElement === null && $el->hasAttribute('style')) {
            $parser = new CssParser();

            foreach ($parser->parseDeclarations($el->getAttribute('style')) as $prop => $d) {
                $candidate = [
                    self::cascadeRank(CssRule::ORIGIN_AUTHOR, $d['important']),
                    1_000_000,
                    PHP_INT_MAX,
                ];

                if (!isset($winning[$prop]) || $candidate >= array_slice($winning[$prop], 0, 3)) {
                    $winning[$prop] = [$candidate[0], $candidate[1], $candidate[2], $d['value']];
                }
            }
        }

        $declared = array_map(function ($w) { return $w[3]; }, $winning);

        // Custom properties inherit, and everything else may reference them,
        // so they have to be resolved before shorthands are expanded.

        $vars = array_filter($inherited, fn($prop) => str_starts_with($prop, '--'), ARRAY_FILTER_USE_KEY);

        foreach ($declared as $prop => $value) {
            if (str_starts_with($prop, '--')) {
                $vars[$prop] = trim($value);
            }
        }

        foreach ($declared as $prop => $value) {
            if (str_starts_with($prop, '--')) {
                continue;
            }

            if (str_contains($value, 'var(')) {
                $substituted     = $this->substituteVars($value, $vars);
                $declared[$prop] = $this->usableValue($prop, $substituted) ? $substituted : 'unset';
            }
        }

        $declared = $this->expandShorthands($this->dropOutrankedLonghands($declared, $winning));

        // Start from inherited values, then initial, then declared.
        $computed = [];

        foreach (self::INITIAL as $prop => $initial) {
            $computed[$prop] = isset(self::INHERITED[$prop]) && isset($inherited[$prop])
                ? $inherited[$prop]
                : $initial;
        }

        foreach ($inherited as $prop => $value) {
            if (isset(self::INHERITED[$prop])) {
                $computed[$prop] = $value;
            }
        }

        foreach ($vars as $prop => $value) {
            $computed[$prop] = $value;
        }

        foreach ($declared as $prop => $value) {
            if (str_starts_with($prop, '--')) {
                continue;
            }

            // CSS Cascade §7: `unset` is `inherit` on an inherited property
            // and `initial` on every other one, which is also what a
            // declaration invalid at computed-value time falls back to.
            //
            // The length test is what keeps a data URI or a gradient out of
            // `strtolower()`, which would copy the whole of it once per
            // element: the longest keyword here is seven characters.
            $wide = strlen($value) <= 24 ? (self::CSS_WIDE[strtolower(trim($value))] ?? '') : '';

            if ($wide === 'unset') {
                $wide = isset(self::INHERITED[$prop]) ? 'inherit' : 'initial';
            }

            if ($wide === 'inherit') {
                if (isset($inherited[$prop])) {
                    $computed[$prop] = $inherited[$prop];
                }

                continue;
            }

            if ($wide === 'initial') {
                if (isset(self::CSS_INITIAL[$prop]) || isset(self::INITIAL[$prop])) {
                    $computed[$prop] = self::CSS_INITIAL[$prop] ?? self::INITIAL[$prop];

                    continue;
                }

                // A property with no entry above has no value of its own until
                // something declares one, and its consumer's default is that
                // value: leaving the key out is how the initial is spelled.
                unset($computed[$prop]);

                continue;
            }

            $computed[$prop] = $value;
        }

        // font-size resolves against the parent's, so do it here. The result
        // carries an explicit 'pt' unit: a bare number would be re-read as px
        // by the next level down and shrink by 0.75 at every generation.
        $parentSize = isset($inherited['font-size'])
            ? ($this->length($inherited['font-size'], 12.0, $this->rootFontSize) ?? 12.0)
            : 12.0;

        // A monospace box is measured against the size this chain would have
        // with no monospace family in it, which {@see ORDINARY_SIZE} carries
        // down, and not against its parent's own size. Chrome reaches the same
        // place by keeping the *specified* size beside the computed one, and
        // the two shapes that say it has to be the ordinary size are on
        // `docs/harness/probes/E14-monospace-size.html`: `md`, a monospace box
        // inside a monospace box, is 13px and not 13 x 13/16, and `mf`, a
        // proportional box inside a monospace one, is back at 16px.
        $ordinary = isset($inherited[self::ORDINARY_SIZE])
            ? (float) $inherited[self::ORDINARY_SIZE]
            : $parentSize;

        // Only while the chain is still at the base: an explicit size anywhere
        // above wins over the default, which is Chrome's `mh` and `mq`, both
        // 20px under a `font-size: 20px` ancestor.
        $monospace = abs($ordinary - self::BASE_FONT_SIZE) < 1e-9
            && $this->isMonospaceFamily($computed['font-family'] ?? '');

        $basis    = $monospace ? self::MONOSPACE_BASE_FONT_SIZE : $ordinary;
        $inherits = !isset($declared['font-size']) && isset($inherited['font-size']);

        $size = $inherits
            ? $basis
            : $this->fontSize($computed['font-size'] ?? '12px', $basis, $monospace);

        $computed['font-size'] = $size . 'pt';

        // Assigned last on purpose: a sheet that declares this property by hand
        // cannot reach the value the next generation inherits.
        $computed[self::ORDINARY_SIZE] = (string) ($inherits ? $ordinary : $size);

        return $computed;
    }

    /**
     * Whether a family list is the bare generic `monospace` and nothing else,
     * which is what Chrome keys its fixed default off.
     *
     * Naming a real monospace face does **not** count, and neither does putting
     * the keyword in a list: on `E14-monospace-size.html` Chrome gives `Menlo`,
     * `'Courier New'`, `monospace, Helvetica` and `Helvetica, monospace` the
     * ordinary 16px, and only the keyword alone the fixed 13.
     */
    private function isMonospaceFamily(string $family): bool
    {
        return strtolower(trim($family)) === 'monospace';
    }

    /** The only directory @font-face urls may name a file in. */
    public string $basePath = '';

    /**
     * Whether a document may fetch an image over the network, and from where.
     * Off unless the operator turned it on and named the hosts; the default
     * instance reaches nothing.
     */
    public RemoteImages $remoteImages;

    private function resolvePath(string $candidate): ?string
    {
        return AssetPath::resolve($candidate, $this->basePath);
    }

    public function setRootFontSize(float $pt): void
    {
        $this->rootFontSize = $pt;
    }

    /**
     * The subset of a computed style an anonymous box may keep.
     *
     * CSS 2.1 §9.2.1.1: an anonymous box inherits the inherited properties and
     * takes the initial value for every other one, so the box a table generates
     * around stray content carries the font and never the table's own width,
     * border or background. Custom properties come along because a descendant's
     * `var()` still has to resolve through it.
     *
     * @param  array<string,string> $computed
     * @return array<string,string>
     */
    public function inheritedOnly(array $computed): array
    {
        return array_filter(
            $computed,
            static fn(string $prop): bool => isset(self::INHERITED[$prop]) || str_starts_with($prop, '--'),
            ARRAY_FILTER_USE_KEY,
        );
    }

    // -----------------------------------------------------------------
    // shorthands
    // -----------------------------------------------------------------
    /** Which longhands each shorthand is responsible for. */
    private const array SHORTHAND_LONGHANDS = [
        'margin'        => [
            'margin-top', 'margin-right', 'margin-bottom', 'margin-left',
            'margin-block', 'margin-inline',
            'margin-block-start', 'margin-block-end', 'margin-inline-start', 'margin-inline-end',
        ],
        'padding'       => [
            'padding-top', 'padding-right', 'padding-bottom', 'padding-left',
            'padding-block', 'padding-inline',
            'padding-block-start', 'padding-block-end', 'padding-inline-start', 'padding-inline-end',
        ],
        'font'          => ['font-size', 'line-height', 'font-family', 'font-weight', 'font-style'],
        'background'    => [
            'background-color', 'background-image',
            'background-repeat', 'background-position', 'background-size',
            'background-clip', 'background-origin',
        ],
        'flex'          => ['flex-grow', 'flex-shrink', 'flex-basis'],
        'flex-flow'     => ['flex-direction', 'flex-wrap'],
        'outline'       => ['outline-width', 'outline-style', 'outline-color'],
        'list-style'    => ['list-style-type', 'list-style-position', 'list-style-image'],
        'border-image'  => [
            'border-image-source', 'border-image-slice', 'border-image-width',
            'border-image-outset', 'border-image-repeat',
        ],
        'mask'          => ['mask-image', 'mask-size', 'mask-repeat', 'mask-position', 'mask-mode'],
        'gap'           => ['row-gap', 'column-gap'],
        'overflow'      => ['overflow-x', 'overflow-y'],
        'columns'       => ['column-count', 'column-width'],
        'column-rule'   => ['column-rule-width', 'column-rule-style', 'column-rule-color'],
        'container'     => ['container-name', 'container-type'],
        'text-decoration' => ['text-decoration-color', 'text-decoration-thickness', 'text-decoration-style'],
        'border-width'  => ['border-top-width', 'border-right-width', 'border-bottom-width', 'border-left-width'],
        'border-style'  => ['border-top-style', 'border-right-style', 'border-bottom-style', 'border-left-style'],
        'border-color'  => ['border-top-color', 'border-right-color', 'border-bottom-color', 'border-left-color'],
        'border-top'    => ['border-top-width', 'border-top-style', 'border-top-color'],
        'border-right'  => ['border-right-width', 'border-right-style', 'border-right-color'],
        'border-bottom' => ['border-bottom-width', 'border-bottom-style', 'border-bottom-color'],
        'border-left'   => ['border-left-width', 'border-left-style', 'border-left-color'],
        'border'        => [
            'border-width', 'border-style', 'border-color',
            'border-top-width', 'border-right-width', 'border-bottom-width', 'border-left-width',
            'border-top-style', 'border-right-style', 'border-bottom-style', 'border-left-style',
            'border-top-color', 'border-right-color', 'border-bottom-color', 'border-left-color',
        ],
        'grid-column'   => ['grid-column-start', 'grid-column-end'],
        'grid-row'      => ['grid-row-start', 'grid-row-end'],
        'grid-area'     => ['grid-row-start', 'grid-column-start', 'grid-row-end', 'grid-column-end'],
        'grid-template' => ['grid-template-rows', 'grid-template-columns'],
        'place-items'   => ['align-items', 'justify-items'],
        'place-content' => ['align-content', 'justify-content'],
        'place-self'    => ['align-self', 'justify-self'],
        'inset'         => ['top', 'right', 'bottom', 'left', 'inset-block', 'inset-inline'],
        'white-space'   => ['white-space-collapse', 'text-wrap'],
    ];

    /**
     * CSS Logical Properties written the physical way. This engine lays every
     * document out horizontally, top to bottom, so each of these is the
     * physical property under another name rather than an approximation of it,
     * and mapping them in the cascade is what lets the two spellings rank
     * against each other by specificity.
     *
     * The border logical group is deliberately absent: nothing measures it
     * yet.
     */
    private const array LOGICAL_LONGHANDS = [
        'block-size'           => 'height',
        'inline-size'          => 'width',
        'min-block-size'       => 'min-height',
        'min-inline-size'      => 'min-width',
        'max-block-size'       => 'max-height',
        'max-inline-size'      => 'max-width',
        'margin-block-start'   => 'margin-top',
        'margin-block-end'     => 'margin-bottom',
        'margin-inline-start'  => 'margin-left',
        'margin-inline-end'    => 'margin-right',
        'padding-block-start'  => 'padding-top',
        'padding-block-end'    => 'padding-bottom',
        'padding-inline-start' => 'padding-left',
        'padding-inline-end'   => 'padding-right',
        'inset-block-start'    => 'top',
        'inset-block-end'      => 'bottom',
        'inset-inline-start'   => 'left',
        'inset-inline-end'     => 'right',
    ];

    /** The two-value logical shorthands, block axis first then inline. */
    private const array LOGICAL_PAIRS = [
        'margin-block'   => ['margin-top', 'margin-bottom'],
        'margin-inline'  => ['margin-left', 'margin-right'],
        'padding-block'  => ['padding-top', 'padding-bottom'],
        'padding-inline' => ['padding-left', 'padding-right'],
        'inset-block'    => ['top', 'bottom'],
        'inset-inline'   => ['left', 'right'],
    ];

    /** CSS Box Alignment's shorthands: the block axis, then the inline one. */
    private const array PLACE_SHORTHANDS = [
        'place-items'   => ['align-items', 'justify-items'],
        'place-content' => ['align-content', 'justify-content'],
        'place-self'    => ['align-self', 'justify-self'],
    ];

    /**
     * Legacy names a browser-written stylesheet carries. Each is the same
     * property as the one it maps onto, so the value travels across unchanged.
     */
    private const array PROPERTY_ALIASES = [
        'word-wrap'          => 'overflow-wrap',
        '-webkit-mask-image' => 'mask-image',
        '-webkit-mask'       => 'mask',
        '-webkit-mask-size'     => 'mask-size',
        '-webkit-mask-repeat'   => 'mask-repeat',
        '-webkit-mask-position' => 'mask-position',
    ];

    /**
     * Shorthands are expanded after the cascade has picked a winner per
     * property, and the expansion only fills in longhands that are absent. So
     * a longhand from a weaker rule would otherwise survive a shorthand from a
     * stronger one: the UA sheet's `body { font-size: 12px }` beat an author's
     * `body { font: 8pt Helvetica }`. Drop the longhands the winning shorthand
     * outranks, and the expansion then does the right thing.
     *
     * @param array<string,string>                        $declared
     * @param array<string,array{0:int,1:int,2:int,3:string}> $winning
     *
     * @return array<string,string>
     */
    private function dropOutrankedLonghands(array $declared, array $winning): array
    {
        foreach ($this->shorthandLonghands() as $shorthand => $longhands) {
            if (!isset($winning[$shorthand])) {
                continue;
            }

            $priority = array_slice($winning[$shorthand], 0, 3);

            foreach ($longhands as $longhand) {
                if (isset($winning[$longhand]) && array_slice($winning[$longhand], 0, 3) < $priority) {
                    unset($declared[$longhand]);
                }
            }
        }

        return $declared;
    }

    /**
     * Every shorthand beside the longhands it can outrank, with the logical
     * spellings, the alignment shorthands and the legacy aliases folded in:
     * each of those reaches the same longhands the physical spelling does, so
     * a `.box { width: 96px }` has to lose to a `style="inline-size: 48px"`
     * exactly as it loses to a `style="width: 48px"`.
     *
     * @return array<string, string[]>
     */
    private function shorthandLonghands(): array
    {
        if (self::$shorthandLonghands !== null) {
            return self::$shorthandLonghands;
        }

        $map = self::SHORTHAND_LONGHANDS;

        foreach ([self::LOGICAL_PAIRS, self::PLACE_SHORTHANDS] as $table) {
            foreach ($table as $shorthand => $longhands) {
                $map[$shorthand] = $longhands;
            }
        }

        foreach ([self::LOGICAL_LONGHANDS, self::PROPERTY_ALIASES] as $table) {
            foreach ($table as $shorthand => $longhand) {
                $map[$shorthand] = [$longhand];
            }
        }

        return self::$shorthandLonghands = $map;
    }

    /** @var array<string, string[]>|null */
    private static ?array $shorthandLonghands = null;

    private function expandShorthands(array $d): array
    {
        foreach (self::PROPERTY_ALIASES as $legacy => $property) {
            if (isset($d[$legacy])) {
                $d[$property] ??= $d[$legacy];

                unset($d[$legacy]);
            }
        }

        // The narrower spelling goes first, so a `margin-block-start` beats a
        // `margin-block`, which beats a `margin`, which is the order the
        // physical longhands already resolve in below.
        foreach (self::LOGICAL_LONGHANDS as $logical => $physical) {
            if (isset($d[$logical])) {
                $d[$physical] ??= $d[$logical];

                unset($d[$logical]);
            }
        }

        foreach (self::LOGICAL_PAIRS as $shorthand => [$start, $end]) {
            if (!isset($d[$shorthand])) {
                continue;
            }

            $parts = $this->topLevelTokens($d[$shorthand]) ?: ['0'];

            $d[$start] ??= $parts[0];
            $d[$end]   ??= $parts[1] ?? $parts[0];

            unset($d[$shorthand]);
        }

        if (isset($d['inset'])) {
            $sides = $this->fourSides($d['inset']);

            foreach (['top', 'right', 'bottom', 'left'] as $i => $side) {
                $d[$side] ??= $sides[$i];
            }

            unset($d['inset']);
        }

        foreach (self::PLACE_SHORTHANDS as $shorthand => [$block, $inline]) {
            if (!isset($d[$shorthand])) {
                continue;
            }

            $parts = preg_split('/\s+/', trim($d[$shorthand])) ?: [];

            $d[$block]  ??= $parts[0] ?? 'normal';
            $d[$inline] ??= $parts[1] ?? $parts[0] ?? 'normal';

            unset($d[$shorthand]);
        }

        /*
         * CSS Text 4 splits `white-space` into a collapsing rule and a
         * wrapping one, and only the shorthand reaches layout here, so a sheet
         * that writes the longhands has them folded back into it. A document
         * that writes `white-space` itself is left exactly as it was, which is
         * what keeps the UA sheet's `pre { white-space: pre }` in charge.
         */
        if (!isset($d['white-space']) && (isset($d['white-space-collapse']) || isset($d['text-wrap']))) {
            $collapse = strtolower(trim($d['white-space-collapse'] ?? 'collapse'));
            $wraps    = strtolower(trim($d['text-wrap'] ?? 'wrap')) !== 'nowrap';

            $d['white-space'] = match (true) {
                $collapse === 'preserve-breaks' => 'pre-line',
                $collapse === 'preserve'        => $wraps ? 'pre-wrap' : 'pre',
                default                         => $wraps ? 'normal' : 'nowrap',
            };
        }

        unset($d['white-space-collapse'], $d['text-wrap']);

        foreach (['margin', 'padding'] as $box) {
            if (isset($d[$box])) {
                $sides = $this->fourSides($d[$box]);

                foreach (['top', 'right', 'bottom', 'left'] as $i => $side) {
                    $d["$box-$side"] ??= $sides[$i];
                }

                unset($d[$box]);
            }
        }

        /*
         * CSS Overflow §3: `overflow` is a shorthand for `overflow-x` and
         * `overflow-y`, one value for both axes or one each. Expanding it here
         * rather than reading the shorthand at the box is what lets a longhand
         * reach the box at all, and it is what makes the two rank against each
         * other by specificity: `dropOutrankedLonghands` above has just thrown
         * away any longhand a winning `overflow` outranks.
         */
        if (isset($d['overflow'])) {
            $parts = preg_split('/\s+/', strtolower(trim($d['overflow']))) ?: [];

            $d['overflow-x'] ??= $parts[0] ?? 'visible';
            $d['overflow-y'] ??= $parts[1] ?? $parts[0] ?? 'visible';

            unset($d['overflow']);
        }

        if (isset($d['flex'])) {
            $parts = preg_split('/\s+/', trim($d['flex'])) ?: [];

            if (count($parts) === 1 && $parts[0] === 'none') {
                $d['flex-grow']   ??= '0';
                $d['flex-shrink'] ??= '0';
                $d['flex-basis']  ??= 'auto';
            } elseif (count($parts) === 1 && is_numeric($parts[0])) {
                $d['flex-grow']   ??= $parts[0];
                $d['flex-shrink'] ??= '1';
                $d['flex-basis']  ??= '0';
            } else {
                $d['flex-grow']   ??= $parts[0] ?? '0';
                $d['flex-shrink'] ??= $parts[1] ?? '1';
                $d['flex-basis']  ??= $parts[2] ?? '0';
            }

            unset($d['flex']);
        }

        if (isset($d['flex-flow'])) {
            foreach (preg_split('/\s+/', strtolower(trim($d['flex-flow']))) ?: [] as $part) {
                if (in_array($part, ['row', 'row-reverse', 'column', 'column-reverse'], true)) {
                    $d['flex-direction'] ??= $part;
                } elseif (in_array($part, ['nowrap', 'wrap', 'wrap-reverse'], true)) {
                    $d['flex-wrap'] ??= $part;
                }
            }

            unset($d['flex-flow']);
        }

        if (isset($d['background'])) {
            foreach ($this->backgroundShorthand($d['background']) as $property => $value) {
                $d[$property] ??= $value;
            }

            unset($d['background']);
        }

        // Per-side shorthands first, so `border-bottom: 2px solid red` keeps
        // its own width and color when `border` also names one.
        foreach (['top', 'right', 'bottom', 'left'] as $side) {
            if (!isset($d["border-$side"])) {
                continue;
            }

            foreach ($this->borderShorthand($d["border-$side"]) as $part => $value) {
                $d["border-$side-$part"] ??= $value;
            }

            unset($d["border-$side"]);
        }

        if (isset($d['border'])) {
            foreach ($this->borderShorthand($d['border']) as $part => $value) {
                $d["border-$part"] ??= $value;
            }

            unset($d['border']);
        }

        // `outline` and `column-rule` take the same three components in the
        // same order, and both are a stroke rather than an edge of the box.
        foreach (['outline', 'column-rule'] as $stroke) {
            if (!isset($d[$stroke])) {
                continue;
            }

            foreach ($this->borderShorthand($d[$stroke]) as $part => $value) {
                $d["$stroke-$part"] ??= $value;
            }

            unset($d[$stroke]);
        }

        foreach (['width', 'style', 'color'] as $part) {
            if (!isset($d["border-$part"])) {
                continue;
            }

            $sides = $this->fourSides($d["border-$part"]);

            foreach (['top', 'right', 'bottom', 'left'] as $i => $side) {
                $d["border-$side-$part"] ??= $sides[$i];
            }
        }

        if (isset($d['font'])) {
            foreach ($this->fontShorthand($d['font']) as $property => $value) {
                $d[$property] ??= $value;
            }

            unset($d['font']);
        }

        /*
         * `text-decoration: <line> || <style> || <color> || <thickness>`,
         * order-free. The shorthand stays in place because the line keyword is
         * still read from it; what this adds is the two longhands beside it,
         * so `text-decoration: underline wavy #e53935` is no longer half read.
         */
        if (isset($d['text-decoration'])) {
            foreach ($this->decorationShorthand($d['text-decoration']) as $property => $value) {
                $d[$property] ??= $value;
            }
        }

        /*
         * `list-style: <type> || <position> || <image>`, order-free. `none`
         * sets the type, which is how a document turns the marker off before
         * numbering the items with a counter instead, and an image is any
         * token that names one: a `url()` or one of the gradient functions.
         */
        if (isset($d['list-style'])) {
            foreach ($this->topLevelTokens($d['list-style']) as $token) {
                $lower = strtolower($token);

                if ($lower === 'inside' || $lower === 'outside') {
                    $d['list-style-position'] ??= $lower;
                } elseif (self::namesAnImage($lower)) {
                    $d['list-style-image'] ??= $token;
                } else {
                    $d['list-style-type'] ??= $lower;
                }
            }

            unset($d['list-style']);
        }

        /*
         * `border-image: <source> || <slice> [ / <width> [ / <outset> ]? ]? ||
         * <repeat>`. The slashes split it: everything before the first one
         * holds the source, the slice and the repeat keywords in any order,
         * the second segment is the image width and the third the outset.
         */
        if (isset($d['border-image'])) {
            $segments = self::splitOnSlashes($d['border-image']);
            $head     = [];

            foreach ($this->topLevelTokens($segments[0] ?? '') as $token) {
                $lower = strtolower($token);

                if (self::namesAnImage($lower) || $lower === 'none') {
                    $d['border-image-source'] ??= $token;
                } elseif (in_array($lower, ['stretch', 'repeat', 'round', 'space'], true)) {
                    $d['border-image-repeat'] ??= $lower;
                } else {
                    $head[] = $lower;
                }
            }

            if ($head !== []) {
                $d['border-image-slice'] ??= implode(' ', $head);
            }

            if (isset($segments[1]) && $segments[1] !== '') {
                $d['border-image-width'] ??= $segments[1];
            }

            if (isset($segments[2]) && $segments[2] !== '') {
                $d['border-image-outset'] ??= $segments[2];
            }

            unset($d['border-image']);
        }

        if (isset($d['mask'])) {
            foreach ($this->maskShorthand($d['mask']) as $property => $value) {
                $d[$property] ??= $value;
            }

            unset($d['mask']);
        }

        if (isset($d['columns'])) {
            foreach (preg_split('/\s+/', trim($d['columns'])) ?: [] as $bit) {
                if (ctype_digit($bit)) {
                    $d['column-count'] ??= $bit;
                } elseif ($bit !== '' && strtolower($bit) !== 'auto') {
                    $d['column-width'] ??= $bit;
                }
            }

            unset($d['columns']);
        }

        // CSS Containment 3 section 2.3: `container: <name> / <type>`, and the
        // type half is optional, so a bare `container: card` names a container
        // that is still `container-type: normal` and therefore not one.
        if (isset($d['container'])) {
            $bits = array_map('trim', explode('/', $d['container'], 2));

            $d['container-name'] ??= $bits[0] === '' ? 'none' : $bits[0];

            if (isset($bits[1]) && $bits[1] !== '') {
                $d['container-type'] ??= $bits[1];
            }

            unset($d['container']);
        }

        if (isset($d['gap'])) {
            $parts           = preg_split('/\s+/', trim($d['gap'])) ?: [];
            $d['row-gap']    ??= $parts[0] ?? '0';
            $d['column-gap'] ??= $parts[1] ?? $parts[0] ?? '0';
        }

        foreach (['grid-column' => 'grid-column', 'grid-row' => 'grid-row'] as $short => $axis) {
            if (!isset($d[$short])) {
                continue;
            }

            $bits             = array_map('trim', explode('/', $d[$short]));
            $d["$axis-start"] ??= $bits[0];

            if (isset($bits[1])) {
                $d["$axis-end"] ??= $bits[1];
            }

            unset($d[$short]);
        }

        if (isset($d['grid-area']) && !str_contains($d['grid-area'], '/')) {
            // A bare name refers to a template area; keep it for the builder.
            $d['grid-area'] = trim($d['grid-area']);
        } elseif (isset($d['grid-area'])) {
            $bits                   = array_map('trim', explode('/', $d['grid-area']));
            $d['grid-row-start']    ??= $bits[0] ?? 'auto';
            $d['grid-column-start'] ??= $bits[1] ?? 'auto';
            $d['grid-row-end']      ??= $bits[2] ?? 'auto';
            $d['grid-column-end']   ??= $bits[3] ?? 'auto';

            unset($d['grid-area']);
        }

        if (isset($d['grid-template'])) {
            $bits                    = array_map('trim', explode('/', $d['grid-template']));
            $d['grid-template-rows'] ??= $bits[0] ?? 'none';

            if (isset($bits[1])) {
                $d['grid-template-columns'] ??= $bits[1];
            }

            unset($d['grid-template']);
        }

        return $d;
    }

    /**
     * `mask: <image> <position> / <size> <repeat> <mode> <origin> <clip>
     * <composite>`, in any order, which is `background`'s syntax with the
     * color taken out and four keyword lists put in.
     *
     * The placement used to be dropped whole and only the `<image>` survived,
     * so a document that wrote its mask in the shorthand got the initial size,
     * position and repeat whatever it asked for. `SN-mask-shorthand.html` is
     * three longhand slots against the same three written as a shorthand.
     *
     * **A keyword this engine does not read is swallowed rather than left to
     * fall through**, because an unrecognized token would otherwise be taken
     * for a position and place the mask somewhere nothing asked for. That is
     * `mask-origin`, `mask-clip` and `mask-composite`, all three decided out of
     * scope in round 31 and all three still unread.
     *
     * @return array<string,string>
     */
    private function maskShorthand(string $value): array
    {
        $out        = [];
        $position   = [];
        $size       = [];
        $afterSlash = false;

        foreach (self::splitAroundSlashes($this->topLevelTokens($value)) as $token) {
            if ($token === '') {
                continue;
            }

            $lower = strtolower($token);

            if (self::namesAnImage($lower) || $lower === 'none') {
                $out['mask-image'] = $token;
                continue;
            }

            if (in_array($lower, ['repeat', 'repeat-x', 'repeat-y', 'no-repeat', 'space', 'round'], true)) {
                $out['mask-repeat'] = $lower;
                continue;
            }

            if (in_array($lower, ['alpha', 'luminance', 'match-source'], true)) {
                $out['mask-mode'] = $lower;
                continue;
            }

            if (in_array($lower, ['add', 'subtract', 'intersect', 'exclude'], true)) {
                continue;
            }

            if (in_array($lower, [
                'border-box', 'padding-box', 'content-box',
                'fill-box', 'stroke-box', 'view-box', 'no-clip',
            ], true)) {
                continue;
            }

            if (str_starts_with($token, '/')) {
                $afterSlash = true;
                $token      = ltrim($token, '/');
                $lower      = strtolower($token);

                if ($token === '') {
                    continue;
                }
            }

            if ($afterSlash || $lower === 'cover' || $lower === 'contain') {
                $size[]     = $lower;
                $afterSlash = true;
                continue;
            }

            if (
                in_array($lower, ['left', 'right', 'top', 'bottom', 'center'], true)
                || preg_match('/^-?[\d.]/', $token) === 1
            ) {
                $position[] = $lower;
            }
        }

        if ($position !== []) {
            $out['mask-position'] = implode(' ', $position);
        }

        if ($size !== []) {
            $out['mask-size'] = implode(' ', $size);
        }

        return $out;
    }

    /**
     * A token list with `a/b` broken in two, the second piece keeping the
     * slash so the caller can see where the size started.
     *
     * A slash inside a function is not a separator, which is what keeps
     * `data:image/png` and `rgb(0 0 0 / 50%)` in one piece.
     *
     * @param  list<string> $tokens
     * @return list<string>
     */
    private static function splitAroundSlashes(array $tokens): array
    {
        $out = [];

        foreach ($tokens as $token) {
            if (!str_contains($token, '/') || str_contains($token, '(')) {
                $out[] = $token;

                continue;
            }

            foreach (explode('/', $token) as $i => $part) {
                $out[] = $i === 0 ? $part : '/' . $part;
            }
        }

        return $out;
    }

    /**
     * `background: <color> <image> <position>/<size> <repeat>`, in any order.
     *
     * Only the parts this engine paints are pulled out; `background-origin`,
     * `-clip` and `-attachment` are recognized well enough not to be mistaken
     * for a color, which is the failure that matters, since an unparsed token
     * landing in `background-color` is what turns a decorated box blank.
     *
     * @return array<string,string>
     */
    private function backgroundShorthand(string $value): array
    {
        $out        = [];
        $position   = [];
        $size       = [];
        $boxes      = [];
        $afterSlash = false;

        // `center/cover` is one whitespace token and two values.
        foreach (self::splitAroundSlashes($this->topLevelTokens($value)) as $token) {
            if ($token === '') {
                continue;
            }

            $lower = strtolower($token);

            if (str_starts_with($lower, 'url(') || str_starts_with($lower, 'linear-gradient(') || $lower === 'none') {
                $out['background-image'] = $token;
                continue;
            }

            if (in_array($lower, ['repeat', 'repeat-x', 'repeat-y', 'no-repeat', 'space', 'round'], true)) {
                $out['background-repeat'] = $lower;
                continue;
            }

            /*
             * A box keyword in the shorthand sets both `background-origin` and
             * `background-clip`; a second one sets the clip on its own, so
             * `padding-box border-box` is an origin at the padding edge and
             * ink out to the border. `scroll`, `fixed` and `local` are
             * `background-attachment`, which describes a viewport and is out
             * of scope, so they are swallowed rather than mistaken for a
             * color.
             */
            if (in_array($lower, ['border-box', 'padding-box', 'content-box'], true)) {
                $boxes[] = $lower;
                continue;
            }

            if (in_array($lower, ['scroll', 'fixed', 'local'], true)) {
                continue;
            }

            if (str_starts_with($token, '/')) {
                $afterSlash = true;
                $token      = ltrim($token, '/');
                $lower      = strtolower($token);

                if ($token === '') {
                    continue;
                }
            }

            if ($afterSlash || $lower === 'cover' || $lower === 'contain') {
                $size[]     = $lower;
                $afterSlash = true;
                continue;
            }

            if (
                in_array($lower, ['left', 'right', 'top', 'bottom', 'center'], true)
                || preg_match('/^-?[\d.]/', $token) === 1
            ) {
                $position[] = $lower;
                continue;
            }

            $out['background-color'] = $token;
        }

        if ($position !== []) {
            $out['background-position'] = implode(' ', $position);
        }

        if ($size !== []) {
            $out['background-size'] = implode(' ', $size);
        }

        if ($boxes !== []) {
            $out['background-origin'] = $boxes[0];
            $out['background-clip']   = $boxes[1] ?? $boxes[0];
        }

        return $out;
    }

    /**
     * `font: [style] [variant] [weight] <size>[/<line-height>] <family>`.
     * Everything before the size is optional and order-free; anything the
     * shorthand does not name is reset to its initial value, which is what
     * makes `font:` on an element cancel an inherited bold.
     *
     * @return array<string,string>
     */
    private function fontShorthand(string $value): array
    {
        if (!preg_match('#(^|\s)([\d.]+(?:px|pt|em|rem|%)|x{0,2}-?(?:small|large)|medium)\s*(?:/\s*([\d.]+\S*))?\s+(.+)$#i', $value, $m)) {
            return [];
        }

        $out = [
            'font-size'   => $m[2],
            'font-family' => trim($m[4]),
            'font-weight' => 'normal',
            'font-style'  => 'normal',
        ];

        if (($m[3] ?? '') !== '') {
            $out['line-height'] = $m[3];
        }

        foreach (preg_split('/\s+/', trim(substr($value, 0, (int) strpos($value, $m[2])))) ?: [] as $token) {
            $token = strtolower($token);

            if ($token === 'italic' || $token === 'oblique') {
                $out['font-style'] = $token;
            }

            if ($token === 'bold' || $token === 'bolder' || (ctype_digit($token) && (int) $token >= 600)) {
                $out['font-weight'] = $token;
            }
        }

        return $out;
    }

    /**
     * Split `<width> || <style> || <color>` in any order, as the border
     * shorthands allow. Parts that are absent stay absent, so a longhand
     * declared elsewhere still wins.
     *
     * @return array<string,string> keyed width|style|color
     */
    /**
     * The `text-decoration` shorthand's color, thickness and style.
     *
     * The line keyword is left to the caller that already reads it off the
     * shorthand itself, so a shorthand naming nothing else adds nothing and
     * the declaration map is unchanged.
     *
     * `auto` and `from-font` are a thickness rather than a style, and they sit
     * in both keyword lists because the grammar lets either spelling appear
     * with no unit: the thickness arm claims them.
     *
     * @return array<string,string>
     */
    private function decorationShorthand(string $value): array
    {
        $out = [];

        foreach ($this->topLevelTokens($value) as $token) {
            $lower = strtolower($token);

            if (in_array($lower, self::DECORATION_LINES, true)) {
                continue;
            }

            if ($lower === 'auto' || $lower === 'from-font') {
                $out['text-decoration-thickness'] ??= $lower;

                continue;
            }

            if (in_array($lower, self::DECORATION_STYLES, true)) {
                $out['text-decoration-style'] ??= $lower;

                continue;
            }

            if (preg_match('/^[\d.]/', $token) === 1) {
                $out['text-decoration-thickness'] ??= $lower;

                continue;
            }

            $out['text-decoration-color'] ??= $token;
        }

        return $out;
    }

    private const array DECORATION_LINES = ['none', 'underline', 'overline', 'line-through', 'blink', 'spelling-error', 'grammar-error'];

    private const array DECORATION_STYLES = ['solid', 'double', 'dotted', 'dashed', 'wavy'];

    private function borderShorthand(string $value): array
    {
        $out = [];

        foreach ($this->topLevelTokens($value) as $token) {
            if (preg_match('/^[\d.]+(px|pt|em|rem|in|cm|mm)?$/', $token)) {
                $out['width'] ??= $token;
            } elseif (in_array(strtolower($token), self::BORDER_STYLES, true)) {
                $out['style'] ??= strtolower($token);
            } else {
                $out['color'] ??= $token;
            }
        }

        return $out;
    }

    private const array BORDER_STYLES = [
        'none',
        'hidden',
        'solid',
        'dashed',
        'dotted',
        'double',
        'groove',
        'ridge',
        'inset',
        'outset',
    ];

    /** @return string[] top, right, bottom, left */
    private function fourSides(string $value): array
    {
        $p = $this->topLevelTokens($value) ?: ['0'];

        return match (count($p)) {
            1       => [$p[0], $p[0], $p[0], $p[0]],
            2       => [$p[0], $p[1], $p[0], $p[1]],
            3       => [$p[0], $p[1], $p[2], $p[1]],
            default => [$p[0], $p[1], $p[2], $p[3]],
        };
    }

    // -----------------------------------------------------------------
    // value resolution
    // -----------------------------------------------------------------

    /**
     * Replace every var(--name[, fallback]) with its value. Custom properties
     * can reference each other, so this runs to a fixed point with a depth
     * guard rather than a single pass.
     *
     * @param array<string,string> $vars
     */
    public function substituteVars(string $value, array $vars, int $depth = 0): string
    {
        if ($depth > 8 || !str_contains($value, 'var(')) {
            return $value;
        }

        $out = '';
        $i   = 0;
        $len = strlen($value);

        while ($i < $len) {
            $at = strpos($value, 'var(', $i);

            if ($at === false) {
                $out .= substr($value, $i);
                break;
            }

            $out .= substr($value, $i, $at - $i);

            // Find the matching close paren
            $depthParen = 0;
            $j          = $at + 3;

            for (; $j < $len; $j++) {
                if ($value[$j] === '(') {
                    $depthParen++;
                } elseif ($value[$j] === ')') {
                    $depthParen--;

                    if ($depthParen === 0) {
                        break;
                    }
                }
            }

            $inner = substr($value, $at + 4, $j - $at - 4);

            $comma    = strpos($inner, ',');
            $name     = trim($comma === false ? $inner : substr($inner, 0, $comma));
            $fallback = $comma === false ? '' : trim(substr($inner, $comma + 1));

            $resolved = $vars[$name] ?? $fallback;
            $out      .= $this->substituteVars($resolved, $vars, $depth + 1);
            $i        = $j + 1;
        }

        return $out;
    }

    /**
     * Evaluate a calc() expression. Operands are lengths (or bare numbers for
     * multipliers); percentages need a basis and resolve to null without one.
     */
    public function evalCalc(string $expr, float $fontSize, float $rootSize, ?float $basis): ?float
    {
        $tokens = [];

        // The unit list is this tokenizer's own and has to carry every unit
        // {@see length} knows: a unit missing from it is not rejected, it is
        // skipped, so `calc(50cqw - 12px)` tokenized as `50` and read the 50 as
        // points. The longer container-query spellings come before the shorter
        // ones because this pattern is unanchored.
        $pattern = '/(min|max|clamp)\(([^()]*)\)'
            . '|(-?[\d.]+(?:px|pt|em|rem|ex|ch|in|cm|mm|q|pc|vw|vh|vmin|vmax|cqmin|cqmax|cqw|cqh|cqi|cqb|%)?)'
            . '|([-+*\/()])/i';

        if (!preg_match_all($pattern, $expr, $m, PREG_SET_ORDER)) {
            return null;
        }

        foreach ($m as $tok) {
            // A comparison function nested inside calc() resolves on its own.
            if (($tok[1] ?? '') !== '') {
                $nested = $this->evalMathFunction(
                    strtolower($tok[1]),
                    $tok[2],
                    $fontSize,
                    $rootSize,
                    $basis,
                );

                if ($nested === null) {
                    return null;
                }

                $tokens[] = $nested;
                continue;
            }

            if (($tok[4] ?? '') !== '') {
                $tokens[] = $tok[4];
                continue;
            }

            $raw = $tok[3];

            if (preg_match('/^-?[\d.]+$/', $raw)) {
                $tokens[] = (float) $raw;   // bare number: a multiplier
                continue;
            }

            $v = $this->length($raw, $fontSize, $rootSize, $basis);

            if ($v === null) {
                return null;
            }

            $tokens[] = $v;
        }

        $pos   = 0;
        $value = $this->parseSum($tokens, $pos);

        return $pos === count($tokens) ? $value : null;
    }

    /** @param array<int,float|string> $t */
    private function parseSum(array $t, int &$pos): ?float
    {
        $left = $this->parseProduct($t, $pos);

        if ($left === null) {
            return null;
        }

        while ($pos < count($t) && ($t[$pos] === '+' || $t[$pos] === '-')) {
            $op    = $t[$pos++];
            $right = $this->parseProduct($t, $pos);

            if ($right === null) {
                return null;
            }

            $left = $op === '+' ? $left + $right : $left - $right;
        }

        return $left;
    }

    /** @param array<int,float|string> $t */
    private function parseProduct(array $t, int &$pos): ?float
    {
        $left = $this->parseAtom($t, $pos);

        if ($left === null) {
            return null;
        }

        while ($pos < count($t) && ($t[$pos] === '*' || $t[$pos] === '/')) {
            $op    = $t[$pos++];
            $right = $this->parseAtom($t, $pos);

            if ($right === null) {
                return null;
            }

            if ($op === '/' && abs($right) < 1e-12) {
                return null;
            }

            $left = $op === '*' ? $left * $right : $left / $right;
        }

        return $left;
    }

    /** @param array<int,float|string> $t */
    private function parseAtom(array $t, int &$pos): ?float
    {
        if ($pos >= count($t)) {
            return null;
        }

        if ($t[$pos] === '(') {
            $pos++;
            $v = $this->parseSum($t, $pos);

            if ($pos < count($t) && $t[$pos] === ')') {
                $pos++;
            }

            return $v;
        }

        if ($t[$pos] === '-') {
            $pos++;
            $v = $this->parseAtom($t, $pos);

            return $v === null ? null : -$v;
        }

        if (is_float($t[$pos])) {
            return $t[$pos++];
        }

        return null;
    }

    /**
     * Implementation limit on a resolved length, in points, roughly 2,700 A4
     * pages. Browsers clamp too; without a ceiling a single absurd declaration
     * turns into a document nobody can paginate. Configurable via Limits
     * because it is a security control on untrusted input.
     */
    private function clampLength(?float $v): ?float
    {
        if ($v === null || !is_finite($v)) {
            return null;
        }

        return max(-$this->limits->maxLength, min($this->limits->maxLength, $v));
    }

    /** CSS px are 1/96in; PDF points are 1/72in. */
    public function length(string $value, float $fontSize, float $rootSize, ?float $percentBasis = null): ?float
    {
        $value = trim($value);

        if ($value === '' || $value === 'auto' || $value === 'none') {
            return null;
        }

        if (preg_match('/^(calc|min|max|clamp)\((.*)\)$/is', $value, $m)) {
            return $this->clampLength(
                $this->evalMathFunction(strtolower($m[1]), $m[2], $fontSize, $rootSize, $percentBasis),
            );
        }

        if (preg_match(
            '/^(-?[\d.]+(?:[eE][-+]?\d+)?)(px|pt|em|rem|ex|ch|in|cm|mm|q|pc|vw|vh|vmin|vmax|cqmin|cqmax|cqw|cqh|cqi|cqb|%)?$/i',
            $value,
            $m,
        )) {
            $n = (float) $m[1];

            return $this->clampLength(
                match (strtolower($m[2] ?? '')) {
                    'pt'    => $n,
                    'em'    => $n * $fontSize,
                    'rem'   => $n * $rootSize,
                    'ch'    => $n * $this->chUnit($fontSize),
                    'ex'    => $n * $this->exUnit($fontSize),
                    'in'    => $n * 72.0,
                    'cm'    => $n * 28.3465,
                    'mm'    => $n * 2.83465,
                    'q'     => $n * 0.708661,       // a quarter of a millimetre
                    'pc'    => $n * 12.0,           // one pica is 12 points
                    'vw'    => $n / 100.0 * $this->viewportWidth,
                    'vh'    => $n / 100.0 * $this->viewportHeight,
                    'vmin'  => $n / 100.0 * min($this->viewportWidth, $this->viewportHeight),
                    'vmax'  => $n / 100.0 * max($this->viewportWidth, $this->viewportHeight),
                    // CSS Containment 3 section 6. `cqw` and `cqi` are the same
                    // axis and so are `cqh` and `cqb`, because this engine lays
                    // out horizontal-tb only.
                    'cqw', 'cqi' => $n / 100.0 * $this->containerAxis(true),
                    'cqh', 'cqb' => $n / 100.0 * $this->containerAxis(false),
                    'cqmin' => $n / 100.0 * min($this->containerAxis(true), $this->containerAxis(false)),
                    'cqmax' => $n / 100.0 * max($this->containerAxis(true), $this->containerAxis(false)),
                    '%'     => $percentBasis === null ? null : $n / 100.0 * $percentBasis,
                    default => $n * 0.75, // px
                },
            );
        }

        return null;
    }

    /**
     * `calc()` plus the CSS Values 4 comparison functions. `min()` and `max()`
     * take any number of comma-separated lengths; `clamp(a, b, c)` is
     * `max(a, min(b, c))`.
     */
    private function evalMathFunction(
        string $function,
        string $body,
        float $fontSize,
        float $rootSize,
        ?float $percentBasis,
    ): ?float {
        if ($function === 'calc') {
            return $this->evalCalc($body, $fontSize, $rootSize, $percentBasis);
        }

        $values = [];

        foreach ($this->splitOnTopLevelCommas($body) as $argument) {
            $resolved = $this->length($argument, $fontSize, $rootSize, $percentBasis)
                ?? $this->evalCalc($argument, $fontSize, $rootSize, $percentBasis);

            if ($resolved === null) {
                return null;
            }

            $values[] = $resolved;
        }

        if ($values === []) {
            return null;
        }

        if ($function === 'clamp') {
            return count($values) === 3
                ? max($values[0], min($values[1], $values[2]))
                : null;
        }

        return $function === 'min' ? min($values) : max($values);
    }

    /** The absolute `font-size` keywords, in points. */
    private const array FONT_SIZE_KEYWORDS = [
        'xx-small' => 6.75,
        'x-small'  => 7.5,
        'small'    => 9.75,
        'medium'   => 12.0,
        'large'    => 13.5,
        'x-large'  => 18.0,
        'xx-large' => 24.0,
    ];

    /**
     * The same keywords for a monospace family, which is a second table rather
     * than a scaling of the first.
     *
     * Read straight out of Chrome on `docs/harness/probes/E14-monospace-size.html`,
     * where the two tables run 9 / 10 / 13 / 16 / 18 / 24 / 32 px proportional
     * against 9 / 10 / 12 / 13 / 16 / 20 / 26 monospace: the ratio between them
     * is 1.000 at `xx-small` and 0.813 at `xx-large`, so no single factor
     * reproduces it. The table above already agrees with Chrome's proportional
     * column keyword for keyword, so this is its fixed column in the same unit.
     */
    private const array FONT_SIZE_KEYWORDS_MONOSPACE = [
        'xx-small' => 6.75,
        'x-small'  => 7.5,
        'small'    => 9.0,
        'medium'   => 9.75,
        'large'    => 12.0,
        'x-large'  => 15.0,
        'xx-large' => 19.5,
    ];

    /** The CSS-wide keywords, which are valid on every property. */
    private const array CSS_WIDE = [
        'inherit' => 'inherit',
        'initial' => 'initial',
        'unset'   => 'unset',
    ];

    /**
     * Where CSS's initial value and the INITIAL table above are different
     * things, because that table holds this engine's own default rather than
     * the specification's.
     *
     * `font-size` is the one that shows: the table says `12px`, which is what
     * an unstyled document is set in here and what the UA sheet gives `body`,
     * while CSS says the initial value is `medium` and this engine already
     * resolves `medium` as 12pt. Chrome prints `font-size: initial` at 16px,
     * so the keyword has to mean `medium` even where the default does not.
     *
     * `display` is the other one: the INITIAL table says `block`, which is what
     * a box with no declaration on it gets here, and CSS's initial value is
     * `inline`. Only a declaration that spells the keyword out reads this
     * table, so the two answers do not collide.
     */
    private const array CSS_INITIAL = [
        'display'   => 'inline',
        'font-size' => 'medium',
    ];

    /**
     * Whether a value is usable for this property once `var()` has been
     * substituted into it.
     *
     * CSS Variables §3.2 calls a declaration that only becomes invalid after
     * substitution "invalid at computed-value time", and it does *not* fall
     * back to the declaration that lost to it: the cascade has already run by
     * then, so the property behaves as `unset` instead. Chrome gives
     * `line-height: var(--x)` with `--x: -1.5` the **inherited** 24px, where
     * this engine gave zero, and `color: var(--x)` with a nonsense value the
     * inherited color, where this engine gave its default gray.
     *
     * Only properties whose grammar this resolver can decide completely are
     * checked, and everything else is taken as valid: a wrong "no" here throws
     * away a working declaration, which is worse than the defect.
     *
     * `display` is decided by `CssParser::isValidDisplay()`, which is the same
     * grammar the parser drops a literal invalid declaration with.
     */
    private function usableValue(string $prop, string $value): bool
    {
        $v = strtolower(trim($value));

        if ($v === '') {
            return false;
        }

        if (isset(self::CSS_WIDE[$v])) {
            return true;
        }

        $nonNegativeLength = function (string $candidate): bool {
            $resolved = $this->length($candidate, 12.0, $this->rootFontSize, 100.0);

            return $resolved !== null && $resolved >= 0.0;
        };

        return match ($prop) {
            'line-height' => $v === 'normal'
                || (is_numeric($v) ? (float) $v >= 0.0 : $nonNegativeLength($v)),
            'font-size' => isset(self::FONT_SIZE_KEYWORDS[$v])
                || $v === 'smaller'
                || $v === 'larger'
                || $nonNegativeLength($v),
            'color', 'background-color' => $v === 'transparent'
                || $v === 'currentcolor'
                || $this->rgba($v, [0.0, 0.0, 0.0], true) !== null,
            'display' => CssParser::isValidDisplay($v),
            default => true,
        };
    }

    private function fontSize(string $value, float $parentSize, bool $monospace = false): float
    {
        $clamp = fn(float $v): float => is_finite($v) ? max(0.0, min($this->limits->maxFontSize, $v)) : 12.0;

        $named = $monospace ? self::FONT_SIZE_KEYWORDS_MONOSPACE : self::FONT_SIZE_KEYWORDS;

        $v = strtolower(trim($value));

        if (isset($named[$v])) {
            return $named[$v];
        }

        // The factor is a division rather than a multiplication by 0.833
        // because that is what Chrome computes and the two do not agree at
        // the size a `<sup>` is written in: `smaller` inside a 12px paragraph
        // is 10px there, and 9.996 the other way.
        if ($v === 'smaller') {
            return $clamp($parentSize / 1.2);
        }

        if ($v === 'larger') {
            return $clamp($parentSize * 1.2);
        }

        if (str_ends_with($v, '%')) {
            return $clamp((float) rtrim($v, '%') / 100.0 * $parentSize);
        }

        if (preg_match('/^([\d.]+)(em|rem)$/', $v, $m)) {
            return $clamp((float) $m[1] * ($m[2] === 'em' ? $parentSize : $this->rootFontSize));
        }

        return $clamp($this->length($v, $parentSize, $this->rootFontSize) ?? 12.0);
    }

    /**
     * Parse a transform list into primitive ops. Angles are degrees;
     * translate lengths are already in points.
     *
     * @return array<int,array{0:string,1:float,2:float}>
     */
    public function transform(string $value, float $fontSize, float $rootSize): array
    {
        $value = trim($value);

        if ($value === '' || strtolower($value) === 'none') {
            return [];
        }

        $ops = [];

        if (!preg_match_all('/([a-zA-Z]+)\s*\(([^)]*)\)/', $value, $m, PREG_SET_ORDER)) {
            return [];
        }

        foreach ($m as $match) {
            $fn   = strtolower($match[1]);
            // Dropping the empty strings the split leaves needs a predicate:
            // a bare `array_filter` drops the string "0" with them, because
            // "0" is falsy, and every argument after it moves down one place.
            // `translate(0, 12px)` became `translate(12px)`, a sideways move
            // where CSS asks for a downward one, and `matrix(1, 0, 0, 1, 40, 0)`
            // lost four of its six values and became a skew.
            $args = array_values(array_filter(
                array_map('trim', preg_split('/[\s,]+/', $match[2]) ?: []),
                static fn (string $arg): bool => $arg !== '',
            ));
            $num  = function (int $i, float $default = 0.0) use ($args, $fontSize, $rootSize): float {
                if (!isset($args[$i])) {
                    return $default;
                }

                $a = $args[$i];

                if (preg_match('/^-?[\d.]+$/', $a)) {
                    return (float) $a;
                }

                return $this->length($a, $fontSize, $rootSize) ?? (float) $a;
            };

            // A matrix writes its two translation components unitless and
            // means CSS pixels, where `$num` hands a bare number back raw
            // because `a` to `d` are ratios.
            $shift = function (int $i) use ($args, $fontSize, $rootSize): float {
                return isset($args[$i])
                    ? $this->length($args[$i], $fontSize, $rootSize) ?? 0.0
                    : 0.0;
            };

            switch ($fn) {
                case 'translate':
                    $ops[] = ['translate', $num(0), $num(1)];
                    break;
                case 'translatex':
                    $ops[] = ['translate', $num(0), 0.0];
                    break;
                case 'translatey':
                    $ops[] = ['translate', 0.0, $num(0)];
                    break;
                case 'scale':
                    $ops[] = ['scale', $num(0, 1.0), $num(1, $num(0, 1.0))];
                    break;
                case 'scalex':
                    $ops[] = ['scale', $num(0, 1.0), 1.0];
                    break;
                case 'scaley':
                    $ops[] = ['scale', 1.0, $num(0, 1.0)];
                    break;
                case 'rotate':
                    $ops[] = ['rotate', $this->angle($args[0] ?? '0'), 0.0];
                    break;
                case 'skewx':
                    $ops[] = ['skew', $this->angle($args[0] ?? '0'), 0.0];
                    break;
                case 'skewy':
                    $ops[] = ['skew', 0.0, $this->angle($args[0] ?? '0')];
                    break;
                case 'skew':
                    $ops[] = ['skew', $this->angle($args[0] ?? '0'), $this->angle($args[1] ?? '0')];
                    break;
                case 'matrix':
                    $ops[] = ['matrix', 0.0, 0.0, [
                        $num(0),
                        $num(1),
                        $num(2),
                        $num(3),
                        $shift(4),
                        $shift(5),
                    ]];
                    break;
            }
        }

        return $ops;
    }

    private function angle(string $v): float
    {
        $v = strtolower(trim($v));

        if (str_ends_with($v, 'rad')) {
            return (float) $v * 180.0 / M_PI;
        }

        if (str_ends_with($v, 'turn')) {
            return (float) $v * 360.0;
        }

        if (str_ends_with($v, 'grad')) {
            return (float) $v * 0.9;
        }

        return (float) $v; // deg
    }

    /**
     * Parse a grid track list into min/max sizing functions.
     *
     * Handles lengths, percentages, `fr`, `auto`, `min-content`,
     * `max-content`, `minmax()` and `repeat()`. `auto-fill` and `auto-fit`
     * need the container size, so they resolve to a single repetition.
     *
     * @return array<int,array{minType:string,minValue:float,maxType:string,maxValue:float}>
     */
    public function trackList(string $value, float $fontSize, float $rootSize, ?array &$names = null): array
    {
        $value = trim($value);

        if ($value === '' || strtolower($value) === 'none') {
            return [];
        }

        $tracks = [];

        foreach ($this->splitTopLevel($value, $names) as $token) {
            if (preg_match('/^repeat\(\s*([^,]+)\s*,\s*(.+)\)$/is', $token, $m)) {
                $count = trim($m[1]);
                $times = ctype_digit($count) ? max(0, min(1000, (int) $count)) : 1;
                $inner = $this->trackList($m[2], $fontSize, $rootSize);

                for ($i = 0; $i < $times; $i++) {
                    foreach ($inner as $t) {
                        $tracks[] = $t;
                    }
                }

                continue;
            }

            if (preg_match('/^minmax\(\s*([^,]+)\s*,\s*(.+)\)$/is', $token, $m)) {
                $min      = $this->trackFunction(trim($m[1]), $fontSize, $rootSize, true);
                $max      = $this->trackFunction(trim($m[2]), $fontSize, $rootSize, false);
                $tracks[] = [
                    'minType'  => $min[0],
                    'minValue' => $min[1],
                    'maxType'  => $max[0],
                    'maxValue' => $max[1],
                ];

                continue;
            }

            $tracks[] = $this->singleTrack($token, $fontSize, $rootSize);
        }

        return $tracks;
    }

    /** @return array{minType:string,minValue:float,maxType:string,maxValue:float} */
    public function singleTrack(string $token, float $fontSize, float $rootSize): array
    {
        $token = strtolower(trim($token));

        if (str_ends_with($token, 'fr')) {
            $weight = (float) substr($token, 0, -2);

            return [
                'minType'  => 'auto',
                'minValue' => 0.0,
                'maxType'  => 'fr',
                'maxValue' => max(0.0, $weight),
            ];
        }

        // `auto` sizes exactly as `max-content` does and is the only max
        // sizing function CSS Grid §12.8 will stretch, so it is kept apart
        // from the keyword it otherwise behaves like. Defect GO.
        if ($token === 'auto') {
            return [
                'minType'  => 'auto',
                'minValue' => 0.0,
                'maxType'  => 'auto',
                'maxValue' => 0.0,
            ];
        }

        if ($token === 'min-content' || $token === 'max-content') {
            return [
                'minType'  => $token,
                'minValue' => 0.0,
                'maxType'  => $token,
                'maxValue' => 0.0,
            ];
        }

        if (str_ends_with($token, '%')) {
            $pct = (float) rtrim($token, '%');

            return [
                'minType'  => 'percent',
                'minValue' => $pct,
                'maxType'  => 'percent',
                'maxValue' => $pct,
            ];
        }

        $len = $this->length($token, $fontSize, $rootSize) ?? 0.0;
        $len = max(0.0, $len);

        return [
            'minType'  => 'fixed',
            'minValue' => $len,
            'maxType'  => 'fixed',
            'maxValue' => $len,
        ];
    }

    /**
     * Parse `grid-template-areas` (rows of space-separated area names, one
     * quoted string per row) into rectangles. A `.` is an empty cell.
     *
     * @return array<string,array{0:int,1:int,2:int,3:int}> name => row, col, rowSpan, colSpan
     */
    public function templateAreas(string $value): array
    {
        $value = trim($value);

        if ($value === '' || strtolower($value) === 'none') {
            return [];
        }

        if (!preg_match_all('/"([^"]*)"|\'([^\']*)\'/', $value, $m, PREG_SET_ORDER)) {
            return [];
        }

        $grid = [];

        foreach ($m as $row) {
            $cells  = preg_split('/\s+/', trim(($row[1] ?? '') !== '' ? $row[1] : ($row[2] ?? ''))) ?: [];
            $grid[] = array_values(array_filter($cells, static fn(string $c): bool => $c !== ''));
        }

        $areas = [];

        foreach ($grid as $r => $cells) {
            foreach ($cells as $c => $name) {
                if ($name === '.' || $name === '') {
                    continue;
                }

                if (!isset($areas[$name])) {
                    $areas[$name] = [$r, $c, $r, $c];
                    continue;
                }

                $areas[$name][0] = min($areas[$name][0], $r);
                $areas[$name][1] = min($areas[$name][1], $c);
                $areas[$name][2] = max($areas[$name][2], $r);
                $areas[$name][3] = max($areas[$name][3], $c);
            }
        }

        // Store as row, col, rowSpan, colSpan
        foreach ($areas as $name => [$r0, $c0, $r1, $c1]) {
            $areas[$name] = [$r0, $c0, $r1 - $r0 + 1, $c1 - $c0 + 1];
        }

        return $areas;
    }

    /** Does a track list need the container size before it can be expanded? */
    public static function needsContainerSize(string $value): bool
    {
        return (bool) preg_match('/\bauto-(?:fill|fit)\b/i', $value);
    }

    /**
     * Expand `repeat(auto-fill, ...)` now that the container width is known.
     * The count is how many whole repetitions fit alongside the fixed tracks.
     */
    public function expandAutoRepeat(
        string $value,
        float $available,
        float $gap,
        float $fontSize,
        float $rootSize,
    ): string {
        return (string) preg_replace_callback(
            '/repeat\(\s*auto-(fill|fit)\s*,\s*(.+?)\s*\)/is',
            function (array $m) use ($available, $gap, $fontSize, $rootSize): string {
                $inner  = $m[2];
                $tracks = $this->trackList($inner, $fontSize, $rootSize);
                $unit   = 0.0;

                foreach ($tracks as $t) {
                    $unit += match ($t['minType']) {
                        'fixed'   => $t['minValue'],
                        'percent' => $t['minValue'] / 100.0 * $available,
                        default   => 0.0,
                    };
                }

                $unit += max(0, count($tracks) - 1) * $gap;

                if ($unit <= 0.01) {
                    return $inner; // nothing measurable to repeat
                }

                $count = (int) max(1, min(1000, floor(($available + $gap) / ($unit + $gap))));

                return trim(str_repeat($inner . ' ', $count));
            },
            $value,
        ) ?: $value;
    }

    /** @return array{0:string,1:float} */
    private function trackFunction(string $token, float $fontSize, float $rootSize, bool $isMin): array
    {
        $t = $this->singleTrack($token, $fontSize, $rootSize);

        return $isMin
            ? [$t['minType'], $t['minValue']]
            : [$t['maxType'], $t['maxValue']];
    }

    /**
     * Split a value on whitespace, keeping bracketed functions intact.
     *
     * `[line-names]` are recorded against the track index they precede, so
     * placement can resolve `grid-column: sidebar-start` later.
     *
     * @param array<string,int>|null $names filled with name => line index
     */
    /**
     * Split on `/` that is not inside brackets, which is what separates the
     * three segments of a `border-image` shorthand while leaving a
     * `linear-gradient(... 45deg ...)` alone.
     *
     * @return string[]
     */
    private static function splitOnSlashes(string $value): array
    {
        $out     = [];
        $depth   = 0;
        $current = '';

        for ($i = 0, $len = strlen($value); $i < $len; $i++) {
            $c = $value[$i];

            if ($c === '(') {
                $depth++;
            } elseif ($c === ')') {
                $depth = max(0, $depth - 1);
            }

            if ($depth === 0 && $c === '/') {
                $out[]   = trim($current);
                $current = '';

                continue;
            }

            $current .= $c;
        }

        $out[] = trim($current);

        return $out;
    }

    private function splitTopLevel(string $value, ?array &$names = null): array
    {
        $out = $this->topLevelTokens($value);

        $tracks = [];

        foreach ($out as $token) {
            if (str_starts_with($token, '[')) {
                if ($names !== null) {
                    foreach (preg_split('/\s+/', trim($token, '[] ')) ?: [] as $name) {
                        if ($name !== '') {
                            $names[$name] = count($tracks);
                        }
                    }
                }

                continue;
            }
            $tracks[] = $token;
        }

        return $tracks;
    }

    /**
     * Split on whitespace that is not inside brackets, so `rgb(0, 0, 0)` and
     * `calc(1px + 2px)` survive as one token.
     *
     * @return string[]
     */
    private function topLevelTokens(string $value): array
    {
        $out     = [];
        $depth   = 0;
        $current = '';
        $len     = strlen($value);

        for ($i = 0; $i < $len; $i++) {
            $c = $value[$i];

            if ($c === '(' || $c === '[') {
                $depth++;
            } elseif ($c === ')' || $c === ']') {
                $depth = max(0, $depth - 1);
            }

            if ($depth === 0 && ctype_space($c)) {
                if (trim($current) !== '') {
                    $out[] = trim($current);
                }
                $current = '';

                continue;
            }

            $current .= $c;
        }

        if (trim($current) !== '') {
            $out[] = trim($current);
        }

        return $out;
    }

    /** The CSS Color 4 named colors, verified against what Chrome paints. */
    private const array NAMED_COLORS = [
        'aliceblue'            => '#f0f8ff',
        'antiquewhite'         => '#faebd7',
        'aqua'                 => '#00ffff',
        'aquamarine'           => '#7fffd4',
        'azure'                => '#f0ffff',
        'beige'                => '#f5f5dc',
        'bisque'               => '#ffe4c4',
        'black'                => '#000000',
        'blanchedalmond'       => '#ffebcd',
        'blue'                 => '#0000ff',
        'blueviolet'           => '#8a2be2',
        'brown'                => '#a52a2a',
        'burlywood'            => '#deb887',
        'cadetblue'            => '#5f9ea0',
        'chartreuse'           => '#7fff00',
        'chocolate'            => '#d2691e',
        'coral'                => '#ff7f50',
        'cornflowerblue'       => '#6495ed',
        'cornsilk'             => '#fff8dc',
        'crimson'              => '#dc143c',
        'cyan'                 => '#00ffff',
        'darkblue'             => '#00008b',
        'darkcyan'             => '#008b8b',
        'darkgoldenrod'        => '#b8860b',
        'darkgray'             => '#a9a9a9',
        'darkgreen'            => '#006400',
        'darkgrey'             => '#a9a9a9',
        'darkkhaki'            => '#bdb76b',
        'darkmagenta'          => '#8b008b',
        'darkolivegreen'       => '#556b2f',
        'darkorange'           => '#ff8c00',
        'darkorchid'           => '#9932cc',
        'darkred'              => '#8b0000',
        'darksalmon'           => '#e9967a',
        'darkseagreen'         => '#8fbc8f',
        'darkslateblue'        => '#483d8b',
        'darkslategray'        => '#2f4f4f',
        'darkslategrey'        => '#2f4f4f',
        'darkturquoise'        => '#00ced1',
        'darkviolet'           => '#9400d3',
        'deeppink'             => '#ff1493',
        'deepskyblue'          => '#00bfff',
        'dimgray'              => '#696969',
        'dimgrey'              => '#696969',
        'dodgerblue'           => '#1e90ff',
        'firebrick'            => '#b22222',
        'floralwhite'          => '#fffaf0',
        'forestgreen'          => '#228b22',
        'fuchsia'              => '#ff00ff',
        'gainsboro'            => '#dcdcdc',
        'ghostwhite'           => '#f8f8ff',
        'gold'                 => '#ffd700',
        'goldenrod'            => '#daa520',
        'gray'                 => '#808080',
        'green'                => '#008000',
        'greenyellow'          => '#adff2f',
        'grey'                 => '#808080',
        'honeydew'             => '#f0fff0',
        'hotpink'              => '#ff69b4',
        'indianred'            => '#cd5c5c',
        'indigo'               => '#4b0082',
        'ivory'                => '#fffff0',
        'khaki'                => '#f0e68c',
        'lavender'             => '#e6e6fa',
        'lavenderblush'        => '#fff0f5',
        'lawngreen'            => '#7cfc00',
        'lemonchiffon'         => '#fffacd',
        'lightblue'            => '#add8e6',
        'lightcoral'           => '#f08080',
        'lightcyan'            => '#e0ffff',
        'lightgoldenrodyellow' => '#fafad2',
        'lightgray'            => '#d3d3d3',
        'lightgreen'           => '#90ee90',
        'lightgrey'            => '#d3d3d3',
        'lightpink'            => '#ffb6c1',
        'lightsalmon'          => '#ffa07a',
        'lightseagreen'        => '#20b2aa',
        'lightskyblue'         => '#87cefa',
        'lightslategray'       => '#778899',
        'lightslategrey'       => '#778899',
        'lightsteelblue'       => '#b0c4de',
        'lightyellow'          => '#ffffe0',
        'lime'                 => '#00ff00',
        'limegreen'            => '#32cd32',
        'linen'                => '#faf0e6',
        'magenta'              => '#ff00ff',
        'maroon'               => '#800000',
        'mediumaquamarine'     => '#66cdaa',
        'mediumblue'           => '#0000cd',
        'mediumorchid'         => '#ba55d3',
        'mediumpurple'         => '#9370db',
        'mediumseagreen'       => '#3cb371',
        'mediumslateblue'      => '#7b68ee',
        'mediumspringgreen'    => '#00fa9a',
        'mediumturquoise'      => '#48d1cc',
        'mediumvioletred'      => '#c71585',
        'midnightblue'         => '#191970',
        'mintcream'            => '#f5fffa',
        'mistyrose'            => '#ffe4e1',
        'moccasin'             => '#ffe4b5',
        'navajowhite'          => '#ffdead',
        'navy'                 => '#000080',
        'oldlace'              => '#fdf5e6',
        'olive'                => '#808000',
        'olivedrab'            => '#6b8e23',
        'orange'               => '#ffa500',
        'orangered'            => '#ff4500',
        'orchid'               => '#da70d6',
        'palegoldenrod'        => '#eee8aa',
        'palegreen'            => '#98fb98',
        'paleturquoise'        => '#afeeee',
        'palevioletred'        => '#db7093',
        'papayawhip'           => '#ffefd5',
        'peachpuff'            => '#ffdab9',
        'peru'                 => '#cd853f',
        'pink'                 => '#ffc0cb',
        'plum'                 => '#dda0dd',
        'powderblue'           => '#b0e0e6',
        'purple'               => '#800080',
        'rebeccapurple'        => '#663399',
        'red'                  => '#ff0000',
        'rosybrown'            => '#bc8f8f',
        'royalblue'            => '#4169e1',
        'saddlebrown'          => '#8b4513',
        'salmon'               => '#fa8072',
        'sandybrown'           => '#f4a460',
        'seagreen'             => '#2e8b57',
        'seashell'             => '#fff5ee',
        'sienna'               => '#a0522d',
        'silver'               => '#c0c0c0',
        'skyblue'              => '#87ceeb',
        'slateblue'            => '#6a5acd',
        'slategray'            => '#708090',
        'slategrey'            => '#708090',
        'snow'                 => '#fffafa',
        'springgreen'          => '#00ff7f',
        'steelblue'            => '#4682b4',
        'tan'                  => '#d2b48c',
        'teal'                 => '#008080',
        'thistle'              => '#d8bfd8',
        'tomato'               => '#ff6347',
        'turquoise'            => '#40e0d0',
        'violet'               => '#ee82ee',
        'wheat'                => '#f5deb3',
        'white'                => '#ffffff',
        'whitesmoke'           => '#f5f5f5',
        'yellow'               => '#ffff00',
        'yellowgreen'          => '#9acd32',
    ];

    /**
     * Parse `text-shadow: none | [<x> <y> <blur>? <color>?]#`.
     *
     * The list is returned in the order it was written, which is front to
     * back: CSS paints the first shadow on top of the second, and every one of
     * them under the text itself.
     *
     * `box-shadow`'s `oneShadow()` is deliberately NOT reused. It accepts a
     * spread length and the `inset` keyword, neither of which `text-shadow`
     * has, so it would take `1pt 1pt 2pt 3pt` as a shadow where CSS says the
     * declaration is invalid, and it returns a color with an alpha component
     * where the text painter takes three.
     *
     * @return array<int, array{x:float,y:float,blur:float,color:array{0:float,1:float,2:float}}>
     */
    public function textShadow(string $value, float $fontSize, float $rootSize): array
    {
        $value = trim($value);

        if ($value === '' || strtolower($value) === 'none') {
            return [];
        }

        $shadows = [];

        // The split has to ignore the commas inside a color function or
        // `rgb(0, 128, 0)` is cut down to `rgb(0` and stops being a color.
        foreach ($this->splitList($value) as $layer) {
            $shadow = $this->oneTextShadow($layer, $fontSize, $rootSize);

            if ($shadow !== null) {
                $shadows[] = $shadow;
            }

            if (count($shadows) === self::MAX_SHADOWS) {
                break;
            }
        }

        return $shadows;
    }

    /**
     * @return array{x:float,y:float,blur:float,color:array{0:float,1:float,2:float}}|null
     */
    private function oneTextShadow(string $layer, float $fontSize, float $rootSize): ?array
    {
        $first = trim($layer);

        $colour = null;
        $first  = preg_replace_callback(
            '/(#[0-9a-fA-F]{3,8}|rgba?\([^)]*\)|[a-zA-Z]+)/',
            function (array $m) use (&$colour): string {
                $c = $this->color($m[1]);
                if ($c !== null && $colour === null) {
                    $colour = $c;

                    return '';
                }

                return $m[1];
            },
            $first,
        ) ?? $first;

        $parts = array_values(array_filter(preg_split('/\s+/', trim($first)) ?: [], 'strlen'));

        if (count($parts) < 2) {
            return null;
        }

        return [
            'x'     => $this->length($parts[0], $fontSize, $rootSize) ?? 0.0,
            'y'     => $this->length($parts[1], $fontSize, $rootSize) ?? 0.0,
            'blur'  => max(0.0, $this->length($parts[2] ?? '0', $fontSize, $rootSize) ?? 0.0),
            'color' => $colour ?? [0.0, 0.0, 0.0],
        ];
    }

    /**
     * Parse `aspect-ratio: auto | <ratio> | auto <ratio>` into width over
     * height.
     *
     * `auto` on its own is no ratio at all. `auto` beside one keeps the
     * ratio for anything without proportions of its own, which is every box
     * here except a replaced element, so the pair reads the same as the bare
     * ratio and the caller decides what a replaced element does with it.
     */
    public function aspectRatio(string $value): ?float
    {
        $value = strtolower(trim($value));

        if ($value === '' || $value === 'auto') {
            return null;
        }

        $parts = array_values(array_filter(
            preg_split('/\s+/', str_replace('auto', ' ', $value)) ?: [],
            'strlen',
        ));

        $ratio = implode('', $parts);

        if (!preg_match('#^([\d.]+)(?:/([\d.]+))?$#', $ratio, $m)) {
            return null;
        }

        $width  = (float) $m[1];
        $height = isset($m[2]) ? (float) $m[2] : 1.0;

        return $width > 0.0 && $height > 0.0 ? $width / $height : null;
    }

    /**
     * How many shadows one box may carry. Each is a separate paint, and a
     * blurred one is a separate mask image, so the list length is a cost the
     * document controls.
     */
    private const int MAX_SHADOWS = 8;

    /** How many color stops one gradient may carry, for the same reason. */
    private const int MAX_GRADIENT_STOPS = 64;

    /**
     * Parse `box-shadow: none | [inset? <x> <y> <blur>? <spread>? <color>?]#`.
     *
     * The list is returned in the order it was written, which is front to
     * back: CSS paints the first shadow on top of the second.
     *
     * @param  array{0:float,1:float,2:float}|null $currentColor
     * @return array<int, array{x:float,y:float,blur:float,spread:float,inset:bool,color:array{0:float,1:float,2:float,3:float}}>
     */
    public function boxShadow(string $value, float $fontSize, float $rootSize, ?array $currentColor = null): array
    {
        $value = trim($value);

        if ($value === '' || strtolower($value) === 'none') {
            return [];
        }

        $shadows = [];

        foreach ($this->splitList($value) as $layer) {
            $shadow = $this->oneShadow($layer, $fontSize, $rootSize, $currentColor);

            if ($shadow !== null) {
                $shadows[] = $shadow;
            }

            if (count($shadows) === self::MAX_SHADOWS) {
                break;
            }
        }

        return $shadows;
    }

    /**
     * @param  array{0:float,1:float,2:float}|null $currentColor
     * @return array{x:float,y:float,blur:float,spread:float,inset:bool,color:array{0:float,1:float,2:float,3:float}}|null
     */
    private function oneShadow(string $layer, float $fontSize, float $rootSize, ?array $currentColor): ?array
    {
        $inset = false;
        $layer = preg_replace_callback(
            '/\binset\b/i',
            static function () use (&$inset): string {
                $inset = true;

                return '';
            },
            $layer,
        ) ?? $layer;

        $colour = null;
        $layer  = preg_replace_callback(
            '/(#[0-9a-fA-F]{3,8}|(?:rgba?|hsla?)\([^)]*\)|[a-zA-Z][a-zA-Z0-9-]*)/',
            function (array $m) use (&$colour, $currentColor): string {
                $parsed = $this->rgba($m[1], $currentColor === null ? null : [...$currentColor, 1.0]);

                if ($parsed !== null && $colour === null) {
                    $colour = $parsed;

                    return '';
                }

                return $m[1];
            },
            $layer,
        ) ?? $layer;

        $parts = array_values(array_filter(preg_split('/\s+/', trim($layer)) ?: [], 'strlen'));

        // A shadow with fewer than two lengths is invalid, and an invalid
        // layer takes the whole declaration with it in CSS. Dropping only the
        // layer is closer to what an author meant and keeps the rest painting.
        if (count($parts) < 2) {
            return null;
        }

        foreach ($parts as $part) {
            if ($this->length($part, $fontSize, $rootSize) === null) {
                return null;
            }
        }

        return [
            'x'      => $this->length($parts[0], $fontSize, $rootSize) ?? 0.0,
            'y'      => $this->length($parts[1], $fontSize, $rootSize) ?? 0.0,
            'blur'   => max(0.0, $this->length($parts[2] ?? '0', $fontSize, $rootSize) ?? 0.0),
            'spread' => $this->length($parts[3] ?? '0', $fontSize, $rootSize) ?? 0.0,
            'inset'  => $inset,
            'color'  => $colour ?? [...($currentColor ?? [0.0, 0.0, 0.0]), 1.0],
        ];
    }

    /**
     * @param  array{0:float,1:float,2:float}|null  $currentColor  what `currentColor` resolves to
     * @return array{0:float,1:float,2:float}|null RGB in 0..1, or null for transparent
     */
    public function color(string $value, ?array $currentColor = null): ?array
    {
        $rgba = $this->rgba($value, $currentColor === null ? null : [...$currentColor, 1.0]);

        return $rgba === null ? null : [$rgba[0], $rgba[1], $rgba[2]];
    }

    /**
     * The same parse as color(), keeping the alpha channel. Anything painted
     * with alpha below 1 needs it: dropping it turns `rgba(0, 0, 0, .05)`,
     * which every stylesheet uses for subtle fills, into solid black.
     *
     * @param  array{0:float,1:float,2:float,3:float}|null  $currentColor
     * @return array{0:float,1:float,2:float,3:float}|null RGB plus alpha in 0..1, null for transparent
     */
    /**
     * @param bool $keepTransparent whether a fully transparent color is a
     *                              value rather than nothing. A fill has
     *                              nothing to paint; a gradient stop at
     *                              `transparent` is a real stop that the
     *                              colours around it fade into.
     */
    /** Whether a bare identifier is one of CSS Color 4's 148 named colors. */
    public static function isNamedColor(string $name): bool
    {
        return isset(self::NAMED_COLORS[strtolower(trim($name))]);
    }

    public function rgba(string $value, ?array $currentColor = null, bool $keepTransparent = false): ?array
    {
        $v = strtolower(trim($value));

        if ($v === '' || $v === 'transparent' || $v === 'none') {
            return $keepTransparent && $v === 'transparent' ? [0.0, 0.0, 0.0, 0.0] : null;
        }

        if ($v === 'currentcolor') {
            return $currentColor;
        }

        $v = self::NAMED_COLORS[$v] ?? $v;

        if ($v[0] === '#') {
            return $this->hexColor(substr($v, 1), $keepTransparent);
        }

        if (!preg_match('/^([a-z-]+)\((.*)\)$/s', $v, $m)) {
            return null;
        }

        [$function, $body] = [$m[1], $m[2]];

        if ($function === 'color-mix') {
            return $this->mixColors($body, $currentColor);
        }

        [$components, $alpha] = $this->colorComponents($body);

        if ($alpha !== null && $alpha <= 0.0 && !$keepTransparent) {
            return null;
        }

        $rgb = match ($function) {
            'rgb', 'rgba'   => $this->clampRgb([
                $this->colorChannel($components[0] ?? '0', 255.0),
                $this->colorChannel($components[1] ?? '0', 255.0),
                $this->colorChannel($components[2] ?? '0', 255.0),
            ]),
            'hsl', 'hsla'   => $this->hslToRgb(
                $this->hueDegrees($components[0] ?? '0'),
                $this->colorChannel($components[1] ?? '0', 1.0),
                $this->colorChannel($components[2] ?? '0', 1.0),
            ),
            'hwb'           => $this->hwbToRgb(
                $this->hueDegrees($components[0] ?? '0'),
                $this->colorChannel($components[1] ?? '0', 1.0),
                $this->colorChannel($components[2] ?? '0', 1.0),
            ),
            'oklch'         => $this->oklabToRgb(
                $this->colorChannel($components[0] ?? '0', 1.0),
                $this->colorChannel($components[1] ?? '0', 0.4) * cos(deg2rad($this->hueDegrees($components[2] ?? '0'))),
                $this->colorChannel($components[1] ?? '0', 0.4) * sin(deg2rad($this->hueDegrees($components[2] ?? '0'))),
            ),
            'oklab'         => $this->oklabToRgb(
                $this->colorChannel($components[0] ?? '0', 1.0),
                $this->colorChannel($components[1] ?? '0', 0.4),
                $this->colorChannel($components[2] ?? '0', 0.4),
            ),
            default         => null,
        };

        if ($rgb === null) {
            return null;
        }

        return [$rgb[0], $rgb[1], $rgb[2], max(0.0, min(1.0, $alpha ?? 1.0))];
    }

    /** @return array{0:float,1:float,2:float,3:float}|null */
    private function hexColor(string $digits, bool $keepTransparent = false): ?array
    {
        if (!preg_match('/^[0-9a-f]+$/', $digits)) {
            return null;
        }

        // The short forms duplicate each digit: #abc is #aabbcc.
        if (strlen($digits) === 3 || strlen($digits) === 4) {
            $digits = preg_replace('/(.)/', '$1$1', $digits) ?? $digits;
        }

        if (strlen($digits) !== 6 && strlen($digits) !== 8) {
            return null;
        }

        $alpha = strlen($digits) === 8 ? (float) hexdec(substr($digits, 6, 2)) / 255 : 1.0;

        if ($alpha <= 0.0 && !$keepTransparent) {
            return null;
        }

        return [
            (float) hexdec(substr($digits, 0, 2)) / 255,
            (float) hexdec(substr($digits, 2, 2)) / 255,
            (float) hexdec(substr($digits, 4, 2)) / 255,
            $alpha,
        ];
    }

    /**
     * Splits a functional color body into its components and its alpha,
     * accepting both the legacy comma form and the CSS Color 4 space form
     * with a slash before alpha.
     *
     * @return array{0:string[],1:?float}
     */
    private function colorComponents(string $body): array
    {
        $alpha = null;

        if (str_contains($body, '/')) {
            [$body, $tail] = explode('/', $body, 2);
            $tail          = trim($tail);
            $alpha         = str_ends_with($tail, '%')
                ? (float) rtrim($tail, '%') / 100.0
                : (float) $tail;
        }

        $parts = array_values(array_filter(
            array_map('trim', preg_split('/[\s,]+/', trim($body)) ?: []),
            static fn(string $part): bool => $part !== '',
        ));

        // The legacy comma form carries alpha as a fourth component.
        if ($alpha === null && count($parts) === 4) {
            $fourth = array_pop($parts);
            $alpha  = str_ends_with($fourth, '%')
                ? (float) rtrim($fourth, '%') / 100.0
                : (float) $fourth;
        }

        return [$parts, $alpha];
    }

    /** A component as a fraction of $full, where `none` is zero per CSS Color 4. */
    private function colorChannel(string $component, float $full): float
    {
        if ($component === 'none') {
            return 0.0;
        }

        if (str_ends_with($component, '%')) {
            return (float) rtrim($component, '%') / 100.0 * $full;
        }

        return (float) $component;
    }

    private function hueDegrees(string $component): float
    {
        if ($component === 'none') {
            return 0.0;
        }

        if (preg_match('/^(-?[\d.]+)(deg|grad|rad|turn)?$/', $component, $m)) {
            $n = (float) $m[1];

            return match ($m[2] ?? 'deg') {
                'grad'  => $n * 0.9,
                'rad'   => rad2deg($n),
                'turn'  => $n * 360.0,
                default => $n,
            };
        }

        return 0.0;
    }

    /**
     * @param  array{0:float,1:float,2:float}  $rgb
     * @return array{0:float,1:float,2:float}
     */
    private function clampRgb(array $rgb): array
    {
        return [
            max(0.0, min(1.0, $rgb[0] / 255.0)),
            max(0.0, min(1.0, $rgb[1] / 255.0)),
            max(0.0, min(1.0, $rgb[2] / 255.0)),
        ];
    }

    /** @return array{0:float,1:float,2:float} */
    private function hslToRgb(float $hue, float $saturation, float $lightness): array
    {
        $saturation = max(0.0, min(1.0, $saturation));
        $lightness  = max(0.0, min(1.0, $lightness));

        $chroma = (1.0 - abs(2.0 * $lightness - 1.0)) * $saturation;
        $hue    = fmod(fmod($hue, 360.0) + 360.0, 360.0) / 60.0;
        $second = $chroma * (1.0 - abs(fmod($hue, 2.0) - 1.0));
        $base   = $lightness - $chroma / 2.0;

        [$r, $g, $b] = match ((int) floor($hue)) {
            0       => [$chroma, $second, 0.0],
            1       => [$second, $chroma, 0.0],
            2       => [0.0, $chroma, $second],
            3       => [0.0, $second, $chroma],
            4       => [$second, 0.0, $chroma],
            default => [$chroma, 0.0, $second],
        };

        return [$r + $base, $g + $base, $b + $base];
    }

    /** @return array{0:float,1:float,2:float} */
    private function hwbToRgb(float $hue, float $whiteness, float $blackness): array
    {
        $whiteness = max(0.0, min(1.0, $whiteness));
        $blackness = max(0.0, min(1.0, $blackness));

        if ($whiteness + $blackness >= 1.0) {
            $grey = $whiteness / ($whiteness + $blackness);

            return [$grey, $grey, $grey];
        }

        $base  = $this->hslToRgb($hue, 1.0, 0.5);
        $scale = 1.0 - $whiteness - $blackness;

        return [
            $base[0] * $scale + $whiteness,
            $base[1] * $scale + $whiteness,
            $base[2] * $scale + $whiteness,
        ];
    }

    /**
     * Oklab to sRGB, via the LMS cone response and the linear-sRGB matrix in
     * Björn Ottosson's definition, then the sRGB transfer function.
     *
     * @return array{0:float,1:float,2:float}
     */
    private function oklabToRgb(float $lightness, float $a, float $b): array
    {
        $l = ($lightness + 0.3963377774 * $a + 0.2158037573 * $b) ** 3;
        $m = ($lightness - 0.1055613458 * $a - 0.0638541728 * $b) ** 3;
        $s = ($lightness - 0.0894841775 * $a - 1.2914855480 * $b) ** 3;

        $gamma = static function (float $linear): float {
            $sign   = $linear < 0 ? -1.0 : 1.0;
            $linear = abs($linear);

            $encoded = $linear <= 0.0031308
                ? $linear * 12.92
                : 1.055 * $linear ** (1.0 / 2.4) - 0.055;

            return max(0.0, min(1.0, $sign * $encoded));
        };

        return [
            $gamma(4.0767416621 * $l - 3.3077115913 * $m + 0.2309699292 * $s),
            $gamma(-1.2684380046 * $l + 2.6097574011 * $m - 0.3413193965 * $s),
            $gamma(-0.0041960863 * $l - 0.7034186147 * $m + 1.7076147010 * $s),
        ];
    }

    /**
     * `color-mix(in <space>, <color> <p>?, <color> <p>?)`. Mixing happens in
     * sRGB regardless of the named space, which is wrong for oklab but keeps
     * the endpoints exact, and endpoints are what stylesheets rely on.
     *
     * @param  array{0:float,1:float,2:float,3:float}|null  $currentColor
     * @return array{0:float,1:float,2:float,3:float}|null
     */
    private function mixColors(string $body, ?array $currentColor): ?array
    {
        $parts = $this->splitOnTopLevelCommas($body);

        if (count($parts) < 3) {
            return null;
        }

        array_shift($parts);

        $weights = [];
        $colors  = [];

        foreach (array_slice($parts, 0, 2) as $part) {
            if (preg_match('/\s+(-?[\d.]+)%$/', $part, $m)) {
                $weights[] = (float) $m[1] / 100.0;
                $part      = trim(substr($part, 0, -strlen($m[0])));
            } else {
                $weights[] = null;
            }

            $colors[] = $this->rgba(trim($part), $currentColor) ?? [1.0, 1.0, 1.0, 0.0];
        }

        if ($weights[0] === null && $weights[1] === null) {
            $weights = [0.5, 0.5];
        } elseif ($weights[0] === null) {
            $weights[0] = 1.0 - $weights[1];
        } elseif ($weights[1] === null) {
            $weights[1] = 1.0 - $weights[0];
        }

        $total = $weights[0] + $weights[1];

        if ($total <= 0.0) {
            return null;
        }

        $first = $weights[0] / $total;

        $mixed = [
            $colors[0][0] * $first + $colors[1][0] * (1.0 - $first),
            $colors[0][1] * $first + $colors[1][1] * (1.0 - $first),
            $colors[0][2] * $first + $colors[1][2] * (1.0 - $first),
            $colors[0][3] * $first + $colors[1][3] * (1.0 - $first),
        ];

        return $mixed[3] <= 0.0 ? null : $mixed;
    }

    /** @return string[] */
    private function splitOnTopLevelCommas(string $value): array
    {
        $parts = [];
        $depth = 0;
        $start = 0;

        for ($i = 0, $len = strlen($value); $i < $len; $i++) {
            $char = $value[$i];

            if ($char === '(') {
                $depth++;
            } elseif ($char === ')') {
                $depth--;
            } elseif ($char === ',' && $depth === 0) {
                $parts[] = trim(substr($value, $start, $i - $start));
                $start   = $i + 1;
            }
        }

        $parts[] = trim(substr($value, $start));

        return $parts;
    }
}
