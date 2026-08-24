<?php

declare(strict_types=1);

namespace FlexPDF\Engine;

use FlexPDF\Engine\Support\Deadline;
use FlexPDF\Engine\Support\Limits;

/**
 * CSS Flexible Box Layout Module Level 1, section 9 ("Flex Layout Algorithm").
 *
 * Implemented: 9.2 line length determination, 9.3 main size determination,
 * 9.4 cross size determination, 9.5 main-axis alignment, 9.6 cross-axis
 * alignment, 9.7 resolving flexible lengths. Auto margins absorb free space
 * before justify-content and align-self see it, per 9.5 and 9.6.
 *
 * Not implemented: order, reverse directions, baseline alignment, the
 * flex-flow shorthand, aspect ratios, nested writing modes. Each of those was
 * re-probed against Chrome and still diverges. See docs/ROADMAP.md section I.
 *
 * Fragmentation across pages is implemented, in Fragmenter.
 */
final class FlexLayout
{
    private static int $generation = 0;

    /**
     * Exclusion rects contributed by floats in the block currently being laid
     * out. Line boxes inside that block are shortened to avoid them.
     *
     * @var array<int,array{side:string,top:float,bottom:float,edge:float}>
     */
    private array $floats = [];

    /**
     * The floats of that same block whose bottom the flow has already passed.
     *
     * A float above the flow position shortens no line below it and answers no
     * `clear`, so keeping it in the live set only means rescanning it on every
     * line box. It is kept rather than dropped because the escape handover and
     * the §10.6.3 enclosure both need every float the block owns.
     *
     * @var array<int,array{side:string,top:float,bottom:float,edge:float}>
     */
    private array $retiredFloats = [];

    /** The flow position the retired set was last measured against. */
    private float $floatWatermark = -INF;

    /** @var (callable(float,float):array{0:float,1:float})|null */
    private $lineConstraint = null;

    private readonly Deadline $deadline;

    /** Layouts since the budget was last read. See checkBudget(). */
    private int $sinceCheck = 0;

    public function __construct(
        private readonly Font $font = new Font(),
        ?Deadline $deadline = null,
    ) {
        $this->deadline = $deadline ?? new Limits()->deadline();
    }

    /**
     * Layout is where a hostile document spends its time, so the wall-clock
     * budget has to be read during it rather than after it. Reading the clock
     * on every node would tax every ordinary document for the sake of the
     * pathological one, so it is sampled: a run between two reads is bounded
     * by the work in 512 nodes, which is far below the resolution any budget
     * is set at.
     */
    private function checkBudget(): void
    {
        if (++$this->sinceCheck < 512) {
            return;
        }

        $this->sinceCheck = 0;
        $this->deadline->check('layout');
    }

    private function fontFor(Node $n): Font
    {
        return $n->bold ? new Font('Helvetica-Bold', true) : $this->font;
    }

    /**
     * The page's own content height, which is the fragmentainer every column
     * is measured against. Zero until {@see layout()} sets it, which is what a
     * subtree laid out on its own reads. Defect HM.
     */
    private float $fragmentainer = 0.0;

    /**
     * How far down the document the box being laid out starts, in the root's
     * own coordinates, or null where the flow does not say. Block flow carries
     * it from parent to child; a flex, grid or table child is placed by its own
     * container and its offset is not this one, so those paths clear it and a
     * multi-column box inside one is filled the way it was before defect HT.
     */
    private ?float $flowTop = null;

    /**
     * How many boxes have read {@see $flowTop} to cap a column. The block loop
     * uses it to tell whether the child it has just laid out cares where its
     * top ended up, because the cursor it was laid out at is only exact once
     * the child's own collapsed margin and its clearance are in. Defect HT.
     */
    private int $columnStarts = 0;

    /**
     * Entry point. Lays out $root into a box of the given size.
     *
     * The size is the root's **containing block**, not its used width. CSS 2.1
     * §10.3.3 sizes the root box the way it sizes any other block in normal
     * flow, so a declared `width` wins over the fill, a `margin` comes out of
     * the room and places the box, and `auto` margins centre it. Nothing above
     * the root does any of that for it, which is the whole of defect DE: the
     * page assigned the root its used width and every declaration on `<body>`
     * except `max-width` reached nothing.
     */
    public function layout(Node $root, float $availableWidth, float $availableHeight): void
    {
        // The page's own content box, which is the fragmentainer a column is
        // as tall as. A block child is handed no height at all, so a
        // multi-column box could not ask its parent for one: defect HM.
        $this->fragmentainer = $availableHeight;

        // The root's own border edge, which is where the flow starts and what
        // every offset below is measured from: {@see accumulateOffsets} hands
        // each child its parent's position and the root's is its top margin.
        $this->flowTop = $root->margin['top'];

        self::$generation++;
        $root->isRoot = true;
        $root->resolveAgainstContainingBlock($availableWidth, $availableHeight);
        $this->layoutNode($root, max(0.0, $availableWidth - $root->marginMain(true)), $availableHeight);

        $root->x += $root->margin['left'] + $this->autoMarginShift($root, $availableWidth);
        $root->y += $root->margin['top'];

        $this->accumulateOffsets($root, 0.0, 0.0);

        // Out-of-flow boxes resolve against their containing block, which is
        // the nearest positioned ancestor (not necessarily their parent), so
        // they can only be placed once the flow is in absolute coordinates.
        $root->layoutWidth = $root->layoutWidth ?: $availableWidth;
        $this->placePositioned($root, $root, $availableWidth, $availableHeight);
        $this->applyRelativeOffsets($root);
    }

    /**
     * A cached layout is reusable when the new constraint is the one we
     * already used, or is exactly the size we already produced. The second
     * case is what makes the measure/layout double-pass free.
     */
    private function cacheCompatible(?float $requested, ?float $cached, float $result): bool
    {
        if ($requested === $cached) {
            return true;
        }

        return $requested !== null && abs($requested - $result) < 0.01;
    }

    /**
     * A declared length against its basis. The box is what answers, because a
     * percentage and the `calc()` pair beside it are both resolved against the
     * `max_length` ceiling the box carries. {@see Node::resolveLength}.
     */
    private function resolve(Node $n, float|string|null $value, ?float $basis): ?float
    {
        return $n->resolveLength($value, $basis);
    }

    /**
     * A declared `width` as the border-box length layout works in. Everything
     * downstream (layoutWidth, painting, fragmentation) is border-box, so the
     * conversion belongs here, at the one point a declared size is read.
     */
    /**
     * CSS Images §5.2's default object size, 300x150px in points. It is the
     * constraint rectangle a box with a ratio and no intrinsic size is fitted
     * inside when there is no containing block width to fill, and the size
     * itself when there is no ratio either (`OC-svg-viewbox-ratio.html` `c9`,
     * 225.000 x 112.500 in Chrome).
     */
    private const DEFAULT_OBJECT_WIDTH = 225.0;

    private const DEFAULT_OBJECT_HEIGHT = 112.5;

    /*
     * A declared width or height cannot be negative. A plain one never is,
     * because the builder drops it, but a `calc()` may only turn out negative
     * once the basis is known, and CSS clamps the used value to zero there
     * rather than discarding the declaration.
     */
    public function usedWidth(Node $n, ?float $basis): ?float
    {
        $declared = $this->resolve($n, $n->width, $basis);

        return $declared === null ? null : $n->toBorderBoxWidth(max(0.0, $declared));
    }

    /**
     * A declared height as the border-box length layout works in, or null
     * where there is not one.
     *
     * **A null basis is not a zero one.** CSS 2.1 §10.5 computes a percentage
     * height to `auto` when the containing block's height is indefinite, and
     * `resolveLength()` has always returned null for exactly that; what reached
     * it was `$availHeight ?? 0.0`, which turned "no basis" into "a basis of
     * zero" and collapsed the box. Part of defect DJ.
     */
    public function usedHeight(Node $n, ?float $basis): ?float
    {
        $declared = $this->resolve($n, $n->height, $basis);

        return $declared === null ? null : $n->toBorderBoxHeight(max(0.0, $declared));
    }

    /**
     * The declared width this box lays its own content out in, if it has one.
     *
     * CSS 2.1 §17.5.2: a table cell's `width` is an input to the column
     * algorithm and nothing else. Once the columns are resolved the used width
     * of every cell in a column is the column's, so reading the declaration a
     * second time here lays the lines out in a width that is neither the
     * column's nor the declaration's: a percentage resolves against the column
     * rather than against the table, and a length ignores the column entirely.
     *
     * **A row flex item's main size is the same kind of answer** and defect DS
     * was reading its declaration a second time too. The available width a row
     * item is laid out with is its own resolved main size, so `width: 32%`
     * resolved against that instead of against the container: a 32 percent item
     * of a 300pt row is 96.000 wide and laid its children out in 30.720, which
     * is 32 percent of itself. CSS Flexible Box §9.5 makes the main size the
     * used size, so the declaration has already been read by then.
     */
    private function declaredLayoutWidth(Node $n, ?float $availWidth): ?float
    {
        if ($n->display === 'table-cell' || $n->mainSizeIsUsedWidth) {
            return null;
        }

        return $this->usedWidth($n, $availWidth ?? 0.0);
    }

    /**
     * How far the floats on one side intrude across a vertical band.
     *
     * The list is passed in rather than read from $this: a line constraint is
     * a closure that outlives the block that built it, and by the time a
     * nested block invokes it the instance's float list has been reset.
     *
     * @param array<int,array{side:string,top:float,bottom:float,edge:float}>|null $floats
     */
    private function floatEdge(string $side, float $top, float $bottom, ?array $floats = null): float
    {
        $edge = 0.0;

        foreach ($floats ?? $this->floats as $f) {
            if ($f['side'] !== $side) {
                continue;
            }

            if ($f['bottom'] <= $top + 1e-6 || $f['top'] >= $bottom - 1e-6) {
                continue;
            }

            $edge = max($edge, $f['edge']);
        }

        return $edge;
    }

    /**
     * Every float the block being laid out owns, retired or live.
     *
     * @return array<int,array{side:string,top:float,bottom:float,edge:float}>
     */
    private function allFloats(): array
    {
        return $this->retiredFloats === []
            ? $this->floats
            : array_merge($this->retiredFloats, $this->floats);
    }

    /**
     * Move the floats the flow has passed out of the live set.
     *
     * This is exact rather than an approximation, on both readers of the live
     * set. `floatEdge()` already ignores a float whose bottom is at or above
     * the band it is asked about, and every band asked about from here on
     * starts at or below `$above`. `clearance()` takes a maximum that its
     * caller then maxes against the flow position, which is never above the
     * watermark, so a retired float cannot change the answer either.
     *
     * Without it, a parent that adopts one float from each of its children
     * rescans all of them on every line box, which is quadratic in the number
     * of children. A negative margin can put a later child back above the
     * watermark, and then the whole set is merged and partitioned again.
     */
    private function retireFloats(float $above): void
    {
        if ($above < $this->floatWatermark) {
            $this->floats        = $this->allFloats();
            $this->retiredFloats = [];
        }

        $this->floatWatermark = $above;

        if ($this->floats === []) {
            return;
        }

        $live = [];

        foreach ($this->floats as $f) {
            if ($f['bottom'] <= $above) {
                $this->retiredFloats[] = $f;

                continue;
            }

            $live[] = $f;
        }

        $this->floats = $live;
    }

    /**
     * Lay a box out at CSS 2.1 §10.3.5's shrink-to-fit width,
     * `min(max(preferred minimum width, available width), preferred width)`,
     * given the room its containing block has for it.
     *
     * This engine's spelling of shrink-to-fit is a **null** available width,
     * which answers the preferred width alone, so the room never reached the
     * box at all. Every shape that is sized this way was wrong because of it,
     * and each in its own direction:
     *
     * - a `float: left` holding `alpha be cd` in a 15pt block was 46.530 wide
     *   and one line tall against Chrome's 22.031 and two, hanging 31pt past
     *   the right edge of the block it sits in (`OH-float-shrink.html` `h1`);
     * - a **table** was handed nothing, which `TableLayout` reads as a zero
     *   containing block, so every column took its minimum: a floated table
     *   holding one line of text in a 150pt block was 22.014 wide and seven
     *   lines tall against Chrome's 144.082 and one (`hn`).
     *
     * A table is therefore handed the room and left alone: CSS 2.1 §17.5.2.2
     * is shrink-to-fit already, `min(available, what the columns ask for)`
     * over the width `TableLayout` is given, and §10.3.5 applied on top of it
     * would measure the table with a block's rules and get a different answer.
     */
    private function layoutShrinkToFit(Node $n, float $room, float $basis): void
    {
        if ($n->display === 'table' || $n->isTableWrapper) {
            $this->layoutNode($n, $room, null);

            return;
        }

        // §10.3.5 is what an **automatic** width means here. A declared one is
        // used as it stands, and a percentage of it resolves against the
        // containing block, which is `$basis` and not the room left over after
        // the margins. The null pass below resolves a percentage against zero,
        // so `float: left; width: 50%` was a box 0.000 wide (defect DF).
        if ($this->usedWidth($n, $basis) !== null) {
            $this->layoutNode($n, $basis, null);

            return;
        }

        $this->layoutNode($n, null, null);

        if ($n->layoutWidth <= $room + 1e-9) {
            return;
        }

        $fit = $this->clamp(
            max($this->minContentWidth($n), $room),
            $n->minWidth,
            $n->maxWidth,
        );

        // `minContentWidth()` is a measuring call with a side effect:
        // {@see contentMinWidth} lays every atomic inline under this box out at
        // its own preferred width, through `layoutAtomicInlines($runs, null)`,
        // and leaves it there. So the box is laid out again either way, at the
        // fit if the fit moved and at the same null pass if it did not, and the
        // cache has to stand down for it or it would answer with the poisoned
        // pass. The floor is the preferred minimum width and not the room,
        // which is what makes an overflow narrower rather than making the box
        // narrower than its own longest word: Chrome overflows too, it just
        // overflows by less.
        $n->cacheGen = -1;

        $this->layoutNode($n, $fit < $n->layoutWidth - 1e-9 ? $fit : null, null);
    }

    /** Push a float down until it fits beside whatever is already there. */
    private function fitFloat(Node $child, float $top, float $inner): float
    {
        $needed = $child->layoutWidth + $child->marginMain(true);
        $guard  = 0;

        while ($guard++ < 64) {
            $bottom    = $top + max($child->layoutHeight, 1.0);
            $available = $inner
                - $this->floatEdge('left', $top, $bottom)
                - $this->floatEdge('right', $top, $bottom);

            if ($needed <= $available + 1e-6) {
                return $top;
            }

            // Drop to the next float boundary below.
            $nextTop = null;

            foreach ($this->floats as $f) {
                if ($f['bottom'] > $top + 1e-6) {
                    $nextTop = $nextTop === null ? $f['bottom'] : min($nextTop, $f['bottom']);
                }
            }

            if ($nextTop === null) {
                return $top;
            }

            $top = $nextTop;
        }

        return $top;
    }

    /**
     * Whether an in-flow block child lays its floats out in its parent's float
     * context instead of opening one of its own.
     *
     * CSS 2.1 §9.4.1 and CSS Display §2.6: a float, an out-of-flow box, a
     * table cell or caption, an inline-block, `flow-root` and anything that
     * is a **scroll container** all start a new block formatting context.
     * Every other in-flow block shares its parent's, and each of the eight
     * shapes here was measured against Chrome rather than read off the list.
     *
     * It is the scroll container that starts one, not the box that clips:
     * `overflow: clip` clips without scrolling and Chrome gives it no
     * formatting context at all. Around a child with a 16px `margin-top`,
     * `ZY-overflow-longhand.html` `yk` (`overflow: clip`) and `yl`
     * (`overflow: visible clip`) are **12.000** tall in Chrome, the margin
     * escaping through the top edge exactly as it does through `yj`'s plain
     * wrapper, where `ym` (`overflow: hidden`) is 24.000.
     */
    private function sharesFloatContext(Node $child): bool
    {
        return !$child->scrollContainer
            && !$child->flowRoot
            && !in_array($child->display, [
                'table', 'table-cell', 'table-caption', 'flex', 'grid',
            ], true);
    }

    /**
     * Whether a block-level child's own border box has to keep clear of the
     * floats beside it, rather than only the lines inside it.
     *
     * CSS 2.1 §9.5: the border box of a table, a block-level replaced element
     * and an in-flow box that establishes a new block formatting context must
     * not overlap the margin box of a float in the same context. Every other
     * in-flow block sits over the float and shortens its lines instead, which
     * is the `x0` control on `docs/harness/probes/D10-bfc-float.html`: Chrome
     * leaves it at x 0.000 across the full 300.000 where it moves the
     * `overflow: hidden` beside it to x 33.000 across 267.000.
     *
     * `display: rect` is this engine's replaced leaf, an `<img>` or an inline
     * `<svg>`, and it is asked rather than `$image` because Chrome moves a
     * replaced box whose file is missing exactly as it moves one that loaded.
     */
    private function avoidsFloats(Node $child): bool
    {
        return !$this->sharesFloatContext($child) || $child->display === 'rect';
    }

    /**
     * Whether this box's used width is whatever it is handed, so the space
     * left beside a float narrows it instead of moving it down.
     *
     * A declared width is the box's whatever room there is, and so is a width
     * a ratio derives from a declared height, so those are the two that can
     * fail to fit. Everything else either fills what it is given (block flow,
     * flex, grid) or shrink-wraps inside it (a table), and Chrome caps a
     * shrink-wrapped one at the room rather than moving it: `D11`'s `y3` is a
     * `display: table` whose text wants 279.594 and which Chrome makes
     * 267.000, beside the same float that moves `D10`'s `x8` down.
     */
    /**
     * The used width of the table inside a caption wrapper, which is the
     * wrapper's own width. Falls back to the room offered when there is no
     * table to measure.
     */
    private function tableWrapperWidth(Node $n, ?float $availWidth, ?float $availHeight): ?float
    {
        foreach ($n->children as $child) {
            if ($child->display !== 'table') {
                continue;
            }

            $this->layoutNode($child, $availWidth, $availHeight);

            return $child->layoutWidth;
        }

        return $availWidth;
    }

    private function shrinksToAvailableWidth(Node $n): bool
    {
        if ($this->usedWidth($n, 0.0) !== null || $n->aspectRatio !== null) {
            return false;
        }

        // A flex or a grid container is block-level and takes the width it is
        // handed, and it takes it from the CONTAINING BLOCK rather than from
        // what is inside it, so an EMPTY one narrows beside a float exactly as
        // a full one does. Without this clause `$n->children !== []` carried
        // the answer alone and an empty one was pushed below the float
        // instead: on `TR-column-bfc.html` t2 and t3, and on the same pair
        // outside a column, Chrome puts it at x 18 across 90 where this engine
        // put it at x 0 across 108 one row further down. Defect HF.
        if ($n->display === 'flex' || $n->display === 'grid') {
            return true;
        }

        return $n->children !== [] || $this->fillsAvailableWidth($n);
    }

    /**
     * The inline-axis span one vertical band leaves free, as an offset from
     * the content edge and a width.
     *
     * @param  array<int,array{side:string,top:float,bottom:float,edge:float}> $floats
     * @return array{0: float, 1: float}
     */
    private function floatBand(
        float $top,
        float $bottom,
        float $inner,
        array $floats,
        ?callable $inherited,
    ): array {
        $offset = $this->floatEdge('left', $top, $bottom, $floats);
        $width  = $inner - $offset - $this->floatEdge('right', $top, $bottom, $floats);

        if ($inherited !== null) {
            [$parentOffset, $parentWidth] = $inherited($top, $bottom - $top);

            $combined = max($offset, $parentOffset);
            $width    = min($offset + $width, $parentOffset + $parentWidth) - $combined;
            $offset   = $combined;
        }

        return [$offset, max(0.0, $width)];
    }

    /**
     * Place a child whose own border box keeps clear of the floats, and hand
     * back its top and its offset from the content edge.
     *
     * The band is the child's own rather than a line's, so its width and its
     * height define each other: a narrower box is taller, and a taller box
     * reaches further down into the floats below it. Chrome settles that by
     * iterating and so does this, from the widest box down, which is the
     * direction the room only ever shrinks in.
     * `docs/harness/probes/D12-bfc-float-band.html` `zb` is the shape that
     * says it is a band and not the top edge: 267.000 wide the box is two
     * lines and clears the wide float below the narrow one, 150.000 wide it is
     * three lines and does not, and Chrome's answer is the 150.000.
     *
     * A box that cannot narrow drops to the next float bottom instead and asks
     * again, which is `D10`'s `x8`: 285.000 does not fit in the 267.000 beside
     * the float, so Chrome puts it under the float at x 0.000 rather than
     * letting it overlap.
     *
     * **Every step has to buy something, because each one re-lays the subtree
     * out and the cost would otherwise compound per nesting level.** A narrower
     * box is normally taller, so the room only shrinks and two or three passes
     * settle it; a box that gets *shorter* as it narrows, a scaled image inside
     * one for instance, would otherwise trade two widths back and forth. So the
     * room must strictly shrink to earn another pass, and the guard is the
     * backstop rather than the bound.
     *
     * @param  array<int,array{side:string,top:float,bottom:float,edge:float}> $floats
     * @return array{0: float, 1: float}
     */
    private function fitBesideFloats(
        Node $child,
        float $top,
        float $inner,
        float $childWidth,
        array $floats,
        ?callable $inherited,
    ): array {
        $shrinks = $this->shrinksToAvailableWidth($child);
        $laidAt  = $childWidth;
        $start   = $child->margin['left'];
        $room    = $childWidth;
        $guard   = 0;

        while ($guard++ < 8) {
            $bottom = $top + max($child->layoutHeight, 1.0);

            [$offset, $width] = $this->floatBand($top, $bottom, $inner, $floats, $inherited);

            $start = max($child->margin['left'], $offset);
            $room  = max(0.0, min($inner - $child->margin['right'], $offset + $width) - $start);

            if ($shrinks) {
                if ($room >= $laidAt - 1e-6) {
                    break;
                }

                $laidAt = $room;
                $this->invalidate($child);
                $this->layoutNode($child, $room, null);

                continue;
            }

            if ($child->layoutWidth <= $room + 1e-6) {
                break;
            }

            $next = null;

            foreach ($floats as $f) {
                if ($f['bottom'] > $top + 1e-6) {
                    $next = $next === null ? $f['bottom'] : min($next, $f['bottom']);
                }
            }

            if ($next === null) {
                break;
            }

            $top = $next;
        }

        // The leftover the box does not use is still an `auto` margin's to
        // take, measured across the band rather than across the whole line.
        return [$top, $start + $this->autoMarginShift($child, $room + $child->marginMain(true))];
    }

    /**
     * Whether this box contains its in-flow children's vertical margins rather
     * than letting them collapse out through its own edges.
     *
     * CSS 2.1 §8.3.1: the margins of a box that establishes a new block
     * formatting context do not collapse with its in-flow children's. That is
     * the same question {@see sharesFloatContext} answers, plus the three
     * shapes it is never asked about, because their floats never had a parent
     * context to escape into: a float, an atomic inline and an out-of-flow box
     * each establish one of their own.
     *
     * Every shape is measured against Chrome on
     * `docs/harness/probes/D6-bfc-margin.html` and `D7-bfc-margin-more.html`.
     * Around a child with `margin-top: 12pt`, Chrome makes an `overflow:
     * hidden` wrapper, `overflow: auto`, `display: flow-root`, a float, an
     * `inline-block` and an absolutely positioned box all **24.000** tall,
     * where a plain wrapper is 12.000 and the margin escapes through its top
     * edge. A child's `margin-bottom` is contained the same way, and a wrapper
     * that already has padding was 32.000 either way, which is the control
     * saying this is the formatting context and not the edge.
     */
    /**
     * The top of the last line box in a laid-out box, in its parent's
     * coordinates, or null where it holds no lines.
     *
     * This is where §9.5.1 rule 6 puts a float that follows inline content:
     * the line holding that content is the one the float may not sit below.
     * The lines stack, so the last one starts a line's height above the box's
     * own bottom.
     */
    private function lastLineTop(Node $n): ?float
    {
        $lines = $n->lineBoxes;

        if ($lines === []) {
            foreach ($n->children as $child) {
                $top = $this->lastLineTop($child);

                if ($top !== null) {
                    return $n->y + $top;
                }
            }

            return null;
        }

        return $n->y + $n->layoutHeight - end($lines)->height;
    }

    private function containsChildMargins(Node $n): bool
    {
        return !$this->sharesFloatContext($n)
            || $n->float !== 'none'
            || $n->isOutOfFlow()
            || $n->display === 'inline-block';
    }

    /**
     * Take over the floats a child laid out in this block's float context,
     * moved from the child's content box into this one's.
     *
     * This is what makes an escaped float shorten the lines of the *following*
     * sibling and answer its `clear`, which is what Chrome does and what makes
     * §10.6.3 safe to implement: without it a shorter block would simply let
     * the next one paint over the float.
     */
    private function adoptFloats(Node $child, float $left, float $inner): void
    {
        if ($child->escapedFloats === []) {
            return;
        }

        $offsetLeft  = $child->x - $left + $child->edge('left');
        $childInner  = max(0.0, $child->layoutWidth - $child->edgeMain(true));
        $offsetRight = $inner - $offsetLeft - $childInner;

        foreach ($child->escapedFloats as $f) {
            $this->floats[] = [
                'side'   => $f['side'],
                'top'    => $f['top'] + $child->y,
                'bottom' => $f['bottom'] + $child->y,
                'edge'   => $f['edge'] + ($f['side'] === 'left' ? $offsetLeft : $offsetRight),
            ];
        }
    }

    /** Lowest edge of the floats a `clear` must drop below. */
    private function clearance(string $clear): float
    {
        $y = 0.0;

        foreach ($this->floats as $f) {
            if ($clear === 'both' || $f['side'] === $clear) {
                $y = max($y, $f['bottom']);
            }
        }

        return $y;
    }

    /**
     * Two adjoining margins become one: the larger if both are positive, the
     * more negative if both are negative, and their sum when they disagree.
     */
    private function collapseMargins(float $a, float $b): float
    {
        if ($a >= 0 && $b >= 0) {
            return max($a, $b);
        }

        if ($a < 0 && $b < 0) {
            return min($a, $b);
        }

        return $a + $b;
    }

    private function clamp(float $v, ?float $min, ?float $max): float
    {
        if ($max !== null) {
            $v = min($v, $max);
        }

        if ($min !== null) {
            $v = max($v, $min);
        }

        // A used size is never negative, however the constraints combine.
        return max(0.0, $v);
    }

    // ------------------------------------------------------------------
    // Measurement
    // ------------------------------------------------------------------

    /**
     * Measure a leaf. Returns [width, height].
     * This is the callback boundary, the equivalent of Yoga's measure func.
     */
    private function measureLeaf(Node $n, ?float $availableWidth): array
    {
        if ($n->display !== 'text') {
            $w = $this->usedWidth($n, $availableWidth ?? 0.0) ?? 0.0;
            $h = $this->usedHeight($n, 0.0) ?? 0.0;

            return [$w, $h];
        }

        $runs = $n->inlineRuns();

        if ($runs === []) {
            return $this->measureEmpty($n);
        }

        $this->layoutAtomicInlines($runs, $availableWidth);

        $inline     = new InlineFormatter();
        $maxContent = $n->cachedMaxContent ??= $inline->maxContentWidth($runs);

        $explicit = $this->usedWidth($n, $availableWidth ?? 0.0);

        if ($explicit !== null) {
            $w = $explicit;
        } elseif ($availableWidth === null) {
            // Intrinsic sizing pass: only the width is consumed (a row
            // container derives flex base size from it). Building line boxes
            // here would be thrown away on the real pass, so do not, but mark
            // the node so this partial result never gets cached.
            $n->partialMeasure = true;

            return [$maxContent, 0.0];
        } else {
            $w = min($maxContent, max($availableWidth, 0.0));
        }

        /*
         * The lines are laid out in the containing block's content width, not
         * in the width this anonymous box measured for itself. `text-align`
         * aligns inside the containing block (CSS 2.1 §16.2), and a line
         * shorter than that block was being aligned inside its own
         * shrink-wrapped width, where there is nothing to align in: a
         * right-aligned `Alpha beta gamma` in a 240px paragraph stayed at
         * x 0.000 against Chrome's 104.449, and so did a centred one.
         *
         * The box still *measures* $w, which is what a shrink-to-fit parent
         * and a table column read, and a shrink-to-fit parent passes its own
         * resolved width down here, so a floated right-aligned line still does
         * not move. Any block holding a float already took this path: the line
         * constraint reports the containing block's width, which is why the
         * same paragraph aligned correctly with a float above it and not
         * without one.
         */
        $lineWidth = max($explicit ?? $availableWidth ?? $w, 1.0);

        $n->lineWidth = $lineWidth;
        $n->lineBoxes = $inline->format(
            $runs,
            $lineWidth,
            $n->textAlign,
            $this->lineConstraint,
            $n->direction,
            $n->strut,
            $this->usedTextIndent($n, $availableWidth ?? $w),
        );

        if ($n->textOverflow === 'ellipsis') {
            $n->lineBoxes = $inline->applyEllipsis($n->lineBoxes, $lineWidth);
        }

        $h = 0.0;

        foreach ($n->lineBoxes as $lb) {
            $h += $lb->height;
        }

        return [$w, $h];
    }

    /**
     * How a flex line shares out its free main-axis space: the offset before
     * the first item, and the gap to insert between each pair after it.
     *
     * {@see staticInside()} asks it with a count of one, because CSS Flexible
     * Box section 4.1 places an out-of-flow child as the SOLE FLEX ITEM of its
     * container and the sharing has to be the same rule the real items get.
     *
     * @return array{0:float,1:float}
     */
    private function mainDistribution(string $mode, float $free, int $count): array
    {
        return match ($mode) {
            'flex-end'      => [$free, 0.0],
            'center'        => [$free / 2, 0.0],
            'space-between' => [0.0, $count > 1 ? $free / ($count - 1) : 0.0],
            'space-around'  => [$count > 0 ? $free / $count / 2 : 0.0, $count > 0 ? $free / $count : 0.0],
            'space-evenly'  => [$count > 0 ? $free / ($count + 1) : 0.0, $count > 0 ? $free / ($count + 1) : 0.0],
            default         => [0.0, 0.0],
        };
    }

    /**
     * Where a single box sits in free space along one axis. Used by
     * {@see GridLayout} for every item it places and by
     * {@see staticInside()} for the one it does not.
     */
    public function alignOffset(string $mode, float $free): float
    {
        if ($free <= 0.0) {
            return 0.0;
        }

        return match ($mode) {
            'flex-end', 'end' => $free,
            'center'          => $free / 2,
            default           => 0.0,
        };
    }

    /**
     * How far an out-of-flow child with no offsets of its own sits from the
     * CONTENT origin of a flex or a grid container, which is where
     * {@see placePositioned()} starts every out-of-flow child.
     *
     * CSS Flexible Box section 4.1 puts the box where it would be as the sole
     * flex item of the container, so `justify-content` reaches it along the
     * main axis and `align-items` along the cross one, and the box it is
     * placed in is the container's content box. CSS Grid section 9.1 says the
     * same against a grid area, and the area it names is the container's
     * PADDING box, which is the one thing the two specs spell differently.
     *
     * Both edges were measured rather than recalled. On
     * `TA-static-flexgrid.html` a4 Chrome puts a padded flex container's box at
     * 20 by 20 and a6 puts a padded grid container's at 0 by 0, and a9 says a
     * reversed main axis mirrors the share the way a real item's does. Eleven
     * bands, and the engine read 5 of them before this. Defect GJ.
     *
     * Block flow is neither of these and never arrives here:
     * {@see staticFlowY()} reads a flow cursor, and a flex or a grid container
     * has none.
     *
     * @return array{0:float,1:float}
     */
    private function staticInside(Node $host, Node $child): array
    {
        if ($host->display !== 'flex' && $host->display !== 'grid') {
            return [0.0, 0.0];
        }

        $outerWidth  = $child->layoutWidth + $child->marginMain(true);
        $outerHeight = $child->layoutHeight + $child->marginCross(true);

        if ($host->display === 'grid') {
            // The area is the padding box, so the origin steps back off the
            // content edge onto it and the free space grows by the padding.
            $areaWidth  = max(0.0, $host->layoutWidth - $host->edgeMain(true))
                + $host->padding['left'] + $host->padding['right'];
            $areaHeight = max(0.0, $host->layoutHeight - $host->edgeCross(true))
                + $host->padding['top'] + $host->padding['bottom'];

            return [
                $this->alignOffset(
                    $child->justifySelf ?? $host->justifyItems,
                    $areaWidth - $outerWidth,
                ) - $host->padding['left'],
                $this->alignOffset(
                    $child->alignSelf ?? $host->alignItems,
                    $areaHeight - $outerHeight,
                ) - $host->padding['top'],
            ];
        }

        $row       = $host->isRow();
        $innerMain = max(0.0, $row
            ? $host->layoutWidth - $host->edgeMain(true)
            : $host->layoutHeight - $host->edgeCross(true));
        $innerCross = max(0.0, $row
            ? $host->layoutHeight - $host->edgeCross(true)
            : $host->layoutWidth - $host->edgeMain(true));

        $mainFree  = $innerMain - ($row ? $outerWidth : $outerHeight);
        $crossFree = $innerCross - ($row ? $outerHeight : $outerWidth);

        [$main] = $this->mainDistribution($host->justifyContent, max(0.0, $mainFree), 1);
        $cross   = $this->alignOffset($child->alignSelf ?? $host->alignItems, $crossFree);

        // A reverse direction mirrors the sole item inside the free space, and
        // `wrap-reverse` does the same to the cross axis, which is the trick
        // `layoutLines()` uses so that every alignment rule is spelled once.
        if ($host->isReverse()) {
            $main = max(0.0, $mainFree) - $main;
        }

        if ($host->flexWrap === 'wrap-reverse') {
            $cross = max(0.0, $crossFree) - $cross;
        }

        return $row ? [$main, $cross] : [$cross, $main];
    }

    /**
     * Where an out-of-flow child written at $written would have gone.
     *
     * The cursor recorded for the NEXT in-flow sibling and not the previous
     * one's: `$flowAt` holds the position each child was reached AT, so the
     * place a box written between two of them belongs is where the one after it
     * starts. With nothing after it the box really is last and the cursor the
     * caller has got to is the answer, which is what this engine always did and
     * is why `SZ-static-position.html` a2 was right before this and a0 was not.
     *
     * @param array<int,float> $flowAt
     */
    private function staticFlowY(array $flowAt, int $written, float $end): float
    {
        $next = null;

        foreach ($flowAt as $at => $_) {
            if ($at > $written && ($next === null || $at < $next)) {
                $next = $at;
            }
        }

        return $next === null ? $end : $flowAt[$next];
    }

    /**
     * How far an item's own content moves down so its marker's line can sit
     * above it, in points. Defect FU.
     *
     * An item whose only content is a block hosts its marker on a line of its
     * own, which round 54 landed as a `minHeight` floor plus a strut. What it
     * did not do is put the content anywhere: a nested list stayed at the top
     * and overlapped the line its own bullet sits on.
     *
     * **Chrome pushes the content down until its FIRST BASELINE is no higher
     * than the item's own strut baseline**, and it is a floor rather than an
     * offset, which is what three rounds of fitting could not see. A nested
     * list at 40px inside a 24px item is already below that line and is not
     * moved at all, so the push is zero there and every fit against the outer
     * font size alone had to fail.
     *
     * `SY-nested-face.html` pairs every band with the same markup at
     * `list-style-type: none`, so the push is the difference of two baselines
     * and the inner font's own ascent cancels: **9 of 9 pairs** over four
     * outer sizes, three line-heights and three nested sizes, and the same
     * rule reproduces all 8 subject bands of `SU-nested-list.html`, which is
     * written in Helvetica.
     *
     * Both terms are already in the engine. The strut baseline is
     * `emptyLine()`'s, which is the line `measureEmpty()` sizes the item with
     * and the line `BoxPainter::paintHostedLabel()` draws the marker on, and
     * the content's is {@see Node::firstLineBaseline}. A block child with no
     * line box in it has no baseline to align, and Chrome leaves it where it
     * is: `SY-nested-face.html`'s own b22 and `ST-marker-rows.html` t5 and t6.
     *
     * **The two sides are one rule and this is where both are decided.** When
     * the content's baseline is the LOWER of the two there is nothing to push,
     * and Chrome moves the marker down to meet it instead of leaving it on a
     * line of its own: b14 is a 40px nested list inside a 24px item and its
     * bullet is 6 pixels below where the strut alone would put it. That
     * distance is recorded on the node for the painter, because it is the
     * same subtraction read the other way round.
     *
     * **And the marker does not have to be on this box.** An item whose block
     * child holds a line hangs the marker on that child's text box rather than
     * hosting one, so asking the item alone whether it has a marker reads no
     * and nothing moves: `<li><ul>` was pushed and `<li><p>` was not, which is
     * one rule behaving two ways. `SY-nested-face.html` p9 and p10 read a push
     * of 20 and 40 in Chrome, the same two numbers p1 and p5 read with a
     * nested list in the same place. Defect GD.
     *
     * **And the one-line floor holds only where there is no line to share.**
     * Round 54 gave every item whose content is a block a `minHeight` of one
     * line, on a page whose block child holds no line at all. Where the child
     * does hold one, Chrome makes the item exactly as tall as that child plus
     * the distance it moved and no taller: the twelve subject bands of
     * `SY-nested-face.html` are `content + push` to the pixel, and an item at
     * 48px over a 40px line-height is 39 tall in Chrome where a floor would
     * make it 40. So the floor is applied here, where the answer to "is there
     * a line" has already been computed, rather than in the builder, which
     * cannot tell a nested list from an empty `<div>`. Defect GE.
     */
    private function markerLinePush(Node $n): float
    {
        $holder = $n->markerHung ?? $n;

        if ($n->strut === null || ($holder->marker === null && $holder->markerImage === null)) {
            return 0.0;
        }

        $content = $n->firstLineBaseline();

        if ($content === null) {
            /*
             * No line anywhere under the item, so the marker's line is the
             * only one and the item is at least as tall as it. An author's own
             * `min-height` wins, exactly as it does over the line a form
             * control floors itself with.
             */
            $n->minHeight ??= $n->lineHeight * $n->fontSize + $n->edgeCross(true);

            return 0.0;
        }

        $gap = ($content - $n->edge('top')) - new InlineFormatter()->emptyLine($n->strut)->baseline;

        $n->markerBaselineShift = max(0.0, $gap);

        return max(0.0, -$gap);
    }

    /**
     * A text box with nothing on it, which is no box at all unless it carries
     * a list marker.
     *
     * An empty `<li>` still takes a line and still shows its bullet, because
     * the marker is content on that line: `SN-list-marker.html` m8 keeps 20px
     * and a bullet in Chrome where this engine dropped the item entirely. The
     * line is empty of items, so nothing but the marker paints on it, and the
     * height is the strut's, which is exactly what CSS 2.1 §10.8 says an empty
     * line box takes. Defect FE.
     *
     * @return array{0:float,1:float}
     */
    private function measureEmpty(Node $n): array
    {
        $seed = $n->strut ?? $n->marker;

        if (($n->marker === null && $n->markerImage === null) || $seed === null) {
            return [0.0, 0.0];
        }

        $n->lineBoxes = [(new InlineFormatter())->emptyLine($seed)];

        return [0.0, $n->lineBoxes[0]->height];
    }

    /**
     * Lay out the atomic inlines a paragraph carries, before it is measured.
     *
     * An inline-block is sized like a float: with no available width, which is
     * this engine's spelling of shrink-to-fit. One that still does not fit the
     * line is laid out again against the room there is, so a long badge wraps
     * its own text instead of painting past the page.
     *
     * The box is laid out in its own coordinate space and its descendants keep
     * the parent-relative offsets layout gave them, so the line it lands on
     * decides where it paints and the same box paints correctly on whatever
     * page fragmentation puts that line on.
     *
     * Nothing here accumulates those offsets into absolute ones, deliberately:
     * a paragraph is measured more than once, the layout cache serves the
     * second pass without touching the subtree, and accumulating twice would
     * displace every descendant by its parent's coordinate a second time.
     * `BoxPainter` sums the offsets as it descends instead.
     *
     * @param InlineRun[] $runs
     */
    private function layoutAtomicInlines(array $runs, ?float $availableWidth): void
    {
        $outerFloats     = $this->floats;
        $outerRetired    = $this->retiredFloats;
        $outerWatermark  = $this->floatWatermark;
        $outerConstraint = $this->lineConstraint;

        foreach ($runs as $run) {
            $box = $run->box;

            if ($box === null) {
                continue;
            }

            $this->floats         = [];
            $this->retiredFloats  = [];
            $this->floatWatermark = -INF;
            $this->lineConstraint = null;

            $box->resolveAgainstContainingBlock(max(0.0, $availableWidth ?? 0.0));

            // A percentage width is definite once the containing block is
            // known, so a box that declares one is laid out against that
            // basis instead of shrink-to-fit. Passing it through as the
            // available width is safe precisely because `usedWidth()` answers
            // for a percentage, so the shrink-to-fit fallback never sees it,
            // and `width: auto` still reaches this with null.
            // A box with a ratio and no intrinsic size fills its containing
            // block rather than shrinking to fit, and an atomic inline's
            // containing block is the line's: Chrome makes an `<img>` of a
            // viewBox-only SVG inside a paragraph **300.000** wide in a 300pt
            // block, exactly as it makes an inline `<svg>` there
            // (`OC-svg-viewbox-ratio.html` `c5` and `c3`).
            $definite = is_string($box->width) || $box->ratioFill;

            if ($definite || $availableWidth === null) {
                $this->layoutNode($box, $definite ? $availableWidth : null, null);
            } else {
                $this->layoutShrinkToFit($box, max(0.0, $availableWidth - $box->marginMain(true)), $availableWidth);
            }

            $box->x = 0.0;
            $box->y = 0.0;
        }

        $this->floats         = $outerFloats;
        $this->retiredFloats  = $outerRetired;
        $this->floatWatermark = $outerWatermark;
        $this->lineConstraint = $outerConstraint;
    }

    /**
     * CSS automatic minimum size (`min-width: auto` on a flex item), which
     * resolves to the item's min-content size. Without this, containers
     * shrink below their contents and text collapses into a narrow column,
     * which is the single most common flexbox surprise.
     */
    private function autoMinMain(
        Node $n,
        bool $row,
        ?float $transferred = null,
        ?float $specifiedMain = null,
        ?float $availCross = null,
    ): float {
        // §4.5 gives a **scroll container** an automatic minimum size of zero,
        // whatever its content or its ratio would suggest, and it does so in
        // both axes. Chrome shrinks an `overflow: hidden` item straight past its
        // three lines, 13.336 where they are 36.000
        // (`ZS-column-automin-more.html` `k2`), squashes a 240px-tall image to
        // **78.000** in a 90pt column where the same image without the
        // declaration stays 600.000 (`ka` against `kb`), and in the row axis
        // shrinks a long word's item to **22.500** where its own min-content is
        // 60.000 (`ZW-row-automin-overflow.html` `v1`, with `v0` the same item
        // undeclared exact on both, and `v9` the transferred-size half at
        // 7.031 against `va`'s 7.500).
        //
        // It is a scroll container rather than a box that clips, and those are
        // not the same set: `overflow: clip` clips without scrolling, so §4.5
        // still applies to it. `v4` is **60.000** in Chrome where `v1` is
        // 22.500, and `vb`, the same declaration in the column axis, is 36.000
        // where reading `overflow` here gave it 13.333.
        if ($n->scrollContainer) {
            return 0.0;
        }

        // CSS Sizing §5.2's transferred size suggestion: with a ratio and a
        // definite cross size, the main axis has a size of its own and it is
        // that, not the content, that a `min-*: auto` floor is measured from.
        if ($transferred !== null && $specifiedMain === null) {
            return $transferred;
        }

        // The column axis has a content size suggestion of its own, and it is
        // the height the box takes at its used inline size: two items declaring
        // `height: 60px` in a 40px column are **36.000** and **12.000** in
        // Chrome, each floored at its own content, where sharing the shortfall
        // out with no floor gave 13.333 and 16.667 and an item beside one that
        // will not shrink reached **0.000** and vanished (defect BD,
        // `ZS-column-automin-more.html` `k0` and `k7`).
        if (!$row) {
            $content = $this->contentMinHeight($n, $availCross);

            return $specifiedMain === null ? $content : min($specifiedMain, $content);
        }

        // §4.5: the content-based minimum size is the smaller of the specified
        // size suggestion and the content size suggestion. The specified one is
        // handed in rather than read off the node, because `width: 50%` is only
        // a number once the containing block is known and reading it back as a
        // string loses it: Chrome floors a 50% image at 75.000 in a 150pt row.
        $content = $this->contentMinWidth($n);

        return $specifiedMain === null ? $content : min($specifiedMain, $content);
    }

    /**
     * CSS Flexible Box §4.5's content size suggestion in the **block** axis:
     * the height the box takes at its used inline size, with its own declared
     * height left out of it.
     *
     * There is no structural shortcut for this the way {@see contentMinWidth}
     * is one for the inline axis: how tall a box's content is depends on how
     * its lines break, so it is a layout pass. A box with no declared height
     * has already had that pass, at exactly these available sizes, by the time
     * §9.2 asks for a flex base size, so the cache answers it for free and only
     * an item that declares a height pays.
     */
    public function contentMinHeight(Node $n, ?float $availWidth): float
    {
        // A replaced element's intrinsic height is the shortest it goes,
        // exactly as its intrinsic width is the narrowest.
        if ($n->intrinsicHeight !== null) {
            return $n->intrinsicHeight + $n->edgeMain(false);
        }

        if ($n->height === null) {
            $this->layoutNode($n, $availWidth, null);

            return $n->layoutHeight;
        }

        $declared = $n->height;

        // The measured pass is not the layout this box will keep, so the cache
        // is dropped either side of it: serving it back to §9.2 would give the
        // item its content height and lose the declaration entirely.
        $n->height   = null;
        $n->cacheGen = -1;

        try {
            $this->layoutNode($n, $availWidth, null);

            return $n->layoutHeight;
        } finally {
            $n->height   = $declared;
            $n->cacheGen = -1;
        }
    }

    /**
     * A flex item's cross size when it is definite: either declared, or
     * settled in advance because the item stretches and its container's own
     * cross size is known. Null means it depends on the content, and an
     * aspect ratio then has nothing to transfer from.
     */
    private function definiteCross(Node $c, Node $item, bool $row, ?float $innerCross): ?float
    {
        $declared = $this->authoredLength($item, !$row, $innerCross ?? 0.0);

        if ($declared !== null) {
            return $declared;
        }

        if ($innerCross === null || ($item->alignSelf ?? $c->alignItems) !== 'stretch') {
            return null;
        }

        return max(0.0, $innerCross - $item->marginCross($row));
    }

    /** Narrowest width the box can take without overflowing its content. */
    public function minContentWidth(Node $n): float
    {
        if ($n->cachedMinContentBox !== null) {
            return $n->cachedMinContentBox;
        }

        $explicit = $n->display === 'text' || !is_float($n->width) ? null : $n->width;

        return $n->cachedMinContentBox = $explicit !== null
            ? $explicit + $n->edgeMain(true)
            : $this->contentMinWidth($n);
    }

    /**
     * The same width with the box's own declared one left out of it: CSS
     * Flexible Box §4.5's content size suggestion.
     *
     * A flex item's automatic minimum size is the **smaller** of its declared
     * width and this, so a declared width is a ceiling on the floor rather than
     * the floor itself. Reading it as the floor is what stopped items shrinking
     * at all: Chrome shrinks a `width: 300px` item to 132.352pt in a 150pt row,
     * where the engine froze it at 225 and overflowed the container by 75.
     */
    public function contentMinWidth(Node $n): float
    {
        // §4.5 again: the content size suggestion of a **replaced** element is
        // its intrinsic size, not the min-content width of whatever is drawn
        // inside it. A form control is replaced and this engine draws its value
        // with an ordinary text child, so without this the child answered for
        // the control and a flex line squeezed the box onto its own value
        // (`ON-form-control.html` `n3`, 61.499 against Chrome's 117.750).
        if ($n->replacedContent && $n->intrinsicWidth !== null && $n->children !== []) {
            return $n->cachedContentMin ??= $n->intrinsicWidth + $n->edgeMain(true);
        }

        if ($n->cachedContentMin !== null) {
            return $n->cachedContentMin;
        }

        // CSS Containment 3 section 2: `container-type: inline-size` and
        // `size` both contain the inline axis, so the box is measured **as if
        // it had no contents**. That is what makes a query container's own
        // width safe to ask a question about, and it is visible the moment a
        // container is a flex item: `RU-container-more.html` `u12` is three
        // `flex: 1 1 0` cells in a 240px row, and Chrome gives each 80px where
        // the first cell's 120px child would otherwise floor it at 120.
        if ($n->containerType !== 'normal') {
            return $n->cachedContentMin = $n->edgeMain(true);
        }

        if ($n->display === 'text') {
            $runs = $n->inlineRuns();

            if ($runs === []) {
                return $n->cachedContentMin = 0.0;
            }

            $this->layoutAtomicInlines($runs, null);

            return $n->cachedContentMin = $n->cachedMinContent
                ??= new InlineFormatter()->minContentWidth($runs);
        }

        // A replaced element is the one childless box with content to be
        // narrower than, and CSS Sizing §5.2.1 makes that content its own
        // width. Reading the empty child list as "no content" is what crushed
        // an `<img>` flex item to nothing: Chrome leaves a 240px image at
        // 180.000 in a 150pt row and overflows rather than shrink it.
        if ($n->children === []) {
            return $n->cachedContentMin = ($n->intrinsicWidth ?? 0.0) + $n->edgeMain(true);
        }

        // A row of flex items must fit all of them side by side; anything
        // else stacks, so the widest child wins.
        $isRow = ($n->display === 'flex' && $n->isRow()) || $n->display === 'table-row';
        $total = 0.0;

        foreach ($n->children as $child) {
            $cw = $this->minContentWidth($child) + $child->marginMain(true);
            if ($isRow) {
                $total += $cw;
            } else {
                $total = max($total, $cw);
            }
        }

        if ($isRow) {
            $total += max(0, count($n->children) - 1) * $n->gap;
        }

        return $n->cachedContentMin = $total + $n->edgeMain(true);
    }

    // ------------------------------------------------------------------
    // Layout
    // ------------------------------------------------------------------

    private function layoutNode(Node $n, ?float $availWidth, ?float $availHeight): void
    {
        $this->checkBudget();

        // A flex container measures each child, then lays it out again with a
        // resolved size. When the available space is unchanged between those
        // passes the result is identical, so re-running costs an exponential
        // amount of work down a deep tree. Cache on (available space).
        if ($n->cacheGen === self::$generation
            && $n->cacheMainIsUsed === $n->mainSizeIsUsedWidth
            && $this->cacheCompatible($availWidth, $n->cacheAvailW, $n->layoutWidth)
            && $this->cacheCompatible($availHeight, $n->cacheAvailH, $n->layoutHeight)
        ) {
            return;
        }

        $n->partialMeasure = false;
        $this->layoutNodeInner($n, $availWidth, $availHeight);

        if ($n->lineClamp > 0 && !$n->partialMeasure) {
            $this->applyLineClamp($n);
        }

        // An intrinsic-sizing pass returns a width but no line boxes, so its
        // result is NOT a complete layout and must never be served from cache.
        if ($n->partialMeasure) {
            $n->cacheGen = -1;

            return;
        }
        $n->cacheGen        = self::$generation;
        $n->cacheAvailW     = $availWidth;
        $n->cacheAvailH     = $availHeight;
        $n->cacheMainIsUsed = $n->mainSizeIsUsedWidth;
    }

    /**
     * `-webkit-line-clamp`: keep the first N lines of the box's own subtree,
     * drop the rest, and mark the last one that stayed.
     *
     * It runs after the box is laid out rather than inside the line breaker,
     * because the count is over the whole subtree and a child block does not
     * know how many lines its earlier siblings produced. `RX-clamp.html` n8 is
     * why: the two lines Chrome keeps there are one from each of two
     * paragraphs, and it paints the same picture as the one-paragraph slot.
     *
     * Truncating only ever removes content from the end, so no box before the
     * cut moves and the heights that change are the ones the cut reached.
     */
    private function applyLineClamp(Node $n): void
    {
        if ($this->countLines($n) <= $n->lineClamp) {
            return;
        }

        $budget = $n->lineClamp;
        $last   = null;

        $this->truncateLines($n, $budget, $last);

        if ($last !== null) {
            new InlineFormatter()->clampEllipsis(...$last);
        }

        /*
         * Chrome ends a clamped box at the bottom edge of the last line it
         * kept, so every margin below that line goes with the lines that were
         * dropped. Defect ER, and it is the one place the subtraction above
         * is not enough: `RX-clamp-margin.html` m1 clamps to two lines and
         * Chrome makes the card **42.000pt** where subtracting the lost line
         * from 63 makes it 51, and m2 clamps to one and Chrome makes it
         * **21.000** where subtracting two makes it 39. The difference is the
         * collapsed margin between the paragraphs plus the last one's
         * `margin-bottom`, which no longer has a line under it.
         *
         * It does not argue with the rule above it, which is about the boxes
         * *inside*: they keep the height the model that laid them out gave
         * them, minus what they lost. This is the clamped box's own height,
         * and the clamp is the thing that decided it.
         *
         * A declared height is the author's answer and the clamp does not
         * overrule it, which is the same guard {@see truncateLines} applies
         * one level in.
         */
        if ($n->height !== null) {
            return;
        }

        $bottom = $this->lastKeptLineBottom($n);

        if ($bottom !== null) {
            $n->layoutHeight = $this->clamp(
                $bottom + $n->edge('bottom'),
                $n->minHeight,
                $n->maxHeight,
            );
        }
    }

    /**
     * The bottom edge of the last line box left in this subtree, measured from
     * the top of `$n`'s own border box.
     *
     * Truncation only ever removes content from the end, so nothing on the way
     * down to that line has moved and the offsets layout gave them still hold.
     * A box with no line left under it reports nothing rather than zero, so an
     * empty paragraph after the cut cannot pull the answer back up.
     */
    private function lastKeptLineBottom(Node $n, float $offset = 0.0, int $depth = 0): ?float
    {
        if ($depth > self::MAX_COLUMN_DEPTH) {
            return null;
        }

        if ($n->display === 'text') {
            if ($n->lineBoxes === []) {
                return null;
            }

            foreach ($n->lineBoxes as $line) {
                $offset += $line->height;
            }

            return $offset;
        }

        $deepest = null;

        foreach ($n->children as $child) {
            if ($child->isOutOfFlow()) {
                continue;
            }

            $found = $this->lastKeptLineBottom($child, $offset + $child->y, $depth + 1);

            if ($found !== null) {
                $deepest = $deepest === null ? $found : max($deepest, $found);
            }
        }

        return $deepest;
    }

    /** How many line boxes the in-flow part of this subtree produced. */
    private function countLines(Node $n): int
    {
        if ($n->display === 'text') {
            return count($n->lineBoxes);
        }

        $total = 0;

        foreach ($n->children as $child) {
            if ($child->isOutOfFlow()) {
                continue;
            }

            $total += $this->countLines($child);
        }

        return $total;
    }

    /**
     * Keep the first `$budget` lines of the subtree, drop the rest, and pull
     * every box the cut reached back by exactly what it lost.
     *
     * The height is **subtracted** rather than re-derived from where the
     * children ended up. Re-deriving it means answering "how tall is this box"
     * with a second model, and the box already has an answer from the one that
     * laid it out: a table, a flex line and a block with collapsing margins
     * each got their height a different way, and none of them is the bottom of
     * the last child. Only ever removing what was removed cannot disagree with
     * any of them.
     *
     * A box with a declared height absorbs the cut and reports nothing upward:
     * the clamp decides how much text there is, not how tall the author said
     * the card was. So does anything that is not block flow, because a flex or
     * grid container's height is not the sum of its children's.
     *
     * @param array{0:LineBox,1:float}|null $last the last line kept and the
     *                                            width it was laid out in
     * @return float how much shorter this box got
     */
    private function truncateLines(Node $n, int &$budget, ?array &$last): float
    {
        if ($n->display === 'text') {
            $keep = max(0, min($budget, count($n->lineBoxes)));
            $budget -= $keep;
            $lost = 0.0;

            for ($i = $keep, $total = count($n->lineBoxes); $i < $total; $i++) {
                $lost += $n->lineBoxes[$i]->height;
            }

            if ($keep < count($n->lineBoxes)) {
                $n->lineBoxes = array_slice($n->lineBoxes, 0, $keep);
            }

            if ($keep > 0) {
                $last = [$n->lineBoxes[$keep - 1], $n->lineWidth];
            }

            $n->layoutHeight = max(0.0, $n->layoutHeight - $lost);

            return $lost;
        }

        $lost = 0.0;

        foreach ($n->children as $child) {
            if ($child->isOutOfFlow()) {
                continue;
            }

            $lost += $this->truncateLines($child, $budget, $last);
        }

        if ($lost <= 0.0) {
            return 0.0;
        }

        if ($n->height !== null || $n->display !== 'block') {
            return 0.0;
        }

        $n->layoutHeight = max(0.0, $n->layoutHeight - $lost);

        return $lost;
    }

    private function layoutNodeInner(Node $n, ?float $availWidth, ?float $availHeight): void
    {
        // CSS 2.1 §17.4: the box around a table and its caption takes the
        // table's used width, so the caption is as wide as the table and no
        // wider. Laying the table out first is what that costs: the caption
        // is a block child and fills whatever the wrapper turns out to be.
        // The caption of a `width: 120px` table is 90.000 in Chrome and was
        // 300.000 here, so a centred caption centred on the page (defect BM).
        if ($n->isTableWrapper) {
            $availWidth = $this->tableWrapperWidth($n, $availWidth, $availHeight);
        }

        /*
         * A flex, grid or table child is placed by its own container rather
         * than by the block flow, so the cursor this box was reached at is not
         * where its children start. The multi-column branch below is block flow
         * and keeps it; the three below take the fragmentainer they had before
         * defect HT, which is a whole page.
         */
        if ($n->display === 'flex' || $n->display === 'table' || $n->display === 'grid') {
            $this->flowTop = null;
        }

        if ($n->display === 'flex') {
            $this->layoutFlex($n, $availWidth, $availHeight);

            return;
        }

        if ($n->display === 'table') {
            new TableLayout($this)->layout($n, $availWidth);

            return;
        }

        if ($n->display === 'grid') {
            new GridLayout($this)->layout($n, $availWidth, $availHeight);

            return;
        }

        if ($this->isMulticol($n)) {
            $this->layoutMulticol($n, $availWidth, $availHeight);

            return;
        }

        if ($n->children === []) {
            [$w, $h] = $this->measureLeaf($n, $availWidth);

            // A childless block-level box still fills its containing block:
            // `width: auto` is not shrink-to-fit outside inline and flex
            // contexts. Without this a spacer div, an <hr> and any empty box
            // with a background collapse to nothing.
            //
            // A box with nothing in it is still as wide and as tall as its own
            // padding and border. `usedWidth()` and `usedHeight()` add the
            // edges to a *declared* size, so only the automatic one lost them:
            // Chrome gives `<div style="float:left;padding:4px"></div>` a
            // 6.000 x 6.000 border box and this engine gave it 0.000 x 0.000,
            // and a `<div style="border-top:1px solid">` separator took no
            // space in the flow at all.
            // The edge tests come first because they are two property reads
            // against a length resolve, and every text leaf in a document with
            // no padding anywhere in it reaches this line.
            $edgeMain  = $n->edgeMain(true);
            $edgeCross = $n->edgeCross(true);

            if ($this->fillsAvailableWidth($n) && $availWidth !== null) {
                $w = $availWidth;
            } elseif ($n->ratioFill) {
                // No containing block width to fill, which is the intrinsic
                // sizing pass a flex item's base size is measured in. CSS
                // Images §5.2's default object size, 300x150px, is the
                // constraint rectangle the ratio is fitted inside.
                $w = min(self::DEFAULT_OBJECT_WIDTH, $n->ratioWidth(self::DEFAULT_OBJECT_HEIGHT));
            } elseif ($edgeMain > 0.0 && $this->usedWidth($n, $availWidth ?? 0.0) === null) {
                $w += $edgeMain;
            }

            $declaredWidth  = $this->declaredLayoutWidth($n, $availWidth);
            $declaredHeight = $this->usedHeight($n, $availHeight);

            if ($edgeCross > 0.0 && $declaredHeight === null) {
                $h += $edgeCross;
            }

            // A definite height with an automatic width outranks the fill:
            // the ratio is what makes the width definite, so the box no
            // longer stretches to its containing block.
            if ($n->aspectRatio !== null && $declaredWidth === null && $declaredHeight !== null) {
                $w = $n->ratioWidth($declaredHeight);
            }

            $n->layoutWidth = $this->clamp($declaredWidth ?? $w, $n->minWidth, $n->maxWidth);

            if ($n->aspectRatio !== null && $declaredHeight === null) {
                $h = $n->ratioHeight($n->layoutWidth);
            }

            $unclampedHeight = $declaredHeight ?? $h;
            $n->layoutHeight = $this->clamp($unclampedHeight, $n->minHeight, $n->maxHeight);

            /*
             * CSS 2.1 §10.4: on a replaced element with an automatic width and
             * a ratio, a `min-height` or `max-height` that moves the height
             * takes the width with it. Clamping the height alone squashed the
             * picture: `<img style="max-height:40px">` on a 240x240 file is
             * 22.500 x 22.500 in Chrome and was 180.000 x 22.500 here, which is
             * everyday markup for a logo in a header.
             */
            if ($n->autoIntrinsicWidth && abs($n->layoutHeight - $unclampedHeight) > 1e-9) {
                $width = $n->intrinsicCross($n->layoutHeight, false);

                if ($width !== null) {
                    $n->layoutWidth = $this->clamp($width, $n->minWidth, $n->maxWidth);
                }
            }

            $n->collapsedMarginTop    = $n->margin['top'];
            $n->collapsedMarginBottom = $n->margin['bottom'];

            // The same §8.3.1 list as at the end of `blockLayout()`, for the box
            // that never gets there: a spacer `<div>` with margins and nothing
            // in it is the commonest self-collapsing box there is.
            $n->marginsCollapseThrough = !$n->isRoot
                && !$this->containsChildMargins($n)
                && $n->border === null
                && $n->edge('top') <= 0.0
                && $n->edge('bottom') <= 0.0
                && $n->layoutHeight <= 0.0;

            return;
        }

        // Plain block flow: children stack vertically, full width, with
        // adjoining vertical margins collapsing per CSS 2.1 §8.3.1.
        //
        // With no available width this is an intrinsic pass (a flex container
        // asking "how wide do you want to be?"). The answer is the shrink-to-fit
        // width, not zero: returning zero collapses every block-level flex
        // item down to its longest word.
        $declaredHeight = $this->usedHeight($n, $availHeight);

        // CSS 2.1 §10.4: `min-width` and `max-width` are applied to the
        // tentative used width and then the width calculation is done again
        // with the clamped value, so the lines are laid out in the width the
        // box ends up with. Clamping the finished box instead narrows the
        // border without re-wrapping anything inside it: a
        // `max-width: 30px` box holding `alpha be cd` was 22.500 wide and one
        // line tall against Chrome's 22.500 and two, with the text hanging out
        // of its own background (`OI-width-clamp.html` `i4`).
        $width = $this->clamp(
            $this->declaredLayoutWidth($n, $availWidth)
                ?? ($n->aspectRatio !== null && $declaredHeight !== null
                    ? $n->ratioWidth($declaredHeight)
                    : null)
                ?? $availWidth
                ?? $this->maxContentWidth($n),
            $n->minWidth,
            $n->maxWidth,
        );

        $inner = max(0.0, $width - $n->edgeMain(true));

        $this->resolveChildEdges($n, $inner, $this->usedHeight($n, $availHeight));

        // CSS 2.1 §10.5: a percentage `height` resolves against the containing
        // block's own height when that height is definite, and computes to
        // `auto` when it is not. The block walk used to hand every child a null
        // available height, so `height: 50%` resolved against zero and the
        // declaration reached nothing at all: defect DJ, and on a cover page it
        // takes the band's reversed-out text with it. `min-height` already
        // worked, through `resolveChildEdges()` above, which is what said the
        // basis was there and only the walk was not passing it.
        $definite   = $this->usedHeight($n, $availHeight);
        $childBasis = $definite === null
            ? null
            : max(0.0, $definite - $n->edge('top') - $n->edge('bottom'));

        // A border or padding on the edge stops a child's margin escaping
        // through it, so the collapse only happens through an open edge. The
        // root has no parent to escape to: CSS 2.1 §8.3.1 keeps its margins
        // out of the collapse, and without that a first child's top margin is
        // simply lost and the document starts higher than the author asked.
        // A box that establishes a formatting context contains the margin
        // whatever its edges look like, which is what {@see
        // containsChildMargins} asks and what an `overflow: hidden` card needs:
        // it was 12.000 tall against Chrome's 24.000.
        $collapses  = !$n->isRoot && !$this->containsChildMargins($n);
        $openTop    = $collapses && $n->edge('top') <= 0.0 && $n->border === null;
        $openBottom = $collapses && $n->edge('bottom') <= 0.0 && $n->border === null
            && $this->usedHeight($n, $availHeight) === null;

        // `$y` is the border bottom of the last child that ended a run of
        // adjoining margins, and `$run` is the one margin that whole run has
        // collapsed to, so the next box's border edge is `$y + $run`. A box
        // whose own margins are adjoining does not end the run: it is placed
        // inside it and hands its bottom margin back to it, which is CSS 2.1
        // §8.3.1's collapse *through*. `$atTop` says the run is still adjoining
        // this box's own top margin, which is what lets it escape.
        $y          = $n->edge('top');
        $run        = null;
        $atTop      = true;
        $runCleared = false;
        $firstTop   = 0.0;

        // Save and restore the float context, so what a nested block leaks into
        // its parent's is a deliberate handover rather than a side effect.
        $outerFloats          = $this->floats;
        $outerRetired         = $this->retiredFloats;
        $outerWatermark       = $this->floatWatermark;
        $outerConstraint      = $this->lineConstraint;
        $outerFlowTop         = $this->flowTop;
        $this->floats         = [];
        $this->retiredFloats  = [];
        $this->floatWatermark = -INF;

        $left = $n->edge('left');

        /*
         * The flow cursor as each in-flow child was reached, keyed by where
         * that child was WRITTEN.
         *
         * `HtmlBuilder::partition()` holds every out-of-flow child back to the
         * end of the block, deliberately: emitting one in place would close the
         * line it occurs on, and Appendix E paints a positioned box above the
         * in-flow content around it. So by the time the loop below reaches one,
         * the cursor is the end of the block and using it puts the box under
         * everything instead of where it was written. Defect GH.
         *
         * `Node::$sourceOrder` is 1-based, so nothing here collides with a
         * child's own.
         */
        $flowAt = [];

        foreach ($n->children as $i => $child) {
            if ($child->isOutOfFlow()) {
                /*
                 * CSS 2.1 §10.6.4: with `top` auto the box goes where it would
                 * have gone in the flow, and where it would have gone is where
                 * it was written rather than where the cursor has got to. It is
                 * recorded in this box's own coordinates because the flow is
                 * still relative here; `placePositioned()` reads it back once
                 * `accumulateOffsets()` has made both absolute. Defect DB.
                 *
                 * The same question the paint order already asks:
                 * `HtmlBuilder::sourceOrderChildren()` walks the held-back
                 * children back to their written places for Appendix E, and
                 * this is that walk read as a position instead of as an order.
                 */
                $child->staticX = $left + $child->margin['left'];
                $child->staticY = $this->staticFlowY(
                    $flowAt,
                    $child->sourceOrder,
                    $y + ($atTop && $openTop ? 0.0 : ($run ?? 0.0)),
                );

                continue;
            }

            $flowAt[$child->sourceOrder] = $y + ($atTop && $openTop ? 0.0 : ($run ?? 0.0));

            // Where this child starts down the document, for a multi-column box
            // that has to know how far down its page it is. It is the cursor
            // rather than the child's final top, which needs the child's own
            // collapsed margin and is not known until it has been laid out, so
            // the pass further down redoes the boxes those two move. Defect HT.
            $this->flowTop = $outerFlowTop === null
                ? null
                : $outerFlowTop + $flowAt[$child->sourceOrder];

            if ($child->isFloating()) {
                $this->layoutShrinkToFit($child, max(0.0, $inner - $child->marginMain(true)), $inner);

                // The position a box would take with no margins of its own,
                // which is where a float's own top margin starts from. A float
                // is not in the flow and so does not consume the run: the
                // in-flow sibling after it still collapses against the same
                // margin, which is why this reads the run rather than adding to
                // it.
                $flow = $y + ($atTop && $openTop ? 0.0 : ($run ?? 0.0));

                // §9.5.1 rule 6: a float written inside inline content may not
                // sit lower than the line box holding the content before it,
                // and Chrome puts it exactly at that line's top. The builder
                // has already closed those runs into the previous sibling, so
                // the cursor is a line too low by now: defect AW, 12.000pt of
                // it on `D16-float-static.html` `e2`. It only ever moves the
                // float **up**, so a float with nothing before it on its line
                // is untouched.
                if ($child->afterInlineContent && $i > 0) {
                    $lineTop = $this->lastLineTop($n->children[$i - 1]);

                    if ($lineTop !== null) {
                        $flow = min($flow, $lineTop);
                    }
                }

                // CSS 2.1 §9.5.1: a float's outer top edge sits at its static
                // position, which is below the preceding sibling's *margin*
                // edge and not its border edge, and a float's own margins never
                // collapse with anything, so its `margin-top` adds to that.
                // `clear` on a float moves that same outer edge (rule 8), where
                // on a block it moves the border edge.
                $top = $flow + $child->margin['top'];

                if ($child->clear !== 'none') {
                    $top = max($top, $this->clearance($child->clear) + $child->margin['top']);
                }

                // A float child does not advance the flow, so what the rest of
                // this block will look at is the lower of the flow position and
                // this float's own top.
                $this->retireFloats(min($y, $top));

                $top = $this->fitFloat($child, $top, $inner);

                $child->y = $top;
                $child->x = $child->float === 'left'
                    ? $left + $this->floatEdge('left', $top, $top + $child->layoutHeight) + $child->margin['left']
                    : $left + $inner - $this->floatEdge(
                        'right',
                        $top,
                        $top + $child->layoutHeight,
                    ) - $child->layoutWidth - $child->margin['right'];

                $this->floats[] = [
                    'side'   => $child->float,
                    'top'    => $top,
                    'bottom' => $top + $child->layoutHeight + $child->margin['bottom'],
                    'edge'   => $this->floatEdge(
                            $child->float,
                            $top,
                            $top + $child->layoutHeight,
                        ) + $child->layoutWidth + $child->marginMain(true),
                ];

                continue;
            }

            // Collapsed margins are only known after the child is laid out,
            // but a float constraint needs the child's final top. So lay out
            // once to learn the margins, then, only when floats are actually
            // in play, lay out again with the exclusions applied.
            $childWidth           = max(0.0, $inner - $child->marginMain(true));
            $child->floatsEscape  = $this->sharesFloatContext($child);
            $this->lineConstraint = null;
            $columnStarts         = $this->columnStarts;
            $this->layoutNode($child, $childWidth, $childBasis);

            $run = $run === null
                ? $child->collapsedMarginTop
                : $this->collapseMargins($run, $child->collapsedMarginTop);

            if ($atTop) {
                $firstTop = $run;
            }

            // What the run actually separates with. It is zero while the run is
            // still escaping through this box's own top edge, where the margin
            // moves the box itself rather than the child inside it.
            $posRun   = $atTop && $openTop ? 0.0 : $run;
            $childTop = $y + $posRun;

            // CSS 2.1 §9.5.2: clearance is the amount *necessary* to put the
            // border edge below the floats, so it is a floor under the position
            // the margins have already produced rather than something added to
            // it. `D14-float-prevmargin.html` `c0` declares `margin-top: 40px`
            // over a 15pt float and is at 30.000 in Chrome, not 45.000.
            $cleared = 0.0;

            if ($child->clear !== 'none') {
                $clearance = $this->clearance($child->clear);

                if ($clearance > $childTop) {
                    $cleared  = $clearance - $childTop;
                    $childTop = $clearance;
                }
            }

            /*
             * A multi-column box that reaches a second fragmentainer is filled
             * against the offset of its own top within its page, and the cursor
             * it was laid out at above is short by its own collapsed margin and
             * by any clearance it took. Both are in by here, so a box either of
             * them moved is laid out again at the top it actually got. Defect
             * HT, and it is the same shape as the float re-layout below.
             */
            if ($this->columnStarts > $columnStarts
                && $outerFlowTop !== null
                && abs($childTop - $flowAt[$child->sourceOrder]) > 1e-6
            ) {
                $this->flowTop = $outerFlowTop + $childTop;
                $this->invalidate($child);
                $this->layoutNode($child, $childWidth, $childBasis);
            }

            $this->retireFloats($childTop);

            $inheritedConstraint = $outerConstraint;
            $activeFloats        = $this->floats; // snapshot, not a live reference
            $placedX             = null;

            // A child that opens a formatting context of its own moves and
            // narrows rather than shortening its lines, and its contents never
            // see these floats at all, so it takes no line constraint either. A
            // retired float cannot reach it: retirement is by bottom edge and
            // this band starts at the child's own top.
            //
            // The other branch is asked of the whole set and not of the live
            // one, because a block that has passed every float in its context
            // still lays its lines out through the constraint, and skipping it
            // there would change the width they are measured against.
            $avoids = $this->avoidsFloats($child);
            $inBand = $activeFloats !== [] || $inheritedConstraint !== null;

            if ($avoids && $inBand) {
                [$childTop, $placedX] = $this->fitBesideFloats(
                    $child,
                    $childTop,
                    $inner,
                    $childWidth,
                    $activeFloats,
                    $inheritedConstraint,
                );
            } elseif (!$avoids && ($inBand || $this->retiredFloats !== [])) {
                $this->lineConstraint = function (float $lineY, float $lineH) use (
                    $childTop,
                    $inner,
                    $inheritedConstraint,
                    $activeFloats,
                ): array {
                    [$offset, $width] = $this->floatBand(
                        $childTop + $lineY,
                        $childTop + $lineY + $lineH,
                        $inner,
                        $activeFloats,
                        $inheritedConstraint,
                    );

                    return [$offset, max(1.0, $width)];
                };

                $this->invalidate($child);
                $this->layoutNode($child, $childWidth, $childBasis);
                $this->lineConstraint = null;
            }

            $child->x = $left + ($placedX ?? $child->margin['left'] + $this->autoMarginShift($child, $inner));
            $child->y = $childTop;

            // A box whose own top and bottom margins are adjoining is placed
            // inside the run and hands its bottom margin back to it, so the box
            // after it collapses against every margin in the run at once and
            // not against this one twice.
            //
            // Clearance does not end that, it moves the whole run down with the
            // box: `i4` is a cleared empty box with 12pt above and 24pt below
            // over a 15pt float, and Chrome puts the box at 15.000 and the next
            // one at **27.000**, which is the 24 the two margins collapse to
            // plus the 3 of clearance and neither 39 nor 24. What clearance does
            // end is the escape through this box's own top edge, and it stops
            // the run reaching the bottom edge as well: §8.3.1's own exception
            // is that the margin of an element with clearance does not collapse
            // with the parent's bottom margin, which is what makes the one-line
            // `<div style="clear:both">` grow the box it closes.
            if ($child->marginsCollapseThrough) {
                if ($cleared > 0.0) {
                    $y          += $cleared;
                    $run         = $this->collapseMargins($posRun, $child->collapsedMarginBottom);
                    $atTop       = false;
                    $runCleared  = true;
                } else {
                    $run = $this->collapseMargins($run, $child->collapsedMarginBottom);

                    if ($atTop) {
                        $firstTop = $run;
                    }
                }
            } else {
                $y          = $childTop + $child->layoutHeight;
                $run        = $child->collapsedMarginBottom;
                $atTop      = false;
                $runCleared = false;
            }

            $this->adoptFloats($child, $left, $inner);
        }

        $escapes = $n->floatsEscape;
        $owned   = $this->allFloats();

        // CSS 2.1 §10.6.3: a block whose height is `auto` and that establishes
        // no new formatting context does **not** grow to contain its floats. It
        // is the box that owns the float context that encloses them, which is
        // why this asks the box rather than doing it unconditionally: a one-line
        // block holding a 45pt float is 12pt tall in Chrome and was 45 here.
        /*
         * The marker's line goes in before the floats are folded in, because
         * the push moves the FLOW and a float's bottom is already where it is
         * going to be. Folding first and pushing after would move the item's
         * bottom edge by the push even where a tall float, and not the flow,
         * is what set it.
         */
        $push = $this->markerLinePush($n);

        if ($push > 0.0) {
            foreach ($n->children as $child) {
                /*
                 * A float and an out-of-flow box both sit against the item's
                 * own content edge and Chrome leaves both of them there: on
                 * `SZ-push-float.html` p0 and p3 the float stays at 0 while
                 * the block child beside it goes to 20, and p2's absolutely
                 * positioned child stays at 0 as well. The control is p1, the
                 * same float one level down inside the block child, which DOES
                 * move by the whole 20 on both engines, because there it is
                 * its containing block that moved. Defect GG.
                 */
                if ($child->isOutOfFlow() || $child->isFloating()) {
                    continue;
                }

                $child->y += $push;
            }

            $y += $push;
        }

        if (!$escapes) {
            foreach ($owned as $f) {
                $y = max($y, $f['bottom']);
            }
        }

        // Handed to the parent in this box's content box coordinates, because
        // the parent is where they belong and only the parent knows where this
        // box ended up.
        $n->escapedFloats     = $escapes ? $owned : [];
        $this->floats         = $outerFloats;
        $this->retiredFloats  = $outerRetired;
        $this->floatWatermark = $outerWatermark;
        $this->lineConstraint = $outerConstraint;
        $this->flowTop        = $outerFlowTop;

        $lastBottom = $run ?? 0.0;

        if (!$openBottom || $runCleared) {
            $y += $lastBottom;
        }

        $n->collapsedMarginTop = $openTop
            ? $this->collapseMargins($n->margin['top'], $firstTop)
            : $n->margin['top'];

        $n->collapsedMarginBottom = $openBottom && !$runCleared
            ? $this->collapseMargins($n->margin['bottom'], $lastBottom)
            : $n->margin['bottom'];

        $n->layoutWidth = $width;

        // CSS Containment 3 section 2: `container-type: size` contains the
        // block axis as well as the inline one, so the box is sized as if it
        // had no contents and whatever is inside it overflows. A sized
        // container with no declared height is as tall as its own padding and
        // border, which is nothing at all on an ordinary one, and its next
        // sibling starts where it started. That is what `RU-container-units.html`
        // `v7` reads off Chrome, and it is the other half of the inline-axis
        // containment {@see contentMinWidth} already applies: an author who
        // writes `container-type: size` writes a height with it, or the
        // container has no size to be queried about.
        $content  = ($n->containerType === 'size' ? $n->edge('top') : $y) + $n->edge('bottom');
        $declared = $declaredHeight;

        // A ratio makes the height definite, so content that does not fit
        // overflows rather than growing the box, which is what Chrome does.
        if ($declared === null && $n->aspectRatio !== null) {
            $declared = $n->ratioHeight($n->layoutWidth);
        }

        // CSS 2.1 §17.5.3: a table cell's specified height is a minimum, not
        // a definite size. Treating it as definite lets a cell report less
        // than it paints, and its row, its table and the whole flow inherit
        // that shortfall, so pagination ends up measured against a document
        // shorter than the one on the page.
        $used = $n->display === 'table-cell' && $declared !== null
            ? max($declared, $content)
            : $declared ?? $content;

        $n->layoutHeight = $this->clamp($used, $n->minHeight, $n->maxHeight);

        // CSS 2.1 §8.3.1's list, read off the finished box rather than off its
        // declarations. An open top edge already says there is no border, no
        // top padding and no formatting context of its own; the other two
        // conditions are the bottom edge and a height of nothing, which is what
        // `height: 0`, `min-height: 0` and an empty content box come to
        // together. Chrome agrees on both halves: an empty `height: 0` box
        // collapses through (`D16-float-static.html` `e9`) and an empty
        // `overflow: hidden` one does not (`e8`).
        $n->marginsCollapseThrough = $openTop
            && $n->edge('bottom') <= 0.0
            && $n->layoutHeight <= 0.0;
    }

    private function layoutFlex(Node $c, ?float $availWidth, ?float $availHeight): void
    {
        $row = $c->isRow();

        // --- 9.2 step 1: available space in the container's content box ---
        $explicitMain = $row
            ? $this->usedWidth($c, $availWidth ?? 0.0)
            : $this->usedHeight($c, $availHeight ?? 0.0);

        // A block-level flex container fills its containing block in the
        // inline axis. Only the block axis (a column with height:auto) is
        // genuinely indefinite and shrink-wraps to content.
        $mainDefinite = $explicitMain !== null || ($row && $availWidth !== null);

        $outerMain  = $explicitMain
            ?? ($row ? ($availWidth ?? 0.0) : ($availHeight ?? 0.0));
        $outerCross = $row
            ? ($this->usedHeight($c, $availHeight ?? 0.0))
            : ($this->usedWidth($c, $availWidth ?? 0.0) ?? $availWidth ?? 0.0);

        $innerMain  = max(0.0, $outerMain - $c->edgeMain($row));
        $innerCross = $outerCross === null ? null : max(0.0, $outerCross - $c->edgeCross($row));

        $this->resolveChildEdges(
            $c,
            $row ? $innerMain : ($innerCross ?? 0.0),
            $row ? $innerCross : $innerMain,
        );

        $items = array_values(array_filter($c->children, fn(Node $n) => !$n->isOutOfFlow()));

        // --- 5.4: order-modified document order. The box tree keeps document
        // order, so a reader, the fragmenter and the painter still see what the
        // author wrote; only the flex algorithm walks the reordered sequence.
        foreach ($items as $item) {
            if ($item->order !== 0) {
                usort($items, static fn(Node $a, Node $b): int => $a->order <=> $b->order);
                break;
            }
        }

        // --- 9.2 steps 3-4: flex base size and hypothetical main size ---
        foreach ($items as $item) {
            $basis = $item->flexBasis;

            $definiteCross = $this->definiteCross($c, $item, $row, $innerCross);

            // §4.5's specified size suggestion, which is the item's preferred
            // main size and not its flex basis: `flex-basis: 40px` on a 240px
            // image leaves Chrome's floor at the image's own 180.000, where a
            // `width: 40px` takes it down to 30.000.
            $explicitMain = $this->authoredLength($item, $row, $row ? $innerMain : ($innerCross ?? 0.0));
            $transferred  = $definiteCross === null
                ? null
                : $this->transferredMain($item, $definiteCross, $row);

            if ($basis === 'auto' || $basis === null) {
                if ($explicitMain !== null) {
                    $item->flexBaseSize = $explicitMain;
                } elseif ($transferred !== null) {
                    // §9.2.3.B: a ratio plus a definite cross size is a
                    // definite main size, so the item never measures content.
                    $item->flexBaseSize = $transferred;
                } elseif ($item->ratioFill && $row) {
                    // A box with a ratio and no intrinsic size fills its
                    // containing block, and inside a flex container that is
                    // the line itself: Chrome gives a viewBox-only `<img>` in
                    // a 150pt row a base size of 150.000 and lets the item
                    // beside it overflow (`OC-svg-viewbox-ratio.html` `cb`).
                    // Measuring it with no available width instead handed it
                    // CSS Images §5.2's default object size, which is what
                    // that pass is for everywhere else.
                    $item->flexBaseSize = max(0.0, $innerMain - $item->marginMain(true));

                    // An image's own content is that width, so §4.5's content
                    // size suggestion floors it there and it does not shrink.
                    // An inline `<svg>` is a document fragment with no
                    // min-content size and shrinks with the line (`ca`).
                    if ($item->replacedContent) {
                        $item->cachedContentMin = $item->flexBaseSize;
                    }
                } else {
                    $this->layoutNode($item, $row ? null : $innerCross, null);
                    $item->flexBaseSize = $row ? $item->layoutWidth : $item->layoutHeight;
                }
            } else {
                $item->flexBaseSize = $this->resolve($item, $basis, $innerMain) ?? 0.0;
            }

            $minMain = $row ? $item->minWidth : $item->minHeight;
            $maxMain = $row ? $item->maxWidth : $item->maxHeight;
            if ($minMain === null) {
                $minMain = $this->autoMinMain($item, $row, $transferred, $explicitMain, $innerCross);

                // §4.5's last clause: the automatic minimum is clamped by the
                // max main size. It never bit while a childless box measured
                // zero, and `max-width: 40px` on a 240px image is where it
                // does: Chrome bounds it at 30.000, not at the image's 180.
                if ($maxMain !== null) {
                    $minMain = min($minMain, $maxMain);
                }
            }

            $item->resolvedMinMain      = $minMain;
            $item->hypotheticalMainSize = $this->clamp($item->flexBaseSize, $minMain, $maxMain);
            $item->frozen               = false;
        }

        // --- 9.3: collect items into flex lines ---
        $lines = [];

        if ($c->flexWrap === 'nowrap') {
            $lines[] = $items;
        } else {
            $line = [];
            $used = 0.0;

            foreach ($items as $item) {
                $outer   = $item->hypotheticalMainSize + $item->marginMain($row);
                $withGap = $line === [] ? $outer : $used + $c->gap + $outer;

                if ($line !== [] && $withGap > $innerMain) {
                    $lines[] = $line;
                    $line    = [$item];
                    $used    = $outer;
                } else {
                    $line[] = $item;
                    $used   = $withGap;
                }
            }

            if ($line !== []) {
                $lines[] = $line;
            }
        }

        // --- 9.2.2: an indefinite main size resolves to the max-content size.
        // This must happen before 9.7, or items would shrink to fit space
        // the container was never actually constrained to.
        if (!$mainDefinite) {
            $contentMain = 0.0;

            foreach ($lines as $line) {
                $lineMain = max(0, count($line) - 1) * $c->gap;

                foreach ($line as $item) {
                    $lineMain += $item->hypotheticalMainSize + $item->marginMain($row);
                }

                $contentMain = max($contentMain, $lineMain);
            }

            $innerMain = $this->clamp(
                $contentMain,
                $row ? $c->minWidth : $c->minHeight,
                $row ? $c->maxWidth : $c->maxHeight,
            );

            $outerMain = $innerMain + $c->edgeMain($row);
        }

        // --- 9.7 + 9.4 + 9.5 + 9.6, per line ---
        $lineCrossSizes = [];
        $lineBaselines  = [];
        $itemBaselines  = [];
        foreach ($lines as $line) {
            $gapTotal = max(0, count($line) - 1) * $c->gap;
            $this->resolveFlexibleLengths($line, $innerMain - $gapTotal, $row);

            // Cross size of each item, now that main size is known.
            foreach ($line as $item) {
                // Margins wider than the container can drive the resolved size
                // below zero; a used size is never negative.
                $item->targetMainSize = max(0.0, $item->targetMainSize);
                $mainSize             = $item->targetMainSize;

                if ($row) {
                    // The main size is the used width from here on, so the
                    // item's own `width` declaration is not read again while
                    // it lays its children out. Defect DS.
                    $item->mainSizeIsUsedWidth = true;
                    $this->layoutNode($item, $mainSize, $innerCross);
                    $item->mainSizeIsUsedWidth = false;

                    $item->layoutWidth = $mainSize;
                    $this->applyIntrinsicCross($item, $mainSize, true);

                    continue;
                }

                // In a column the cross axis is the inline one, where a block
                // fills whatever width it is offered. Only `stretch` wants
                // that: every other alignment has to know the item's own
                // width first, so it is sized shrink-to-fit, which is what
                // passing no available width means here.
                $this->layoutNode($item, $this->stretchesCross($c, $item, $row) ? $innerCross : null, $mainSize);
                $item->layoutHeight = $mainSize;
                $this->applyIntrinsicCross($item, $mainSize, false);
            }

            $lineCross = 0.0;
            $above     = 0.0;
            $below     = 0.0;
            $baselines = [];

            foreach ($line as $ii => $item) {
                $outer = ($row ? $item->layoutHeight : $item->layoutWidth) + $item->marginCross($row);

                // 9.4 step 8: a baseline-aligned line is as tall as the largest
                // distance above any item's baseline plus the largest below it,
                // which is more than the tallest item whenever two of them sit
                // on the same baseline with different amounts above it.
                if ($this->alignsOnBaseline($c, $item, $row)) {
                    $baselines[$ii] = $item->firstBaselineOffset();
                    $above          = max($above, $baselines[$ii]);
                    $below          = max($below, $outer - $baselines[$ii]);

                    continue;
                }

                $lineCross = max($lineCross, $outer);
            }

            $lineCrossSizes[] = max($lineCross, $above + $below);
            $lineBaselines[]  = $above;
            $itemBaselines[]  = $baselines;
        }

        // Single-line containers with a definite cross size take that size.
        if (count($lines) === 1 && $innerCross !== null) {
            $lineCrossSizes[0] = $innerCross;
        }

        // --- 9.6 align-content: the lines share the cross axis ---
        [$linesOffset, $linesBetween] = $this->alignLines($c, $lineCrossSizes, $innerCross);

        // --- 9.5 main-axis alignment + 9.6 cross-axis alignment ---
        $crossStart  = $row ? $c->edge('top') : $c->edge('left');
        $crossCursor = $crossStart + $linesOffset;

        foreach ($lines as $li => $line) {
            $lineCross = $lineCrossSizes[$li];
            $gapTotal  = max(0, count($line) - 1) * $c->gap;

            $usedMain = $gapTotal;

            foreach ($line as $item) {
                $usedMain += $item->targetMainSize + $item->marginMain($row);
            }

            $free = max(0.0, $innerMain - $usedMain);
            $n    = count($line);

            /*
             * 9.5 step 1: auto margins on the main axis take all the free
             * space, split evenly between them, and justify-content is only
             * consulted with what is left, which is nothing.
             */
            $autoMains = 0;

            foreach ($line as $item) {
                $autoMains += $this->autoMainCount($item, $row);
            }

            $perAuto = $autoMains > 0 ? $free / $autoMains : 0.0;

            if ($autoMains > 0) {
                $free = 0.0;
            }

            [$offset, $between] = $this->mainDistribution($c->justifyContent, $free, $n);

            $mainStart  = $row ? $c->edge('left') : $c->edge('top');
            $mainCursor = $mainStart + $offset;

            foreach ($line as $ii => $item) {
                $align     = $item->alignSelf ?? $c->alignItems;
                $itemCross = $row ? $item->layoutHeight : $item->layoutWidth;

                if ($align === 'stretch') {
                    $hasExplicit = $this->authoredAxis($item, !$row) !== null;

                    if (!$hasExplicit) {
                        // §9.4 step 11: a stretched cross size is still clamped
                        // by the item's own min and max. `max-height: 30px` on
                        // an image beside a taller item is 22.500 in Chrome and
                        // the line's 24.000 without this.
                        $itemCross = $this->clamp(
                            max(0.0, $lineCross - $item->marginCross($row)),
                            $row ? $item->minHeight : $item->minWidth,
                            $row ? $item->maxHeight : $item->maxWidth,
                        );

                        if ($row) {
                            $item->layoutHeight = $itemCross;
                        } else {
                            $item->layoutWidth = $itemCross;
                        }
                    }
                }

                $crossFree = $lineCross - $itemCross - $item->marginCross($row);

                // 9.6 step 1: an auto cross margin outranks align-self too.
                $autoCrosses = $this->autoCrossCount($item, $row);

                $crossOffset = match (true) {
                    $autoCrosses === 2      => max(0.0, $crossFree) / 2.0,
                    $autoCrosses === 1      => $this->hasAutoCrossStart($item, $row) ? max(0.0, $crossFree) : 0.0,
                    isset($itemBaselines[$li][$ii]) => $lineBaselines[$li] - $itemBaselines[$li][$ii],
                    $align === 'flex-end'   => $crossFree,
                    $align === 'center'     => $crossFree / 2,
                    default                 => 0.0,
                };

                $leadingAuto = $this->hasAutoMainStart($item, $row) ? $perAuto : 0.0;

                $mainPos  = $mainCursor + $leadingAuto + ($row ? $item->margin['left'] : $item->margin['top']);
                $crossPos = $crossCursor + $crossOffset + ($row ? $item->margin['top'] : $item->margin['left']);

                // A reverse direction swaps main-start and main-end, so the
                // whole arrangement mirrors inside the content box rather than
                // each alignment rule needing a second spelling.
                if ($c->isReverse()) {
                    $mainPos = 2 * $mainStart + $innerMain - $mainPos - $item->targetMainSize;
                }

                // `wrap-reverse` flips the cross axis: the lines stack from the
                // cross end, and an item sits at what is now the far side of
                // its own line. Mirroring the finished position inside the
                // container is the same trick a reverse main axis uses, and it
                // keeps every alignment rule spelled once.
                if ($c->flexWrap === 'wrap-reverse' && $innerCross !== null) {
                    $extent   = $row ? $item->layoutHeight : $item->layoutWidth;
                    $crossPos = 2 * $crossStart + $innerCross - $crossPos - $extent;
                }

                if ($row) {
                    $item->x = $mainPos;
                    $item->y = $crossPos;
                } else {
                    $item->y = $mainPos;
                    $item->x = $crossPos;
                }

                $mainCursor += $item->targetMainSize + $item->marginMain($row) + $between + $c->gap
                    + $this->autoMainCount($item, $row) * $perAuto;
            }

            $crossCursor += $lineCross + $linesBetween;
        }

        $contentCross = array_sum($lineCrossSizes);
        $totalCross   = ($innerCross ?? $contentCross) + $c->edgeCross($row);
        $totalMain    = $outerMain;

        $c->collapsedMarginTop    = $c->margin['top'];
        $c->collapsedMarginBottom = $c->margin['bottom'];

        if ($row) {
            $c->layoutWidth  = $this->clamp($totalMain, $c->minWidth, $c->maxWidth);
            $c->layoutHeight = $this->clamp($totalCross, $c->minHeight, $c->maxHeight);
        } else {
            $c->layoutHeight = $this->clamp($totalMain, $c->minHeight, $c->maxHeight);
            $c->layoutWidth  = $this->clamp($totalCross, $c->minWidth, $c->maxWidth);
        }
    }

    /**
     * The size the author gave one axis, or null when the file gave it.
     *
     * A replaced element's own size is not a declaration. The builder resolves
     * it into `width` and `height` so block flow has a number to work with,
     * and reading it back as one is what stopped `align-items: stretch`
     * deciding that axis: Chrome makes a 240x60 image 96.000 tall beside a
     * taller item where this engine left it at the file's 45.000.
     */
    private function authoredAxis(Node $item, bool $horizontal): float|string|null
    {
        if ($horizontal ? $item->autoIntrinsicWidth : $item->autoIntrinsicHeight) {
            return null;
        }

        return $horizontal ? $item->width : $item->height;
    }

    /** The same, as the border-box length layout works in. */
    private function authoredLength(Node $item, bool $horizontal, float $basis): ?float
    {
        $resolved = $this->resolve($item, $this->authoredAxis($item, $horizontal), $basis);

        return $resolved === null
            ? null
            : ($horizontal ? $item->toBorderBoxWidth($resolved) : $item->toBorderBoxHeight($resolved));
    }

    /**
     * CSS Sizing §5.2's transferred size: the main size a definite cross size
     * implies through the box's proportions. `aspect-ratio` carries a declared
     * ratio only, so a replaced element's own one is asked for separately.
     */
    private function transferredMain(Node $item, float $definiteCross, bool $row): ?float
    {
        if ($item->aspectRatio !== null) {
            return $row ? $item->ratioWidth($definiteCross) : $item->ratioHeight($definiteCross);
        }

        return $item->intrinsicCross($definiteCross, !$row);
    }

    /**
     * A replaced flex item's automatic cross size, once the line has settled
     * a main size for it.
     *
     * Everywhere else the two agree, because nothing else hands a replaced box
     * a main size it did not ask for; a flex line does, and the builder has
     * already resolved the file's own height into `height`, so without this
     * the box keeps a height its width no longer justifies: a 240px square
     * shrunk to 75.083 stayed 180.000 tall against Chrome's 75.082.
     */
    private function applyIntrinsicCross(Node $item, float $mainSize, bool $row): void
    {
        $cross = $item->intrinsicCross($mainSize, $row);

        if ($cross === null) {
            return;
        }

        if ($row) {
            $item->layoutHeight = $this->clamp($cross, $item->minHeight, $item->maxHeight);

            return;
        }

        $item->layoutWidth = $this->clamp($cross, $item->minWidth, $item->maxWidth);
    }

    /**
     * Whether an item fills its line in the cross axis rather than sizing to
     * its own content. `stretch` does; so does an item that has already been
     * given an explicit cross size, since nothing is being decided for it.
     * `baseline` in a column falls back to flex-start, which does not.
     */
    private function stretchesCross(Node $c, Node $item, bool $row): bool
    {
        if ($this->authoredAxis($item, !$row) !== null) {
            return true;
        }

        return ($item->alignSelf ?? $c->alignItems) === 'stretch';
    }

    /**
     * §9.6 step 15, `align-content`: the free space left in the cross axis
     * after the lines have been sized is shared between them. `stretch`, the
     * initial value, grows every line by an equal share, which is why two
     * lines in a container with room to spare are spread rather than packed
     * against the cross start.
     *
     * Returns the offset before the first line and the extra gap between
     * lines; `stretch` returns neither, because it has already widened them.
     *
     * @param  float[] $lineCrossSizes modified in place by `stretch`
     * @return array{0:float,1:float}
     */
    private function alignLines(Node $c, array &$lineCrossSizes, ?float $innerCross): array
    {
        $n = count($lineCrossSizes);

        if ($n === 0 || $innerCross === null || $c->flexWrap === 'nowrap') {
            return [0.0, 0.0];
        }

        $free = $innerCross - array_sum($lineCrossSizes);

        if ($free <= 0.0) {
            return [0.0, 0.0];
        }

        if ($c->alignContent === 'stretch') {
            $share = $free / $n;

            foreach ($lineCrossSizes as $i => $size) {
                $lineCrossSizes[$i] = $size + $share;
            }

            return [0.0, 0.0];
        }

        return match ($c->alignContent) {
            'flex-end'      => [$free, 0.0],
            'center'        => [$free / 2.0, 0.0],
            'space-between' => [0.0, $n > 1 ? $free / ($n - 1) : 0.0],
            'space-around'  => [$free / $n / 2.0, $free / $n],
            'space-evenly'  => [$free / ($n + 1), $free / ($n + 1)],
            default         => [0.0, 0.0],
        };
    }

    /**
     * Section 9.7, Resolving Flexible Lengths.
     * This is the part everyone gets wrong; it is transcribed step by step.
     *
     * @param Node[] $line
     */
    private function resolveFlexibleLengths(array $line, float $innerMain, bool $row): void
    {
        // 1. Determine the used flex factor
        $hypotheticalTotal = 0.0;

        foreach ($line as $item) {
            $hypotheticalTotal += $item->hypotheticalMainSize + $item->marginMain($row);
        }

        $growing = $hypotheticalTotal < $innerMain;

        // 2. Size inflexible items
        foreach ($line as $item) {
            $factor = $growing ? $item->flexGrow : $item->flexShrink;

            $inflexible = $factor == 0.0
                || ($growing && $item->flexBaseSize > $item->hypotheticalMainSize)
                || (!$growing && $item->flexBaseSize < $item->hypotheticalMainSize);

            if ($inflexible) {
                $item->frozen         = true;
                $item->targetMainSize = $item->hypotheticalMainSize;
            } else {
                $item->frozen         = false;
                $item->targetMainSize = $item->flexBaseSize;
            }
        }

        // 3. Calculate initial free space
        $initialFree = $this->freeSpace($line, $innerMain, $row);

        // 4. Loop
        $guard = 0;

        while ($guard++ < 64) {
            // a. Any flexible items left?
            $unfrozen = array_values(array_filter($line, fn(Node $i) => !$i->frozen));

            if ($unfrozen === []) {
                break;
            }

            // b. Remaining free space
            $remaining = $this->freeSpace($line, $innerMain, $row);
            $factorSum = 0.0;

            foreach ($unfrozen as $item) {
                $factorSum += $growing ? $item->flexGrow : $item->flexShrink;
            }

            if ($factorSum < 1.0) {
                $scaled = $initialFree * $factorSum;

                if (abs($scaled) < abs($remaining)) {
                    $remaining = $scaled;
                }
            }

            // c. Distribute free space proportional to the flex factors.
            if (abs($remaining) > 1e-9) {
                if ($growing) {
                    $growSum = 0.0;

                    foreach ($unfrozen as $item) {
                        $growSum += $item->flexGrow;
                    }

                    if ($growSum > 0) {
                        foreach ($unfrozen as $item) {
                            $item->targetMainSize = $item->flexBaseSize
                                + $remaining * ($item->flexGrow / $growSum);
                        }
                    }
                } else {
                    // §9.7 step 4c: the scaled shrink factor multiplies the
                    // **inner** flex base size, so an item's own padding and
                    // border are not part of what makes it shrink faster than
                    // its neighbour. Measured: a 40px item with 5px of padding
                    // beside a 300px one is 24.270pt in Chrome and 21.429 when
                    // the outer size is scaled instead.
                    $scaled    = [];
                    $scaledSum = 0.0;

                    foreach ($unfrozen as $idx => $item) {
                        $inner        = max(0.0, $item->flexBaseSize - $item->edgeMain($row));
                        $scaled[$idx] = $inner * $item->flexShrink;
                        $scaledSum    += $scaled[$idx];
                    }

                    if ($scaledSum > 0) {
                        foreach ($unfrozen as $idx => $item) {
                            $item->targetMainSize = $item->flexBaseSize
                                - abs($remaining) * ($scaled[$idx] / $scaledSum);
                        }
                    }
                }
            }

            // d. Fix min/max violations
            $totalViolation = 0.0;
            $violations     = [];

            foreach ($unfrozen as $idx => $item) {
                $min                  = $item->resolvedMinMain;
                $max                  = $row ? $item->maxWidth : $item->maxHeight;
                $unclamped            = $item->targetMainSize;
                $clamped              = $this->clamp($unclamped, max($min, 0.0), $max);
                $clamped              = max(0.0, $clamped);
                $violations[$idx]     = $clamped - $unclamped;
                $totalViolation       += $violations[$idx];
                $item->targetMainSize = $clamped;
            }

            // e. Freeze over-flexed items
            if (abs($totalViolation) < 1e-9) {
                foreach ($unfrozen as $item) {
                    $item->frozen = true;
                }
            } elseif ($totalViolation > 0) {
                foreach ($unfrozen as $idx => $item) {
                    if ($violations[$idx] > 0) {
                        $item->frozen = true;
                    }
                }
            } else {
                foreach ($unfrozen as $idx => $item) {
                    if ($violations[$idx] < 0) {
                        $item->frozen = true;
                    }
                }
            }
        }
    }

    /** @param Node[] $line */
    private function freeSpace(array $line, float $innerMain, bool $row): float
    {
        $used = 0.0;

        foreach ($line as $item) {
            $used += ($item->frozen ? $item->targetMainSize : $item->flexBaseSize) + $item->marginMain($row);
        }

        return $innerMain - $used;
    }

    /** Drop cached layout for a subtree so it will be laid out again. */
    /**
     * How far into its fragmentainer an offset down the document falls.
     *
     * A boundary reads as the top of the next fragmentainer rather than as the
     * bottom of this one, which is what keeps a row that ends exactly on a page
     * edge from starting the next one with no room in it.
     */
    private function fragmentainerOffset(float $at, float $page): float
    {
        if ($page <= 0.0) {
            return 0.0;
        }

        $into = fmod(max(0.0, $at), $page);

        return $into < 1e-6 || $page - $into < 1e-6 ? 0.0 : $into;
    }

    private function invalidate(Node $n): void
    {
        $n->cacheGen = -1;

        foreach ($n->children as $child) {
            $this->invalidate($child);
        }
    }

    /**
     * Fill in each child's percentage margins and paddings now that the
     * containing block's content width is known. Every side resolves against
     * the width, the vertical ones included.
     */
    public function resolveChildEdges(Node $parent, float $contentWidth, ?float $contentHeight = null): void
    {
        foreach ($parent->children as $child) {
            $child->resolveAgainstContainingBlock($contentWidth, $contentHeight);
        }
    }

    /** How many of a flex item's main-axis margins are `auto`. */
    private function autoMainCount(Node $item, bool $row): int
    {
        $sides = $row ? ['left', 'right'] : ['top', 'bottom'];

        return (int) $item->autoMargin[$sides[0]] + (int) $item->autoMargin[$sides[1]];
    }

    private function autoCrossCount(Node $item, bool $row): int
    {
        $sides = $row ? ['top', 'bottom'] : ['left', 'right'];

        return (int) $item->autoMargin[$sides[0]] + (int) $item->autoMargin[$sides[1]];
    }

    private function hasAutoMainStart(Node $item, bool $row): bool
    {
        return $item->autoMargin[$row ? 'left' : 'top'];
    }

    private function hasAutoCrossStart(Node $item, bool $row): bool
    {
        return $item->autoMargin[$row ? 'top' : 'left'];
    }

    /** `text-indent`, with a percentage resolved against the containing block. */
    private function usedTextIndent(Node $n, float $basis): float
    {
        if (!is_string($n->textIndent)) {
            return $n->textIndent;
        }

        return (float) rtrim($n->textIndent, '%') / 100.0 * $basis;
    }

    /**
     * Baseline alignment needs the item's inline axis to run along the main
     * axis, so it means something in a row container and nothing in a column
     * one, where Chrome falls back to flex-start. An auto cross margin still
     * outranks it, exactly as it outranks every other align-self value.
     */
    private function alignsOnBaseline(Node $container, Node $item, bool $row): bool
    {
        if (!$row || $this->autoCrossCount($item, $row) > 0) {
            return false;
        }

        $align = $item->alignSelf ?? $container->alignItems;

        return $align === 'baseline' || $align === 'first baseline';
    }

    /**
     * How far an `auto` inline-axis margin pushes a block across the leftover
     * space in its containing block. Two autos split it, which is what
     * `margin: 0 auto` centering actually is.
     */
    private function autoMarginShift(Node $child, float $inner): float
    {
        $free = $inner - $child->layoutWidth - $child->marginMain(true);

        if ($free <= 0.0) {
            return 0.0;
        }

        return match (true) {
            $child->autoMargin['left'] && $child->autoMargin['right'] => $free / 2.0,
            $child->autoMargin['left']                               => $free,
            default                                                  => 0.0,
        };
    }

    /**
     * Whether `width: auto` means "as wide as the containing block" for this
     * box. Block-level boxes stretch; inline-level, floating and absolutely
     * positioned ones shrink to fit, and a replaced box has its own size.
     */
    private function fillsAvailableWidth(Node $n): bool
    {
        // A replaced box with a ratio and no intrinsic size is the exception
        // to both tests below: it has no size of its own to keep, so CSS 2.1
        // §10.3.2's last clause gives it the width a block-level non-replaced
        // box would have had, and Chrome does the same for an inline one
        // (`OC-svg-viewbox-ratio.html` `c3`). {@see Node::$ratioFill}.
        if ($n->ratioFill) {
            return !$n->isFloating() && !$n->isOutOfFlow();
        }

        if ($n->display !== 'block') {
            return false;
        }

        if ($n->image !== null || $n->svg !== null) {
            return false;
        }

        return !$n->isFloating() && !$n->isOutOfFlow();
    }

    private function isMulticol(Node $n): bool
    {
        return ($n->columnCount > 1 || $n->columnWidth !== null)
            && $n->display === 'block'
            && $n->children !== [];
    }

    /**
     * How many pieces one run of a multi-column box may be placed as. A run is
     * cut once per column boundary it crosses, so this is a ceiling on
     * author-controlled input rather than a design limit: twenty columns is
     * the most `column-count` will take.
     */
    private const int MAX_COLUMN_PIECES = 512;

    /** How far into a box the column cut looks for a break opportunity. */
    private const int MAX_COLUMN_DEPTH = 64;

    /**
     * How many columns past `column-count` one run may overflow into.
     *
     * Only a box with a height of its own has any, because only there can the
     * columns not grow to hold what is left. Each one costs at least one child
     * or one cut piece, so the run itself bounds them; this is the ceiling on
     * author-controlled input that says so, and a run that reaches it fills
     * the last column the way every run did before round 42.
     */
    private const int MAX_OVERFLOW_COLUMNS = 512;

    /**
     * How often one run is placed before its float bands and its balance
     * height are taken as settled.
     *
     * A float's own column is whichever one the flow had reached, and how far
     * the flow reached depends on the lines the float shortened, so the two
     * define each other. Chrome lays a multi-column box out twice for exactly
     * this reason. The balance height is the same shape of question, one pass
     * further on: an even share is only a GUESS at the height, and what the
     * placement did with that guess is what says whether it was one.
     * **A run that fits its own share settles on the first pass and never
     * reaches this**, which is what keeps the cost off every other
     * multi-column box.
     */
    private const int MAX_COLUMN_FLOAT_PASSES = 4;

    /**
     * CSS Multi-column: lay the content out once at column width, then slice
     * it into columns, cutting a child at the boundary where one does not fit.
     *
     * This is a fragmentation context of its own, one column boundary at a
     * time: a child taller than what is left of a column is cut there and the
     * piece that did not fit starts the next column, which is what Chrome
     * does. What cannot be cut moves whole, and {@see splitAtOffset} is the
     * list of what that is.
     *
     * A `column-span: all` child cuts the flow into runs: the children before
     * it fill one set of columns, the spanner takes the full content width on
     * its own, and the children after it fill the next set. A spanner that is
     * not a direct child is hoisted out of its wrapper and the wrapper is
     * split around it, which is {@see hoistedChildren}.
     */
    private function layoutMulticol(Node $n, ?float $availWidth, ?float $availHeight): void
    {
        $outer = $this->usedWidth($n, $availWidth ?? 0.0) ?? ($availWidth ?? 0.0);
        $inner = max(0.0, $outer - $n->edgeMain(true));
        $gap   = $n->columnGap > 0.0 ? $n->columnGap : $n->gap;

        $count = $n->columnCount;

        if ($n->columnWidth !== null && $n->columnWidth > 0.0) {
            $fit   = (int) floor(($inner + $gap) / ($n->columnWidth + $gap));
            $count = max(1, min(20, $n->columnCount > 1 ? min($n->columnCount, $fit) : $fit));
        }

        $count = max(1, $count);

        $columnWidth = max(0.0, ($inner - $gap * ($count - 1)) / $count);

        $declared = $this->usedHeight($n, $availHeight ?? 0.0);

        // `column-fill: auto` fills each column to the box's own height before
        // starting the next one, so it needs that height before anything is
        // placed. A box with no height to fill has nothing to fill to, and
        // Chrome puts every child in the first column: `RX-column.html` x3.
        $fillTo = $declared === null
            ? null
            : max(0.0, $this->clamp($declared, $n->minHeight, $n->maxHeight) - $n->edgeCross(true));

        /*
         * The fragmentainer a column is as tall as. A multi-column box taller
         * than the page gets a ROW of columns per page, so a column is capped
         * at the page rather than at the box: defect HM, where the first
         * column ran the whole height of the box and the second started below
         * it rather than beside it.
         *
         * **It is null wherever the box fits**, which is every box that
         * declares no height and every box shorter than a page, and then
         * nothing below this changes at all. The height passed in is the
         * containing block's, which for a box in the page's own flow is the
         * page; a box that fits inside one has no second fragmentainer to
         * reach and `$rowsAhead()` answers false for it.
         */
        // **The PAGE and never `$availHeight`**, which is the containing
        // block's height and is a different quantity: `RX-column.html`'s
        // slots are 36pt tall, so reading that as the fragmentainer capped
        // every column in the page at 36 and split a 54pt run that Chrome
        // keeps in one column. A container shorter than the page caps the box
        // through `$fillTo` already.
        $page     = $this->fragmentainer > 0.0 ? $this->fragmentainer : null;
        $fragment = $page !== null && ($fillTo === null || $fillTo > $page)
            ? $page
            : null;

        /*
         * How far down its own page the box starts, because **a fragmentainer
         * is what is LEFT of the page a box begins on** and not a whole page.
         * Chrome fills the first row of columns of `UF-column-midpage.html` to
         * 405pt, where the box starts 135pt down a 540pt page, and every row
         * after it to the whole page. Defect HT.
         *
         * It is zero wherever the flow does not say where the box is, which is
         * every box outside plain block flow, and then every line below is what
         * it was.
         */
        $start = $fragment === null || $this->flowTop === null
            ? 0.0
            : $this->fragmentainerOffset($this->flowTop + $n->edge('top'), $fragment);

        if ($fragment !== null) {
            $this->columnStarts++;
        }

        $y       = 0.0;
        $rows    = [];
        $widest  = 1;
        $pending = [];
        $placed  = [];

        foreach ($n->columnFlow ??= $this->hoistedChildren($n) as $child) {
            if (!$child->columnSpanAll) {
                $pending[] = $child;

                continue;
            }

            $y = $this->fillColumnRun($n, $pending, $columnWidth, $gap, $count, $fillTo, $fragment, $start, $y, $rows, $widest, $placed);
            $pending = [];

            $this->layoutNode($child, $inner - $child->marginMain(true), null);

            $child->x = $n->edge('left') + $child->margin['left'];
            $child->y = $n->edge('top') + $y + $child->margin['top'];
            $y        += $child->layoutHeight + $child->marginCross(true);
            $placed[] = $child;
        }

        $y = $this->fillColumnRun($n, $pending, $columnWidth, $gap, $count, $fillTo, $fragment, $start, $y, $rows, $widest, $placed);

        $this->sliceSpannerPieces($placed);

        // Every piece a cut produced is a box of the tree's own, so the box
        // has to hold them: the painter and the page fragmenter both walk this
        // list and neither knows what a column is.
        $n->children = $placed;

        $n->layoutWidth = $this->clamp($outer, $n->minWidth, $n->maxWidth);

        $n->layoutHeight = $this->clamp(
            $declared ?? ($y + $n->edgeCross(true)),
            $n->minHeight,
            $n->maxHeight,
        );

        $boxHeight = max(0.0, $n->layoutHeight - $n->edgeCross(true));

        /*
         * A multi-column box with no spanner in it is one set of columns, and
         * that set is as tall as the column box rather than as tall as what
         * the content filled. On a box with a declared `height` those differ,
         * and Chrome draws the rule down the whole box: `RT-column.html` `c1`
         * is 40px of rule over 24px of content, which is 2.15 of 255 against
         * 1.29 if the content is used instead, exactly the 24/40 the two
         * heights are.
         */
        if (count($rows) === 1) {
            $rows[0]['height'] = $boxHeight;
        }

        /*
         * What the multi-column pass produced, which is where `column-rule`
         * gets its geometry. The count is what the content filled rather than
         * what `column-count` asked for, because a rule goes between two
         * columns that both hold something.
         */
        $n->columnBoxes = [
            'count'  => $widest,
            'width'  => $columnWidth,
            'gap'    => $gap,
            'height' => $boxHeight,
            'rows'   => $rows,
        ];

        $n->collapsedMarginTop    = $n->margin['top'];
        $n->collapsedMarginBottom = $n->margin['bottom'];
    }

    /**
     * Place one run of children across the columns and return the offset the
     * next run starts at.
     *
     * `balance` gives every column the same share of the run, which is what
     * the initial value asks for; `auto` fills each column to what is left of
     * the box's own height before starting the next one. Either way a child
     * that does not fit what is left is **cut** at the boundary and the piece
     * that did not fit starts the next column. A child that cannot be cut
     * moves whole, which is what every child did before this.
     *
     * The two rules the cut needs and neither is in the spec's own words:
     * **Chrome balances to the exact half of the run's content height**, so
     * the target is not what fits but what an even share is, and **a float
     * takes no part in it**, so the share is measured over the rest.
     *
     * A float does take part in one thing, and that is defect ES: the lines
     * beside it in its own column are shortened by it. That cannot be read off
     * a single pass, because a run is laid out before it is known which column
     * each child lands in and a band is a fact about a column. So the run is
     * placed, the bands the placement produced are handed back in, and it is
     * placed again until they stop moving. {@see MAX_COLUMN_FLOAT_PASSES} is
     * the ceiling, and a run with no float in it settles on the first pass.
     *
     * @param list<Node>                                    $items
     * @param list<array{count:int,top:float,height:float}> $rows
     * @param list<Node>                                    $placed every box
     *        this run put on the page, pieces included, in the order the
     *        multi-column box has to hold them in
     */
    private function fillColumnRun(
        Node $n,
        array $items,
        float $columnWidth,
        float $gap,
        int $count,
        ?float $fillTo,
        ?float $rowHeight,
        float $start,
        float $top,
        array &$rows,
        int &$widest,
        array &$placed,
    ): float {
        if ($items === []) {
            return $top;
        }

        $plan   = ['bands' => [], 'seats' => [], 'height' => 0.0];
        $passes = 0;

        do {
            $before = $this->columnPlanKey($plan);
            $result = $this->placeColumnRun(
                $n,
                $items,
                $columnWidth,
                $gap,
                $count,
                $fillTo,
                $rowHeight,
                $start,
                $top,
                $plan,
                $passes > 0,
            );
            $plan = [
                'bands'  => $result['bands'],
                'seats'  => $result['seats'],
                'height' => max($plan['height'], $this->balanceShortfall($result)),
            ];
            $passes++;
        } while (
            $this->columnPlanKey($plan) !== $before
            && ($result['bands'] !== [] || $plan['height'] > 0.0)
            && $passes < self::MAX_COLUMN_FLOAT_PASSES
        );

        foreach ($result['placed'] as $box) {
            $placed[] = $box;
        }

        // One entry per ROW of columns, because a box taller than a page has
        // one per fragmentainer and `column-rule` needs each of them.
        foreach ($result['rows'] as $row) {
            $rows[] = $row;
        }

        $widest = max($widest, $result['columns']);

        return $top + $result['tallest'];
    }

    /**
     * One pass of a run: lay every child out against the bands the last pass
     * found, place them across the columns, and report the bands and the seats
     * this pass produced.
     *
     * @param list<Node>                                                                                    $items
     * @param array{bands:array<int,list<array{side:string,top:float,bottom:float,edge:float}>>,seats:array<int,array{0:int,1:float}>,height?:float} $plan
     *
     * @return array{placed:list<Node>,columns:int,tallest:float,bands:array<int,list<array{side:string,top:float,bottom:float,edge:float}>>,seats:array<int,array{0:int,1:float}>,target:float,balanced:bool}
     */
    private function placeColumnRun(
        Node $n,
        array $items,
        float $columnWidth,
        float $gap,
        int $count,
        ?float $fillTo,
        ?float $rowHeight,
        float $start,
        float $top,
        array $plan,
        bool $relayout,
    ): array {
        $run   = [];
        $total = 0.0;
        $floor = 0.0;

        foreach ($items as $child) {
            // An out-of-flow box is placed by placePositioned() against its own
            // containing block, so the run neither lays it out nor measures it.
            // It rides the run rather than being put in front of it, because
            // the child list it ends up in is the order the page fragmenter
            // emits the box's children in.
            if ($child->isOutOfFlow()) {
                $run[] = [$child, 0.0, 'skip', $child];

                continue;
            }

            $this->layoutColumnChild($child, $columnWidth, $plan, $relayout);

            // CSS Multi-column §6: a float is in the column the flow reached
            // and out of the block flow, so it contributes no height to the
            // balance and the boxes after it are placed as if it were not
            // there. `RX-column.html` x13, where the engine's own side is the
            // proof: rendering that slot with the declaration deleted gives
            // the same page, so the float was an ordinary item. Defect EP.
            if ($child->isFloating()) {
                $run[] = [$child, 0.0, 'float', $child];

                continue;
            }

            $height = $child->layoutHeight + $child->marginCross(true);
            $run[]  = [$child, $height, 'flow', $child];
            $total  += $height;
            $floor  = max($floor, $this->columnLineFloor($child));
        }

        $auto  = $n->columnFill === 'auto';
        $boxTo = $fillTo ?? INF;

        /*
         * A fragmentainer is as tall as the page, and a column is as tall as
         * its fragmentainer, so a box taller than one page gets a ROW of
         * columns per page rather than columns that carry on down the
         * document. Defect HM: with the box's own height as the only limit,
         * the first column ran the whole height of the box and the second
         * started BELOW it, so a 720pt two-column box on a 540pt page was
         * three pages of one column each where Chrome gives two pages of two.
         *
         * `$rowHeight` is null wherever the box fits in one fragmentainer, and
         * then every line below is what it was. The LAST row is the box's own
         * remainder rather than a whole page, which is what puts two items in
         * each column of Chrome's last page instead of four in the first.
         *
         * And a row is only a WHOLE fragmentainer where the box starts at the
         * top of one. `$start` is how far down its page the box begins, so the
         * first row is what is left of that page and every row after it is a
         * whole one: defect HT, where a box 135pt down a 540pt page filled its
         * first row of columns to 540 and Chrome fills it to 405.
         */
        $rows    = [];
        $rowTop  = $top;
        $rowSpan = fn(float $at): float => max(0.0, min(
            $rowHeight === null
                ? INF
                : $rowHeight - $this->fragmentainerOffset($start + $at, $rowHeight),
            $boxTo - $at,
        ));

        $limit = $rowSpan($rowTop);

        // How much of the run has been placed, which is what the row after
        // this one has left to share out. A balanced box shares the WHOLE run
        // over its columns only while it is the whole run: every fragmentainer
        // but the last is filled and the last one balances what is left, so a
        // row reading the first row's share puts three items in the first
        // column of the last page where Chrome puts two in each. HM's residual.
        $consumed = 0.0;

        // An even share, never leaving a column empty. Filling sequentially
        // asks the other question: does this child still fit in what is left.
        // Neither may run past a height the box has already been given, which
        // is what `SD-column-overflow.html` d5 reads: one item taller than the
        // whole box is cut at the box's height and not at the even share.
        //
        // The share is also never smaller than the tallest run of lines that
        // `orphans` and `widows` leave no break inside, because a column that
        // cannot hold such a run forces a break the properties forbid. That is
        // defect EV and {@see columnLineFloor} is the measurement.
        //
        // And it is never smaller than the height a previous pass of this run
        // needed and could not get out of the even share, which is defect HC:
        // `$plan['height']` is that height and it is zero on the first pass
        // and on every run that fits. It is capped by `$limit` with the other
        // two, so a box that declares a height still cannot grow.
        $share  = $auto ? $limit : ($count > 0 ? $total / $count : $total);
        $target = min(max($share, $floor, $plan['height'] ?? 0.0), $limit);

        /*
         * There is a row after this one only where this row's columns are
         * capped by the FRAGMENTAINER rather than by the balance. A wrap has
         * two reasons and they need telling apart: a column that has filled
         * its balanced share on a box with room to grow has not reached a
         * fragmentainer boundary at all, and `RX-column.html`'s 54pt box came
         * out 36pt when that was read as one. `$target` is the share where the
         * balance decides and the whole limit where the page does, so the two
         * are one comparison.
         */
        $rowsAhead = function (float $at) use (&$target, &$limit, $rowHeight, $boxTo, $rowSpan): bool {
            return $rowHeight !== null
                && $target >= $limit - 1e-6
                && $at + $rowSpan($at) < $boxTo - 1e-6;
        };

        // `column-count` is a maximum only where the box has no height of its
        // own to fill. Where it has one, the columns cannot grow to hold what
        // is left over, so Chrome puts it in a column past the declared ones,
        // outside the box's own width: `SD-column-overflow.html` d1 is three
        // columns of a box that declares two, at the same pitch, with the
        // box's background still the width it declared and a `column-rule`
        // drawn beside the extra column like any other (d4). Round 38 capped
        // this deliberately and d6 and d7 are why the cap was half right: with
        // no declared height the columns grow instead and there is nothing to
        // overflow.
        // Where a further ROW is possible the columns never overflow sideways:
        // what does not fit this fragmentainer belongs in the next one.
        $lastColumn = $rowsAhead($rowTop) || $fillTo === null
            ? $count - 1
            : $count - 1 + self::MAX_OVERFLOW_COLUMNS;

        $column  = 0;
        $used    = 0.0;
        $tallest = 0.0;
        $splits  = 0;
        $placed  = [];
        $bands   = [];
        $seats   = [];

        /*
         * The next seat for a column that has just filled: the column beside
         * this one, or the first column of the next row where the columns are
         * full and the box has another fragmentainer to give. It answers false
         * where there is neither, which is the last column of the last row and
         * is where a run simply keeps piling into what it has.
         */
        $wrap = function () use (
            &$column,
            &$used,
            &$rowTop,
            &$tallest,
            &$rows,
            &$limit,
            &$target,
            &$lastColumn,
            &$bands,
            &$consumed,
            $count,
            $auto,
            $floor,
            $plan,
            $total,
            $rowSpan,
            $rowsAhead,
            $fillTo,
        ): bool {
            if ($column < $lastColumn) {
                $column++;
                $used = 0.0;

                return true;
            }

            if (!$rowsAhead($rowTop)) {
                return false;
            }

            $rows[]  = ['count' => $column + 1, 'top' => $rowTop, 'height' => $tallest];
            $rowTop  += $rowSpan($rowTop);
            $column  = 0;
            $used    = 0.0;
            $tallest = 0.0;
            $bands   = [];

            // What is LEFT of the run, because the rows before this one are
            // filled and gone: CSS Multicol 3.3 balances the last
            // fragmentainer over what reaches it and not over the whole box.
            $left = max(0.0, $total - $consumed);

            $limit  = $rowSpan($rowTop);
            $share  = $auto ? $limit : ($count > 0 ? $left / $count : $left);
            $target = min(max($share, $floor, $plan['height'] ?? 0.0), $limit);

            $lastColumn = $rowsAhead($rowTop) || $fillTo === null
                ? $count - 1
                : $count - 1 + self::MAX_OVERFLOW_COLUMNS;

            return true;
        };

        // The ceiling stops the cutting, never the placing: a run that has
        // been cut as often as it may be places what is left whole, the way
        // every run did before this. Dropping the rest would be content loss.
        while ($run !== []) {
            [$child, $height, $kind, $origin] = array_shift($run);

            if ($kind === 'skip') {
                $placed[] = $child;

                continue;
            }

            if ($kind === 'float') {
                // The float is in the column the flow has reached, and a flow
                // that has filled this one has reached the next. It carries no
                // height, so the wrap test the flow children use cannot see it:
                // `$used + $height / 2 > $target` is false at the exact moment
                // a column is full, which is where `SA-column-float.html` s7
                // puts one and where Chrome puts it in the second column.
                if ($used > 0.0 && $used >= $target - 1e-6) {
                    $wrap();
                }

                $bandTop = $used + $child->margin['top'];
                $edge    = $this->floatEdge(
                    $child->float,
                    $bandTop,
                    $bandTop + $child->layoutHeight,
                    $bands[$column] ?? [],
                );

                $placed[] = $child;
                $child->x = $n->edge('left') + $column * ($columnWidth + $gap) + ($child->float === 'right'
                    ? $columnWidth - $edge - $child->layoutWidth - $child->margin['right']
                    : $edge + $child->margin['left']);
                $child->y = $n->edge('top') + $rowTop + $bandTop;

                // Recorded in the column's own coordinates, because that is
                // what the next pass hands a child whose lines it shortens.
                $bands[$column][] = [
                    'side'   => $child->float,
                    'top'    => $bandTop,
                    'bottom' => $bandTop + $child->layoutHeight + $child->margin['bottom'],
                    'edge'   => $edge + $child->layoutWidth + $child->marginMain(true),
                ];

                continue;
            }

            $room    = $target - $used - $child->margin['top'];
            $ceiling = $limit - $used - $child->margin['top'];
            $tail    = null;

            if ($column < $lastColumn
                && $height > $target - $used + 1e-6
                && $room > 0.01
                && $splits < self::MAX_COLUMN_PIECES
                && $this->mightSplit($child)
            ) {
                // The cut takes a box apart, so it takes a copy apart: the one
                // in the tree has to survive being laid out again.
                //
                // $room is handed in twice, as where to cut and as how tall the
                // piece above the cut ends up: a column is a fragmentainer and
                // a box that carries on into the next one ends where the column
                // does rather than where its own content ran out. That is
                // defect EU, and it only adds anything where the cut lands
                // above the column's bottom.
                $copy = $this->cloneSubtree($child);
                $tail = $this->splitAtOffset($copy, $room, $ceiling, 0, $room);

                if ($tail !== null) {
                    $child = $copy;
                    $splits++;
                }
            }

            $placed[] = $child;

            if ($tail === null) {
                $remaining = 1;

                foreach ($run as [, , $other]) {
                    $remaining += $other === 'flow' ? 1 : 0;
                }

                $full = $auto
                    ? $used + $height > $target + 1e-6
                    : $used + $height / 2 > $target || $remaining <= $count - $column - 1;

                if ($used > 0.0 && $full) {
                    $wrap();
                }
            }

            $child->x = $n->edge('left') + $column * ($columnWidth + $gap)
                + $child->margin['left'] + $child->columnFloatShift;
            $child->y = $n->edge('top') + $rowTop + $used + $child->margin['top'];

            // Where the next pass has to lay this child out from. A cut child
            // is seated by its first piece, which is the one the layout the
            // cut took apart was measured at.
            $seats[spl_object_id($origin)] ??= [$column, $used];

            $step      = $tail === null ? $height : $child->layoutHeight + $child->marginCross(true);
            $used      += $step;
            $consumed  += $step;
            $tallest   = max($tallest, $used);

            if ($tail !== null) {
                $wrap();
                array_unshift($run, [$tail, $tail->layoutHeight + $tail->marginCross(true), 'flow', $origin]);
            }
        }

        $rows[] = ['count' => $column + 1, 'top' => $rowTop, 'height' => $tallest];

        return [
            'placed'   => $placed,
            'columns'  => max(array_column($rows, 'count')),
            'tallest'  => $rowTop - $top + $tallest,
            'rows'     => $rows,
            'bands'    => $bands,
            'seats'    => $seats,
            'target'   => $target,
            'balanced' => !$auto,
        ];
    }

    /**
     * Lay one child of a run out at column width, against the float band its
     * seat in the last pass puts it in.
     *
     * A child that establishes a formatting context of its own keeps clear of
     * a float rather than shortening its lines, which is CSS 2.1 §9.5 and
     * {@see avoidsFloats}. Inside a column that would be a move rather than a
     * narrowing, and a move changes which column it is in, so it is left where
     * the balance put it and recorded as not built.
     *
     * @param array{bands:array<int,list<array{side:string,top:float,bottom:float,edge:float}>>,seats:array<int,array{0:int,1:float}>,height?:float} $plan
     */
    private function layoutColumnChild(Node $child, float $columnWidth, array $plan, bool $relayout): void
    {
        $width  = $columnWidth - $child->marginMain(true);
        $seat   = $plan['seats'][spl_object_id($child)] ?? null;
        $floats = $seat === null ? [] : ($plan['bands'][$seat[0]] ?? []);

        $child->columnFloatShift = 0.0;

        if ($floats === [] && !$relayout) {
            $this->layoutNode($child, $width, null);

            return;
        }

        $outer = $this->lineConstraint;

        /*
         * A box that establishes a formatting context of its own moves and
         * narrows rather than shortening its lines, CSS 2.1 section 9.5, and
         * that is true inside a column as well: Chrome puts a `flow-root` box
         * beside a 24px float in a 60px column at x 24 and 36 wide, and
         * `TR-column-bfc.html` t1 to t4 read it on four spellings of the same
         * context. Round 41 left this because a move inside a column looked
         * like a change of column, and the measurement says it is not: the
         * move is along the column the box is already in.
         *
         * The band is the column's own, in the column's own coordinates, and
         * {@see fitBesideFloats} is the same helper block flow uses, which is
         * why t7, the pair outside a column, agreed before this and after it.
         * Defect HE.
         */
        if ($floats !== [] && $this->avoidsFloats($child)) {
            $this->lineConstraint = null;
            $this->invalidate($child);
            $this->layoutNode($child, $width, null);

            [, $start] = $this->fitBesideFloats(
                $child,
                $seat[1],
                $columnWidth,
                $width,
                $floats,
                null,
            );

            $child->columnFloatShift = $start - $child->margin['left'];
            $this->lineConstraint    = $outer;

            return;
        }

        if ($floats !== [] && !$this->avoidsFloats($child)) {
            $offset = $seat[1];

            $this->lineConstraint = function (float $lineY, float $lineH) use (
                $offset,
                $columnWidth,
                $floats,
            ): array {
                [$edge, $room] = $this->floatBand(
                    $offset + $lineY,
                    $offset + $lineY + $lineH,
                    $columnWidth,
                    $floats,
                    null,
                );

                return [$edge, max(1.0, $room)];
            };
        } else {
            $this->lineConstraint = null;
        }

        $this->invalidate($child);
        $this->layoutNode($child, $width, null);
        $this->lineConstraint = $outer;
    }

    /**
     * The tallest run of line boxes anywhere under $n that `orphans` and
     * `widows` leave no break inside, which is a floor on the balanced column
     * height.
     *
     * A break after line k of an n-line paragraph is one the properties permit
     * when k is at least `orphans` and n - k is at least `widows`, so the lines
     * between two permitted breaks travel together. A column shorter than the
     * tallest such run cannot hold it, and Chrome answers that by making every
     * column taller rather than by breaking where the properties forbid:
     * `SG-column-widows.html` g2 is five lines over four columns at
     * `widows: 3`, where the even share is 15px, the run that cannot be broken
     * is 36px, and Chrome cuts **2 + 3** in a 36px column. g1 is the same five
     * lines at `widows: 2`, where two breaks are permitted, the tallest run is
     * 24px, and Chrome cuts 2 + 2 + 1 in a 24px column. That is defect EV, and
     * the two slots differ by one token.
     *
     * **`widows` is first reduced to what a break could possibly leave.** g3
     * asks for four widows out of five lines with two orphans, which no break
     * can satisfy; reading it literally makes the whole paragraph one run and
     * gives it a 60px column, where Chrome reads it exactly like g2.
     *
     * A paragraph with no permitted break at all contributes **nothing**. A
     * column that has to grow to hold an item it cannot break is the box rule
     * rather than this one, and `SH-column-fragheight.html` h13 is the slot
     * that measures it.
     */
    private function columnLineFloor(Node $n, int $depth = 0): float
    {
        if ($depth > self::MAX_COLUMN_DEPTH || $n->isOutOfFlow() || $n->isFloating()) {
            return 0.0;
        }

        $tallest = 0.0;

        foreach ($n->children as $child) {
            $tallest = max($tallest, $this->columnLineFloor($child, $depth + 1));
        }

        $lines   = $n->lineBoxes;
        $count   = count($lines);
        $orphans = max(1, $n->orphans);

        if ($count <= $orphans) {
            return $tallest;
        }

        $widows = min(max(1, $n->widows), $count - $orphans);
        $run    = 0.0;

        foreach ($lines as $index => $line) {
            $run += $line->height;
            $kept = $index + 1;

            if ($kept >= $orphans && $count - $kept >= $widows) {
                $tallest = max($tallest, $run);
                $run     = 0.0;
            }
        }

        return max($tallest, $run);
    }

    /**
     * A run's placement as one string, so two passes can be compared for
     * having settled rather than for being close.
     *
     * @param array{bands:array<int,list<array{side:string,top:float,bottom:float,edge:float}>>,seats:array<int,array{0:int,1:float}>,height?:float} $plan
     */
    /**
     * The height a balanced run needs that the share it was given did not give
     * it, or zero where the share was enough.
     *
     * CSS Multi-column section 3.3 asks for columns "as balanced in height as
     * content allows", and an even share is only the first guess at what
     * content allows. A box that cannot be broken where the share ends pushes
     * the placement past it, and then a column is TALLER than the height every
     * other column was fitted to, which is the one thing a balanced box may
     * not be. Chrome's answer is to raise the height to what the placement
     * actually needed and lay the run out again, and the fixed point is where
     * the tallest column and the target agree.
     *
     * **This is one rule for a box and a line both.** A line is unbreakable in
     * exactly the way `break-inside: avoid` makes a box unbreakable, and the
     * cut refusing at either is the same event: `SH-column-fragheight.html`
     * h13 is the box case and h1 is the line case, and h1 agreed before this
     * because five 12px lines over two columns overshoot to the same 36 the
     * second pass settles on.
     *
     * A run under `column-fill: auto` is not balanced at all and has nothing
     * to raise, and a run whose box declares a height is capped by it in
     * {@see placeColumnRun}, so neither can grow here.
     *
     * **The height to compare is the LAST row's and never the box's.** A run
     * that fills more than one fragmentainer hands back a box height in
     * `tallest`, which is every row added up, and `target` is the last row's
     * own share: comparing the two raised a 180pt balanced last page to the
     * 720pt the whole box is and put three items in its first column where
     * Chrome puts two in each. On a run that fits one fragmentainer the two
     * numbers are the same one, which is the case this rule was written for.
     *
     * @param array{tallest:float,target:float,balanced:bool,rows:list<array{count:int,top:float,height:float}>} $result
     */
    private function balanceShortfall(array $result): float
    {
        if (!$result['balanced']) {
            return 0.0;
        }

        $rows = $result['rows'];
        $last = $rows === [] ? $result['tallest'] : $rows[count($rows) - 1]['height'];

        return $last > $result['target'] + 1e-6 ? $last : 0.0;
    }

    private function columnPlanKey(array $plan): string
    {
        $parts = [sprintf('h%.4f', $plan['height'] ?? 0.0)];

        foreach ($plan['bands'] as $column => $floats) {
            foreach ($floats as $f) {
                $parts[] = sprintf(
                    '%d:%s:%.4f:%.4f:%.4f',
                    $column,
                    $f['side'],
                    $f['top'],
                    $f['bottom'],
                    $f['edge'],
                );
            }
        }

        foreach ($plan['seats'] as $id => [$column, $offset]) {
            $parts[] = sprintf('%d@%d:%.4f', $id, $column, $offset);
        }

        return implode('|', $parts);
    }

    /**
     * Whether a cut could reach this box at all, read off the box alone.
     *
     * The cut works on a copy, and copying a subtree to find out that its root
     * is a picture is work an author can ask for as often as they like. This
     * is the same list {@see splitAtOffset} refuses on, minus the walk.
     */
    private function mightSplit(Node $n): bool
    {
        if ($n->breakInside === 'avoid' || $n->isOutOfFlow() || $n->isFloating()) {
            return false;
        }

        if ($n->display === 'text') {
            return count($n->lineBoxes) >= $n->orphans + $n->widows;
        }

        if ($n->image !== null || $n->svg !== null || $n->formField !== null) {
            return false;
        }

        return $this->cutsBetweenChildren($n);
    }

    /**
     * Whether a column boundary can take this box apart at all, by display and
     * by the shape its own children are in.
     *
     * A `block`, a `flow-root`, a `list-item` and a `table-cell` lay their
     * children out one after another down the block axis, so a cut between two
     * of them is a cut this path can make. **A table, a grid and a flex
     * container do the same whenever their items are a stack**, and that is
     * defect HH: the page fragmenter has split all three since round 14 and
     * the column path refused them by display, so a table that did not fit a
     * column moved whole where Chrome cuts it between rows.
     *
     * **A BAND is cut too, at one offset through every item of it**, which is
     * defect HL and what {@see columnBands} groups: the cells of a table row,
     * the items of a grid row and the items of a flex line all share a top, so
     * the offset has to be one every one of them can be cut at. It is
     * {@see Fragmenter::rowBreaksHere}'s rule arriving in the column path, and
     * a container whose children overlap in the block axis WITHOUT sharing a
     * top still moves whole, because a walk down the block axis cannot order
     * them. A nested multi-column box is its own fragmentation context and is
     * refused for that reason instead.
     */
    private function cutsBetweenChildren(Node $n): bool
    {
        if ($this->isMulticol($n)) {
            return false;
        }

        if (in_array($n->display, ['block', 'flow-root', 'list-item', 'table-cell'], true)) {
            return true;
        }

        if (!in_array($n->display, ['table', 'table-row', 'grid', 'flex'], true)) {
            return false;
        }

        return $this->columnBands($n) !== null && !$this->hasBorderedRowGroup($n);
    }

    /**
     * Does a row group of this table draw a rim of its own?
     *
     * A row group has no box in this tree: its background travels on its rows
     * and is painted per row, and its BORDER used to be one rectangle over
     * every row of the group that lands on a page
     * ({@see Fragmenter::bandRowGroups}). Two columns are one page, so a table
     * cut between them would put a single rim around the rows in both:
     * `TT-column-split.html` b5 measures that as one 236 by 44 rectangle
     * straight across the 24px gap, where Chrome draws a rim per column with
     * the edges the cut removed left out. **The rim is what that cut could not
     * carry, so a table that had one moved whole.**
     *
     * **Defect HN took the rim away**, so this is nearly vestigial now: a
     * group's border is resolved into the grid lines its own rows share and
     * drawn by the cells, which a cut carries the way it carries any other
     * cell border. `TableLayout::collapseGeometry()` clears the group's border
     * as it adopts it, in the collapsed model, and `layout()` clears it in the
     * separated model where CSS 2.1 section 17.6.1 says a group has none at
     * all. What still reaches this is a table whose grid has NO COLUMNS,
     * because neither of those two runs for one, and such a table has no cell
     * to cut anyway. It is kept rather than deleted for that case.
     */
    private function hasBorderedRowGroup(Node $n): bool
    {
        if ($n->display !== 'table') {
            return false;
        }

        foreach ($n->children as $row) {
            if ($row->rowGroupBox !== null && $row->rowGroupBox->border !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * This box's own in-flow children, grouped into the bands they occupy down
     * the block axis, or null where they are not a sequence of bands at all.
     *
     * Read off the laid-out boxes rather than off the display, because that is
     * the property the cut needs and no keyword carries it: a single-column
     * grid and a `column` flex container give one child per band, the cells of
     * a table row and the items of a grid row or a flex line give one band of
     * several, and which one a container produces is a fact about its content.
     * A float and an out-of-flow box are placed against something else
     * entirely, so neither is part of a band or an argument against one.
     *
     * **Two children that overlap without sharing a top are not a band**, and
     * that is the case this refuses: a `column-reverse` flex container puts a
     * later child higher up, so a walk in document order would cut through
     * boxes it has already passed. It answers null there and the container
     * moves whole, which is what every container that is not a stack did
     * before round 68.
     *
     * @return list<Node[]>|null one entry per band, in document order
     */
    private function columnBands(Node $n): ?array
    {
        $bands  = [];
        $bottom = -INF;

        foreach ($n->children as $child) {
            if ($child->isOutOfFlow() || $child->isFloating()) {
                continue;
            }

            $top  = $child->y - $child->margin['top'];
            $foot = $child->y + $child->layoutHeight + $child->margin['bottom'];

            if ($top >= $bottom - 1e-6) {
                $bands[] = [$child];
                $bottom  = $foot;

                continue;
            }

            $last  = count($bands) - 1;
            $first = $bands[$last][0];

            if (abs($top - ($first->y - $first->margin['top'])) > 1e-6) {
                return null;
            }

            $bands[$last][] = $child;
            $bottom         = max($bottom, $foot);
        }

        return $bands;
    }

    /**
     * A copy of a laid-out subtree, boxes and all.
     *
     * Line boxes are shared rather than copied: a cut partitions the list and
     * neither piece writes to a `LineBox`. What is copied node by node is the
     * structure owner, because a piece whose owner is a box outside the tree
     * hands the structure tree ink nothing claims.
     */
    private function cloneSubtree(Node $n): Node
    {
        $map  = [];
        $copy = $this->copyNode($n, $map);

        foreach ($map as $piece) {
            $owner = $piece->structureOwner;

            if ($owner !== null && isset($map[spl_object_id($owner)])) {
                $piece->structureOwner = $map[spl_object_id($owner)];
            }
        }

        return $copy;
    }

    /** @param array<int,Node> $map source object id to its copy */
    private function copyNode(Node $n, array &$map, int $depth = 0): Node
    {
        $this->checkBudget();

        $copy                   = clone $n;
        $map[spl_object_id($n)] = $copy;

        if ($depth > self::MAX_COLUMN_DEPTH) {
            return $copy;
        }

        $children = [];

        foreach ($n->children as $child) {
            $children[] = $this->copyNode($child, $map, $depth + 1);
        }

        $copy->children = $children;

        return $copy;
    }

    /**
     * One background across every piece a spanner split a wrapper into, which
     * is defect HJ.
     *
     * {@see splitAroundSpanner} is tree surgery and it runs before either
     * piece has a height, so it cannot band the background there and both
     * pieces painted the whole of it: a two-colour gradient on a wrapper with
     * a spanner in the middle of it has ONE edge in Chrome and had one per
     * piece here, `TV-spanner-bg.html` s0. The heights exist once the run is
     * placed, which is here, and a background belongs to the box rather than
     * to the piece, which is what {@see Node::$slicedBackground} already says
     * for a box a page break or a column boundary cut.
     *
     * **A group any cut has already banded is left alone.** `cutBox()` writes
     * a slice of its own for a column cut, and its arithmetic carries the
     * FILL height, which is the bottom of the column rather than the bottom of
     * what landed in it: recomputing it from the laid-out heights would undo
     * that.
     *
     * @param list<Node> $placed
     */
    private function sliceSpannerPieces(array $placed): void
    {
        $groups = [];

        foreach ($placed as $piece) {
            $this->collectFragmentGroups($piece, $groups, 0);
        }

        foreach ($groups as $pieces) {
            if (count($pieces) < 2) {
                continue;
            }

            $total = 0.0;

            foreach ($pieces as $piece) {
                if ($piece->slicedBackground !== null) {
                    continue 2;
                }

                $total += $piece->layoutHeight;
            }

            $start = 0.0;

            foreach ($pieces as $piece) {
                $piece->slicedBackground = [$start, $total];
                $start                   += $piece->layoutHeight;
            }
        }
    }

    /**
     * Every box under this one that paints a background layer, keyed by the
     * box it is a piece of.
     *
     * A wrapper written around a spanner can be nested, so the pieces are not
     * always children of the multi-column box: `<section><div>` with the
     * spanner inside the `<div>` splits both, and the inner pair sits inside
     * the outer one.
     *
     * @param array<int,list<Node>> $groups
     */
    private function collectFragmentGroups(Node $box, array &$groups, int $depth): void
    {
        if ($depth > self::MAX_COLUMN_DEPTH) {
            return;
        }

        if (!$box->columnSpanAll && $box->backgroundLayers !== []) {
            $groups[spl_object_id($box->fragmentOf ?? $box)][] = $box;
        }

        foreach ($box->children as $child) {
            $this->collectFragmentGroups($child, $groups, $depth + 1);
        }
    }

    /**
     * The multi-column box's children with every `column-span: all` box among
     * their descendants hoisted up to be one of them.
     *
     * CSS Multi-column §6: a spanner is pulled out of the wrapper it was
     * written in and the wrapper is **split around it**, so a heading inside a
     * `<section>` still spans the columns. Chrome does exactly that on
     * `RX-column.html` x9, where the wrapper contributes nothing because it
     * has nothing else in it. That was defect EO.
     *
     * @return list<Node>
     */
    private function hoistedChildren(Node $n): array
    {
        $out   = [];
        $queue = $n->children;
        $guard = 0;

        while ($queue !== []) {
            $child = array_shift($queue);
            $split = $guard++ < self::MAX_COLUMN_PIECES && !$child->columnSpanAll
                ? $this->splitAroundSpanner($child)
                : null;

            if ($split === null) {
                $out[] = $child;

                continue;
            }

            [$head, $spanner, $tail] = $split;
            $out[]                   = $head;
            $out[]                   = $spanner;

            // The tail may hold the next spanner, which is what a wrapper with
            // two of them in it asks for.
            array_unshift($queue, $tail);
        }

        return $out;
    }

    /**
     * Split a box around the first spanner among its descendants.
     *
     * The box handed in becomes the piece before the spanner and keeps its own
     * identity, so an anchor, an outline entry and a query container all stay
     * on the box the document declared. The piece after it is a copy.
     *
     * The walk descends through block boxes only, which is what CSS asks for:
     * a spanner inside a table, a flex item, a float or an out-of-flow box is
     * not a spanner at all, and neither is one inside a multi-column box of
     * its own.
     *
     * @return array{0:Node,1:Node,2:Node}|null before, the spanner, after
     */
    private function splitAroundSpanner(Node $box, int $depth = 0): ?array
    {
        if ($depth > self::MAX_COLUMN_DEPTH
            || $box->children === []
            || !in_array($box->display, ['block', 'flow-root', 'list-item'], true)
            || $box->isOutOfFlow()
            || $box->isFloating()
            || $this->isMulticol($box)
        ) {
            return null;
        }

        foreach ($box->children as $i => $child) {
            $inner = null;

            if ($child->columnSpanAll) {
                $spanner = $child;
            } else {
                $inner = $this->splitAroundSpanner($child, $depth + 1);

                if ($inner === null) {
                    continue;
                }

                $spanner = $inner[1];
            }

            $tail           = clone $box;
            $tail->children = array_slice($box->children, $i + 1);
            $box->children  = array_slice($box->children, 0, $i);

            if ($inner !== null) {
                $box->children[] = $inner[0];
                array_unshift($tail->children, $inner[2]);
            }

            $edges               = $box->fragmentEdges ?? ['top', 'right', 'bottom', 'left'];
            $box->fragmentEdges  = array_values(array_diff($edges, ['bottom']));
            $tail->fragmentEdges = array_values(array_diff($edges, ['top']));

            $box->margin['bottom'] = 0.0;
            $tail->margin['top']   = 0.0;
            $tail->anchorId        = null;
            $tail->outlineTitle    = '';
            $tail->marker          = null;
            $tail->markerImage     = null;
            $tail->fragmentOf      = $box->fragmentOf ?? $box;

            return [$box, $spanner, $tail];
        }

        return null;
    }

    /**
     * Cut a laid-out box $room below its own top and hand back the piece that
     * did not fit, or null where the box cannot be cut there and has to move
     * whole.
     *
     * The box handed in is a copy of the one in the document, so the cut is
     * free to take it apart: a multi-column box is laid out more than once
     * whenever a shrink-to-fit measure or a second container-query pass asks
     * for a different width, and the run has to start from the tree the
     * builder made every time.
     *
     * What cannot be cut is what the page fragmenter refuses too
     * ({@see Fragmenter::isSplittable}), plus the three layout modes whose cut
     * is one offset through every cell, item or column of them: a table, a
     * grid and a flex container each move whole, which is what every child of
     * a multi-column box did before this.
     *
     * $room is the balance target and $ceiling is the height the box itself
     * was given, which are the same thing only where the target reaches it.
     * A line box may run past the first and never past the second.
     *
     * $fill is how tall the piece above the cut ends up, which is the bottom
     * of the column rather than the bottom of what landed in it. It travels
     * down the walk because every box the cut passes through ends at the same
     * place, which is {@see cutBox} and `SH-column-fragheight.html` h6.
     */
    private function splitAtOffset(Node $n, float $room, float $ceiling = INF, int $depth = 0, float $fill = 0.0): ?Node
    {
        $whole = $n->layoutHeight;

        if ($depth > self::MAX_COLUMN_DEPTH || $room <= 0.01 || $room >= $whole - 0.01) {
            return null;
        }

        if ($n->breakInside === 'avoid' || $n->isOutOfFlow() || $n->isFloating()) {
            return null;
        }

        if ($n->display === 'text') {
            return $this->splitLinesAtOffset($n, $room, $ceiling, $fill);
        }

        // A replaced element is monolithic: there is no break opportunity
        // inside a picture. A form field is one box holding one widget.
        if ($n->image !== null || $n->svg !== null || $n->formField !== null) {
            return null;
        }

        if (!$this->cutsBetweenChildren($n)) {
            return null;
        }

        // CSS Fragmentation §3.1: what a box with no children distributes
        // across fragments is its own content box, so one with nothing in it
        // cannot be broken and one with a content height can be broken
        // anywhere. Chrome cuts `RZ-column-frag.html` z4 four pixels in, which
        // is exactly its top border.
        if ($n->children === []) {
            return $whole - $n->edgeCross(true) > 0.01 ? $this->cutBox($n, $room, $fill) : null;
        }

        $kept  = [];
        $moved = [];
        $cut   = null;

        // A band is cut at one offset through every item of it or not at all,
        // so the whole band is decided once and every item of it is emitted in
        // that one decision. Bands of a single child are the ordinary walk
        // below and are left to it.
        $bands  = $this->columnBands($n) ?? [];
        $bandOf = [];

        foreach ($bands as $index => $items) {
            if (count($items) < 2) {
                continue;
            }

            foreach ($items as $item) {
                $bandOf[spl_object_id($item)] = $index;
            }
        }

        $emitted = [];

        foreach ($n->children as $child) {
            $id = spl_object_id($child);

            if (isset($emitted[$id])) {
                continue;
            }

            // An out-of-flow box is placed against its own containing block
            // rather than against the flow, so it stays with the piece the
            // tree already points at and is never copied into the other one.
            if ($child->isOutOfFlow()) {
                $kept[] = $child;

                continue;
            }

            if ($moved !== []) {
                $moved[] = $child;

                continue;
            }

            if (isset($bandOf[$id])) {
                $band = $bands[$bandOf[$id]];

                [$where, $heads, $pieces, $at] = $this->cutBand($n, $band, $room, $ceiling, $depth, $fill);

                foreach ($band as $item) {
                    $itemId           = spl_object_id($item);
                    $emitted[$itemId] = true;

                    if ($where === 'move') {
                        $moved[] = $item;

                        continue;
                    }

                    $kept[] = $heads[$itemId] ?? $item;

                    if (isset($pieces[$itemId])) {
                        $pieces[$itemId]->y = $at;
                        $moved[]            = $pieces[$itemId];
                    }
                }

                if ($where !== 'move') {
                    $cut = max($cut ?? 0.0, $at);
                }

                continue;
            }

            $foot = $child->y + $child->layoutHeight + $child->margin['bottom'];

            if ($foot <= $room + 1e-6) {
                $kept[] = $child;
                $cut    = max($cut ?? 0.0, $foot);

                continue;
            }

            if ($child->y - $child->margin['top'] >= $room - 1e-6) {
                $moved[] = $child;

                continue;
            }

            $before = $child->layoutHeight;
            $piece  = $this->splitAtOffset(
                $child,
                $room - $child->y,
                $ceiling - $child->y,
                $depth + 1,
                $fill - $child->y,
            );

            if ($piece === null) {
                $moved[] = $child;

                continue;
            }

            $kept[] = $child;

            // Where the CONTENT was cut, which is not where the child now
            // ends: a child that carries on was filled to the column's bottom
            // and reading its height back would charge this box for the fill
            // twice, leaving its own tail that much shorter.
            $cut      = $child->y + $before - $piece->layoutHeight;
            $piece->y = $cut;
            $moved[]  = $piece;
        }

        // Nothing to carry, or nothing left behind: a cut with no content
        // above it paints a band of background with nothing in it, which is
        // the rule {@see Fragmenter::landsHere} reads at a page boundary.
        if ($moved === [] || $cut === null || $cut <= 0.01 || $cut >= $whole - 0.01) {
            return null;
        }

        $tail           = $this->cutBox($n, $cut, $fill);
        $n->children    = $kept;
        $tail->children = $moved;

        foreach ($moved as $child) {
            $child->y -= $cut;
        }

        return $tail;
    }

    /**
     * Decide what a column boundary does to one band, and cut it if it can.
     *
     * A band is the cells of a table row, the items of a grid row or the items
     * of a flex line, and it is cut at ONE offset through every item of it or
     * not at all. So the offset has to be one every item can be cut at, which
     * is {@see Fragmenter::rowBreaksHere}'s rule at a page boundary and defect
     * HL in this path: `TX-band-cut.html` x4 puts a `break-inside: avoid` item
     * beside an ordinary one and Chrome moves the whole band to the next
     * column, leaving the first with nothing but the box above it.
     *
     * **Every item is cut on a copy of itself**, because one item refusing
     * moves the band whole and the items before it have already been taken
     * apart by then. The copies are what the head keeps when the cut goes
     * through, and they are dropped whole when it does not.
     *
     * An item whose own CONTENT ends above the offset is cut as a box alone:
     * a table stretches every cell to the row's height, so a short cell
     * straddles the boundary with nothing to carry, and refusing there would
     * move a row Chrome cuts (`TX-band-cut.html` x6).
     *
     * @param  Node[] $items
     * @return array{0:string,1:array<int,Node>,2:array<int,Node>,3:float}
     *         what to do, the head per item, the tail piece per item, and the
     *         offset the band was cut at
     */
    private function cutBand(Node $n, array $items, float $room, float $ceiling, int $depth, float $fill): array
    {
        $top  = INF;
        $foot = -INF;

        foreach ($items as $item) {
            $top  = min($top, $item->y - $item->margin['top']);
            $foot = max($foot, $item->y + $item->layoutHeight + $item->margin['bottom']);
        }

        if ($foot <= $room + 1e-6) {
            return ['keep', [], [], $foot];
        }

        if ($top >= $room - 1e-6) {
            return ['move', [], [], 0.0];
        }

        $heads  = [];
        $pieces = [];
        $cut    = null;

        foreach ($items as $item) {
            if ($item->y + $item->layoutHeight + $item->margin['bottom'] <= $room + 1e-6) {
                continue;
            }

            $copy   = $this->cloneSubtree($item);
            $offset = $room - $copy->y;

            // A row aligns its cells to the top of the fragment the moment it
            // is cut, which is what `vertical-align` leaves to be undone and
            // what {@see Fragmenter::topAlignCells} does at a page boundary.
            if ($n->display === 'table-row') {
                $this->topAlignCell($copy);
            }

            $before = $copy->layoutHeight;
            $piece  = $this->splitAtOffset($copy, $offset, $ceiling - $copy->y, $depth + 1, $fill - $copy->y);

            if ($piece !== null) {
                $cut = max($cut ?? 0.0, $copy->y + $before - $piece->layoutHeight);
            }

            if ($piece === null) {
                if (!$this->contentEndsAbove($copy, $offset)) {
                    return ['move', [], [], 0.0];
                }

                $piece           = $this->cutBox($copy, $offset, $fill - $copy->y);
                $piece->children = [];
            }

            $heads[spl_object_id($item)]  = $copy;
            $pieces[spl_object_id($item)] = $piece;
        }

        if ($pieces === []) {
            return ['keep', [], [], $foot];
        }

        return ['cut', $heads, $pieces, $cut ?? $room];
    }

    /**
     * Is all of this box's own content above the offset, whatever its box
     * does?
     *
     * A cell stretched to its row's height straddles a boundary its content
     * cleared, and the box is still cut there so its background and its border
     * are sliced the way every other fragment's are. A box with no children
     * has nothing to say here and neither does a run of text, because both are
     * cut by {@see splitAtOffset} itself wherever they can be.
     */
    private function contentEndsAbove(Node $n, float $offset): bool
    {
        if ($n->display === 'text' || $n->children === []) {
            return false;
        }

        foreach ($n->children as $child) {
            if ($child->isOutOfFlow()) {
                continue;
            }

            if ($child->y + $child->layoutHeight + $child->margin['bottom'] > $offset + 1e-6) {
                return false;
            }
        }

        return true;
    }

    /**
     * Give back the offset `vertical-align` gave this cell's content inside its
     * row, because a row a column boundary cuts aligns its cells to the top of
     * the fragment.
     *
     * `TX-band-cut.html` x6 is an 18px block in a 36px row: Chrome paints all
     * 18 of it in the first column, which is the top of the row and not the
     * 9px down `middle` put it. It is done once and cleared, the way
     * {@see Fragmenter::topAlignCells} does it, and it is done on the copy the
     * cut works on so a row that turns out to move whole stays centered.
     */
    private function topAlignCell(Node $cell): void
    {
        if ($cell->cellShift <= 0.01) {
            return;
        }

        foreach ($cell->children as $child) {
            $child->y -= $cell->cellShift;
        }

        $cell->cellShift = 0.0;
    }

    /**
     * Cut a run of line boxes, which is the one break opportunity a paragraph
     * has.
     *
     * **A line that starts before the balance target belongs to the column it
     * started in**, however far past the target it ends. Taking only the lines
     * that fit inside the target drops the straddling line into the next
     * column and is defect ET: `SC-column-lines.html` c1 is 3 + 2 in Chrome
     * against 2 + 3 that way, c2 is 4 + 3 against 3 + 4, and c9 has a first
     * line twice the height of the rest so the two rules name different lines
     * rather than the same one twice. c10's target falls exactly on a line's
     * top, and a line that starts ON the target has not started before it.
     *
     * `orphans` and `widows` then move the break, and **orphans wins**:
     * Chrome pushes the break down until the column keeps `orphans` lines, and
     * back up toward carrying `widows` lines **as far as `orphans` allows**.
     * Where they cannot both hold it breaks anyway rather than moving the
     * paragraph whole, which is c12, three lines cut two and one at the
     * initial value of both properties.
     *
     * **The move up is partial where it has to be**, which is what round 44
     * corrected: the old rule gave up entirely when carrying `widows` lines
     * would leave fewer than `orphans` behind, and Chrome takes what it can.
     * `SG-column-widows.html` g5 is seven lines at `widows: 3` and its second
     * break carries **two** rather than the one giving up produced.
     *
     * A break the property forbids outright is not moved here at all, because
     * a column that cannot hold the fragment `widows` asks for is a column
     * that has to be taller, which is {@see columnLineFloor}.
     *
     * A column of a box that declares a height cannot grow to hold the
     * straddling line, so there $ceiling stops it and the line goes to the
     * next column: `RZ-column-frag.html` z10 is three lines of a 40px column
     * and not the four the target alone would keep.
     */
    private function splitLinesAtOffset(Node $n, float $room, float $ceiling = INF, float $fill = 0.0): ?Node
    {
        $lines = $n->lineBoxes;
        $count = count($lines);
        $inner = $room - $n->edge('top');
        $cap   = $ceiling - $n->edge('top');

        $fit  = 0;
        $used = 0.0;

        foreach ($lines as $line) {
            if ($used >= $inner - 1e-6 || $used + $line->height > $cap + 1e-6) {
                break;
            }

            $used += $line->height;
            $fit++;
        }

        // No line starts inside what is left of the column, so there is
        // nothing to leave behind and the paragraph moves whole.
        if ($fit === 0) {
            return null;
        }

        $fit = max($n->orphans, min($fit, $count - $n->widows));

        if ($fit <= 0 || $fit >= $count) {
            return null;
        }

        $kept = 0.0;

        foreach (array_slice($lines, 0, $fit) as $line) {
            $kept += $line->height;
        }

        $tail            = $this->cutBox($n, $n->edge('top') + $kept, $fill);
        $n->lineBoxes    = array_slice($lines, 0, $fit);
        $tail->lineBoxes = array_slice($lines, $fit);

        return $tail;
    }

    /**
     * The piece of $n below the cut, with $n left as the piece above it.
     *
     * `box-decoration-break` defaults to `slice`, so a column boundary is not
     * an edge of the box: the piece above owns no bottom border and the piece
     * below owns no top one, and the background is one band of the whole box
     * rather than a fresh one per piece. A margin does not split at all.
     *
     * $fill is where the piece above ends, which is the bottom of the column
     * and not the bottom of what landed in it. A box that carries on into the
     * next fragmentainer fills the one it is leaving:
     * `SH-column-fragheight.html` h7 is three lines of 36px in a 40px column
     * and Chrome paints the band 40. It never shortens a piece, because a line
     * that starts before the target may end after it, and it is 0 for every
     * caller that is not a column cut. **A balanced column is exactly as tall
     * as its content**, so this adds nothing at all to most boxes and h1 and
     * h4 are the controls that say so.
     *
     * The two pieces still tile one band of background: what the piece above
     * gained, the piece below starts that much further down, and the band is
     * that much longer.
     */
    private function cutBox(Node $n, float $cut, float $fill = 0.0): Node
    {
        $whole = $n->layoutHeight;
        $tail  = clone $n;
        $ends  = max($cut, $fill);

        $edges               = $n->fragmentEdges ?? ['top', 'right', 'bottom', 'left'];
        $n->fragmentEdges    = array_values(array_diff($edges, ['bottom']));
        $tail->fragmentEdges = array_values(array_diff($edges, ['top']));

        [$start, $total]        = $n->slicedBackground ?? [0.0, $whole];
        $n->slicedBackground    = [$start, $total + $ends - $cut];
        $tail->slicedBackground = [$start + $ends, $total + $ends - $cut];

        $n->margin['bottom'] = 0.0;
        $tail->margin['top'] = 0.0;

        $n->layoutHeight    = $ends;
        $tail->layoutHeight = $whole - $cut;

        // A box has one anchor, one outline entry, one list marker and one
        // structure element however many pieces it is cut into, and they all
        // belong to the first.
        $tail->anchorId     = null;
        $tail->outlineTitle = '';
        $tail->marker       = null;
        $tail->markerImage  = null;
        $tail->fragmentOf   = $n->fragmentOf ?? $n;

        return $tail;
    }

    /** Resolve a length or percentage against a basis. Used by GridLayout. */
    public function resolveLength(Node $n, float|string|null $value, float $basis): ?float
    {
        return $this->resolve($n, $value, $basis);
    }

    /** Clamp a used size between min and max. Used by GridLayout. */
    public function clampSize(float $value, ?float $min, ?float $max): float
    {
        return $this->clamp($value, $min, $max);
    }

    /** Lay a subtree out against a given available space. Used by TableLayout. */
    public function measure(Node $n, ?float $availWidth, ?float $availHeight): void
    {
        $this->layoutNode($n, $availWidth, $availHeight);
    }

    /** Widest the box wants to be if nothing wraps. */
    public function maxContentWidth(Node $n): float
    {
        if ($n->display !== 'text' && is_float($n->width)) {
            return $n->width + $n->edgeMain(true);
        }

        return $this->contentMaxWidth($n);
    }

    /**
     * The same width with the box's own declared one left out of it, which is
     * what a table column asks for: a cell's `width` is an input to the column
     * algorithm beside its content rather than a replacement for it, so the
     * two have to be readable apart.
     */
    public function contentMaxWidth(Node $n): float
    {
        // Inline-axis containment, the same sentence as in contentMinWidth().
        if ($n->containerType !== 'normal') {
            return $n->edgeMain(true);
        }

        if ($n->display === 'text') {
            $runs = $n->inlineRuns();

            if ($runs === []) {
                return 0.0;
            }

            $this->layoutAtomicInlines($runs, null);

            return new InlineFormatter()->maxContentWidth($runs);
        }

        if ($n->children === []) {
            return $n->edgeMain(true);
        }

        $isRow = ($n->display === 'flex' && $n->isRow()) || $n->display === 'table-row';
        $total = 0.0;

        foreach ($n->children as $child) {
            $cw = $this->maxContentWidth($child) + $child->marginMain(true);

            if ($isRow) {
                $total += $cw;
            } else {
                $total = max($total, $cw);
            }
        }

        if ($isRow) {
            $total += max(0, count($n->children) - 1) * $n->gap;
        }

        return $total + $n->edgeMain(true);
    }

    /** Convert parent-relative positions into absolute page coordinates. */
    private function accumulateOffsets(Node $n, float $dx, float $dy): void
    {
        $n->x += $dx;
        $n->y += $dy;

        foreach ($n->children as $child) {
            if ($child->isOutOfFlow()) {
                continue;
            }

            $this->accumulateOffsets($child, $n->x, $n->y);
        }
    }

    /**
     * Translate a subtree that is already in absolute coordinates: every box
     * moves by the same delta.
     *
     * accumulateOffsets cannot do this. It hands each child its parent's
     * absolute position, which is right only while the child still holds a
     * parent-relative one. Applied to an absolute subtree it displaces every
     * descendant by its parent's whole coordinate, so a box far down the page
     * has its rows, cells and text pushed a page-length further at each level.
     */
    private function shiftSubtree(Node $n, float $dx, float $dy): void
    {
        $n->x += $dx;
        $n->y += $dy;

        foreach ($n->children as $child) {
            if ($child->isOutOfFlow()) {
                continue;
            }

            $this->shiftSubtree($child, $dx, $dy);
        }
    }

    /**
     * Place every absolutely positioned descendant. $block is the current
     * containing block: the nearest ancestor with a position other than
     * static, or the root for `fixed` and for anything with no such ancestor.
     */
    private function placePositioned(Node $n, Node $block, float $pageWidth, float $pageHeight): void
    {
        $nextBlock = $n->isPositioned() ? $n : $block;

        // One walk of the lines answers for every out-of-flow child at once,
        // and the walk costs the whole block, so it is taken once and only
        // where something asks for it.
        $inline = null;

        foreach ($n->children as $child) {
            if ($child->isOutOfFlow()) {
                $cb   = $child->position === 'fixed' ? null : $nextBlock;
                $rect = null;

                if ($child->position !== 'fixed' && $child->inlineContainer !== null) {
                    $inline ??= $this->inlineContainingBlocks($n);
                    $rect   = $inline[$child->inlineContainer] ?? null;
                }

                // The flow is absolute by now, so the static position recorded
                // against the parent becomes one by adding the parent in.
                $static = [
                    $n->x + ($child->staticX ?? $n->edge('left')),
                    $n->y + ($child->staticY ?? $n->edge('top')),
                ];

                $this->placeAbsolute($child, $cb, $pageWidth, $pageHeight, $rect, $static, $n);
            }

            $this->placePositioned($child, $nextBlock, $pageWidth, $pageHeight);
        }
    }

    /**
     * The rect every inline element on this block's lines hands its out-of-flow
     * descendants, per CSS 2.1 section 10.1.4.1: the bounding box around the
     * padding boxes of its first and its last fragment, keyed by the element.
     *
     * They are read off the laid-out lines rather than off a box, because an
     * inline element has none. An element that generated no fragment is absent,
     * and the block stands in for it.
     *
     * @return array<int, array{0:float,1:float,2:float,3:float}>
     */
    private function inlineContainingBlocks(Node $n): array
    {
        $bounds = [];

        $walk = static function (Node $node) use (&$walk, &$bounds): void {
            $cursor = $node->y;

            foreach ($node->lineBoxes as $line) {
                $baseline = $cursor + $line->baseline;

                foreach ($line->items as $item) {
                    foreach ($item->boxes as $depth => $box) {
                        $x0 = $node->x + $item->x - $item->edgeBefore($depth)
                            + ($box['border']['left']['width'] ?? 0.0);
                        $x1 = $node->x + $item->x + $item->width + $item->edgeAfter($depth)
                            - ($box['border']['right']['width'] ?? 0.0);
                        $y0 = $baseline - $item->baselineShift - $box['above'] - $box['padTop'];
                        $y1 = $baseline - $item->baselineShift + $box['below'] + $box['padBottom'];

                        $seen = $bounds[$box['id']] ?? null;

                        $bounds[$box['id']] = $seen === null
                            ? [$x0, $y0, $x1, $y1]
                            : [$seen[0], $seen[1], $x1, $y1];
                    }
                }

                $cursor += $line->height;
            }

            foreach ($node->children as $child) {
                if (!$child->isOutOfFlow()) {
                    $walk($child);
                }
            }
        };

        $walk($n);

        return array_map(
            static fn(array $b): array => [$b[0], $b[1], max(0.0, $b[2] - $b[0]), max(0.0, $b[3] - $b[1])],
            $bounds,
        );
    }

    /**
     * @param array{0:float,1:float,2:float,3:float}|null $rect
     * @param array{0:float,1:float}|null                 $static the box's
     *        static position in absolute coordinates, per CSS 2.1 §10.6.4
     */
    private function placeAbsolute(
        Node $child,
        ?Node $block,
        float $pageWidth,
        float $pageHeight,
        ?array $rect = null,
        ?array $static = null,
        ?Node $staticHost = null,
    ): void {
        [$bx, $by, $bw, $bh] = $rect ?? [
            $block?->x ?? 0.0,
            $block?->y ?? 0.0,
            $block !== null ? max($block->layoutWidth, 0.0) : $pageWidth,
            $block !== null ? max($block->layoutHeight, 0.0) : $pageHeight,
        ];

        $left   = $this->resolve($child, $child->left, $bw);
        $right  = $this->resolve($child, $child->right, $bw);
        $top    = $this->resolve($child, $child->top, $bh);
        $bottom = $this->resolve($child, $child->bottom, $bh);

        // With both edges pinned and no explicit size, the box stretches.
        $width = $this->usedWidth($child, $bw);

        if ($width === null && $left !== null && $right !== null) {
            $width = max(0.0, $bw - $left - $right - $child->marginMain(true));
        }

        // CSS 2.1 §10.3.7: an out-of-flow box with an automatic width and at
        // most one of `left` and `right` is shrink-to-fit, not a box that
        // fills its containing block. Handing it `$bw` made every one of them
        // as wide as the block it is positioned in: a bare
        // `position: absolute` holding `alpha be cd` in a 300pt block was
        // 300.000 against Chrome's 46.547 (`OJ-abspos-shrink.html` `j8`). The
        // available width the fit is measured against takes the offsets out,
        // which is what §10.3.7 says and what `j1` and `j4` read.
        if ($width === null) {
            $this->layoutShrinkToFit(
                $child,
                max(0.0, $bw - ($left ?? 0.0) - ($right ?? 0.0) - $child->marginMain(true)),
                $bw,
            );
        } else {
            $this->layoutNode($child, $width, null);
            $child->layoutWidth = $width;
        }

        $height = $this->usedHeight($child, $bh);

        if ($height === null && $top !== null && $bottom !== null) {
            $child->layoutHeight = max(0.0, $bh - $top - $bottom - $child->marginCross(true));
        }

        // CSS 2.1 §10.3.7 and §10.6.4: with neither offset given the box stays
        // where the flow would have put it, not at the containing block's
        // origin. The two coincide often enough that this went unfound for
        // eighteen rounds: they differ the moment the containing block is an
        // ancestor rather than the parent (`OJ-abspos-shrink.html` `j6`, y
        // 216.000 against 0.000) or anything precedes the box in its parent
        // (`j7`, 264.000 against 252.000). Defect DB.
        // A flex or a grid container aligns the box it holds back, and it can
        // only be asked once the box has a size. Defect GJ.
        [$alignX, $alignY] = $staticHost === null
            ? [0.0, 0.0]
            : $this->staticInside($staticHost, $child);

        $x = match (true) {
            $left !== null  => $bx + $left + $child->margin['left'],
            $right !== null => $bx + $bw - $right - $child->layoutWidth - $child->margin['right'],
            default         => ($static[0] ?? $bx) + $alignX + $child->margin['left'],
        };

        $y = match (true) {
            $top !== null    => $by + $top + $child->margin['top'],
            $bottom !== null => $by + $bh - $bottom - $child->layoutHeight - $child->margin['bottom'],
            default          => ($static[1] ?? $by) + $alignY + $child->margin['top'],
        };

        $child->x = 0.0;
        $child->y = 0.0;
        $this->accumulateOffsets($child, $x, $y);
    }

    /** `relative` stays in flow but paints offset from where it landed. */
    private function applyRelativeOffsets(Node $n): void
    {
        foreach ($n->children as $child) {
            if ($child->position === 'relative') {
                $dx = ($this->resolve($child, $child->left, $n->layoutWidth) ?? 0.0)
                    - ($this->resolve($child, $child->right, $n->layoutWidth) ?? 0.0);
                $dy = ($this->resolve($child, $child->top, $n->layoutHeight) ?? 0.0)
                    - ($this->resolve($child, $child->bottom, $n->layoutHeight) ?? 0.0);
                if (abs($dx) > 1e-9 || abs($dy) > 1e-9) {
                    $this->shiftSubtree($child, $dx, $dy);
                }
            }

            $this->applyRelativeOffsets($child);
        }
    }
}
