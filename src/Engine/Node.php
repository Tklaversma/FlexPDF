<?php
declare(strict_types=1);

namespace FlexPDF\Engine;

/**
 * A box in the layout tree.
 *
 * Deliberately mirrors the shape of a Yoga node: resolved numeric style in,
 * absolute rect out. Text and images are "replaced"/measured leaves:
 * they expose a measure() callback and are otherwise opaque to the solver.
 */
final class Node
{
    /** The tag {@see linearLength} writes and {@see linearParts} reads. */
    private const string LINEAR = 'calc:';

    // ---- style (already computed; no cascade here) ----
    public string $display = 'block';     // block | flex | table | table-row | table-cell | text | rect
    public string $flexDirection = 'row'; // row | row-reverse | column | column-reverse

    // ---- positioning ----
    public string $float = 'none'; // none | left | right
    public string $clear = 'none'; // none | left | right | both

    /**
     * Set by a block-flow parent on a child that shares the parent's float
     * context, which CSS 2.1 §9.4.1 says is every in-flow block that
     * establishes no formatting context of its own.
     *
     * Such a child does not grow to contain its own floats (§10.6.3), and the
     * floats belong to the parent, so they reach the parent's following
     * siblings as exclusions and as something to `clear`. The default is the
     * safe one: a box nobody opened contains its own floats.
     */
    public bool $floatsEscape = false;

    /**
     * The float exclusions this box handed back to its parent, in its own
     * content box's coordinates.
     *
     * @var array<int,array{side:string,top:float,bottom:float,edge:float}>
     */
    public array $escapedFloats = [];

    /**
     * `display: flow-root`, whose only job in CSS is to establish a block
     * formatting context. It is the one display value that has to survive into
     * layout after the box mode has resolved to `block`.
     */
    public bool $flowRoot = false;

    /**
     * Set while a **row** flex item is laid out with its resolved main size,
     * which CSS Flexible Box §9.5 makes its used width. Its own `width`
     * declaration must not be resolved again against that size, or a
     * percentage resolves against the item instead of against the container.
     * Defect DS.
     */
    public bool $mainSizeIsUsedWidth = false;

    /**
     * Set on a float that was written **inside** a block's inline content, so
     * the runs before it are on a line of their own by the time it is placed.
     *
     * CSS 2.1 §9.5.1 rule 6: such a float's outer top may not be higher than
     * the top of the line box holding that earlier content, and Chrome puts it
     * exactly there. The builder flushes the pending runs into an anonymous
     * box and makes the float its next sibling, so the flow cursor is one line
     * too low by then and the float landed under the text instead of beside
     * it. Defect AW.
     */
    public bool $afterInlineContent = false;
    public string $position = 'static'; // static | relative | absolute | fixed
    public float|string|null $top = null;
    public float|string|null $right = null;
    public float|string|null $bottom = null;
    public float|string|null $left = null;
    /**
     * The declared `z-index`, or null for `auto`.
     *
     * The two cannot share a value. Both paint in the same place, and only
     * `0` makes a stacking context, so only `0` keeps a raised child inside
     * the box that declared it. `RI-stacking-steps.html` g8 and g9 are that
     * pair and Chrome renders them differently.
     */
    public ?int $zIndex = null;

    /**
     * The id of the `position: relative` **inline** element this out-of-flow
     * box is a descendant of, if any.
     *
     * An inline element has no box in this tree: it is flattened into runs, so
     * `placePositioned()`'s walk up the box tree cannot find it and the box
     * resolved against the block instead. CSS 2.1 section 10.1.4.1 makes it the
     * containing block all the same, and its rect is the bounding box of its
     * fragments, which only exists once the lines are laid out. The id is what
     * finds those fragments then.
     */
    public ?int $inlineContainer = null;

    /**
     * Where this box sorts in CSS 2.1 Appendix E's painting order.
     *
     * One `[z-index, step]` pair for every stacking context between the root
     * and this box, with the box's own pair last, and a serial number after
     * the pair on any box that other boxes sort inside. Compared term by
     * term, and a path that runs out first paints first.
     *
     * That comparison is Appendix E for a flat list of boxes, which is what
     * the paginated painter has to sort: it keeps a raised child inside the
     * lower parent that contains it, and it puts a positioned box over the
     * plain blocks written after it.
     *
     * Computed once after the tree is built, because a box has no parent
     * pointer to walk up at paint time. See
     * {@see BoxPainter::compareStack()}, which is the only thing that reads
     * it, and {@see HtmlBuilder::assignStackPaths()}, which fills it in.
     *
     * @var list<int>
     */
    public array $stackPath = [];

    /**
     * Where among its siblings this box was written, counting from 1.
     *
     * The order children are stored in is not always the order they were
     * written in: {@see HtmlBuilder::partition()} holds an out-of-flow child
     * back and appends it after the block's own content, because emitting it
     * where it stands would close the line it sits on. Paint order must not
     * inherit that, so the number the box was given while the source was read
     * is kept and {@see HtmlBuilder::assignStackPaths()} walks by it.
     *
     * An anonymous box holding runs of text takes the number the first of
     * those runs was written at, because {@see FlexLayout::staticFlowY()} reads
     * these numbers as positions and an out-of-flow box written before a
     * block's own inline content has only that box to take one from. Defect GI.
     *
     * Zero on every box nothing numbered, which is a drop cap, a list marker
     * and an anonymous cell's content. None of them can sort against an
     * out-of-flow sibling, because Appendix E puts them in a different
     * painting step.
     */
    public int $sourceOrder = 0;

    /** @var array<int,array{0:string,1:float,2:float}> transform ops, in order */
    public array $transform = [];
    public string $transformOrigin = '50% 50%';
    public float $opacity = 1.0;
    public string $overflow = 'visible'; // visible | hidden | clip

    /**
     * The computed `overflow-x` and `overflow-y`, with `scroll` and `auto`
     * folded into `hidden` because a PDF has no scrollbars: `visible` clips
     * nothing, `clip` clips that axis alone and `hidden` clips it and scrolls.
     *
     * They are per axis because Chrome clips per axis. `overflow-x: clip`
     * leaves the block axis alone, and the child of a 60x40px box overhanging
     * both edges is **45 x 60** in `ZZ-overflow-clip-axes.html` `w4`, where the
     * same child under `overflow: hidden` is 45 x 30 (`w1`) and under nothing
     * at all is 75 x 60 (`w0`).
     *
     * `overflow` above stays the "does this box clip at all" answer, which is
     * either axis not being `visible`.
     */
    public string $overflowX = 'visible'; // visible | clip | hidden
    public string $overflowY = 'visible'; // visible | clip | hidden

    /**
     * Whether the box is a **scroll container**, which is a different question
     * from whether it clips: CSS Overflow §3 makes `hidden`, `scroll` and
     * `auto` scroll containers and leaves `clip` out, and CSS Box Sizing §4.1
     * gives a scroll container's `min-*: auto` a value of **zero** where every
     * other box gets its content-based minimum.
     *
     * A PDF has no scrollbars, so `overflow` above answers "does this box clip"
     * and all four values are the same there. This one answers the sizing
     * question, and `ZW-row-automin-overflow.html` `v4` is why they cannot be
     * the same field: an `overflow: clip` flex item is **60.000** in Chrome,
     * its content's width, where an `overflow: hidden` one is 22.500.
     */
    public bool $scrollContainer = false;

    /**
     * `overflow-clip-margin`, the length a box at `overflow: clip` still paints
     * outside its own clip edge, and the box that length is measured from.
     *
     * It applies to `clip` ALONE, which is the only thing separating `clip`
     * from `hidden` anywhere a PDF can see: `TD-overflow-clip-margin.html` o4
     * declares the margin beside an `overflow: hidden` and Chrome ignores it.
     * Defect GM.
     */
    public float $overflowClipMargin = 0.0;

    public string $overflowClipBox = 'padding-box'; // content-box | padding-box | border-box

    /**
     * `text-overflow`. It is set on the block container but has to reach the
     * anonymous text box holding that block's inline content, since the line
     * boxes it truncates live there.
     */
    public string $textOverflow = 'clip'; // clip | ellipsis
    public string $blendMode = 'normal';

    /**
     * `isolation`, which asks for a box's descendants to blend with each other
     * and with nothing under the box.
     *
     * It is the only property whose whole effect is the group itself: it
     * pushes no graphics state and paints nothing, so a box that declares it
     * and holds no blend anywhere below is the same picture either way. That
     * is why {@see BoxPainter::makesGroup()} is the only place it is read, and
     * why a group opened for it still goes back on the page inline when
     * nothing inside it blended.
     */
    public string $isolation = 'auto'; // auto | isolate

    /**
     * `mask-image`, one entry per layer, each carrying the source and the
     * three properties that place it plus its `mask-mode`.
     *
     * A mask hides the box and everything inside it wherever the source is
     * transparent, so it travels with `opacity` and `mix-blend-mode` rather
     * than with the background layers: those paint one box and this covers a
     * whole subtree.
     *
     * Empty where the box has no mask, which is what every check reads: a
     * source that resolves to nothing leaves no layer behind, because CSS says
     * an image that fails to load masks nothing.
     *
     * `mask-origin`, `mask-clip` and `mask-composite` are not read. All three
     * are outside what this engine takes on, and the two boxes default to the
     * border box, which is the one this paints against.
     *
     * @var list<array<string,mixed>>
     */
    public array $maskLayers = [];

    public string $objectFit = 'fill'; // fill | contain | cover | none | scale-down

    /**
     * `object-position`, in the spelling `background-position` uses, because
     * the two resolve the same way: a percentage aligns that fraction of the
     * picture with the same fraction of the box, so `50% 50%` centres it and
     * is the initial value. It only shows where the placement leaves room,
     * which is every `object-fit` but `fill`.
     */
    public string $objectPosition = '50% 50%';

    /**
     * `background-clip` for the box's own `background-color`. The layers carry
     * their own, because both properties are per-layer lists, and CSS paints
     * the color in the area the last layer's clip names.
     */
    public string $backgroundClip = 'border-box'; // border-box | padding-box | content-box

    /**
     * `clip-path`, parsed into the shape the painter builds a PDF clipping
     * path from: `inset`, `circle`, `ellipse` or `polygon`.
     *
     * Every length is already in points; a percentage is kept as one, because
     * what it resolves against is the box's own size and that is not known
     * until paint time. The reference box is the border box, which is
     * `clip-path`'s initial `border-box`.
     *
     * It clips the whole subtree, so it is pushed in `pushEffects()` beside
     * the `overflow: hidden` clip rather than around the box's own ink.
     *
     * @var array<string,mixed>|null
     */
    public ?array $clipPath = null;

    /**
     * `text-shadow`, front to back: CSS paints the first of the list on top,
     * and all of them under the text itself.
     *
     * @var array<int, array{x:float,y:float,blur:float,color:array}>
     */
    public array $textShadow = [];

    /**
     * `box-shadow`, front to back: CSS paints the first of the list on top.
     * Layout never sees it: a shadow takes no space, exactly like an outline.
     *
     * @var array<int, array{x:float,y:float,blur:float,spread:float,inset:bool,color:array{0:float,1:float,2:float,3:float}}>
     */
    public array $boxShadow = [];

    /** Absolutely positioned descendants, hoisted out of normal flow. */
    public array $positioned = [];

    // ---- grid ----
    /** @var array<int,array{minType:string,minValue:float,maxType:string,maxValue:float}> */
    public array $gridTemplateColumns = [];

    /** @var array<int,array{minType:string,minValue:float,maxType:string,maxValue:float}> */
    public array $gridTemplateRows = [];
    public ?array $gridAutoColumns = null;
    public ?array $gridAutoRows = null;
    public string $gridAutoFlow = 'row'; // row | column
    public bool $gridDense = false;

    /** @var array<string,int> line name => zero-based line index */
    public array $gridColumnNames = [];

    public array $gridRowNames = [];

    /** @var array<string,array{0:int,1:int,2:int,3:int}> area name => row,col,rowSpan,colSpan */
    public array $gridAreas = [];

    /** Raw track lists, kept for auto-fill which needs the container size. */
    public string $gridColumnsRaw = '';
    public string $gridRowsRaw = '';
    public float $gridFontSize = 12.0;
    public float $gridRootFontSize = 12.0;

    /** Placement by area name, resolved once the areas are known. */
    public string $gridAreaName = '';
    public string $gridColumnStartName = '';
    public string $gridColumnEndName = '';
    public string $gridRowStartName = '';
    public string $gridRowEndName = '';
    public string $justifyItems = 'stretch';
    public ?string $justifySelf = null;

    /** Placement: line numbers are 1-based; null means auto. */
    public ?int $gridColumnStart = null;
    public ?int $gridColumnEnd = null;
    public int $gridColumnSpan = 1;
    public ?int $gridRowStart = null;
    public ?int $gridRowEnd = null;
    public int $gridRowSpan = 1;

    public int $columnCount = 1;
    public ?float $columnWidth = null;

    /**
     * `column-fill`, either `balance` or `auto`.
     *
     * `balance` gives every column the same share of the content, which is
     * what the initial value asks for. `auto` fills each column to the box's
     * own height before starting the next one, so a box with no height to
     * fill puts everything in the first column.
     */
    public string $columnFill = 'balance';

    /**
     * `column-span: all` on an in-flow child of a multi-column box.
     *
     * A spanner leaves the column flow: it takes the full content width, the
     * children before it fill a set of columns above it and the children
     * after it fill a set below. CSS computes it to `none` on a float and on
     * an out-of-flow box, which is why this is set from the used display
     * rather than from the declaration alone.
     */
    public bool $columnSpanAll = false;

    /**
     * `column-rule`, the stroke down each gutter. Same three components as an
     * `outline`, and like an outline it takes no space: the gutter is already
     * `column-gap` wide and the rule is centred in it whatever its own width.
     *
     * @var array{width:float,style:string,color:array{0:float,1:float,2:float,3?:float}}|null
     */
    public ?array $columnRule = null;

    /**
     * What the multi-column pass actually produced, which is the only place
     * the gutter positions exist: the count is what the content filled rather
     * than what `column-count` asked for, because CSS draws a rule only
     * between two columns that both hold something.
     *
     * `rows` is one entry per set of columns, which is more than one only when
     * a `column-span: all` child cuts the flow in two. `top` is measured from
     * the content box's own top edge.
     *
     * @var array{count:int,width:float,gap:float,height:float,rows:list<array{count:int,top:float,height:float}>}|null
     */
    public ?array $columnBoxes = null;

    /**
     * The multi-column box's children with every spanner hoisted to the top of
     * it, which is the list the column runs are filled from.
     *
     * It is worked out once and kept, because hoisting is tree surgery rather
     * than layout: it moves a `column-span: all` box out of the wrapper it was
     * written in and splits the wrapper around it, and doing that twice would
     * hoist a box that is already hoisted. Cutting a child at a column
     * boundary is the opposite and is redone on every pass, because where the
     * boundary falls is a measurement.
     *
     * @var list<Node>|null
     */
    public ?array $columnFlow = null;

    /**
     * Which of the box's four edges this box still owns, for a piece a column
     * boundary cut. Null is the whole box, which is every box a fragmentation
     * context did not touch.
     *
     * A page break carries the same fact on the `Fragment` instead, because a
     * page is a piece of one node and a column is a node of its own: the piece
     * that did not fit is a second box in the tree, so the fact has to live
     * where the box does. `box-decoration-break: clone` overrides both, which
     * is what that value means.
     *
     * @var string[]|null
     */
    public ?array $fragmentEdges = null;

    public float $rowGap = 0.0;
    public float $columnGap = 0.0;

    /**
     * `container-type`, one of `normal`, `inline-size` or `size`.
     *
     * Only the last two establish a query container, and only `size` can
     * answer a question about the block axis. Nothing in layout reads this:
     * the containment it implies is not modeled, and what the box is here for
     * is to carry its used size back to the cascade after layout has one.
     *
     * @see ContainerQuery
     */
    public string $containerType = 'normal';

    /** @var string[] the `container-name` list this box answers to */
    public array $containerNames = [];

    // ---- table ----
    public int $colspan = 1;
    public int $rowspan = 1;
    public string $verticalAlign = 'top'; // top | middle | bottom
    public string $borderCollapse = 'separate';

    /**
     * `table-layout`. Under `fixed` the column widths come from the table and
     * its first row and the content never widens them, which is what makes a
     * long cell wrap instead of stretching its column.
     */
    public string $tableLayout = 'auto'; // auto | fixed
    /**
     * `border-spacing` is two lengths, horizontal then vertical, and one value
     * sets both. Chrome's UA sheet gives a table 2px of each; CSS's initial
     * value is 0, which is what a `display: table` box that is not a `<table>`
     * element gets.
     */
    public float $borderSpacingX = 0.0;
    public float $borderSpacingY = 0.0;
    /**
     * The anonymous block CSS 2.1 §17.4 puts around a table and its
     * `<caption>`. Its width is the table's used width, not the container's,
     * which is what gives the caption the table's width to fill.
     */
    public bool $isTableWrapper = false;

    /**
     * Which of a table's three sections this row was written in. Membership is
     * not the same question as whether the section repeats: Chrome repeats the
     * header section only when its element is a `<thead>`, so a `<tbody>`
     * acting as one is a header that stays where it is
     * (`Z4-fold-group-header-tbody.html`). §17.5.3 keeps a surplus height away
     * from both sections either way, which is what this pair is read for.
     */
    public bool $isHeaderRow = false;
    public bool $isFooterRow = false;

    /**
     * How far `vertical-align` pushed this cell's content down inside the row,
     * which a fold has to be able to give back: Chrome aligns a fragmented
     * row's cells to the top of the fragment, so a `middle` cell reads as
     * `top` the moment the row is cut (defect CF). `TableLayout` shifts the
     * children rather than storing an offset the painter applies, so the
     * distance has to be recorded for the fragmenter to undo it.
     */
    public float $cellShift = 0.0;

    /**
     * Which row group this row was written in. Groups are flattened away
     * everywhere else, but CSS 2.1 §17.5.3 hands a table's surplus height to
     * its *sections* before its rows, and Chrome measurably does the same:
     * `W6-table-height-more.html`'s `b1` is 60 / 84 / 24 across two `<tbody>`s
     * where one flat pool of rows gives 60 / 54 / 54.
     */
    public ?int $rowGroup = null;

    /**
     * That group's own `height`, which CSS 2.1 §17.5.3 makes a minimum for the
     * section exactly as a table's is for the table: whatever the group has
     * over what its rows asked for is shared between them, and the section is
     * pinned against the table's own surplus while it happens. Carried on the
     * rows because the group box itself is flattened away (defect DC).
     */
    public float|string|null $rowGroupHeight = null;

    /**
     * A row that is not a row: the band a row group with **no** rows in it
     * still occupies, which is where its declared height goes. It takes no
     * border spacing of its own, which is what Chrome's `ga` at y 0.000 in a
     * `border-spacing: 10px` table says.
     */
    public bool $isGroupBand = false;

    /**
     * The row group's own styled box, shared by every row written inside it,
     * or null where the group asks for no ink.
     *
     * The group is flattened away everywhere else, so this box is never in the
     * tree and never laid out: {@see Fragmenter::bandRowGroups()} gives it a
     * rect per page from the rows that landed there, which is what makes a
     * group that crosses a fold paint on both pages without being sliced.
     * Defect DK.
     */
    public ?Node $rowGroupBox = null;

    /** @var array<int,float|string> explicit widths from <col> */
    public array $columnWidths = [];

    /**
     * Per-column borders from `<col>` and from `<colgroup>`, keyed by column
     * index. CSS 2.1 §17.6.2.1 makes a column and a column group two of the
     * six boxes competing for a collapsed grid line, and neither has a box of
     * its own in this tree to carry one, which is why the table holds them.
     * A column's perimeter is its left and right edges on every row plus the
     * top of the first row and the bottom of the last, so these compete for
     * some of a table's lines and not for all of them. Defect HS.
     *
     * Both are empty in the separated model, where §17.6.1 gives a column no
     * border at all.
     *
     * @var array<int,array<string,array{width:float,style:string,color:array{0:float,1:float,2:float,3:float}}>>
     */
    public array $columnBorders = [];

    /** @var array<int,array<string,array{width:float,style:string,color:array{0:float,1:float,2:float,3:float}}>> */
    public array $columnGroupBorders = [];

    public string $flexWrap = 'nowrap'; // nowrap | wrap | wrap-reverse

    public string $justifyContent = 'flex-start';
    public string $alignItems = 'stretch';
    public ?string $alignSelf = null;

    /**
     * How the *lines* of a multi-line container share the cross axis, as
     * opposed to `align-items`, which places an item within its line. CSS
     * Flexible Box §9.6: the initial value is `stretch`, so two lines in a
     * container with room to spare grow to fill it rather than packing.
     * `GridLayout` reads it as the block-axis half of its own distribution.
     */
    public string $alignContent = 'stretch';

    public float $flexGrow = 0.0;
    public float $flexShrink = 1.0;
    public float|string $flexBasis = 'auto'; // number | 'auto' | '50%'

    /**
     * `order`. It reorders a flex item within its container without moving the
     * box in the tree, so the document keeps its reading order and only the
     * flex algorithm sees the new sequence.
     */
    public int $order = 0;

    public float|string|null $width = null;
    public float|string|null $height = null;
    public ?float $minWidth = null;
    public ?float $maxWidth = null;
    public ?float $minHeight = null;
    public ?float $maxHeight = null;

    public float $gap = 0.0;

    /** @var array{top:float,right:float,bottom:float,left:float} */
    public array $margin  = ['top'=>0.0,'right'=>0.0,'bottom'=>0.0,'left'=>0.0];
    public array $padding = ['top'=>0.0,'right'=>0.0,'bottom'=>0.0,'left'=>0.0];

    /** content-box | border-box. CSS defaults to content-box. */
    public string $boxSizing = 'content-box';

    /**
     * Which margins were declared `auto`, per side. Auto margins absorb the
     * free space in their axis before `justify-content` sees any of it.
     *
     * @var array{top:bool,right:bool,bottom:bool,left:bool}
     */
    public array $autoMargin = ['top'=>false,'right'=>false,'bottom'=>false,'left'=>false];

    /**
     * Margins and paddings that need the containing block, in the authored
     * form {@see resolveLength} reads. All four sides resolve against its
     * *width*, including the vertical ones, which is CSS 2.1 §8.3 and §8.4,
     * and it is what makes a percentage padding a usable aspect-ratio trick.
     *
     * @var array{top:float|string|null,right:float|string|null,bottom:float|string|null,left:float|string|null}
     */
    public array $marginPercent  = ['top'=>null,'right'=>null,'bottom'=>null,'left'=>null];
    public array $paddingPercent = ['top'=>null,'right'=>null,'bottom'=>null,'left'=>null];

    /**
     * The declared min/max sizes, kept in their authored form. A percentage
     * needs the containing block, and box-sizing needs the padding and border,
     * neither of which the box tree knows while it is being built.
     */
    public float|string|null $declaredMinWidth  = null;
    public float|string|null $declaredMaxWidth  = null;
    public float|string|null $declaredMinHeight = null;
    public float|string|null $declaredMaxHeight = null;

    /**
     * Fill in everything that could only be resolved once the containing block
     * was known: percentage margins and paddings, and the min/max sizes.
     *
     * A percentage against an indefinite height has no answer, so a height
     * constraint expressed that way is dropped, which is what CSS asks for.
     */
    public function resolveAgainstContainingBlock(float $width, ?float $height = null): void
    {
        foreach (['top', 'right', 'bottom', 'left'] as $side) {
            $margin = $this->resolveLength($this->marginPercent[$side], $width);

            if ($margin !== null) {
                $this->margin[$side] = $margin;
            }

            $padding = $this->resolveLength($this->paddingPercent[$side], $width);

            if ($padding !== null) {
                $this->padding[$side] = max(0.0, $padding);
            }
        }

        $this->resolveConstraints($width, $height);
    }

    /**
     * A declared length that needed the containing block, against that block.
     *
     * Three forms reach here and the third is defect DI's. A plain number is
     * already in points. A percentage is a fraction of the basis. A `calc()`
     * holding a percentage is the `A + B%` pair the builder reduced it to,
     * because CSS only lets a percentage be added, subtracted or multiplied by
     * a plain number, so every such expression is **linear** in the basis and
     * two constants say all of it. `min()`, `max()` and `clamp()` are not
     * linear and are deliberately not reduced this way: they never reach here
     * and stay unsupported, exactly as they were.
     *
     * The result goes through the `max_length` ceiling, because a percentage
     * is a number the document chose and the basis is another.
     */
    public function resolveLength(float|string|null $value, ?float $basis): ?float
    {
        if ($value === null || $value === 'auto') {
            return null;
        }

        if (!is_string($value)) {
            return $this->clampLength((float) $value);
        }

        if (str_ends_with($value, '%')) {
            return $basis === null
                ? null
                : $this->clampLength((float) rtrim($value, '%') / 100.0 * $basis);
        }

        $parts = self::linearParts($value);

        if ($parts !== null) {
            return $basis === null
                ? null
                : $this->clampLength($parts[0] + $parts[1] / 100.0 * $basis);
        }

        return $this->clampLength((float) $value);
    }

    /**
     * `A + B%` in the one channel a declared length already travels in.
     *
     * It deliberately does **not** end in `%`, so every reader that has not
     * been taught the pair drops the declaration exactly as it does today
     * rather than reading a number out of it.
     */
    public static function linearLength(float $constant, float $percent): string
    {
        return self::LINEAR . $constant . ':' . $percent;
    }

    /** @return array{0:float,1:float}|null the constant, in points, and the percentage */
    private static function linearParts(string $value): ?array
    {
        if (!str_starts_with($value, self::LINEAR)) {
            return null;
        }

        $parts = explode(':', substr($value, strlen(self::LINEAR)));

        return count($parts) === 2 ? [(float) $parts[0], (float) $parts[1]] : null;
    }

    private function clampLength(float $value): ?float
    {
        if (!is_finite($value)) {
            return null;
        }

        return max(-$this->maxLength, min($this->maxLength, $value));
    }

    /**
     * `min-width` and the three like it, against a containing block.
     *
     * Edges are settled by the time this runs, so the box-sizing conversion is
     * meaningful. A node built by hand may carry a used min/max directly and
     * no declared one; that is the caller's final word, so leave it.
     *
     * Split out of {@see resolveAgainstContainingBlock} for the table cell,
     * which is measured by TableLayout rather than laid out by its row and so
     * is the one box nothing hands a containing block to.
     */
    public function resolveConstraints(float $width, ?float $height = null): void
    {
        foreach (['MinWidth', 'MaxWidth', 'MinHeight', 'MaxHeight'] as $constraint) {
            $declared = $this->{"declared$constraint"};

            if ($declared === null) {
                continue;
            }

            $horizontal = str_ends_with($constraint, 'Width');

            $this->{lcfirst($constraint)} = $this->usedConstraint(
                $declared,
                $horizontal ? $width : $height,
                $horizontal,
            );
        }
    }

    private function usedConstraint(float|string|null $declared, ?float $basis, bool $horizontal): ?float
    {
        if ($declared === null) {
            return null;
        }

        if (is_string($declared)) {
            $used = $this->resolveLength($declared, $basis);

            if ($used === null) {
                return null;
            }

            $declared = $used;
        }

        if ($declared < 0) {
            return null;
        }

        return $horizontal
            ? $this->toBorderBoxWidth($declared)
            : $this->toBorderBoxHeight($declared);
    }

    // ---- paint-only properties (ignored by layout) ----

    /*
     * Colors are [r, g, b, a] in 0..1. The alpha rides along as a fourth
     * component so every consumer that destructures three keeps working, and
     * the painter reaches for it only when it is below 1.
     */
    public ?array $background = null;

    /**
     * The `background-image` layers, **back to front**: CSS writes them
     * first-on-top, so the list is reversed on the way in and the painter
     * walks it in order. Each layer is a raster, an SVG or a gradient, and
     * carries the `background-size`, `-position` and `-repeat` belonging to
     * it, since those are comma lists of their own that cycle over the
     * layers. Every path resolves through `Support/AssetPath`, like every
     * other file a document names.
     *
     * @var array<int, array{image:?PdfImage,svg:?SvgDocument,gradient:?array,size:string,position:string,repeat:string}>
     */
    public array $backgroundLayers = [];

    /**
     * The background CSS propagates to the canvas, on the root box alone.
     *
     * CSS Backgrounds 3 section 2.11.2: the root element's background becomes
     * the canvas background and its painting area covers the whole canvas.
     * Where the root element declares none, the value is taken from its
     * `<body>` child instead, and that child is then painted as if it had
     * declared none. Chrome's printed canvas is the page area inside the
     * `@page` margin rather than the sheet, which is what
     * `PQ-root-paint-pagemargin.html` measures.
     *
     * @var array<int, array{image:?PdfImage,svg:?SvgDocument,gradient:?array,size:string,position:string,repeat:string}>
     */
    public array $canvasBackgroundLayers = [];

    public ?array $canvasBackground = null;

    /**
     * For one page's slice of a box that spans a break: how far into the box
     * the slice starts, and how tall the whole box is. A background belongs
     * to the box rather than to the slice, so a gradient carries on across
     * the fold instead of restarting, which is what a browser prints.
     *
     * @var array{0:float,1:float}|null
     */
    public ?array $slicedBackground = null;

    /**
     * `box-decoration-break`, `slice` or `clone`.
     *
     * Under `clone` every fragment of a box the fold cut draws all four of its
     * own edges and paints its own background from the start rather than a
     * band of one, which is what closes a bordered panel's border on both
     * pages. Chrome moves 4,103 pixels of 36,000 on the first page of
     * `RY-fold-clone.html` and 15,900 on the second between the two spellings.
     *
     * **The layout half of `clone` is not built**, and it has a row of its own:
     * CSS applies the border and the padding to every fragment, which makes
     * the box taller and moves the content down on each page after the first.
     */
    public string $decorationBreak = 'slice';
    public ?array $color = null;
    /**
     * The four corners in CSS order: top-left, top-right, bottom-right,
     * bottom-left, **each a horizontal half and a vertical one**. It held one
     * float until the multi-value shorthand turned out to resolve to zero,
     * which squares off exactly the card-with-a-rounded-top pattern that
     * spells it that way, and it held four until the `/` form turned out to be
     * drawn as a circle. Defect GL.
     *
     * @var list<array{0:float,1:float}>
     */
    public array $borderRadius = [[0.0, 0.0], [0.0, 0.0], [0.0, 0.0], [0.0, 0.0]];

    /**
     * `aspect-ratio`, as width divided by height. It sizes whichever axis is
     * automatic from the one that is not, so layout reads it rather than the
     * painter. `auto` and a replaced element's own ratio both leave it null,
     * because an image already sizes from its intrinsic proportions.
     */
    public ?float $aspectRatio = null;

    /**
     * The ceiling `aspect-ratio` may size a box to. A ratio multiplies an
     * author-controlled length by an author-controlled factor, so without it
     * a document reaches sizes `max_length` exists to rule out. It is the
     * same limit, carried here because layout cannot see the cascade's.
     */
    public float $maxLength = 200000.0;

    /**
     * `visibility: hidden`. The box keeps its place in the flow and still
     * paginates; it just paints nothing. A descendant can turn it back on,
     * so this is per box rather than a subtree decision.
     */
    public bool $visible = true;

    /**
     * Per-edge border. An edge with no border is absent; null means the box
     * has none at all, which is the cheap test layout and pagination use.
     *
     * @var array<string,array{width:float,style:string,color:array{0:float,1:float,2:float,3:float}}>|null
     */
    public ?array $border = null;

    /** @var string[] which edges to actually stroke */
    public array $borderEdges = ['top', 'right', 'bottom', 'left'];

    /**
     * Set on the box a layout pass starts from. CSS 2.1 §8.3.1: the margins of
     * the root element's box do not collapse, so a first or last child's
     * vertical margin stays inside the document instead of escaping through an
     * open edge to a parent that does not exist.
     */
    public bool $isRoot = false;

    /**
     * Set on a cell of a `border-collapse: collapse` table. Adjoining cells
     * share one grid line, so each reserves half of it rather than a whole
     * one, and the line is painted centered on the border box edge instead of
     * inside it. Without this a collapsed table's pitch is one border width
     * too large on both axes.
     */
    public bool $collapsedBorder = false;

    /**
     * Set on a `border-collapse: collapse` table. Its border carries the
     * resolved outer grid lines rather than one of its own, so it is painted
     * across the whole side and closes the corners the way Chrome draws them.
     * The room those lines take is already reserved, half by the table's rim
     * and half by the cells that share them, so this border costs no space.
     */
    public bool $borderIsRim = false;

    /**
     * `outline`. It is a border that takes no space: it is painted outside the
     * border box and layout never sees it, which is the whole point of the
     * property.
     *
     * @var array{width:float,style:string,color:array{0:float,1:float,2:float,3:float}}|null
     */
    public ?array $outline = null;
    public float $outlineOffset = 0.0;
    public ?PdfImage $image = null;
    public ?SvgDocument $svg = null;

    /**
     * Whether that SVG is an `<img>`'s source rather than an inline `<svg>`
     * element, which is what decides whether `object-fit` reaches it.
     *
     * An `<img>` holds a picture and CSS sizes a picture inside its box, so
     * every value applies; an inline `<svg>` is a viewport of its own and
     * Chrome ignores the property on it entirely. `SP-svg-objectfit.html` p8
     * and p9 are the pair that says so: an inline `<svg>` under `cover` and
     * under `none` draws the same letterboxed picture as under `contain`,
     * where p3 and p4 move on the `<img>` beside them.
     */
    public bool $svgAsImage = false;

    /**
     * The interactive field this box is, when it is a named form control.
     *
     * A box carrying one paints nothing onto the page: its ink goes into the
     * widget's appearance stream instead, so filling the field in a reader
     * replaces what is drawn rather than adding a second copy over the top of
     * it. {@see BoxPainter::paint()} is where the two part company.
     */
    public ?FormField $formField = null;

    /**
     * A checked checkbox or radio's mark, as an rgba.
     *
     * It is a fill over the whole border box rather than a glyph, because a
     * tick would depend on a face that can encode one. It is kept apart from
     * `background` because a field paints it only in its on state, and the two
     * states are two appearance streams over one box.
     *
     * @var array{0:float,1:float,2:float,3:float}|null
     */
    public ?array $checkMark = null;

    /**
     * The form control this box's ink belongs to, for the text child a control
     * carries its value in. It is the control itself and never an ancestor
     * further up, because only a direct child's offsets are the appearance
     * stream's own coordinates.
     */
    public ?Node $formOwner = null;

    /**
     * A replaced element's own size in points, kept whatever the declarations
     * do to the used one.
     *
     * CSS Sizing §5.2.1 makes it the box's min-content size, so a flex item's
     * automatic minimum size is measured from it: an `<img>` is the one box
     * with content to be narrower than and no children to read it off.
     * `width` cannot stand in, because a declaration overwrites it and CSS
     * Flexible Box §4.5 needs the two apart.
     */
    /**
     * A replaced box that has an intrinsic **ratio** and no intrinsic size:
     * an `<svg>` or an `<img>` of one whose file declares a `viewBox` and
     * neither `width` nor `height`.
     *
     * CSS 2.1 §10.3.2's last clause: with both axes automatic and a ratio but
     * no intrinsic size, the used width is the one a block-level non-replaced
     * box would get, which is the containing block's, and the ratio answers
     * for the height. Chrome does that for an inline one as well
     * (`OC-svg-viewbox-ratio.html` `c3`, 300.000 x 300.000 in a 300pt block),
     * and falls back to CSS Images §5.2's 300x150px default object size only
     * when there is no containing block width to fill (`c9`, which has no
     * viewBox either, so no ratio: 225.000 x 112.500).
     *
     * This engine read the viewBox as a length in points, so the same box was
     * 180.000 x 180.000 wherever it sat (defect BG).
     */
    /**
     * Where this box's own background is painted, as offsets from its left
     * edge, when that is not simply the whole box.
     *
     * CSS 2.1 §17.5.1: a table row's background covers the cells' area and
     * the border spacing between them is the **table**'s to paint, so a row in
     * a `border-spacing: 10px` table paints one band per cell with a hole
     * between them (`OD-row-spacing-band.html` `d1`, and `d6` with a cell
     * carrying a background of its own). An empty list means the whole box,
     * which is every other box there is.
     *
     * @var array<int,array{0:float,1:float}> offset and width, in points
     */
    public array $backgroundBands = [];

    public bool $ratioFill = false;

    /**
     * The UA decoration for an `<img>` whose `src` names a file the document
     * cannot read: `none`, `icon` or `framed`.
     *
     * `icon` is the placeholder alone, drawn at its own 12.000pt square at the
     * content box's origin and never stretched: Chrome puts the same 16x16
     * mark in the corner of a 200pt block as it puts in a 16x16 inline one.
     * `framed` adds the one-pixel rule Chrome draws round a box that declared
     * **both** of its axes, which is the only shape where a broken image keeps
     * a size of its own to frame.
     *
     * An `alt` attribute suppresses both, empty or not: with text it is the
     * text that renders, and `alt=""` says the image is decorative and Chrome
     * draws nothing at all. Defect BF.
     */
    public string $brokenImage = 'none';

    /**
     * Whether this box's content is a picture rather than a document fragment
     * that scales, which is the difference between an `<img>` and an inline
     * `<svg>` and the only thing that separates them once neither has an
     * intrinsic size.
     *
     * An image's content is its own min-content size, so a flex line cannot
     * squeeze it below the width it fills and the item beside it overflows
     * instead (`OC-svg-viewbox-ratio.html` `cb`, **150.000** in a 150pt row).
     * An inline `<svg>` has no min-content size at all and shrinks with the
     * line (`ca`, 93.141).
     */
    public bool $replacedContent = false;

    public ?float $intrinsicWidth = null;
    public ?float $intrinsicHeight = null;

    /**
     * Where this box would have been placed had it stayed in the flow, in its
     * **parent's** coordinates, which is CSS 2.1 §10.6.4's static position.
     *
     * An out-of-flow box with `top` and `left` auto goes here rather than at
     * its containing block's origin, and the two are only the same box when
     * the parent happens to be the containing block and the box happens to be
     * its first child. Null where nothing laid the parent out in block flow,
     * and {@see FlexLayout::placePositioned} then falls back to the parent's
     * content origin. Defect DB.
     */
    public ?float $staticX = null;
    public ?float $staticY = null;

    /**
     * Which axes of a replaced element the author left automatic. The builder
     * resolves both eagerly, which is right everywhere except a flex line:
     * that is the only context that hands a replaced box a main size it did
     * not ask for, and the axis nobody declared has to follow it rather than
     * keep the file's own number.
     */
    public bool $autoIntrinsicWidth = false;
    public bool $autoIntrinsicHeight = false;

    // ---- text ----
    public string $text = '';
    public float $fontSize = 12.0;
    public bool $bold = false;
    public bool $italic = false;
    public float $lineHeight = 1.35;
    public string $textAlign = 'left';

    /**
     * `text-indent`, kept in its authored form: a percentage resolves against
     * the containing block's width, which the box tree does not know yet.
     */
    public float|string $textIndent = 0.0;
    public string $direction = 'auto'; // auto | ltr | rtl
    public string $fontFamily = 'Helvetica';

    /**
     * `font-stretch` as a percentage of normal, which is what picks between
     * two faces of one family registered at different widths.
     */
    public float $fontStretch = 100.0;

    /** @var InlineRun[] Mixed-style spans. Overrides $text when non-empty. */
    public array $runs = [];

    /**
     * List marker for the first line of this text box. `outside`, the CSS
     * default, hangs it in the list's padding, so it is painted rather than
     * laid out: that keeps continuation lines aligned with the text instead of
     * with the bullet.
     */
    public ?InlineRun $marker = null;

    /**
     * `list-style-image`: a picture or a gradient painted where the bullet
     * would be, which replaces the marker text entirely.
     *
     * The two sizes are the marker box in points and are worked out by the
     * builder, because the answer needs the item's own font size and a
     * gradient has no size of its own to fall back on. Chrome's rules,
     * measured on `RM-list-image.html` across fourteen font sizes:
     *
     * - a source with no intrinsic size is **0.45em square**, not the 1em CSS
     *   gives a default object size and not half of it either;
     * - the box's **right edge is 7 CSS pixels left of the content edge**, at
     *   every font size from 8px to 64px;
     * - its **bottom edge sits on the first line's baseline**, exactly, on all
     *   fourteen, which is what a replaced inline box on the baseline does.
     *
     * @var array{image:?PdfImage,svg:?SvgDocument,gradient:?array}|null
     */
    public ?array $markerImage = null;

    /** The marker image's box, in points. {@see self::$markerImage}. */
    public float $markerImageWidth = 0.0;

    public float $markerImageHeight = 0.0;

    /**
     * The list item this box carries a marker for, when that item is not this
     * box, which is what an item whose content is a block child produces.
     *
     * A marker hangs outside the **item's** content edge, and this box is a
     * descendant of the item that may sit anywhere inside it: the block child
     * of `SN-list-marker.html` m7 carries a `margin-left: 40px`, so a marker
     * hung against this box lands 40px right of where Chrome puts it. The
     * distance between the two is a fact layout knows and the builder does
     * not, so the item travels here and the painter subtracts it. Defect FD.
     */
    public ?Node $markerHost = null;

    /**
     * The box that carries this item's marker, when that box is not this one.
     *
     * The same relationship as {@see self::$markerHost}, read from the item's
     * end. The item needs it because the marker's line is the ITEM's line
     * wherever the ink ends up, and the question layout has to ask is "does
     * this box own a marker at all": on an item whose content is a block the
     * answer sits on a descendant, so asking the item alone reads no and the
     * item's content is never moved down to clear the line. Defect GD.
     */
    public ?Node $markerHung = null;

    /**
     * `border-image`: a picture or a gradient sliced into nine regions and
     * painted over the border box, which **replaces** the border rather than
     * decorating it. Chrome paints no `#999` anywhere on a `border-image` box
     * whose source loads (`RM-border-image.html` `s1`).
     *
     * `slice`, `width` and `outset` are four edges each, top right bottom
     * left, and each edge carries its unit rather than a resolved length:
     * a slice is source pixels or a percentage of the source, an image width
     * is a length, a multiple of the border width or a percentage of the
     * border image area, and none of the three can be resolved until the
     * painter knows the box and the source's own size.
     *
     * @var array{
     *     layer: array{image:?PdfImage,svg:?SvgDocument,gradient:?array},
     *     slice: array<int, array{v:float,unit:string}>,
     *     fill: bool,
     *     width: array<int, array{v:float,unit:string}>,
     *     outset: array<int, array{v:float,unit:string}>,
     *     repeat: string
     * }|null
     */
    public ?array $borderImage = null;

    /**
     * The line box strut: the containing block's own font, which every line
     * in this box reserves room for whether or not anything on that line uses
     * it. It rides here rather than being read back off the box, because only
     * the builder knows the cascaded font; a node assembled by hand has the
     * constructor's defaults and no strut at all, which leaves its lines
     * measured exactly as they were before struts existed.
     */
    public ?InlineRun $strut = null;

    /**
     * How far below its own strut's baseline a hosted list marker sits, in
     * points. Defect FU's other half.
     *
     * A marker and the item's first line box share a baseline, and whichever
     * of the two is higher moves down to meet the other. `FlexLayout` moves
     * the content when the strut is lower and records the distance here when
     * the content is, because a nested list of its own bigger font is already
     * below the line the marker would otherwise take and Chrome does not lift
     * it. Zero on every item where the two coincide, which is every item whose
     * content is text.
     */
    public float $markerBaselineShift = 0.0;

    /**
     * How far right of its column's own content edge a box that keeps clear of
     * a float in that column starts, in points. Defect HE.
     *
     * CSS 2.1 section 9.5 moves and narrows a box that establishes a
     * formatting context of its own rather than shortening its lines, and
     * inside a multi-column box the move is along the column it is already in.
     * The width is already the narrowed one by the time this is read, because
     * the box was laid out at it; this is the other half of the same answer,
     * and `FlexLayout::placeColumnRun()` adds it where it would otherwise add
     * the margin alone. Zero on every box that does not meet a float.
     */
    public float $columnFloatShift = 0.0;

    /**
     * The element's `id`, kept so a `#name` link has something to jump to.
     * Only box-level elements carry one; an inline `id` is not a jump target
     * today because inline content has no box of its own.
     */
    public ?string $anchorId = null;

    /** Heading depth for the document outline: 1 for h1, 0 for anything else. */
    public int $outlineLevel = 0;

    /** The heading's own text, which is what a bookmark shows. */
    public string $outlineTitle = '';

    /**
     * What this box means, as a PDF structure type: `P`, `H1`, `TD`, `Figure`.
     * Empty for a box no element asked for, which is most of them.
     *
     * {@see StructureTree}. It is set from the element name rather than from
     * the display, because a `display: block` on a `<td>` does not stop it
     * being a table cell to a reader.
     */
    public string $role = '';

    /**
     * Which cells a `<th>` heads, `Column` or `Row`, for a tagged document.
     *
     * PDF/UA-1 clause 7.5 refuses a table whose headers cannot be worked out:
     * "if the table's structure is not determinable via Headers and IDs, then
     * structure elements of type TH shall have a Scope attribute". Chrome's own
     * tagged export writes one on every `TH` it produces. Empty on everything
     * that is not a header cell.
     */
    public string $headerScope = '';

    /**
     * The header cells this DATA cell belongs to, in the order Chrome names
     * them: every column header above it first, then every row header to its
     * left. Defect HA, and it is filled in by {@see TableLayout} because the
     * answer needs the resolved GRID rather than the markup, which is where
     * `rowspan` and `colspan` have been taken apart.
     *
     * Empty on a header cell and on everything that is not a table cell.
     *
     * @var list<Node>
     */
    public array $headerCells = [];

    /**
     * The box this one is the rest of, for a box a column boundary cut.
     *
     * A page break makes one box into two fragments of the same node, so a
     * reader sees one element with its ink in two places. A column boundary
     * makes it into two boxes, and without this the second one would be a
     * second element: a paragraph cut between two columns would read as two
     * paragraphs. {@see StructureTree::own}.
     */
    public ?Node $fragmentOf = null;

    /**
     * The box whose element owns this one's ink, for the structure tree.
     *
     * An anonymous box has no element and therefore no role, and its text is
     * still the enclosing element's text: a bare run inside a `<p>` reaches
     * the page in a box of its own, and a reader that treats it as decoration
     * loses the paragraph. So every box points at the nearest ancestor that
     * has a role, and null means nothing does, which is decoration.
     */
    public ?Node $structureOwner = null;

    /** Alternate text for a replaced element, from `alt` or `aria-label`. */
    public string $altText = '';

    /** @var LineBox[] Populated by layout; consumed by paint and fragmentation. */
    public array $lineBoxes = [];

    /**
     * The width the lines above were laid out in, which is the containing
     * block's content width rather than this box's own measured one.
     *
     * A line-clamped box needs it after the fact: the marker has to fit the
     * width the line was broken to, and a text box that shrink-wrapped is
     * narrower than that.
     */
    public float $lineWidth = 0.0;

    /**
     * `-webkit-line-clamp`, the number of lines a box keeps, or 0 for all of
     * them.
     *
     * Chrome reads it only on a `display: -webkit-box` with
     * `-webkit-box-orient: vertical`, and reads neither the unprefixed
     * `line-clamp` nor the same declaration on an ordinary block, so the two
     * declarations beside it are part of the property rather than decoration.
     * `RX-clamp.html` n2 and n3 are both NO SIGNAL for that reason.
     */
    public int $lineClamp = 0;

    /** @var InlineRun[]|null memoised synthesis of $text into a single run */
    private ?array $synthesised = null;

    /** Memoised intrinsic widths, computed once and reused across layout passes. */
    public ?float $cachedMaxContent = null;
    public ?float $cachedMinContent = null;
    public ?float $cachedMinContentBox = null;

    /**
     * The same measurement with this box's own declared width left out, which
     * is CSS Flexible Box §4.5's content size suggestion.
     */
    public ?float $cachedContentMin = null;

    /** Normalised inline content for this node. @return InlineRun[] */
    public function inlineRuns(): array
    {
        if ($this->runs !== []) {
            return $this->runs;
        }

        if ($this->text === '') {
            return [];
        }

        return $this->synthesised ??= [new InlineRun(
            $this->text,
            $this->fontSize,
            $this->bold,
            $this->color ?? [0.12, 0.13, 0.16],
            $this->lineHeight,
            $this->fontFamily,
            false,
            $this->italic,
            fontStretch: $this->fontStretch,
        )];
    }

    // ---- fragmentation control ----
    public string $breakInside = 'auto'; // auto | avoid

    /*
     * Forced breaks around this box. `page` covers the CSS `page`, `always`
     * and the legacy `page-break-*` spelling, which all mean the same thing
     * for a paged medium with one page stream.
     */
    public string $breakBefore = 'auto'; // auto | page
    public string $breakAfter = 'auto';  // auto | page

    /**
     * The page type this box asks for, from the `page` property, or the empty
     * string for the ordinary page.
     *
     * CSS Paged Media 3 section 4.1: the used value of `auto` is the parent's
     * used value, so the name travels down the tree, and a box whose name
     * differs from the name in force at that point in the flow starts a new
     * page. Chrome forces that break for the NAME rather than for the block:
     * `VL-page-named-noblock.html` declares `page: cover` with no `@page cover`
     * anywhere and Chrome still paginates 4, 3, 9.
     */
    public string $pageName = '';
    public bool $repeatOnBreak = false; // re-emit at the top of each fragment

    /**
     * A `<tfoot>` row: re-emitted at the *bottom* of every page its table
     * reaches. That is a different mechanism from `repeatOnBreak`, because the
     * room has to be reserved before the body is flowed rather than consumed
     * as the page opens.
     */
    public bool $repeatAtBottom = false;
    public int $orphans = 2; // min lines left at the bottom of a page
    public int $widows = 2; // min lines carried to the next page

    /** @var Node[] */
    public array $children = [];

    // ---- layout result, in the parent's coordinate space ----
    public float $x = 0.0;
    public float $y = 0.0;
    public float $layoutWidth = 0.0;
    public float $layoutHeight = 0.0;

    /** Margins after collapsing with children, read by the parent. */
    public float $collapsedMarginTop = 0.0;
    public float $collapsedMarginBottom = 0.0;

    /**
     * CSS 2.1 §8.3.1: this box's own top and bottom margins are adjoining, so
     * the run of margins around it collapses *through* it and the box does not
     * end that run. True only for a box with no border, no padding, no height
     * of its own and nothing in it tall enough to separate the two.
     */
    public bool $marginsCollapseThrough = false;

    // ---- layout cache (measure passes repeat with identical inputs) ----
    /** True when the last pass measured only: the result must not be cached. */
    public bool $partialMeasure = false;

    public ?float $cacheAvailW = null;
    public ?float $cacheAvailH = null;

    /**
     * The {@see \FlexPDF\Engine\Node::$mainSizeIsUsedWidth} the cached layout was
     * produced under. The same available width means two different layouts
     * depending on it, so it belongs in the key.
     */
    public bool $cacheMainIsUsed = false;
    public int $cacheGen = -1;

    // ---- solver scratch ----
    public float $flexBaseSize = 0.0;
    public float $hypotheticalMainSize = 0.0;
    public float $targetMainSize = 0.0;
    public bool $frozen = false;

    /**
     * The automatic minimum main size §9.2 settled, so §9.7 step 4d clamps
     * against the same number rather than recomputing it without the container
     * the percentage and the definite cross size were resolved against.
     */
    public float $resolvedMinMain = 0.0;

    public function __construct(array $style = [], array $children = [])
    {
        foreach ($style as $k => $v) {
            if ($k === 'margin' || $k === 'padding') {
                $this->$k = $this->expandBox($v);
                continue;
            }

            // One radius still means all four corners and both halves of each.
            // The field widened when per-corner radii landed and again when
            // elliptical ones did; the spelling callers use did not.
            if ($k === 'borderRadius') {
                $this->borderRadius = self::cornerPairs($v);
                continue;
            }

            if (property_exists($this, $k)) {
                $this->$k = $v;
            }
        }

        $this->children = $children;
    }

    private function expandBox(float|array $v): array
    {
        if (is_float($v) || is_int($v)) {
            $v = (float) $v;

            return ['top'=>$v,'right'=>$v,'bottom'=>$v,'left'=>$v];
        }

        return array_merge(['top'=>0.0,'right'=>0.0,'bottom'=>0.0,'left'=>0.0], $v);
    }

    public function isPositioned(): bool
    {
        return $this->position !== 'static';
    }

    public function isOutOfFlow(): bool
    {
        return $this->position === 'absolute' || $this->position === 'fixed';
    }

    /**
     * Whether this box wraps its whole subtree in something, rather than
     * decorating only itself.
     *
     * Three places have to agree on this and they used to spell it out
     * separately: the fragmenter puts such a box on every descendant
     * fragment's chain, both in flow and for an out-of-flow box collected long
     * after the walk has left it, and the painter turns the chain into groups.
     * Round 49 added `isolation` to the first spelling and the probe did not
     * move, because the second and third still had the old list.
     *
     * {@see BoxPainter::makesGroup()} is this and a transform, which travels on
     * the chain as a matrix around each piece and is deliberately not a group
     * root.
     */
    public function wrapsSubtree(): bool
    {
        return $this->compositesSubtree()
            || $this->transform !== []
            || $this->isolation === 'isolate';
    }

    /**
     * Whether this box composites its whole subtree as one **picture**, rather
     * than only making a stacking context around it.
     *
     * This is the half of {@see wrapsSubtree()} that is observable with nothing
     * else on the page. An `opacity`, a `mix-blend-mode` and a `mask-image`
     * each need the subtree drawn once and composited once, or a child
     * composites against its own parent's background instead of against what
     * the pair of them stand on. A `transform` and an `isolation: isolate` ask
     * for neither: one is a matrix and the other is only observable where
     * something below it blends.
     */
    public function compositesSubtree(): bool
    {
        return $this->opacity < 1.0
            || $this->blendMode !== 'normal'
            || $this->maskLayers !== [];
    }

    /**
     * Whether any box below this one asks for a blend the writer can name.
     *
     * This is what says an isolating box is observable at all: a group where
     * nothing blends is the same picture without one, which is the argument
     * round 48 used to open the page's own group on 140 of 800 documents
     * instead of on all 800.
     *
     * The answer is cached because {@see BoxPainter::makesGroup()} asks it
     * several times per fragment, and a fresh walk each time would be a scan
     * over author-controlled content inside a per-element helper. Each node
     * computes once and reads its children's cached answers, so a whole tree
     * costs one visit per box. It is read after layout has finished and the
     * box tree no longer changes.
     */
    public function blendsBelow(): bool
    {
        if ($this->blendsBelowCache !== null) {
            return $this->blendsBelowCache;
        }

        // Set before the recursion rather than after it, so a tree that
        // somehow refers back to itself answers `false` instead of running
        // until the stack gives out.
        $this->blendsBelowCache = false;

        foreach ($this->children as $child) {
            if (Pdf::blendable($child->blendMode) || $child->blendsBelow()) {
                $this->blendsBelowCache = true;
                break;
            }
        }

        return $this->blendsBelowCache;
    }

    private ?bool $blendsBelowCache = null;

    public function isFloating(): bool
    {
        return $this->float !== 'none' && !$this->isOutOfFlow();
    }

    public function isRow(): bool
    {
        return str_starts_with($this->flexDirection, 'row');
    }

    /** Main-start and main-end are swapped, so the line is laid out mirrored. */
    public function isReverse(): bool
    {
        return str_ends_with($this->flexDirection, '-reverse');
    }

    public function marginMain(bool $row): float
    {
        return $row
            ? $this->margin['left'] + $this->margin['right']
            : $this->margin['top'] + $this->margin['bottom'];
    }

    public function marginCross(bool $row): float
    {
        return $row
            ? $this->margin['top'] + $this->margin['bottom']
            : $this->margin['left'] + $this->margin['right'];
    }

    public function paddingMain(bool $row): float
    {
        return $row
            ? $this->padding['left'] + $this->padding['right']
            : $this->padding['top'] + $this->padding['bottom'];
    }

    public function paddingCross(bool $row): float
    {
        return $row
            ? $this->padding['top'] + $this->padding['bottom']
            : $this->padding['left'] + $this->padding['right'];
    }

    public function borderWidth(string $side): float
    {
        return $this->border[$side]['width'] ?? 0.0;
    }

    /**
     * How much room the edges take on one side: padding plus border. This is
     * the distance from the border box to the content box, and it is what
     * layout means whenever it reaches for padding.
     */
    public function edge(string $side): float
    {
        if ($this->borderIsRim) {
            return $this->padding[$side];
        }

        return $this->collapsedBorder
            ? $this->padding[$side] + $this->borderWidth($side) / 2.0
            : $this->padding[$side] + $this->borderWidth($side);
    }

    /**
     * How far inside this box's BORDER box its overflow clip edge sits on one
     * side, which is negative wherever the clip reaches outside the box.
     *
     * `overflow-clip-margin` measures from the box its own keyword names, and
     * the default is the padding box, so a declared 20px on a box with a 10px
     * border puts the clip edge 10px OUTSIDE the border box:
     * `TD-overflow-clip-margin.html` o5 is 100 by 100 in Chrome against a
     * border box of 80 by 80. It applies to an axis that clips WITHOUT
     * scrolling and to no other, which o4 measures. Defect GM.
     *
     * With no margin on it the edge is the PADDING box, which is where CSS
     * clips overflow, so the inset is the border width: o9 is a 10px ring that
     * stays visible around a child stopping at 60 by 60. **The box's own
     * decoration is painted outside this clip**, in {@see BoxPainter::paint()},
     * because the border sits on the far side of the edge the clip cuts at and
     * a border painted inside the clip is cut away by it. Defect GN.
     *
     * The border is read as `edge()` less the padding rather than as
     * {@see borderWidth()}, so a collapsed table border and a rim contribute
     * here exactly what they contribute to every other box-model question.
     */
    public function overflowClipInset(string $side): float
    {
        $border = $this->edge($side) - $this->padding[$side];
        $axis   = $side === 'left' || $side === 'right' ? $this->overflowX : $this->overflowY;

        if ($axis !== 'clip' || $this->overflowClipMargin <= 0.0) {
            return $border;
        }

        $from = match ($this->overflowClipBox) {
            'content-box' => $border + $this->padding[$side],
            'border-box'  => 0.0,
            default       => $border,
        };

        return $from - $this->overflowClipMargin;
    }

    /**
     * Whatever a caller spells a `border-radius` as, read as four corner
     * pairs: one length, four circular corners, or the four pairs themselves.
     *
     * @param  list<array{0:float,1:float}>|array<int,float>|float|int $value
     * @return list<array{0:float,1:float}>
     */
    public static function cornerPairs(array|float|int $value): array
    {
        if (!is_array($value)) {
            return array_fill(0, 4, [(float) $value, (float) $value]);
        }

        return array_map(
            static fn(array|float|int $corner): array => is_array($corner)
                ? [(float) $corner[0], (float) $corner[1]]
                : [(float) $corner, (float) $corner],
            array_values($value),
        );
    }

    /** Whether any of the eight halves is worth drawing a curve for. */
    public function hasBorderRadius(): bool
    {
        foreach ($this->borderRadius as [$horizontal, $vertical]) {
            if ($horizontal > 0.0 || $vertical > 0.0) {
                return true;
            }
        }

        return false;
    }

    /**
     * These corner radii with each edge moved in by its own amount, in CSS's
     * corner order: top-left, top-right, bottom-right, bottom-left.
     *
     * TWO sides meet at a corner and they can move by different amounts, so
     * the HORIZONTAL half of a corner shrinks by the left or the right inset
     * and the VERTICAL half by the top or the bottom one. Shrinking both
     * halves by one scalar is exact on a uniform edge and wrong on anything
     * else: on `TJ-corner-sides.html` a 32px radius inside a 4px top and a
     * 24px left border is (8, 28) at the top left, where one scalar gives
     * (8, 8) and flattens the corner Chrome draws.
     *
     * **A square corner stays square**, which is CSS Backgrounds 3's own rule
     * for growing a rounded rectangle and is the half that cannot be derived
     * from the arithmetic: an `overflow-clip-margin` moves the edge outward,
     * so subtracting a negative inset from a zero radius would round the
     * corners of a box that declared none. `TD-overflow-clip-margin.html` o2
     * and o6 are 10,000 square pixels and read 9,704 with a 20px radius
     * invented on them.
     *
     * **And a corner with one half at zero is square**, per §5.1, so a radius
     * the border eats in one direction alone takes the whole corner with it.
     *
     * **The overlap factor belongs to the BORDER BOX and is applied before the
     * edge is subtracted**, which is the whole of defect GU. §5.1 computes one
     * factor for the box and every radius is multiplied by it; the inner curve
     * is then that scaled radius with the edge taken off. Subtracting first and
     * letting the path scale itself against the smaller box gives the same
     * answer whenever the two edges meeting at a corner are equal, and a
     * different one as soon as they are not: on `TJ-corner-sides.html` j10 a
     * 400px radius on a 160 by 80 border box is 40 once the height binds, so
     * Chrome's left corners are 16 wide against this engine's 32.
     *
     * @return list<array{0:float,1:float}>
     */
    public function shrunkRadii(
        float $left,
        float $top,
        float $right,
        float $bottom,
        float $boxWidth,
        float $boxHeight,
    ): array {
        $factor = self::overlapFactor($this->borderRadius, $boxWidth, $boxHeight);

        $inner = static function (array $corner, float $horizontal, float $vertical) use ($factor): array {
            $rx = $corner[0] > 0.0 ? max(0.0, $corner[0] * $factor - $horizontal) : 0.0;
            $ry = $corner[1] > 0.0 ? max(0.0, $corner[1] * $factor - $vertical) : 0.0;

            return $rx > 0.0 && $ry > 0.0 ? [$rx, $ry] : [0.0, 0.0];
        };

        return [
            $inner($this->borderRadius[0], $left, $top),
            $inner($this->borderRadius[1], $right, $top),
            $inner($this->borderRadius[2], $right, $bottom),
            $inner($this->borderRadius[3], $left, $bottom),
        ];
    }

    /**
     * The four corner radii of that clip edge, which is the same rule read off
     * the clip's own insets. `TD-overflow-clip-margin.html` o10 is a 20px
     * radius on a 10px border and Chrome cuts the child at 10.
     *
     * The box is the fragment's own border box, because that is what §5.1's
     * overlap factor is measured against and a fragment is not always the
     * node's whole layout box.
     *
     * @return list<array{0:float,1:float}>
     */
    public function overflowClipRadii(float $boxWidth, float $boxHeight): array
    {
        return $this->shrunkRadii(
            $this->overflowClipInset('left'),
            $this->overflowClipInset('top'),
            $this->overflowClipInset('right'),
            $this->overflowClipInset('bottom'),
            $boxWidth,
            $boxHeight,
        );
    }

    /**
     * CSS Backgrounds 3 §5.1's curve overlap factor: when two radii on one side
     * sum past that side, EVERY radius is multiplied by the same number so the
     * shape stays a rounded rectangle rather than folding through itself. A side
     * is measured on its own axis, so the two horizontal halves are read against
     * the width and the two vertical ones against the height, and the smallest
     * of the four factors applies to all eight halves.
     *
     * @param list<array{0:float,1:float}> $radii
     */
    public static function overlapFactor(array $radii, float $width, float $height): float
    {
        $half = static fn(int $corner, int $axis): float => max(0.0, $radii[$corner][$axis]);

        $factor = 1.0;

        foreach ([
            [$half(0, 0) + $half(1, 0), $width],
            [$half(3, 0) + $half(2, 0), $width],
            [$half(0, 1) + $half(3, 1), $height],
            [$half(1, 1) + $half(2, 1), $height],
        ] as [$pair, $extent]) {
            if ($pair > $extent && $pair > 0.0) {
                $factor = min($factor, $extent / $pair);
            }
        }

        return $factor;
    }

    public function edgeMain(bool $row): float
    {
        return $row
            ? $this->edge('left') + $this->edge('right')
            : $this->edge('top') + $this->edge('bottom');
    }

    public function edgeCross(bool $row): float
    {
        return $row
            ? $this->edge('top') + $this->edge('bottom')
            : $this->edge('left') + $this->edge('right');
    }

    /**
     * A declared inline-axis size as the border-box length layout works in.
     * Under `box-sizing: content-box`, which is the CSS default, the declared
     * width is the content width and the edges sit outside it.
     *
     * Under `border-box` the declared size already includes the edges, but it
     * cannot go below them: the content box floors at zero, so a 20pt box with
     * 29pt of padding and border is 29pt tall, not 20pt.
     */
    /**
     * The border-box height this box's `aspect-ratio` asks for, given its
     * border-box width, and the reverse.
     *
     * CSS applies the ratio to the box `box-sizing` names, so under the
     * default `content-box` the edges come off before the division and go
     * back on after it. That is why a 200px box with 20px of padding is 240
     * wide and 140 tall at 2/1, rather than 240 by 120.
     */
    public function ratioHeight(float $borderBoxWidth): float
    {
        if ($this->aspectRatio === null || $this->aspectRatio <= 0.0) {
            return $borderBoxWidth;
        }

        if ($this->boxSizing === 'border-box') {
            return min($this->maxLength, $borderBoxWidth / $this->aspectRatio);
        }

        $content = max(0.0, $borderBoxWidth - $this->edgeMain(true));

        return min($this->maxLength, $content / $this->aspectRatio + $this->edgeCross(true));
    }

    /**
     * The border-box cross size the file's own proportions ask for, given a
     * border-box main size, or null when the author declared that axis or the
     * box is not a replaced one.
     */
    public function intrinsicCross(float $mainBorderBox, bool $row): ?float
    {
        $auto = $row ? $this->autoIntrinsicHeight : $this->autoIntrinsicWidth;

        if (!$auto || $this->intrinsicWidth === null || $this->intrinsicHeight === null) {
            return null;
        }

        $ratio = $this->intrinsicWidth / $this->intrinsicHeight;

        if ($ratio <= 0.0) {
            return null;
        }

        // Always through the content box, whatever `box-sizing` says: the
        // file's proportions are the picture's, and padding and a border are
        // not part of the picture.
        $content = max(0.0, $mainBorderBox - $this->edgeMain($row));

        return min(
            $this->maxLength,
            ($row ? $content / $ratio : $content * $ratio) + $this->edgeCross($row),
        );
    }

    public function ratioWidth(float $borderBoxHeight): float
    {
        if ($this->aspectRatio === null || $this->aspectRatio <= 0.0) {
            return $borderBoxHeight;
        }

        if ($this->boxSizing === 'border-box') {
            return min($this->maxLength, $borderBoxHeight * $this->aspectRatio);
        }

        $content = max(0.0, $borderBoxHeight - $this->edgeCross(true));

        return min($this->maxLength, $content * $this->aspectRatio + $this->edgeMain(true));
    }

    public function toBorderBoxWidth(float $declared): float
    {
        $edges = $this->edge('left') + $this->edge('right');

        return $this->boxSizing === 'border-box'
            ? max($declared, $edges)
            : $declared + $edges;
    }

    public function toBorderBoxHeight(float $declared): float
    {
        $edges = $this->edge('top') + $this->edge('bottom');

        return $this->boxSizing === 'border-box'
            ? max($declared, $edges)
            : $declared + $edges;
    }

    /** The space this box takes on a line, margins included. */
    public function outerWidth(): float
    {
        return $this->margin['left'] + $this->layoutWidth + $this->margin['right'];
    }

    public function outerHeight(): float
    {
        return $this->margin['top'] + $this->layoutHeight + $this->margin['bottom'];
    }

    /**
     * Where this box's baseline sits, measured down from its top margin edge.
     *
     * An atomic inline sits on the line's baseline. A **scroll container**,
     * and a box with no line of its own, does so by its bottom margin edge;
     * every other box does so by the baseline of its own last line box, which
     * is what keeps a badge's text on the same baseline as the text beside it
     * rather than pushed down by its padding.
     *
     * CSS 2.1 §10.8.1's exception is the scroll container's and not the
     * clipping box's, which is the same reading §4.5 needed:
     * `ZY-overflow-longhand.html` `yo` (`overflow: hidden`) is **33.000** tall
     * in Chrome, the marker beside it pushed to the bottom margin edge, where
     * `yp` (`overflow: clip`) is **30.000**, byte-identical to `yn` with
     * nothing declared at all.
     *
     * Only meaningful once this box has been laid out in its own coordinate
     * space, since the descendants below carry parent-relative offsets that are
     * summed on the way down.
     */
    public function baselineOffset(): float
    {
        $bottom = $this->outerHeight();

        if ($this->scrollContainer) {
            return $bottom;
        }

        $last = $this->lastLineBaseline(0.0);

        return $last === null ? $bottom : $this->margin['top'] + $last;
    }

    /**
     * Where this box's *first* baseline sits, measured down from its top
     * margin edge. This is the one flexbox aligns on, and it differs from
     * `baselineOffset()` the moment a box holds more than one line.
     *
     * A box with no line box of its own has no baseline to share, so CSS
     * synthesizes one from its bottom margin edge. Chrome puts an empty 30pt
     * item's baseline exactly there.
     *
     * `overflow` is not part of that question here. CSS 2.1 §10.8.1's
     * bottom-margin-edge rule is the **inline** formatting context's, which is
     * {@see baselineOffset}; CSS Flexible Box §8.3 synthesizes only when the
     * item has no baseline at all, whatever its overflow. Chrome puts the
     * neighbour of a 40px `overflow: hidden` item at **0.000** in
     * `ZY-overflow-longhand.html` `yr`, exactly where `yq` with nothing
     * declared puts it, where reading `overflow` here pushed it down 21.701.
     */
    public function firstBaselineOffset(): float
    {
        $bottom = $this->outerHeight();
        $first  = $this->firstLineBaseline(0.0);

        return $first === null ? $bottom : $this->margin['top'] + $first;
    }

    /**
     * Baseline of the first in-flow line box at or below this one, or null.
     *
     * `$top` is where this box's border box sits relative to the box the walk
     * started from, so nothing reads this node's own `$y`: a flex item is
     * asked for its baseline before the container has placed it.
     *
     * **Null is the answer and not a gap**, which is why this is public beside
     * {@see firstBaselineOffset}: that one synthesizes a baseline from the
     * bottom margin edge, and a list item deciding whether to make room for
     * its marker's line has to tell a block child that holds a line from one
     * that holds none. A coloured spacer is the second and Chrome leaves it
     * where it is.
     */
    public function firstLineBaseline(float $top = 0.0): ?float
    {
        if ($this->display === 'text' && $this->lineBoxes !== []) {
            return $top + $this->lineBoxes[0]->baseline;
        }

        foreach ($this->children as $child) {
            if ($child->isOutOfFlow() || $child->isFloating()) {
                continue;
            }

            $found = $child->firstLineBaseline($top + $child->y);

            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    /** Baseline of the last in-flow line box below this one, or null. */
    private function lastLineBaseline(float $offset): ?float
    {
        $found = null;

        if ($this->display === 'text' && $this->lineBoxes !== []) {
            $height = 0.0;

            foreach ($this->lineBoxes as $line) {
                $height += $line->height;
            }

            $last  = $this->lineBoxes[count($this->lineBoxes) - 1];
            $found = $offset + $this->y + $height - $last->height + $last->baseline;
        }

        foreach ($this->children as $child) {
            if ($child->isOutOfFlow() || $child->isFloating()) {
                continue;
            }

            $found = $child->lastLineBaseline($offset + $this->y) ?? $found;
        }

        return $found;
    }

    /** Absolute rect after a layout pass has run and offsets are accumulated. */
    public function rect(): array
    {
        return [
            'x' => round($this->x, 3),
            'y' => round($this->y, 3),
            'w' => round($this->layoutWidth, 3),
            'h' => round($this->layoutHeight, 3),
        ];
    }
}
