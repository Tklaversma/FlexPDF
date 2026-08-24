<?php

declare(strict_types=1);

namespace FlexPDF\Engine;

use Closure;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;
use FlexPDF\Engine\Support\AssetPath;
use FlexPDF\Engine\Support\Deadline;
use FlexPDF\Engine\Support\Limits;

/**
 * Turns a styled DOM into the layout engine's box tree.
 *
 * The interesting part is inline flattening: `<p>Hello <b>world</b></p>` has
 * to become one text box carrying two runs, because a line box is the only
 * place where mixed styles can share a baseline. Elements whose children mix
 * block and inline content get anonymous boxes around the inline runs, which
 * is what CSS requires.
 */
final class HtmlBuilder
{
    /** CSS Lists §4's own counter, which every list item increments. */
    private const string LIST_ITEM = 'list-item';

    /** One CSS pixel in points, which is the unit everything here is measured in. */
    private const float CSS_PIXEL = 0.75;

    /**
     * The `vertical-align` values that place a box against the line or against
     * the parent's content edge rather than a constant distance from its
     * baseline. None of them is implemented; see `verticalShift()`.
     */
    private const array BOX_RELATIVE_ALIGN = [
        ''            => true,
        'baseline'    => true,
        'top'         => true,
        'bottom'      => true,
        'middle'      => true,
        'text-top'    => true,
        'text-bottom' => true,
    ];

    /**
     * The elements that are an atomic inline whatever `display` they are
     * given, because their content is a picture or a control rather than
     * child boxes. The UA sheet spells this as `display: inline-block`, which
     * an author can overwrite; these cannot stop being atomic.
     */
    private const array ATOMIC_TAGS = [
        'img'      => true,
        'svg'      => true,
        'input'    => true,
        'textarea' => true,
        'select'   => true,
        'button'   => true,
    ];

    /**
     * The broken-image placeholder's own square, in points. Chrome's is 16 CSS
     * pixels and it does not follow the font: an `<img>` in a 24px block is the
     * same 12.000pt mark as one in a 12px block (`OS-broken-minmax.html` `s8`).
     */
    private const float BROKEN_ICON = 12.0;

    /**
     * What a browser reserves beside a control's own content: a `<textarea>`'s
     * scrollbar and a `<select>`'s drop-down arrow. Both are 16 CSS pixels and
     * both are there whether or not anything needs them, which is what the
     * remainder of every Chrome measurement of an empty one is.
     */
    private const float CONTROL_GUTTER = 12.0;

    /** A `cols`, `rows` or `size` a document may write anything into. */
    private const int MAX_CONTROL_COUNT = 1000;

    /** How many `<option>` elements one `<select>` is measured against. */
    private const int MAX_OPTIONS = 1000;

    /**
     * The largest `-webkit-line-clamp` a document can ask for.
     *
     * The number is a count a document writes and it is held as an int, so
     * this keeps `2147483648` from wrapping rather than bounding any loop:
     * the clamp walks the subtree it is on, whatever the count says. A card
     * nobody would call truncated is well under this.
     */
    private const int MAX_CLAMP_LINES = 10000;

    /**
     * The `<input>` types that draw as a text box and are not one.
     *
     * All four are buttons, and a PDF button is nothing but the action it runs.
     * {@see FormFieldType} says why this engine writes none.
     */
    private const array BUTTON_TYPES = ['submit', 'reset', 'button', 'image'];

    private float $rootFontSize = 12.0;

    /**
     * CSS counters, one stack of instances per name with the innermost last.
     *
     * @var array<string,list<array{owner:?DOMNode,pseudo:string,value:int}>>
     */
    private array $counters = [];

    /*
     * The href of the <a> currently being walked, if any. Inline content is
     * flattened into runs that no longer know their ancestors, so the link
     * target is carried down the walk instead of looked up afterwards.
     */
    private ?string $currentHref = null;

    /**
     * Block-level boxes lifted out of an inline subtree by `collectRuns()`, for
     * the `partition()` call that started it to place. A float and an
     * out-of-flow box are block-level whatever `display` says, and a line box is
     * no place for either.
     *
     * @var Node[]
     */
    private array $hoisted = [];

    /**
     * How many inline boxes have been handed an id. Two sibling spans styled
     * identically are two boxes and must not merge into one rect, and value
     * comparison cannot tell them apart, so each gets a number of its own.
     */
    private int $inlineBoxes = 0;

    /**
     * The id of the `position: relative` inline element currently being walked,
     * which is the containing block for any out-of-flow box inside it. Null
     * outside one, and cleared on the way into every block.
     */
    private ?int $inlineContainer = null;

    /**
     * The `::first-line` style a parent handed down, because the line it styles
     * is inside this box rather than in the one that declared the rule.
     *
     * It is already cascaded onto the child, so the box that picks it up uses
     * it exactly as it would use its own. Cleared the moment it is read, so
     * only the one child it was meant for can see it.
     *
     * @var array<string,string>|null
     */
    private ?array $inheritedFirstLine = null;

    /** The same for `::first-letter`, which takes the identical walk. */
    private ?Closure $inheritedFirstLetter = null;

    /**
     * Whether the last `partition()` handed this block's first line to a child.
     *
     * A block that did has no first formatted line of its own, so a run of
     * inline content further down in it is the second line and must not be
     * styled: `<div><p>a</p>b</div>` styles `a` and leaves `b` alone.
     */
    private bool $firstLineDescended = false;

    /**
     * The `::first-letter` cascade, captured when the hook fires.
     *
     * It is here rather than in a by-reference parameter because the hook can
     * fire in a descendant block, and the frame that has to build the drop cap
     * out of it is that descendant's rather than the one that made the hook.
     *
     * @var array<string,string>|null
     */
    private ?array $firstLetterFloat = null;

    /**
     * How many row groups have been handed an id. Row groups are flattened
     * away, so the only thing left of one is the number its rows share, and a
     * counter that never restarts keeps two tables' groups apart as cheaply as
     * it keeps one table's.
     */
    private int $rowGroupSeq = 0;

    private readonly Deadline $deadline;

    /** Boxes built since the budget was last read. See checkBudget(). */
    private int $sinceCheck = 0;

    /**
     * The `<body>` element and the box built from it, for the canvas rule.
     *
     * CSS Backgrounds §2.11.2 needs both the root element's box and the body's,
     * and the body is not reachable by position: it is whichever child of the
     * root the document put it under. Captured on the way past by identity, so
     * an ordinary element pays one `===` and no string comparison.
     */
    private ?DOMElement $bodyElement = null;

    private ?Node $bodyBox = null;

    /**
     * Hands out the serial number a box that holds other boxes carries at the
     * end of its {@see Node::$stackPath}. Document order, so a box always
     * gets a lower number than anything written after it.
     */
    private int $stackSerial = 0;

    public function __construct(
        private readonly StyleResolver $styles = new StyleResolver(),
        ?Deadline $deadline = null,
    ) {
        $this->deadline = $deadline ?? new Limits()->deadline();
    }

    /**
     * Cascading and box building run before layout and cost more than it on a
     * document with many elements, so the budget has to cover them too.
     * Sampled for the same reason layout samples it: reading the clock per
     * box would tax every ordinary document.
     */
    private function checkBudget(): void
    {
        if (++$this->sinceCheck < 512) {
            return;
        }

        $this->sinceCheck = 0;
        $this->deadline->check('box building');
    }

    public function resolver(): StyleResolver
    {
        return $this->styles;
    }

    public function build(DOMDocument $dom, float $rootFontSize = 12.0): Node
    {
        $this->rootFontSize = $rootFontSize;
        $this->styles->setRootFontSize($rootFontSize);

        $body = $dom->getElementsByTagName('body')->item(0);
        $html = $dom->documentElement;

        // Defect DG: the box tree's root is the root *element*, and that is
        // `<html>`, not `<body>`. While the tree started at the body, the root
        // element's `margin`, `padding`, `border` and `width` reached nothing,
        // and every box on `PI-root-html.html` was out by exactly the padding
        // nobody could declare. CSS 2.1 §10.5 reads it too: `body { height:
        // 100% }` resolves against the root's height, which is `auto` unless
        // the document says otherwise, so a cover page fills the page in
        // Chrome only when both elements declare it.
        $root = $html instanceof DOMElement
            ? $html
            : ($body instanceof DOMElement ? $body : null);

        if (!$root instanceof DOMElement) {
            return new Node(['display' => 'block']);
        }

        $this->bodyElement = $body instanceof DOMElement ? $body : null;
        $this->bodyBox     = null;

        // Styles declared above the root still have to reach it, which is the
        // case where a caller hands in a document whose root is not `<html>`.
        $chain = [];

        for ($n = $root->parentNode; $n instanceof DOMElement; $n = $n->parentNode) {
            array_unshift($chain, $n);
        }

        $inherited = [];

        foreach ($chain as $ancestor) {
            $inherited = $this->styles->cascade($ancestor, $inherited);
        }

        $computed = $this->styles->cascade($root, $inherited);
        $node     = $this->buildBox($root, $computed) ?? new Node(['display' => 'block']);

        $this->propagateCanvasBackground($node, $this->bodyBox);

        $this->stackSerial = 0;
        $this->assignStackPaths($node, [], [], true);

        return $node;
    }

    /**
     * CSS Backgrounds 3 section 2.11.2, the canvas background.
     *
     * The root element's background paints the whole canvas rather than its
     * own box. Where the root declares none, the value comes from its `<body>`
     * child, and the body then paints none of its own: a `body { background }`
     * covers the page and not the 200pt box the body happens to be. Both
     * halves are Chrome's printed answer on `PO-root-paint.html` and
     * `PR-root-paint-html.html`.
     *
     * **This used to read a computed style and it now reads two boxes**, which
     * is what defect DG bought: while the tree started at `<body>` there was no
     * root box to take the background off, so the rule was expressed as the
     * cascade of the element above the tree. Both spellings agree on the two
     * probes; this one is the spec's own sentence.
     */
    private function propagateCanvasBackground(Node $root, ?Node $body): void
    {
        if ($root->background !== null || $root->backgroundLayers !== []) {
            $root->canvasBackground       = $root->background;
            $root->canvasBackgroundLayers = $root->backgroundLayers;
            $root->background             = null;
            $root->backgroundLayers       = [];

            return;
        }

        if ($body === null || ($body->background === null && $body->backgroundLayers === [])) {
            return;
        }

        $root->canvasBackground       = $body->background;
        $root->canvasBackgroundLayers = $body->backgroundLayers;
        $body->background             = null;
        $body->backgroundLayers       = [];
    }

    /**
     * Resolve `z-index` into the paint order each box carries, as CSS 2.1
     * Appendix E describes it.
     *
     * Appendix E paints a page as a tree of stacking contexts, and the
     * paginated painter has one flat list of fragments per page to sort. The
     * two meet in {@see Node::$stackPath}: one `[z-index, step]` pair per
     * stacking context between the root and the box, the box's own pair last.
     *
     * Two things decide where a box hangs off:
     *
     * - A box that **makes a stacking context** is where a raised descendant
     *   stops. A child at `z-index: 10` inside a parent at 1 sorts at 1, not
     *   at 10, because its path starts with the parent's pair.
     * - A positioned box with `z-index: auto` makes no stacking context, but
     *   it still paints as one piece: its plain descendants go with it and
     *   its positioned ones sort against its own siblings instead. That is
     *   why the two prefixes below are separate.
     * - **A float is the same shape.** Appendix E step 4 says to treat one as
     *   if it created a stacking context, while its positioned descendants
     *   stay in the parent's. Without a group of its own a float's contents
     *   would sort at step 3 or 5 against the boxes around it and paint under
     *   the float's own background.
     *
     * **Every box gets a serial number after its pair**, in the order this
     * walk reaches it, which is document order with a flex container's `order`
     * already applied. Two things need it. A box's contents have to land
     * between it and the next box asking for the same place, which is what the
     * serial did when only a box that held others carried one. And the
     * comparison has to be **total**: a box the fold cuts is painted through a
     * proxy that is not in flow position in the page's list, so leaving ties
     * to the stable sort put that proxy under an earlier sibling as well as
     * under its own children, which is defect EC.
     *
     * @param list<int> $context the nearest stacking context's own path
     * @param list<int> $group   the nearest path this box may hang off, which
     *                           for a positioned `z-index: auto` box is its own
     */
    private function assignStackPaths(Node $node, array $context, array $group, bool $isRoot = false): void
    {
        $makesContext = $isRoot || self::makesStackingContext($node);

        /*
         * Which prefix the box hangs off and whether other boxes hang off it
         * are two questions, and a float is the box that separates them: it
         * sorts among its own group the way any in-flow box does, and its
         * contents still have to travel with it.
         */
        $sortsInContext = $makesContext || $node->isPositioned();
        $holdsBoxes     = $sortsInContext || self::paintsAsFloat($node);

        $node->stackPath = [
            ...($sortsInContext ? $context : $group),
            $node->isPositioned() ? ($node->zIndex ?? 0) : 0,
            self::paintStep($node, $makesContext, $isRoot),
            $this->stackSerial++,
        ];

        $childContext = $makesContext ? $node->stackPath : $context;
        $childGroup   = $holdsBoxes ? $node->stackPath : $group;

        foreach (self::sourceOrderChildren($node) as $child) {
            $this->assignStackPaths($child, $childContext, $childGroup);
        }
    }

    /**
     * A box's children in the order they were written, which is not always the
     * order they are stored in.
     *
     * {@see partition()} appends an out-of-flow child after the block's own
     * content, because emitting it where it stands would close the line it
     * sits on. That is a layout decision and the paint order must not inherit
     * it: two positioned siblings that reach the same step of Appendix E are
     * separated by document order alone, so an out-of-flow box written
     * **before** a sibling that makes a stacking context paints under it, and
     * coming last here paints it over. Defect EI, and it needs the sibling to
     * make a stacking context to show at all, because an ordinary in-flow
     * block paints its background at step 3 and belongs under an out-of-flow
     * sibling whatever the source order is.
     *
     * A box `partition()` did not number keeps its stored place, which is what
     * a zero means: it is an anonymous box of text, a drop cap or a list
     * marker, and none of them shares a painting step with a positioned box.
     *
     * @return Node[]
     */
    private static function sourceOrderChildren(Node $node): array
    {
        $children = BoxPainter::orderedChildren($node);
        $held     = [];
        $ordered  = [];

        foreach ($children as $child) {
            if ($child->isOutOfFlow() && $child->sourceOrder > 0) {
                $held[] = $child;

                continue;
            }

            $ordered[] = $child;
        }

        if ($held === []) {
            return $children;
        }

        // Backwards, so that two children wanting the same place keep the
        // order they were written in.
        foreach (array_reverse($held) as $child) {
            $at = count($ordered);

            foreach ($ordered as $index => $other) {
                if ($other->sourceOrder > $child->sourceOrder) {
                    $at = $index;

                    break;
                }
            }

            array_splice($ordered, $at, 0, [$child]);
        }

        return $ordered;
    }

    /**
     * Whether a raised descendant of this box stops here instead of sorting
     * against the box's own siblings.
     *
     * `z-index: auto` and `z-index: 0` paint in the same place and only the
     * second one makes a context, which is why {@see Node::$zIndex} has to be
     * able to say "auto". `position: fixed` always makes one.
     */
    private static function makesStackingContext(Node $node): bool
    {
        return ($node->isPositioned() && $node->zIndex !== null)
            || $node->position === 'fixed'
            || $node->opacity < 1.0
            || $node->transform !== []
            || $node->maskLayers !== []
            || $node->blendMode !== 'normal';
    }

    /**
     * A box that paints where Appendix E's step 4 puts a float.
     *
     * A positioned box is not one whatever it declares, because `position`
     * takes `float` off the box, and a box that makes a stacking context of
     * its own paints atomically at step 6 instead.
     */
    private static function paintsAsFloat(Node $node): bool
    {
        return $node->float !== 'none'
            && !$node->isPositioned()
            && !self::makesStackingContext($node);
    }

    /**
     * Which of CSS 2.1 Appendix E's painting steps this box belongs to.
     *
     * Step 2 is a negative `z-index`, step 6 a positioned box or one that
     * makes a stacking context without being positioned, step 7 a positive
     * `z-index`, and step 3 the background and border of a block-level box.
     *
     * **Steps 4 and 5 are a float and inline-level content**, and the engine
     * can express both because it already paints them as boxes of their own: a
     * run of text is a `display: text` node beside the block whose background
     * it sits on, so a fragment's decoration is separable from its text
     * without splitting anything. An atomic inline rides on that node's lines
     * and so paints with them, which is where Appendix E puts an inline block
     * anyway.
     */
    private static function paintStep(Node $node, bool $makesContext, bool $isRoot): int
    {
        if ($isRoot) {
            return 3;
        }

        $z = $node->isPositioned() ? ($node->zIndex ?? 0) : 0;

        return match (true) {
            $z < 0                                => 2,
            $z > 0                                => 7,
            $node->isPositioned() || $makesContext => 6,
            self::paintsAsFloat($node)             => 4,
            $node->display === 'text'              => 5,
            default                                => 3,
        };
    }

    // -----------------------------------------------------------------
    private function buildBox(DOMElement $el, array $computed): ?Node
    {
        // The canvas rule needs the body's box and the body is not reachable by
        // position, so it is caught on the way past. Clearing the element first
        // is what stops the re-entry from finding it again.
        if ($el === $this->bodyElement) {
            $this->bodyElement = null;

            return $this->bodyBox = $this->buildBox($el, $computed);
        }

        $this->checkBudget();

        $display = $computed['display'] ?? 'block';

        if ($display === 'none') {
            return null;
        }

        // CSS 2.1 §12.4: reset, then increment, then the pseudo-elements read
        // the result. Boxes are built in document order, so the counter scopes
        // can be a stack per name rather than a second walk.
        // CSS Lists §4: a `display: list-item` box increments `list-item`
        // before its own `counter-*` declarations are read, and a list
        // instantiates the counter. The marker used to count `<li>` siblings
        // and was wired to no counter at all, so `counter-set: list-item 4`,
        // `counter-increment: list-item` and `<li value>` all reached nothing.
        // Defect AS.
        $this->applyListItemCounter($el, $computed);
        $this->applyCounters($computed, $el);

        $tag = strtolower($el->nodeName);

        if ($tag === 'img') {
            return $this->buildImage($el, $computed);
        }

        if ($tag === 'input') {
            return $this->buildInput($el, $computed);
        }

        if ($tag === 'textarea') {
            return $this->buildTextArea($el, $computed);
        }

        if ($tag === 'select') {
            return $this->buildSelect($el, $computed);
        }

        // A rule is a childless block-level box, not a replaced one: it takes
        // the width of its containing block like any other block.
        if ($tag === 'hr') {
            return $this->styleBox(new Node(['display' => 'block']), $computed, $el);
        }

        if ($tag === 'svg') {
            return $this->buildInlineSvg($el, $computed);
        }

        if ($display === 'table') {
            return $this->buildTable($el, $computed);
        }

        if ($display === 'table-row') {
            return $this->buildRow($el, $computed);
        }

        // Partition children into runs of inline content and block boxes.
        //
        // The pseudo-element style is this element's own where it declares one
        // and the parent's where the parent's first formatted line turned out
        // to be in here. Both are already cascaded onto this element, so from
        // here on there is no difference between them.
        $inheritedLine   = $this->inheritedFirstLine;
        $inheritedLetter = $this->inheritedFirstLetter;

        $this->inheritedFirstLine   = null;
        $this->inheritedFirstLetter = null;
        $this->firstLetterFloat     = null;

        $firstLine   = $this->firstLineStyle($el, $computed) ?? $inheritedLine;
        $firstLetter = $this->firstLetterHook($el, $computed) ?? $inheritedLetter;

        $groups = $this->generatedContent($el, $computed, $firstLine, $firstLetter);
        $marker = $this->listMarker($el, $computed);

        /*
         * A `list-style-image` that resolves replaces the marker text
         * entirely, bullet or number alike, and it beats
         * `list-style-type: none` as well. One that does not resolve, a
         * `url()` naming a file that is not there, leaves the text marker
         * exactly where it was.
         */
        $markerImage = $this->listMarkerImage($el, $computed);

        if ($markerImage !== null) {
            $marker = null;
        }

        /*
         * An `inside` marker image is ON the line rather than beside it, so it
         * becomes a run exactly as an `inside` shape does and travels down the
         * two paths below that already know how to put one there. Converting
         * it here rather than at each of them is what keeps "an inside marker
         * joins the line" one rule: Chrome draws the picture at the item's own
         * content edge and starts the text {@see InlineRun::MARKER_GAP} after
         * the picture's box, `ST-marker-image.html`.
         */
        if ($markerImage !== null && $this->markerIsInside($computed)) {
            $marker                    = $this->makeRun('', $computed);
            $marker->markerImage       = $markerImage['layer'];
            $marker->markerImageWidth  = $markerImage['width'];
            $marker->markerImageHeight = $markerImage['height'];
            $markerImage               = null;
        }

        $children = [];

        // A block that handed its first line to a child has none of its own
        // left, so inline content further down in it is the second line.
        $firstRun = !$this->firstLineDescended;

        foreach ($groups as $group) {
            if ($group['type'] === 'inline') {
                if ($group['runs'] === [] && $marker === null && $markerImage === null) {
                    continue;
                }

                // Only the block's first formatted line is the first line, and
                // a block child between two runs of inline content ends it.
                if ($firstRun) {
                    [$group['runs'], $letter] = $this->splitFirstLetter($group['runs']);

                    // A floated first letter leaves the line and becomes a
                    // float beside it, which is what a drop cap is. The block
                    // float machinery then shortens the line boxes for it,
                    // exactly as it does for any other float.
                    if ($letter !== null && $this->firstLetterFloat !== null
                        && strtolower(trim($this->firstLetterFloat['float'] ?? 'none')) !== 'none') {
                        $children[]    = $this->dropCap($letter, $this->firstLetterFloat, $el);
                        $group['runs'] = array_values(array_filter(
                            $group['runs'],
                            static fn(InlineRun $run): bool => $run !== $letter,
                        ));
                    }
                } else {
                    foreach ($group['runs'] as $run) {
                        $run->firstLine = null;
                    }
                }

                if ($marker !== null && $this->markerIsInside($computed)) {
                    array_unshift($group['runs'], $marker);
                    $marker = null;
                }

                $text = $this->textBox(
                    $group['runs'],
                    $computed,
                    $el,
                    $firstRun ? $firstLine : null,
                );

                $firstRun = false;

                // Where this run of inline content was written, so an
                // out-of-flow sibling held back to the end of the block can be
                // put back in front of it. Defect GI.
                $text->sourceOrder = $group['order'] ?? 0;

                $text->orphans    = max(1, (int) ($computed['orphans'] ?? 2));
                $text->widows     = max(1, (int) ($computed['widows'] ?? 2));
                // The same parser the box itself uses. There were two, and the
                // weaker one was the one that reached the glyphs: it split the
                // declaration on whitespace, so `rgb(0, 128, 0)` came apart
                // into three pieces and none of them was a color, and it only
                // recognised px, pt, em and rem, so offsets in any other unit
                // left it with no lengths and it dropped the shadow.
                $text->textShadow = $this->styles->textShadow(
                    $computed['text-shadow'] ?? 'none',
                    $this->pt($computed['font-size'] ?? '12'),
                    $this->rootFontSize,
                );

                if ($marker !== null) {
                    $text->marker = $marker;
                    $marker       = null;
                }

                if ($markerImage !== null) {
                    $text->markerImage       = $markerImage['layer'];
                    $text->markerImageWidth  = $markerImage['width'];
                    $text->markerImageHeight = $markerImage['height'];
                    $markerImage             = null;
                }

                $children[] = $text;
            } else {
                $children[] = $group['node'];
            }
        }

        /*
         * An item whose content is a block child makes no inline group at all,
         * so the marker above has nothing to hang on and used to be dropped on
         * the floor: `<li><p>x</p></li>` painted no bullet anywhere. Chrome
         * draws one on the first line of the item's content, so the first text
         * box under the item is what carries it.
         *
         * An `inside` marker is the other shape and it is layout rather than
         * ink: Chrome gives it a line of its own above the block child, which
         * moves the content down a line, so it becomes a text box of the
         * item's own rather than riding a descendant's line.
         * `SN-list-marker.html` m6.
         */
        $hung = null;

        if ($marker !== null && $this->markerIsInside($computed)) {
            array_unshift($children, $this->textBox([$marker], $computed, $el, null));
            $marker = null;
        }

        if (($marker !== null || $markerImage !== null) && !$this->markerIsInside($computed)) {
            /*
             * An item with no content at all has no line and no text box, so
             * there is nothing to hang the marker beside and the item took no
             * room: `<li></li>` vanished. Chrome keeps its line and draws its
             * bullet, so the item gets a text box of its own with nothing on
             * it, which {@see FlexLayout::measureEmpty} gives one empty line.
             * `SN-list-marker.html` m8.
             */
            if ($children === []) {
                $children[] = $this->textBox([], $computed, $el, null);
            }

            $text = self::firstTextBox($children);

            if ($text !== null) {
                $hung = $text;

                if ($marker !== null) {
                    $text->marker = $marker;
                }

                if ($markerImage !== null) {
                    $text->markerImage       = $markerImage['layer'];
                    $text->markerImageWidth  = $markerImage['width'];
                    $text->markerImageHeight = $markerImage['height'];
                }
            }
        }

        $mode = match (true) {
            $display === 'flex' || $display === 'inline-flex' => 'flex',
            self::isWebkitFlex($computed)                     => 'flex',
            $display === 'grid' || $display === 'inline-grid' => 'grid',
            default                                           => 'block',
        };

        $node = new Node(['display' => $mode], $children);

        // A block box with exactly one anonymous text child and no padding of
        // its own can collapse into that text box, which keeps the tree small.
        $node = $this->styleBox($node, $computed, $el);

        // The marker hangs outside the ITEM's content edge, and the box
        // carrying it is a descendant that may sit anywhere inside the item.
        // Where the collapse above made the two one box there is nothing
        // between them to measure.
        if ($hung !== null && $hung !== $node) {
            $hung->markerHost = $node;
            $node->markerHung = $hung;

            /*
             * The marker's line is the item's own font whichever box the ink
             * ends up on, so the item's content moves down to clear it exactly
             * as it does where the item hosts the marker itself. Only the
             * hosted spelling got a strut, so `<li><ul>` was pushed and
             * `<li><p>` was not, which is one rule behaving two ways:
             * `SY-nested-face.html` p9 and p10 read a push of 20 and 40 in
             * Chrome, the same two numbers p1 and p5 read with a nested list
             * in the same place. Defect GD.
             *
             * There is no floor beside it. An item that hosts its own marker
             * has no line under it at all and is floored at one; this one has
             * the line the marker hangs on, and Chrome makes the item exactly
             * as tall as that line plus the distance it moved.
             */
            $node->strut ??= $this->makeRun('', $computed);
        }

        /*
         * An item whose only children are blocks that hold no line has nothing
         * anywhere under it to hang the marker on, so it hosts one itself.
         *
         * `<li></li>` was already covered, by giving an item with NO children
         * a text box of its own; `<li><div></div></li>` was not, and painted
         * no marker at all. The two cannot be answered the same way, because a
         * text box takes a line's worth of room in the flow and Chrome does
         * not move the block child down for it: `ST-marker-rows.html` t5 and
         * t6, and a 20px child in a 40px line box leaves the child at the top
         * with the item 40 tall. So the line is a FLOOR under the item's own
         * height rather than a box in front of its children, which is
         * `max(content, one line)` and is what `minHeight` already means. An
         * 80px child keeps its 80.
         *
         * The floor belongs to the marker and not to list items, which the
         * control says: the same markup with `list-style-type: none` is 20
         * tall in Chrome and draws nothing.
         *
         * **The floor itself is applied in layout and not here**, because it
         * holds only where the item has no line under it anywhere and this is
         * the wrong place to ask: a nested list is built before the item that
         * holds it and its own items already carry markers, so nothing here
         * can tell `<li><ul>`, which has a line, from `<li><div></div></li>`,
         * which has none. Defect GE, and {@see FlexLayout::markerLinePush()}
         * is where the question is already asked.
         */
        if ($hung === null && ($marker !== null || $markerImage !== null)) {
            $node->marker = $marker;

            if ($markerImage !== null) {
                $node->markerImage       = $markerImage['layer'];
                $node->markerImageWidth  = $markerImage['width'];
                $node->markerImageHeight = $markerImage['height'];
            }

            // The line the marker sits on is the item's own font, which is
            // what a strut is. The item has no inline content of its own by
            // construction here, so nothing else can read it.
            $node->strut ??= $this->makeRun('', $computed);
        }

        return $node;
    }

    /**
     * A table's rows may sit directly under it or inside thead/tbody/tfoot.
     * Row groups are flattened away: they carry no layout of their own here,
     * only the hint that thead rows should repeat after a page break and the
     * group each row was written in, which §17.5.3 shares a surplus height
     * between before it reaches the rows.
     */
    private function buildTable(DOMElement $el, array $computed): Node
    {
        $rows = [];
        $this->collectRows($el, $computed, $rows);

        // CSS 2.1 §17.5.1: the header group renders at the top of the table and
        // the footer group at the bottom, whatever order they were written in.
        // HTML lets a `<tfoot>` be written before the body precisely so a
        // streaming parser sees it early, and Chrome moves a header group up
        // the same way: `Z1-group-display.html`'s `e2` puts a group written
        // second at the top, and `ZA-fold-thead-late.html` a whole `<thead>`.
        $rows = [
            ...array_filter($rows, static fn (Node $row): bool => $row->isHeaderRow),
            ...array_filter($rows, static fn (Node $row): bool => !$row->isHeaderRow && !$row->isFooterRow),
            ...array_filter($rows, static fn (Node $row): bool => $row->isFooterRow),
        ];

        $node               = new Node(['display' => 'table'], $rows);
        $node->columnWidths = $this->columnWidths($el, $computed);
        $this->styleBox($node, $computed, $el);

        // A column and a column group compete for a collapsed grid line and
        // have no box of their own to carry a border, so the table holds
        // theirs. Defect HS: read here rather than in `columnWidths()` because
        // a color needs the table's own `currentcolor` and that is settled by
        // `styleBox()` on the line above.
        [$node->columnBorders, $node->columnGroupBorders] = $this->columnBorders($el, $computed, $node->color);

        // WAI-ARIA: a presentational table takes its rows and cells with it,
        // because a `TR` or a `TD` with no `Table` around it is not a tree a
        // reader can use and veraPDF is right to say so. Defect HU.
        if ($node->role === 'NonStruct') {
            $this->stripTableRoles($rows);
        }

        $node->borderCollapse = trim($computed['border-collapse'] ?? 'separate') === 'collapse' ? 'collapse' : 'separate';
        $node->tableLayout    = strtolower(trim($computed['table-layout'] ?? 'auto')) === 'fixed' ? 'fixed' : 'auto';

        // Two lengths, horizontal then vertical; one sets both. Reading the
        // whole declaration as a single length made `16px 0` parse as nothing
        // and drop the spacing on both axes (defect BY).
        $spacing = preg_split('/\s+/', trim($computed['border-spacing'] ?? '0'), -1, PREG_SPLIT_NO_EMPTY) ?: ['0'];

        $node->borderSpacingX = max(0.0, $this->styles->length(
            $spacing[0],
            $node->fontSize,
            $this->rootFontSize,
        ) ?? 0.0);

        $node->borderSpacingY = max(0.0, $this->styles->length(
            $spacing[1] ?? $spacing[0],
            $node->fontSize,
            $this->rootFontSize,
        ) ?? 0.0);

        return $this->withCaption($node, $el, $computed);
    }

    /**
     * Which cells a `<th>` heads.
     *
     * The author's own `scope` attribute wins, because HTML has the answer and
     * nothing else here can beat it. Without one the shape decides the way a
     * browser's does: a header inside `<thead>`, or in the table's first row,
     * heads its column, and a header anywhere else heads its row, which is the
     * `<th>` at the start of a body row.
     *
     * PDF/UA-1 clause 7.5 is what asks for this, and it is what
     * `SM-tag-table.html` failed twice over before round 47: **round 25 read
     * this row as missing `THead`/`TBody`/`TFoot`, and neither Chrome nor the
     * checker wants those at all.**
     */
    /** Whether every cell of this row is a header cell. */
    private static function rowIsAllHeaders(DOMElement $row): bool
    {
        $cells = 0;

        foreach ($row->childNodes as $cell) {
            if (!$cell instanceof DOMElement) {
                continue;
            }

            $name = strtolower($cell->nodeName);

            if ($name !== 'th' && $name !== 'td') {
                continue;
            }

            if ($name === 'td') {
                return false;
            }

            $cells++;
        }

        return $cells > 0;
    }

    private static function headerScopeOf(DOMElement $el): string
    {
        $declared = strtolower(trim($el->getAttribute('scope')));

        if ($declared === 'col' || $declared === 'colgroup') {
            return 'Column';
        }

        if ($declared === 'row' || $declared === 'rowgroup') {
            return 'Row';
        }

        $row = $el->parentNode;

        if (!$row instanceof DOMElement) {
            return 'Column';
        }

        $section = $row->parentNode;

        if ($section instanceof DOMElement && strtolower($section->nodeName) === 'thead') {
            return 'Column';
        }

        // A row whose cells are ALL headers is a header row wherever it sits,
        // which is what a two-level header is: `<th colspan=2>2026</th>` in the
        // first row over `<th>Q1</th><th>Q2</th>` in the second. Chrome gives
        // every one of them Column, and the first-row test alone gives the
        // second row Row, which is `TP-table-headers.html` t0 #2 and #3.
        if (self::rowIsAllHeaders($row)) {
            return 'Column';
        }

        // The first row of the table, whichever section holds it.
        foreach (($section instanceof DOMElement ? $section : $row)->childNodes as $sibling) {
            if (!$sibling instanceof DOMElement || strtolower($sibling->nodeName) !== 'tr') {
                continue;
            }

            return $sibling === $row ? 'Column' : 'Row';
        }

        return 'Column';
    }

    /**
     * A `<caption>` is not part of the table grid: CSS puts it in the table
     * *wrapper* box, above the grid or below it. Modeling it as an anonymous
     * block around the two is what that means in this box tree, and it costs
     * layout and pagination nothing, since a block containing a table is a
     * shape they both already handle. Left as a row, the caption would be
     * measured as a one-cell row of the grid; left out, as it was, the text
     * simply never reached the page.
     *
     * @param array<string,string> $computed
     */
    private function withCaption(Node $table, DOMElement $el, array $computed): Node
    {
        foreach ($el->childNodes as $child) {
            if (!$child instanceof DOMElement) {
                continue;
            }

            $childComputed = $this->styles->cascade($child, $computed);
            $isCaption     = strtolower($child->nodeName) === 'caption'
                || ($childComputed['display'] ?? '') === 'table-caption';

            if (!$isCaption || ($childComputed['display'] ?? '') === 'none') {
                continue;
            }

            $childComputed['display'] = 'block';
            $caption                  = $this->buildBox($child, $childComputed);

            if ($caption === null) {
                continue;
            }

            // A caption box establishes a block formatting context, and the
            // rewrite above is what loses it: `table-caption` is one of the
            // displays `FlexLayout::sharesFloatContext()` already answers
            // `false` for, and `block` is not. Chrome makes a caption holding a
            // 100px float **75.000** tall and contains it
            // (`O-table-margin-wrapper.html` `of`); this let the float out into
            // the wrapper, where it pushed the table box and everything under
            // it 12.000 down the page (defect CQ).
            $caption->flowRoot = true;

            $onBottom = strtolower(trim($childComputed['caption-side'] ?? 'top')) === 'bottom';

            $wrapper                 = new Node(
                ['display' => 'block'],
                $onBottom ? [$table, $caption] : [$caption, $table],
            );
            $wrapper->isTableWrapper = true;

            $this->moveToWrapper($table, $wrapper);

            return $wrapper;
        }

        return $table;
    }

    /**
     * CSS 2.1 §17.4: `position`, `float`, the four `margin-*` and the four
     * offsets computed on the table element are used on the table **wrapper**
     * box and not on the table box inside it. Leaving them on the table box
     * puts a `margin-bottom` between the table and a bottom caption instead of
     * outside both, and lets a `float` carry the table out from under its own
     * caption. Every one of these is measured on
     * `docs/harness/probes/O-table-margin-wrapper.html`:
     *
     *     o2  margin-top, top caption      the margin is above the caption,
     *                                      Chrome 381.000, this had 393.000
     *     o4  margin-bottom, bottom cap    the caption at 859.500 under the
     *                                      table, this had it at 865.500
     *     oa  margin: 0 auto               the WRAPPER centres, so the caption
     *                                      centres with it: x 105.000, not 0
     *     og  float: left                  caption and table both at x 6.000,
     *                                      and the block after them at 2979.000
     *     oi  position: relative           the offset moves the caption too
     *     ok  clear: left                  the wrapper clears, so the caption
     *                                      goes below the float with the table
     *     om  position: absolute           the wrapper is the positioned box
     *
     * `clear` is not in §17.4's list and Chrome moves it anyway: `ok` puts the
     * whole wrapper below the float where a `clear` left on the table box has
     * nothing to clear, because the wrapper's own formatting context holds no
     * floats. `oh`, `oj`, `ol` and `on` are the same declarations on a table
     * with **no** caption, and all four are exact on both sides: without a
     * caption there is no wrapper and nothing moves.
     *
     * The wrapper also establishes a block formatting context, which is what
     * contains the caption's own margins: `o9` puts a `margin-top: 8px` caption
     * 6.000 below a preceding `margin-bottom: 16px` block rather than
     * collapsing the two, and `o8` does the same underneath. That is CSS Tables
     * §2.1 and it is the same field `display: flow-root` sets.
     */
    private function moveToWrapper(Node $table, Node $wrapper): void
    {
        $wrapper->flowRoot = true;

        $wrapper->margin        = $table->margin;
        $wrapper->autoMargin    = $table->autoMargin;
        $wrapper->marginPercent = $table->marginPercent;
        $wrapper->float         = $table->float;
        $wrapper->clear         = $table->clear;
        $wrapper->position      = $table->position;
        $wrapper->top           = $table->top;
        $wrapper->right         = $table->right;
        $wrapper->bottom        = $table->bottom;
        $wrapper->left          = $table->left;

        $table->margin        = ['top' => 0.0, 'right' => 0.0, 'bottom' => 0.0, 'left' => 0.0];
        $table->autoMargin    = ['top' => false, 'right' => false, 'bottom' => false, 'left' => false];
        $table->marginPercent = ['top' => null, 'right' => null, 'bottom' => null, 'left' => null];
        $table->float         = 'none';
        $table->clear         = 'none';
        $table->position      = 'static';
        $table->top           = null;
        $table->right         = null;
        $table->bottom        = null;
        $table->left          = null;
    }

    /**
     * <colgroup>/<col> give explicit column widths. A `span` attribute
     * repeats one <col> across several columns.
     *
     * @return array<int,float|string>
     */
    private function columnWidths(DOMElement $table, array $computed): array
    {
        $widths = [];
        $index  = 0;

        foreach ($table->getElementsByTagName('col') as $col) {
            if (!$col instanceof DOMElement) {
                continue;
            }

            $colComputed = $this->styles->cascade($col, $computed);
            $fs          = $this->pt($colComputed['font-size'] ?? '12');
            $value       = $this->sizeValue($colComputed['width'] ?? null, $fs);

            if (is_float($value) && $value < 0) {
                $value = null;
            }

            $span = max(1, (int) ($col->getAttribute('span') ?: 1));

            for ($k = 0; $k < $span; $k++, $index++) {
                if ($value !== null) {
                    $widths[$index] = $value;
                }
            }
        }

        return $widths;
    }

    /**
     * Take the table roles off a presentational table's rows and cells.
     *
     * Their own children keep theirs: a `<p>` inside a presentational cell is
     * still a paragraph, and only the boxes whose meaning IS the table lose
     * one. Defect HU.
     *
     * @param Node[] $rows
     */
    private function stripTableRoles(array $rows): void
    {
        foreach ($rows as $row) {
            $row->role = '';

            foreach ($row->children as $cell) {
                $cell->role        = '';
                $cell->headerScope = '';
            }
        }
    }

    /**
     * The borders `<col>` and `<colgroup>` declare, per column index.
     *
     * CSS 2.1 §17.6.2.1 ranks a column above a column group and both below a
     * row group, so the two are kept apart rather than merged: which one won a
     * line decides what is drawn on a tie of width. A `<colgroup>` covers the
     * columns its own `<col>` children define, or its `span` where it has
     * none, and a `<col>`'s `span` repeats it exactly as a width repeats.
     *
     * Defect HS. Nothing else reads these: a column has no box in this tree,
     * so its border exists only as a competitor for a grid line, and the cell
     * that wins draws it.
     *
     * @param  array<string,string>                        $computed
     * @param  array{0:float,1:float,2:float,3?:float}|null $currentColor
     * @return array{0:array<int,array<string,array{width:float,style:string,color:array{0:float,1:float,2:float,3:float}}>>,
     *               1:array<int,array<string,array{width:float,style:string,color:array{0:float,1:float,2:float,3:float}}>>}
     */
    private function columnBorders(DOMElement $table, array $computed, ?array $currentColor): array
    {
        $columns = [];
        $groups  = [];
        $index   = 0;

        foreach ($table->getElementsByTagName('colgroup') as $group) {
            if (!$group instanceof DOMElement) {
                continue;
            }

            $sides = $this->declaredBorder($group, $computed, $currentColor);
            $cols  = [];

            foreach ($group->getElementsByTagName('col') as $col) {
                if ($col instanceof DOMElement) {
                    $cols[] = $col;
                }
            }

            $start = $index;

            if ($cols === []) {
                $index += max(1, (int) ($group->getAttribute('span') ?: 1));
            }

            foreach ($cols as $col) {
                $own  = $this->declaredBorder($col, $computed, $currentColor);
                $span = max(1, (int) ($col->getAttribute('span') ?: 1));

                for ($k = 0; $k < $span; $k++, $index++) {
                    if ($own !== null) {
                        $columns[$index] = $own;
                    }
                }
            }

            if ($sides === null) {
                continue;
            }

            for ($c = $start; $c < $index; $c++) {
                $groups[$c] = $sides;
            }
        }

        // A `<col>` outside any `<colgroup>` is a column of its own, and the
        // index it lands on is the one `columnWidths()` gives it: both walk
        // the table's `<col>` elements in document order.
        foreach ($table->getElementsByTagName('col') as $col) {
            if (!$col instanceof DOMElement || $col->parentNode?->nodeName === 'colgroup') {
                continue;
            }

            $own  = $this->declaredBorder($col, $computed, $currentColor);
            $span = max(1, (int) ($col->getAttribute('span') ?: 1));

            for ($k = 0; $k < $span; $k++, $index++) {
                if ($own !== null) {
                    $columns[$index] = $own;
                }
            }
        }

        return [$columns, $groups];
    }

    /**
     * One element's own border sides, cascaded against the table's computed
     * style, for an element that gets no box of its own.
     *
     * @param  array<string,string>                        $computed
     * @param  array{0:float,1:float,2:float,3?:float}|null $currentColor
     * @return array<string,array{width:float,style:string,color:array{0:float,1:float,2:float,3:float}}>|null
     */
    private function declaredBorder(DOMElement $el, array $computed, ?array $currentColor): ?array
    {
        $own    = $this->styles->cascade($el, $computed);
        $size   = $this->pt($own['font-size'] ?? '12');
        $length = fn(string $key): ?float => $this->styles->length(
            $own[$key] ?? '',
            $size,
            $this->rootFontSize,
            null,
        );

        return $this->borderSides($own, $length, $currentColor);
    }

    /**
     * @param Node[]                      $rows
     * @param string                      $section    which of the table's three sections these rows are
     *                                                written in: `header`, `body` or `footer`
     * @param bool                        $repeats    whether that section repeats on every page the
     *                                                table reaches, which needs the `<thead>` or
     *                                                `<tfoot>` element and not only the display
     * @param iterable<int,DOMNode>|null  $childNodes overrides the element's own children, which is
     *                                                what lets an anonymous table be built around a
     *                                                run of orphan siblings rather than around a box
     *                                                that exists in the DOM
     */
    private function collectRows(
        DOMElement $el,
        array $computed,
        array &$rows,
        string $section = 'body',
        bool $repeats = false,
        ?int $group = null,
        ?iterable $childNodes = null,
        float|string|null $groupHeight = null,
    ): void {
        // CSS 2.1 §17.2.1: a run of children that are not table boxes gets one
        // anonymous row around it, so stray content reaches the page instead of
        // being dropped for not being a `<tr>`.
        $stray = [];

        // §17.2.1 again, for the group rather than the row: a run of rows
        // written straight under the table shares one anonymous row group, and
        // a run on the far side of an explicit group is a second one.
        $anonymous = $group;

        // CSS 2.1 §17.5.1 gives a table one header and one footer, so a second
        // group asking to be either is an ordinary row group where it stands.
        // Chrome shares the surplus height with it as with any body section:
        // `Z2-group-display-more.html`'s `f0` is 69 / 69 with the first of two
        // footer groups at the bottom on 12, and `f3` says the same for two
        // `<thead>`s.
        $seenHeader = false;
        $seenFooter = false;

        // A generated row is still in whichever group it was written in, so a
        // stray line inside a `<thead>` repeats after a break and one inside a
        // `<tfoot>` goes to the foot rather than into the flow where it stood.
        $flushStray = function () use (&$stray, &$rows, &$anonymous, $computed, $el, $section, $repeats, $groupHeight): void {
            if ($stray === []) {
                return;
            }

            $row   = $this->anonymousRow($stray, $computed, $el);
            $stray = [];

            if ($row === null) {
                return;
            }

            $row->isHeaderRow    = $section === 'header';
            $row->isFooterRow    = $section === 'footer';
            $row->repeatOnBreak  = $repeats && $section === 'header';
            $row->repeatAtBottom = $repeats && $section === 'footer';
            $row->rowGroup       = $anonymous ??= ++$this->rowGroupSeq;
            $row->rowGroupHeight = $groupHeight;

            $rows[] = $row;
        };

        foreach ($childNodes ?? $el->childNodes as $child) {
            if (!$child instanceof DOMElement) {
                if ($child instanceof DOMText) {
                    $stray[] = $child;
                }

                continue;
            }

            $childComputed = $this->styles->cascade($child, $computed);
            $display       = $childComputed['display'] ?? 'inline';

            if ($display === 'none') {
                continue;
            }

            $tag = strtolower($child->nodeName);

            // A caption is not part of the grid: `withCaption()` walks these
            // same children for it, and a caption in an anonymous row as well
            // would put it on the page twice.
            if ($tag === 'caption' || $display === 'table-caption') {
                continue;
            }

            // §17.2.1 rule 2 counts rows, row groups and columns as proper
            // children of a table and nothing else, so a cell written without
            // a row around it gets one generated here, which is the shape the
            // `display: table` / `display: table-cell` centring idiom is
            // written in.
            if (!in_array($display, self::TABLE_ROW_LEVEL, true)
                && !in_array($tag, ['tr', 'thead', 'tbody', 'tfoot'], true)) {
                $stray[] = $child;

                continue;
            }

            $flushStray();

            if (in_array($display, self::TABLE_GROUP_LEVEL, true)
                || in_array($tag, ['thead', 'tbody', 'tfoot'], true)) {
                // A row group is built without going through buildBox(), so
                // its counters have to be applied here or `counter-reset` on a
                // <tbody> does nothing.
                $this->applyCounters($childComputed, $child);

                // Which section a group is, is its *display*: Chrome takes a
                // `<tbody style="display: table-header-group">` for the header
                // (`Z1` `e6`, 12.000 / 138.000) and a `<thead style="display:
                // table-row-group">` for a body section (`e7`, 75 / 75), where
                // this read the tag and got both the wrong way round.
                $role = match ($display) {
                    'table-header-group' => $seenHeader ? $section : 'header',
                    'table-footer-group' => $seenFooter ? $section : 'footer',
                    default              => $section,
                };

                $seenHeader = $seenHeader || $role === 'header';
                $seenFooter = $seenFooter || $role === 'footer';

                // Repeating on every page needs the element as well as the
                // display, which is the one question the display does not
                // settle. Chrome repeats a `<thead>` that is the header section
                // (`Z8`) and refuses a `<tbody>` acting as one (`Z4`), a
                // `<thead>` that is not it (`Z5`) and the second of two
                // `<thead>`s (`ZB`).
                // CSS 2.1 §17.5.3 reads a row group's `height` as a minimum for
                // the section, so it travels with the rows it is shared
                // between. A group with no rows in it still occupies that
                // height, and there is no row for it to travel on, so one is
                // generated: an empty band, which is what Chrome reports for
                // `<tbody style="height:80px"></tbody>` (defect DC).
                $height = $this->sizeValue(
                    $childComputed['height'] ?? null,
                    $this->pt($childComputed['font-size'] ?? '12'),
                );
                $before = count($rows);
                $id     = ++$this->rowGroupSeq;

                $this->collectRows(
                    $child,
                    $childComputed,
                    $rows,
                    $role,
                    match ($role) {
                        'header' => $tag === 'thead' || ($repeats && $section === 'header'),
                        'footer' => $tag === 'tfoot' || ($repeats && $section === 'footer'),
                        default  => false,
                    },
                    $id,
                    groupHeight: $height,
                );

                // The group's own ink. It is flattened away as a box, so the
                // style is carried on the rows it was written around and the
                // band is drawn per page from the rows that land there.
                // Defect DK.
                $groupBox = $this->rowGroupBox($childComputed, $child);

                if ($groupBox !== null) {
                    for ($r = $before, $n = count($rows); $r < $n; $r++) {
                        $rows[$r]->rowGroupBox = $groupBox;
                    }
                }

                if (count($rows) === $before && $height !== null) {
                    $band                 = new Node(['display' => 'table-row']);
                    $band->isGroupBand    = true;
                    $band->isHeaderRow    = $role === 'header';
                    $band->isFooterRow    = $role === 'footer';
                    $band->rowGroup       = $id;
                    $band->rowGroupHeight = $height;

                    $rows[] = $band;
                }

                $anonymous = null;

                continue;
            }

            if ($display === 'table-row' || $tag === 'tr') {
                $row                 = $this->buildRow($child, $childComputed);
                $row->isHeaderRow    = $section === 'header';
                $row->isFooterRow    = $section === 'footer';
                $row->repeatOnBreak  = ($repeats && $section === 'header') || $child->hasAttribute('data-repeat');
                $row->repeatAtBottom = $repeats && $section === 'footer';
                $row->rowGroup       = $anonymous ??= ++$this->rowGroupSeq;
                $row->rowGroupHeight = $groupHeight;

                $rows[] = $row;
            }
        }

        $flushStray();
    }

    /**
     * The anonymous `table-cell` box CSS 2.1 §17.2.1 generates around a run of
     * children that are not cells themselves.
     *
     * Without it the content is not merely misplaced, it never reaches the
     * page: `collectRows()` kept the rows it recognized and `buildRow()` kept
     * the elements, and both dropped everything else on the floor, so
     * `<div style="display: table">text</div>` printed nothing at all.
     *
     * Returns null when the run is white space that collapses away, which is
     * the newline every table is written with between its rows. Generating a
     * box there would give every table in every document a row of empty cells.
     *
     * @param array<int,DOMNode>   $nodes
     * @param array<string,string> $computed
     */
    private function anonymousCell(array $nodes, array $computed, DOMElement $el): ?Node
    {
        $inherited = $this->styles->inheritedOnly($computed);
        $groups    = $this->partition($el, $inherited, childNodes: $nodes);

        if ($groups === []) {
            return null;
        }

        $children = [];

        foreach ($groups as $group) {
            $children[] = $group['type'] === 'inline'
                ? $this->textBox($group['runs'], $inherited, $el)
                : $group['node'];
        }

        $cell = $this->styleBox(new Node(['display' => 'table-cell'], $children), $inherited, $el);

        // The element these nodes came out of owns its id and its heading
        // level, and two boxes answering to either would make a link or an
        // outline entry land on whichever the painter reached first.
        $cell->anchorId     = null;
        $cell->outlineLevel = 0;
        $cell->outlineTitle = '';
        $cell->altText      = '';

        // The element's own role belongs to the element's own box. This one is
        // a cell because CSS made it one, so it says so itself.
        $cell->role = StructureTree::anonymousRoleFor($cell->display);

        return $cell;
    }

    /**
     * The anonymous `table-row` box §17.2.1 generates around a run of children
     * of a table that are not rows, holding the anonymous cell around them.
     *
     * @param array<int,DOMNode>   $nodes
     * @param array<string,string> $computed
     */
    private function anonymousRow(array $nodes, array $computed, DOMElement $el): ?Node
    {
        $cells = $this->rowCells($nodes, $computed, $el);

        if ($cells === []) {
            return null;
        }

        $row = $this->styleBox(
            new Node(['display' => 'table-row'], $cells),
            $this->styles->inheritedOnly($computed),
            $el,
        );

        $row->anchorId     = null;
        $row->outlineLevel = 0;
        $row->outlineTitle = '';
        $row->altText      = '';
        $row->role         = StructureTree::anonymousRoleFor($row->display);

        return $row;
    }

    /**
     * The anonymous `table` box CSS 2.1 §17.2.1 generates around a run of
     * table-internal siblings that have no table to belong to.
     *
     * Rule 3 of §17.2.1: a misparented `table-row`, `table-row-group`,
     * `table-header-group`, `table-footer-group`, `table-column`,
     * `table-column-group` or `table-cell` and every consecutive sibling of the
     * same kind are wrapped in one anonymous table. Without it each one was an
     * ordinary block and kept a `width` that does not apply to it: three orphan
     * row groups were 90.000, 300.000 and 90.000 wide against Chrome's
     * **22.031** for all three, which is the one anonymous table's own
     * shrink-to-fit width (`L5-table-rowgroup-orphan.html`).
     *
     * The box inherits and nothing else, which is what §17.2.1 says: it has no
     * margin, border, padding or width of its own, and `border-collapse` and
     * `border-spacing` reach it because those two inherit.
     *
     * @param  DOMNode[]            $nodes
     * @param  array<string,string> $computed
     */
    private function anonymousTable(array $nodes, array $computed, DOMElement $el): ?Node
    {
        $inherited = $this->styles->inheritedOnly($computed);
        $rows      = [];

        $this->collectRows($el, $inherited, $rows, childNodes: $nodes);

        if ($rows === []) {
            return null;
        }

        $rows = [
            ...array_filter($rows, static fn (Node $row): bool => $row->isHeaderRow),
            ...array_filter($rows, static fn (Node $row): bool => !$row->isHeaderRow && !$row->isFooterRow),
            ...array_filter($rows, static fn (Node $row): bool => $row->isFooterRow),
        ];

        $table = $this->styleBox(new Node(['display' => 'table'], $rows), $inherited, $el);

        $table->anchorId     = null;
        $table->outlineLevel = 0;
        $table->outlineTitle = '';
        $table->altText      = '';
        $table->role         = StructureTree::anonymousRoleFor($table->display);

        $table->borderCollapse = trim($inherited['border-collapse'] ?? 'separate') === 'collapse'
            ? 'collapse'
            : 'separate';

        $spacing = preg_split('/\s+/', trim($inherited['border-spacing'] ?? '0'), -1, PREG_SPLIT_NO_EMPTY) ?: ['0'];

        $table->borderSpacingX = max(0.0, $this->styles->length($spacing[0], $table->fontSize, $this->rootFontSize) ?? 0.0);
        $table->borderSpacingY = max(0.0, $this->styles->length($spacing[1] ?? $spacing[0], $table->fontSize, $this->rootFontSize) ?? 0.0);

        return $table;
    }

    /**
     * The styled box a row group paints, or null where it paints nothing.
     *
     * CSS 2.1 §17.5.1 puts a row group's background under its rows and over
     * the table's, and §17.2 gives it a border in the collapsing model. The
     * group has no box in this tree, so this one is built and handed to its
     * rows to carry: nothing lays it out, and the fragmenter gives it a rect
     * per page from the rows that landed there. Defect DK.
     *
     * A group asking for no ink returns null, so the common case costs a
     * cascade read and one comparison.
     *
     * @param array<string,string> $computed
     */
    private function rowGroupBox(array $computed, DOMElement $el): ?Node
    {
        $box = $this->styleBox(new Node(['display' => 'table-row-group']), $computed, $el);

        if ($box->background === null && $box->border === null && $box->backgroundLayers === []) {
            return null;
        }

        return $box;
    }

    private function buildRow(DOMElement $el, array $computed): Node
    {
        // A row is built here rather than by buildBox(), which is the only
        // place counters are applied, so `tr { counter-increment: row }` had
        // no effect at all and every row read the same value. It has to run
        // before the cells, since their ::before is what reads the counter.
        $this->applyCounters($computed, $el);

        $node = new Node(['display' => 'table-row'], $this->rowCells($el->childNodes, $computed, $el));

        return $this->styleBox($node, $computed, $el);
    }

    /**
     * The cells of one row.
     *
     * CSS 2.1 §17.2.1 wraps each run of consecutive children that are not cells
     * in **one** anonymous cell, which is why the run is gathered rather than
     * wrapped node by node: `text <div>block</div> text` inside a row is one
     * cell holding three things, not three cells.
     *
     * @param  iterable<int,DOMNode> $childNodes
     * @param  array<string,string>  $computed
     * @return Node[]
     */
    private function rowCells(iterable $childNodes, array $computed, DOMElement $el): array
    {
        $cells = [];
        $stray = [];

        $flushStray = function () use (&$stray, &$cells, $computed, $el): void {
            if ($stray === []) {
                return;
            }

            $cell  = $this->anonymousCell($stray, $computed, $el);
            $stray = [];

            if ($cell !== null) {
                $cells[] = $cell;
            }
        };

        foreach ($childNodes as $child) {
            if (!$child instanceof DOMElement) {
                if ($child instanceof DOMText) {
                    $stray[] = $child;
                }

                continue;
            }

            $cellComputed = $this->styles->cascade($child, $computed);

            if (($cellComputed['display'] ?? '') === 'none') {
                continue;
            }

            if (($cellComputed['display'] ?? '') !== 'table-cell') {
                $stray[] = $child;

                continue;
            }

            $flushStray();

            $cell = $this->buildCell($child, $cellComputed);

            if ($cell !== null) {
                $cells[] = $cell;
            }
        }

        $flushStray();

        return $cells;
    }

    /** @param array<string,string> $cellComputed */
    private function buildCell(DOMElement $el, array $cellComputed): ?Node
    {
        $cell = $this->buildBox($el, $cellComputed);

        if ($cell === null) {
            return null;
        }

        $cell->display = 'table-cell';
        $cell->colspan = max(1, (int) ($el->getAttribute('colspan') ?: 1));
        $cell->rowspan = max(1, (int) ($el->getAttribute('rowspan') ?: 1));

        /*
         * CSS 2.1 §17.6.1.1: under `empty-cells: hide` a cell with no
         * content paints neither its background nor its border. The cell
         * keeps its place in the grid; it just goes blank, so this is a
         * decision about ink and nothing else.
         */
        if (
            strtolower(trim($cellComputed['empty-cells'] ?? 'show')) === 'hide'
            && trim($el->textContent) === ''
            && $cell->children === []
        ) {
            $cell->background = null;
            $cell->border     = null;
            $cell->outline    = null;
        }

        $cell->verticalAlign = match (trim($cellComputed['vertical-align'] ?? 'top')) {
            'middle' => 'middle',
            'bottom' => 'bottom',
            default  => 'top',
        };

        return $cell;
    }

    /**
     * Group children so that consecutive inline content becomes one anonymous
     * box, and each block-level child stands alone.
     *
     * `$childNodes` overrides the element's own children, which is what lets an
     * anonymous table cell be partitioned from the run of stray nodes it was
     * generated around rather than from a box that exists in the DOM.
     *
     * @param  iterable<int,DOMNode>|null $childNodes
     * @return array<array{type:string,runs?:InlineRun[],node?:Node}>
     */
    private function partition(
        DOMElement $el,
        array $parentComputed,
        ?array $firstLine = null,
        ?Closure $firstLetter = null,
        ?iterable $childNodes = null,
    ): array {
        $groups  = [];
        $pending = [];

        /*
         * How many boxes this block has taken off the source so far, which is
         * what {@see Node::$sourceOrder} carries. It counts boxes rather than
         * groups so that holding an out-of-flow child back does not move the
         * siblings around it, and it is what puts the child back where it was
         * written when the paint order is worked out. Defect EI.
         */
        $sourceOrder = 0;

        /*
         * The number the anonymous box holding the pending runs will take,
         * claimed when the first of those runs is written so that it falls
         * between the boxes either side of it. Null while nothing is pending.
         *
         * An anonymous box used to carry no number at all, because it is
         * assembled from runs afterwards rather than taken off the source, so
         * an out-of-flow box written before its block's own inline content had
         * nothing numbered after it and went to the end of the block. Defect
         * GI, `SZ-static-position.html` a6.
         */
        $pendingOrder = null;

        $openPending = function () use (&$pendingOrder, &$sourceOrder): void {
            $pendingOrder ??= ++$sourceOrder;
        };

        // A block ends any inline element's reach: an out-of-flow box inside
        // one resolves against the block, not against a `position: relative`
        // span the walk happened to pass through on the way here.
        $outerContainer        = $this->inlineContainer;
        $this->inlineContainer = null;

        /*
         * Out-of-flow children, held back until the block's own content is
         * partitioned. CSS 2.1 §9.5 keeps them off the line they occur on, so
         * emitting one in place would close that line and give the block a
         * second one it does not have; and Appendix E paints a positioned box
         * above the in-flow content of its stacking context, which is what
         * coming last spells here.
         *
         * @var array<array{type:string,runs?:InlineRun[],node?:Node}>
         */
        $deferred = [];

        // A flex or grid container blockifies its children, so an inline-block
        // item is a block there and never joins a line.
        $parentDisplay = $parentComputed['display'] ?? 'block';
        $blockifies    = self::isWebkitFlex($parentComputed) || in_array(
            $parentDisplay,
            ['flex', 'inline-flex', 'grid', 'inline-grid'],
            true,
        );

        /*
         * Floats written **inside** this block's inline content, held back
         * until the line they sit on is closed and then emitted **before** it.
         *
         * A float used to close the line where it was written, so
         * `beta<float>gamma` was two anonymous blocks and two lines where
         * Chrome has one of each. Chrome places the float first and lays the
         * whole line beside it: on `QX-float-midline.html` **both** runs sit at
         * x 33.5, not just the one after the float. Emitting the float ahead of
         * the runs is what spells that here, because a block flow applies a
         * float's exclusion to the lines that come after it. Defect AW.
         *
         * @var Node[]
         */
        $inlineFloats = [];

        /*
         * Whether this block's own first formatted line is still to come.
         *
         * `::first-line` and `::first-letter` style that line and it may not be
         * in this box at all, so which child gets the style is decided here.
         * A float and an out-of-flow box do not close it, because neither
         * generates a line in this block's inline formatting context: measured
         * on `RL-first-line-reach.html`, Chrome styles the paragraph after
         * either of them.
         */
        $firstLineOpen  = true;
        $passedFirstLine = false;

        $flush = function () use (&$groups, &$pending, &$pendingOrder, &$inlineFloats, &$firstLineOpen): void {
            foreach ($inlineFloats as $float) {
                $groups[] = ['type' => 'block', 'node' => $float];
            }

            $inlineFloats = [];

            if ($pending === []) {
                return;
            }

            // CSS 2.1 §9.2.1.1: white space that collapses away generates no
            // anonymous box. Between two block-level siblings that white space
            // is the newline every template is written with, and a box there
            // is not harmless: it sits between the two margins and stops them
            // collapsing, which pushes the second block down by the smaller of
            // the pair. Only a group that is *entirely* collapsible space goes,
            // so a space between two inline elements still separates them.
            foreach ($pending as $run) {
                if ($run->box !== null || $run->isBreak || !$run->collapsesWhitespace() || trim($run->text) !== '') {
                    $groups[]      = ['type' => 'inline', 'runs' => $pending, 'order' => $pendingOrder ?? 0];
                    $pending       = [];
                    $pendingOrder  = null;
                    $firstLineOpen = false;

                    return;
                }
            }

            $pending      = [];
            $pendingOrder = null;
        };

        /*
         * CSS 2.1 §17.2.1 rule 3: a table-internal box whose parent is not a
         * table gets an anonymous table generated around it and around every
         * consecutive sibling of the same kind. The run is gathered rather than
         * wrapped box by box, exactly as `rowCells()` gathers a run of stray
         * nodes into one anonymous cell: three orphan row groups written in a
         * row are **one** table in Chrome and take its width, not three.
         *
         * @var DOMNode[]
         */
        $orphans = [];

        $flushOrphans = function () use (&$orphans, &$groups, $parentComputed, $el): void {
            if ($orphans === []) {
                return;
            }

            $table   = $this->anonymousTable($orphans, $parentComputed, $el);
            $orphans = [];

            if ($table !== null) {
                $groups[] = ['type' => 'block', 'node' => $table];
            }
        };

        foreach ($childNodes ?? $el->childNodes as $child) {
            if ($child instanceof DOMText) {
                // White space between two orphan table boxes does not end the
                // run: §17.2.1 skips an anonymous inline box holding nothing
                // but collapsible space when it looks for consecutive
                // misparented siblings, which is every one of them written on
                // its own line.
                if (trim($child->textContent) !== '') {
                    $flushOrphans();
                }

                // CSS Flexible Box §4: an anonymous item holding only white
                // space is not rendered. Keeping it costs a whole `gap` per
                // item, which is every flex container written across lines.
                if ($blockifies && trim($child->textContent) === '') {
                    continue;
                }

                $run = $this->runFor($child->textContent, $parentComputed, $firstLine, $firstLetter);

                if ($run !== null) {
                    $openPending();
                    $pending[] = $run;
                }

                continue;
            }

            if (!$child instanceof DOMElement) {
                continue;
            }

            $computed = $this->styles->cascade($child, $parentComputed);
            $display  = $computed['display'] ?? 'inline';

            if ($display === 'none') {
                continue;
            }

            // A flex or grid container blockifies a table-internal child, so
            // there is nothing misparented there to wrap.
            if (!$blockifies && in_array($display, self::TABLE_INTERNALS, true)) {
                $flush();
                $orphans[] = $child;

                continue;
            }

            $flushOrphans();

            if (strtolower($child->nodeName) === 'br') {
                $openPending();
                $pending[] = $this->makeRun('', $computed, isBreak: true, firstLine: $firstLine);

                continue;
            }

            if ($display === 'inline' && $this->blockifiedByFlow($computed, $blockifies)) {
                $display             = 'block';
                $computed['display'] = 'block';
            }

            // CSS 2.1 §9.2.2: an inline-level replaced element is an atomic
            // inline, so `display: inline` on one means what the UA sheet's
            // `inline-block` means. Without this it took the inline-*element*
            // branch below, which walks the child nodes for runs and finds
            // none, so `<img style="display: inline">` left the document
            // altogether rather than being laid out.
            if ($display === 'inline' && isset(self::ATOMIC_TAGS[strtolower($child->nodeName)])) {
                $display             = 'inline-block';
                $computed['display'] = 'inline-block';
            }

            if ($display === 'inline') {
                $childFirstLine = $firstLine === null
                    ? null
                    : $this->firstLineFor($child, $parentComputed, $firstLine);

                // A float or an out-of-flow box anywhere in the inline subtree
                // is block-level (CSS 2.1 §9.7) and belongs to this block, not
                // to the line. `collectRuns()` hands them back here, and they
                // close the pending line the way a direct floated child does.
                $outerHoisted  = $this->hoisted;
                $this->hoisted = [];

                $childShift = $this->verticalShift($computed, $parentComputed);

                $runs = $this->withHref(
                    $child,
                    fn(): array => $this->collectRuns(
                        $child,
                        $computed,
                        $childFirstLine,
                        $firstLetter,
                        $childShift,
                    ),
                );

                $hoisted       = $this->hoisted;
                $this->hoisted = $outerHoisted;

                foreach ($hoisted as $box) {
                    $box->sourceOrder = ++$sourceOrder;

                    if ($box->isOutOfFlow()) {
                        $deferred[] = ['type' => 'block', 'node' => $box];

                        continue;
                    }

                    // Whether there was inline content before this float
                    // decides where its top may sit, so it is recorded before
                    // the flush that turns that content into a sibling box.
                    // Defect AW.
                    $box->afterInlineContent = $pending !== [];

                    $flush();
                    $groups[] = ['type' => 'block', 'node' => $box];
                }

                foreach ($runs as $run) {
                    $openPending();
                    $pending[] = $run;
                }

                continue;
            }

            $atomic    = $this->isAtomicInline($display, $computed, $blockifies);
            $outOfFlow = !$atomic && $this->isOutOfFlowStyle($computed);

            // Whether inline content came before this child decides both where
            // a float's top may sit and whether the float belongs before the
            // line rather than after it, and the flush below is what makes
            // that unknowable afterwards. Defect AW.
            $afterInline = $pending !== [];
            $floats      = in_array(
                strtolower(trim($computed['float'] ?? 'none')),
                ['left', 'right'],
                true,
            );

            if (!$atomic && !$outOfFlow && !($floats && $afterInline)) {
                $flush();
            }

            /*
             * The block's own first formatted line can be inside a block
             * child, in which case the style belongs to the box that will hold
             * that line rather than here, where there is no line at all.
             *
             * Chrome walks into the first in-flow child that is a block
             * container and stops at one that is not, and it stops there
             * whether or not the walk found a line: an empty first child takes
             * the first line with it and the paragraph after it stays
             * unstyled. Both measured on `RL-first-line-reach.html`.
             */
            $descends = $firstLineOpen
                && ($firstLine !== null || $firstLetter !== null)
                && !$atomic
                && !$outOfFlow
                && !$floats
                && $this->firstLineReaches($display, $computed);

            if ($descends) {
                $this->inheritedFirstLine = $firstLine === null
                    ? null
                    : $this->firstLineFor($child, $parentComputed, $firstLine);

                $this->inheritedFirstLetter = $firstLetter;
                $passedFirstLine            = true;
            }

            // A drop cap belongs to whichever block holds the letter, so the
            // style the hook captures is the inner build's while it runs and
            // this one's again afterwards.
            $outerDropCap = $this->firstLetterFloat;
            $box          = $this->withHref($child, fn(): ?Node => $this->buildBox($child, $computed));

            $this->firstLetterFloat     = $outerDropCap;
            $this->inheritedFirstLine   = null;
            $this->inheritedFirstLetter = null;

            if (!$atomic && !$outOfFlow && !$floats) {
                $firstLineOpen = false;
            }

            if ($box === null) {
                continue;
            }

            // A float that interrupts inline content is emitted before the
            // line rather than after it, so its own flow position is already
            // the line's top and rule 6 needs no correction. Defect AW.
            $isFloat = in_array(
                strtolower(trim($computed['float'] ?? 'none')),
                ['left', 'right'],
                true,
            );

            $box->afterInlineContent = $afterInline && !$isFloat;
            $box->sourceOrder        = ++$sourceOrder;

            if ($isFloat && $afterInline) {
                $inlineFloats[] = $box;

                continue;
            }

            if ($outOfFlow) {
                $deferred[] = ['type' => 'block', 'node' => $box];

                continue;
            }

            // CSS Flexible Box §4 and CSS Grid §6: an item establishes an
            // independent formatting context whatever its own `display` says,
            // so a block inside it keeps its margins to itself. Without this a
            // flex item is an ordinary block to `containsChildMargins()` and
            // the first child's top margin collapses out through the item and
            // is lost: defect DT, 12.000pt of it on `QM-flexitem-margin-bfc`.
            // An out-of-flow child is not an item and is left alone above.
            $box->flowRoot = $box->flowRoot || $blockifies;

            if (!$atomic) {
                $groups[] = ['type' => 'block', 'node' => $box];

                continue;
            }

            $run      = $this->makeRun('', $computed, shift: $this->verticalShift($computed, $parentComputed));
            $run->box = $box;
            $openPending();
            $pending[] = $run;
        }

        $flush();
        $flushOrphans();

        $this->inlineContainer   = $outerContainer;
        $this->firstLineDescended = $passedFirstLine;

        return [...$groups, ...$deferred];
    }

    /**
     * Whether `::first-line` and `::first-letter` reach into this child.
     *
     * Chrome walks down from the block that declares the rule into its first
     * in-flow child while that child is a block container, and stops at one
     * that is not: a table, a flex or grid container, an atomic inline, a
     * float or an out-of-flow box.
     *
     * **It does not stop at an independent formatting context**, which is what
     * this row was recorded as needing for three rounds. Measured on
     * `RL-first-line-reach.html`: an `overflow: hidden` child and a
     * `display: flow-root` child both establish one and Chrome reaches through
     * both, painting the first line inside them.
     *
     * @param array<string,string> $computed
     */
    private function firstLineReaches(string $display, array $computed): bool
    {
        if ($this->isOutOfFlowStyle($computed)) {
            return false;
        }

        if (in_array(strtolower(trim($computed['float'] ?? 'none')), ['left', 'right'], true)) {
            return false;
        }

        return in_array($display, ['block', 'flow-root', 'list-item'], true);
    }

    /**
     * The anonymous box holding one run of inline content.
     *
     * It carries the containing block's own font, which is the line box's
     * strut: CSS gives every line the block's ascent and descent even when
     * nothing on that line has them, and without it a line holding only an
     * image reserves no room below its baseline.
     *
     * @param InlineRun[]          $runs
     * @param array<string,string> $computed
     */
    private function textBox(array $runs, array $computed, DOMElement $el, ?array $firstLine = null): Node
    {
        $node = new Node([
            'display'   => 'text',
            'runs'      => $this->expandRuns($runs),
            'textAlign' => $computed['text-align'] ?? 'left',
            'direction' => $this->direction($computed, $el),
        ]);

        // An anonymous box takes the page name of the block it holds the lines
        // of, the same way it takes that block's font. Leaving it empty made
        // the fragmenter read the first line of a NAMED paragraph as a change
        // back to the ordinary page: the break itself did nothing, because it
        // fell at the top of a page, but the type was reset there and the run
        // then lost both its own sheet and the break at its end.
        $node->pageName = $this->pageName($computed);

        // `text-indent` indents the first line of the block, and the lines
        // live here rather than on the block, so it has to travel the same
        // way `text-align` does.
        $indent = trim($computed['text-indent'] ?? '0');

        $node->textIndent = str_ends_with($indent, '%') && is_numeric(rtrim($indent, '%'))
            ? $indent
            : ($this->styles->length($indent, $this->pt($computed['font-size'] ?? '12'), $this->rootFontSize) ?? 0.0);

        // The strut carries `::first-line` too: CSS 2.1 §5.12.1 styles the
        // fictional element the line's content sits in, and that element is
        // what the block's own font reaches the line as. Without it a first
        // line whose font is larger than its `line-height` is measured against
        // two fonts at once and comes out taller than the one Chrome draws.
        $node->strut = $this->makeRun('', $computed, firstLine: $firstLine);

        /*
         * `::first-line`'s own background fills the fictional tag, which is
         * what the strut stands for, so it rides on the strut's first-line
         * variant and the painter reads it off the line. Chrome fills the
         * line's content and the strut's font box: 147.750 wide by 10.500 tall
         * on a 200px paragraph at 12px, and it follows the line's start under
         * `text-align: right`.
         *
         * The overlay carries the *block's* background where the rule set none
         * of its own, and the block already paints that itself, so a band is
         * only worth drawing where the two differ. That also keeps a
         * translucent block background from being composited twice.
         */
        if (
            $firstLine !== null
            && $node->strut->firstLine !== null
            && ($firstLine['background-color'] ?? '') !== ($computed['background-color'] ?? '')
        ) {
            $band = $this->inlineBox($firstLine);

            if ($band !== null) {
                $node->strut->firstLine->boxes = [$band];
            }
        }

        return $node;
    }

    /**
     * Whether a `display: inline` element is made block-level by its
     * surroundings: by being a flex or grid item, by floating, or by being
     * positioned out of the flow.
     *
     * CSS Display §2.7 blockifies every in-flow child of a flex or grid
     * container, so an inline one is an item of its own rather than part of the
     * anonymous item the text beside it forms. CSS 2.1 §9.7 blockifies a float
     * and an out-of-flow box whatever `display` says, so either leaves the line
     * rather than stretching it with its own glyph.
     *
     * **The container comes first, which is what the flex exception needs.** CSS
     * Flexible Box §4 says `float` has no effect on a flex item, and Chrome
     * agrees: a floated span and a plain one measure the same 27.000 there. Both
     * are blockified as items; neither floats.
     *
     * @param array<string,string> $computed
     */
    private function blockifiedByFlow(array $computed, bool $blockifies): bool
    {
        if ($blockifies) {
            return true;
        }

        if ($this->isOutOfFlowStyle($computed)) {
            return true;
        }

        return in_array(strtolower(trim($computed['float'] ?? 'none')), ['left', 'right'], true);
    }

    /**
     * Whether a computed style takes the box out of the flow entirely.
     *
     * @param array<string,string> $computed
     */
    private function isOutOfFlowStyle(array $computed): bool
    {
        $position = strtolower(trim($computed['position'] ?? 'static'));

        return $position === 'absolute' || $position === 'fixed';
    }

    /**
     * Whether this child belongs on the line beside the text rather than on a
     * line of its own.
     *
     * A float and an out-of-flow box leave the inline flow entirely, whatever
     * their `display` says, so neither is atomic here.
     *
     * @param array<string,string> $computed
     */
    private function isAtomicInline(string $display, array $computed, bool $blockifies): bool
    {
        if ($blockifies) {
            return false;
        }

        if ($display !== '-webkit-inline-box'
            && !in_array($display, ['inline-block', 'inline-flex', 'inline-grid'], true)) {
            return false;
        }

        $position = strtolower(trim($computed['position'] ?? 'static'));

        return strtolower(trim($computed['float'] ?? 'none')) === 'none'
            && $position !== 'absolute'
            && $position !== 'fixed';
    }

    /**
     * Walk an inline subtree, emitting one run per styled text fragment.
     *
     * `$boxes` is the stack of inline boxes this element sits inside, outermost
     * first, and it is handed *down* rather than prepended on the way back up.
     * Both give every run the same stack; only this one gives it to each run
     * once. Prepending re-built the array at every level, which is one array of
     * the level's own depth per run and quadratic in the nesting.
     *
     * @param  list<array<string,mixed>> $boxes
     * @param  list<array<string,mixed>> $firstLineBoxes
     * @return InlineRun[]
     */
    private function collectRuns(
        DOMElement $el,
        array $computed,
        ?array $firstLine = null,
        ?Closure $firstLetter = null,
        float $shift = 0.0,
        array $boxes = [],
        array $firstLineBoxes = [],
    ): array {
        // An inline element never reaches buildBox(), which was the only place
        // counters were applied and the only place `::before` and `::after`
        // were generated. Both were therefore dropped on every inline element:
        // Chrome prints `one «quoted» two` where the engine printed
        // `one quoted two`, and reads 3 across two counting spans where the
        // engine read 1.
        $this->applyCounters($computed, $el);

        $box       = $this->inlineBox($computed);
        $container = $this->inlineContainer;

        if ($box !== null) {
            $boxes[] = $box;

            // The first line may set a font size of its own, and the band is
            // the font box, so the piece painted there is measured against that
            // font rather than against the one the rest of the element uses. It
            // is the same box, so it keeps the same id: the two are never on
            // one line. The two stacks stay the same depth whether or not a
            // first line applies, because the depth is what indexes them.
            $firstLineBoxes[] = $firstLine === null
                ? $box
                : [...$this->inlineBox($firstLine) ?? $box, 'id' => $box['id']];

            if (strtolower(trim($computed['position'] ?? 'static')) === 'relative') {
                $this->inlineContainer = $box['id'];
            }
        }

        $runs   = [];
        $before = $this->enclose(
            $this->pseudoElementRun($el, $computed, 'before', shift: $shift),
            $boxes,
            $firstLineBoxes,
        );

        if ($before !== null) {
            $runs[] = $before;
        }

        foreach ($el->childNodes as $child) {
            if ($child instanceof DOMText) {
                $run = $this->enclose(
                    $this->runFor($child->textContent, $computed, $firstLine, $firstLetter, $shift),
                    $boxes,
                    $firstLineBoxes,
                );

                if ($run !== null) {
                    $runs[] = $run;
                }

                continue;
            }

            if (!$child instanceof DOMElement) {
                continue;
            }

            if (strtolower($child->nodeName) === 'br') {
                $runs[] = $this->enclose(
                    $this->makeRun('', $computed, isBreak: true, firstLine: $firstLine, shift: $shift),
                    $boxes,
                    $firstLineBoxes,
                );

                continue;
            }

            $childComputed = $this->styles->cascade($child, $computed);

            if (($childComputed['display'] ?? 'inline') === 'none') {
                continue;
            }

            // A float or an out-of-flow box nested inside an inline element is
            // block-level all the same, and `partition()` only ever saw a
            // block's direct children: a floated span inside a `<b>` stayed on
            // the line and stretched it to 39.000 where Chrome gives 36.000.
            // Hoisting it here is what routes it to the block path the direct
            // spelling already takes.
            if ($this->blockifiedByFlow($childComputed, false)) {
                $childComputed['display'] = 'block';
                $hoist = $this->withHref($child, fn(): ?Node => $this->buildBox($child, $childComputed));

                if ($hoist !== null) {
                    if ($hoist->isOutOfFlow()) {
                        $hoist->inlineContainer = $this->inlineContainer;
                    }

                    $this->hoisted[] = $hoist;
                }

                continue;
            }

            $childShift = $shift + $this->verticalShift($childComputed, $computed);

            if (($childComputed['display'] ?? 'inline') === 'inline'
                && isset(self::ATOMIC_TAGS[strtolower($child->nodeName)])
            ) {
                $childComputed['display'] = 'inline-block';
            }

            // An atomic inline *nested* inside another inline element: an
            // `<img>` inside an `<a>`, an `inline-block` inside a `<b>`, a form
            // control inside either. Only `partition()` knew about them, so
            // walking in here treated the box as an inline element and emitted
            // its text children as runs: `Aaa <a href="#"><img></a> ccc`
            // reserved no room for the image, painted none of it, and lost the
            // space beside it as well.
            if ($this->isAtomicInline($childComputed['display'] ?? 'inline', $childComputed, false)) {
                $atomic = $this->withHref($child, fn(): ?Node => $this->buildBox($child, $childComputed));

                if ($atomic !== null) {
                    $run      = $this->makeRun('', $childComputed, shift: $childShift);
                    $run->box = $atomic;
                    $runs[]   = $this->enclose($run, $boxes, $firstLineBoxes);
                }

                continue;
            }

            // CSS 2.1 §5.12.1: the fictional `::first-line` tag wraps *outside*
            // the inline elements on the line, so a span that declares a
            // property of its own keeps it and only inherits the rest.
            $childFirstLine = $firstLine === null
                ? null
                : $this->firstLineFor($child, $computed, $firstLine);

            foreach (
                $this->withHref(
                    $child,
                    fn(): array => $this->collectRuns(
                        $child,
                        $childComputed,
                        $childFirstLine,
                        $firstLetter,
                        $childShift,
                        $boxes,
                        $firstLineBoxes,
                    ),
                ) as $r
            ) {
                $runs[] = $r;
            }
        }

        $after = $this->enclose(
            $this->pseudoElementRun($el, $computed, 'after', shift: $shift),
            $boxes,
            $firstLineBoxes,
        );

        if ($after !== null) {
            $runs[] = $after;
        }

        $this->inlineContainer = $container;

        return $runs;
    }

    /**
     * Seat one run inside the inline boxes the walk currently has open.
     *
     * Only the runs this level made: a nested `collectRuns()` seats its own,
     * deeper, and re-seating them here would throw that away.
     *
     * @param  list<array<string,mixed>> $boxes
     * @param  list<array<string,mixed>> $firstLineBoxes
     */
    private function enclose(?InlineRun $run, array $boxes, array $firstLineBoxes): ?InlineRun
    {
        if ($run === null || $boxes === []) {
            return $run;
        }

        $run->boxes = $boxes;

        if ($run->firstLine !== null) {
            $run->firstLine->boxes = $firstLineBoxes;
        }

        return $run;
    }

    /**
     * The rect an inline element paints and reserves room for: its
     * `background-color` band, its `padding` and its `border`, or null where it
     * asks for none of the three.
     *
     * Returning null for a plain element is what keeps a document with no inline
     * decoration in it walking exactly the path it walked before: an empty stack
     * costs no comparison in the line breaker and no rect in the painter.
     *
     * A `background-image` on an inline element is still deliberately not
     * painted. The rect is here now, but the tiling, the origin box and the
     * sliced band a wrapped element would need are the block painter's, and
     * reaching them from a line box is a row of its own.
     *
     * @param  array<string,string> $computed
     * @return array{
     *     id:int,
     *     ink:bool,
     *     color:array{0:float,1:float,2:float,3?:float}|null,
     *     above:float,
     *     below:float,
     *     padTop:float,
     *     padRight:float,
     *     padBottom:float,
     *     padLeft:float,
     *     clone:bool,
     *     border:array<string,array{width:float,style:string,color:array<int,float>}>|null
     * }|null
     */
    private function inlineBox(array $computed): ?array
    {
        $band    = $this->inlineFill($computed);
        $visible = $this->isVisible($computed);
        $size    = $this->pt($computed['font-size'] ?? '12');
        $length  = fn(string $key): ?float => $this->styles->length(
            $computed[$key] ?? '',
            $size,
            $this->rootFontSize,
        );

        // `visibility: hidden` suppresses the ink and nothing else: the padding
        // and the border still take their room on the line, exactly as they do
        // on a block, so they are read whether or not anything is drawn.
        $border  = $this->borderSides($computed, $length, $this->styles->rgba($computed['color'] ?? '#000'));
        $padding = [];
        $padded  = false;

        foreach (['top', 'right', 'bottom', 'left'] as $side) {
            $padding[$side] = max(0.0, $length("padding-$side") ?? 0.0);
            $padded         = $padded || $padding[$side] > 0.0;
        }

        // A `position: relative` inline is the containing block for any
        // out-of-flow box inside it, so it needs a rect whether or not it
        // paints anything: without one, the box resolves against the block and
        // lands 22pt to the left of where Chrome puts it.
        $positioned = strtolower(trim($computed['position'] ?? 'static')) === 'relative';

        // Nothing to paint, nothing to reserve and nothing to contain. The
        // edges still have to be read to know that, but the element does not
        // become a box.
        if ($band === null && $border === null && !$padded && !$positioned) {
            return null;
        }

        $reference = $band ?? $this->fontBand($computed, [0.0, 0.0, 0.0]);

        // CSS Fragmentation §4.2 gives an inline element broken over several
        // lines the same choice a block broken over several pages gets: the
        // initial `slice` puts the left padding and border on the first line
        // and the right pair on the last, and `clone` gives every line all
        // four borders and both paddings. The node carries the same keyword
        // for the block case; an inline element has no node of its own.
        $clone = strtolower(trim(
            $computed['box-decoration-break'] ?? $computed['-webkit-box-decoration-break'] ?? 'slice',
        )) === 'clone';

        return [
            'id'        => ++$this->inlineBoxes,
            'ink'       => $visible,
            'color'     => $band['color'] ?? null,
            'above'     => $reference['above'],
            'below'     => $reference['below'],
            'padTop'    => $padding['top'],
            'padRight'  => $padding['right'],
            'padBottom' => $padding['bottom'],
            'padLeft'   => $padding['left'],
            'clone'     => $clone,
            'border'    => $border,
        ];
    }

    /**
     * `background-color` on an inline element, as the band it paints, or null
     * where the element asks for none.
     *
     * `visibility` is read here rather than in the painter because the band
     * belongs to the element that declared it: a hidden span paints none, which
     * is what Chrome does, and a hidden word *inside* a visible span still has
     * the span's band behind it, which is also what Chrome does.
     *
     * @param  array<string,string> $computed
     * @return array{color:array{0:float,1:float,2:float,3?:float},above:float,below:float}|null
     */
    private function inlineFill(array $computed): ?array
    {
        if (!$this->isVisible($computed)) {
            return null;
        }

        $color = $this->styles->rgba(
            $computed['background-color'] ?? '',
            $this->styles->rgba($computed['color'] ?? '#000'),
        );

        return $color === null ? null : $this->fontBand($computed, $color);
    }

    /**
     * A fill color plus the font box it covers: how far a style's own font
     * reaches above and below the baseline.
     *
     * The band is the font box rather than the line box, measured on
     * `docs/harness/probes/B5-inline-bg.html`: at `font-size: 20px` on a
     * `line-height: 16px` paragraph Chrome fills 18 rows where 12px fills 11.
     * `normalLineHeight()` is the figure this engine's `gap` was fitted to
     * Chrome for, so the box is the metrics already here read one more way,
     * with half of the leading on each side of the font's own ascent and
     * descent exactly as a line box has it.
     *
     * @param  array<string,string> $computed
     * @param  array{0:float,1:float,2:float,3?:float} $color
     * @return array{color:array{0:float,1:float,2:float,3?:float},above:float,below:float}
     */
    private function fontBand(array $computed, array $color): array
    {
        $size = $this->usedFontSize($computed);
        $font = FontRegistry::default()->get(
            $this->family($computed),
            $this->isBold($computed),
            $this->isItalic($computed),
            $this->stretch($computed),
        );
        [$above, $below] = $font->fontBox($size);

        return ['color' => $color, 'above' => $above, 'below' => $below];
    }

    /**
     * Run $fn with $el's href in effect, if it has one, restoring whatever was
     * in effect before. Nested anchors are invalid HTML, but restoring rather
     * than clearing means the inner one simply wins for its own subtree.
     *
     * @template T
     * @param  callable():T $fn
     * @return T
     */
    private function withHref(DOMElement $el, callable $fn): mixed
    {
        if (strtolower($el->nodeName) !== 'a') {
            return $fn();
        }

        $href = trim($el->getAttribute('href'));

        if ($href === '') {
            return $fn();
        }

        $previous          = $this->currentHref;
        $this->currentHref = $href;

        try {
            return $fn();
        } finally {
            $this->currentHref = $previous;
        }
    }

    private function runFor(
        string $text,
        array $computed,
        ?array $firstLine = null,
        ?Closure $firstLetter = null,
        float $shift = 0.0,
    ): ?InlineRun {
        if (trim($text) === '') {
            // Preserve a single separating space between inline elements.
            return $text === '' ? null : $this->makeRun(' ', $computed, firstLine: $firstLine, shift: $shift);
        }

        return $this->makeRun(
            $text,
            $computed,
            firstLine: $firstLine,
            firstLetter: $firstLetter,
            shift: $shift,
        );
    }

    /**
     * Every inline run is built here, so a text property added to InlineRun
     * cannot end up wired into three of the four places that make one.
     *
     * @param array<string,string> $computed
     */
    private function makeRun(
        string $text,
        array $computed,
        bool $isBreak = false,
        ?array $firstLine = null,
        ?Closure $firstLetter = null,
        float $shift = 0.0,
    ): InlineRun {
        $fontSize = $this->pt($computed['font-size'] ?? '12');

        $run = new InlineRun(
            $this->transformText($text, $computed['text-transform'] ?? 'none'),
            $this->usedFontSize($computed),
            $this->isBold($computed),
            $this->styles->rgba($computed['color'] ?? '#000') ?? [0.1, 0.1, 0.1],
            $this->lineHeight($computed),
            $this->family($computed),
            $isBreak,
            $this->isItalic($computed),
            $this->inlineVerticalAlign($computed),
            'auto',
            $this->hyphensMode($computed),
            strtolower(trim($computed['white-space'] ?? 'normal')),
            strtolower(trim($computed['word-break'] ?? 'normal')),
            strtolower(trim($computed['overflow-wrap'] ?? 'normal')),
            $this->decorationLine($computed['text-decoration'] ?? 'none'),
            $this->spacing($computed['letter-spacing'] ?? 'normal', $fontSize),
            $this->spacing($computed['word-spacing'] ?? 'normal', $fontSize),
            $this->currentHref,
            $this->isVisible($computed),
            null,
            ($caps = $this->capsMode($computed)) !== '',
            $caps,
            fontFeatures: $this->fontFeatures($computed),
            fontStretch: $this->stretch($computed),
        );

        $run->baselineShift  = $shift;
        $run->fontSynthesis  = strtolower(trim($computed['font-synthesis'] ?? 'weight style small-caps'));

        $declaredColor = trim($computed['text-decoration-color'] ?? 'currentcolor');

        if (strtolower($declaredColor) !== 'currentcolor') {
            $run->decorationColor = $this->styles->rgba($declaredColor, $run->color);
        }

        $declaredThickness = strtolower(trim($computed['text-decoration-thickness'] ?? 'auto'));

        if ($declaredThickness !== 'auto' && $declaredThickness !== 'from-font') {
            $run->decorationThickness = $this->styles->length($declaredThickness, $fontSize, $this->rootFontSize);
        }

        $declaredStyle = strtolower(trim($computed['text-decoration-style'] ?? 'solid'));

        if (in_array($declaredStyle, self::DECORATION_STYLES, true)) {
            $run->decorationStyle = $declaredStyle;
        }

        $declaredOffset = strtolower(trim($computed['text-underline-offset'] ?? 'auto'));

        if ($declaredOffset !== 'auto') {
            $run->underlineOffset = $this->styles->length($declaredOffset, $fontSize, $this->rootFontSize);
        }

        if ($firstLine !== null) {
            $run->firstLine = $this->makeRun($text, $firstLine, $isBreak, shift: $shift);

            $wanted = strtolower(trim($firstLine['text-transform'] ?? 'none'));

            if ($wanted !== strtolower(trim($computed['text-transform'] ?? 'none'))) {
                $run->firstLine->firstLineTransform = $wanted;
            }
        }

        $letter = $firstLetter === null ? null : $firstLetter($text, $computed);

        if ($letter !== null) {
            $run->firstLetter = $this->makeRun($text, $letter, shift: $shift);
        }

        return $run;
    }

    /**
     * How far `vertical-align` raises an inline element above the baseline it
     * would otherwise sit on, in points, positive upwards.
     *
     * `super` and `sub` are a fraction of the *parent's* font size plus one
     * CSS pixel, which is what Chrome computes: measured on
     * `docs/harness/probes/B9-valign-wide.html`, a span at 24px inside a 12px
     * paragraph is raised the same 5px as one at 6px inside it, and a `<sup>`
     * inside a 20px `<b>` is raised 20/3 + 1 rather than 12/3 + 1. A
     * percentage is of the element's own used `line-height`, and a length is
     * itself.
     *
     * `middle`, `top`, `bottom`, `text-top` and `text-bottom` are deliberately
     * absent and read as no shift at all: none of them is a constant offset
     * from the parent's baseline, and `middle` needs an x-height no face here
     * carries. Defect AF.
     *
     * @param array<string,string> $computed       the element's own style
     * @param array<string,string> $parentComputed the style it sits in
     */
    /**
     * The face a computed style resolves to, for the two metrics
     * `vertical-align` needs before a line box exists.
     *
     * @param array<string,string> $computed
     */
    private function fontFor(array $computed): Font|TrueTypeFont
    {
        return FontRegistry::default()->faceFor($computed);
    }

    private function verticalShift(array $computed, array $parentComputed): float
    {
        $value = strtolower(trim($computed['vertical-align'] ?? 'baseline'));

        if ($value === 'super' || $value === 'sub') {
            $parentSize = $this->pt($parentComputed['font-size'] ?? '12');

            return $value === 'super'
                ? $parentSize / 3.0 + self::CSS_PIXEL
                : -($parentSize / 5.0 + self::CSS_PIXEL);
        }

        // CSS 2.1 §10.8.1's three font-relative keywords. Each is a constant
        // offset once both faces are known, so it is resolved here with
        // `super` and `sub` rather than in the line box: `text-top` aligns the
        // two font boxes at the top, `text-bottom` at the bottom, and `middle`
        // puts the child's own midpoint half an x-height above the parent's
        // baseline. Defect AF, and the x-height it needs is the field the
        // ascent/descent split of round 21 added to the same table.
        //
        // `top` and `bottom` are the two that are **not** constant: they align
        // against the line box, which does not exist until every item on it has
        // been placed, so they still leave here with nothing.
        if ($value === 'middle' || $value === 'text-top' || $value === 'text-bottom') {
            $parent = $this->fontFor($parentComputed);
            $child  = $this->fontFor($computed);
            $ps     = $this->pt($parentComputed['font-size'] ?? '12');
            $cs     = $this->pt($computed['font-size'] ?? '12');

            /*
             * **The two sides of the alignment are two different boxes**, and
             * reading both as the font box is what made these three keywords
             * miss on any face with a line gap. CSS 2.1 section 10.8.1 aligns
             * the ALIGNED SUBTREE, which is the child's own inline box and
             * therefore carries its leading, against the parent's CONTENT
             * AREA, which is the parent's font box and carries none.
             * `SX-face-decoration.html` reads all eight bands off Chrome and
             * the split reproduces every one; on a face whose line gap is zero
             * the two are the same number, which is why DejaVu Sans alone said
             * nothing was wrong.
             */
            [$above, $below] = $child->lineBand($cs, $this->lineHeight($computed) * $cs);

            return match ($value) {
                'text-top'    => $parent->fontBox($ps)[0] - $above,
                'text-bottom' => $below - $parent->fontBox($ps)[1],
                default       => $parent->xHeight($ps) / 2.0 - ($above - $below) / 2.0,
            };
        }

        // The keywords with no constant offset leave before anything is
        // measured, and so does every element that asked for nothing: the
        // initial value here is `top`, because a table cell reads the same
        // property, so this is the path almost every inline element takes.
        if (isset(self::BOX_RELATIVE_ALIGN[$value])) {
            return 0.0;
        }

        $size = $this->pt($computed['font-size'] ?? '12');

        return $this->styles->length(
            $value,
            $size,
            $this->rootFontSize,
            $this->lineHeight($computed) * $size,
        ) ?? 0.0;
    }

    /**
     * How much of the font size a synthesized small capital takes, in percent.
     * Measured against Chrome, which has no real small-cap glyphs for these
     * faces either: `Hamburgefonstiv` at 20px Helvetica is 109.43pt wide
     * there, and a leading capital plus fourteen capitals at 0.700 is 109.43pt.
     *
     * It is written as a percentage rather than as `0.700` so the arithmetic
     * below is exact, see {@see smallCapsSize()}.
     */
    private const int SMALL_CAPS_PERCENT = 70;

    /**
     * The size Chrome draws a synthesized small capital at.
     *
     * The multiplier is 70 percent and the product is then **rounded to a
     * whole CSS pixel**, which is the same snapping Chrome does to a used
     * border width and to `line-height: normal`. Reading the font in force at
     * every text operator on `RN-caps-scale.html` gives 12 sizes from 8px to
     * 48px and `RN-caps-scale-ties.html` the five where the product lands
     * exactly halfway, and all 20 agree: 12px gives 8px rather than 8.4, 24px
     * gives 17 rather than 16.8, and a tie rounds away from zero (5px gives 4,
     * not 3).
     *
     * Without the rounding the size is right on 3 of the 12 and wrong by up to
     * half a pixel on the rest, which is a visible difference at display sizes.
     *
     * **A tie has to be reached exactly or it is not a tie.** `45 * 0.700` in
     * binary is 31.499999999999996 and rounds to 31 where Chrome gives 32;
     * `45 * 70 / 100` is 31.5 on the nose. The engine keeps a font size in
     * points, and a whole number of CSS pixels converts to points and back
     * without loss because the factor is three quarters.
     */
    private function smallCapsSize(float $fontSize): float
    {
        return round($fontSize / 0.75 * self::SMALL_CAPS_PERCENT / 100.0) * 0.75;
    }

    /**
     * Which letters the caps axis shrinks, or `''` for none.
     *
     * Helvetica and the faces this engine subsets carry neither a petite nor a
     * unicase feature, so Chrome synthesizes all of them from the one small-cap
     * scale and the only question is which letters it applies to. Measured on
     * `docs/harness/probes/E13-caps.html`: `petite-caps` renders identically to
     * `small-caps` and `all-petite-caps` to `all-small-caps`, to the thousandth.
     *
     * `titling-caps` is deliberately absent. It asks for a face's titling
     * capitals and Chrome draws the text unchanged without them, which is what
     * an unhandled value already does here.
     *
     * `font-variant` is a shorthand, so the keyword is looked for among its
     * tokens rather than compared against the whole declaration.
     *
     * @param array<string,string> $computed
     */
    private function capsMode(array $computed): string
    {
        $caps = strtolower(trim($computed['font-variant-caps'] ?? 'normal'));

        if ($caps === 'normal') {
            $caps = strtolower(trim($computed['font-variant'] ?? 'normal'));
        }

        $tokens = preg_split('/\s+/', $caps) ?: [];

        foreach (
            [
                'small-caps'        => 'small',
                'petite-caps'       => 'small',
                'all-small-caps'    => 'all-small',
                'all-petite-caps'   => 'all-small',
                'unicase'           => 'unicase',
            ] as $keyword => $mode
        ) {
            if (in_array($keyword, $tokens, true)) {
                return $mode;
            }
        }

        return '';
    }

    /**
     * The OpenType features a run is shaped with, as the sorted `tag=value`
     * string {@see InlineRun::$fontFeatures} carries.
     *
     * Four properties land here and they are read in the order CSS resolves
     * them: the default set first, then the ligature and numeric axes, then
     * `font-kerning`, and `font-feature-settings` last because it is the low
     * level control and overrides every one of them.
     *
     * `font-variant-caps` is deliberately absent. The engine draws small
     * capitals by scaling the capitals it has, which is what Chrome does for a
     * face with no `smcp`, and asking the face for `smcp` as well would apply
     * the shrink twice on a face that carries both.
     *
     * @param array<string,string> $computed
     */
    private function fontFeatures(array $computed): string
    {
        $features = OpenTypeLayout::DEFAULT_FEATURES;

        foreach ($this->variantTokens($computed, 'font-variant-ligatures') as $token) {
            match ($token) {
                'none'                        => $features = ['calt' => 0, 'ccmp' => 1, 'clig' => 0, 'dlig' => 0, 'hlig' => 0, 'kern' => $features['kern'], 'liga' => 0, 'locl' => 1, 'rlig' => 0],
                'common-ligatures'            => $features['liga'] = $features['clig'] = 1,
                'no-common-ligatures'         => $features['liga'] = $features['clig'] = 0,
                'discretionary-ligatures'     => $features['dlig'] = 1,
                'no-discretionary-ligatures'  => $features['dlig'] = 0,
                'historical-ligatures'        => $features['hlig'] = 1,
                'no-historical-ligatures'     => $features['hlig'] = 0,
                'contextual'                  => $features['calt'] = 1,
                'no-contextual'               => $features['calt'] = 0,
                default                       => null,
            };
        }

        foreach ($this->variantTokens($computed, 'font-variant-numeric') as $token) {
            $tag = self::NUMERIC_FEATURES[$token] ?? null;

            if ($tag !== null) {
                $features[$tag] = 1;
            }
        }

        $kerning = strtolower(trim($computed['font-kerning'] ?? 'auto'));

        if ($kerning === 'none') {
            $features['kern'] = 0;
        }

        foreach ($this->featureSettings($computed['font-feature-settings'] ?? 'normal') as $tag => $value) {
            $features[$tag] = $value;
        }

        return OpenTypeLayout::describe($features);
    }

    /** `font-variant-numeric`'s keywords and the feature each one asks the face for. */
    private const array NUMERIC_FEATURES = [
        'lining-nums'         => 'lnum',
        'oldstyle-nums'       => 'onum',
        'proportional-nums'   => 'pnum',
        'tabular-nums'        => 'tnum',
        'diagonal-fractions'  => 'frac',
        'stacked-fractions'   => 'afrc',
        'ordinal'             => 'ordn',
        'slashed-zero'        => 'zero',
    ];

    /**
     * A `font-variant-*` longhand's tokens, falling back to the `font-variant`
     * shorthand exactly as {@see capsMode()} does.
     *
     * @param  array<string,string> $computed
     * @return list<string>
     */
    private function variantTokens(array $computed, string $property): array
    {
        $value = strtolower(trim($computed[$property] ?? 'normal'));

        if ($value === 'normal') {
            $value = strtolower(trim($computed['font-variant'] ?? 'normal'));
        }

        if ($value === 'normal' || $value === '') {
            return [];
        }

        return preg_split('/\s+/', $value) ?: [];
    }

    /**
     * `font-feature-settings`, which is `<string> [<integer> | on | off]?` over
     * a comma list. A tag is four characters and anything else is dropped
     * rather than passed to the face.
     *
     * @return array<string,int>
     */
    private function featureSettings(string $value): array
    {
        $value = trim($value);

        if ($value === '' || strtolower($value) === 'normal') {
            return [];
        }

        $out = [];

        foreach (explode(',', $value) as $entry) {
            if (!preg_match('/^\s*["\']([A-Za-z0-9]{4})["\']\s*(.*)$/', $entry, $m)) {
                continue;
            }

            $setting = strtolower(trim($m[2]));

            $out[$m[1]] = match (true) {
                $setting === '' || $setting === 'on' => 1,
                $setting === 'off'                   => 0,
                default                              => max(0, (int) $setting),
            };
        }

        return $out;
    }

    /**
     * Split a caps run into the pieces that are drawn at full size and the
     * pieces that are drawn as capitals at {@see smallCapsSize()}.
     *
     * Under `small-caps` only a letter with a capital form of its own shrinks,
     * so digits, punctuation and anything already capitalized keep the size the
     * author asked for, which is what makes `MiXeD` come out with two
     * full-height letters. `all-small-caps` shrinks every letter and `unicase`
     * shrinks the capitals instead, which is the same split read the other way
     * round. Whitespace ends a piece so the pieces that continue a word can be
     * told from the ones that start one.
     *
     * **The run and its `::first-line` variant can disagree**, because the
     * pseudo-element may turn the caps on for the first line only or off for
     * it only. The split is then the union of what the two ask for, one piece
     * per pair of answers, and each side of the pair decides its own size.
     * Chrome renders both directions: `RN-firstline-caps.html` `c2` shrinks
     * only on the first line and `c5` is a small-caps paragraph whose first
     * line comes back out full size.
     *
     * Where the two agree, the capitals are the piece's own text, which is
     * what every document without a `::first-line` rule takes. Where they
     * disagree they are a `text-transform` on whichever variant wants them,
     * because the text is shared between the two and the transform is not.
     *
     * @return InlineRun[]
     */
    private function expandSmallCaps(InlineRun $run): array
    {
        $lineCaps = $run->firstLine !== null && $run->firstLine->smallCaps;

        if ((!$run->smallCaps && !$lineCaps) || $run->text === '' || $run->box !== null) {
            return [$run];
        }

        $chars = preg_split('//u', $run->text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $parts = [];

        foreach ($chars as $char) {
            $lower = mb_strtolower($char) === $char && mb_strtoupper($char) !== $char;
            $upper = mb_strtoupper($char) === $char && mb_strtolower($char) !== $char;

            $shrinks = static fn(string $mode): bool => match ($mode) {
                'all-small' => $lower || $upper,
                'unicase'   => $upper,
                default     => $lower,
            };

            $space = preg_match('/^\s$/u', $char) === 1;
            $base  = !$space && $run->smallCaps && $shrinks($run->capsMode);
            $line  = !$space && $lineCaps && $shrinks((string) $run->firstLine?->capsMode);
            $class = $space ? 'space' : ($base ? 'b' : '') . ($line ? 'l' : '') . '-';

            if ($parts !== [] && $parts[count($parts) - 1][0] === $class) {
                $parts[count($parts) - 1][1] .= $char;

                continue;
            }

            $parts[] = [$class, $char, $base, $line];
        }

        $runs     = [];
        $previous = 'space';

        foreach ($parts as [$class, $text, $base, $line]) {
            // A piece whose two variants want the same capitals can carry them
            // in its text; one whose variants disagree cannot, because both
            // read the same text.
            $bake = $run->firstLine === null || $base === $line;

            $piece                = clone $run;
            $piece->text          = $bake && $base ? mb_strtoupper($text) : $text;
            $piece->fontSize      = $base ? $this->smallCapsSize($run->fontSize) : $run->fontSize;
            $piece->smallCaps     = $base;
            $piece->joinsPrevious = $previous !== 'space' && $class !== 'space';

            if (!$bake && $base) {
                $piece->firstLineTransform = 'uppercase';
            }

            if ($run->firstLine !== null) {
                $variant                = clone $run->firstLine;
                $variant->fontSize      = $line
                    ? $this->smallCapsSize($run->firstLine->fontSize)
                    : $run->firstLine->fontSize;
                $variant->smallCaps     = $line;
                $variant->joinsPrevious = $piece->joinsPrevious;
                $piece->firstLine       = $variant;

                if (!$bake && $line) {
                    $variant->firstLineTransform = 'uppercase';
                }
            }

            $runs[]   = $piece;
            $previous = $class;
        }

        return $runs === [] ? [$run] : $runs;
    }

    /**
     * @param  InlineRun[] $runs
     * @return InlineRun[]
     */
    private function expandRuns(array $runs): array
    {
        $out = [];

        foreach ($runs as $run) {
            foreach ($this->expandSmallCaps($run) as $piece) {
                $out[] = $piece;
            }
        }

        return $out;
    }

    /**
     * `border-radius` as four corners, in CSS order, each one a horizontal
     * half and a vertical half.
     *
     * The shorthand takes one to four values and fills the missing corners
     * from the opposite one, and each corner may also be set on its own. A
     * percentage resolves against the box, which is not known yet, so it is
     * carried as a fraction of the width and refused rather than silently
     * zeroed. The single-value case has to keep producing exactly what the
     * old scalar did, or every rounded box in the suites moves.
     *
     * **The `/` form is elliptical and both halves are kept.** The vertical
     * list is read exactly like the horizontal one, from its own one to four
     * values, and a corner set on its own takes the same two-value form. A
     * PDF corner is a Bezier curve and an ellipse is that curve with a
     * different control distance on each axis, so nothing here has to be
     * approximated. Defect GL.
     *
     * @param  array<string,string> $computed
     * @return list<array{0:float,1:float}>
     */
    private function cornerRadii(array $computed, float $fs, Node $node): array
    {
        $length = fn(string $raw): ?float => $this->styles->length($raw, $fs, $this->rootFontSize, null);

        $parts = preg_split('/\s+/', trim($computed['border-radius'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $slash = array_search('/', $parts, true);

        $horizontal = self::fourCorners(
            array_map(fn(string $p): float => $length($p) ?? 0.0, $slash === false ? $parts : array_slice($parts, 0, $slash)),
        );

        // With no `/` on it the one list answers both axes, which is a circle.
        $vertical = $slash === false
            ? $horizontal
            : self::fourCorners(
                array_map(fn(string $p): float => $length($p) ?? 0.0, array_slice($parts, $slash + 1)),
            );

        $corners = [
            [$horizontal[0], $vertical[0]],
            [$horizontal[1], $vertical[1]],
            [$horizontal[2], $vertical[2]],
            [$horizontal[3], $vertical[3]],
        ];

        foreach ([
            'border-top-left-radius'     => 0,
            'border-top-right-radius'    => 1,
            'border-bottom-right-radius' => 2,
            'border-bottom-left-radius'  => 3,
        ] as $prop => $slot) {
            $halves = preg_split('/\s+/', trim($computed[$prop] ?? ''), -1, PREG_SPLIT_NO_EMPTY) ?: [];

            if ($halves === []) {
                continue;
            }

            $own = $length($halves[0]);

            if ($own === null) {
                continue;
            }

            // One value on a corner is a circle; two are the two halves.
            $corners[$slot] = [$own, count($halves) > 1 ? ($length($halves[1]) ?? $own) : $own];
        }

        return $corners;
    }

    /**
     * The `border-radius` shorthand's own one-to-four fill, which the vertical
     * list after a `/` obeys exactly as the horizontal list before it does.
     *
     * @param  list<float> $values
     * @return array{0:float,1:float,2:float,3:float}
     */
    private static function fourCorners(array $values): array
    {
        return match (count($values)) {
            1       => [$values[0], $values[0], $values[0], $values[0]],
            2       => [$values[0], $values[1], $values[0], $values[1]],
            3       => [$values[0], $values[1], $values[2], $values[1]],
            0       => [0.0, 0.0, 0.0, 0.0],
            default => [$values[0], $values[1], $values[2], $values[3]],
        };
    }

    /** @param array<string,string> $computed */
    private function isVisible(array $computed): bool
    {
        $value = strtolower(trim($computed['visibility'] ?? 'visible'));

        return $value !== 'hidden' && $value !== 'collapse';
    }

    /**
     * The element's own content with its `::before` and `::after` folded in.
     * Generated content is inline, so it joins the neighboring inline group
     * rather than becoming a box of its own.
     *
     * @param  array<string,string>  $computed
     * @return array<int,array{type:string,runs?:InlineRun[],node?:Node}>
     */
    private function generatedContent(
        DOMElement $el,
        array $computed,
        ?array $firstLine = null,
        ?Closure $firstLetter = null,
    ): array {
        // CSS 2.1 §12.4: `::before` is the element's first child and `::after`
        // its last, so each reads the counters in effect at its own point in
        // the walk. Building both after the subtree gave a `::before` the value
        // its own descendants had left behind: 2 where Chrome prints 0.
        $before = $this->pseudoElementRun($el, $computed, 'before', $firstLine);
        $groups = $this->partition($el, $computed, $firstLine, $firstLetter);
        $after  = $this->pseudoElementRun($el, $computed, 'after', $firstLine);

        if ($before !== null) {
            $groups = $this->prependRun($groups, $before);
        }

        if ($after !== null) {
            $groups = $this->appendRun($groups, $after);
        }

        return $groups;
    }

    /**
     * What `::first-line` is allowed to change.
     *
     * CSS 2.1 §5.12.1 restricts the pseudo-element to a property set, and this
     * is that set narrowed to what an inline run actually carries. Anything
     * else on the rule is ignored rather than let through: a `width` there
     * would otherwise reach the run and resize a word, and a `font-variant`
     * would set a flag the run was not split for.
     */
    private const array FIRST_LINE_PROPERTIES = [
        'background-color'     => true,
        'color'                => true,
        'font'                 => true,
        'font-family'          => true,
        'font-size'            => true,
        'font-style'           => true,
        'font-variant'         => true,
        'font-variant-caps'    => true,
        'font-variant-ligatures' => true,
        'font-variant-numeric' => true,
        'font-kerning'         => true,
        'font-feature-settings' => true,
        'font-weight'          => true,
        'letter-spacing'       => true,
        'word-spacing'         => true,
        'line-height'          => true,
        'text-decoration'      => true,
        'text-decoration-line' => true,
        'text-transform'       => true,
        'vertical-align'       => true,
    ];

    /**
     * What `::first-letter` is allowed to change, narrowed to what an inline
     * run carries plus the two that make a drop cap: `float` takes the letter
     * out of the line, and the margins hold the text off it.
     */
    private const array FIRST_LETTER_PROPERTIES = [
        'color'                => true,
        'float'                => true,
        'font'                 => true,
        'font-family'          => true,
        'font-size'            => true,
        'font-style'           => true,
        'font-variant'         => true,
        'font-variant-caps'    => true,
        'font-variant-ligatures' => true,
        'font-variant-numeric' => true,
        'font-kerning'         => true,
        'font-feature-settings' => true,
        'font-weight'          => true,
        'letter-spacing'       => true,
        'word-spacing'         => true,
        'line-height'          => true,
        'margin'               => true,
        'margin-top'           => true,
        'margin-right'         => true,
        'margin-bottom'        => true,
        'margin-left'          => true,
        'padding'              => true,
        'padding-top'          => true,
        'padding-right'        => true,
        'padding-bottom'       => true,
        'padding-left'         => true,
        'text-decoration'      => true,
        'text-decoration-line' => true,
        'text-transform'       => true,
        'vertical-align'       => true,
    ];

    /**
     * A hook that styles the first run able to hold the block's first letter,
     * or null where no `::first-letter` rule reaches it.
     *
     * It fires once, on the first run with something other than white space in
     * it, so a block that declares the pseudo-element pays one cascade rather
     * than one per run. Unlike `::first-line`, the fictional tag goes *inside*
     * the innermost inline, so what it declares wins over the span around it
     * and a `font-size: 200%` resolves against that span's size. Measured:
     * Chrome paints the first letter of a blue span in the pseudo-element's
     * color, and resolves its percentage against the span.
     *
     * @param array<string,string> $computed
     */
    private function firstLetterHook(DOMElement $el, array $computed): ?Closure
    {
        $declared = $this->styles->declaredProperties($el, 'first-letter');

        if ($declared === []) {
            return null;
        }

        $used = false;

        return function (string $text, array $runComputed) use (&$used, $el, $declared): ?array {
            if ($used || trim($text) === '') {
                return null;
            }

            $pseudo  = $this->styles->cascade($el, $runComputed, 'first-letter');
            $overlay = $runComputed;
            $applied = false;

            foreach ($declared as $prop => $_) {
                if (!isset(self::FIRST_LETTER_PROPERTIES[$prop]) || !isset($pseudo[$prop])) {
                    continue;
                }

                $overlay[$prop] = $pseudo[$prop];
                $applied        = true;
            }

            if (!$applied) {
                return null;
            }

            // The box a floated letter becomes is built from the pseudo-element's
            // own cascade rather than from the overlay: there every property the
            // rule did not set is at its initial value, so the paragraph's width,
            // margins and background stay on the paragraph instead of being
            // inherited onto a letter.
            $used                   = true;
            $this->firstLetterFloat = $pseudo;

            return $overlay;
        };
    }

    /**
     * The float a `::first-letter { float: left }` becomes.
     *
     * It is an ordinary floated block holding one line, so nothing downstream
     * has to know where it came from: the fragmenter, the painter and the
     * float machinery all see a float, and the lines beside it are shortened
     * by the same code that shortens them beside an image.
     *
     * @param array<string,string> $style
     */
    private function dropCap(InlineRun $letter, array $style, DOMElement $el): Node
    {
        $text = $this->textBox([$letter], $style, $el);
        $node = $this->styleBox(new Node(['display' => 'block'], [$text]), $style, $el);

        // The block already owns the id, and two boxes answering to it would
        // make a link to it land on whichever the painter reached first. The
        // role goes with it: a drop cap is one letter of a paragraph, not a
        // structure of its own.
        $node->anchorId     = null;
        $node->outlineLevel = 0;
        $node->outlineTitle = '';
        $node->altText      = '';
        $node->role         = '';

        return $node;
    }

    /**
     * The first letter of the first formatted line, with the punctuation in
     * front of it.
     *
     * CSS 2.1 §5.12.2 takes any preceding punctuation along, which is what
     * puts an opening quotation mark inside the drop cap instead of leaving it
     * stranded above one.
     */
    private function leadingLetter(string $text): string
    {
        return preg_match('/^\s*[\p{P}\p{S}]*./u', $text, $m) === 1 ? $m[0] : '';
    }

    /**
     * Split the first letter out of a group's runs into a run of its own.
     *
     * @param  InlineRun[] $runs
     * @return array{0:InlineRun[],1:?InlineRun}
     */
    private function splitFirstLetter(array $runs): array
    {
        foreach ($runs as $i => $run) {
            if ($run->firstLetter === null || $run->box !== null || trim($run->text) === '') {
                continue;
            }

            $letter = $this->leadingLetter($run->text);

            if ($letter === '') {
                break;
            }

            $head       = $run->firstLetter;
            $head->text = $letter;
            $rest       = mb_substr($run->text, mb_strlen($letter));

            $tail       = clone $run;
            $tail->text = $rest;

            array_splice($runs, $i, 1, $rest === '' ? [$head] : [$head, $tail]);

            return [$runs, $head];
        }

        return [$runs, null];
    }

    /**
     * The first-line style a child inherits from its parent's.
     *
     * Everything descends the ordinary way, so a span that sets its own color
     * or font keeps it. `text-transform` does not: Chrome applies the one
     * `::first-line` asks for to the whole line, over a descendant's own, and
     * that is measured rather than read off the spec. The condition is what
     * keeps it from also overriding a descendant when `::first-line` never
     * mentioned the property.
     *
     * @param  array<string,string> $computed  the parent's own style
     * @param  array<string,string> $firstLine the parent's first-line style
     * @return array<string,string>
     */
    private function firstLineFor(DOMElement $child, array $computed, array $firstLine): array
    {
        $inherited = $this->styles->cascade($child, $firstLine);

        if (($firstLine['text-transform'] ?? null) !== ($computed['text-transform'] ?? null)) {
            $inherited['text-transform'] = $firstLine['text-transform'] ?? 'none';
        }

        return $inherited;
    }

    /**
     * The style the block's first line is written in, or null where no
     * `::first-line` rule reaches it.
     *
     * It is returned as a whole computed map rather than as the declarations,
     * because it is handed down as the *inherited* style of everything on that
     * line: the fictional tag wraps outside the inline elements, so a span
     * that sets its own color keeps it.
     *
     * @param  array<string,string> $computed
     * @return array<string,string>|null
     */
    private function firstLineStyle(DOMElement $el, array $computed): ?array
    {
        $declared = $this->styles->declaredProperties($el, 'first-line');

        if ($declared === []) {
            return null;
        }

        $pseudo  = $this->styles->cascade($el, $computed, 'first-line');
        $overlay = $computed;
        $applied = false;

        foreach ($declared as $prop => $_) {
            if (!isset(self::FIRST_LINE_PROPERTIES[$prop]) || !isset($pseudo[$prop])) {
                continue;
            }

            $overlay[$prop] = $pseudo[$prop];
            $applied        = true;
        }

        return $applied ? $overlay : null;
    }

    /**
     * @param  array<string,string>       $computed
     * @param  array<string,string>|null  $firstLine the block's first-line style, where one reaches this run
     */
    private function pseudoElementRun(
        DOMElement $el,
        array $computed,
        string $which,
        ?array $firstLine = null,
        float $shift = 0.0,
    ): ?InlineRun {
        if (!$this->styles->declaresPseudoElement($which)) {
            return null;
        }

        $pseudo = $this->styles->cascade($el, $computed, $which);

        if (($pseudo['display'] ?? 'inline') === 'none') {
            return null;
        }

        $content = trim($pseudo['content'] ?? 'normal');

        // `none` and `normal` generate no pseudo-element at all, so its own
        // `counter-increment` does not count either. Measured: Chrome reads 0
        // where the same rule with `content: ""` reads 5.
        if (in_array(strtolower($content), ['', 'none', 'normal'], true)) {
            return null;
        }

        $this->applyCounters($pseudo, $el, $which);

        $text = $this->generatedText($el, $content, $which);

        if ($text === null) {
            return null;
        }

        // Generated content sits *inside* the fictional `::first-line` tag, so
        // it takes the line's styling exactly as a `<span>` in the same place
        // does. Building it against `$computed` alone left it the block's own
        // color: Chrome paints all 512 ink pixels of the line in the
        // first-line color where the engine painted 469 and left the
        // generated 44 black. A `::before` that sets its own color keeps it,
        // which is the same 467 / 44 split the `<span>` control gives.
        $run = $this->makeRun('', $pseudo, firstLine: $firstLine === null
            ? null
            : $this->styles->cascade($el, $firstLine, $which), shift: $shift);

        $run->text = $text;

        return $run;
    }

    /**
     * Apply this element's or pseudo-element's `counter-reset`,
     * `counter-increment` and `counter-set`, in CSS Lists §4.5's order, which
     * is that one whatever order the declarations are written in: Chrome reads
     * `counter-increment: n 2; counter-set: n 9` and `counter-set: n 9;
     * counter-increment: n 2` alike as 9.
     *
     * A counter name is an identifier and identifiers are case-sensitive, so
     * only the `none` keyword is matched without regard to case. Lowercasing
     * the whole value gave `counter-reset: U1 9` a counter this document can
     * never read back, because `counter(U1)` keeps its own case.
     *
     * @param array<string,string> $computed
     */
    private function applyCounters(array $computed, DOMNode $owner, string $pseudo = ''): void
    {
        $declared = [];

        foreach (['reset', 'increment', 'set'] as $kind) {
            $value = trim($computed["counter-{$kind}"] ?? 'none');

            if ($value !== '' && strtolower($value) !== 'none') {
                $declared[$kind] = $value;
            }
        }

        if ($declared === []) {
            return;
        }

        $this->enterCounterScope($owner, $pseudo);

        foreach ($declared as $kind => $value) {
            $tokens = preg_split('/\s+/', $value) ?: [];

            for ($i = 0; $i < count($tokens); $i++) {
                $name = $tokens[$i];

                if ($name === '' || is_numeric($name)) {
                    continue;
                }

                $number = isset($tokens[$i + 1]) && preg_match('/^-?\d+$/', $tokens[$i + 1]) === 1
                    ? (int) $tokens[++$i]
                    : ($kind === 'increment' ? 1 : 0);

                match ($kind) {
                    'reset'     => $this->instantiateCounter($name, $number, $owner, $pseudo),
                    'increment' => $this->incrementCounter($name, $number),
                    default     => $this->setCounter($name, $number, $owner, $pseudo),
                };
            }
        }
    }

    /**
     * Drop every counter instance this element or pseudo-element sits outside
     * the scope of.
     *
     * **Measured against Chrome rather than read off CSS 2.1 §12.4.1**, whose
     * "and the element's following siblings" wording Chrome does not
     * implement: an instance reaches the element that reset it and that
     * element's descendants, and a following sibling reads whatever instance
     * encloses them both instead. What keeps a document numbering across
     * sibling subtrees is the outermost instance, which is never dropped: the
     * value of the outermost one that does go out of scope carries into the
     * document-wide instance CSS instantiates implicitly.
     *
     * A pseudo-element's own instance reaches nothing but itself, which is why
     * `::before` resetting a counter leaves `::after` reading the outer one.
     */
    private function enterCounterScope(DOMNode $owner, string $pseudo): void
    {
        if ($this->counters === []) {
            return;
        }

        $ancestors = [];

        for ($node = $owner; $node !== null; $node = $node->parentNode) {
            $ancestors[spl_object_id($node)] = true;
        }

        foreach ($this->counters as $name => $stack) {
            $dropped = null;

            while ($stack !== []) {
                $frame = $stack[count($stack) - 1];

                if ($this->counterInScope($frame, $ancestors, $owner, $pseudo)) {
                    break;
                }

                $dropped = array_pop($stack);
            }

            $this->counters[$name] = $stack === [] && $dropped !== null
                ? [['owner' => null, 'pseudo' => '', 'value' => $dropped['value']]]
                : $stack;
        }
    }

    /**
     * @param array{owner:?DOMNode,pseudo:string,value:int} $frame
     * @param array<int,true>                               $ancestors
     */
    private function counterInScope(array $frame, array $ancestors, DOMNode $owner, string $pseudo): bool
    {
        if ($frame['owner'] === null) {
            return true;
        }

        if ($frame['pseudo'] !== '') {
            return $frame['owner'] === $owner && $frame['pseudo'] === $pseudo;
        }

        return isset($ancestors[spl_object_id($frame['owner'])]);
    }

    /** CSS 2.1 §12.4.1: `counter-reset` instantiates a counter in this element's scope. */
    private function instantiateCounter(string $name, int $value, DOMNode $owner, string $pseudo): void
    {
        $stack = $this->counters[$name] ?? [];
        $top   = $stack === [] ? null : $stack[count($stack) - 1];
        $frame = ['owner' => $owner, 'pseudo' => $pseudo, 'value' => $value];

        // The document-wide instance, and one this element already created, are
        // taken over rather than nested inside. Nesting either is what would
        // give two sibling subtrees resetting the same counter one value too
        // many to join, which Chrome does not print.
        $takeOver = $top !== null
            && ($top['owner'] === null || ($top['owner'] === $owner && $top['pseudo'] === $pseudo));

        if ($takeOver) {
            array_pop($stack);
        }

        $stack[]               = $frame;
        $this->counters[$name] = $stack;
    }

    /**
     * CSS Lists §4.4: `counter-set` sets the instance already in scope, and
     * instantiates one on this element only when there is none.
     *
     * **Measured against Chrome**, which names every part of it. A set inside
     * an outer `counter-reset` leaves `counters()` one level deep and its value
     * outlives the setting element, where a `counter-reset` written in the same
     * place nests and does not: `F9-counter-set-scope.html` reads `s3a=8` and
     * `s1b=8` against the control `c3b=1`. A set with nothing in scope creates
     * an instance a nested reset then nests *inside*, so
     * `F10-counter-set-edges.html` reads `t1=8.2` and not `2`, which is what
     * says the created instance is this element's rather than the document's.
     */
    private function setCounter(string $name, int $value, DOMNode $owner, string $pseudo): void
    {
        $stack = $this->counters[$name] ?? [];

        if ($stack === []) {
            $this->instantiateCounter($name, $value, $owner, $pseudo);

            return;
        }

        $stack[count($stack) - 1]['value'] = $value;
        $this->counters[$name]             = $stack;
    }

    /**
     * CSS 2.1 §12.4.3: a counter with no `counter-reset` in scope behaves as
     * though one had reset it to zero, and that instance is the document's.
     */
    private function incrementCounter(string $name, int $by): void
    {
        $stack = $this->counters[$name] ?? [];

        if ($stack === []) {
            $stack = [['owner' => null, 'pseudo' => '', 'value' => 0]];
        }

        $stack[count($stack) - 1]['value'] += $by;
        $this->counters[$name]              = $stack;
    }

    /**
     * What `counter()` and `counters()` print: the innermost instance alone, or
     * every instance in scope joined outermost first by the separator.
     */
    private function counterText(string $name, string $style, ?string $separator): string
    {
        $stack  = $this->counters[$name] ?? [['owner' => null, 'pseudo' => '', 'value' => 0]];
        $values = $separator === null
            ? [$stack[count($stack) - 1]['value']]
            : array_column($stack, 'value');

        return implode($separator ?? '', array_map(
            fn(int $value): string => $this->counterValue($value, $style),
            $values,
        ));
    }

    /** One counter value in the style `counter()` asked for. */
    private function counterValue(int $value, string $style): string
    {
        return match (strtolower(trim($style))) {
            'upper-roman'                    => $this->roman($value),
            'lower-roman'                    => strtolower($this->roman($value)),
            'upper-alpha', 'upper-latin'     => $this->alphabetic($value, true),
            'lower-alpha', 'lower-latin'     => $this->alphabetic($value, false),
            'decimal-leading-zero'           => str_pad((string) $value, 2, '0', STR_PAD_LEFT),
            'none'                           => '',
            default                          => (string) $value,
        };
    }

    /**
     * The `content` value as literal text. Anything the engine cannot
     * evaluate, such as `url()`, is skipped rather than printed as its own
     * source.
     */
    private function generatedText(DOMElement $el, string $content, string $pseudo): ?string
    {
        $text     = '';
        $produced = false;
        $scoped   = false;

        foreach ($this->contentTokens($content) as $token) {
            if (preg_match('/^"(.*)"$|^\'(.*)\'$/s', $token, $m)) {
                $text     .= $this->unescapeContent($m[1] !== '' ? $m[1] : ($m[2] ?? ''));
                $produced = true;
                continue;
            }

            if (preg_match('/^attr\(\s*([\w-]+)\s*\)$/i', $token, $m)) {
                $text     .= $el->getAttribute($m[1]);
                $produced = true;
                continue;
            }

            if (preg_match('/^counters?\(\s*([\w-]+)\s*(?:,(.*))?\)$/is', $token, $m)) {
                $rest = trim($m[2] ?? '');

                $arguments = $rest === ''
                    ? []
                    : array_map(static fn(string $a): string => trim($a, " \t\"'"), explode(',', $rest));

                // `counters()` takes a separator first and the style second;
                // `counter()` takes only a style and reads the innermost
                // instance, so a null separator is what distinguishes them.
                $joins = str_starts_with(strtolower($token), 'counters(');
                $style = $joins
                    ? ($arguments[1] ?? 'decimal')
                    : ($arguments[0] ?? 'decimal');

                if (!$scoped) {
                    $this->enterCounterScope($el, $pseudo);
                    $scoped = true;
                }

                $text     .= $this->counterText($m[1], $style, $joins ? ($arguments[0] ?? '') : null);
                $produced = true;
                continue;
            }

            // url() and the open-quote family are not supported.
        }

        return $produced ? $text : null;
    }

    /** @return string[] */
    private function contentTokens(string $content): array
    {
        preg_match_all('/"(?:\\\\.|[^"\\\\])*"|\'(?:\\\\.|[^\'\\\\])*\'|[\w-]+\([^)]*\)|\S+/', $content, $m);

        return $m[0];
    }

    /** CSS escapes a code point as `\XXXX`, which is how `content: "\2022"` works. */
    private function unescapeContent(string $text): string
    {
        $text = preg_replace_callback(
            '/\\\\([0-9a-fA-F]{1,6})\s?/',
            static fn(array $m): string => mb_chr((int) hexdec($m[1]), 'UTF-8') ?: '',
            $text,
        ) ?? $text;

        return str_replace(['\\A', '\\a'], "\n", $text);
    }

    /**
     * @param  array<int,array{type:string,runs?:InlineRun[],node?:Node}>  $groups
     * @return array<int,array{type:string,runs?:InlineRun[],node?:Node}>
     */
    private function prependRun(array $groups, InlineRun $run): array
    {
        // Only a *leading* run of inline content can take it. Joining the first
        // inline group past a block-level sibling put the generated content
        // behind that block instead of in front of the element: Chrome reads
        // `[B] b1 tail` where the engine read `b1 [B]tail`.
        if (($groups[0]['type'] ?? '') === 'inline') {
            array_unshift($groups[0]['runs'], $run);

            return $groups;
        }

        return [['type' => 'inline', 'runs' => [$run]], ...$groups];
    }

    /**
     * @param  array<int,array{type:string,runs?:InlineRun[],node?:Node}>  $groups
     * @return array<int,array{type:string,runs?:InlineRun[],node?:Node}>
     */
    private function appendRun(array $groups, InlineRun $run): array
    {
        $last = count($groups) - 1;

        // Only a *trailing* run of inline content can take it, for the mirror
        // of the reason above: an element whose children are all blocks used to
        // hand its `::after` to the group its `::before` had just created, so
        // both printed in front of everything.
        if ($last >= 0 && $groups[$last]['type'] === 'inline') {
            $groups[$last]['runs'][] = $run;

            return $groups;
        }

        return [...$groups, ['type' => 'inline', 'runs' => [$run]]];
    }

    /**
     * The automatic half of `list-item`, which CSS Lists §4 gives every list
     * and every list item without either of them declaring anything.
     *
     * A list instantiates the counter at `start - 1`, so its first item's
     * increment lands on `start`, and `reversed` counts the other way. An item
     * increments it by one **unless it declares an increment of its own**,
     * which replaces it rather than adding to it: Chrome numbers
     * `counter-increment: list-item 2` on two items **2** and **4**, not 3 and
     * 6. `<li value>` is a set and is applied before the element's own
     * `counter-set`, so a declaration still wins over the attribute.
     *
     * @param array<string,string> $computed
     */
    private function applyListItemCounter(DOMElement $el, array $computed): void
    {
        /*
         * Drop the frames this element is no longer inside before reading or
         * advancing anything. `applyCounters()` does it too, but it runs after
         * this method, and by then a nested list's own instance is still on
         * top of the stack: the item after a sublist increments the sublist's
         * counter instead of its own, and the whole rest of the outer list
         * numbers from there. Defect IX.
         */
        $this->enterCounterScope($el, '');

        $tag = strtolower($el->nodeName);

        if (in_array($tag, ['ol', 'ul', 'menu'], true)) {
            $start = $el->hasAttribute('start')
                ? (int) $el->getAttribute('start')
                : ($el->hasAttribute('reversed') ? $this->listItemCount($el) + 1 : 1);

            $this->instantiateCounter(self::LIST_ITEM, $start - 1, $el, '');

            return;
        }

        if (!self::isListItem($computed)) {
            return;
        }

        $parent   = $el->parentNode;
        $reversed = $parent instanceof DOMElement && $parent->hasAttribute('reversed');

        if (!$this->declaresCounter($computed, 'increment', self::LIST_ITEM)) {
            $this->incrementCounter(self::LIST_ITEM, $reversed ? -1 : 1);
        }

        if ($el->hasAttribute('value')) {
            $this->setCounter(self::LIST_ITEM, (int) $el->getAttribute('value'), $el, '');
        }
    }

    /** How many list items a list holds, which is where `reversed` counts from. */
    private function listItemCount(DOMElement $list): int
    {
        $count = 0;

        foreach ($list->childNodes as $child) {
            if ($child instanceof DOMElement && strtolower($child->nodeName) === 'li') {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Whether a computed style names one counter in one of the three
     * properties, which is what decides if the automatic increment is replaced.
     *
     * @param array<string,string> $computed
     */
    private function declaresCounter(array $computed, string $kind, string $name): bool
    {
        $value = trim($computed["counter-{$kind}"] ?? 'none');

        if ($value === '' || strtolower($value) === 'none') {
            return false;
        }

        return in_array($name, preg_split('/\s+/', $value) ?: [], true);
    }

    /**
     * The `::marker` for a list item, or null when this element is not one.
     * The counter is the item's position among its list-item siblings, which
     * is what `<ol>` numbering means before CSS counters exist.
     *
     * @param array<string,string> $computed
     */
    private function listMarker(DOMElement $el, array $computed): ?InlineRun
    {
        if (!self::isListItem($computed)) {
            return null;
        }

        $type = strtolower(trim($computed['list-style-type'] ?? 'disc'));

        $parent = $el->parentNode;
        $ordered = $parent instanceof DOMElement && strtolower($parent->nodeName) === 'ol';

        if ($type === 'disc' && $ordered) {
            $type = 'decimal';
        }

        if ($type === 'none') {
            return null;
        }

        // The number is the `list-item` counter's, which
        // {@see applyListItemCounter} has already advanced for this element.
        // Counting siblings here is what defect AS was: it could not see
        // `counter-set`, `counter-increment` or `<li value>`.
        //
        // The scope is narrowed again first, because a marker is made after
        // this item's own children are built: an item holding a sublist would
        // otherwise read the sublist's instance, which is sitting on top of
        // the stack by then, and print its last number. Defect IX.
        $this->enterCounterScope($el, '');

        $stack = $this->counters[self::LIST_ITEM] ?? [];
        $index = $stack === [] ? 1 : $stack[count($stack) - 1]['value'];

        $run = $this->makeRun('', $computed);
        $run->text = $this->markerText($type, $index, $run) . ' ';

        if ($run->text === ' ') {
            return null;
        }

        $run->listMarker = true;

        $run->markerShape = match ($type) {
            'disc', 'circle', 'square' => $type,
            default                    => null,
        };

        /*
         * An `inside` marker joins the line, and a browser draws these three
         * shapes there exactly as it draws them beside it: `SR-marker-inside.html`
         * reads the same 7 by 7 bullet on j0 and j1. So the run keeps no text
         * of its own in either spelling, and it takes the advance its shape
         * and its font ask for rather than the one a face's bullet glyph
         * happens to have. {@see InlineRun::markerMetrics}.
         */
        if ($run->markerShape !== null && $this->markerIsInside($computed)) {
            $run->text = '';
        }

        return $run;
    }

    /**
     * Whether this box is a list item, which is the one thing that decides
     * whether it gets a marker and whether it advances the `list-item` counter.
     *
     * **It is the computed `display` and never the tag name.** Defect FT was
     * three callers each asking whether the ELEMENT was an `<li>`, which CSS
     * Lists section 4 does not ask anywhere: a marker belongs to a box whose
     * computed `display` is `list-item`. The two are the same thing only until
     * an author writes a `display` of their own, and then they disagree in both
     * directions at once. `SU-marker-display.html`, six of its ten bands:
     *
     *     an <li> at display: block, inline-block, or an <ol>'s at block
     *         Chrome draws nothing and this engine drew a bullet
     *     a <div> or a <p> at display: list-item, with a shape or a picture
     *         Chrome draws a marker and this engine drew none
     *
     * The UA sheet says `li { display: list-item }` for it, where it used to
     * say `block`. While it said `block` there was nothing for a caller to
     * read: an author's override and the engine's own default computed to the
     * same value, so no predicate over `display` could have told them apart and
     * the tag name was the only thing left to ask.
     *
     * @param array<string,string> $computed
     */
    private static function isListItem(array $computed): bool
    {
        return strtolower(trim($computed['display'] ?? '')) === 'list-item';
    }

    /** @param array<string,string> $computed */
    private function markerIsInside(array $computed): bool
    {
        return strtolower(trim($computed['list-style-position'] ?? 'outside')) === 'inside';
    }

    /**
     * The first text box in document order under these children, which is the
     * line a list marker hangs beside when the item's own content is a block.
     *
     * **A box that already carries a marker is skipped rather than taken.** A
     * nested list is built before the item holding it, so its own items have
     * their markers already, and taking one would overwrite an inner bullet
     * rather than add an outer one.
     *
     * @param Node[] $children
     */
    private static function firstTextBox(array $children, int $depth = 0): ?Node
    {
        if ($depth > self::MAX_MARKER_DEPTH) {
            return null;
        }

        foreach ($children as $child) {
            if ($child->display === 'text' && $child->marker === null && $child->markerImage === null) {
                return $child;
            }

            $found = self::firstTextBox($child->children, $depth + 1);

            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    /**
     * `list-style-image` on a list item, resolved to a layer and a box.
     *
     * Returns null when the element is not an item, when nothing is declared,
     * or when what is declared does not resolve: a `url()` naming a file that
     * is not there falls back to the type's own marker, which is Chrome's
     * answer on `RM-list-image.html` `s7` and is what happens by itself here,
     * because the text marker is only suppressed once this returns something.
     *
     * The size is the source's own where it has one and **0.45em square**
     * where it has not, which is measured rather than read off CSS Images:
     * the specification gives a marker image with no intrinsic size a 1em
     * default object size, Chrome uses neither that nor half of it, and
     * fourteen font sizes from 8px to 64px agree on 0.45em to within the
     * whole pixel Chrome rounds to.
     *
     * @param array<string,string> $computed
     * @return array{layer:array{image:?PdfImage,svg:?SvgDocument,gradient:?array},width:float,height:float}|null
     */
    private function listMarkerImage(DOMElement $el, array $computed): ?array
    {
        if (!self::isListItem($computed)) {
            return null;
        }

        $declared = trim($computed['list-style-image'] ?? 'none');

        if ($declared === '' || strtolower($declared) === 'none') {
            return null;
        }

        $layer = $this->backgroundSource($declared, $this->styles->rgba($computed['color'] ?? '#000'));

        if ($layer === null) {
            return null;
        }

        $default = $this->pt($computed['font-size'] ?? '12') * 0.45;

        [$width, $height] = match (true) {
            $layer['image'] !== null => [$layer['image']->width * 0.75, $layer['image']->height * 0.75],
            $layer['svg'] !== null && $layer['svg']->hasIntrinsicSize
                                     => [$layer['svg']->width * 0.75, $layer['svg']->height * 0.75],
            default                  => [$default, $default],
        };

        return $width <= 0.0 || $height <= 0.0
            ? null
            : ['layer' => $layer, 'width' => $width, 'height' => $height];
    }

    /**
     * Bullets that WinAnsi cannot encode would paint as `?` on the base-14
     * fonts, so those degrade to one it can rather than to a question mark.
     */
    private function markerText(string $type, int $index, InlineRun $run): string
    {
        $embedded = $run->font() instanceof TrueTypeFont;

        return match ($type) {
            'disc'                 => '•',
            'circle'               => $embedded ? '◦' : 'o',
            'square'               => $embedded ? '▪' : '•',
            'decimal'              => $index . '.',
            'decimal-leading-zero' => str_pad((string) $index, 2, '0', STR_PAD_LEFT) . '.',
            'lower-alpha', 'lower-latin' => $this->alphabetic($index, false) . '.',
            'upper-alpha', 'upper-latin' => $this->alphabetic($index, true) . '.',
            'lower-roman'          => strtolower($this->roman($index)) . '.',
            'upper-roman'          => $this->roman($index) . '.',
            default                => '',
        };
    }

    private function alphabetic(int $index, bool $upper): string
    {
        $out = '';

        for ($n = max(1, $index); $n > 0; $n = intdiv($n - 1, 26)) {
            $out = chr(ord('a') + ($n - 1) % 26) . $out;
        }

        return $upper ? strtoupper($out) : $out;
    }

    private function roman(int $index): string
    {
        if ($index < 1 || $index > 3999) {
            return (string) $index;
        }

        $numerals = [
            1000 => 'M', 900 => 'CM', 500 => 'D', 400 => 'CD',
            100  => 'C', 90  => 'XC', 50  => 'L', 40  => 'XL',
            10   => 'X', 9   => 'IX', 5   => 'V', 4   => 'IV', 1 => 'I',
        ];

        $out = '';

        foreach ($numerals as $value => $numeral) {
            while ($index >= $value) {
                $out   .= $numeral;
                $index -= $value;
            }
        }

        return $out;
    }

    private function transformText(string $text, string $transform): string
    {
        return InlineRun::transform($text, strtolower(trim($transform)));
    }

    /** The five values `text-decoration-style` takes. */
    private const array DECORATION_STYLES = ['solid', 'double', 'dotted', 'dashed', 'wavy'];

    /**
     * `display: -webkit-box` is the old flexbox and Chrome still lays it out as
     * a flex container, which is defect EQ: the engine mapped it to `block`,
     * where a child's adjoining margins collapse. `RX-clamp-margin.html`'s m0
     * declares **no clamp at all** and reads 63.000pt here against Chrome's
     * 72.000 for exactly that reason, two 12px paragraph margins collapsing to
     * one where the browser keeps both.
     *
     * The value matters because it is the only spelling Chrome 151 applies
     * `-webkit-line-clamp` on, so every document that clamps a card writes it.
     */
    private static function isWebkitBox(string $display): bool
    {
        return $display === '-webkit-box' || $display === '-webkit-inline-box';
    }

    /**
     * Whether an element's `display: -webkit-box` is laid out as a flex
     * container, which it is **unless a clamp is in force on it**.
     *
     * That split is Chrome's and it is measured on both sides rather than
     * reasoned about. On `RX-clamp-margin.html` the unclamped m0 puts its two
     * paragraphs 18.000pt apart, which is both their margins, and the clamped
     * m1 puts them 9.000pt apart, which is one collapsed margin: the same
     * declaration lays out as the old flexbox with no clamp on it and as an
     * ordinary block container with one.
     */
    private static function isWebkitFlex(array $computed): bool
    {
        if (! self::isWebkitBox(strtolower(trim($computed['display'] ?? 'block')))) {
            return false;
        }

        return ! ctype_digit(trim($computed['-webkit-line-clamp'] ?? 'none'))
            || strtolower(trim($computed['-webkit-box-orient'] ?? 'horizontal')) !== 'vertical';
    }

    /**
     * The line the `text-decoration` shorthand asks for. The style, the color
     * and the thickness reach the run through their own longhands, which
     * `StyleResolver` expands the shorthand into, so only the line keyword is
     * read here.
     */
    private function decorationLine(string $value): string
    {
        foreach (preg_split('/\s+/', strtolower(trim($value))) ?: [] as $token) {
            if (in_array($token, ['underline', 'line-through', 'overline'], true)) {
                return $token;
            }
        }

        return 'none';
    }

    private function spacing(string $value, float $fontSize): float
    {
        $value = strtolower(trim($value));

        if ($value === '' || $value === 'normal') {
            return 0.0;
        }

        return $this->styles->length($value, $fontSize, $this->rootFontSize) ?? 0.0;
    }

    /** An inline <svg> element is re-serialized and handed to the renderer. */
    private function buildInlineSvg(DOMElement $el, array $computed): ?Node
    {
        $markup = $this->styleInlineSvg($el);
        $doc    = $markup === null ? null : SvgDocument::parse($markup);

        if ($doc === null) {
            return null;
        }

        $node      = new Node(['display' => 'rect']);
        $node->svg = $doc;

        /*
         * An inline `<svg>` is an element in the page, so the four font
         * properties inherit into it and on into every `<text>` inside. The
         * size goes over in CSS pixels because that is what a user unit is:
         * the viewBox scales it from there, exactly as it scales a size the
         * SVG declares itself. Defect DY.
         */
        $doc->inheritFont([
            'font-family' => $this->family($computed),
            'font-size'   => (string) ($this->pt($computed['font-size'] ?? '12pt') / 0.75),
            'font-weight' => $this->isBold($computed) ? 'bold' : 'normal',
            'font-style'  => $this->isItalic($computed) ? 'italic' : 'normal',
        ]);

        $this->styleBox($node, $computed, $el);
        [$iw, $ih, $sized] = $this->svgIntrinsics($doc);
        $this->applyIntrinsicSize($node, $iw, $ih, $el, false, $sized);

        return $node;
    }

    /**
     * The `<svg>` element as markup, carrying every declaration a rule in the
     * page's own stylesheet makes for an element inside it.
     *
     * `SvgDocument` reads presentation attributes and `style=""` and knows
     * nothing about selectors, so `svg text { font-family: Helvetica }`
     * reached nothing at all. That is defect DZ, and the row sized it as a
     * cascade pass over a second DOM that would have to be matched up with
     * this one. **There is only one DOM.** The cascade runs on the element the
     * page already holds, and its answer travels across as the string the
     * reader already parses, so there is nothing to match up: a copy is
     * serialized, the page's own tree is never touched, and no declaration is
     * carried that {@see SvgDocument::STYLE_PROPERTIES} cannot use.
     *
     * The element's own `style=""` is written last, so it still wins. A
     * presentation attribute loses to a rule, which is what CSS Style
     * Attributes asks for: its specificity is zero.
     *
     * **The copy is `cloneNode()` and not `importNode()`.** Importing a node
     * that already belongs to the document hands back that same node, so the
     * declarations would land in the page's own tree: a second builder pass
     * would then read them as an inline `style` attribute, which beats every
     * rule, and the answer would change between passes.
     */
    private function styleInlineSvg(DOMElement $el): ?string
    {
        $document = $el->ownerDocument;
        $copy     = $el->cloneNode(true);

        if ($document === null || !$copy instanceof DOMElement) {
            return null;
        }

        $this->carryRulesInto($el, $copy, 0);
        $markup = $document->saveXML($copy);

        return $markup === false ? null : $markup;
    }

    /**
     * Walk the element and its copy together and write what the cascade says
     * onto the copy.
     *
     * They are the same tree in the same order, so the pairing is positional
     * and nothing is looked up. The depth cap is the usual one: an SVG subtree
     * is author input like any other.
     */
    private function carryRulesInto(DOMElement $from, DOMElement $to, int $depth): void
    {
        if ($depth > self::MAX_SVG_STYLE_DEPTH) {
            return;
        }

        $this->carryRules($from, $to);

        $originals = [];

        foreach ($from->childNodes as $child) {
            if ($child instanceof DOMElement) {
                $originals[] = $child;
            }
        }

        $index = 0;

        foreach ($to->childNodes as $child) {
            if (!$child instanceof DOMElement) {
                continue;
            }

            if (isset($originals[$index])) {
                $this->carryRulesInto($originals[$index], $child, $depth + 1);
            }

            $index++;
        }
    }

    /** Put one element's own winning declarations into the copy's `style`. */
    private function carryRules(DOMElement $from, DOMElement $to): void
    {
        $declared = $this->styles->declaredProperties($from, null);

        if ($declared === []) {
            return;
        }

        $computed = $this->styles->cascade($from, []);

        foreach (SvgDocument::STYLE_PROPERTIES as $property) {
            if (!isset($declared[$property])) {
                continue;
            }

            $value = $this->svgValue($property, $computed);

            if ($value === null) {
                continue;
            }

            /*
             * Written as a PRESENTATION ATTRIBUTE rather than into a `style`
             * attribute, which is the other half of bullet 82. Every property
             * in `SvgDocument::STYLE_PROPERTIES` is one, and `styleOf()` reads
             * them all; a `style` attribute is a `;`-separated list, so a value
             * carrying one was cut in half by the reader's own `explode(';')`
             * and had to be dropped instead. An attribute has no separator in
             * it, and DOM escapes a `"` on the way out, so neither character is
             * a problem any more.
             *
             * The precedence is the same either way. `styleOf()` reads
             * presentation attributes first and the element's own `style` over
             * them, so the element's own inline declaration still wins, which
             * is what putting the carried rules FIRST in one attribute used to
             * arrange; and a carried rule still beats the element's own
             * presentation attribute, because it overwrites it.
             */
            $to->setAttribute($property, $value);
        }
    }

    /**
     * One declaration in the spelling an SVG reader expects, or null where it
     * cannot survive the trip.
     *
     * **The guard runs on the value that is about to be written**, not on the
     * one the cascade handed over, and that is the whole of defect HG. A
     * `font-family` is quoted in most stylesheets, because a name with a space
     * in it has to be, so `"DejaVu Sans", sans-serif` carries a `"` and the
     * declaration was dropped where the same rule written without the quotes
     * was carried. What travels for that property is
     * {@see FontRegistry::resolveFamily}'s answer, which is ONE name with the
     * quotes already off it, so there is nothing left for the guard to catch:
     * `TS-svg-family.html` reads 9 of 9 against Chrome where the pre-round
     * engine reads 6, and the three it missed are its three double-quoted
     * slots.
     *
     * @param array<string,string> $computed
     */
    private function svgValue(string $property, array $computed): ?string
    {
        $value = trim((string) ($computed[$property] ?? ''));

        if ($value === '') {
            return null;
        }

        // A user unit is a CSS pixel and the cascade hands `font-size` back in
        // points, which is the same conversion {@see SvgDocument::inheritFont}
        // is given at the root.
        if ($property === 'font-size') {
            $value = (string) ($this->pt($value) / 0.75);
        }

        if ($property === 'font-family') {
            $value = $this->family($computed);
        }

        return $value;
    }

    /**
     * What an SVG file offers the box model: a size, a ratio, or neither.
     *
     * A `width` and a `height` on the root element are an intrinsic size. A
     * `viewBox` alone is a ratio and no size, which {@see Node::$ratioFill}
     * spells out. **Neither** is CSS Images §5.2's default object size,
     * 300x150px, which is Chrome's answer on `OC-svg-viewbox-ratio.html` `c9`:
     * 225.000 x 112.500 for an `<svg>` with nothing on it at all.
     *
     * @return array{0:float,1:float,2:bool} width, height, and whether that
     *                                       pair is an intrinsic size
     */
    private function svgIntrinsics(SvgDocument $doc): array
    {
        if (!$doc->hasIntrinsicSize && !$doc->hasViewBox) {
            return [300.0, 150.0, true];
        }

        return [$doc->width, $doc->height, $doc->hasIntrinsicSize];
    }

    /**
     * Resolve width/height against an intrinsic size, preserving the ratio.
     *
     * `$replaced` is what separates an `<img>` from an inline `<svg>`, and
     * Chrome draws the line hard: an image's content is the picture, so the
     * picture's size is its min-content size and a flex line cannot squeeze it
     * below that; an inline `<svg>` is a document fragment that scales, has no
     * min-content size at all, and shrinks to 75.082 in the same 150pt row
     * even when it declares `width: 180pt`.
     */
    private function applyIntrinsicSize(
        Node $node,
        float $iw,
        float $ih,
        DOMElement $el,
        bool $replaced = true,
        bool $sized = true,
    ): void {
        // Recorded before a declaration can overwrite `width`, because it is
        // the box's min-content size and a flex item's automatic minimum size
        // is the smaller of that and whatever was declared.
        if ($replaced && $sized && $iw > 0 && $ih > 0) {
            $node->intrinsicWidth  = $iw * 0.75;
            $node->intrinsicHeight = $ih * 0.75;

            // Read before this method resolves either axis, so it records what
            // the author wrote rather than what the file supplied.
            $node->autoIntrinsicWidth  = $node->width === null && !$el->hasAttribute('width');
            $node->autoIntrinsicHeight = $node->height === null && !$el->hasAttribute('height');
        }

        /*
         * `box-sizing` governs a declared size, not the file's own. `width` is
         * read back through `toBorderBoxWidth()`, which hands a `border-box`
         * value straight back, so the file's size has to be written as the
         * border box or the edges come off the picture instead of sitting
         * outside it: under `* { box-sizing: border-box }` Chrome makes a 240px
         * image with a 4px border 186.000 wide and this engine made it 180.000.
         */
        $edges = $node->boxSizing === 'border-box';
        $fileW = $iw * 0.75 + ($edges ? $node->edgeMain(true) : 0.0);
        $fileH = $ih * 0.75 + ($edges ? $node->edgeCross(true) : 0.0);

        $w = is_float($node->width) ? $node->width : null;
        $h = is_float($node->height) ? $node->height : null;

        // A dimension attribute is a length the document chose and nothing
        // parsed it through `clampLength()`, so it was the one length in the
        // engine that could exceed `max_length`: `<img width="99999999">` asked
        // for a 75,000,000pt box. Clamped like every other, which is a safety
        // control rather than a sizing rule and bites nothing a document means.
        if ($w === null && $el->hasAttribute('width')) {
            $w = min($node->maxLength, (float) $el->getAttribute('width') * 0.75);
        }

        if ($h === null && $el->hasAttribute('height')) {
            $h = min($node->maxLength, (float) $el->getAttribute('height') * 0.75);
        }

        /*
         * A `viewBox` on its own is a ratio and not a size (CSS Images §4), so
         * a box that declares neither axis has no intrinsic size to lay out
         * with: `width: auto` fills the containing block and the ratio answers
         * for the height. `OC-svg-viewbox-ratio.html` `c0` is 300.000 x 300.000
         * in a 300pt block and `c1` 150.000 x 150.000 in a 150pt one, where
         * reading the viewBox as points made both of them 180.000 x 180.000
         * (defect BG). One declared axis is enough to leave it: `c6`
         * (`width: 120px`) and `c7` (`height: 120px`) are 90.000 x 90.000 on
         * both engines and always were.
         */
        if (!$sized && $w === null && $h === null && $iw > 0 && $ih > 0
            && !is_string($node->width) && !is_string($node->height)
        ) {
            $node->aspectRatio     ??= $iw / $ih;
            $node->ratioFill         = true;
            $node->replacedContent   = $replaced;
            $node->width             = null;
            $node->height            = null;

            return;
        }

        // A specified `aspect-ratio` outranks the file's own proportions: the
        // ratio is the preferred one and the intrinsic size only survives as
        // the width to apply it to.
        $ratio = match (true) {
            $node->aspectRatio !== null => 1.0 / $node->aspectRatio,
            $iw > 0 && $ih > 0          => $ih / $iw,
            default                     => null,
        };

        /*
         * A percentage is not a number until layout knows the containing
         * block, and neither is a size a `min-` or `max-` constraint may still
         * move, so the axis that follows from either cannot be resolved here.
         * Carrying the file's own proportions as an `aspect-ratio` hands both
         * axes to the leaf path, which finishes the job once the used width is
         * settled. Without it the second axis fell back to the intrinsic size
         * in points, and `width: 50%` or `max-width: 100%` painted an image at
         * its full height and a fraction of its width.
         */
        $undecided = is_string($node->width)
            || $node->declaredMinWidth !== null
            || $node->declaredMaxWidth !== null
            || $node->declaredMinHeight !== null
            || $node->declaredMaxHeight !== null;

        if ($undecided && $ratio !== null) {
            $node->aspectRatio ??= 1.0 / $ratio;

            if (!is_string($node->width)) {
                $node->width = $w ?? ($h === null ? $fileW : null);
            }

            // Null is `auto`, which is what a percentage height amounts to
            // here: the ratio answers for it once the width is settled, and
            // resolving it against a containing block that has no definite
            // height of its own would collapse the image to nothing.
            $node->height = $h;

            return;
        }

        if ($ratio !== null) {
            if ($w !== null && $h === null) {
                $h = $w * $ratio;
            } elseif ($h !== null && $w === null) {
                $w = $h / $ratio;
            } elseif ($w === null && $h === null) {
                $w = $fileW;
                $h = $node->aspectRatio !== null ? $w * $ratio : $fileH;
            }
        }

        if (!is_string($node->width)) {
            $node->width = $w ?? 0.0;
        }

        $node->height = $h ?? 0.0;
    }

    /**
     * Make this control an interactive field, where it can be one.
     *
     * A control with no `name` gets nothing: there would be no way to fill it
     * or to read it back, and a box that claims to be addressable and is not is
     * worse than the same box with no field over it.
     *
     * The control's value ink goes with it. Everything a field draws lives in
     * the widget's appearance stream rather than in the page's content stream,
     * so filling the field replaces what is shown instead of adding a second
     * copy over it, and the text child a control carries its value in is told
     * which control it belongs to here.
     *
     * @param array<int,array{0:string,1:string}> $options
     * @param string[]                            $selected
     */
    private function attachField(
        Node $node,
        DOMElement $el,
        FormFieldType $type,
        string $value = '',
        array $options = [],
        array $selected = [],
    ): void {
        $name = trim($el->getAttribute('name'));

        if ($name === '') {
            return;
        }

        $maxLength = null;

        if ($type->fieldType() === 'Tx' && ctype_digit(trim($el->getAttribute('maxlength')))) {
            $maxLength = min(self::MAX_CONTROL_COUNT, (int) trim($el->getAttribute('maxlength')));
        }

        /*
         * PDF has no disabled. Read-only is the nearest thing it does have and
         * it keeps the visible half of what the attribute means, which is that
         * nobody may type into the box. What it does not keep is the other
         * half, that the control submits nothing.
         */
        $node->formField = new FormField(
            type: $type,
            name: $name,
            value: $value,
            checked: $el->hasAttribute('checked'),
            export: $this->exportValue($el, $type),
            options: $options,
            selected: $selected,
            multiSelect: $el->hasAttribute('multiple'),
            readOnly: $el->hasAttribute('readonly') || $el->hasAttribute('disabled'),
            required: $el->hasAttribute('required'),
            maxLength: $maxLength,
            tooltip: $this->fieldTooltip($el),
        );

        foreach ($node->children as $child) {
            $child->formOwner = $node;
        }
    }

    /**
     * What a reader says about a field when someone reaches it, which PDF
     * spells `/TU` and calls the alternate description.
     *
     * `aria-label` first, because it exists to say exactly this; then `title`,
     * which is a browser's own tooltip; then `placeholder`, which is the only
     * thing many templates give a control at all. A `name` is not a fallback:
     * `customer_vat_no` is an identifier and reading it out is worse than
     * silence.
     */
    private function fieldTooltip(DOMElement $el): string
    {
        foreach (['aria-label', 'title', 'placeholder'] as $attribute) {
            $value = trim($el->getAttribute($attribute));

            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    /**
     * A checkbox or radio's on-state name.
     *
     * `Yes` is what every PDF tool writes for a checkbox and what a reader's
     * own interface offers, and an HTML checkbox with no `value` has no export
     * value worth carrying: the implicit `on` a browser submits says nothing to
     * anyone reading the PDF. A radio does need its own, because two buttons in
     * one group that export the same name are one state rather than two, so an
     * unnamed one takes a number instead.
     */
    private function exportValue(DOMElement $el, FormFieldType $type): string
    {
        if (!$type->isToggle()) {
            return 'Yes';
        }

        $value = trim($el->getAttribute('value'));

        if ($value !== '') {
            return $value;
        }

        return $type === FormFieldType::Radio ? 'Choice' . ++$this->radioStates : 'Yes';
    }

    /** How many radio buttons have been given a state name of their own. */
    private int $radioStates = 0;

    /**
     * `<input>` as a printed control.
     *
     * It rendered as literally nothing before: no box, no value, so a filled
     * form printed blank. There is no interactivity to reproduce in a PDF, so
     * what a print needs is what the control *shows*: a box the size a
     * browser gives it, carrying its current value. Checkboxes and radios are
     * a small square with a mark when checked; everything text-like is a box
     * as wide as its `size` attribute, which is what decides the width in a
     * browser too.
     *
     * @param array<string,string> $computed
     */
    private function buildInput(DOMElement $el, array $computed): ?Node
    {
        $type = strtolower(trim($el->getAttribute('type') ?: 'text'));

        if ($type === 'hidden' || ($computed['display'] ?? '') === 'none') {
            return null;
        }

        $fs = $this->pt($computed['font-size'] ?? '11px');

        if ($type === 'checkbox' || $type === 'radio') {
            $box = new Node(['display' => 'inline-block']);
            $this->styleBox($box, $computed, $el);

            // 13 CSS pixels square, border included, which is what a browser
            // draws. The declared size is content-box here, so the edges the
            // UA sheet adds come back off it.
            $side        = 9.75;
            $box->width  = max(0.0, $side - $box->edgeMain(true));
            $box->height = max(0.0, $side - $box->edgeMain(false));

            if ($type === 'radio') {
                $box->borderRadius = array_fill(0, 4, [$side / 2, $side / 2]);
            }

            // A checked control is painted as a filled box rather than a
            // glyph: the mark has to survive a face that cannot encode one.
            if ($el->hasAttribute('checked')) {
                $box->checkMark = $this->styles->rgba('#333333') ?? [0.2, 0.2, 0.2, 1.0];
            }

            $this->attachField($box, $el, $type === 'radio' ? FormFieldType::Radio : FormFieldType::Checkbox);

            return $box;
        }

        $value = $el->getAttribute('value');

        if ($type === 'password' && $value !== '') {
            $value = str_repeat("\u{2022}", mb_strlen($value));
        }

        if ($type === 'submit' && $value === '') {
            $value = 'Submit';
        }

        if ($type === 'reset' && $value === '') {
            $value = 'Reset';
        }

        // A control's content box is one line tall whatever is in it, and the
        // line is the empty one its own font makes. Dropping the text child
        // when there is no value left the box as tall as its border and
        // padding and nothing else: an `<input>` with no value was 3.000
        // against Chrome's 15.750 (`ON-form-control.html` `n0`). The line has
        // to be a **content** height rather than a declared one, or the
        // control stops stretching to a flex line's cross size, which Chrome
        // does do (`n3`, 84.000).
        $runs = [$this->makeRun($value, $computed)];

        $node = new Node(['display' => 'inline-block'], [$this->textBox($runs, $computed, $el)]);
        $this->styleBox($node, $computed, $el);

        /*
         * A button's only purpose in PDF is the action it runs, and this engine
         * writes none, so `submit`, `reset`, `button` and `image` keep the box
         * they already draw and get no field. Everything else that draws as a
         * text box becomes one, which is the same rule the drawing uses: an
         * unknown `type` is a text input in HTML and it is a text field here.
         */
        if (!in_array($type, self::BUTTON_TYPES, true)) {
            $this->attachField(
                $node,
                $el,
                $type === 'password' ? FormFieldType::Password : FormFieldType::Text,
                $el->getAttribute('value'),
            );
        }

        // `size` is a count of characters, which is the `ch` unit: the advance
        // of "0" in the control's own face. It is a **content** width, and
        // `usedWidth()` adds the box's own edges to a declared one, so adding
        // them here as well counted the border and padding twice:
        // `<input size="20">` came out 120.172 wide against Chrome's 117.750
        // where the two faces differ by a tenth of that (`ON-form-control.html`
        // `n1`).
        if (!is_string($node->width) && ($node->width === null || $node->width <= 0.0)) {
            $size        = max(1, (int) ($el->getAttribute('size') ?: 20));
            $zero        = FontRegistry::default()->get($node->fontFamily, $node->bold, $node->italic, $node->fontStretch);
            $node->width = $size * $zero->stringWidth('0', $fs);
        }

        // The line is a floor and not a declared height, because a control does
        // stretch to a flex line's cross size in Chrome (`n3`, 84.000), and a
        // declared height would stop it. An author's own `min-height` wins.
        if ($node->minHeight === null) {
            $node->minHeight = $node->lineHeight * $node->fontSize + $node->edgeCross(true);
        }

        /*
         * CSS Flexible Box §4.5: the **content size suggestion** of a replaced
         * element is its intrinsic size. A form control is replaced, and this
         * engine draws its value with an ordinary text child, so the child's
         * min-content was answering for the control and a flex line squeezed
         * the box onto its own value: `<input size="20" value="hello">` in a
         * 150pt row was 61.499 wide against Chrome's 117.750 (`n3`), which is
         * the width its `size` asks for whether or not the line has room.
         */
        if (is_float($node->width)) {
            $node->replacedContent = true;
            $node->intrinsicWidth  = $node->width;
        }

        if (is_float($node->height)) {
            $node->intrinsicHeight = $node->height;
        }

        return $node;
    }

    /**
     * `<textarea>` as a printed control: `cols` by `rows`, with its text in it.
     *
     * It had no size of its own at all before, so an empty one was its border
     * and padding and nothing else, 4.500 x 3.000 against Chrome's 127.500 x
     * 27.000. `cols` is a count of characters exactly as `<input size>` is, and
     * `rows` a count of lines; both are **content** sizes, because `usedWidth()`
     * and `usedHeight()` add the box's own edges to a declared one.
     *
     * The scrollbar gutter is the part that is not arithmetic. Chrome reserves
     * one whether or not the text overflows, and it is what the remaining 16
     * pixels of every measurement are: `cols="10"` is 76.500 and `cols="40"`
     * 257.250, which is 6.000pt a column on both and 16.500 left over.
     *
     * @param array<string,string> $computed
     */
    private function buildTextArea(DOMElement $el, array $computed): Node
    {
        $fs   = $this->pt($computed['font-size'] ?? '12');
        $runs = [$this->makeRun($el->textContent, $computed)];
        $node = new Node(['display' => 'inline-block'], [$this->textBox($runs, $computed, $el)]);
        $this->styleBox($node, $computed, $el);
        $this->attachField($node, $el, FormFieldType::Multiline, $el->textContent);

        // Both are a count the document chose multiplied by a font size it also
        // chose, which is how round 12's `aspect-ratio` got past `max_length`.
        // The count is capped and the product is clamped, as every other
        // resolved length in this engine is.
        if (!is_string($node->width) && ($node->width === null || $node->width <= 0.0)) {
            $cols        = $this->countAttribute($el, 'cols', 20);
            $zero        = FontRegistry::default()->get($node->fontFamily, $node->bold, $node->italic, $node->fontStretch);
            $node->width = min($node->maxLength, $cols * $zero->stringWidth('0', $fs) + self::CONTROL_GUTTER);
        }

        if (!is_string($node->height) && ($node->height === null || $node->height <= 0.0)) {
            $node->height = min(
                $node->maxLength,
                $this->countAttribute($el, 'rows', 2) * $node->lineHeight * $node->fontSize,
            );
        }

        // CSS Flexible Box §4.5 again, and this control is the exception to it:
        // a `<textarea>` scrolls, and an item whose computed `overflow` is not
        // `visible` has an automatic minimum size of **zero**. So it is the one
        // form control a flex line may squeeze below the size it asks for, and
        // Chrome does squeeze it, to 86.203 in a 150pt row (`OU` `uf`) where it
        // leaves an `<input>` at its full 117.750 (`ON` `n3`).
        return $this->replacedControl($node, false);
    }

    /**
     * `<select>` as a printed control: the option that is showing, in a box as
     * wide as the widest one.
     *
     * A closed `<select>` shows exactly one option, and this engine drew every
     * one of them stacked, so a two-option list was two lines tall (`OU` `ua`,
     * 26.994 against Chrome's 14.250) and as wide as its **first** option
     * rather than its widest. The width is the widest because that is the box
     * the control needs whichever option is showing, which is what Chrome
     * measures too.
     *
     * @param array<string,string> $computed
     */
    private function buildSelect(DOMElement $el, array $computed): Node
    {
        $fs       = $this->pt($computed['font-size'] ?? '12');
        $rows     = $this->countAttribute($el, 'size', 1);
        $labels   = [];
        $showing  = [];
        $options  = [];
        $selected = [];

        foreach ($el->getElementsByTagName('option') as $option) {
            if (count($labels) >= self::MAX_OPTIONS) {
                break;
            }

            $labels[] = $label = trim(preg_replace('/\s+/u', ' ', $option->textContent) ?? '');

            // An `<option>` with no `value` submits its own text, which is
            // what makes the pair worth writing rather than the label alone.
            $options[] = [$option->hasAttribute('value') ? $option->getAttribute('value') : $label, $label];

            if ($option->hasAttribute('selected')) {
                $showing    = [$label];
                $selected[] = $options[count($options) - 1][0];
            }
        }

        /*
         * A closed `<select>` with nothing selected shows and submits its first
         * option; an open one selects nothing at all and shows as many rows as
         * it was asked for. The drawing already followed that rule and the
         * field follows it too, so what a reader offers is what the paper says.
         */
        $isCombo = $rows <= 1 && !$el->hasAttribute('multiple');

        if ($showing === []) {
            $showing = $isCombo ? array_slice($labels, 0, 1) : array_slice($labels, 0, $rows);

            if ($isCombo && $options !== []) {
                $selected = [$options[0][0]];
            }
        }

        $runs = [];

        foreach ($showing as $index => $label) {
            if ($index > 0) {
                $runs[] = $this->makeRun('', $computed, isBreak: true);
            }

            $runs[] = $this->makeRun($label, $computed);
        }

        $node = new Node(['display' => 'inline-block'], [$this->textBox($runs, $computed, $el)]);
        $this->styleBox($node, $computed, $el);

        $this->attachField(
            $node,
            $el,
            $isCombo ? FormFieldType::Combo : FormFieldType::ListBox,
            selected: $selected,
            options: $options,
        );

        if (!is_string($node->width) && ($node->width === null || $node->width <= 0.0)) {
            $font  = FontRegistry::default()->get($node->fontFamily, $node->bold, $node->italic, $node->fontStretch);
            $widest = 0.0;

            foreach ($labels as $label) {
                $widest = max($widest, $font->stringWidth($label, $fs));
            }

            // The gutter is the drop-down arrow rather than a scrollbar here,
            // and it is the same 16 pixels: an empty `<select>` is 16.500 wide
            // on both engines with nothing in it to measure (`OU` `u8`).
            $node->width = min($node->maxLength, $widest + self::CONTROL_GUTTER);
        }

        if (!is_string($node->height) && ($node->height === null || $node->height <= 0.0)) {
            $node->height = min($node->maxLength, max(1, $rows) * $node->lineHeight * $node->fontSize);
        }

        return $this->replacedControl($node);
    }

    /**
     * A count attribute a document may write anything into. Zero and negative
     * are not counts, and the ceiling is there because the number becomes a box
     * dimension: `rows="99999999"` is a document asking for a mile of paper.
     */
    private function countAttribute(DOMElement $el, string $name, int $default): int
    {
        $raw = trim($el->getAttribute($name));

        if ($raw === '' || !ctype_digit($raw)) {
            return $default;
        }

        return max(1, min(self::MAX_CONTROL_COUNT, (int) $raw));
    }

    /**
     * CSS Flexible Box §4.5 for a control this engine draws with a text child,
     * which is the same rule {@see buildInput} spells out: without it the child
     * answers for the box and a flex line squeezes the control onto its own
     * text.
     */
    private function replacedControl(Node $node, bool $replaced = true): Node
    {
        if (is_float($node->width)) {
            $node->replacedContent = $replaced;
            $node->intrinsicWidth  = $node->width;
        }

        if (is_float($node->height)) {
            $node->intrinsicHeight = $node->height;
        }

        return $node;
    }

    private function buildImage(DOMElement $el, array $computed): ?Node
    {
        $src  = $el->getAttribute('src');
        $data = $src === '' ? null : $this->decodeDataUri($src);

        // An <img> pointing at an SVG is drawn as vectors, not rasterized.
        if ($src !== '' && $this->isSvgSource($src, $data)) {
            $doc = $data !== null
                ? SvgDocument::parse($data)
                : (($resolved = $this->resolveAsset($src)) === null ? null : SvgDocument::load($resolved));

            if ($doc !== null) {
                $node             = new Node(['display' => 'rect']);
                $node->svg        = $doc;
                $node->svgAsImage = true;
                $this->styleBox($node, $computed, $el);
                [$iw, $ih, $sized] = $this->svgIntrinsics($doc);
                $this->applyIntrinsicSize($node, $iw, $ih, $el, true, $sized);

                return $node;
            }
        }

        $resolved = $src === '' ? null : $this->resolveAsset($src);

        // The one place a document may reach the network, and only ever for an
        // `<img src>`: a stylesheet is a second document and a font is glyph
        // data copied into the output, so both stay local. It is off unless
        // the operator turned it on **and** named the hosts, and every refusal
        // lands on the missing-image placeholder rather than throwing.
        $remote = $resolved === null && $data === null && $src !== ''
            ? $this->styles->remoteImages->fetch($src, $this->deadline)
            : null;

        $image = match (true) {
            $data !== null     => PdfImage::parse($data, $this->styles->maxImageBytes()),
            $remote !== null   => PdfImage::parse($remote, $this->styles->maxImageBytes()),
            $resolved !== null => PdfImage::load($resolved, $this->styles->maxImageBytes()),
            default            => null,
        };

        // A `src` naming a file the document cannot read is not a box of
        // nothing, and an `<img>` with no `src` at all is: Chrome gives the
        // second 0.000 x 0.000 and the first a placeholder. Defect BF.
        if ($image === null && $src !== '') {
            return $this->brokenImage($el, $computed);
        }

        $node = new Node(['display' => 'rect']);

        if ($image !== null) {
            $node->image = $image;
        }

        // styleBox sets width/height from the computed style, so intrinsic
        // sizing has to run afterwards or it gets overwritten.
        $this->styleBox($node, $computed, $el);

        $this->applyIntrinsicSize(
            $node,
            $image !== null ? (float) $image->width : 0.0,
            $image !== null ? (float) $image->height : 0.0,
            $el,
        );

        return $node;
    }

    /**
     * How far a line box built from this run reaches below its own baseline:
     * the face's descent plus half the leading, which is what
     * `InlineFormatter` reserves under every line whether anything uses it or
     * not.
     */
    private function belowBaseline(InlineRun $run): float
    {
        $font = $run->font();

        return $font->lineBand($run->fontSize, $run->lineHeight * $run->fontSize)[1];
    }

    /**
     * An `<img>` whose file will not load, laid out the way Chrome lays it out.
     *
     * The one rule underneath all of it: **a broken image stops being a
     * replaced element**. It has no picture to be the size of, so it is an
     * ordinary box whose content is the `alt` text, a placeholder icon, or
     * nothing, and ordinary box rules then size it. That is what makes a
     * block-level one **fill** its containing block (`OO-missing-image.html`
     * `o0`, 300.000 x 12.000) where a replaced element would have taken the
     * icon's own width, and what makes `alt` text wrap (`OQ` `q2`, 75.000 x
     * 48.000 in a 100px block).
     *
     * The exception is a box that declares **both** axes: that pair stands in
     * for the intrinsic size the file did not supply, so the box keeps it and
     * is framed (`o3`, 60.000 x 30.000). One axis alone is not a size, and
     * Chrome drops it on an inline box exactly as CSS drops `width` on any
     * other inline: `<img src="gone.png" width="80">` is the 12.000pt icon,
     * not 60.000 wide (`OR-broken-size.html` `r1`, `r2`, `r4`, `rd`).
     *
     * **`alt` text is drawn beside the icon, not instead of it.** Round 18x
     * read the 38.520pt box as the text's own and it is not: 16 pixels of it
     * are the same placeholder, which is why the alt box is exactly one icon
     * wider than the string it holds, at every font size (`OS` `s9`, 65.027
     * against 86.703px of which 70.703 are the glyphs).
     *
     * @param array<string,string> $computed
     */
    private function brokenImage(DOMElement $el, array $computed): Node
    {
        $fs        = $this->pt($computed['font-size'] ?? '12');
        $hasWidth  = $this->sizeValue($computed['width'] ?? null, $fs) !== null || $el->hasAttribute('width');
        $hasHeight = $this->sizeValue($computed['height'] ?? null, $fs) !== null || $el->hasAttribute('height');
        $alt       = $el->hasAttribute('alt') ? $el->getAttribute('alt') : null;

        // Text to render outranks the pair: alt text is content, and content is
        // what an inline box is the size of, so Chrome drops both axes for it
        // (`OQ` `q6` and `OR` `r9`, 38.520 x 12.000 where `width="80"
        // height="40"` asked for 60.000 x 30.000). `alt=""` is not text and
        // keeps them (`ra`, `sb`).
        if ($hasWidth && $hasHeight && ($alt === null || $alt === '')) {
            $node = new Node(['display' => 'rect']);
            $this->styleBox($node, $computed, $el);
            $this->applyIntrinsicSize($node, 0.0, 0.0, $el);
            // `alt=""` still gets the mark here. It says the image is
            // decorative, which collapses an automatic box to nothing and
            // leaves nothing to draw; a box the author gave a size to is
            // still a box, and Chrome frames it and fills it the same way.
            $node->brokenImage = 'framed';

            return $node;
        }

        // The icon is a box rather than a decoration on this one, so ordinary
        // sizing reaches it: an atomic inline shrink-wraps to the 12.000pt
        // square and a block-level box still fills its containing block, which
        // is the pair of answers Chrome gives (`o1` against `o0`).
        $icon = static function (): Node {
            $node = new Node([
                'display'     => 'rect',
                'width'       => self::BROKEN_ICON,
                'height'      => self::BROKEN_ICON,
                'brokenImage' => 'icon',
            ]);

            return $node;
        };

        $children = [];

        if ($alt === null) {
            // With no text beside it the icon is the whole box and no line
            // wraps it: Chrome keeps a bare placeholder 12.000pt tall in a
            // 24px block where a line box would have been 24.000 (`s8`).
            $children[] = $icon();
        } elseif ($alt !== '') {
            $marker      = $this->makeRun('', $computed);
            $marker->box = $icon();

            /*
             * Chrome's placeholder fills the line box top to bottom rather than
             * standing on the baseline, which is `vertical-align: bottom` and
             * would grow the line by a descender if it were taken literally
             * (15.700 where Chrome has 12.000). The shift is the strut's own
             * extent below the baseline, so the icon reaches the line's floor
             * and no further, at every font size: `o2` 38.514 x 12.000 and
             * `OS` `s9` 65.028 x 24.000 against Chrome's 38.520 and 65.027.
             */
            $marker->baselineShift = -$this->belowBaseline($marker);

            $children[] = $this->textBox([$marker, $this->makeRun($alt, $computed)], $computed, $el);
        }

        $node = new Node(['display' => 'block'], $children);
        $this->styleBox($node, $computed, $el);

        // `width` and `height` do not apply to an inline box, and this one is
        // inline whenever the UA sheet's `inline-block` was left alone.
        if (str_starts_with($computed['display'] ?? '', 'inline')) {
            $node->width  = null;
            $node->height = null;
        }

        return $node;
    }

    // -----------------------------------------------------------------
    /**
     * Every box this build produced that establishes a query container, with
     * the element that declared it.
     *
     * The cascade needs a used size and only layout has one, so the pair is
     * kept here until {@see Html::layout()} has run one and can hand the sizes
     * back to the resolver. A document that declares no `container-type` never
     * puts anything in this list, and that is what keeps the second pass off
     * every document that does not ask for one.
     *
     * @var array<int,array{0:DOMElement,1:Node}>
     */
    private array $containers = [];

    /**
     * The query containers this build produced, sized off the layout that has
     * just run.
     *
     * A container query is asked about the container's **content box**, which
     * is what CSS Containment 3 section 5.1 means by the query container's
     * size and what a `cqw` unit is one percent of.
     *
     * **The element travels with its entry and that is not decoration.**
     * `spl_object_id()` on a `DOMElement` is only stable while something holds
     * a PHP reference to it: `php_dom` builds a fresh wrapper each time a walk
     * reaches a node whose old wrapper has been freed, and PHP reuses object
     * handles, so a map keyed by a dead id can quietly answer for the wrong
     * element. Walking one document twice with nothing held gives two
     * different id sets; holding the elements gives the same set every time.
     *
     * @return array<int,array{element:DOMElement,names:string[],type:string,inline:float,block:?float}>
     */
    public function containerSizes(): array
    {
        $out = [];

        foreach ($this->containers as [$el, $node]) {
            $out[spl_object_id($el)] = [
                'element' => $el,
                'names'   => $node->containerNames,
                'type'    => $node->containerType,
                'inline'  => max(0.0, $node->layoutWidth - $node->edgeMain(true)),
                'block'   => $node->containerType === 'size'
                    ? max(0.0, $node->layoutHeight - $node->edgeMain(false))
                    : null,
            ];
        }

        return $out;
    }

    private function noteContainer(Node $node, array $computed, DOMElement $el): void
    {
        $type = strtolower(trim($computed['container-type'] ?? 'normal'));

        // `scroll-state` and anything else new is a container of a kind this
        // engine cannot answer, so it establishes none rather than answering
        // wrongly.
        if ($type !== 'inline-size' && $type !== 'size') {
            return;
        }

        $names = preg_split('/\s+/', strtolower(trim($computed['container-name'] ?? 'none'))) ?: [];

        $node->containerType  = $type;
        $node->containerNames = array_values(array_filter(
            $names,
            static fn(string $name): bool => $name !== '' && $name !== 'none',
        ));

        $this->containers[] = [$el, $node];
    }

    private function styleBox(Node $node, array $computed, DOMElement $el): Node
    {
        // A container query unit is a percentage of this element's own nearest
        // container, so the resolver has to be told which element it is
        // resolving for. The cascade sets the same thing, and a box is styled
        // after its descendants have been cascaded, so it is set twice rather
        // than once.
        //
        // An `ex` and a `ch` are per element in the same way and for the same
        // kind of reason, so the face this box is written in rides along.
        $this->styles->resolveLengthsFor($el, $this->fontFor($computed));

        $fs = $this->pt($computed['font-size'] ?? '12');
        $L  = fn(string $key, ?float $basis = null): ?float => $this->styles->length(
            $computed[$key] ?? '',
            $fs,
            $this->rootFontSize,
            $basis,
        );

        $id = trim($el->getAttribute('id'));

        if ($id !== '') {
            $node->anchorId = $id;
        }

        if (preg_match('/^h([1-6])$/', strtolower($el->nodeName), $heading) === 1) {
            $node->outlineLevel = (int) $heading[1];
            $node->outlineTitle = trim(preg_replace('/\s+/u', ' ', $el->textContent) ?? '');
        }

        // What the box means, for a tagged document. It comes from the element
        // and not from the display, because `display: block` on a `<td>` does
        // not stop it being a table cell to a reader.
        $node->role = StructureTree::roleFor($el->nodeName);

        /*
         * WAI-ARIA `role="presentation"` and its synonym `role="none"` say the
         * element carries no meaning of its own, and they are how an author
         * says a table is there for layout. This read nothing but the element
         * name, so a layout table was announced to a reader as a table with
         * one row and one column and there was no way to say otherwise:
         * defect HU. Chrome answers the same question with a heuristic of its
         * own, `NonStruct` for a one-cell table and for every plain `<div>`,
         * which is bullets 66 and 67 and which it also applies to `<section>`.
         * Taking the author's word for it is the narrower answer and it is the
         * one an integrator can act on.
         *
         * **It becomes `NonStruct` rather than nothing**, which is the same
         * element Chrome writes and is the whole reason to have one: a box
         * with no role at all leaves its marks with no owner, and PDF/UA 7.1
         * wants every mark either tagged or an artifact. Dropping the role
         * outright painted `UG-tag-roles.html`'s d5 and t4 with no `BDC`
         * around them at all, which extract_text still reads and a reader
         * cannot.
         */
        $declaredRole = strtolower(trim($el->getAttribute('role')));

        if ($declaredRole === 'presentation' || $declaredRole === 'none') {
            $node->role = 'NonStruct';
        }

        if ($node->role === 'TH') {
            $node->headerScope = self::headerScopeOf($el);
        }

        $alt = trim($el->getAttribute('alt'));

        if ($alt === '') {
            $alt = trim($el->getAttribute('aria-label'));
        }

        $node->altText = $alt;

        $node->fontSize   = $this->usedFontSize($computed);
        $node->bold       = $this->isBold($computed);
        $node->italic     = $this->isItalic($computed);
        $node->fontFamily = $this->family($computed);
        $node->fontStretch = $this->stretch($computed);
        $node->lineHeight = $this->lineHeight($computed);
        $node->textAlign  = $computed['text-align'] ?? 'left';
        $node->direction  = $this->direction($computed, $el);
        $node->color      = $this->styles->rgba($computed['color'] ?? '') ?? [0.1, 0.1, 0.1];

        $node->boxSizing = strtolower(trim($computed['box-sizing'] ?? 'content-box')) === 'border-box'
            ? 'border-box'
            : 'content-box';

        $this->noteContainer($node, $computed, $el);

        $node->flowRoot = strtolower(trim($computed['display'] ?? '')) === 'flow-root';

        // Sizes keep percentages as strings so the layout engine resolves them
        // against the real containing block.
        $node->width      = $this->sizeValue($computed['width'] ?? null, $fs);
        $node->height     = $this->sizeValue($computed['height'] ?? null, $fs);
        $node->declaredMinWidth  = $this->sizeValue($computed['min-width'] ?? null, $fs);
        $node->declaredMaxWidth  = $this->sizeValue($computed['max-width'] ?? null, $fs);
        $node->declaredMinHeight = $this->sizeValue($computed['min-height'] ?? null, $fs);
        $node->declaredMaxHeight = $this->sizeValue($computed['max-height'] ?? null, $fs);

        // A percentage edge resolves against the containing block, which is
        // not known yet, so keep the declaration and let layout fill it in.
        // A `calc()` holding a percentage travels the same way, as the pair
        // {@see linearLength} reduces it to.
        $basisDependent = function (string $value) use ($fs): ?string {
            $value = trim($value);

            if (str_ends_with($value, '%')) {
                return is_numeric(rtrim($value, '%')) ? $value : null;
            }

            return $this->linearLength($value, $fs);
        };

        foreach (['top', 'right', 'bottom', 'left'] as $side) {
            $declaredMargin  = strtolower(trim($computed["margin-$side"] ?? ''));
            $declaredPadding = trim($computed["padding-$side"] ?? '');

            $node->autoMargin[$side]     = $declaredMargin === 'auto';
            $node->marginPercent[$side]  = $basisDependent($declaredMargin);
            $node->paddingPercent[$side] = $basisDependent($declaredPadding);

            $node->margin[$side]  = $L("margin-$side") ?? 0.0;
            $node->padding[$side] = $L("padding-$side") ?? 0.0;
        }

        /*
         * The old flexbox says which way it stacks with `-webkit-box-orient`
         * rather than with `flex-direction`, and a template that writes
         * `display: -webkit-box` writes the orient beside it: that pair is the
         * only spelling Chrome 151 reads `-webkit-line-clamp` on.
         */
        $webkitOrient = self::isWebkitFlex($computed)
            && strtolower(trim($computed['-webkit-box-orient'] ?? 'horizontal')) === 'vertical';

        $node->flexDirection  = match (true) {
            $webkitOrient => 'column',
            default       => match (strtolower(trim($computed['flex-direction'] ?? 'row'))) {
                'row-reverse'    => 'row-reverse',
                'column'         => 'column',
                'column-reverse' => 'column-reverse',
                default          => 'row',
            },
        };
        $node->flexWrap       = match (strtolower(trim($computed['flex-wrap'] ?? 'nowrap'))) {
            'wrap'         => 'wrap',
            'wrap-reverse' => 'wrap-reverse',
            default        => 'nowrap',
        };
        $node->justifyContent = $this->alignKeyword($computed['justify-content'] ?? 'normal');
        $node->alignItems     = $this->alignKeyword($computed['align-items'] ?? 'stretch');
        $node->alignContent   = $this->alignKeyword($computed['align-content'] ?? 'stretch');
        $self                 = $this->alignKeyword($computed['align-self'] ?? 'auto');
        $node->alignSelf      = $self === 'auto' ? null : $self;

        $node->flexGrow    = (float) ($computed['flex-grow'] ?? 0);
        $node->flexShrink  = (float) ($computed['flex-shrink'] ?? 1);
        $order             = trim($computed['order'] ?? '0');
        $node->order       = is_numeric($order) ? (int) $order : 0;
        $basis             = $computed['flex-basis'] ?? 'auto';
        $node->flexBasis   = $basis === 'auto' ? 'auto' : ($this->sizeValue($basis, $fs) ?? 'auto');
        $count             = trim($computed['column-count'] ?? 'auto');
        $node->columnCount = ctype_digit($count) ? max(1, min(20, (int) $count)) : 1;
        $node->columnWidth = $L('column-width');

        if ($node->columnWidth !== null && $node->columnWidth <= 0.0) {
            $node->columnWidth = null;
        }

        $node->columnFill = strtolower(trim($computed['column-fill'] ?? 'balance')) === 'auto'
            ? 'auto'
            : 'balance';

        // The prefixed spelling is the one a template written for Safari
        // carries, and Chrome reads both: `RY-fold-clone.html` declares the
        // pair and its `-slice` twin declares the other pair.
        $break = strtolower(trim(
            $computed['box-decoration-break'] ?? $computed['-webkit-box-decoration-break'] ?? 'slice',
        ));

        $node->decorationBreak = $break === 'clone' ? 'clone' : 'slice';

        // CSS Multi-column §6: `column-span` computes to `none` on a float and
        // on an out-of-flow box, so a floated heading stays in its column
        // rather than being pulled across the gutters. `RX-column.html` x13.
        //
        // `float` and `position` are read from the cascade rather than off the
        // node, because both are assigned further down this same method.
        $node->columnSpanAll = strtolower(trim($computed['column-span'] ?? 'none')) === 'all'
            && !in_array(strtolower(trim($computed['float'] ?? 'none')), ['left', 'right'], true)
            && !in_array(strtolower(trim($computed['position'] ?? 'static')), ['absolute', 'fixed'], true);

        $node->rowGap    = max(0.0, $L('row-gap') ?? 0.0);
        $node->columnGap = max(0.0, $L('column-gap') ?? 0.0);
        $node->gap       = max(0.0, $L('row-gap') ?? $L('gap') ?? 0.0);

        $colNames                  = [];
        $rowNames                  = [];
        $node->gridColumnsRaw      = trim($computed['grid-template-columns'] ?? 'none');
        $node->gridRowsRaw         = trim($computed['grid-template-rows'] ?? 'none');
        $node->gridFontSize        = $fs;
        $node->gridRootFontSize    = $this->rootFontSize;
        $node->gridTemplateColumns = $this->styles->trackList($node->gridColumnsRaw, $fs, $this->rootFontSize, $colNames);
        $node->gridTemplateRows    = $this->styles->trackList($node->gridRowsRaw, $fs, $this->rootFontSize, $rowNames);
        $node->gridColumnNames     = $colNames;
        $node->gridRowNames        = $rowNames;
        $node->gridAreas           = $this->styles->templateAreas($computed['grid-template-areas'] ?? 'none');

        // Areas implicitly name their edges: `head` yields head-start/head-end.
        foreach ($node->gridAreas as $name => [$r, $c, $rs, $cs]) {
            $node->gridRowNames["$name-start"]    ??= $r;
            $node->gridRowNames["$name-end"]      ??= $r + $rs;
            $node->gridColumnNames["$name-start"] ??= $c;
            $node->gridColumnNames["$name-end"]   ??= $c + $cs;
        }

        $autoCols              = trim($computed['grid-auto-columns'] ?? 'auto');
        $autoRows              = trim($computed['grid-auto-rows'] ?? 'auto');
        $node->gridAutoColumns = $autoCols === 'auto' ? null : $this->styles->singleTrack($autoCols, $fs, $this->rootFontSize);
        $node->gridAutoRows    = $autoRows === 'auto' ? null : $this->styles->singleTrack($autoRows, $fs, $this->rootFontSize);
        $flow                  = strtolower($computed['grid-auto-flow'] ?? 'row');
        $node->gridAutoFlow    = str_contains($flow, 'column') ? 'column' : 'row';
        $node->gridDense       = str_contains($flow, 'dense');

        $node->gridAreaName        = $this->areaName($computed['grid-area'] ?? '');
        $node->gridColumnStartName = $this->areaName($computed['grid-column-start'] ?? '');
        $node->gridColumnEndName   = $this->areaName($computed['grid-column-end'] ?? '');
        $node->gridRowStartName    = $this->areaName($computed['grid-row-start'] ?? '');
        $node->gridRowEndName      = $this->areaName($computed['grid-row-end'] ?? '');

        [$node->gridColumnStart, $node->gridColumnSpan] = $this->gridLine($computed['grid-column-start'] ?? 'auto');
        [$node->gridColumnEnd] = $this->gridLine($computed['grid-column-end'] ?? 'auto');
        [$node->gridRowStart, $node->gridRowSpan] = $this->gridLine($computed['grid-row-start'] ?? 'auto');
        [$node->gridRowEnd] = $this->gridLine($computed['grid-row-end'] ?? 'auto');

        // `grid-column-end: span N` carries the span rather than a line.
        foreach (
            [
                ['grid-column-end', 'gridColumnSpan', 'gridColumnEnd'],
                ['grid-row-end', 'gridRowSpan', 'gridRowEnd'],
            ] as [$prop, $spanField, $endField]
        ) {
            [$line, $span] = $this->gridLine($computed[$prop] ?? 'auto');

            if ($line === null && $span > 1) {
                $node->$spanField = $span;
                $node->$endField  = null;
            }
        }

        $node->justifyItems = $this->alignKeyword($computed['justify-items'] ?? 'stretch');
        $self               = $this->alignKeyword($computed['justify-self'] ?? 'auto');
        $node->justifySelf  = $self === 'auto' ? null : $self;

        $node->background   = $this->styles->rgba($computed['background-color'] ?? '', $node->color);
        $this->backgroundLayer($node, $computed);
        $node->visible      = $this->isVisible($computed);
        $node->borderRadius = $this->cornerRadii($computed, $fs, $node);

        $node->border = $this->borderSides($computed, $L, $node->color);
        $node->borderImage = $this->borderImage($computed, $node, $fs);
        $node->outline    = $this->outlineBox($computed, $L, $node->color);
        $node->columnRule = $this->strokeBox($computed, $L, 'column-rule', $node->color);
        $node->clipPath   = $this->clipPathShape($computed['clip-path'] ?? 'none', $fs);
        $node->outlineOffset = $L('outline-offset') ?? 0.0;

        $node->boxShadow = $this->styles->boxShadow(
            $computed['box-shadow'] ?? 'none',
            $fs,
            $this->rootFontSize,
            $node->color,
        );

        $ratio = trim($computed['aspect-ratio'] ?? 'auto');
        $replaced = $node->image !== null || $node->svg !== null;

        /*
         * CSS Sizing 4: `aspect-ratio` applies to every element except an
         * inline box and an internal table box. A row and a cell take their
         * height from the table algorithm, and letting a ratio size them
         * turns a wide cell into a document hundreds of pages long.
         *
         * `auto <ratio>` asks for the replaced element's own proportions and
         * offers the ratio only as a fallback, which for an image that loaded
         * means the ratio is never used.
         */
        $internal = in_array($node->display, self::TABLE_INTERNALS, true)
            || in_array(strtolower(trim($computed['display'] ?? '')), self::TABLE_INTERNALS, true);

        $node->aspectRatio = $internal || ($replaced && str_contains(strtolower($ratio), 'auto'))
            ? null
            : $this->styles->aspectRatio($ratio);
        $node->maxLength = $this->styles->maxLength();

        $indent = trim($computed['text-indent'] ?? '0');

        $node->textIndent = str_ends_with($indent, '%') && is_numeric(rtrim($indent, '%'))
            ? $indent
            : ($L('text-indent') ?? 0.0);

        $node->float    = match (strtolower(trim($computed['float'] ?? 'none'))) {
            'left'  => 'left',
            'right' => 'right',
            default => 'none',
        };

        $node->clear    = match (strtolower(trim($computed['clear'] ?? 'none'))) {
            'left'  => 'left',
            'right' => 'right',
            'both'  => 'both',
            default => 'none',
        };

        $node->position = match (strtolower(trim($computed['position'] ?? 'static'))) {
            'relative' => 'relative',
            'absolute' => 'absolute',
            'fixed'    => 'fixed',
            default    => 'static',
        };

        $node->top      = $this->sizeValue($computed['top'] ?? null, $fs, true);
        $node->right    = $this->sizeValue($computed['right'] ?? null, $fs, true);
        $node->bottom   = $this->sizeValue($computed['bottom'] ?? null, $fs, true);
        $node->left     = $this->sizeValue($computed['left'] ?? null, $fs, true);
        // `auto` is not zero: see Node::$zIndex. Anything that is not a plain
        // integer is `auto` too, which is what the initial value is.
        $zIndex         = strtolower(trim((string) ($computed['z-index'] ?? 'auto')));
        $node->zIndex   = preg_match('/^-?\d+$/', $zIndex) === 1 ? (int) $zIndex : null;

        $node->transform       = $this->styles->transform($computed['transform'] ?? 'none', $fs, $this->rootFontSize);
        $node->transformOrigin = trim($computed['transform-origin'] ?? '50% 50%');
        $node->opacity         = max(0.0, min(1.0, (float) ($computed['opacity'] ?? 1)));

        $node->blendMode  = $this->blendKeyword($computed['mix-blend-mode'] ?? 'normal');
        $node->isolation  = strtolower(trim($computed['isolation'] ?? 'auto')) === 'isolate' ? 'isolate' : 'auto';
        $node->maskLayers = $this->maskLayers($computed, $node->color);

        $node->objectFit  = match (strtolower(trim($computed['object-fit'] ?? 'fill'))) {
            'contain'    => 'contain',
            'cover'      => 'cover',
            'none'       => 'none',
            'scale-down' => 'scale-down',
            default      => 'fill',
        };

        $node->objectPosition = strtolower(trim($computed['object-position'] ?? '50% 50%')) ?: '50% 50%';

        $node->textShadow = $this->styles->textShadow(
            $computed['text-shadow'] ?? 'none',
            $fs,
            $this->rootFontSize,
        );

        /*
         * One computed value per axis, out of all three declarations. The
         * `overflow` shorthand has already been expanded into `overflow-x` and
         * `overflow-y` by the cascade, so this only has CSS Overflow §3's own
         * rule left to apply: when one axis is `visible` or `clip` and the
         * other is neither, the first computes to `auto` or to `hidden`
         * respectively. `auto` and `scroll` are `hidden` here because a PDF has
         * no scrollbars, which leaves that rule saying "an axis beside a
         * scrolling one scrolls too".
         *
         * Chrome, on `ZY-overflow-longhand.html`: `overflow-x: hidden` alone
         * makes a flex item a scroll container, so §4.5 gives it no automatic
         * minimum and `y2` is **22.500** exactly as the shorthand's `y1` is,
         * where a longhand reaching nothing left it at 60.000. `overflow-x:
         * clip` (`y4`) stays 60.000, and `overflow: clip hidden` and
         * `hidden clip` (`y6`, `y7`) are 22.500, because the `clip` axis
         * beside a scrolling one computes to `hidden` too.
         */
        $overflowX = self::overflowKeyword($computed['overflow-x'] ?? 'visible');
        $overflowY = self::overflowKeyword($computed['overflow-y'] ?? 'visible');

        if ($overflowX === 'hidden' && $overflowY !== 'hidden') {
            $overflowY = 'hidden';
        } elseif ($overflowY === 'hidden' && $overflowX !== 'hidden') {
            $overflowX = 'hidden';
        }

        $node->overflowX = $overflowX;
        $node->overflowY = $overflowY;

        // A box clips when either axis does, and it is a **scroll container**
        // when either axis scrolls, which `clip` is the value that separates:
        // it clips without scrolling, so §4.5's automatic minimum size still
        // applies to it. `ZW` `v4` is 60.000 in Chrome against `v1`'s 22.500.
        $node->overflow        = $overflowX !== 'visible' || $overflowY !== 'visible'
            ? 'hidden'
            : 'visible';
        $node->scrollContainer = $overflowX === 'hidden' || $overflowY === 'hidden';

        [$node->overflowClipBox, $node->overflowClipMargin] = $this->clipMargin(
            $computed['overflow-clip-margin'] ?? '',
            $fs,
        );

        // CSS requires a non-visible overflow for the ellipsis to appear at
        // all; without it the text simply spills, which is what `clip` means
        // here anyway.
        $node->textOverflow = $node->overflow === 'hidden'
            && strtolower(trim($computed['text-overflow'] ?? 'clip')) === 'ellipsis'
                ? 'ellipsis'
                : 'clip';

        // The property is set on the block container but truncates the line
        // boxes, which live in the anonymous text box holding its content.
        foreach ($node->children as $child) {
            if ($child->display === 'text') {
                $child->textOverflow = $node->textOverflow;
            }
        }

        // `-webkit-line-clamp` needs the two declarations beside it, and that
        // is Chrome's answer rather than a reading of the spec: on an ordinary
        // block Chrome drops it, and it drops the unprefixed `line-clamp`
        // outright. `RX-clamp.html` n2 and n3 are both NO SIGNAL, so an engine
        // that read either of them would truncate a card the browser does not.
        $clamp = trim($computed['-webkit-line-clamp'] ?? 'none');

        $node->lineClamp = ctype_digit($clamp)
            && strtolower(trim($computed['display'] ?? 'block')) === '-webkit-box'
            && strtolower(trim($computed['-webkit-box-orient'] ?? 'horizontal')) === 'vertical'
                ? max(0, min(self::MAX_CLAMP_LINES, (int) $clamp))
                : 0;

        $node->breakInside   = $this->avoidsBreak($computed);
        $node->breakBefore   = $this->forcedBreak($computed, 'before');
        $node->breakAfter    = $this->forcedBreak($computed, 'after');
        $node->pageName      = $this->pageName($computed);
        $node->repeatOnBreak = $el->hasAttribute('data-repeat') || strtolower($el->nodeName) === 'thead';

        return $node;
    }

    /**
     * Whether a box asks not to be split across a page.
     *
     * `break-inside` wins over the legacy `page-break-inside` when both are
     * set, which is the rule `forcedBreak()` applies to the other two sides.
     * `avoid-page` means the same thing here as `avoid`, because there is one
     * page stream; `avoid-column` is deliberately not in the list, since this
     * engine balances a multi-column box in flow rather than fragmenting it.
     *
     * @param array<string,string> $computed
     */
    private function avoidsBreak(array $computed): string
    {
        $modern = strtolower(trim($computed['break-inside'] ?? 'auto'));
        $legacy = strtolower(trim($computed['page-break-inside'] ?? 'auto'));
        $value  = $modern !== 'auto' ? $modern : $legacy;

        return in_array($value, ['avoid', 'avoid-page'], true) ? 'avoid' : 'auto';
    }

    /**
     * The page type a box asks for, from the `page` property.
     *
     * `auto` and an absent declaration are both the ordinary page, spelled as
     * the empty string. The name is lowercased because `CssParser` lowercases
     * the whole `@page` prelude, so `@page Cover` is recorded under `cover` and
     * a name compared case-sensitively could never match its own block. CSS
     * makes a `<custom-ident>` case-sensitive, so two names differing only in
     * case are one name here.
     *
     * @param array<string,string> $computed
     */
    private function pageName(array $computed): string
    {
        $value = strtolower(trim($computed['page'] ?? ''));

        if ($value === '' || $value === 'auto' || !preg_match('/^-?[a-z_][a-z0-9_-]*$/', $value)) {
            return '';
        }

        return $value;
    }

    /**
     * Whether a forced page break is asked for on one side of a box.
     *
     * `break-$side` wins over the legacy `page-break-$side` when both are set,
     * which is what the CSS Fragmentation spec says about the alias. `left`
     * and `right` ask for a specific facing page; with a single page stream
     * and no notion of recto and verso, honoring them as a plain break is
     * closer than ignoring them.
     *
     * @param array<string,string> $computed
     */
    private function forcedBreak(array $computed, string $side): string
    {
        $modern = strtolower(trim($computed["break-$side"] ?? 'auto'));
        $legacy = strtolower(trim($computed["page-break-$side"] ?? 'auto'));
        $value  = $modern !== 'auto' ? $modern : $legacy;

        return in_array($value, ['page', 'always', 'left', 'right', 'recto', 'verso'], true)
            ? 'page'
            : 'auto';
    }

    /**
     * Each edge carries its own width and color, so `border-bottom` alone is
     * a rule under the box rather than a box with no border at all. An edge
     * with no width, or a style of `none`, is simply absent from the result.
     *
     * @param array<string,string>                $computed
     * @param callable                            $length (string):?float $length
     * @param array{0:float,1:float,2:float}|null $currentColor what an omitted border-color inherits
     *
     * @return array<string,array{width:float,style:string,color:array{0:float,1:float,2:float}}>|null
     */
    /**
     * `background-image: url()` and the three properties that place it.
     *
     * The url goes through `Support/AssetPath` like every other path a
     * document names, and a `data:` URI is decoded in place, so a background
     * reaches exactly the files an `<img src>` reaches and no others.
     */
    /**
     * How many `background-image` layers one box may carry. Each may name a
     * file and each is painted in full, so the list length is a cost the
     * document controls.
     */
    private const int MAX_BACKGROUND_LAYERS = 16;

    /**
     * CSS Masking's four compositing operators. Anything else a document writes
     * is `add`, which is the initial value, rather than a reason to refuse the
     * layer.
     *
     * @var list<string>
     */
    private const array MASK_OPERATORS = ['add', 'subtract', 'intersect', 'exclude'];

    /**
     * The boxes `mask-origin` may name. `content-box`, `padding-box` and
     * `border-box` are the three CSS Box names; `fill-box`, `stroke-box` and
     * `view-box` are the SVG ones and there is no SVG geometry here to hang them
     * on, so they resolve to the initial value rather than being refused.
     *
     * @var list<string>
     */
    private const array MASK_BOXES = ['border-box', 'padding-box', 'content-box'];

    /**
     * `mask-clip` takes the same three boxes and `no-clip`, which is the only
     * value that leaves a layer reaching past the box it belongs to.
     *
     * @var list<string>
     */
    private const array MASK_CLIPS = ['border-box', 'padding-box', 'content-box', 'no-clip'];

    /**
     * How far into an inline `<svg>` a rule in the page's stylesheet is
     * carried. An SVG subtree is author input and the walk over it recurses.
     */
    private const int MAX_SVG_STYLE_DEPTH = 64;

    /**
     * How far under a list item the walk for the line its marker hangs beside
     * descends. The box tree is built from author input and the walk recurses.
     */
    private const int MAX_MARKER_DEPTH = 64;

    /**
     * The displays CSS Sizing 4 excludes from `aspect-ratio`: an internal
     * table box takes its size from the table algorithm, not from a ratio.
     *
     * @var string[]
     */
    private const array TABLE_INTERNALS = [
        'table-row', 'table-cell', 'table-row-group', 'table-header-group',
        'table-footer-group', 'table-column', 'table-column-group',
    ];

    /**
     * What CSS 2.1 §17.2.1 accepts as a direct child of a table. A `table-cell`
     * is deliberately absent: written without a row around it, it gets an
     * anonymous one generated.
     */
    private const array TABLE_ROW_LEVEL = [
        'table-row', 'table-row-group', 'table-header-group',
        'table-footer-group', 'table-column', 'table-column-group',
    ];

    /**
     * The three displays that make an element one of a table's sections. Which
     * of them it is decides where its rows go, and a `table-column-group` is
     * deliberately absent: it holds no rows.
     */
    private const array TABLE_GROUP_LEVEL = [
        'table-row-group', 'table-header-group', 'table-footer-group',
    ];

    /**
     * `border-image`, resolved as far as it can be without the box.
     *
     * A slice is source pixels or a percentage of the source, an image width
     * is a length, a multiple of the border width or a percentage of the
     * border image area, and an outset is a length or a multiple of the
     * border width. None of the three can be finished here, because the
     * source's own size and the area both belong to the painter, so each edge
     * keeps its unit and {@see BoxPainter::borderImageEdges()} resolves it.
     *
     * @param array<string,string> $computed
     * @return array{
     *     layer: array{image:?PdfImage,svg:?SvgDocument,gradient:?array},
     *     slice: array<int, array{v:float,unit:string}>,
     *     fill: bool,
     *     width: array<int, array{v:float,unit:string}>,
     *     outset: array<int, array{v:float,unit:string}>,
     *     repeat: string
     * }|null
     */
    private function borderImage(array $computed, Node $node, float $fontSize): ?array
    {
        $source = trim($computed['border-image-source'] ?? 'none');

        if ($source === '' || strtolower($source) === 'none') {
            return null;
        }

        $layer = $this->backgroundSource($source, $node->color);

        if ($layer === null) {
            return null;
        }

        $slice = trim($computed['border-image-slice'] ?? '100%');
        $fill  = false;

        // `fill` may sit anywhere in the value, so it comes out before the
        // four edges are read off what is left.
        $words = array_values(array_filter(
            preg_split('/\s+/', $slice) ?: [],
            static function (string $word) use (&$fill): bool {
                if (strtolower($word) === 'fill') {
                    $fill = true;

                    return false;
                }

                return $word !== '';
            },
        ));

        return [
            'layer'  => $layer,
            'slice'  => $this->borderImageSides($words, '100%', $fontSize),
            'fill'   => $fill,
            'width'  => $this->borderImageSides(
                preg_split('/\s+/', trim($computed['border-image-width'] ?? '1')) ?: [],
                '1',
                $fontSize,
            ),
            'outset' => $this->borderImageSides(
                preg_split('/\s+/', trim($computed['border-image-outset'] ?? '0')) ?: [],
                '0',
                $fontSize,
            ),
            'repeat' => strtolower(trim($computed['border-image-repeat'] ?? 'stretch')),
        ];
    }

    /**
     * One to four edge values, expanded the way every CSS edge shorthand
     * expands, with each kept beside the unit it was written in.
     *
     * @param  string[] $words
     * @return array<int, array{v:float,unit:string}>
     */
    private function borderImageSides(array $words, string $fallback, float $fontSize): array
    {
        $words = array_values(array_filter($words, static fn(string $w): bool => trim($w) !== ''));

        if ($words === []) {
            $words = [$fallback];
        }

        $edges = match (count($words)) {
            1       => [$words[0], $words[0], $words[0], $words[0]],
            2       => [$words[0], $words[1], $words[0], $words[1]],
            3       => [$words[0], $words[1], $words[2], $words[1]],
            default => array_slice($words, 0, 4),
        };

        return array_map(
            function (string $word) use ($fontSize): array {
                $word = strtolower(trim($word));

                if ($word === 'auto') {
                    return ['v' => 0.0, 'unit' => 'auto'];
                }

                if (str_ends_with($word, '%')) {
                    return ['v' => (float) rtrim($word, '%') / 100.0, 'unit' => 'pct'];
                }

                if (is_numeric($word)) {
                    return ['v' => (float) $word, 'unit' => 'num'];
                }

                return [
                    'v'    => $this->styles->length($word, $fontSize, $this->rootFontSize) ?? 0.0,
                    'unit' => 'pt',
                ];
            },
            $edges,
        );
    }

    private function backgroundLayer(Node $node, array $computed): void
    {
        /*
         * `background-clip` is read whether or not the box has an image on it,
         * because it is what cuts the box's own `background-color` too, and
         * CSS takes the color's area from the LAST layer's value.
         */
        $clips              = $this->styles->splitList($computed['background-clip'] ?? 'border-box');
        $node->backgroundClip = self::paintBox(end($clips) ?: 'border-box', 'border-box');

        $declared = $this->styles->splitList($computed['background-image'] ?? 'none');

        if ($declared === []) {
            return;
        }

        $repeats   = $this->styles->splitList($computed['background-repeat'] ?? 'repeat');
        $positions = $this->styles->splitList($computed['background-position'] ?? '0% 0%');
        $sizes     = $this->styles->splitList($computed['background-size'] ?? 'auto');
        $origins   = $this->styles->splitList($computed['background-origin'] ?? 'padding-box');

        $layers = [];

        foreach ($declared as $index => $source) {
            // Each layer may name a file and each is painted in full, and the
            // list length is the author's to choose, so it is bounded like
            // every other loop over untrusted input.
            if (count($layers) >= self::MAX_BACKGROUND_LAYERS) {
                break;
            }

            $layer = $this->backgroundSource($source, $node->color);

            if ($layer === null) {
                continue;
            }

            /*
             * The placement properties are comma lists of their own and they
             * cycle: two layers against one `background-size` means both take
             * it, which is what a single-value declaration behind a two-layer
             * image list means.
             */
            $layers[] = [
                ...$layer,
                'repeat'   => strtolower(trim($repeats[$index % max(1, count($repeats))] ?? 'repeat')),
                'position' => strtolower(trim($positions[$index % max(1, count($positions))] ?? '0% 0%')),
                'size'     => strtolower(trim($sizes[$index % max(1, count($sizes))] ?? 'auto')),
                'origin'   => self::paintBox($origins[$index % max(1, count($origins))] ?? '', 'padding-box'),
                'clip'     => self::paintBox($clips[$index % max(1, count($clips))] ?? '', 'border-box'),
            ];
        }

        // CSS writes the layers first-on-top; the painter goes back to front.
        $node->backgroundLayers = array_reverse($layers);
    }

    /**
     * `clip-path`, as a shape the painter can turn into a PDF clipping path.
     *
     * PDF has native clipping paths and the writer already builds three kinds,
     * so the work here is reading the functions rather than drawing them.
     * `inset()`, `circle()`, `ellipse()` and `polygon()` are covered;
     * `path()`, `url()` and the geometry-box keywords on their own are not,
     * and each comes back null so the box is left unclipped rather than
     * clipped to a guess.
     *
     * A length is resolved to points here, where the font size is; a
     * percentage cannot be, because it resolves against the box's own size.
     *
     * @return array<string,mixed>|null
     */
    private function clipPathShape(string $value, float $fontSize): ?array
    {
        $value = trim($value);

        if ($value === '' || strtolower($value) === 'none') {
            return null;
        }

        if (preg_match('/^([a-z-]+)\((.*)\)$/is', $value, $m) !== 1) {
            return null;
        }

        $shape = strtolower($m[1]);
        $args  = trim($m[2]);

        // `at <position>` splits every basic shape but `inset` and `polygon`.
        $at = [['v' => 50.0, 'pct' => true], ['v' => 50.0, 'pct' => true]];

        if (preg_match('/\bat\b(.*)$/is', $args, $position) === 1) {
            $args  = trim((string) substr($args, 0, (int) strpos($args, $position[0])));
            $words = preg_split('/\s+/', trim($position[1])) ?: [];

            foreach (array_slice($words, 0, 2) as $i => $word) {
                $at[$i] = $this->clipLength($word, $fontSize) ?? $at[$i];
            }
        }

        return match ($shape) {
            'inset'   => $this->clipInset($args, $fontSize),
            'circle'  => ['shape' => 'circle', 'r' => $this->clipRadius($args, $fontSize), 'at' => $at],
            'ellipse' => $this->clipEllipse($args, $fontSize, $at),
            'polygon' => $this->clipPolygon($args, $fontSize),
            default   => null,
        };
    }

    /** @return array<string,mixed>|null */
    private function clipInset(string $args, float $fontSize): ?array
    {
        [$sides, $radii] = array_pad(preg_split('/\bround\b/i', $args, 2) ?: [], 2, null);

        $edges = [];

        foreach (preg_split('/\s+/', trim((string) $sides)) ?: [] as $word) {
            $edge = $this->clipLength($word, $fontSize);

            if ($edge !== null) {
                $edges[] = $edge;
            }
        }

        if ($edges === []) {
            return null;
        }

        // The one-to-four shorthand, exactly as `margin` takes it.
        $edges = match (count($edges)) {
            1       => [$edges[0], $edges[0], $edges[0], $edges[0]],
            2       => [$edges[0], $edges[1], $edges[0], $edges[1]],
            3       => [$edges[0], $edges[1], $edges[2], $edges[1]],
            default => array_slice($edges, 0, 4),
        };

        $corners = [];

        foreach (preg_split('/\s+/', trim((string) $radii)) ?: [] as $word) {
            $corner = $this->clipLength($word, $fontSize);

            if ($corner !== null) {
                $corners[] = $corner;
            }
        }

        $corners = match (count($corners)) {
            0       => [],
            1       => [$corners[0], $corners[0], $corners[0], $corners[0]],
            2       => [$corners[0], $corners[1], $corners[0], $corners[1]],
            3       => [$corners[0], $corners[1], $corners[2], $corners[1]],
            default => array_slice($corners, 0, 4),
        };

        return ['shape' => 'inset', 'edges' => $edges, 'radii' => $corners];
    }

    /** @return array<string,mixed>|null */
    private function clipEllipse(string $args, float $fontSize, array $at): ?array
    {
        $words = preg_split('/\s+/', trim($args)) ?: [];

        return [
            'shape' => 'ellipse',
            'rx'    => $this->clipRadius($words[0] ?? '', $fontSize),
            'ry'    => $this->clipRadius($words[1] ?? '', $fontSize),
            'at'    => $at,
        ];
    }

    /** @return array<string,mixed>|null */
    private function clipPolygon(string $args, float $fontSize): ?array
    {
        $points = [];

        foreach (explode(',', $args) as $pair) {
            $words = preg_split('/\s+/', trim($pair)) ?: [];

            // A leading `nonzero` or `evenodd` is the fill rule, and both
            // describe the same shape for every polygon a template writes.
            if (in_array(strtolower($words[0] ?? ''), ['nonzero', 'evenodd'], true)) {
                $words = array_slice($words, 1);
            }

            $px = $this->clipLength($words[0] ?? '', $fontSize);
            $py = $this->clipLength($words[1] ?? '', $fontSize);

            if ($px !== null && $py !== null) {
                $points[] = [$px, $py];
            }
        }

        return count($points) >= 3 ? ['shape' => 'polygon', 'points' => $points] : null;
    }

    /**
     * A radius component: a length, a percentage, or one of the two sizing
     * keywords. `closest-side` is `circle()`'s own initial value.
     *
     * @return array<string,mixed>
     */
    private function clipRadius(string $word, float $fontSize): array
    {
        $lower = strtolower(trim($word));

        if ($lower === 'closest-side' || $lower === 'farthest-side' || $lower === '') {
            return ['side' => $lower === 'farthest-side' ? 'farthest' : 'closest'];
        }

        return $this->clipLength($word, $fontSize) ?? ['side' => 'closest'];
    }

    /**
     * One `clip-path` component, in points, or kept as a percentage.
     *
     * @return array{v:float,pct:bool}|null
     */
    private function clipLength(string $word, float $fontSize): ?array
    {
        $word = trim($word);

        if ($word === '') {
            return null;
        }

        if (str_ends_with($word, '%')) {
            return ['v' => (float) rtrim($word, '%'), 'pct' => true];
        }

        $length = $this->styles->length($word, $fontSize, $this->rootFontSize);

        return $length === null ? null : ['v' => $length, 'pct' => false];
    }

    /**
     * One of the three painting boxes, or the given default for anything else.
     *
     * `background-clip` also takes `text` and `border-area`, and neither is
     * painted: falling back to the default leaves the box exactly as it was
     * rather than cutting it somewhere the engine cannot draw.
     */
    private static function paintBox(string $value, string $fallback): string
    {
        $value = strtolower(trim($value));

        return in_array($value, ['border-box', 'padding-box', 'content-box'], true)
            ? $value
            : $fallback;
    }

    /**
     * One `background-image` layer: a gradient, an SVG or a raster.
     *
     * @return array{image:?PdfImage,svg:?SvgDocument,gradient:?array}|null
     */
    /**
     * `mask-image` and the four properties that place it, which together hide
     * the box and its whole subtree wherever the source is transparent.
     *
     * A gradient and a picture are both read. An SVG source is read only in
     * `luminance` mode, because a luminosity soft mask reads what was drawn
     * and an SVG's own alpha is not separable the way a picture's is; in
     * `alpha` mode it leaves no layer behind, which is the safe direction. A
     * source that resolves to nothing leaves none either: CSS says an image
     * that fails to load masks nothing.
     *
     * The four placement properties are comma lists of their own and they
     * cycle against the image list, exactly as the `background-*` ones do.
     *
     * @param  array<string,string>                        $computed
     * @param  array{0:float,1:float,2:float,3?:float}|null $currentColor
     * @return list<array<string,mixed>>
     */
    private function maskLayers(array $computed, ?array $currentColor): array
    {
        $declared = $this->styles->splitList($computed['mask-image'] ?? 'none');

        if ($declared === []) {
            return [];
        }

        $sizes      = $this->styles->splitList($computed['mask-size'] ?? 'auto');
        $repeats    = $this->styles->splitList($computed['mask-repeat'] ?? 'repeat');
        $positions  = $this->styles->splitList($computed['mask-position'] ?? '0% 0%');
        $modes      = $this->styles->splitList($computed['mask-mode'] ?? 'match-source');
        $composites = $this->styles->splitList($computed['mask-composite'] ?? 'add');
        $origins    = $this->styles->splitList($computed['mask-origin'] ?? 'border-box');
        $clips      = $this->styles->splitList($computed['mask-clip'] ?? 'border-box');

        $layers = [];

        foreach ($declared as $index => $source) {
            // Each layer may name a file of its own and the list length is the
            // author's to choose, so it is bounded like every other loop over
            // untrusted input.
            if (count($layers) >= self::MAX_BACKGROUND_LAYERS) {
                break;
            }

            $source = trim($source);

            if ($source === '' || strtolower($source) === 'none') {
                continue;
            }

            $layer = $this->backgroundSource($source, $currentColor);

            if ($layer === null) {
                continue;
            }

            // `match-source` is the initial value and it means the source's
            // own kind decides. Every source this reads is an image, and CSS
            // Masking says an image is masked by its alpha.
            $mode = strtolower(trim($modes[$index % max(1, count($modes))] ?? 'match-source')) === 'luminance'
                ? 'luminance'
                : 'alpha';

            if ($layer['svg'] !== null && $mode !== 'luminance') {
                continue;
            }

            $operator = strtolower(trim(
                $composites[$index % max(1, count($composites))] ?? 'add',
            ));
            $origin = strtolower(trim($origins[$index % max(1, count($origins))] ?? 'border-box'));
            $clip   = strtolower(trim($clips[$index % max(1, count($clips))] ?? 'border-box'));

            $layers[] = [
                ...$layer,
                'mode'      => $mode,
                'repeat'    => strtolower(trim($repeats[$index % max(1, count($repeats))] ?? 'repeat')),
                'position'  => strtolower(trim($positions[$index % max(1, count($positions))] ?? '0% 0%')),
                'size'      => strtolower(trim($sizes[$index % max(1, count($sizes))] ?? 'auto')),
                'composite' => in_array($operator, self::MASK_OPERATORS, true) ? $operator : 'add',
                'origin'    => in_array($origin, self::MASK_BOXES, true) ? $origin : 'border-box',
                'clip'      => in_array($clip, self::MASK_CLIPS, true) ? $clip : 'border-box',
            ];
        }

        return $layers;
    }

    private function backgroundSource(string $source, ?array $currentColor): ?array
    {
        $gradient = $this->styles->gradient($source, $currentColor);

        if ($gradient !== null) {
            return ['image' => null, 'svg' => null, 'gradient' => $gradient];
        }

        $src = $this->styles->urlValue($source);

        if ($src === null) {
            return null;
        }

        $data = $this->decodeDataUri($src);

        if ($this->isSvgSource($src, $data)) {
            $svg = $data !== null
                ? SvgDocument::parse($data)
                : (($resolved = $this->resolveAsset($src)) === null ? null : SvgDocument::load($resolved));

            if ($svg !== null) {
                return ['image' => null, 'svg' => $svg, 'gradient' => null];
            }
        }

        $resolved = $this->resolveAsset($src);

        $image = match (true) {
            $data !== null     => PdfImage::parse($data, $this->styles->maxImageBytes()),
            $resolved !== null => PdfImage::load($resolved, $this->styles->maxImageBytes()),
            default            => null,
        };

        return $image === null ? null : ['image' => $image, 'svg' => null, 'gradient' => null];
    }

    /**
     * `outline`, which is a border that never enters the box model. The
     * default width of `medium` is Chrome's 3px, and a style of `none` or a
     * zero width means there is nothing to paint.
     *
     * @return array{width:float,style:string,color:array{0:float,1:float,2:float,3:float}}|null
     */
    private function outlineBox(array $computed, callable $length, ?array $currentColor = null): ?array
    {
        return $this->strokeBox($computed, $length, 'outline', $currentColor);
    }

    /**
     * A `<width> || <style> || <color>` stroke that is not an edge of the box:
     * `outline` and `column-rule`. Neither takes space, both default to
     * `currentcolor`, and both are absent unless a style is named.
     *
     * @param  array<string,string> $computed
     * @param  array{0:float,1:float,2:float,3?:float}|null $currentColor
     * @return array{width:float,style:string,color:array{0:float,1:float,2:float,3?:float}}|null
     */
    private function strokeBox(
        array $computed,
        callable $length,
        string $property,
        ?array $currentColor = null,
    ): ?array {
        $style = strtolower(trim($computed["$property-style"] ?? 'none'));

        if ($style === 'none' || $style === 'hidden') {
            return null;
        }

        $declared = strtolower(trim($computed["$property-width"] ?? 'medium'));

        $width = match ($declared) {
            'thin'   => 0.75,
            'medium' => 2.25,
            'thick'  => 3.75,
            default  => $length("$property-width") ?? 0.0,
        };

        if ($width <= 0.0) {
            return null;
        }

        $color = $this->styles->rgba($computed["$property-color"] ?? 'currentcolor', $currentColor);

        return $color === null ? null : ['width' => $width, 'style' => $style, 'color' => $color];
    }

    private function borderSides(array $computed, callable $length, ?array $currentColor = null): ?array
    {
        $sides = [];

        foreach (['top', 'right', 'bottom', 'left'] as $side) {
            $width = $length("border-$side-width") ?? 0.0;
            $style = strtolower(trim($computed["border-$side-style"] ?? 'none'));

            if ($width <= 0.0 || $style === 'none' || $style === 'hidden') {
                continue;
            }

            // CSS makes border-color default to currentColor, not to black.
            //
            // A fully transparent border still takes its width in the box
            // model, so it is kept here and skipped at paint time instead.
            // `border: Npx solid transparent` is an everyday spacing idiom and
            // dropping the side collapses the box by its own edges, which is
            // defect EH. A color that does not parse at all still comes back
            // null and is still dropped.
            $color = $this->styles->rgba(
                $computed["border-$side-color"] ?? 'currentcolor',
                $currentColor,
                keepTransparent: true,
            );

            if ($color === null) {
                continue;
            }

            $sides[$side] = [
                'width' => $width,
                'style' => $style,
                'color' => $color,
            ];
        }

        return $sides === [] ? null : $sides;
    }

    private function resolveAsset(string $src): ?string
    {
        return AssetPath::resolve($src, $this->styles->basePath);
    }

    /**
     * The payload of a `data:` URI, or null if this is not one. Base64 and
     * percent-encoded bodies are both in use; an SVG logo is usually the
     * latter, a PNG logo the former.
     */
    private function decodeDataUri(string $src): ?string
    {
        if (!preg_match('/^data:([^,]*),(.*)$/is', trim($src), $m)) {
            return null;
        }

        $body = $m[2];

        if (str_contains(strtolower($m[1]), ';base64')) {
            $decoded = base64_decode($body, true);

            return $decoded === false ? null : $decoded;
        }

        return rawurldecode($body);
    }

    private function isSvgSource(string $src, ?string $data): bool
    {
        if ($data !== null) {
            return str_contains(strtolower($src), 'image/svg')
                || preg_match('/^\s*(<\?xml|<svg)/i', $data) === 1;
        }

        return preg_match('/\.svgz?$/i', $src) === 1;
    }

    /**
     * A negative width or height is invalid CSS and is ignored rather than
     * applied, because otherwise calc() arithmetic can hand the layout engine a
     * negative used size.
     */
    private function sizeValue(?string $value, float $fs, bool $allowNegative = false): float|string|null
    {
        if ($value === null) {
            return null;
        }

        $v = trim($value);

        if ($v === '' || $v === 'auto') {
            return null;
        }

        if (str_ends_with($v, '%')) {
            return !$allowNegative && (float) rtrim($v, '%') < 0 ? null : $v;
        }

        $resolved = $this->styles->length($v, $fs, $this->rootFontSize);

        if ($resolved === null) {
            // Only a length the resolver could not settle at all is worth
            // asking the second question of, so nothing that works today
            // changes: a `calc()` with no percentage in it is resolved above.
            return $this->linearLength($v, $fs);
        }

        return !$allowNegative && $resolved < 0 ? null : $resolved;
    }

    /**
     * A `calc()` holding a percentage, as the `A + B%` pair layout can read.
     *
     * Defect DI: the percentage basis is a layout-time value and the cascade
     * runs before layout, so `StyleResolver::length()` is called here with no
     * basis, the `%` term resolves to null and the whole expression fails.
     * CSS only lets a percentage be added, subtracted or multiplied by a plain
     * number inside `calc()`, so the expression is **linear** in the basis and
     * evaluating it against 0 and against 100 says all of it.
     *
     * `min()`, `max()` and `clamp()` are not linear, so they cannot be
     * reduced this way and are left where they were: unsupported wherever a
     * percentage is one of their arguments.
     */
    private function linearLength(string $value, float $fs): ?string
    {
        if (!str_contains($value, '%') || !preg_match('/^calc\s*\(/i', $value)) {
            return null;
        }

        if (preg_match('/\b(?:min|max|clamp)\s*\(/i', $value)) {
            return null;
        }

        $atZero    = $this->styles->length($value, $fs, $this->rootFontSize, 0.0);
        $atHundred = $this->styles->length($value, $fs, $this->rootFontSize, 100.0);

        if ($atZero === null || $atHundred === null) {
            return null;
        }

        return Node::linearLength($atZero, $atHundred - $atZero);
    }

    /** A placement value that is a name rather than a number or span. */
    private function areaName(string $value): string
    {
        $v = trim($value);

        if ($v === '' || strtolower($v) === 'auto') {
            return '';
        }

        if (preg_match('/^-?\d+$/', $v) || preg_match('/^span\b/i', $v)) {
            return '';
        }

        return $v;
    }

    /**
     * A grid placement value: a line number, or `span N`.
     *
     * @return array{0:?int,1:int}
     */
    private function gridLine(string $value): array
    {
        $v = strtolower(trim($value));

        if ($v === '' || $v === 'auto') {
            return [null, 1];
        }

        if (preg_match('/^span\s+(\d+)$/', $v, $m)) {
            return [null, max(1, (int) $m[1])];
        }

        if (preg_match('/^-?\d+$/', $v)) {
            $n = (int) $v;

            return [$n > 0 ? $n : null, 1];
        }

        return [null, 1]; // named lines are not supported
    }

    /**
     * One axis of `overflow`, with the two scrolling values folded into
     * `hidden`: a PDF has no scrollbars, so `auto` and `scroll` clip exactly as
     * `hidden` does and differ from it nowhere the engine can see.
     * `docs/harness/probes/D8-overflow.html` is where that was measured.
     */
    /**
     * `overflow-clip-margin`, which is a visual box, a length, or one of each
     * in either order, and defaults to the padding box at zero.
     *
     * A negative length is invalid rather than clamped, and so is a second
     * length or a second box: CSS drops the whole declaration there, and
     * dropping it is the same as never writing it.
     *
     * @return array{0:string,1:float}
     */
    private function clipMargin(string $raw, float $fs): array
    {
        $parts = preg_split('/\s+/', strtolower(trim($raw)), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $box    = 'padding-box';
        $length = 0.0;
        $boxes  = 0;
        $lengths = 0;

        foreach ($parts as $part) {
            if (in_array($part, ['content-box', 'padding-box', 'border-box'], true)) {
                $box = $part;
                $boxes++;

                continue;
            }

            $resolved = $this->styles->length($part, $fs, $this->rootFontSize, null);

            if ($resolved === null || $resolved < 0.0) {
                return ['padding-box', 0.0];
            }

            $length = $resolved;
            $lengths++;
        }

        return $boxes > 1 || $lengths > 1 ? ['padding-box', 0.0] : [$box, $length];
    }

    private static function overflowKeyword(string $v): string
    {
        return match (strtolower(trim($v))) {
            'hidden', 'scroll', 'auto' => 'hidden',
            'clip'                     => 'clip',
            default                    => 'visible',
        };
    }

    /** CSS blend keywords map onto PDF's /BM names. */
    private function blendKeyword(string $v): string
    {
        return match (strtolower(trim($v))) {
            'multiply'    => 'Multiply',
            'screen'      => 'Screen',
            'overlay'     => 'Overlay',
            'darken'      => 'Darken',
            'lighten'     => 'Lighten',
            'color-dodge' => 'ColorDodge',
            'color-burn'  => 'ColorBurn',
            'hard-light'  => 'HardLight',
            'soft-light'  => 'SoftLight',
            'difference'  => 'Difference',
            'exclusion'   => 'Exclusion',
            'hue'         => 'Hue',
            'saturation'  => 'Saturation',
            'color'       => 'Color',
            'luminosity'  => 'Luminosity',
            default       => 'normal',
        };
    }

    private function alignKeyword(string $v): string
    {
        return match (trim($v)) {
            'start', 'left' => 'flex-start',
            'end', 'right'  => 'flex-end',
            default         => trim($v),
        };
    }

    private function hyphensMode(array $computed): string
    {
        return match (strtolower(trim($computed['hyphens'] ?? 'manual'))) {
            'auto'  => 'auto',
            'none'  => 'none',
            default => 'manual',
        };
    }

    private function inlineVerticalAlign(array $computed): string
    {
        return match (strtolower(trim($computed['vertical-align'] ?? 'baseline'))) {
            'super'  => 'super',
            'sub'    => 'sub',
            // CSS 2.1 §10.8.1's two line-relative keywords. They cannot be a
            // constant offset the way `middle` and the two `text-*` ones are,
            // because the line box does not exist until every item on it has
            // been placed, so they travel to `InlineFormatter` and are seated
            // there. The other half of defect AF.
            'top'    => 'top',
            'bottom' => 'bottom',
            default  => 'baseline',
        };
    }

    /** CSS `direction`, or the HTML `dir` attribute, or content-derived. */
    private function direction(array $computed, DOMElement $el): string
    {
        $css = strtolower(trim($computed['direction'] ?? ''));

        if ($css === 'rtl' || $css === 'ltr') {
            return $css;
        }

        for ($n = $el; $n instanceof DOMElement; $n = $n->parentNode) {
            $dir = strtolower(trim($n->getAttribute('dir')));

            if ($dir === 'rtl' || $dir === 'ltr') {
                return $dir;
            }
        }

        return 'auto';
    }

    private function isItalic(array $computed): bool
    {
        return FontRegistry::italic($computed['font-style'] ?? 'normal');
    }

    private function isBold(array $computed): bool
    {
        return FontRegistry::bold($computed['font-weight'] ?? 'normal');
    }

    /**
     * `font-stretch` as a percentage of normal, which is the axis a family
     * with two registered widths is picked along.
     *
     * @param array<string,string> $computed
     */
    private function stretch(array $computed): float
    {
        return FontRegistry::width($computed['font-stretch'] ?? 'normal');
    }

    /**
     * The **used** `font-size`, which is the computed one unless
     * `font-size-adjust` asks for a different x-height.
     *
     * CSS Fonts 4 section 5.3: the value is the ratio the author wants between
     * the face's x-height and its size, so the used size is the computed size
     * times that ratio over the face's own. DejaVu Sans's own ratio is
     * 1120/2048, and Chrome reads `font-size-adjust: 0.75` on it as 1.371
     * times the size, which is exactly 0.75 over that.
     *
     * **The computed value is not adjusted and that is the whole distinction.**
     * `em`, `ex`, `ch`, `letter-spacing` and every other font-relative length
     * resolve against the computed size, and a child inherits the computed
     * size, so adjusting it here rather than at each use would scale a nested
     * box twice.
     *
     * @param array<string,string> $computed
     */
    private function usedFontSize(array $computed): float
    {
        $size   = $this->pt($computed['font-size'] ?? '12');
        $wanted = strtolower(trim($computed['font-size-adjust'] ?? 'none'));

        if ($size <= 0.0 || $wanted === '' || $wanted === 'none') {
            return $size;
        }

        /*
         * The two-value form, which is bullet 104. The grammar is
         *
         *     none | [ ex-height | cap-height | ch-width | ic-width | ic-height ]?
         *            [ from-font | <number> ]
         *
         * so a number on its own means `ex-height <number>` and a keyword picks
         * a different metric of the face to hold constant. On Verdana at 16px
         * and a ratio of 0.5 the five metrics are five used sizes:
         * `UL-size-adjust-metrics.html` reads 15, 15, 11, 13 and 8 CSS pixels
         * off Chrome and the engine was 16 on all but the first, because
         * anything that was not a bare number read as `none`.
         */
        $parts  = preg_split('/\s+/', $wanted) ?: [];
        $value  = (string) array_pop($parts);
        $metric = $parts === [] ? 'ex-height' : $parts[0];

        if (!in_array($metric, self::SIZE_ADJUST_METRICS, true)) {
            return $size;
        }

        /*
         * `from-font` takes the ratio off the FIRST AVAILABLE font rather than
         * naming one, and with nothing fallen back that font is the face this
         * element resolves to, so the ratio divides by itself and the used size
         * is the computed size. Chrome's `l7` and `l8` are the unadjusted band
         * exactly. **What it cannot express is the fallback case**, where the
         * point of the keyword is to scale a substituted face to the first
         * font's ratio: a used size is one number per element here and a
         * fallback is chosen per run, so there is nowhere to put a second one.
         */
        if ($value === 'from-font') {
            return $size;
        }

        if (!is_numeric($value)) {
            return $size;
        }

        $aspect = $this->fontFor($computed)->sizeAdjustMetric($metric, $size) / $size;

        return $aspect > 0.0 ? $size * (float) $value / $aspect : $size;
    }

    /** The metrics CSS Fonts 5 section 5.3 lets `font-size-adjust` hold. */
    private const array SIZE_ADJUST_METRICS = [
        'ex-height', 'cap-height', 'ch-width', 'ic-width', 'ic-height',
    ];

    /**
     * The first family in the list that resolves to a face we hold.
     *
     * @param array<string,string> $computed
     */
    private function family(array $computed): string
    {
        return FontRegistry::default()->resolveFamily($computed['font-family'] ?? 'Helvetica');
    }

    private function lineHeight(array $computed): float
    {
        $v = trim((string) ($computed['line-height'] ?? 'normal'));

        if ($v === 'normal') {
            return $this->normalLineHeight($computed);
        }

        if (is_numeric($v)) {
            return max(0.0, (float) $v);
        }

        $fs = $this->pt($computed['font-size'] ?? '12');

        if (str_ends_with($v, '%')) {
            return max(0.0, (float) rtrim($v, '%') / 100.0);
        }

        $abs = $this->styles->length($v, $fs, $this->rootFontSize);

        return $abs !== null && $fs > 0
            ? max(0.0, $abs / $fs)
            : $this->normalLineHeight($computed);
    }

    /**
     * `line-height: normal` is the font's own em box plus its line gap, which
     * is why it differs between families. Ask the face that will actually be
     * used rather than assuming a constant.
     *
     * @param array<string,string> $computed
     */
    private function normalLineHeight(array $computed): float
    {
        $font = FontRegistry::default()->get(
            $this->family($computed),
            $this->isBold($computed),
            $this->isItalic($computed),
            $this->stretch($computed),
        );

        // Defect DQ: a line box is a whole number of CSS pixels, so the ratio
        // depends on the size it is resolved at and cannot be a constant of the
        // face. `lineSpacing()` is the quantized height; everything downstream
        // still works in ratios, so this is the only place that has to know.
        $size = $this->usedFontSize($computed);

        return $size > 0.0 ? $font->lineSpacing($size) / $size : $font->normalLineHeight();
    }

    private function pt(string $v): float
    {
        return is_numeric($v) ? (float) $v : ((float) $v ?: 12.0);
    }
}
