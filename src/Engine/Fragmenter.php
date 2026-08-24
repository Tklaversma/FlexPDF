<?php

declare(strict_types=1);

namespace FlexPDF\Engine;

use FlexPDF\Engine\Exceptions\PageLimitExceededException;
use FlexPDF\Engine\Support\Deadline;
use FlexPDF\Engine\Support\Limits;

/**
 * Slices a laid-out box tree into fragmentainers (pages).
 *
 * The spike question: can a flex container be split across a page boundary
 * sensibly? The answer this implements, and which the tests below probe,
 * is that the *flex line* is the atomic unit in the cross axis. Column
 * containers fragment between items freely; row containers fragment between
 * lines, and only force-split within a line under protest.
 */
final class Fragmenter
{
    /** True once the ceiling is hit; further page breaks are refused. */
    private bool $exhausted = false;

    /** True once the ceiling has actually cost the document content. */
    private bool $truncated = false;

    private bool $oversizedHeaderNoted = false;

    /** Set when an advance could not complete; suppresses ancestor top-ups. */
    private bool $advanceTruncated = false;

    /** Guards against pathological nesting in adversarial input. */
    private int $depth = 0;

    /** @var Fragment[][] */
    private array $pages  = [];
    private int   $page   = 0;
    private float $cursor = 0.0;

    /**
     * Where content may start on the current page: zero, or below whatever
     * repeated headers were replayed onto it. A cursor still sitting on it
     * means the page holds nothing yet, so taking another page would offer
     * exactly the same room.
     */
    private float $pageFloor = 0.0;

    /**
     * The floor each page ended up with, so a page reached twice answers the
     * same question twice the same way.
     *
     * The band walk places every item of a band from the band's own start, so
     * a page is turned once per item that crosses the fold, and `pageFloor`
     * alone (one number, for whichever page is current) cannot say what the
     * page already carries.
     *
     * @var array<int, float>
     */
    private array $pageFloors = [];

    /**
     * Which runs of repeated rows have already been laid onto which page.
     *
     * A repeating header belongs to a page once, however many times something
     * asks for it. Keyed by the run, so two tables (or a table nested in a
     * cell of another) each still get their own.
     *
     * @var array<int, array<string, true>>
     */
    private array $repeatersOnPage = [];

    /**
     * Where on a page each run of repeated rows began, keyed the same way.
     *
     * The box that **holds** a run paints its background behind its own
     * repeated header, so on a continuation page its decoration resumes where
     * that header starts rather than at the top of the page or below it. With
     * one run those two are the same number and {@see $pageFloors} was enough;
     * with a table nested in a cell of another they are not. Chrome's answer on
     * `OA-nested-thead-bg.html` is the outer table's background at 0.000, the
     * outer header on top of it, the inner table's background at **15.000**,
     * its own header on top of that and the content at 30.000.
     *
     * @var array<int, array<string, float>>
     */
    private array $repeaterFloors = [];

    /** Height held back at the foot of every page for a repeating `<tfoot>`. */
    private float $footerReserve = 0.0;

    /**
     * Height held back at the foot of every page for the bottom padding and
     * border of every open `box-decoration-break: clone` box, and the matching
     * inset at the head of every page for their top ones.
     *
     * CSS Fragmentation wraps every fragment of a `clone` box in the box's own
     * border and padding rather than giving the first fragment the top edge and
     * the last the bottom, so a fragment that continues spends both. Chrome on
     * `SJ-clone-thrice.html` gives the first fragment 9pt of content where 24
     * is left on the page, starts every later fragment's content 15pt down, and
     * takes three pages for content that is two pages under `slice`.
     *
     * These are kept apart from `$footerReserve` on purpose. That one is a band
     * the box may not paint into, so {@see closeDecoration} ends a slice above
     * it; a clone box's own background runs all the way to the fold and only
     * its content stops short.
     *
     * The margin is NOT one of them, which is measured rather than read off the
     * spec: `SJ-clone-margin.html` is byte-identical to its `-slice` twin on
     * Chrome, so a 12px margin is spent once whichever spelling is in force.
     */
    private float $cloneReserve = 0.0;

    private float $cloneTop = 0.0;

    /**
     * Total margin dropped at a fold so far, over the whole run.
     *
     * A box that contains a truncated margin is that much shorter on paper
     * than layout made it, and every box above it is too, so the running total
     * is read as a delta at each level rather than reset. {@see settleGap}.
     */
    private float $gapsTruncated = 0.0;

    /** Diagnostics collected during the run. */
    public array $notes = [];

    /** @var array<int,array{page:int,y:float,x:float}> where each box was emitted */
    private array $placed = [];

    /** @var array<int,array{page:int,x:float,y:float}> where each box that wraps its subtree in an effect was */
    private array $effectSpots = [];

    /**
     * Where each positioned box STARTED, first fragment only.
     *
     * `$placed` records the boxes emitted whole and a container the fold
     * splits is emitted a fragment at a time, so it is never in it. Its
     * fragments do not answer either: a box that spans a break paints its
     * background through a proxy `rect` node, so the box's OWN node reaches no
     * fragment at all and reading the pages back cannot find it. That is
     * defect IV: an absolutely positioned child whose containing block was
     * split had no anchor to be placed against, fell through to the branch
     * that treats the page box as the containing block, and was painted on
     * page one from a flow coordinate belonging to page two.
     *
     * @var array<int,array{page:int,x:float,y:float}>
     */
    private array $positionedStarts = [];

    /** @var array<int,array{node:Node,ancestor:?Node,parent:?Node,chain:list<Node>}> out-of-flow boxes to place after the flow */
    private array $deferred = [];

    /**
     * Clip rects contributed by `overflow: hidden` ancestors, in page-local
     * coordinates. Fragments are painted independently, so each one has to
     * carry the clipping that applied to it rather than relying on the
     * painter to still be inside its parent's graphics state.
     *
     * The sixth entry is how many subtree effects were live when the clip was
     * pushed, which is what lets the painter tell a clip declared above a
     * transform from one declared under it. The two cut in different spaces
     * and a flattened rectangle cannot say which is which. Defect FA.
     *
     * @var array<int,array{0:float,1:float,2:float,3:float,4:array{0:float,1:float,2:float,3:float},5:int}>
     */
    private array $clips = [];

    /**
     * Subtree effects contributed by ancestors, in the order they were
     * entered: opacity, blend mode and transform, each with the geometry it
     * was pushed at. Same reasoning as the clips above and the same
     * page-local coordinates, and the same limitation: a box entered on one
     * page keeps the geometry it was entered with, which is what a transform
     * that straddles a fold pays.
     *
     * @var list<array{0:Node,1:float,2:float,3:float,4:float}>
     */
    private array $effects = [];

    private readonly Limits $limits;

    private readonly Deadline $deadline;

    /**
     * The usable height of one page, floored above zero.
     *
     * A page box whose margins consume it has no content area at all, and
     * `@page { size: 100pt 40pt; margin: 20pt }` is a document that says so in
     * two lines. Dividing a coordinate by that height to find its page then
     * threw a `DivisionByZeroError` out of the middle of `clampOverflow()`: an
     * unhandled crash on ordinary CSS. Flooring it leaves the ceiling to do
     * its job, so such a document reaches `max_pages` and is refused with the
     * exception that already means "this cannot be paginated".
     */
    private readonly float $pageHeight;

    /**
     * How much of a given page's strip is not that page's content box.
     *
     * A `@page :first` or `@page :left` block gives one page a shorter content
     * box than the rest, and the flow model here is one strip cut every
     * `$pageHeight`, so a page of a different height is not a parameter of it.
     * What IS a parameter is how much of a page the flow may use: the strip
     * keeps its pitch, the tallest page uses all of it, and a shorter page
     * holds back the difference at its foot. Painting then places each page's
     * content box where that page's own margins put it, so the held-back band
     * is the margin the qualified block asked for.
     *
     * Null is the ordinary document, where every page is the same height and
     * nothing is held back, so a document that writes no qualified `@page`
     * block cannot reach any of this.
     *
     * **{@see clampOverflow} reads the strip and not the band**, deliberately:
     * the band leaves the flow coordinate space with a hole in it, and a
     * fragment parked inside one has no page to be moved to. Everything the
     * flow places respects the band, because every "does it fit?" test goes
     * through {@see remaining}; a box the clamp has to cut short can still
     * reach into the margin of a page the band shortened, which is the one
     * thing a qualified `@page` block does not get exactly.
     *
     * Called with the page number and that page's own page type, from the
     * `page` property, so a named page can hold back a band of its own.
     *
     * @var ?callable(int,string):float
     */
    private $pageReserve;

    public function __construct(
        float $pageHeight,
        private readonly Font $font = new Font(),
        /** Deliberately break inside a row flex line to show what happens. */
        public bool $forceSplitFlexLines = false,
        ?Limits $limits = null,
        ?Deadline $deadline = null,
        /** @var ?callable(int,string):float */
        ?callable $pageReserve = null,
    ) {
        $this->pageHeight  = max(1.0, $pageHeight);
        $this->limits      = $limits ?? new Limits();
        $this->deadline    = $deadline ?? $this->limits->deadline();
        $this->pageReserve = $pageReserve;
    }

    /**
     * The band at the foot of one page that its own page box does not cover.
     *
     * Floored above zero and capped a point short of the whole page for the
     * reason {@see $pageHeight} is floored: a page with no room at all makes
     * every "does it fit?" test false and spins the page-advancing loops until
     * they hit their guard.
     */
    private function reserveOn(int $page): float
    {
        if ($this->pageReserve === null) {
            return 0.0;
        }

        return max(0.0, min($this->pageHeight - 1.0, ($this->pageReserve)($page, $this->typeOfPage($page))));
    }

    /**
     * The flow coordinate one page's content box ends at.
     *
     * The strip keeps its pitch at `$pageHeight` and a shortened page's own
     * content box ends before it, so this is the line a box on that page is cut
     * at and `$pageHeight` is the distance to the next page. Everything the flow
     * places already respects it through {@see remaining}; {@see clampOverflow}
     * asks it here because an out-of-flow box never went through the flow at
     * all, and reading the strip instead painted a box 75pt past a shortened
     * page's content box and 15pt off the paper (defect HY).
     */
    private function floorOn(int $page): float
    {
        return $this->pageHeight - $this->reserveOn($page);
    }

    /**
     * What the pages from $from up to but not including $to held back, which is
     * the room a box crossing them was NOT given.
     *
     * Zero for every document that writes no qualified `@page` block, so the
     * travelled-distance arithmetic below keeps the shape it has always had.
     */
    private function reservedBetween(int $from, int $to): float
    {
        if ($this->pageReserve === null) {
            return 0.0;
        }

        $total = 0.0;

        for ($page = $from; $page < $to; $page++) {
            $total += $this->reserveOn($page);
        }

        return $total;
    }

    /**
     * The page type the flow is inside at this point, from the `page` property.
     *
     * Empty for every document that names none, so the question below can only
     * ever answer false there.
     */
    private string $pageType = '';

    /**
     * The page each change of page type starts on.
     *
     * A named run is bracketed by forced breaks, so a page's type is the type of
     * the last change at or before it and every page a continuation invents
     * inherits the type of the page before it. That is why this records the
     * CHANGES rather than one entry per page: {@see clampOverflow} has three
     * ways to create a page without opening one, and a map with an entry per
     * page would have a hole at each of them.
     *
     * Empty for every document that names no page, which is what keeps
     * {@see typeOfPage} free there.
     *
     * @var array<int,string>
     */
    private array $pageTypeFrom = [];

    /**
     * {@see breaksInside}'s answer per box, by `spl_object_id`, for the length
     * of one run. Cleared in {@see fragment} because a second layout hands back
     * a second tree whose ids may repeat the first one's.
     *
     * @var array<int,bool>
     */
    private array $breaksInside = [];

    /**
     * Whether this box begins a run of pages of a different type, which forces
     * a page break both into a named run and out of it.
     *
     * CSS Paged Media 3 section 4.1, and it is the NAME that breaks rather than
     * the `@page` block: `VL-page-named-noblock.html` declares `page: cover`
     * with no block anywhere and Chrome paginates 4, 3, 9 against the 9, 7 of
     * the same document without the declaration. The break at the END of a run
     * is this same question asked of the box after it, whose name is the
     * ordinary one again.
     */
    private function startsAPageType(Node $node): bool
    {
        return $node->pageName !== $this->pageType;
    }

    /**
     * Whether anything inside this box asks for a page of its own.
     *
     * A forced break and a change of page name are the same question asked two
     * ways, and both are read off the box's own in-flow subtree: an out-of-flow
     * box is placed after the walk and cannot break the flow it left.
     *
     * The names are compared against **this box's** name rather than against
     * the type in force, because the box's own name has already been settled by
     * the walk that reached it. A subtree whose names all agree with their
     * container changes nothing inside it.
     *
     * Memoised for the length of one {@see fragment} run, so the whole tree
     * costs one walk however many boxes ask.
     */
    /**
     * Whether a `float` declaration on a child of this container does anything
     * at all.
     *
     * CSS Flexible Box §3 and CSS Grid §6 both say `float` does not apply to
     * their items, so a box carrying `float: right` inside a flex or grid
     * container is an ordinary item and not a float.
     * {@see Node::isFloating} is the raw declaration and cannot know that,
     * because a node holds no reference to its parent.
     *
     * `WZ-float-flex-item.html` is why this exists: a flex item carrying
     * `float: right` and `page: cover`, which Chrome puts on a named page and
     * which the float clause below silently swallowed.
     */
    private function floatsApplyIn(?Node $container): bool
    {
        if ($container === null) {
            return true;
        }

        return !in_array($container->display, ['flex', 'inline-flex', 'grid', 'inline-grid'], true);
    }

    private function breaksInside(Node $n, ?Node $parent = null): bool
    {
        $key = spl_object_id($n);

        if (isset($this->breaksInside[$key])) {
            return $this->breaksInside[$key];
        }

        // Set before recursing, so a tree that somehow reaches itself stops
        // rather than running out of stack.
        $this->breaksInside[$key] = false;

        // A multi-column box absorbs the question. Chrome turns a forced break
        // inside one into a COLUMN break and never a page break, and it does
        // the same with a change of page name:
        // `VX-page-named-column.html` and the same document with
        // `break-before: page` in place of the name both paginate as though
        // neither were there.
        if ($n->columnCount > 1) {
            return false;
        }

        // A float absorbs the question the same way, and for the reason this
        // docblock already gives for an out-of-flow box: it is placed beside
        // the flow rather than in it, so nothing inside it can break the flow
        // it left. `WX-page-named-in-float.html` puts `page: cover` inside a
        // `float: right` and `WY-break-in-float.html` puts `break-before: page`
        // there, and **Chrome paginates both as one page** where this engine
        // made two. The two spellings are one defect, which is what says the
        // answer belongs here rather than in either branch below.
        //
        // `WY` needs the filler above the float to say anything at all:
        // {@see forcePageBreak} does nothing at the top of a page, so a float
        // placed at the very top agrees for a reason that is not this one.
        //
        // **Only where a float is a float.** {@see floatsApplyIn}: a flex or
        // grid item carrying `float: right` is not one, and
        // `WZ-float-flex-item.html` is the boundary that caught this clause
        // being too wide.
        if ($this->floatsApplyIn($parent) && $n->isFloating()) {
            return false;
        }

        $floatsHere = $this->floatsApplyIn($n);

        foreach ($n->children as $child) {
            if ($child->isOutOfFlow() || ($floatsHere && $child->isFloating())) {
                continue;
            }

            if ($child->breakBefore === 'page'
                || $child->breakAfter === 'page'
                || $child->pageName !== $n->pageName
                || $this->breaksInside($child, $n)
            ) {
                return $this->breaksInside[$key] = true;
            }
        }

        return false;
    }

    /**
     * Where the first forced break inside this box is, in tree coordinates.
     *
     * {@see breaksInside} answers whether there is one; this answers where, and
     * it exists because `break-inside: avoid` on a box taller than the page
     * needs the height of the box's FIRST RUN rather than the height of the
     * whole box. The filters are {@see breaksInside}'s own, copied rather than
     * rewritten: a multi-column box absorbs the question, a float cannot break
     * the flow it left, and an out-of-flow box is placed after the walk.
     *
     * A `break-before` and a change of page name end the run at the child's own
     * top; a `break-after` ends it at the child's bottom.
     */
    private function firstBreakY(Node $n, ?Node $parent): ?float
    {
        if ($n->columnCount > 1) {
            return null;
        }

        if ($this->floatsApplyIn($parent) && $n->isFloating()) {
            return null;
        }

        $floatsHere = $this->floatsApplyIn($n);
        $best       = null;

        foreach ($n->children as $child) {
            if ($child->isOutOfFlow() || ($floatsHere && $child->isFloating())) {
                continue;
            }

            if ($child->breakBefore === 'page' || $child->pageName !== $n->pageName) {
                $at = $child->y;
            } elseif ($child->breakAfter === 'page') {
                $at = $child->y + $child->layoutHeight;
            } else {
                $at = $this->firstBreakY($child, $n);
            }

            if ($at !== null && ($best === null || $at < $best)) {
                $best = $at;
            }
        }

        return $best;
    }

    /**
     * Record the page type in force from the page the flow is now on.
     *
     * Called after the break rather than before it, because the type belongs to
     * the page the box lands on and {@see forcePageBreak} does nothing at the
     * top of a page: a document whose FIRST box is named puts it on page 1
     * rather than on a blank page 2.
     */
    private function enterPageType(string $name): void
    {
        $this->pageType                  = $name;
        $this->pageTypeFrom[$this->page] = $name;
    }

    /** The page type one page carries, which is the last change at or before it. */
    private function typeOfPage(int $page): string
    {
        if ($this->pageTypeFrom === []) {
            return '';
        }

        $best = -1;
        $type = '';

        foreach ($this->pageTypeFrom as $from => $name) {
            if ($from <= $page && $from > $best) {
                $best = $from;
                $type = $name;
            }
        }

        return $type;
    }

    /**
     * The margin this run dropped at a fold, which is flow height no page
     * carries.
     *
     * CSS Fragmentation 3.5 truncates a margin the break falls inside, so a
     * document whose height is mostly margin paginates onto far fewer pages
     * than that height suggests and is right to. `WB-margin-2100.html` is the
     * measurement, and both engines put a 2,100pt margin's next box at the top
     * of the page after.
     *
     * The fuzzer's page-count invariant reads this instead of working the same
     * quantity out again from the tree, which is what {@see settleGap} already
     * knows and a reader outside cannot see. Defect HW.
     */
    public function truncatedGaps(): float
    {
        return $this->gapsTruncated;
    }

    /**
     * The page type of every page that has one, by 0-based page index, for the
     * painter to put each page on its own sheet.
     *
     * @return array<int,string>
     */
    public function pageTypes(): array
    {
        if ($this->pageTypeFrom === []) {
            return [];
        }

        $types = [];

        foreach (array_keys($this->pages) as $page) {
            $name = $this->typeOfPage($page);

            if ($name !== '') {
                $types[$page] = $name;
            }
        }

        return $types;
    }

    /** @return Fragment[][] */
    public function fragment(Node $root): array
    {
        $this->pages        = [[]];
        $this->page         = 0;
        $this->pageType     = $root->pageName;
        $this->pageTypeFrom = $root->pageName === '' ? [] : [0 => $root->pageName];
        $this->breaksInside = [];

        // The cursor is the distance down the page's content area, and the root
        // box does not have to start at the top of it: its own `margin-top`
        // places it, exactly as any other block's does. Defect DE.
        $this->cursor = $root->y;

        $this->placed               = [];
        $this->effectSpots          = [];
        $this->positionedStarts     = [];
        $this->deferred             = [];
        $this->pageFloor            = 0.0;
        $this->pageFloors           = [];
        $this->repeatersOnPage      = [];
        $this->repeaterFloors       = [];
        $this->exhausted            = false;
        $this->truncated            = false;
        $this->oversizedHeaderNoted = false;
        $this->advanceTruncated     = false;
        $this->gapsTruncated        = 0.0;
        $this->cloneReserve         = 0.0;
        $this->cloneTop             = 0.0;

        $this->deadline->check('fragmentation');

        $this->collectOutOfFlow($root, null);

        $decoStart  = $this->cursor;
        $decoPage   = $this->page;

        $this->flowChildren($root, $root->x, 0.0);
        $this->closeRootDecoration($root, $decoPage, $decoStart);
        $this->placeOutOfFlow($root);
        $this->clampOverflow();
        $this->bandRowGroups();

        // The ceiling is a security control, so it says no rather than
        // handing back a document that is quietly missing its tail. Returning
        // the truncated pages is the failure mode that lets a short invoice
        // out of the door with an HTTP 200 behind it.
        if ($this->truncated) {
            throw PageLimitExceededException::at($this->limits->maxPages, $this->notes);
        }

        return $this->pages;
    }

    /**
     * A row group's background and border, once per page, behind its rows.
     *
     * `collectRows()` flattens a `<tbody>`, `<thead>` or `<tfoot>` away and
     * keeps only its rows, so the group has no box for anything to paint and
     * `<tbody style="background: #eee">` reached nothing at all: defect DK, and
     * on an ordinary invoice it takes the header row's reversed-out text with
     * it, painting white on white paper.
     *
     * **The band is read off the rows rather than sliced out of a box**, which
     * is what makes a group crossing a fold work without the group being in the
     * tree: whatever rows landed on this page are what this page's band spans.
     * A group repeated as a running header lands on several pages and gets a
     * band on each, for the same reason and with no extra bookkeeping.
     *
     * **The background goes down row by row and the border once around them
     * all.** A group's fill covers the cells and not the border spacing
     * between them, exactly as a row's does, so it borrows each row's own
     * bands: on `QN-rowgroup-paint.html` Chrome fills 5,553 pixels of a
     * `border-spacing: 6pt` group where one solid rectangle over the same
     * extent is 6,478. The border is the group's own rim and there is only one
     * of it.
     *
     * Each fragment goes in **before** the row it is behind, because the page's
     * list is painted in order and CSS 2.1 §17.5.1 puts the group under its
     * rows and over the table.
     */
    private function bandRowGroups(): void
    {
        foreach ($this->pages as $page => $fragments) {
            /**
             * @var array<int,array{at:int,fills:list<Fragment>,rim:Node|null,
             *      x:float,r:float,y:float,b:float,
             *      effects:list<array{0:Node,1:float,2:float,3:float,4:float}>}> $groups
             */
            $groups = [];

            foreach ($fragments as $i => $f) {
                $n = $f->node;

                if ($n->rowGroupBox === null || $n->rowGroup === null || $n->display !== 'table-row') {
                    continue;
                }

                $group = $n->rowGroupBox;
                $id    = $n->rowGroup;

                if (!isset($groups[$id])) {
                    $rim = null;

                    if ($group->border !== null) {
                        $rim             = clone $group;
                        $rim->background = null;
                        // A row group has no box in this tree, so nothing
                        // gave it a stacking path. It paints where its rows
                        // paint, and the splice below is what puts it under
                        // them.
                        $rim->stackPath  = $n->stackPath;
                    }

                    $groups[$id] = [
                        'at' => $i, 'fills' => [], 'rim' => $rim,
                        'x'  => $f->x, 'r' => $f->x + $f->w, 'y' => $f->y, 'b' => $f->y + $f->h,
                        // The band is the rows' own group, so it is inside
                        // whatever they are inside: a table under an
                        // `opacity: 0.5` panel fades its group fill with its
                        // rows rather than painting it at full strength.
                        'effects' => $f->effects,
                    ];
                }

                $groups[$id]['x'] = min($groups[$id]['x'], $f->x);
                $groups[$id]['r'] = max($groups[$id]['r'], $f->x + $f->w);
                $groups[$id]['y'] = min($groups[$id]['y'], $f->y);
                $groups[$id]['b'] = max($groups[$id]['b'], $f->y + $f->h);

                if ($group->background === null && $group->backgroundLayers === []) {
                    continue;
                }

                $fill                  = clone $group;
                $fill->border          = null;
                $fill->backgroundBands = $n->backgroundBands;
                $fill->stackPath       = $n->stackPath;

                $groups[$id]['fills'][] = new Fragment($fill, $f->x, $f->y, $f->w, $f->h, effects: $f->effects);
            }

            if ($groups === []) {
                continue;
            }

            // Back to front, so an earlier insertion does not move the index a
            // later one was recorded against.
            uasort($groups, static fn(array $a, array $b): int => $b['at'] <=> $a['at']);

            foreach ($groups as $group) {
                $block = $group['fills'];

                if ($group['rim'] !== null) {
                    $block[] = new Fragment(
                        $group['rim'],
                        $group['x'],
                        $group['y'],
                        max(0.0, $group['r'] - $group['x']),
                        max(0.0, $group['b'] - $group['y']),
                        effects: $group['effects'],
                    );
                }

                if ($block !== []) {
                    array_splice($fragments, $group['at'], 0, $block);
                }
            }

            $this->pages[$page] = $fragments;
        }
    }

    /**
     * The root box's own background and border, on every page it reaches.
     *
     * {@see flowChildren} flows the root's children and the root itself never
     * becomes a fragment, so nothing painted its own two decorations at all
     * (defect DH). It is `splitContainer()`'s decoration bookkeeping with the
     * walk taken out: the flow is already done, so this only closes what the
     * flow left open, page by page, and the slice flags make the border break
     * where every other box's does.
     *
     * The cursor is left where the flow left it. A root taller than its own
     * content still ends its decoration on the page the content ended on,
     * rather than buying pages nothing is painted on.
     */
    private function closeRootDecoration(Node $root, int $decoPage, float $decoStart): void
    {
        $end = $this->cursor + $root->edge('bottom');

        // A declared height the content does not fill still paints, up to the
        // bottom of the page the flow stopped on.
        $travelled = ($this->page - $decoPage) * $this->pageHeight + ($end - $decoStart)
            - $this->reservedBetween($decoPage, $this->page);

        if ($root->layoutHeight - $travelled > 0.01) {
            $end = min(
                $end + ($root->layoutHeight - $travelled),
                $this->pageHeight - $this->reserveOn($this->page),
            );
        }

        $sliceTop = 0.0;

        for ($p = $decoPage; $p < $this->page; $p++) {
            $this->closeDecoration($root, $root->x, $p, $decoStart, false, $sliceTop);
            $sliceTop  += max(0.0, $this->pageHeight - $this->reserveOn($p) - $decoStart);
            $decoStart = 0.0;
        }

        $cursor       = $this->cursor;
        $this->cursor = $end;
        $this->closeDecoration($root, $root->x, $this->page, $decoStart, true, $sliceTop);
        $this->cursor = $cursor;
    }

    /**
     * A box taller than the space left on its page (most often a cell with
     * a rowspan covering rows that moved on) would otherwise paint straight
     * off the bottom. Clip it to the page and continue the remainder below
     * whatever repeats at the top of the next one.
     */
    private function clampOverflow(): void
    {
        // Repeat until nothing moves: relocating a fragment can push it onto a
        // page that then overflows in turn.
        for ($round = 0; $round < 32; $round++) {
            $this->deadline->check('overflow clamping');
            $moved = false;

            foreach (array_keys($this->pages) as $pi) {
                foreach ($this->pages[$pi] as $k => $f) {
                    // Starts below the fold: it belongs on a later page. Move
                    // it rather than dropping it: deleting it loses content.
                    if ($f->y >= $this->floorOn($pi) - 0.01) {
                        // Jump straight to the page the coordinate falls on.
                        // Walking down one page per round turns a box parked
                        // thousands of points below into thousands of rounds.
                        //
                        // The strip is what the coordinate is cut by, so the
                        // step is a whole `$pageHeight` even where the page's own
                        // content box is shorter. A box parked in the band at the
                        // foot of a shortened page is inside the strip's own page
                        // and steps to zero, so it takes at least one: that band
                        // is a hole in the flow space and there is nowhere on
                        // this page to put it.
                        $steps  = max(1, (int) floor($f->y / $this->pageHeight));
                        $target = $pi + $steps;

                        $carriesInk = $f->lines !== [] || $f->node->background !== null
                            || $f->node->border !== null || $f->node->image !== null
                            || $f->node->svg !== null;

                        if ($target >= $this->limits->maxPages || !$carriesInk) {
                            unset($this->pages[$pi][$k]);

                            if ($carriesInk) {
                                $this->truncated = true;
                                $this->notes[]   = sprintf(
                                    'dropped a fragment %.0fpt below the flow: past the %d-page ceiling',
                                    $f->y,
                                    $this->limits->maxPages,
                                );
                            }

                            $moved = true;
                            continue;
                        }

                        for ($q = $pi + 1; $q <= $target; $q++) {
                            $this->pages[$q] ??= [];
                        }

                        $f->y -= $steps * $this->pageHeight;
                        $f->y = max($f->y, $this->contentTop($this->pages[$target]));
                        unset($this->pages[$pi][$k]);
                        $this->pages[$target][] = $f;
                        $moved                  = true;

                        continue;
                    }

                    $overflow = ($f->y + $f->h) - $this->floorOn($pi);

                    if ($overflow <= 0.01) {
                        continue;
                    }

                    // Text can be split at a line boundary; a plain box is just
                    // clipped and continued.
                    if ($f->lines !== []) {
                        $kept  = [];
                        $spill = [];
                        $used  = 0.0;

                        foreach ($f->lines as $lb) {
                            if ($f->y + $used + $lb->height <= $this->floorOn($pi) + 0.01 && $spill === []) {
                                $kept[] = $lb;
                                $used   += $lb->height;
                            } else {
                                $spill[] = $lb;
                            }
                        }

                        // Every line fits even though the box does not: a
                        // stretched flex item is taller than its own content.
                        // Fall through and clip the box instead.
                        // A single line taller than the whole page can never
                        // be moved into one. Keep it here and clip, or it
                        // walks forward a page at a time forever.
                        //
                        // **It has to actually BE taller than a page**, and
                        // until round 91 the test was only that nothing fit
                        // where it stood. A 9pt line in a box the fold cuts 1pt
                        // from its top was kept, clipped to that 1pt, painted
                        // in full below the page's own floor, and then painted
                        // AGAIN by `continueTallLines()` on the page it
                        // continued onto: one word on two pages. Chrome moves
                        // it whole, which is what spilling it does.
                        // `ZB-abspos-split-page.html` with a 260pt lead is the
                        // case, and it is the second half of defect IV.
                        if ($kept === [] && $spill !== [] && $spill[0]->height > $this->pageHeight) {
                            $kept[] = array_shift($spill);
                            $used   = $kept[0]->height;
                        }

                        if ($spill !== []) {
                            $target = $pi + 1;

                            // Spilling lines forward is the one place that
                            // creates a page without going through newPage(),
                            // so it has to honor the ceiling itself.
                            if ($target >= $this->limits->maxPages) {
                                $this->truncated = true;
                                $this->notes[]   = sprintf(
                                    'dropped %d line boxes: past the %d-page ceiling',
                                    count($spill),
                                    $this->limits->maxPages,
                                );

                                $f->lines       = $kept;
                                $f->h           = $used;
                                $f->splitsAfter = true;
                                $moved          = true;

                                continue;
                            }

                            $this->pages[$target] ??= [];
                            $resume               = $this->contentTop($this->pages[$target]);

                            $spillHeight = 0.0;

                            foreach ($spill as $lb) {
                                $spillHeight += $lb->height;
                            }

                            $f->lines       = $kept;
                            $f->h           = $used;
                            $f->splitsAfter = true;

                            $this->pages[$target][] = new Fragment(
                                $f->node,
                                $f->x,
                                $resume,
                                $f->w,
                                $spillHeight,
                                $spill,
                                true,
                                false,
                                effects: self::shiftEffects($f->effects, $this->pageHeight),
                            );
                            $moved                  = true;
                            continue;
                        }
                    }

                    $f->h           = max(0.0, $this->floorOn($pi) - $f->y);
                    $f->splitsAfter = true;

                    if ($f->node->background === null && $f->node->border === null) {
                        continue;
                    }

                    $target = $pi + 1;

                    /*
                     * The continuation needs a page to land on, and a box that
                     * reaches the fold on the **last** page has none: skipping
                     * it there paints the top of the box and loses the rest.
                     * That is what an out-of-flow panel with a background did,
                     * and Chrome paints both halves of it. Creating the page is
                     * bounded by the same ceiling every other page creation is.
                     */
                    if (!isset($this->pages[$target])) {
                        if ($target >= $this->limits->maxPages) {
                            continue;
                        }

                        $this->pages[$target] = [];
                    }

                    /*
                     * The continuation carries the WHOLE remainder and the round
                     * loop above cuts it again on the page it lands on, which is
                     * what continues a box over as many pages as it needs.
                     * Capping it at one page here instead painted 840pt of a
                     * 1,500pt out-of-flow box and dropped the other 660 in
                     * silence, on a document with no `@page` block at all, where
                     * Chrome paints 420, 420, 420 and 240 (defect HZ).
                     */
                    $resume = $this->contentTop($this->pages[$target]);
                    array_unshift(
                        $this->pages[$target],
                        new Fragment(
                            $f->node, $f->x, $resume,
                            $f->w, $overflow,
                            [], true, false,
                            effects: self::shiftEffects($f->effects, $this->pageHeight),
                        ),
                    );

                    $moved = true;
                }
            }

            foreach ($this->pages as $pi => $page) {
                $this->pages[$pi] = array_values($page);
            }

            ksort($this->pages);

            if (!$moved) {
                break;
            }
        }

        $this->continueTallLines();

        // Backstop. Everything above tries to move or split content onto the
        // page where it belongs; this guarantees the outcome regardless of
        // whether it succeeded, because a fragment painting past the page edge
        // is never acceptable output.
        foreach ($this->pages as $pi => $page) {
            foreach ($page as $k => $f) {
                if ($f->y >= $this->floorOn($pi) - 0.01) {
                    unset($this->pages[$pi][$k]);
                    continue;
                }

                $f->h = min($f->h, max(0.0, $this->floorOn($pi) - $f->y));
            }

            $this->pages[$pi] = array_values($this->pages[$pi]);
        }
    }

    /**
     * Continue a line taller than its page onto the pages it reaches.
     *
     * emitSlices() already does this for a block: a leaf taller than a page is
     * drawn as a run of page-sized slices. A box sitting on a line had no
     * equivalent, because the line is the fragmentation unit and the box on it
     * is atomic, so everything past the fold was clipped away instead of
     * continued. Re-emit the fragment on each page the line reaches with the
     * origin moved up by one page height, so each page carries the part of the
     * line that falls on it and the page edge does the cutting. Chrome
     * fragments a line the same way, down to drawing a glyph that straddles
     * the fold as two halves.
     */
    private function continueTallLines(): void
    {
        $work = [];

        foreach ($this->pages as $pi => $page) {
            foreach ($page as $f) {
                if ($f->lines === []) {
                    continue;
                }

                $bottom = $f->y;

                foreach ($f->lines as $lb) {
                    $bottom += $lb->height;
                }

                if ($bottom > $this->pageHeight + 0.01) {
                    $work[] = [$pi, $f, $bottom];
                }
            }
        }

        foreach ($work as [$pi, $f, $bottom]) {
            $this->deadline->check('tall line continuation');

            // How many pages after this one the line reaches, counted by what
            // each page can SHOW rather than by the strip's pitch: a page a
            // qualified `@page` block shortened shows less of the box, so the
            // same box takes more pages. With nothing held back anywhere this is
            // `ceil(($bottom - 0.01) / $pageHeight) - 1` exactly, which is what
            // it read before.
            $spans = 0;
            $shown = max(0.0, $this->floorOn($pi) - $f->y);

            while ($shown < $bottom - $f->y - 0.01 && $pi + $spans + 1 < $this->limits->maxPages) {
                $spans++;
                $shown += $this->floorOn($pi + $spans);
            }

            for ($k = 1; $k <= $spans; $k++) {
                // A page reached without going through newPage(), so the
                // ceiling is this method's to honor. Cases 22 to 25 exist for
                // the two other ways out of clampOverflow(); this is a third.
                if ($pi + $k >= $this->limits->maxPages) {
                    break;
                }

                // The distance from this page's content top to the k-th page's,
                // which is the strip's pitch less what the pages between them
                // held back. Using the pitch alone put the slice a whole band too
                // low and lost 75pt of a 501pt box where Chrome loses none
                // (defect HY, in the line path).
                $shift = $k * $this->pageHeight - $this->reservedBetween($pi, $pi + $k);
                $lines = $this->sliceLines($f, $shift, $this->floorOn($pi + $k));

                if (!self::carriesInk($lines)) {
                    continue;
                }

                $clip  = self::shiftClip($f->clip, $shift);
                $stack = self::shiftClipStack($f->clipStack, $shift);

                // A slice cannot be moved down to clear a repeated header the
                // way ordinary content is, because it continues a box whose
                // top is on an earlier page. Clip it instead, or a tall image
                // in a table cell paints straight over the header above it.
                $top = $this->contentTop($this->pages[$pi + $k] ?? []);

                if ($top > 0.01) {
                    [$left, $right] = self::lineExtent($f, $lines);
                    $band           = [$left, $top, $right - $left, $this->floorOn($pi + $k) - $top, [0.0, 0.0, 0.0, 0.0]];

                    $clip = self::intersectClip($clip, $band);

                    // Innermost, which is where this slice has always clipped:
                    // the band is about the page rather than about an ancestor
                    // and nothing measured says which side of a matrix it wants.
                    $band[]  = count($f->effects);
                    $stack[] = $band;
                }

                // An `overflow: hidden` ancestor's clip travels up with the
                // slice, so a box whose clip ended on an earlier page has
                // nothing left to show here. Emitting it anyway would add a
                // page carrying no ink.
                if (!$this->clipAdmitsInk($clip)) {
                    continue;
                }

                // Page indexes have to stay contiguous: the painter walks them
                // in order and numbers the PDF's pages from the key. A slice
                // that skipped a page nothing showed on would leave a hole.
                for ($q = $pi + 1; $q <= $pi + $k; $q++) {
                    $this->pages[$q] ??= [];
                }

                $this->pages[$pi + $k][] = new Fragment(
                    $f->node,
                    $f->x,
                    $f->y - $shift,
                    $f->w,
                    $f->h,
                    $lines,
                    true,
                    $f->splitsAfter,
                    $clip,
                    clipStack: $stack,
                    effects: self::shiftEffects($f->effects, $shift),
                    linesOnly: true,
                );
            }
        }

        ksort($this->pages);
    }

    /**
     * The line boxes a continuation slice paints on the k-th page.
     *
     * Everything the page can show is kept, and what is dropped is only what
     * could not show here at all: a word nowhere near this page must not turn
     * up in its extracted text, and a link on it must not leave a clickable
     * rectangle behind. The original fragment is never cut, so nothing this
     * drops is content the document loses. The list keeps its length and its
     * order, because the painter walks it with a running cursor and removing a
     * line would move every line after it.
     *
     * $shift is the distance from this fragment's own page to the one the slice
     * lands on and $height is what that page can show, so a shortened page asks
     * for a nearer window rather than a page-pitch one.
     *
     * @return LineBox[]
     */
    private function sliceLines(Fragment $f, float $shift, float $height): array
    {
        $out    = [];
        $top    = $shift;
        $bottom = $top + $height;
        $cursor = $f->y;

        foreach ($f->lines as $lb) {
            $slice        = clone $lb;
            $slice->items = [];
            $baseline     = $cursor + $lb->baseline;

            foreach ($lb->items as $item) {
                $seat = $baseline - $item->baselineShift;

                if ($item->isAtomic()) {
                    $box      = $item->run->box;
                    $itemTop  = $seat - $box->baselineOffset() + $box->margin['top'];
                    $itemFoot = $itemTop + $box->layoutHeight;
                } else {
                    // No face reaches an em above its own baseline or half of
                    // one below it, so two ems and one leave the cut safe
                    // whatever the run is set in.
                    $itemTop  = $seat - 2.0 * $item->run->fontSize;
                    $itemFoot = $seat + $item->run->fontSize;
                }

                if ($itemFoot <= $top + 0.01 || $itemTop >= $bottom - 0.01) {
                    continue;
                }

                $slice->items[] = $item;
            }

            $out[]  = $slice;
            $cursor += $lb->height;
        }

        return $out;
    }

    /** @param LineBox[] $lines */
    private static function carriesInk(array $lines): bool
    {
        foreach ($lines as $lb) {
            if ($lb->items !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array{0:float,1:float,2:float,3:float,4:array<int,float>}|null $clip
     * @return array{0:float,1:float,2:float,3:float,4:array<int,float>}|null
     */
    private static function shiftClip(?array $clip, float $dy): ?array
    {
        if ($clip === null) {
            return null;
        }

        $clip[1] -= $dy;

        return $clip;
    }

    /**
     * The same shift for the clips behind that rectangle, which the painter
     * reads separately to tell an outer clip from an inner one.
     *
     * @param  list<array{0:float,1:float,2:float,3:float,4:array<int,float>,5:int}> $clips
     * @return list<array{0:float,1:float,2:float,3:float,4:array<int,float>,5:int}>
     */
    private static function shiftClipStack(array $clips, float $dy): array
    {
        foreach ($clips as $i => $clip) {
            $clips[$i][1] = $clip[1] - $dy;
        }

        return $clips;
    }

    /**
     * The same shift for the effect chain a slice inherits, since an ancestor
     * that reaches this page reaches it that much further up.
     *
     * @param  list<array{0:Node,1:float,2:float,3:float,4:float}> $effects
     * @return list<array{0:Node,1:float,2:float,3:float,4:float}>
     */
    private static function shiftEffects(array $effects, float $dy): array
    {
        foreach ($effects as $i => $effect) {
            $effects[$i][2] = $effect[2] - $dy;
        }

        return $effects;
    }

    /** Whether a clip leaves any of the page to paint on. */
    private function clipAdmitsInk(?array $clip): bool
    {
        if ($clip === null) {
            return true;
        }

        return $clip[2] > 0.01
            && $clip[3] > 0.01
            && $clip[1] < $this->pageHeight - 0.01
            && $clip[1] + $clip[3] > 0.01;
    }

    /**
     * The horizontal span these lines can paint into: the fragment's own box,
     * widened by anything hanging out of it.
     *
     * @param  LineBox[]              $lines
     * @return array{0:float,1:float} left, right
     */
    private static function lineExtent(Fragment $f, array $lines): array
    {
        $left  = $f->x;
        $right = $f->x + $f->w;

        foreach ($lines as $lb) {
            foreach ($lb->items as $item) {
                $width = $item->isAtomic() ? $item->run->box->outerWidth() : $item->width;
                $left  = min($left, $f->x + $item->x);
                $right = max($right, $f->x + $item->x + $width);
            }
        }

        return [$left, $right];
    }

    /**
     * @param  array{0:float,1:float,2:float,3:float,4:array<int,float>}|null $clip
     * @param  array{0:float,1:float,2:float,3:float,4:array<int,float>}      $band
     * @return array{0:float,1:float,2:float,3:float,4:array<int,float>}
     */
    private static function intersectClip(?array $clip, array $band): array
    {
        if ($clip === null) {
            return $band;
        }

        $x      = max($clip[0], $band[0]);
        $y      = max($clip[1], $band[1]);
        $right  = min($clip[0] + $clip[2], $band[0] + $band[2]);
        $bottom = min($clip[1] + $clip[3], $band[1] + $band[3]);

        return [$x, $y, max(0.0, $right - $x), max(0.0, $bottom - $y), $clip[4]];
    }

    /** Where ordinary content starts on a page, below anything repeated. */
    private function contentTop(array $page): float
    {
        $top = 0.0;

        foreach ($page as $f) {
            if ($f->node->repeatOnBreak) {
                $top = max($top, $f->y + $f->h);
            }
        }

        return $top;
    }

    /**
     * Out-of-flow boxes are not part of the flow, so they cannot be paginated
     * by it. Note them and the positioned ancestor they belong to, then place
     * them once the flow has settled and we know where that ancestor landed.
     *
     * @param list<Node> $chain the ancestors between the root and $n that wrap
     *                          their subtree in an effect, which travels with
     *                          the box because the walk that would have
     *                          carried it is over before the box is placed
     */
    private function collectOutOfFlow(Node $n, ?Node $ancestor, array $chain = []): void
    {
        $nextAncestor = $n->isPositioned() ? $n : $ancestor;

        if ($n->wrapsSubtree()) {
            $chain[] = $n;
        }

        foreach ($n->children as $child) {
            if ($child->isOutOfFlow()) {
                $this->deferred[] = [
                    'node'     => $child,
                    'ancestor' => $child->position === 'fixed' ? null : $nextAncestor,
                    'parent'   => $n,
                    'chain'    => $chain,
                ];
                // Keep descending: an out-of-flow box can itself contain one,
                // and nothing else will ever visit that subtree.
                $this->collectOutOfFlow($child, $child, $chain);
                continue;
            }

            $this->collectOutOfFlow($child, $nextAncestor, $chain);
        }
    }

    /** A `fixed` box repeats on every page; an `absolute` one follows its ancestor. */
    private function placeOutOfFlow(Node $root): void
    {
        $flowSpots = $this->firstFragmentSpots();

        foreach ($this->deferred as ['node' => $node, 'ancestor' => $ancestor, 'parent' => $parent, 'chain' => $chain]) {
            if ($node->position === 'fixed') {
                foreach (array_keys($this->pages) as $pi) {
                    $this->page    = $pi;
                    $this->cursor  = $node->y;
                    $this->effects = $this->effectsOf($chain, $pi);
                    $this->emitWhole($node, $node->x, $node->y);
                    $this->effects = [];
                }
                continue;
            }

            $anchor = $ancestor !== null
                ? ($this->placed[spl_object_id($ancestor)]
                    ?? $this->positionedStarts[spl_object_id($ancestor)]
                    ?? null)
                : null;

            $spot = $anchor === null && $parent !== null
                ? ($this->placed[spl_object_id($parent)] ?? $flowSpots[spl_object_id($parent)] ?? null)
                : null;

            if ($anchor === null) {
                /*
                 * No positioned ancestor, so the containing block is the page
                 * box and a declared offset is measured from the first page's
                 * own corner. That is what layout assumed and it is what
                 * Chrome prints: `WN-break-abs-offsets.html` puts a box with
                 * `top: 36pt` on page 1 even though its parent is on page 3.
                 *
                 * An offset that is auto is not that. It is the box's static
                 * position, which sits in the parent, so it has to be taken
                 * off the parent's own fragment: a forced break moves the
                 * parent and leaves the flow coordinate layout recorded
                 * pointing at the page the parent left. `WL` is that case and
                 * bullet 107 is where it was found. Each axis is asked on its
                 * own, because `left: 10pt` with an auto `top` is static in
                 * one axis and positioned in the other.
                 */
                $onParent     = $spot !== null && $parent !== null;
                $blockStatic  = $onParent && $this->isAutoInset($node->top) && $this->isAutoInset($node->bottom);
                $inlineStatic = $onParent && $this->isAutoInset($node->left) && $this->isAutoInset($node->right);

                $page = $blockStatic && $spot !== null ? $spot['page'] : 0;
                $y    = $blockStatic && $spot !== null && $parent !== null
                    ? $spot['y'] + ($node->y - $parent->y)
                    : $node->y;
                $x = $inlineStatic && $spot !== null && $parent !== null
                    ? $spot['x'] + ($node->x - $parent->x)
                    : $node->x;
            } else {
                $page = $anchor['page'];
                $y    = $anchor['y'] + ($node->y - ($ancestor?->y ?? 0.0));
                $x    = $anchor['x'] + ($node->x - ($ancestor?->x ?? 0.0));
            }

            if (!isset($this->pages[$page])) {
                continue;
            }

            $this->page    = $page;
            $this->cursor  = $y;
            $this->effects = $this->effectsOf($chain, $page);
            $this->emitWhole($node, $x, $y);
            $this->effects = [];
        }
    }

    /** Whether an inset is the `auto` that leaves a box at its static position. */
    private function isAutoInset(float|string|null $value): bool
    {
        return $value === null || $value === 'auto';
    }

    /**
     * Where each box's first fragment landed, page and page-local corner.
     *
     * `$placed` only carries boxes emitted whole, which is every box that fit
     * on its page and none that broke across one, so it cannot answer where a
     * broken parent starts. This reads the answer back off the pages the flow
     * has already settled into, so a parent that fragments is as usable an
     * anchor as one that did not.
     *
     * @return array<int,array{page:int,y:float,x:float}>
     */
    private function firstFragmentSpots(): array
    {
        $spots = [];

        foreach ($this->pages as $pi => $page) {
            foreach ($page as $f) {
                $spots[spl_object_id($f->node)] ??= ['page' => $pi, 'y' => $f->y, 'x' => $f->x];
            }
        }

        return $spots;
    }

    /**
     * The subtree effects an out-of-flow box sits inside, at the coordinates
     * its ancestors were painted at on this page.
     *
     * An out-of-flow box is placed after the flow has settled, so the walk
     * that would have carried its ancestors' `opacity`, `mix-blend-mode`,
     * `transform` and `mask` down to it is long over: without this it is
     * painted at full strength inside a faded or masked parent, which is
     * defect DW one case further along. The rects come from where each
     * ancestor was actually emitted, and an ancestor placed on an earlier page
     * is moved up a page height per fold exactly as {@see shiftEffects()}
     * moves one that is carried down the flow.
     *
     * @param  list<Node> $chain
     * @return list<array{0:Node,1:float,2:float,3:float,4:float}>
     */
    private function effectsOf(array $chain, int $page): array
    {
        $effects = [];

        foreach ($chain as $ancestor) {
            $spot = $this->effectSpots[spl_object_id($ancestor)] ?? $this->placed[spl_object_id($ancestor)] ?? null;

            if ($spot === null) {
                continue;
            }

            $effects[] = [
                $ancestor,
                $spot['x'],
                // Both coordinates are page-local, and two pages are one page
                // height apart in that space only where neither held a band
                // back at its foot. {@see $pageReserve}.
                $spot['y'] - ($page - $spot['page']) * $this->pageHeight
                    + $this->reservedBetween($spot['page'], $page),
                $ancestor->layoutWidth,
                $ancestor->layoutHeight,
            ];
        }

        return $effects;
    }

    private function newPage(): void
    {
        // $page is a zero-based index, so the page about to be created is
        // $page + 1. Guarding on $page alone let a ceiling of N produce N + 1.
        if ($this->page + 1 >= $this->limits->maxPages) {
            if (!$this->exhausted) {
                $this->exhausted = true;
                $this->truncated = true;
                $this->notes[]   = sprintf('stopped at the %d-page ceiling', $this->limits->maxPages);
            }

            $this->cursor    = 0.0;
            $this->pageFloor = 0.0;

            return;
        }

        $this->page++;

        if (!isset($this->pages[$this->page])) {
            $this->pages[$this->page] = [];
        }

        // A page opened inside a `box-decoration-break: clone` box starts below
        // that box's own top border and padding, because the fragment about to
        // begin wears them again. {@see $cloneReserve} is the same thing at the
        // other end of the page.
        $this->cursor    = $this->cloneTop;
        $this->pageFloor = $this->cloneTop;
    }

    private function emit(Fragment $f): void
    {
        $f->clip                    = Fragment::intersectClips($this->clips);
        $f->clipStack               = $this->clips;
        $f->effects                 = $this->effects;
        $this->pages[$this->page][] = $f;
    }

    /**
     * Chrome clips per axis, so an axis whose computed `overflow` is `visible`
     * has to stay unbounded rather than be cut at the border box. The sentinel
     * is the same ±1e9 {@see currentClip} already intersects against, so a
     * one-axis clip costs nothing there.
     *
     * `ZZ-overflow-clip-axes.html` `w4`: a 100x80px child of a 60x40px box with
     * `overflow-x: clip` is **45 x 60** in Chrome, cut in the inline axis and
     * whole in the block one, where one boolean cut it in both or in neither.
     * The radius goes with a clip that is bounded in both axes and not with a
     * one-axis band, which has no corner to round.
     */
    private function pushClip(Node $n, float $x, float $y, float $w, float $h): bool
    {
        if ($n->overflow !== 'hidden') {
            return false;
        }

        $clipsX = $n->overflowX !== 'visible';
        $clipsY = $n->overflowY !== 'visible';

        $depth = count($this->effects);

        // The clip edge is the padding box moved out by any
        // `overflow-clip-margin`, and `BoxPainter::pushOverflowClip()` reads the
        // same calls. This one cuts the descendants; the box's own content is
        // cut where it is painted and its decoration is left alone. Defects GN
        // and GM.
        [$left, $top, $right, $bottom] = [
            $n->overflowClipInset('left'),
            $n->overflowClipInset('top'),
            $n->overflowClipInset('right'),
            $n->overflowClipInset('bottom'),
        ];

        $this->clips[] = $clipsX && $clipsY
            ? [
                $x + $left,
                $y + $top,
                $w - $left - $right,
                $h - $top - $bottom,
                $n->overflowClipRadii($w, $h),
                $depth,
            ]
            : [
                $clipsX ? $x + $left : -1e9,
                $clipsY ? $y + $top : -1e9,
                $clipsX ? $w - $left - $right : 2e9,
                $clipsY ? $h - $top - $bottom : 2e9,
                [0.0, 0.0, 0.0, 0.0],
                $depth,
            ];

        return true;
    }

    /**
     * Note that everything emitted from here down is inside this box's
     * `opacity`, `mix-blend-mode` or `transform`.
     *
     * `BoxPainter::paintSubtree()` gets this for nothing, because it holds the
     * graphics state open around its own recursion. The paginated path emits a
     * box's decoration, its lines and every descendant as fragments of their
     * own and paints them one at a time, so an ancestor's effect reached
     * nothing but the box that declared it: an `opacity: 0.5` panel faded and
     * its words stayed at full strength (defect DW).
     */
    private function pushEffects(Node $n, float $x, float $y, float $w, float $h): bool
    {
        // Every path that walks a box passes through here, whether the box
        // carries an effect or not, so this is the one place that sees a
        // positioned container start no matter which layout owns it.
        // {@see $positionedStarts} for why its fragments cannot be read back.
        if ($n->isPositioned()) {
            $this->positionedStarts[spl_object_id($n)] ??= ['page' => $this->page, 'x' => $x, 'y' => $y];
        }

        if (!$n->wrapsSubtree()) {
            return false;
        }

        $this->effects[] = [$n, $x, $y, $w, $h];

        // Where this box was, for an out-of-flow descendant placed long after
        // the walk has left it. `$placed` cannot answer that: it records the
        // boxes emitted whole, and a container that the fold splits is emitted
        // a fragment at a time and is never in it.
        $this->effectSpots[spl_object_id($n)] = ['page' => $this->page, 'x' => $x, 'y' => $y];

        return true;
    }

    /**
     * Space left on the current page. Never negative: a cursor that has run
     * past the bottom would otherwise make every "does it fit?" test false and
     * spin page-advancing loops until they hit their guard.
     */
    private function remaining(): float
    {
        return max(
            0.0,
            $this->pageHeight
                - $this->reserveOn($this->page)
                - $this->footerReserve
                - $this->cloneReserve
                - $this->cursor,
        );
    }

    /**
     * Flow a table whose `<tfoot>` repeats: the footer's height comes off
     * every page before the body is placed, and the footer itself is emitted
     * afterwards, once per page the table reached.
     *
     * Reserving in `remaining()` is what makes every decision below respect
     * the band, because `placeNode`, `advance` and `splitText` all ask that
     * one question rather than reading the page height themselves.
     *
     * @param Node[] $feet
     */
    private function flowWithFooter(Node $table, array $feet, float $x, callable $body): void
    {
        $height = 0.0;

        foreach ($feet as $foot) {
            $height += $foot->layoutHeight;
        }

        // A footer over a quarter of the page is refused, exactly as a header
        // over a quarter is by {@see replayRepeaters}, and Chrome draws the
        // line in the same place at both ends of the table: on a 300pt page it
        // repeats a 75.000pt footer and places a 75.750pt one once, at the end,
        // like any row. Reserving a band that big on every page turns a table
        // needing two pages into one needing many.
        if ($height <= 0.0 || $height > $this->pageHeight / 4.0 + 1e-6) {
            $body($table->children);

            return;
        }

        $first = $this->page;

        // What each page held before the body was placed, so the footer can be
        // put on the pages the body actually reached and on no others. A
        // footer's reason to be on a page is the row above it, which is the
        // rule defect BQ writes for a header at the other end of the table:
        // `R6-fold-tfoot.html` painted a footer at the bottom of the page its
        // row could not fit on, and then again on the page the row moved to.
        $held = [];

        foreach (array_keys($this->pages) as $p) {
            $held[$p] = count($this->pages[$p]);
        }

        $this->footerReserve += $height;

        $body(array_values(array_filter(
            $table->children,
            static fn(Node $child): bool => !$child->repeatAtBottom,
        )));

        $this->footerReserve -= $height;
        $last = $this->page;

        for ($page = $first; $page <= $last; $page++) {
            if (count($this->pages[$page] ?? []) <= ($held[$page] ?? 0)) {
                continue;   // the body put nothing here
            }

            // The footer sits at the bottom of the table's fragment on this
            // page: the reserved band on a page the table fills, and directly
            // under the last row on the page it ends on. Chrome puts
            // `T1-tfoot-multipage.html`'s footer at 285.000 on the first page
            // and at 165.000 on the second, where this pinned both to 285.000.
            $foot = $this->pageHeight - $this->reserveOn($page) - $height;

            $top = $page === $last
                ? min($this->cursor, $foot)
                : $foot;

            foreach ($feet as $foot) {
                $this->emitOnPage($foot, $page, $x + ($foot->x - $table->x), $top);
                $top += $foot->layoutHeight;
            }

            if ($page === $last) {
                $this->cursor = $top;
            }
        }
    }

    /**
     * Flow a container's children down the page, breaking as needed.
     * $originY is the container's own y in the un-fragmented layout, used to
     * convert absolute child offsets into offsets relative to the container.
     */
    private function flowChildren(Node $container, float $x, float $containerTopOnPage): void
    {
        // A list of RUNS, not a list of rows: a table nested in a cell of
        // another repeats both headers on a continuation page and each run is
        // judged against a quarter of the page on its own (defect BX).
        $run = array_values(
            array_filter(
                $container->children,
                fn(Node $c) => $c->repeatOnBreak,
            ),
        );

        $repeaters = $run === [] ? [] : [$run];

        $startPage = $this->page;

        // Gaps come from the positions layout actually produced, not from
        // re-adding margins: after collapsing (and with table border-spacing)
        // those two no longer agree.
        $prevBottom = null;

        foreach ($this->inFlowOrder($container) as $child) {
            if ($child->isOutOfFlow()) {
                continue;
            }

            $relTop = $child->y - $container->y;
            $childX = $x + ($child->x - $container->x);

            // A gap between two siblings is a margin and truncates at a fold;
            // the first child's offset is the container's own padding and does
            // not. {@see settleGap}.
            if ($prevBottom === null) {
                $this->cursor += $relTop;
                $this->settleCursor($childX, $repeaters);
            } else {
                $this->cursor += max(0.0, $relTop - $prevBottom);
                $this->settleGap($repeaters);
            }

            $entersType = $this->startsAPageType($child);

            if ($child->breakBefore === 'page' || $entersType) {
                $this->forcePageBreak($childX, $repeaters, $container);
            }

            if ($entersType) {
                $this->enterPageType($child->pageName);
            }

            $this->placeNode($child, $childX, $repeaters, $startPage, $container);

            if ($child->breakAfter === 'page') {
                $this->forcePageBreak($childX, $repeaters, $container);
            }

            $prevBottom = $relTop + $child->layoutHeight;
        }
    }

    /**
     * The children of a container in the order they occupy the block axis.
     *
     * Both walkers below advance the cursor by the distance from the previous
     * child's bottom to the next child's top, which needs the children sorted
     * down the page. Document order is that order everywhere except a flex
     * container, where `order` and `column-reverse` both put a later child
     * higher up, and the walk would then pile them on top of each other.
     *
     * @return Node[]
     */
    /**
     * The container's in-flow children grouped into the horizontal bands they
     * actually occupy, in reading order down the page.
     *
     * A block container's children run one under the next, so each is its own
     * band and this is the walk it has always had. A flex container's are not:
     * every item of a row line shares a top, and so does every item of a
     * wrapped column's line. Walking those as a list paginates each one after
     * the last instead of beside it, which stacks a two-column card into twice
     * the pages and prints the columns on different ones.
     *
     * @param  Node[]|null $children
     * @return list<array{0:float,1:float,2:Node[]}> top, height and items, per band
     */
    private function flowBands(Node $container, ?array $children = null): array
    {
        $inFlow = array_values(array_filter(
            $this->inFlowOrder($container, $children),
            static fn(Node $c): bool => !$c->isOutOfFlow(),
        ));

        $bands = [];

        // A multi-column box taller than the page is one ROW of columns per
        // page, and a row is a band: everything in it shares a page whatever
        // column it is in. Layout records the rows, so the band is read off
        // them rather than off the children's tops, which a column's items do
        // not share. Defect HM: without this the walk gave each item a band of
        // its own and stacked the second column BELOW the first, so a 720pt
        // two-column box on a 540pt page was three pages of one column.
        $columnRows = count($container->columnBoxes['rows'] ?? []) > 1
            ? $container->columnBoxes['rows']
            : null;

        foreach ($inFlow as $child) {
            $top = $child->y - $container->y;

            // Only a flex container and a multi-column box share a band.
            // Keying a block container's children on their own tops would
            // merge two zero-height siblings into one band and change a walk
            // that is already right.
            $key = match (true) {
                $columnRows !== null              => (string) $this->columnRowOf($columnRows, $top),
                $container->display === 'flex'    => (string) round($top, 2),
                default                           => (string) count($bands),
            };

            $bands[$key][] = $child;
        }

        $out = [];

        foreach ($bands as $items) {
            $top    = $items[0]->y - $container->y;
            $height = 0.0;

            foreach ($items as $item) {
                $top    = min($top, $item->y - $container->y);
                $height = max($height, ($item->y - $container->y) + $item->layoutHeight);
            }

            $out[] = [$top, $height - $top, $items];
        }

        return $out;
    }

    /**
     * Which row of columns an offset inside a multi-column box falls in.
     *
     * The last row whose own top is at or above the offset, so a box sitting
     * exactly on a row boundary belongs to the row it starts, and anything
     * above the first row's top belongs to that first row.
     *
     * @param list<array{count:int,top:float,height:float}> $rows
     */
    private function columnRowOf(array $rows, float $top): int
    {
        $found = 0;

        foreach ($rows as $i => $row) {
            if ($top >= $row['top'] - 1e-6) {
                $found = $i;
            }
        }

        return $found;
    }

    private function inFlowOrder(Node $container, ?array $children = null): array
    {
        $children ??= $container->children;

        if ($container->display !== 'flex') {
            return $children;
        }

        // Ascending `y` is the reading order down the page, which is what
        // pagination needs. Items sharing a `y`, which is every item of a row
        // container, fall back to CSS Flexbox §5.4's order-modified document
        // order, so the paginated painter stacks two overlapping items the
        // way the whole-tree painter does.
        usort(
            $children,
            static fn(Node $a, Node $b): int => $a->y <=> $b->y ?: $a->order <=> $b->order,
        );

        return $children;
    }

    /**
     * @param Node[][] $repeaters one run per table in scope, outermost first
     */
    private function placeNode(Node $n, float $x, array $repeaters, int $startPage, ?Node $parent): void
    {
        // Past the ceiling there is nowhere left to put anything, so stop
        // rather than keep walking a cursor that can no longer move.
        $this->deadline->check('box flow');

        if ($this->exhausted || $this->depth > $this->limits->maxDepth) {
            return;
        }

        $h = $n->layoutHeight;

        // What the box actually covers, which a float escaping it makes taller
        // than the box itself. Only the decision uses this: the cursor advances
        // by the box's own height, because a float hanging out of the bottom
        // moves nothing in the flow.
        $covers = $this->coveredHeight($n);

        // Fits whole on the current page, and nothing inside it asks for a page
        // of its own.
        //
        // **A box that fits is emitted whole and its children are never
        // walked**, so a forced break or a change of page name inside one was
        // simply lost: `VV-page-named-flex.html` and
        // `VW-page-named-tablerow.html` are a flex line and a table row that
        // Chrome starts a new page for and this engine did not, and the same
        // is true of a plain nested block and of the whole document when the
        // document fits on one page. Walking it instead is the same path a box
        // too tall for the page already takes.
        if ($covers <= $this->remaining() + 1e-6) {
            if (!$this->breaksInside($n, $parent)) {
                $this->emitWhole($n, $x, $this->cursor);
                $this->cursor += $h;

                return;
            }

            // Row-structured boxes need the band path here for the reason the
            // taller-than-the-page branch below writes down: each cell has to
            // paginate beside the last rather than after it.
            $n->display === 'grid' || $n->display === 'table-row'
                ? $this->splitGrid($n, $x, $repeaters)
                : $this->splitContainer($n, $x, $repeaters);

            return;
        }

        // Doesn't fit. Can it be broken?
        $splittable = $this->isSplittable($n);

        if (!$splittable) {
            // A box taller than the whole page cannot be kept atomic: there is
            // no page it would ever fit on, and refusing to split it means its
            // content below the fold is simply lost. Split it as a last resort.
            if ($covers > $this->pageHeight) {
                // A box that asked not to be broken still gets ONE fresh page
                // before it is broken anyway. Chrome moves it to the next page
                // and splits it there: on `WS-break-in-avoid.html` a 360pt
                // `break-inside: avoid` block under a 72pt filler starts on
                // page 2 in Chrome and started on page 1 here. It only gets
                // one, so a box already at the top of a page stays and splits
                // where it is, which is what stops the move buying a blank
                // page and is `WU-break-avoid-attop.html`. The same guard and
                // the same reasoning as {@see staysHere}, which this branch
                // never reaches because {@see isSplittable} answers "no" to
                // `avoid` before the walk gets that far.
                // **Unless the box's FIRST RUN already fits where it stands.**
                // A forced break inside the box cuts it into runs, and `avoid`
                // is about keeping a run together rather than the whole box:
                // the height that decides whether the move helps is the height
                // up to the first break, not `$covers`.
                //
                // Three documents, and the third is what settled the rule.
                // `XN-break-inside-avoid-tall.html` has a 144pt first run with
                // 162pt of room left, and Chrome does NOT move it: 2 pages
                // against the 3 this made. `WS-break-in-avoid.html` has a
                // 342pt first run that fits on no page at all, and Chrome
                // moves it, which is round 80's own reading. Between them
                // `XP-break-inside-avoid-run-fits-page.html` has a 144pt run
                // that fits a whole page but not the 108pt left, and Chrome
                // moves that one too, so the question is "does the run fit
                // HERE" and never "does it fit anywhere".
                // `XO-break-inside-avoid-tall-nobreak.html` is `XN` with the
                // declaration taken out and agreed untouched. Defect IM.
                $breakAt = $n->breakInside === 'avoid' && $this->cursor > $this->pageFloor + 1e-6
                    ? $this->firstBreakY($n, $parent)
                    : null;
                $runFits = $breakAt !== null && $breakAt - $n->y <= $this->remaining() + 0.01;

                if ($n->breakInside === 'avoid'
                    && $this->cursor > $this->pageFloor + 1e-6
                    && !$runFits
                ) {
                    $this->newPage();
                    $this->replayRepeaters($repeaters, $n);
                }

                if ($n->children !== []) {
                    $this->notes[] = sprintf(
                        'forced a split inside a %.0fpt %s: taller than the %.0fpt page',
                        $h,
                        $n->display,
                        $this->pageHeight,
                    );
                    // Row-structured boxes still need the band path here, or
                    // each cell paginates after the last instead of beside it.
                    if ($n->display === 'grid' || $n->display === 'table-row') {
                        $this->splitGrid($n, $x, $repeaters);
                    } else {
                        $this->splitContainer($n, $x, $repeaters);
                    }

                    return;
                }

                // Text always breaks at a line boundary, even when orphan and
                // widow limits would rather it did not: slicing a text box
                // paints the background and drops the words.
                if ($n->lineBoxes !== []) {
                    $this->splitText($n, $x, $repeaters);

                    return;
                }

                // A leaf with no internal structure is drawn as a run of
                // page-sized slices instead.
                $this->emitSlices($n, $x, $h, $repeaters);

                return;
            }

            $this->newPage();
            $this->replayRepeaters($repeaters, $n);
            $this->emitWhole($n, $x, $this->cursor);
            $this->cursor += $h;

            return;
        }

        // Splittable: text splits at line boundaries, containers recurse.
        if ($n->display === 'text') {
            $this->splitText($n, $x, $repeaters);

            return;
        }

        // Nothing to recurse into: a childless box is its own decoration, and
        // the slice run is what already paints a leaf taller than a page.
        //
        // **This has to come before the structural branches below.** An empty
        // `display: grid` is splittable and has no row bands, so `splitGrid()`
        // walked nothing and emitted nothing: the box disappeared from the
        // document altogether rather than being moved whole.
        if ($n->children === []) {
            $this->emitSlices($n, $x, $h, $repeaters);

            return;
        }

        // Nothing of this box's own content would stay on this page, so the
        // decoration the split opens at the cursor would be a band of
        // background with nothing inside it. Chrome starts the box on the next
        // page instead: a five-line block with room for one line paints 60.000
        // **once** (`MI-fold-block-lines-control.html`) where this painted
        // 21.000 and then 60.000, 81pt of background for a 60pt box.
        //
        // It is the question {@see rowBreaksHere} answers for a table row,
        // asked of every container, and it is asked here rather than inside
        // {@see isSplittable} because a box taller than any page still has to
        // be split. It just starts on the page it can be split on:
        // `N8-fold-block-tall-control.html` is 300.000 then 60.000 with
        // nothing at all on the page it left.
        //
        // It is also what lets {@see splitGrid} paint a decoration at all: a
        // band-walked box gets there only once one of its bands is going to
        // stay.
        if (!$this->staysHere($n)) {
            $this->newPage();
            $this->replayRepeaters($repeaters, $n);

            if ($covers <= $this->remaining() + 1e-6) {
                $this->emitWhole($n, $x, $this->cursor);
                $this->cursor += $h;

                return;
            }
        }

        // Grid items and table cells share a row, so they are not in block
        // order: walking them as a list would paginate each one after the
        // last instead of alongside it. Group by row band instead.
        if ($n->display === 'grid' || $n->display === 'table-row') {
            $this->splitGrid($n, $x, $repeaters);

            return;
        }

        // A block or column-flex container: paint its own decoration in two
        // pieces and recurse into its children.
        $this->splitContainer($n, $x, $repeaters);
    }

    /**
     * How far down the page a box's own content reaches.
     *
     * A block that establishes no formatting context does not grow to contain
     * its floats (CSS 2.1 §10.6.3), so its height can be one line while a float
     * inside it runs 500pt down the page. Paginating on the height alone then
     * emits the box whole and the part of the float below the fold is simply
     * lost, which is what this stops.
     */
    private function coveredHeight(Node $n): float
    {
        $covers = $n->layoutHeight;

        foreach ($n->escapedFloats as $f) {
            $covers = max($covers, $f['bottom']);
        }

        return $covers;
    }

    /**
     * The `$depth` is carried so a nested flex tree cannot restart the count.
     * {@see landsHere} bounds its own recursion on it, and the flex branch
     * below re-enters that recursion through {@see firstBandStays}; passing 0
     * there would let a document nest its way past the guard one level at a
     * time.
     */
    private function isSplittable(Node $n, int $depth = 0): bool
    {
        if ($n->breakInside === 'avoid') {
            return false;
        }

        if ($n->display === 'text') {
            return count($n->lineBoxes) > ($n->orphans + $n->widows - 1);
        }

        /*
         * CSS Fragmentation §3.1: what a box with no children can distribute
         * across fragments is its own content box, so two of them cannot be
         * broken inside and everything else can.
         *
         * A **replaced** element has no break opportunity in it at all: the
         * picture is monolithic, and Chrome moves an `<img>` and an inline
         * `<svg>` whole where it slices a **3.000pt** sliver off a plain block.
         * A box with **no content height** has nothing to distribute either:
         * `height: 0; padding: 12px 0` moves whole even when the top padding
         * would have fitted, and `height: 40px` is sliced 21.000 / 9.000.
         */
        if ($n->children === []) {
            return $n->display !== 'rect'
                && $n->layoutHeight - $n->edgeCross(true) > 0.01;
        }

        if ($n->display === 'grid') {
            return true;
        }

        // A table row is cut at one offset through every cell, and that is
        // exactly what Chrome does with it: a two-cell 80px row 21pt above the
        // fold is 21.000 of both cells on one page and 39.000 on the next
        // (`M1-fold-row-separate.html`). What it will not do is cut a row that
        // leaves one of its cells with nothing above the fold, so the offset
        // has to be one every cell can break at.
        if ($n->display === 'table-row') {
            return $this->rowBreaksHere($n, $this->remaining());
        }

        if ($n->display === 'table') {
            return true;
        }

        // A row flex container is a single flex line in the cross axis.
        // Breaking inside it means slicing every item at the same offset, and
        // that is what Chrome does with it: `PB-fold-flexrow-bg.html` is
        // 21.000 of the container on one page and 39.000 on the next. What it
        // will not do is cut a line that leaves one item with nothing above the
        // fold, so the offset has to be one every item can break at, which is
        // the rule a table row is cut by ({@see rowBreaksHere}) and a grid row
        // ({@see bandBreaksHere}). `Q3-fold-flexrow-mixed.html` is an empty
        // item beside a five-line one and Chrome moves the line whole.
        if ($n->display === 'flex' && $n->isRow()) {
            if ($n->flexWrap !== 'nowrap' && $this->countFlexLines($n) > 1) {
                return true;   // break between lines: legitimate
            }

            // Items that do not share a top are asked exactly the question
            // items that do are asked, because what decides the cut is the band
            // at the top of the container and nothing below it: Chrome cuts
            // `ZJ-fold-flexrow-center.html` 21.000 / 39.000 and moves the
            // centred item beside it whole. Where that item lands is
            // {@see splitContainer}'s band advance rather than this predicate,
            // and it was defect BT.
            return $this->forceSplitFlexLines || $this->firstBandStays($n, $this->remaining(), $depth);
        }

        return true;
    }

    /**
     * Can every cell of this row be broken within the room on this page?
     *
     * A row is cut at one offset, so the offset has to work for all of its
     * cells at once. Chrome moves a row whole the moment one cell has nothing
     * it can leave behind: `MG-fold-row-mixed.html` puts an empty 80px cell
     * beside a five-line one with room for a single line, and the empty cell
     * could have been sliced, but the row moves.
     */
    private function rowBreaksHere(Node $row, float $room, int $depth = 0): bool
    {
        // A cell's own `y` is measured from the row's border-box top, so it
        // already carries whatever padding and border the row puts above it.
        // Taking `edge('top')` off the room as well counted that twice, which
        // is invisible on a `<tr>` with no edges of its own and moves the
        // answer on one that has them.
        foreach ($row->children as $cell) {
            if ($cell->isOutOfFlow()) {
                continue;
            }

            if (!$this->landsHere($cell, $room - ($cell->y - $row->y), $depth)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Would any of this box's own content stay on the page it starts on?
     *
     * A box cut at the fold paints its background down to the fold, so a cut
     * with nothing above it paints a band of color with nothing in it. The
     * rule is the content's own break opportunities and not the geometry: a
     * five-line row with room for one line moves whole
     * (`M3-fold-row-lines.html`) and the same row with room for three is cut
     * at the fold (`MD-fold-row-lines-348.html`, 39.000 then 24.000).
     */
    private function landsHere(Node $n, float $room, int $depth = 0): bool
    {
        // The walk below descends one level per nesting level of
        // author-controlled markup, and every other recursion in this class is
        // bounded the same way. Answering "no" is the conservative direction:
        // it moves the box whole, which is what it did before this existed.
        if ($depth > $this->limits->maxDepth) {
            return false;
        }

        if ($room <= 0.01) {
            return false;
        }

        $covers = $this->coveredHeight($n);

        if ($covers <= $room + 1e-6) {
            return true;
        }

        // Text answers out of its own lines whatever its total height, because
        // {@see splitText} applies the orphan rule before it splits: fewer
        // lines fitting than `orphans` asks for turns the page **first** and
        // cuts on the next one. That is why this comes above the page-height
        // clause below rather than under it, where a thirty-line block with
        // room for one line claimed the room and painted an empty band into it
        // (`N8-fold-block-tall-control.html`).
        if ($n->display === 'text' && $n->breakInside !== 'avoid') {
            $fit  = 0;
            $used = 0.0;

            foreach ($n->lineBoxes as $line) {
                if ($used + $line->height > $room + 1e-6) {
                    break;
                }

                $used += $line->height;
                $fit++;
            }

            return $fit >= $n->orphans;
        }

        // Taller than any page: {@see placeNode} splits it wherever it has to,
        // because there is no page it would fit on whole.
        if ($covers > $this->pageHeight) {
            return true;
        }

        if ($n->breakInside === 'avoid') {
            return false;
        }

        if ($n->display === 'table-row') {
            return $this->rowBreaksHere($n, $room, $depth + 1);
        }

        // A grid is walked band by band too, so what decides it is its first
        // band and not its first child: {@see flowBands} would hand back one
        // item per band and answer out of that one alone.
        if ($n->display === 'grid') {
            return $this->firstBandStays($n, $room, $depth + 1);
        }

        // Round 18j's row 10: what a childless box distributes is its own
        // content box, and it has no minimum slice at all.
        if ($n->children === []) {
            return $n->display !== 'rect' && $covers - $n->edgeCross(true) > 0.01;
        }

        if (!$this->isSplittable($n, $depth)) {
            return false;
        }

        $bands = $this->flowBands($n);

        if ($bands === []) {
            return true;
        }

        // {@see flowBands} measures a band from the container's border-box
        // top, so the first band's own offset is the whole distance down to
        // it, edges included. Adding `edge('top')` counted them twice.
        [$relTop, , $items] = $bands[0];

        // A cell asks the question as if its content were at the top, because a
        // row the fold cuts aligns its cells there: the centred offset is past
        // the fold, so with it counted no offset is one every cell can break at
        // and the row was refused (defect CF). {@see topAlignCells} takes the
        // shift off for real, once the cut is certain.
        $relTop -= $n->cellShift;

        foreach ($items as $item) {
            if ($this->landsHere($item, $room - $relTop, $depth + 1)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Would this box put any of its own content on the page it starts on?
     *
     * {@see landsHere} is the same question asked of a child, and the two
     * differ in one clause: a box taller than any page answers "yes" there,
     * because there is no page it would ever fit on whole and something has to
     * give. Asked of the box being placed, that is not the question. A
     * thirty-line block with room for one line is cut on the **next** page and
     * leaves this one alone, so what decides it is the box's own first
     * content, whatever the total comes to.
     */
    private function staysHere(Node $n): bool
    {
        $room = $this->remaining();

        if ($room <= 0.01) {
            return false;
        }

        // Already at the top of a page: the next one offers exactly the same
        // room, so turning it buys a blank page and answers nothing. Same
        // principle as {@see splitText}'s orphan rule and {@see
        // forcePageBreak}.
        if ($this->cursor <= $this->pageFloor + 1e-6) {
            return true;
        }

        if ($this->coveredHeight($n) <= $room + 1e-6) {
            return true;
        }

        if ($n->breakInside === 'avoid') {
            return false;
        }

        if (!$this->isSplittable($n)) {
            return false;
        }

        // A grid and a table row are walked band by band, so they are asked
        // about the band {@see splitGrid} will actually place.
        if ($n->display === 'grid' || $n->display === 'table-row') {
            return $this->firstBandStays($n, $room);
        }

        $bands = $this->flowBands($n);

        if ($bands === []) {
            return true;
        }

        [$relTop, , $items] = $bands[0];

        foreach ($items as $item) {
            if ($this->landsHere($item, $room - $relTop)) {
                return true;
            }
        }

        return false;
    }

    private function countFlexLines(Node $n): int
    {
        $ys = [];

        foreach ($n->children as $c) {
            $ys[(int) round($c->y * 100)] = true;
        }

        return count($ys);
    }

    /** @return LineBox[] */
    private function linesOf(Node $n): array
    {
        return $n->lineBoxes;
    }

    /** Emit a whole subtree onto a page that is not the current one. */
    private function emitOnPage(Node $n, int $page, float $x, float $y): void
    {
        $was         = $this->page;
        $this->page  = $page;
        $this->pages[$page] ??= [];

        $this->emitWhole($n, $x, $y);

        $this->page = $was;
    }

    private function emitWhole(Node $n, float $x, float $y): void
    {
        $this->placed[spl_object_id($n)] = ['page' => $this->page, 'y' => $y, 'x' => $x];
        $this->emit(
            new Fragment(
                $n, $x, $y, $n->layoutWidth, $n->layoutHeight,
                $n->display === 'text' ? $this->linesOf($n) : [],
            ),
        );

        // Children ride along; their offsets are relative to the node.
        $affected = $this->pushEffects($n, $x, $y, $n->layoutWidth, $n->layoutHeight);
        $clipped  = $this->pushClip($n, $x, $y, $n->layoutWidth, $n->layoutHeight);

        foreach ($this->inFlowOrder($n) as $c) {
            if ($c->isOutOfFlow()) {
                continue;
            }

            $this->emitWholeSubtree($c, $x + ($c->x - $n->x), $y + ($c->y - $n->y));
        }

        if ($clipped) {
            array_pop($this->clips);
        }

        if ($affected) {
            array_pop($this->effects);
        }
    }

    private function emitWholeSubtree(Node $n, float $x, float $y): void
    {
        $this->placed[spl_object_id($n)] = ['page' => $this->page, 'y' => $y, 'x' => $x];
        $this->emit(
            new Fragment(
                $n, $x, $y, $n->layoutWidth, $n->layoutHeight,
                $n->display === 'text' ? $this->linesOf($n) : [],
            ),
        );

        $affected = $this->pushEffects($n, $x, $y, $n->layoutWidth, $n->layoutHeight);
        $clipped  = $this->pushClip($n, $x, $y, $n->layoutWidth, $n->layoutHeight);

        foreach ($this->inFlowOrder($n) as $c) {
            if ($c->isOutOfFlow()) {
                continue;
            }

            $this->emitWholeSubtree($c, $x + ($c->x - $n->x), $y + ($c->y - $n->y));
        }

        if ($clipped) {
            array_pop($this->clips);
        }

        if ($affected) {
            array_pop($this->effects);
        }
    }

    /**
     * Group a band-walked box's children by the row they share.
     *
     * This is the banding {@see splitGrid} walks, and the question of whether
     * anything stays on a page has to be asked about the same bands the walk
     * will place. {@see flowBands} answers a different one: it assumes block
     * order, so it would hand back a grid's items one per band and call the
     * band breakable because the **first** item can be, where the walk needs
     * every item sharing that row to be.
     *
     * @return array<string, Node[]>
     */
    private function rowBands(Node $grid): array
    {
        $bands = [];

        foreach ($grid->children as $child) {
            if ($child->isOutOfFlow()) {
                continue;
            }

            $key           = (string) round($child->y - $grid->y, 2);
            $bands[$key][] = $child;
        }

        uksort($bands, static fn(string $a, string $b): int => (float) $a <=> (float) $b);

        return $bands;
    }

    /**
     * Give back the offset `vertical-align` gave a cell's content inside its
     * row, because a row the fold cuts aligns its cells to the top of the
     * fragment. It is done once and cleared, so a row two folds cut does not
     * shift twice, and it is done here rather than in the predicate above
     * because a row that turns out to move whole must stay centred
     * (`ZF-fold-cell-valign-nofold.html`).
     *
     * @param Node[] $cells
     */
    private function topAlignCells(array $cells): void
    {
        foreach ($cells as $cell) {
            if ($cell->cellShift <= 0.01) {
                continue;
            }

            foreach ($cell->children as $child) {
                $child->y -= $cell->cellShift;
            }

            $cell->cellShift = 0.0;
        }
    }

    /**
     * Can every item of this band be broken within the room left on the page?
     *
     * A band is cut at one offset, so the offset has to work for all of its
     * items at once. It is {@see rowBreaksHere}'s rule, and Chrome applies it
     * to a grid row exactly as it does to a table row:
     * `P3-fold-grid-mixed.html` is an empty 80px item beside a five-line one
     * with room for a single line, the empty one could have been cut anywhere,
     * and the band moves whole.
     *
     * @param Node[] $items
     */
    private function bandBreaksHere(Node $grid, array $items, float $top, float $room, int $depth = 0): bool
    {
        if ($items === []) {
            return false;
        }

        foreach ($items as $item) {
            if (!$this->landsHere($item, $room - (($item->y - $grid->y) - $top), $depth)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Would the first band of a band-walked box be placed on this page?
     *
     * Either it fits in the room left, or every one of its items can be broken
     * in that room. Anything else moves the band whole, and a box whose first
     * band moves has nothing on the page it started on to decorate.
     */
    private function firstBandStays(Node $grid, float $room, int $depth = 0): bool
    {
        $bands = $this->rowBands($grid);

        if ($bands === []) {
            return true;
        }

        $room  -= $grid->edge('top');
        $top   = (float) array_key_first($bands);
        $items = reset($bands);
        $height = 0.0;

        foreach ($items as $item) {
            $height = max($height, ($item->y - $grid->y) + $item->layoutHeight - $top);
        }

        if ($height <= $room + 1e-6) {
            return true;
        }

        return $this->bandBreaksHere($grid, $items, $top, $room, $depth);
    }

    /**
     * Fragment a grid by row band.
     *
     * Children of a grid are laid out in two dimensions, so the block-order
     * assumption behind splitContainer does not hold: two items in the same
     * row have the same top and would be stacked on top of each other. Group
     * them by row and break between those groups.
     *
     * @param Node[][] $repeaters one run per table in scope, outermost first
     */
    private function splitGrid(Node $grid, float $x, array $repeaters): void
    {
        if ($this->depth > $this->limits->maxDepth) {
            return;
        }

        $this->depth++;

        $bands        = $this->rowBands($grid);
        $startPage    = $this->page;
        // The band an ancestor holds back for its repeating `<tfoot>`. A row
        // never owns one, so whatever is reserved belongs to a table above it
        // and is not this box's to paint into.
        $holdBack     = $this->footerReserve;
        $startY       = $this->cursor;
        $decoStart    = $this->cursor;
        $decoPage     = $this->page;
        $decoOffset   = 0.0;
        $decoProxies  = [];
        $droppedAtStart = $this->gapsTruncated;
        $affected     = $this->pushEffects($grid, $x, $this->cursor, $grid->layoutWidth, $grid->layoutHeight);
        $this->cursor += $grid->edge('top');
        $prevBottom   = null;
        // Whether a forced break inside one of the items is what carried this
        // box onto a later page, which the `rowspan` correction below has to
        // know: a row cut by a break really does cover the distance it
        // traveled, where a row whose spanning cell simply reached further
        // does not.
        $forcedInBand = false;

        // The band walk emits its items and nothing else, so a box split here
        // painted no background, border or outline of its own on **any** page:
        // `MK-fold-grid-bg.html`'s grid lost its background completely the
        // moment it reached a fold, on the page it left and on the page it
        // landed on alike, which is content leaving the paper rather than
        // moving on it. That is defect BP and this is {@see splitContainer}'s
        // bookkeeping, which had no counterpart here.
        //
        // Round 18k could only give it to a table row, because a band that does
        // not fit used to **move** rather than slice and opening a decoration
        // on the page it left would paint a band of color with nothing inside
        // it. Two things make it safe for every band-walked box now: a band
        // whose items can all be broken is sliced rather than moved (below),
        // and a box none of whose bands would stay never gets here at all,
        // because {@see placeNode} turns the page in front of it.
        // A band-walked box never contains the run repeating above it: a
        // `<thead>` row belongs to the table, not to the body row being cut.
        $underRepeaters = $repeaters !== [];

        $closePagesLeft = function () use ($grid, $x, $underRepeaters, $holdBack, &$decoPage, &$decoStart, &$decoOffset, &$decoProxies): void {
            if ($decoPage >= $this->page) {
                return;
            }

            for ($p = $decoPage; $p < $this->page; $p++) {
                $decoProxies[] = $this->closeDecoration($grid, $x, $p, $decoStart, false, $decoOffset, $holdBack);
                $decoOffset += max(0.0, $this->pageHeight - $holdBack - $this->reserveOn($p) - $decoStart);
                $decoStart  = $underRepeaters ? ($this->pageFloors[$p + 1] ?? 0.0) : 0.0;
            }

            $decoPage = $this->page;
        };

        foreach ($bands as $key => $items) {
            $top    = (float) $key;
            $height = 0.0;

            foreach ($items as $item) {
                $height = max($height, ($item->y - $grid->y) + $item->layoutHeight - $top);
            }

            $this->cursor += $prevBottom === null ? 0.0 : max(0.0, $top - $prevBottom);

            // The space between two bands is a gap and the fold truncates it,
            // exactly as it truncates the margin between two block siblings
            // and the `border-spacing` between two table rows. Without this
            // the band still lands on the page after, because it no longer
            // fits, and the box is charged for a gap that never reached the
            // paper: `SQ-ramp-gridgap.html`'s marker sat 60pt low.
            // {@see settleGap}.
            if ($prevBottom !== null) {
                $this->settleGap($repeaters);
            }

            // A band's own ITEM asks for a page the same two ways every other
            // box does, and this walk never asked it either way. Round 72 gave
            // {@see flowChildren} the page-name question and round 74 gave
            // {@see splitContainer}'s band walk both of them; the grid and
            // table-row walk kept the old shape, so a `page` name or a
            // `break-before: page` on a grid item or on a `<td>` did nothing at
            // all. `breaksInside` above reads a band item's DESCENDANTS, which
            // is why a name one level further down already worked.
            //
            // Four probes and two controls, and both spellings were wrong:
            // `XB-page-named-size-grid.html` and `XD-page-named-size-td.html`
            // name a page on the item itself and Chrome writes 288x144 then
            // 288x288 where this wrote 288x288 twice;
            // `XF-break-on-grid-item.html` and `XG-break-on-td.html` are the
            // same position in the `break-before` spelling and Chrome pages 2
            // where this paged 1. `XC-page-named-size-cell.html` and
            // `XE-page-named-size-in-grid.html` put the name one level down,
            // inside the item, and both agreed untouched. Defect IK.
            //
            // A band holds several items side by side, so the first that asks
            // is the one that answers, exactly as a flex line is read in
            // {@see splitContainer}: Chrome starts the page before the WHOLE
            // band when any one item asks.
            $asks = $items[0];

            foreach ($items as $item) {
                if ($item->breakBefore === 'page' || $this->startsAPageType($item)) {
                    $asks = $item;

                    break;
                }
            }

            $entersType = $this->startsAPageType($asks);

            // The third spelling, and it is read off ANY item rather than off
            // the last one. `XJ-break-after-first-of-band.html` puts two grid
            // items in one band and the `break-after` on the FIRST of them, and
            // Chrome starts a fresh page after the whole band; asking
            // `end($items)` alone would have read one page.
            // `XK-break-before-second-of-band.html` is the same question in the
            // `break-before` spelling and the loop above already answers it,
            // which is the flex line's rule from `VV-page-named-flex.html`
            // holding for a grid band too.
            $breaksAfter = false;

            foreach ($items as $item) {
                if ($item->breakAfter === 'page') {
                    $breaksAfter = true;

                    break;
                }
            }

            if ($asks->breakBefore === 'page' || $entersType) {
                // A break in front of the band is what carried the box onto a
                // later page, so the `rowspan` correction below must not read
                // the distance back off the cursor: the row really did travel
                // it. That is the same reason a break INSIDE an item sets this.
                $forcedInBand = true;

                $this->forcePageBreak($x, $repeaters, $grid);
            }

            // After the break rather than before it, because the type belongs
            // to the page the band lands on. {@see enterPageType}.
            if ($entersType) {
                $this->enterPageType($asks->pageName);
            }

            // A band that will not fit moves whole, unless it is taller than
            // any page, and then its items are fragmented individually.
            //
            // A band whose items can every one of them be broken in the room
            // left is the other case that fragments rather than moves, and the
            // same per-item walk is what places it: each item starts where the
            // band starts and breaks where its own content lets it. Chrome cuts
            // a grid row exactly as it cuts a table row, background and all
            // (`MK-fold-grid-bg.html`, 21.000 / 39.000), and refuses on exactly
            // the same terms: one item that cannot be broken moves the whole
            // band (`P3-fold-grid-mixed.html`).
            //
            // A table row is asked the same question earlier, by
            // {@see isSplittable}, so reaching here at all already means
            // {@see rowBreaksHere} has agreed.
            // A band that FITS is emitted item by item whole, so a forced break
            // inside one of its items was lost the same way a forced break
            // inside a box that fits was lost before round 74 fixed
            // {@see placeNode}. The band walk is one level further down and
            // kept the old shape: `WO-break-in-cell.html` puts
            // `break-before: page` on a paragraph inside a table cell, and
            // Chrome cuts the row there and carries the rest to the next page
            // where this engine paginated as though the declaration were not
            // there. The per-item walk below is the path that places it, and
            // it is the same one a band too tall for the page already takes.
            $breaksInBand = false;

            foreach ($items as $item) {
                if ($this->breaksInside($item, $grid)) {
                    $breaksInBand = true;
                    $forcedInBand = true;

                    break;
                }
            }

            if ($height > $this->remaining() + 1e-6 || $breaksInBand) {
                if ($height > $this->pageHeight
                    || $breaksInBand
                    || $grid->display === 'table-row'
                    || $this->bandBreaksHere($grid, $items, $top, $this->remaining())) {
                    // The cut is certain now, so a cell whose `vertical-align`
                    // pushed its content down the row gives that back: Chrome
                    // aligns a fragmented row's cells to the top of the
                    // fragment and renders `Y5-fold-cell-valign-middle.html`
                    // and `Y6-fold-cell-valign-top.html` identically, rect for
                    // rect (defect CF).
                    $this->topAlignCells($items);

                    // Taller than any page: fragment each item from the band's
                    // start, then continue from the furthest point any of them
                    // reached. Advancing by the band height on top of that
                    // would count the same space once per item.
                    $bandPage   = $this->page;
                    $bandCursor = $this->cursor;
                    $endPage    = $bandPage;
                    $endCursor  = $bandCursor;

                    foreach ($items as $item) {
                        $this->page   = $bandPage;
                        $this->cursor = $bandCursor;
                        $this->placeNode($item, $x + ($item->x - $grid->x), $repeaters, $startPage, $grid);

                        if ($this->page > $endPage || ($this->page === $endPage && $this->cursor > $endCursor)) {
                            $endPage   = $this->page;
                            $endCursor = $this->cursor;
                        }
                    }

                    $this->page   = $endPage;
                    $this->cursor = $endCursor;

                    if ($breaksAfter) {
                        $forcedInBand = true;

                        $this->forcePageBreak($x, $repeaters, $grid);
                    }

                    $prevBottom   = $top + $height;
                    continue;
                }

                $this->newPage();
                $this->replayRepeaters($repeaters);
            }

            $bandTop = $this->cursor;

            foreach ($items as $item) {
                $this->emitWhole(
                    $item,
                    $x + ($item->x - $grid->x),
                    $bandTop + (($item->y - $grid->y) - $top),
                );
            }

            $this->cursor = $bandTop + $height;
            $prevBottom   = $top + $height;

            // Both exits of the band loop need it, because a band that
            // fragments leaves through the `continue` above and never reaches
            // here. `splitContainer()` has one exit and asks once.
            if ($breaksAfter) {
                $forcedInBand = true;

                $this->forcePageBreak($x, $repeaters, $grid);
            }

            $closePagesLeft();
        }

        $this->cursor += $grid->edge('bottom');

        // Distance the box's OWN height covered. A page it crossed gave it the
        // page height less whatever was reserved at the foot of that page for
        // a repeating `<tfoot>`, and less whatever a repeated header took at
        // the top: neither band is the box's own extent, and charging them to
        // it makes the `rowspan` correction below take them off the cursor. A
        // page whose own `@page` block makes its content box shorter gave it
        // less again, and that band is per page rather than the same on each.
        $repeated  = $underRepeaters ? $this->repeatedBetween($startPage, $this->page) : 0.0;
        $held      = $this->reservedBetween($startPage, $this->page);
        $usable    = $this->pageHeight - $holdBack;
        $travelled = ($this->page - $startPage) * $usable + ($this->cursor - $startY) - $repeated - $held
            + ($this->gapsTruncated - $droppedAtStart);

        // A `rowspan` cell reaches past the row it was written in, and the
        // walk above leaves the cursor wherever the furthest cell ended. What
        // comes after a row is placed after the ROW, so the overshoot comes
        // back off: Chrome puts the second row of `MA-fold-row-rowspan.html`
        // at 9.000 on the page the first was cut onto, beside the spanning
        // cell's continuation rather than under it.
        if ($grid->display === 'table-row' && !$forcedInBand && $travelled > $grid->layoutHeight + 0.01) {
            $this->cursor = max(0.0, $this->cursor - ($travelled - $grid->layoutHeight));
            $travelled    = ($this->page - $startPage) * $usable + ($this->cursor - $startY) - $repeated - $held
                + ($this->gapsTruncated - $droppedAtStart);
        }

        $shortfall = min($grid->layoutHeight - $travelled, $grid->layoutHeight);

        if ($shortfall > 0.01 && !$this->advanceTruncated && !$this->exhausted) {
            $this->advance($shortfall, $x, $repeaters);
        }

        $closePagesLeft();

        if ($affected) {
            array_pop($this->effects);
        }

        $decoProxies[] = $this->closeDecoration($grid, $x, $decoPage, $decoStart, true, $decoOffset);
        $this->shrinkTruncatedRamp($decoProxies, $this->gapsTruncated - $droppedAtStart);

        $this->depth--;
    }

    /**
     * Draw a box that spans several pages as one slice per page.
     *
     * @param Node[][] $repeaters one run per table in scope, outermost first
     */
    private function emitSlices(Node $n, float $x, float $height, array $repeaters): void
    {
        $left  = $height;
        $guard = 0;

        while ($left > 0.01 && $guard++ < $this->limits->maxPages) {
            $this->deadline->check('page advance');
            $slice = min($left, $this->remaining());

            if ($slice > 0.01) {
                $this->emit(
                    new Fragment(
                        $n, $x, $this->cursor, $n->layoutWidth, $slice,
                        [], $guard > 1, $left - $slice > 0.01,
                        slicedBackground: [$height - $left, $height],
                    ),
                );

                $this->cursor += $slice;
                $left         -= $slice;
            }

            if ($left <= 0.01) {
                break;
            }

            $before = $this->page;
            $this->newPage();

            if ($this->page === $before) {
                return;
            }

            $this->replayRepeaters($repeaters);

            if ($this->remaining() <= 0.01) {
                return;
            }
        }
    }

    /**
     * Re-emit the rows marked to repeat at the top of a continuation page.
     *
     * A header **over a quarter of the page** is refused, which is where Chrome
     * draws the same line, and the fraction rather than the length is what
     * holds when the margins change. The boundary itself is repeated: on a
     * 300pt page Chrome repeats a 75.000pt header and refuses a 75.750pt one,
     * and on a 240pt page it repeats 60.000pt and refuses 60.750pt. Round 18g
     * read the test as "at or over" from a 280pt page, whose quarter is 70pt
     * and cannot be written in whole CSS pixels, so the nearest shape it could
     * measure was already past the line.
     *
     * Below that a big header does not fill a page before content can land on
     * it, but it does multiply the page count: a 200pt header on a 280pt page
     * turns a table needing two pages into one needing eleven, because ten of
     * the eleven carry the header and 80pt of rows.
     *
     * A header belongs to a page **once**, and how many times the page was
     * turned onto is not how many headers it wants. The band walk turns it
     * once per item that crosses the fold, and every cell of a sliced table
     * row asks for the header separately, so without this guard a two-cell row
     * printed the header twice and a three-cell row three times, each copy
     * offset by one cell width and the last of them clear off the right edge
     * of the paper (defect BU, `R2` and `S3`). A page already carrying the run
     * only has its cursor moved below it.
     *
     * **The runs stay distinct and each is judged on its own**, which is what
     * Chrome does: `U2-nested-thead-wide.html` is a 60pt outer header over a
     * 60pt inner one on a 300pt page, 20% each and 40% together, and Chrome
     * repeats **both** on every page. Summing them would refuse both, and
     * `U3-nested-thead-outerbig.html` is the shape at the other end, a 93pt
     * outer (31%, refused) over a 15pt inner (5%, repeated), where merging
     * would lose the inner one as well (defect BX).
     *
     * @param Node[][] $repeaters one run per table in scope, outermost first
     */
    private function replayRepeaters(array $repeaters, ?Node $exclude = null): void
    {
        $emitted = false;
        $seen    = false;

        foreach ($repeaters as $run) {
            $key = $this->repeaterKey($run);

            if (isset($this->repeatersOnPage[$this->page][$key])) {
                // Already laid onto this page by something else that turned it,
                // and its height is in the page's floor: step over it so a run
                // nested inside it lands underneath rather than on top.
                $this->cursor = max($this->cursor, $this->pageFloors[$this->page] ?? 0.0);
                $seen         = true;

                continue;
            }

            $this->repeatersOnPage[$this->page][$key] = true;

            $rim   = $this->repeaterRim($run, $exclude);
            $total = $rim;

            foreach ($run as $r) {
                if ($r === $exclude) {
                    continue;
                }
                $total += $r->layoutHeight + $r->marginCross(true);
            }

            if ($total > $this->pageHeight / 4.0 + 1e-6) {
                if (!$this->oversizedHeaderNoted) {
                    $this->oversizedHeaderNoted = true;
                    $this->notes[]              = sprintf(
                        'repeating header is %.0fpt on a %.0fpt page, not repeated',
                        $total,
                        $this->pageHeight,
                    );
                }

                continue;
            }

            // Where this run starts is where the table that owns it resumes
            // its own background, which is not the top of the page once a run
            // above it has been laid down first.
            $this->repeaterFloors[$this->page][$key] = $this->cursor;

            $this->cursor += $rim;

            foreach ($run as $r) {
                if ($r === $exclude) {
                    continue;
                }

                // At the header's **own** x, not at the x of whatever turned
                // the page. A cell of a sliced row turns it from that cell's
                // left edge, and replaying there put the header a cell width to
                // the right of the table and ran the copy off the paper.
                $this->emitWhole($r, $r->x, $this->cursor);
                $this->cursor += $r->layoutHeight + $r->marginCross(true);
            }

            $emitted = true;
        }

        if ($emitted) {
            $this->pageFloor               = $this->cursor;
            $this->pageFloors[$this->page] = $this->cursor;

            return;
        }

        if ($seen) {
            $this->pageFloor = $this->cursor;
        }
    }

    /**
     * A stable name for a run of repeated rows, so the same header is
     * recognized on a page whoever asks for it and a second table's is not
     * mistaken for it.
     *
     * @param Node[] $repeaters
     */
    private function repeaterKey(array $repeaters): string
    {
        return implode(',', array_map(spl_object_id(...), $repeaters));
    }

    /**
     * How much of the distance between two pages went to repeated headers
     * rather than to the box that crossed them.
     *
     * A box under a repeating header is pushed down the continuation page by
     * it, so the raw page distance overstates how much of the box's own
     * height has been placed. Charging the header to the box makes a sliced
     * row look 15.000 taller than it is, and {@see splitGrid}'s `rowspan`
     * correction then takes that 15.000 back off the cursor: the table's
     * background stopped at 15.000 on `S2-fold-thead-tablebg.html` where
     * Chrome paints 30.000, one header plus the row's continuation.
     */
    private function repeatedBetween(int $from, int $to): float
    {
        $total = 0.0;

        for ($p = $from + 1; $p <= $to; $p++) {
            $total += $this->pageFloors[$p] ?? 0.0;
        }

        return $total;
    }

    /**
     * How far below the top of a continuation page a repeated header starts.
     *
     * A collapsed cell straddles the grid line it shares, so half the rim it
     * carries is drawn outside its own box. On the first page the table's own
     * decoration draws that line and the header sits below it; on a
     * continuation `box-decoration-break: slice` leaves the table's top edge
     * undrawn, so the header's copy is the only one there is, and against the
     * page top its outer half falls off the page. Chrome starts the repeated
     * header the same distance down that the first page does, and the whole
     * line shows.
     *
     * Only the first row repeated is measured: it is the one at the page top,
     * and any row under it shares an internal line rather than the rim.
     *
     * @param Node[] $repeaters
     */
    private function repeaterRim(array $repeaters, ?Node $exclude): float
    {
        foreach ($repeaters as $r) {
            if ($r === $exclude) {
                continue;
            }

            $rim = 0.0;

            foreach ($r->children as $cell) {
                if ($cell->collapsedBorder) {
                    $rim = max($rim, $cell->borderWidth('top') / 2.0);
                }
            }

            return $rim;
        }

        return 0.0;
    }

    /** @param Node[][] $repeaters one run per table in scope, outermost first */
    private function splitText(Node $n, float $x, array $repeaters): void
    {
        $lines          = $n->lineBoxes;
        $isContinuation = false;

        while ($lines !== []) {
            // Line boxes have individual heights (mixed font sizes), so count
            // how many actually fit rather than dividing by a fixed leading.
            $fit  = 0;
            $used = 0.0;

            foreach ($lines as $lb) {
                if ($used + $lb->height > $this->remaining() + 1e-6) {
                    break;
                }

                $used += $lb->height;
                $fit++;
            }

            // Moving to a fresh page is only worth it when this one has
            // something on it already. On a page nothing has been placed on,
            // the next page offers exactly the same room, so obeying the
            // orphan rule there buys a blank page and then clips the line
            // anyway. Same principle as skipping a forced break at the top of
            // a page.
            if ($fit < $n->orphans && $this->cursor > $this->pageFloor + 1e-6) {
                $this->newPage();
                $this->replayRepeaters($repeaters);
                $fit  = 0;
                $used = 0.0;

                foreach ($lines as $lb) {
                    if ($used + $lb->height > $this->remaining() + 1e-6) {
                        break;
                    }

                    $used += $lb->height;
                    $fit++;
                }
            }

            if ($fit < count($lines) && count($lines) - $fit < $n->widows) {
                $fit = max($n->orphans, count($lines) - $n->widows);
            }

            $fit    = min(max($fit, 1), count($lines));
            $chunk  = array_splice($lines, 0, $fit);
            $chunkH = 0.0;

            foreach ($chunk as $lb) {
                $chunkH += $lb->height;
            }

            $this->emit(
                new Fragment(
                    $n, $x, $this->cursor, $n->layoutWidth, $chunkH,
                    $chunk, $isContinuation, $lines !== [],
                ),
            );

            $this->cursor += $chunkH;
            $this->settleCursor($x, $repeaters);

            if ($lines !== []) {
                $this->newPage();
                $this->replayRepeaters($repeaters);
                $isContinuation = true;
            }
        }
    }

    /**
     * Advance the cursor by a distance that may span page boundaries, so a
     * box's own height is honored even when its content is shorter.
     *
     * @param Node[][] $repeaters one run per table in scope, outermost first
     */
    private function advance(float $amount, float $x, array $repeaters): void
    {
        if ($this->exhausted) {
            return;
        }

        $remaining = $amount;
        $guard     = 0;

        while ($remaining > $this->remaining() + 1e-6 && $guard++ < $this->limits->maxPages) {
            $this->deadline->check('page advance');
            $remaining -= $this->remaining();
            $before    = $this->page;
            $this->newPage();

            if ($this->page === $before) {
                return;   // ceiling reached
            }

            $this->replayRepeaters($repeaters);

            // A fresh page that is already full would make no progress. That
            // can only happen if something other than an oversized header
            // filled it, so treat it as a hard stop rather than spinning,
            // but record that the advance was cut short, so callers do not
            // read the shortfall as space they still owe.
            if ($this->remaining() <= 0.01) {
                $this->advanceTruncated = true;

                return;
            }
        }

        $this->cursor += max(0.0, $remaining);
    }

    /**
     * Roll a cursor that has run past the bottom of its page forward onto the
     * pages it actually covers.
     *
     * The cursor is page-local, but a few advances are not bounded by the
     * space left: an inter-child gap, and a line box taller than any page,
     * which has to be emitted where it starts because there is no page it
     * would fit on. Leaving the cursor past the fold silently collapses the
     * pages that distance spans, so whatever comes next is stacked back on
     * top of it.
     *
     * @param Node[][] $repeaters one run per table in scope, outermost first
     */
    private function settleCursor(float $x, array $repeaters): void
    {
        $bottom  = $this->pageHeight - $this->reserveOn($this->page);
        $overrun = $this->cursor - $bottom;

        if ($overrun <= 0.01) {
            return;
        }

        $this->cursor = $bottom;
        $this->advance($overrun, $x, $repeaters);
    }

    /**
     * Settle a gap between two boxes that runs past the bottom of the page.
     *
     * CSS Fragmentation §3.5 truncates a margin the break falls inside, so
     * what is left of it below the fold is dropped rather than carried and a
     * margin never buys a page of its own. Chrome puts the next box at the top
     * of the page after, whatever the size of the gap, and all four measured
     * shapes give that same answer: a 40pt margin 20pt of which fitted
     * (`J2-margin-fold.html`), one starting exactly at the fold
     * (`J2-margin-atfold.html`), a 400pt one (`J2-margin-huge.html`) and a
     * 1000pt one (`RJ-margin-1000.html`), where this engine put the next box
     * 20pt down, 40pt down, a page later and three pages later.
     *
     * {@see settleCursor} is the other half of the question and it carries,
     * because what it settles is not a margin: a staggered flex item's offset
     * is its own place inside the line, and Chrome does carry what is left of
     * it onto the next page (`ZK-fold-flexrow-offsetpast.html`, 1.500).
     *
     * **Only the gap between two siblings truncates.** The first child's
     * offset is the container's own padding and Chrome does not truncate that:
     * it moves the box whole instead (`NB-fold-block-bigpad.html`), so that
     * one still goes through {@see settleCursor}.
     *
     * @param Node[][] $repeaters one run per table in scope, outermost first
     */
    private function settleGap(array $repeaters): void
    {
        $dropped = $this->cursor - ($this->pageHeight - $this->reserveOn($this->page));

        if ($dropped <= 0.01) {
            return;
        }

        $before = $this->page;
        $this->newPage();

        if ($this->page === $before) {
            return;
        }

        $this->replayRepeaters($repeaters);

        // The box's own extent still counts the whole margin, because layout
        // ran before any of this. Recording what was dropped is what stops the
        // containing box buying it back: without it, `<div>top</div><div
        // style="margin-top:1000pt">bottom</div>` puts the second block at the
        // top of page 2 and then tops the container up to 1,021pt, so the
        // document is four pages with two of them blank. See the shortfall in
        // {@see splitContainer}.
        $this->gapsTruncated += $dropped;
    }

    /**
     * Honor a `break-before`/`break-after: page`.
     *
     * A break at the very top of a page is skipped rather than obeyed: a
     * forced break means "start this on a fresh page", and a page nothing has
     * been placed on yet already is one. Obeying it there would put a blank
     * page in front of every document whose first element asks for a break.
     *
     * **The cursor cannot answer "has anything been placed here".** A box
     * whose own height is zero leaves the cursor at the page top after the box
     * and everything inside it has already been put on the page, so every
     * forced break that followed read the page as untouched and did nothing.
     * Three symptoms came out of that one cursor: the break itself, the page
     * type the flow was leaving, which then overwrote the type at the same
     * page index, and the next sibling drawn on top of the zero-height box's
     * own content. `YA-break-after-zero-height.html` is three lines drawn on
     * page 2 with the cursor still at 0.00, Chrome writes 3 pages and this
     * wrote 2, and `YB-break-after-auto-height.html` is the same document with
     * `height: auto` and agrees. Defect IP.
     *
     * **The page's own fragment list is what knows, and it says yes to a box
     * that drew nothing.** `YC-break-after-zero-height-empty.html` puts an
     * EMPTY zero-height box on page 2 and Chrome writes a genuinely blank page
     * for it before starting page 3, so the question is whether a box was
     * placed here and never whether any ink reached the paper.
     *
     * **The cursor was wrong in the other direction too, and that half buys a
     * blank page rather than losing one.** A MARGIN above the first box moves
     * the cursor down a page nothing has been placed on, and the break then
     * fires with nothing to move away from:
     * `YG-break-under-a-margin-only.html` is one box under an 18pt margin
     * carrying `break-before: page`, Chrome writes ONE page and this wrote two
     * with the first blank. `cdoc-1-137` is the census document that found it,
     * where the two halves canceled in the page count until the first was
     * fixed. So the fragment list is the whole of the question and the cursor
     * is no part of it.
     *
     * @param Node[][] $repeaters one run per table in scope, outermost first
     */
    private function forcePageBreak(float $x, array $repeaters, ?Node $exclude = null): void
    {
        if ($this->exhausted) {
            return;
        }

        if (($this->pages[$this->page] ?? []) === []) {
            return;
        }

        $before = $this->page;
        $this->newPage();

        if ($this->page !== $before) {
            $this->replayRepeaters($repeaters, $exclude);
        }
    }

    /** @param Node[][] $repeaters one run per table in scope, outermost first */
    private function splitContainer(Node $n, float $x, array $repeaters): void
    {
        if ($this->depth > $this->limits->maxDepth) {
            return;
        }

        $this->depth++;

        $startPage = $this->page;
        $startY    = $this->cursor;
        // The band an ANCESTOR holds back for its repeating `<tfoot>`, captured
        // before this box reserves its own: a table paints through its own
        // footer band and everything under it stops short of it.
        $holdBack  = $this->footerReserve;
        // Margin dropped at a fold before this box started, so the walk's own
        // share of it can be read as a delta. {@see settleGap}.
        $droppedAtStart = $this->gapsTruncated;

        // Paint the container's own background as a fragment covering only
        // what it occupies on this page. The continuation gets its own.
        $decoStart   = $this->cursor;
        $decoPage    = $this->page;
        $decoOffset  = 0.0;
        $decoProxies = [];

        $ownRun = array_values(
            array_filter(
                $n->children,
                fn(Node $c) => $c->repeatOnBreak,
            ),
        );

        // This box's own run is **added** to the runs already in scope, not
        // put in their place: a table in a cell of another table repeats both
        // headers on a continuation page, outermost first, and replacing the
        // list is what dropped the outer one (defect BX, `S7` and `U1`).
        $allRepeaters = $ownRun === [] ? $repeaters : array_merge($repeaters, [$ownRun]);
        $ownKey       = $ownRun === [] ? null : $this->repeaterKey($ownRun);

        $affected = $this->pushEffects($n, $x, $this->cursor, $n->layoutWidth, $n->layoutHeight);
        $clipped  = $this->pushClip($n, $x, $this->cursor, $n->layoutWidth, $n->layoutHeight);

        $feet = array_values(array_filter(
            $n->children,
            static fn(Node $c): bool => $c->repeatAtBottom,
        ));

        // The container continued onto one or more new pages: close the
        // decoration on every page it left behind and open a fresh one. A page
        // turns as readily between two children as inside one, and as readily
        // in the shortfall top-up below as inside the walk, so every place the
        // page can move asks this the same question.
        // Where this box's own content resumes on a continuation page. It is
        // where its OWN run was laid down for the box that **holds** one,
        // whose background runs behind its own repeated header (Chrome's
        // answer on `S2-fold-thead-tablebg.html`), and the page's floor for
        // everything under it, whose content the header pushes down.
        // A cell reopening at 0.000 painted its continuation **over** the
        // header rather than under it, and 30.000 of background for the
        // 15.000 that was left (defect BU, `R2` and `S1`).
        //
        // Those two were the same number until a table could sit in a cell of
        // another: an inner table holds a run AND is under one, and Chrome
        // starts its background at **15.000** on `OA-nested-thead-bg.html`,
        // under the outer header and behind its own. A box that holds the only
        // run on the page still reads 0.000, because that is where its run was
        // laid down.
        $underRepeaters = $repeaters !== [];

        // Where a box with a used `height` ends when a forced break inside it
        // leaves the page it started on unfinished. Null until such a break
        // fires, and once it is set the box has exactly one fragment of
        // decoration: the pages the walk goes on to visit are the
        // continuation's, and Chrome paints none of the box on them. Defect IF.
        $cap = null;

        $closePagesLeft = function () use ($n, $x, $underRepeaters, $ownKey, $holdBack, &$cap, &$decoPage, &$decoStart, &$decoOffset, &$decoProxies): void {
            if ($cap !== null) {
                return;
            }

            if ($decoPage >= $this->page) {
                return;
            }

            for ($p = $decoPage; $p < $this->page; $p++) {
                $decoProxies[] = $this->closeDecoration($n, $x, $p, $decoStart, false, $decoOffset, $holdBack);
                $decoOffset += max(0.0, $this->pageHeight - $holdBack - $this->reserveOn($p) - $decoStart);
                $decoStart  = match (true) {
                    $ownKey !== null => $this->repeaterFloors[$p + 1][$ownKey] ?? 0.0,
                    $underRepeaters  => $this->pageFloors[$p + 1] ?? 0.0,
                    default          => 0.0,
                };
            }

            $decoPage = $this->page;
        };

        // A row flex container's bands are beside one another, not after one
        // another: an item that does not share the line's top is a band of its
        // own and belongs at its own offset from the container's top, wherever
        // the item next to it reached. Advancing to the furthest point instead
        // put a centred item under its neighbor's continuation rather than at
        // 0.000 on the page after it (defect BT, `ZJ` and `ZK`). The container
        // still ends at the furthest point of all of them, which is what the
        // per-item loop below does within one band.
        $sideBySide = $n->display === 'flex' && $n->isRow();

        // A row of columns is a band whose items are NOT all at its own top:
        // each sits at its own offset down its own column, where a flex line's
        // items really do share one. Defect HM.
        $ownOffsets = count($n->columnBoxes['rows'] ?? []) > 1;

        // Whether a forced break inside this box is what carried it onto a
        // later page, which the overshoot correction at the foot of this
        // method has to know. It is the same question, in the same words, that
        // {@see splitGrid}'s `rowspan` correction has asked since round 79:
        // a box cut by a break really does cover the distance it traveled,
        // where a box whose side-by-side item simply reached further does not.
        // Defect IN.
        $forcedInside = false;

        $walk = function (array $children) use ($n, $x, $allRepeaters, $holdBack, &$cap, &$startPage, &$startY, &$decoPage, &$decoStart, &$forcedInside, $sideBySide, $ownOffsets, $closePagesLeft): void {
            $prevBottom = null;
            $bands      = $this->flowBands($n, $children);

            $fromPage   = $startPage;
            $fromCursor = $startY;
            $fromTop    = 0.0;
            $farPage    = $startPage;
            $farCursor  = $startY;

            foreach ($bands as $i => [$relTop, $height, $items]) {
                // Whether what was just added is a margin between two siblings,
                // which truncates at a fold, or a box's own offset, which does
                // not. {@see settleGap}.
                $isGap = false;

                if ($sideBySide) {
                    $this->page   = $fromPage;
                    $this->cursor = $fromCursor + ($relTop - $fromTop);
                } elseif ($prevBottom === null) {
                    $this->cursor += $relTop;
                } else {
                    $this->cursor += max(0.0, $relTop - $prevBottom);
                    $isGap        = true;
                }

                $bandX = $x + ($items[0]->x - $n->x);

                $isGap
                    ? $this->settleGap($allRepeaters)
                    : $this->settleCursor($bandX, $allRepeaters);

                // The band's own start, which the band after it counts from.
                // Read back after {@see settleCursor}, so an offset that runs
                // past the bottom of the page carries onto the next one:
                // `ZK`'s item is 22.500 down a container 21.000 of which fitted,
                // and Chrome puts it 1.500 into the page after.
                if ($sideBySide) {
                    $fromPage   = $this->page;
                    $fromCursor = $this->cursor;
                    $fromTop    = $relTop;
                }

                // A band that is not a flex line holds one item, so asking the
                // first of them asks all of them. A flex line holds several
                // side by side, and Chrome starts a new page before the WHOLE
                // line when any one of them asks for one:
                // `VV-page-named-flex.html` names the second item of a two-item
                // row and Chrome paginates 3 pages on sheets 540, 270, 540
                // where asking `$items[0]` alone gave 2 with no cover sheet.
                // Defect ID.
                //
                // The first item that asks is the one that answers, in the same
                // ascending-`y`-then-`order` sequence the band was built in, so
                // a line naming two pages takes the name of the item a reader
                // meets first.
                $asks = $items[0];

                if ($sideBySide) {
                    foreach ($items as $item) {
                        if ($item->breakBefore === 'page' || $this->startsAPageType($item)) {
                            $asks = $item;

                            break;
                        }
                    }
                }

                $entersType = $this->startsAPageType($asks);

                // A forced break under any item of this band, or on the band
                // itself, is a page this box legitimately used. Read before the
                // band is placed, for the same reason {@see splitGrid} reads
                // it before its own band walk runs.
                foreach ($items as $item) {
                    if ($item->breakBefore === 'page'
                        || $item->breakAfter === 'page'
                        || $this->startsAPageType($item)
                        || $this->breaksInside($item, $n)
                    ) {
                        $forcedInside = true;

                        break;
                    }
                }

                if ($asks->breakBefore === 'page' || $entersType) {
                    // Whether this box has put anything at all on the page it
                    // is about to leave, read before the break because the
                    // break is what makes the two disagree.
                    $fresh     = $this->page === $startPage && $this->cursor - $startY < 0.01;
                    $wasOn     = $this->page;

                    $this->forcePageBreak($bandX, $allRepeaters, $n);

                    // A break before this box put anything down starts the box
                    // on the page it turned to, so that is the page it is
                    // measured and painted from. The room it gave up belongs to
                    // no box: nothing of this one reached it.
                    //
                    // A break with content above it is the other case and keeps
                    // its start where it was, which is Chrome's answer on
                    // `WA-break-height.html`: a 240pt box holding a 45pt
                    // paragraph and then a break keeps the whole 240pt on the
                    // page it started on and gives its continuation none of it.
                    //
                    // Without this the traveled distance below read the 240pt
                    // a flex line's break gave up as a band overflowing the
                    // line by 240pt, wound the cursor back to the top of the
                    // page it had just turned to, and the break OUT of the
                    // named run then fell at cursor 0 where it does nothing:
                    // the run lost both its own sheet and the break at its end
                    // (defect ID).
                    if ($fresh && $this->page !== $wasOn) {
                        $startPage  = $this->page;
                        $startY     = $this->cursor;
                        $decoPage   = $this->page;
                        $decoStart  = $this->cursor;
                        $fromPage   = $this->page;
                        $fromCursor = $this->cursor;
                        $farPage    = $this->page;
                        $farCursor  = $this->cursor;
                    } elseif ($cap === null && $n->height !== null && $this->page !== $wasOn) {
                        // The other half of the same sentence, and it is where
                        // the box ENDS rather than where it starts. A used
                        // `height` is spent on the page the box started on and
                        // the continuation is given none of it, so the flow
                        // after the box resumes at the box's own bottom edge
                        // rather than after the break.
                        //
                        // `WF-break-height-fits.html` is the reading: Chrome
                        // pages `a1 c1 d1 e1 f1` then `b1` and paints the box's
                        // band at its own 144.000 with no band at all on the
                        // page after, where this walked on from the break and
                        // painted 288.000 on one page and 36.000 on the next.
                        // `WG-break-noheight.html` and
                        // `WH-break-minheight.html` are the controls and both
                        // agree with Chrome untouched: with no used height
                        // Chrome fills the page it leaves too, so a floor is
                        // not the trigger and neither is the break (defect IF).
                        $ends = $startY + $n->layoutHeight;

                        if ($ends <= $this->pageHeight - $holdBack - $this->reserveOn($startPage) + 0.01) {
                            $cap = [$startPage, $ends];
                        }
                    }
                }

                if ($entersType) {
                    $this->enterPageType($asks->pageName);
                }

                // A repeating header is never the last thing on a page. Chrome
                // places it and the row that follows it together or not at all:
                // `R1-fold-thead-stranded.html` paints **nothing** on the page
                // the header would have been stranded on, where this painted a
                // header with no row under it and then repeated it on the next
                // page. It is the same question the fold asks everywhere else,
                // asked of the band **after** this one, because a header's
                // reason to be on a page is the row it heads.
                //
                // The whole run of header rows goes with it, so the question is
                // asked once, on the first of them, about the first band that
                // is not a header. Nothing is replayed on the page it turns to:
                // the walk is about to place the header itself, and replaying
                // would paint it twice (`R1` had it at 0.000 and again at
                // 15.000).
                if ($items[0]->repeatOnBreak
                    && ($i === 0 || !$bands[$i - 1][2][0]->repeatOnBreak)) {
                    $last      = $i;
                    $runBottom = $relTop + $height;

                    while (isset($bands[$last + 1]) && $bands[$last + 1][2][0]->repeatOnBreak) {
                        $last++;
                        $runBottom = $bands[$last][0] + $bands[$last][1];
                    }

                    if (isset($bands[$last + 1])) {
                        [$nextTop, , $nextItems] = $bands[$last + 1];

                        $room = $this->remaining()
                            - ($runBottom - $relTop)
                            - max(0.0, $nextTop - $runBottom);

                        $lands = false;

                        foreach ($nextItems as $next) {
                            if ($this->landsHere($next, $room)) {
                                $lands = true;

                                break;
                            }
                        }

                        if (!$lands) {
                            // The same question the forced-break site above
                            // asks: has this box put anything at all on the
                            // page it is leaving. A header run that strands
                            // turns the page before the box has laid down one
                            // band, so the room it gives up belongs to no box.
                            $hdrFresh = $this->page === $startPage && $this->cursor - $startY < 0.01;
                            $hdrWasOn = $this->page;

                            $this->forcePageBreak($bandX, [], $n);

                            if ($hdrFresh && $this->page !== $hdrWasOn) {
                                $startPage  = $this->page;
                                $startY     = $this->cursor;
                                $decoPage   = $this->page;
                                $decoStart  = $this->cursor;
                                $fromPage   = $this->page;
                                $fromCursor = $this->cursor;
                                $farPage    = $this->page;
                                $farCursor  = $this->cursor;
                            }
                        }
                    }
                }

                // Every item of a band starts where the band starts, and the
                // band ends wherever the furthest of them reached. Advancing
                // once per item instead would count the same space twice and
                // print side-by-side items one under the other.
                $bandPage   = $this->page;
                $bandCursor = $this->cursor;
                $endPage    = $bandPage;
                $endCursor  = $bandCursor;

                foreach ($items as $item) {
                    $itemX        = $x + ($item->x - $n->x);
                    $this->page   = $bandPage;
                    $this->cursor = $bandCursor;

                    // An item's own offset down its column is its place inside
                    // the band, not a margin, so what is left of it below the
                    // fold CARRIES onto the next page rather than truncating.
                    // That is the same answer {@see settleCursor} gives a
                    // staggered flex item, and without it two items of one
                    // column both landed at 0.000 on the page after.
                    if ($ownOffsets) {
                        $this->cursor += ($item->y - $n->y) - $relTop;
                        $this->settleCursor($itemX, $allRepeaters);
                    }

                    $this->placeNode($item, $itemX, $allRepeaters, $startPage, $n);

                    if ($this->page > $endPage || ($this->page === $endPage && $this->cursor > $endCursor)) {
                        $endPage   = $this->page;
                        $endCursor = $this->cursor;
                    }
                }

                $this->page   = $endPage;
                $this->cursor = $endCursor;

                // ANY item of the band, not the last one. A band that is not a
                // flex line holds one item, so the two readings only differ on
                // a flex line, and there Chrome breaks after the WHOLE line
                // when any one item asks: `XL-break-after-first-of-flexline.html`
                // puts the `break-after` on the first of two and Chrome pages
                // 2 where `end($items)` read 1. That is the same rule
                // `VV-page-named-flex.html` settled for `break-before` and a
                // page name, which this walk has asked of every item since
                // round 74 while `break-after` kept asking one. Defect IL.
                $breaksAfter = false;

                foreach ($items as $item) {
                    if ($item->breakAfter === 'page') {
                        $breaksAfter = true;

                        break;
                    }
                }

                if ($breaksAfter) {
                    $this->forcePageBreak($bandX, $allRepeaters, $n);
                }

                $closePagesLeft();

                if ($this->page > $farPage || ($this->page === $farPage && $this->cursor > $farCursor)) {
                    $farPage   = $this->page;
                    $farCursor = $this->cursor;
                }

                $prevBottom = $relTop + $height;
            }

            // The container reaches as far as the furthest of its bands, which
            // is not the last one when a band starting higher up is the taller
            // of the two. Leaving the cursor where the last band ended made the
            // shortfall top-up below buy the difference back a second time.
            if ($sideBySide && $bands !== []) {
                $this->page   = $farPage;
                $this->cursor = $farCursor;
            }
        };

        // Every fragment of a `clone` box wears the box's own border and
        // padding on all four sides, so the two edges are held out of the page
        // for as long as this box's own walk is running and given back before
        // the shortfall below, which is the last fragment spending them.
        $cloned = $n->decorationBreak === 'clone'
            ? [$n->edge('top'), $n->edge('bottom')]
            : [0.0, 0.0];

        $this->cloneTop     += $cloned[0];
        $this->cloneReserve += $cloned[1];

        if ($feet === []) {
            $walk($n->children);
        } else {
            $this->flowWithFooter($n, $feet, $x, $walk);
        }

        $this->cloneTop     -= $cloned[0];
        $this->cloneReserve -= $cloned[1];

        if ($clipped) {
            array_pop($this->clips);
        }

        if ($affected) {
            array_pop($this->effects);
        }

        $this->cursor += $n->edge('bottom');

        // The box ends at its own height on the page it started on, and
        // everything after it in the flow starts there. The pages the walk
        // visited after the break keep what they were given: they hold the
        // continuation's content and none of the box's own decoration, which
        // is why `$closePagesLeft` stopped emitting a proxy the moment this
        // was set. Defect IF.
        if ($cap !== null) {
            [$this->page, $this->cursor] = $cap;
        }

        // A box taller than its own content (an explicit height, or a row
        // stretched by a neighboring cell) still occupies that space.
        //
        // The shortfall has to be measured against how far the cursor actually
        // traveled, not against the children's unfragmented extents: once a
        // child spans a page the two diverge, and topping up from the wrong
        // one re-adds height at every level of nesting.
        $travelled = ($this->page - $startPage) * ($this->pageHeight - $holdBack) + ($this->cursor - $startY)
            - ($underRepeaters ? $this->repeatedBetween($startPage, $this->page) : 0.0)
            - $this->reservedBetween($startPage, $this->page);
        $own       = $n->layoutHeight;

        // A margin the fold truncated is extent this box no longer occupies,
        // so it counts as traveled: without it the top-up below buys back
        // every point the truncation saved and the document is the same length
        // it always was, with blank pages where the margin used to be.
        $travelled += $this->gapsTruncated - $droppedAtStart;

        // A band the walk moved whole can reach past the container's own
        // bottom: `ZM-fold-flexrow-flexend.html`'s item is 45.000 tall with
        // 39.000 of container left on the page it lands on, and Chrome lets it
        // overflow rather than growing the box to hold it. The cursor is where
        // everything after the container starts and where its decoration ends,
        // so it comes back to the height the box actually has.
        // **Not when a forced break inside is what crossed the page.** The
        // correction reads an overshoot as room the box never used, and a page
        // a forced break turned to is a page the box did use: pulling the
        // cursor back to the page floor then starts everything after this box
        // on top of the box's own content.
        // `XT-page-named-grid-in-flex.html` is the reading, a named block
        // inside a grid inside a flex row: the engine painted `a1` and `b1`
        // both at `y 240.000`, `a2` and `b2` both at `222.000` and `a3` and
        // `b3` both at `204.000`, three pairs of boxes on one page, where
        // Chrome writes three pages. It is the same scoping {@see splitGrid}'s
        // `rowspan` correction was given in round 79 and this walk never got.
        // `ZM-fold-flexrow-flexend.html` is the case the correction exists for
        // and it carries no forced break at all. Defect IN.
        if ($sideBySide && !$forcedInside && $travelled - $own > 0.01) {
            $back = min($travelled - $own, max(0.0, $this->cursor - ($this->pageFloors[$this->page] ?? 0.0)));

            $this->cursor -= $back;
            $travelled    -= $back;
        }

        // Hard bound: a box of height H can never need more than H of advance,
        // whatever the bookkeeping says. Without this, a cursor left in an
        // inconsistent state by a nested split turns a 424pt cell into a
        // request for a quarter of a million points.
        $shortfall = min($own - $travelled, $own);

        if ($shortfall > 0.01 && !$this->advanceTruncated && !$this->exhausted) {
            $this->advance($shortfall, $x, $repeaters);
        }

        $closePagesLeft();

        $decoProxies[] = $this->closeDecoration($n, $x, $decoPage, $decoStart, true, $decoOffset);
        $this->shrinkTruncatedRamp($decoProxies, $this->gapsTruncated - $droppedAtStart);
        $this->depth--;
    }

    /**
     * A box that spans a page break paints its own decoration through a proxy
     * on each page it reaches, so everything the painter draws behind and
     * around the content has to travel on the proxy. Anything left off is
     * silently dropped for exactly the boxes big enough to split, which is
     * where a full-page background is most likely to be.
     *
     * The proxy is handed back so the walk can shorten its background once it
     * knows what the fold truncated inside the box. {@see shrinkTruncatedRamp}.
     */
    private function closeDecoration(
        Node $n,
        float $x,
        int $page,
        float $startY,
        bool $final = false,
        float $sliceTop = 0.0,
        float $holdBack = 0.0,
    ): ?Node
    {
        if ($n->background === null
            && $n->border === null
            && $n->outline === null
            && $n->backgroundLayers === []
            && $n->boxShadow === []
        ) {
            return null;
        }

        // A slice the fold cuts runs to the bottom of the page, minus whatever
        // is held back there for a repeating `<tfoot>`: the band is not the
        // box's to paint into. `T1-tfoot-multipage.html`'s fourth row painted
        // 75.000 straight over its own footer where Chrome cuts it at 60.000.
        // A page whose own `@page` block shortened its content box holds back a
        // band of its own at the same end, and that one is not the box's either.
        $end                     = $final
            ? $this->cursor
            : $this->pageHeight - $holdBack - $this->reserveOn($page);
        $deco                    = new Node(['display' => 'rect']);
        $deco->background        = $n->background;
        $deco->backgroundLayers  = $n->backgroundLayers;
        $deco->boxShadow         = $n->boxShadow;
        $deco->border            = $n->border;
        $deco->borderRadius      = $n->borderRadius;
        $deco->outline           = $n->outline;
        $deco->outlineOffset     = $n->outlineOffset;
        $deco->padding           = $n->padding;
        $deco->visible           = $n->visible;
        $deco->slicedBackground  = [$sliceTop, $n->layoutHeight];
        $deco->decorationBreak   = $n->decorationBreak;
        // This piece is the box's own decoration, so it sorts where the box
        // does. Without it the proxy carries the empty path every new Node
        // starts with, which sorts under the whole page.
        $deco->stackPath         = $n->stackPath;

        // Which edges of the box this slice actually owns. The painter reads
        // it off these two flags, so a slice that starts partway down the box
        // draws no top border and one the fold cuts short draws no bottom.
        array_unshift(
            $this->pages[$page],
            new Fragment(
                $deco, $x, $startY, $n->layoutWidth, max(0.0, $end - $startY),
                [], $sliceTop > 0.01, !$final,
                effects: $this->decorationEffects($n, $x, $startY - $sliceTop),
            ),
        );

        return $deco;
    }

    /**
     * A gap the fold truncated is extent the box no longer has, so its
     * background image covers that much less of the paper.
     *
     * Layout runs before pagination, so every proxy above was opened at the
     * height layout gave the box and a gradient on it is stretched by whatever
     * the fold dropped: `SQ-ramp-margin.html` is a 540pt box losing 60pt, and
     * its ramp read 540pt long against Chrome's 480. The total is only known
     * once the walk is finished, which is why the proxies are corrected here
     * rather than written correctly in the first place. Every page's slice
     * keeps its own offset: the part that never landed is below them all.
     *
     * @param list<?Node> $proxies one per page the box reached
     */
    private function shrinkTruncatedRamp(array $proxies, float $dropped): void
    {
        if ($dropped <= 0.01) {
            return;
        }

        foreach ($proxies as $deco) {
            if ($deco?->slicedBackground === null) {
                continue;
            }

            [$sliceTop, $whole]      = $deco->slicedBackground;
            $deco->slicedBackground = [$sliceTop, max(0.0, $whole - $dropped)];
        }
    }

    /**
     * The subtree effects a box's own decoration is painted under, for the
     * proxy that carries it on one page.
     *
     * The proxy is a node of its own, so an `opacity`, a `mix-blend-mode` or a
     * `mask-image` on the box reaches it only if something puts it there.
     * Round 31 copied the mask onto the proxy node; carrying the box itself on
     * the chain instead is what puts the decoration in the **same**
     * transparency group as the box's children, which is the difference
     * between a faded panel composited once and a faded panel whose background
     * shows through its own contents.
     *
     * The rect is the whole box's, at the origin it would have on this page if
     * it started here, which is what a descendant carries and what
     * {@see BoxPainter::maskBand()} then cuts back to the page.
     *
     * @return list<array{0:Node,1:float,2:float,3:float,4:float}>
     */
    private function decorationEffects(Node $n, float $x, float $top): array
    {
        $chain = $this->effects;

        // The box's own entry is on the chain already while the walk is inside
        // it, at the origin it had on the page it **started** on, which is a
        // page height per fold out on every page after that. This proxy knows
        // where the box would start on the page it is closing, so its own
        // entry is taken off and put back rather than trusted.
        if ($chain !== [] && $chain[count($chain) - 1][0] === $n) {
            array_pop($chain);
        }

        if ($n->opacity < 1.0 || $n->blendMode !== 'normal' || $n->transform !== [] || $n->maskLayers !== []) {
            $chain[] = [$n, $x, $top, $n->layoutWidth, $n->layoutHeight];
        }

        return $chain;
    }
}
