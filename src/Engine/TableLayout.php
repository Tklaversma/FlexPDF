<?php

declare(strict_types=1);

namespace FlexPDF\Engine;

/**
 * CSS 2.1 §17 table layout: the automatic algorithm.
 *
 * Tables are the one layout mode where a column's width depends on every
 * cell in it, so nothing can be positioned until the whole grid has been
 * measured. That makes the shape of the work: build the grid (honoring
 * colspan and rowspan), derive per-column min/max content widths, distribute
 * the available width between those bounds, and only then lay cells out.
 */
final class TableLayout
{
    public function __construct(
        private readonly FlexLayout $engine,
    ) {}

    public function layout(Node $table, ?float $availWidth): void
    {
        // A table establishes its own formatting context, so its margins never
        // collapse through it into its rows. Flex and grid say the same about
        // themselves; without this a table's own margins are read as zero and
        // dropped by whatever block flow placed it, empty tables included.
        $table->collapsedMarginTop    = $table->margin['top'];
        $table->collapsedMarginBottom = $table->margin['bottom'];

        $rows = $this->rows($table);

        // CSS 2.1 §17.6.1: in the separated borders model a row, a row group,
        // a column and a column group all have no border at all, and Chrome
        // paints none. The group's background is unaffected and is what defect
        // DK was about; this is the half of it that has to be dropped rather
        // than drawn, measured on `QN-rowgroup-paint.html` where Chrome fills
        // 0 pixels of the declared `3pt solid` and the first version of DK's
        // fix drew 1,114.
        //
        // The ROW is the same rule one level in and it was missing, which is
        // half of defect HR: a `<tr>` with a border stroked a rectangle inside
        // itself on a separated table where Chrome draws nothing at all.
        if ($table->borderCollapse !== 'collapse') {
            foreach ($rows as $row) {
                $row->border = null;

                if ($row->rowGroupBox !== null) {
                    $row->rowGroupBox->border = null;
                }
            }
        }

        if ($rows === []) {
            $this->sizeWithoutCells($table, $availWidth);

            return;
        }

        $grid    = $this->buildGrid($rows);
        $columns = $this->columnCount($grid);

        $this->associateHeaders($grid);

        if ($columns === 0) {
            $this->sizeWithoutCells($table, $availWidth);

            return;
        }

        $collapsed = $table->borderCollapse === 'collapse';
        $spacing   = $collapsed ? 0.0 : $table->borderSpacingX;
        $spacingY  = $collapsed ? 0.0 : $table->borderSpacingY;
        $gutters   = $spacing * ($columns + 1);

        // Collapsed cells reserve half a grid line each, so the flag has to be
        // set before anything measures a cell: the intrinsic widths below read
        // `edge()`, and they cache what they read.
        $outer = $table->borderCollapse === 'collapse'
            ? $this->collapseGeometry($table, $grid, $rows, $columns)
            : ['top' => 0.0, 'right' => 0.0, 'bottom' => 0.0, 'left' => 0.0];

        // A parent narrower than this table's own padding leaves nothing to
        // distribute; a used width is never negative.
        $inner    = max(0.0, ($availWidth ?? 0.0) - $table->edgeMain(true));
        $explicit = is_float($table->width) ? $table->width : null;

        if (is_string($table->width) && str_ends_with($table->width, '%')) {
            $explicit = (float) rtrim($table->width, '%') / 100.0 * ($availWidth ?? 0.0);
        }

        // `box-sizing` names which box a declared width measures, and every
        // other box in the engine reads it. Under `border-box` the table's own
        // edges come out of the declared width rather than sitting outside it,
        // so what is left is the content width the rest of this works in.
        if ($explicit !== null && $table->boxSizing === 'border-box') {
            $explicit = max(0.0, $explicit - $table->edgeMain(true));
        }

        // CSS 2.1 §10.4 reaches a table box too, and a `max-width` has to
        // reach it here rather than at the finished box: the columns are
        // distributed over the width settled below, so clamping afterwards
        // moves the border and leaves every column where it was. This table
        // applied neither constraint at all, so `max-width: 30px` over
        // `alpha be cd` was 46.530 wide and one row tall against Chrome's
        // 22.500 and two (`OI-width-clamp.html` `i7`).
        if ($table->maxWidth !== null) {
            $ceiling  = max(0.0, $table->maxWidth - $table->edgeMain(true));
            $inner    = min($inner, $ceiling);
            $explicit = $explicit === null ? null : min($explicit, $ceiling);
        }

        $this->engine->resolveChildEdges($table, $inner);

        // A cell is measured by this class rather than laid out by its row, so
        // it is the one box nothing ever hands a containing block to: its
        // `min-width` and `max-width` were read off the sheet and left
        // unresolved. Only those two are settled here: a percentage padding
        // must **not** reach the column algorithm, because CSS 2.1 §17.5.2
        // sizes a column as though it were zero and Chrome does the same. It
        // is resolved once the columns are, in {@see layoutCells}. Defect AO.
        $this->resolveCellConstraints($grid, $columns, $explicit ?? $inner);

        [$min, $max, $sized] = $this->columnIntrinsics($grid, $columns, $spacing);

        // The outer half of a collapsed table's rim sits outside the cells and
        // inside the table box, so it is not room the columns can be given.
        $rim = $outer['left'] + $outer['right'];

        // A declared width is a percentage of the table, not of the column it
        // lands in, so the basis has to be settled before the columns are.
        $declared = $this->clampPercentShares(
            $this->widthDeclarations($sized, $table->columnWidths, $columns),
            $columns,
        );
        $space = max(0.0, ($explicit ?? $inner) - $gutters - $rim);

        // Defect DA: the width every percentage column needs the table to be.
        // It is a floor on the table and a basis for the percentages alike, and
        // it is capped at the room first, because a percentage that cannot be
        // satisfied leaves the table filling its container and the shares
        // dividing what is actually there: `width: 50%` beside an automatic
        // column in a 45pt block is 22.500, not the 46.547 its content wants
        // (`OV` `v2`).
        $percentFloor = $explicit !== null
            ? 0.0
            : min($space, $this->percentFloor($declared, $columns, $max));

        $basis = $explicit !== null
            ? $space
            : ($percentFloor > 0.0 ? $percentFloor : $space);

        // A column that names a width does not grow with the table's surplus
        // the way an automatic one does, so it comes out of the distribution
        // as both its own floor and its own ceiling. What its content needs is
        // kept: it is the floor a pin can be cut down to, and no further.
        $floor  = $min;
        $pinned = $this->columnPins($declared, $basis, $min, $max);

        foreach ($pinned as $j => $w) {
            $min[$j] = $w;
            $max[$j] = $w;
        }

        $target = ($explicit ?? $inner) - $gutters - $rim;

        // CSS 2.1 §17.5.2.2: a table with `width: auto` is shrink-to-fit. It
        // takes what its columns ask for and stops, rather than spreading the
        // surplus and filling its container the way a block does.
        if ($explicit === null) {
            $target = min($target, max(array_sum($max), $percentFloor));
        }

        // A `min-width` is applied after that, or an automatic table would
        // answer its columns' preferred width and ignore the declaration
        // (`ia`, 46.530 against Chrome's 225.000). §17.5.2's CAPMIN is the
        // other floor and it is the one that makes a table different from a
        // block: a table narrower than its columns can be is not a table that
        // overflows, it is one Chrome refuses to make, so `max-width: 10px`
        // over the same content stops at 22.031 where a block goes to 7.500
        // (`ib` against `ic`).
        //
        // CAPMIN is raised only where a constraint has been applied, because
        // it is a floor under this rule rather than a rule of its own: the
        // distribution already keeps a column at its minimum, and putting the
        // floor on every table moved 171 corpus documents that declare
        // neither constraint.
        if ($table->minWidth !== null || $table->maxWidth !== null) {
            if ($table->minWidth !== null) {
                $target = max($target, $table->minWidth - $gutters - $rim - $table->edgeMain(true));
            }

            $target = max($target, array_sum($floor));
        }

        $target = max($target, 0.0);
        $pinned = $this->capPins($pinned, $floor, $target);

        foreach ($pinned as $j => $w) {
            $min[$j] = $w;
            $max[$j] = $w;
        }

        // Scaling is only ever upwards here: {@see capPins} has already taken
        // the surplus out in the other direction, and it is the one that knows
        // how far down a column may go.
        $widths = match (true) {
            $table->tableLayout === 'fixed'                        => $this->fixedWidths($grid, $columns, $target, $table->columnWidths, $basis),
            count($pinned) >= $columns && $target > array_sum($max) => $this->scaleToFill($max, $target),
            default                                                => $this->distribute($min, $max, $target, array_fill_keys(array_keys($pinned), true)),
        };

        $tableInner = array_sum($widths) + $gutters + $rim;

        // CSS 2.1 §17.5.2 sizes a column as though a percentage padding were
        // zero, which is why it was kept out of the algorithm above, and the
        // basis it resolves against is the **table's own used width**, not the
        // cell's and not the column's. `QS-cell-pct-padding.html`: `0 10%` on
        // the cells of a 225pt table is 22.5pt and of a 150pt table 15.0, a
        // cell with a declared `width: 100px` still gets 22.5, and an automatic
        // table's own 43.934 gives 4.383. Defect AO, whose entry could not name
        // the basis because 15.829 was ten percent of a shrink-to-fit table
        // rather than of the 300 it assumed.
        $this->resolveCellPadding($grid, $columns, $tableInner);

        // --- lay cells out at their resolved column widths ---
        $x      = [];
        $cursor = $table->edge('left') + $outer['left'] + $spacing;

        foreach ($widths as $j => $w) {
            $x[$j]  = $cursor;
            $cursor += $w + $spacing;
        }

        $rowHeights = array_fill(0, count($rows), 0.0);
        $spanning   = [];
        $fixed      = array_fill(0, count($rows), false);

        foreach ($grid as $i => $rowCells) {
            foreach ($rowCells as $j => $cell) {
                if ($cell === null || $cell->originRow !== $i || $cell->originCol !== $j) {
                    continue;
                }

                $node  = $cell->node;
                $span  = min($node->colspan, $columns - $j);
                $width = 0.0;

                for ($k = 0; $k < $span; $k++) {
                    $width += $widths[$j + $k];
                }

                $width += $spacing * ($span - 1);

                $width = max(0.0, $width);
                $this->engine->measure($node, $width, null);
                $node->layoutWidth = $width;

                if ($node->rowspan > 1) {
                    $spanning[] = [$i, min($node->rowspan, count($rows) - $i), $node->layoutHeight];
                } else {
                    $rowHeights[$i] = max($rowHeights[$i], $node->layoutHeight);
                    $fixed[$i]      = $fixed[$i] || is_float($node->height);
                }
            }
        }

        $this->applyRowHeights($table, $rows, $grid, $rowHeights, $fixed, $spacingY);

        /*
         * A row-spanning cell must fit, and the shortfall is spread over the
         * rows it spans rather than landing on the last of them. It is
         * §17.5.3's rule read at a smaller scale, and the same two preferences:
         * rows the shortfall can move, meaning rows nothing pinned with a
         * length, falling back to every row of the span where there are none;
         * and proportional to what each row already has, equally where every
         * one of them is zero.
         *
         * `ZV-rowspan-shortfall.html` says all four. `rs0` is **60 / 60** where
         * this gave 12 / 108, `rs2` (a first row holding three lines) 90 / 30
         * rather than equal thirds, `rs9` and `rsa` (a pinned middle row, by
         * its own declaration and by its cell's) **37.500 / 45.000 / 37.500**,
         * and `rsb`, every row of the span pinned, 60 / 60 on the fallback.
         */
        foreach ($spanning as [$start, $span, $height]) {
            $covers  = range($start, $start + $span - 1);
            $covered = $spacingY * ($span - 1);

            foreach ($covers as $i) {
                $covered += $rowHeights[$i];
            }

            if ($height <= $covered) {
                continue;
            }

            $free = array_values(array_filter($covers, static fn (int $i): bool => !$fixed[$i]));
            $take = $free !== [] ? $free : $covers;

            $parts = $this->share($height - $covered, array_map(
                static fn (int $i): float => $rowHeights[$i],
                $take,
            ));

            foreach ($take as $n => $i) {
                $rowHeights[$i] += $parts[$n];
            }
        }

        $this->stretchRowGroups($table, $rows, $rowHeights, $fixed, $spacingY);

        $rowHeights = $this->stretchToDeclaredHeight(
            $table,
            $rows,
            $rowHeights,
            $fixed,
            $spacingY * ($this->gutters($rows) + 1) + $outer['top'] + $outer['bottom'],
        );

        // --- position ---
        // A gutter opens before every row and one more closes the last, which
        // is the same geometry as the old "start one gutter in" written so a
        // group band can opt out of its own: Chrome puts an empty
        // `<tbody style="height:80px">` at the table's top edge with no gutter
        // above it and the first real row a full gutter below it.
        $y = $table->edge('top') + $outer['top'];

        foreach ($rows as $i => $row) {
            if (!$row->isGroupBand) {
                $y += $spacingY;
            }

            // CSS 2.1 §17.5.1: in the separated borders model the row box is
            // the cells' area, so the outer border spacing is outside it and
            // the table's own background shows through there. Chrome's row box
            // in a `border-spacing: 10px` 90pt table is x **7.500** w 75.000
            // where this engine laid it out at x 0.000 w 90.000, one band of
            // color running through both gutters (defect CE,
            // `OD-row-spacing-band.html` `d1`).
            $row->x            = $table->edge('left') + $outer['left'] + $spacing;
            $row->y            = $y;
            $row->layoutWidth  = max(0.0, $tableInner - $rim - 2.0 * $spacing);
            $row->layoutHeight = $rowHeights[$i];

            // ...and the row's background covers the cells and not the gutter
            // between them: `d1`'s band is 75.000 wide with a 7.500 hole in the
            // middle of it, and `d6`, whose first cell carries a background of
            // its own, shows the row's color only under the second (`x 43..82`
            // of a row that starts at 7.500).
            $row->backgroundBands = [];

            foreach ($grid[$i] as $j => $cell) {
                if ($cell === null || $cell->originRow !== $i || $cell->originCol !== $j) {
                    continue;
                }

                $node = $cell->node;
                $span = min($node->rowspan, count($rows) - $i);

                $available = 0.0;

                for ($k = 0; $k < $span; $k++) {
                    $available += $rowHeights[$i + $k];
                }

                $available += $spacingY * ($span - 1);

                // Cells stretch to the row height; vertical-align then places
                // the content box within that.
                $contentHeight      = $node->layoutHeight;
                $node->layoutHeight = $available;

                $offset = match ($node->verticalAlign) {
                    'middle' => ($available - $contentHeight) / 2,
                    'bottom' => $available - $contentHeight,
                    default  => 0.0,
                };

                $node->cellShift = 0.0;

                if ($offset > 0.01) {
                    $this->shiftChildren($node, $offset);
                    $node->cellShift = $offset;
                }

                $node->x = $x[$j] - $table->edge('left') - $outer['left'] - $spacing;
                $node->y = 0.0;

                if ($spacing > 0.0) {
                    $row->backgroundBands[] = [$node->x, $node->layoutWidth];
                }
            }

            $y += $rowHeights[$i];
        }

        $y += $spacingY;

        if ($table->borderCollapse === 'collapse') {
            $this->collapseBorders($grid, $rows, $columns);
        }

        // `$tableInner` is a content width, so the table's own padding and
        // border sit outside it: what layout stores is the border box.
        $table->layoutWidth  = max(0.0, $tableInner + $table->edgeMain(true));
        $table->layoutHeight = $y + $outer['bottom'] + $table->edge('bottom');
    }

    /**
     * A `<tr>`'s own declared `height`, which is a **minimum** for the row and
     * not its size: `ZU-row-height.html` `rh1` declares 15pt over three lines
     * and Chrome keeps the 36.000 the content asks for, while `rh0`, one line
     * under a 60pt declaration, is 60.000 where every row height here came from
     * the cells alone. It is read alongside the cells' own declarations rather
     * than instead of them, so a 45pt row holding a 15pt cell is 45.000
     * (`rh7`), and the cell's padding is inside it rather than added to it
     * (`rh2`, 45.000 for a 12pt line inside `padding: 8px`).
     *
     * A percentage resolves against the table's own content height less the
     * **outer** border spacing, and is `auto` where the table has no definite
     * one: `rh4` is 25% of a 150pt table and comes out **37.500**, `rh3`, the
     * same declaration on a table with no height at all, is the 12.000 its line
     * asks for, and `ZX-cell-height-percent.html` `ck5` and `ck9` are 25% of a
     * `border-spacing: 10px` table at **33.750**, which is a quarter of 135 and
     * not of 150 or of 127.5: the two gutters at the ends come off the basis and
     * the ones between the rows do not, whether there are two rows or three.
     * `cka` takes the table's own border and padding off it as well, 31.875.
     *
     * A row that declares a length is **pinned**, exactly as a row whose cell
     * declares one already is, so the table's surplus goes round it: `rha`
     * declares 15pt over 36pt of content in a 150pt table and Chrome leaves it
     * at **36.000** and gives the other row all 114.000. The declaration keeps
     * the row out of the split whether or not it wins the row's height.
     *
     * A **cell**'s percentage height is the same declaration one box further in
     * and CSS 2.1 §17.5.3 makes it a minimum for the row rather than a size for
     * the cell, so it is resolved against the same basis and read into the same
     * maximum: `ck0` is 37.500 / 112.500, `ck6` (a row saying 10% and its cell
     * 25%) is the larger of the two at 37.500, and `ck7` keeps the cell's own
     * `padding: 8px` inside it. A **length** on a cell is not read here: it is
     * already in the row's height through the cell's own layout, and marked
     * fixed with it, which is round 18p's.
     *
     * @param Node[]                       $rows
     * @param array<int,array<int,object>> $grid
     * @param array<int,float>             $rowHeights
     * @param array<int,bool>              $fixed
     */
    private function applyRowHeights(
        Node $table,
        array $rows,
        array $grid,
        array &$rowHeights,
        array &$fixed,
        float $spacingY,
    ): void {
        $height = $this->engine->usedHeight($table, 0.0);
        $basis  = $height === null
            ? null
            : max(0.0, $height - $table->edgeCross(true) - 2.0 * $spacingY);

        foreach ($rows as $i => $row) {
            $declared = $this->declaredRowMinimum($row, $basis);

            foreach ($grid[$i] ?? [] as $j => $cell) {
                if ($cell === null || $cell->originRow !== $i || $cell->originCol !== $j) {
                    continue;
                }

                // A spanning cell's declaration is a minimum for the rows it
                // covers together rather than for this one, and that is the
                // shortfall pass's question rather than this one's.
                if ($cell->node->rowspan > 1 || !is_string($cell->node->height)) {
                    continue;
                }

                $percentage = $this->declaredRowMinimum($cell->node, $basis);
                $declared   = $percentage === null ? $declared : max($declared ?? 0.0, $percentage);
            }

            if ($declared === null) {
                continue;
            }

            $rowHeights[$i] = max($rowHeights[$i], $declared);
            $fixed[$i]      = true;
        }
    }

    /**
     * What a box's own `height` declaration asks of the row it is, or is in.
     * A percentage of an indefinite table height is `auto` and reaches nothing.
     */
    private function declaredRowMinimum(Node $box, ?float $basis): ?float
    {
        if (is_string($box->height) && str_ends_with($box->height, '%')) {
            return $basis === null || $basis <= 0.0
                ? null
                : (float) rtrim($box->height, '%') / 100.0 * $basis;
        }

        return $this->engine->usedHeight($box, 0.0);
    }

    /**
     * CSS 2.1 §17.5.3: a declared height on a table is a **minimum** for the
     * table box rather than its size, and whatever it has over the height the
     * rows asked for is handed to the rows. A height under them is simply
     * lost, which is why this only ever adds.
     *
     * Chrome shares that surplus out in two passes, and
     * `W6-table-height-more.html`'s `b1` is the shape that says so: two
     * `<tbody>`s, the first holding a 60pt cell and a 12pt row and the second
     * a 12pt row, come out **60 / 84 / 24** on a 168pt table, which is the
     * sections splitting 72:12 before the rows do. One flat pool of rows gives
     * 60 / 54 / 54.
     *
     * Each pass prefers a subset and falls back to the whole:
     *
     * - sections that are neither the header nor the footer, if there are any.
     *   `W2`'s `x4` leaves its header at **12.000** and gives the body
     *   everything; `W5`'s `a4`, a table whose only section IS the header,
     *   gives the header all 138 of it. Which section a group is, is its
     *   *display* rather than its tag, so a `<tbody style="display:
     *   table-header-group">` is kept out of the split too (`Z1` `e6`).
     * - rows the surplus can move, meaning rows no cell pinned with a length.
     *   `W5`'s `a1` is 60 / 55 / 110 rather than 140.6 / 28.1 / 56.3.
     *
     * and inside the subset the split is proportional to what each already
     * has, or equal where everything is zero (`W5`'s `a3`, two empty rows on a
     * 150pt table, is 75 / 75).
     *
     * @param  Node[]           $rows
     * @param  array<int,float> $rowHeights
     * @param  array<int,bool>  $fixed      per row: a cell, or the row itself, pinned it with a length
     * @param  float            $reserved   spacing and collapsed rim, which are not the rows' to grow
     * @return array<int,float>
     */
    private function stretchToDeclaredHeight(
        Node $table,
        array $rows,
        array $rowHeights,
        array $fixed,
        float $reserved,
    ): array {
        $natural = array_sum($rowHeights) + $reserved + $table->edgeCross(true);

        $surplus = $this->engine->clampSize(
            $this->engine->usedHeight($table, 0.0) ?? $natural,
            $table->minHeight,
            $table->maxHeight,
        ) - $natural;

        if ($surplus <= 0.01) {
            return $rowHeights;
        }

        $sections = $this->sections($rows);
        $bodies   = array_values(array_filter(
            $sections,
            static fn (array $section): bool => !$rows[$section[0]]->isHeaderRow
                && !$rows[$section[0]]->isFooterRow,
        ));

        $eligible = $bodies !== [] ? $bodies : $sections;

        // A section whose group declares a height is pinned against the
        // table's surplus, exactly as a row a length pinned is against the
        // section's: a 200px table over a `height: 60px` group and a plain one
        // leaves the first at 45.000 and gives the second all 82.500, where
        // sharing it by proportion gives 63.750 to each (defect DC).
        $free = array_values(array_filter(
            $eligible,
            static fn (array $section): bool => $rows[$section[0]]->rowGroupHeight === null,
        ));

        $eligible = $free !== [] ? $free : $eligible;

        $shares = $this->share($surplus, array_map(
            static fn (array $section): float => array_sum(array_map(
                static fn (int $i): float => $rowHeights[$i],
                $section,
            )),
            $eligible,
        ));

        foreach ($eligible as $k => $section) {
            $free = array_values(array_filter($section, static fn (int $i): bool => !$fixed[$i]));
            $take = $free !== [] ? $free : $section;

            $parts = $this->share($shares[$k], array_map(
                static fn (int $i): float => $rowHeights[$i],
                $take,
            ));

            foreach ($take as $n => $i) {
                $rowHeights[$i] += $parts[$n];
            }
        }

        return $rowHeights;
    }

    /**
     * CSS 2.1 §17.5.3 one level in: a declared height on a row **group** is a
     * minimum for the section rather than its size, so whatever it has over
     * what its rows asked for is shared between them, with the same preference
     * for rows no length pinned. A group is a table to its own rows.
     *
     * `PM-rowgroup-box.html` `m4`, a `height: 80px` group over two 12pt rows,
     * is **30.000 / 30.000** in Chrome and was 12.000 / 12.000 here; `m2`, one
     * row under the same declaration, is **60.000**. The internal gutters
     * count towards what the section already has, which is what makes a
     * `border-spacing: 10px` group of two rows 26.250 each rather than 30.000.
     *
     * @param Node[]           $rows
     * @param array<int,float> $rowHeights
     * @param array<int,bool>  $fixed
     */
    private function stretchRowGroups(
        Node $table,
        array $rows,
        array &$rowHeights,
        array &$fixed,
        float $spacingY,
    ): void {
        $height = $this->engine->usedHeight($table, 0.0);
        $basis  = $height === null
            ? null
            : max(0.0, $height - $table->edgeCross(true) - 2.0 * $spacingY);

        foreach ($this->sections($rows) as $section) {
            $declared = $this->declaredGroupHeight($rows[$section[0]], $basis);

            if ($declared === null) {
                continue;
            }

            // Read before the section is pinned, or the preference for rows
            // nothing has pinned yet would see a section it just pinned itself.
            $take = array_values(array_filter($section, static fn (int $i): bool => !$fixed[$i]));
            $take = $take !== [] ? $take : $section;

            foreach ($section as $i) {
                $fixed[$i] = true;
            }

            $natural = $spacingY * max(0, $this->gutters(array_map(
                static fn (int $i): Node => $rows[$i],
                $section,
            )) - 1);

            foreach ($section as $i) {
                $natural += $rowHeights[$i];
            }

            if ($declared - $natural <= 0.01) {
                continue;
            }

            $parts = $this->share($declared - $natural, array_map(
                static fn (int $i): float => $rowHeights[$i],
                $take,
            ));

            foreach ($take as $n => $i) {
                $rowHeights[$i] += $parts[$n];
            }
        }
    }

    /** A row group's own `height`, a percentage of it against the table's. */
    private function declaredGroupHeight(Node $row, ?float $basis): ?float
    {
        $declared = $row->rowGroupHeight;

        if (is_string($declared) && str_ends_with($declared, '%')) {
            return $basis === null || $basis <= 0.0
                ? null
                : (float) rtrim($declared, '%') / 100.0 * $basis;
        }

        return is_float($declared) ? $declared : null;
    }

    /**
     * How many border-spacing gutters a run of rows opens. A group band is not
     * a row and opens none of its own.
     *
     * @param Node[] $rows
     */
    private function gutters(array $rows): int
    {
        return count(array_filter($rows, static fn (Node $row): bool => !$row->isGroupBand));
    }

    /**
     * The rows grouped back into the row groups they were written in. The
     * groups themselves are flattened away by `HtmlBuilder`, so what is left
     * of one is the number its rows share.
     *
     * @param  Node[] $rows
     * @return array<int,int[]> row indices, one list per group, in order
     */
    private function sections(array $rows): array
    {
        $sections = [];
        $previous = false;

        foreach ($rows as $i => $row) {
            if ($previous === false || $row->rowGroup !== $previous) {
                $sections[] = [];
            }

            $sections[array_key_last($sections)][] = $i;
            $previous                              = $row->rowGroup;
        }

        return $sections;
    }

    /**
     * Split an amount in proportion to weights, equally where every weight is
     * zero, with the last recipient taking whatever the division left so the
     * parts add back up to the whole.
     *
     * @param  array<int,float> $weights
     * @return array<int,float>
     */
    private function share(float $amount, array $weights): array
    {
        $total = array_sum($weights);
        $last  = count($weights) - 1;
        $parts = [];
        $given = 0.0;

        foreach ($weights as $k => $weight) {
            if ($k === $last) {
                $parts[$k] = $amount - $given;

                break;
            }

            $parts[$k] = $total > 0.0 ? $amount * $weight / $total : $amount / ($last + 1);
            $given    += $parts[$k];
        }

        return $parts;
    }

    /**
     * A table with no cells to measure, sized from its own declarations.
     *
     * Everything else here derives a table's box from its columns and rows, so
     * a table with neither had nothing to derive from and was handed the
     * container's width and no height at all. CSS 2.1 §17.5.2 does not stop
     * applying to a table because the grid is empty: `width` is still the
     * content width, `height` is still the content height, and an automatic
     * one of either is shrink-to-fit over nothing, which is zero rather than
     * the container.
     *
     * Chrome measures exactly that on `docs/harness/probes/L1-empty-table.html`:
     * `width: 120px; height: 80px` is 90.000 x 60.000, a declared width alone
     * is 90.000 x 0.000, a declared height alone is 0.000 x 60.000, and a bare
     * one is 0.000 x 0.000 rather than the 300.000 its container offers.
     */
    private function sizeWithoutCells(Node $table, ?float $availWidth): void
    {
        $width  = $this->engine->usedWidth($table, $availWidth ?? 0.0);
        $height = $this->engine->usedHeight($table, 0.0);

        $table->layoutWidth = $this->engine->clampSize(
            $width ?? $table->edgeMain(true),
            $table->minWidth,
            $table->maxWidth,
        );

        $table->layoutHeight = $this->engine->clampSize(
            $height ?? $table->edgeCross(true),
            $table->minHeight,
            $table->maxHeight,
        );
    }

    /**
     * In collapse mode a grid line is shared, so each cell reserves half of it
     * and the table's rim carries the outer half of the outermost lines. The
     * returned widths are that rim, per side.
     *
     * **The line is resolved once, from every box whose own perimeter it is**,
     * and both boxes either side of it are then given the winner. CSS 2.1
     * §17.6.2.1 names six competitors and all six are here: the cell, the row,
     * the row group, the column, the column group and the table. A column and
     * a column group have no box in this tree, so the table carries theirs in
     * `columnBorders` and `columnGroupBorders`.
     *
     * Defects HN, HQ, HR and HS: the engine used to let each cell reserve half
     * of its OWN declared border and hand the table the rim, so a group's
     * border reserved nothing and was painted as a rectangle over its rows
     * afterwards, a row's did the same one level in, a `<col>`'s reached
     * nothing at all, and a cell that declared nothing beside a cell that
     * declared 4px reserved nothing where Chrome gives it half the line.
     * `UC-rowgroup-collapse.html` is **2 of 10 bands against Chrome before
     * this and 10 of 10 after**.
     *
     * **Every winner is written after the walk, never during it.** A cell's
     * declared border is a competitor for its neighbor's line as well as its
     * own, so writing in place would resolve the second line against a value
     * the first line had already replaced. That is round 68's own lesson about
     * cutting each item on a copy of itself, one class along.
     *
     * @param  array<int,array<int,object|null>> $grid
     * @param  Node[]                            $rows
     * @return array{top:float,right:float,bottom:float,left:float}
     */
    private function collapseGeometry(Node $table, array $grid, array $rows, int $columns): array
    {
        $outer    = ['top' => 0.0, 'right' => 0.0, 'bottom' => 0.0, 'left' => 0.0];
        $lines    = [];
        $last     = count($rows) - 1;
        $spans    = $this->groupSpans($rows);
        $resolved = [];

        foreach ($grid as $i => $row) {
            foreach ($row as $j => $cell) {
                if ($cell === null || $cell->originRow !== $i || $cell->originCol !== $j) {
                    continue;
                }

                $node                  = $cell->node;
                $node->collapsedBorder = true;
                $border                = $node->border ?? [];

                foreach (['top', 'right', 'bottom', 'left'] as $side) {
                    $winner = $this->winningLine(
                        $this->competitors($table, $grid, $rows, $spans, $columns, $cell, $side),
                    );

                    if ($winner === null) {
                        unset($border[$side]);

                        continue;
                    }

                    $border[$side] = $winner;

                    if (!$this->onTableRim($cell, $columns, $last, $side)) {
                        continue;
                    }

                    $outer[$side] = max($outer[$side], $winner['width'] / 2.0);

                    if ($winner['width'] >= ($lines[$side]['width'] ?? 0.0)) {
                        $lines[$side] = $winner;
                    }
                }

                $resolved[] = [$node, $border];
            }
        }

        foreach ($resolved as [$node, $border]) {
            $node->border = $border === [] ? null : $border;
        }

        // A row and a row group have both handed their border to the cells
        // that share its line, so neither may draw one of its own: a row used
        // to stroke a rectangle inside itself and a group one around every row
        // of it that lands on a page, and both are now the collapsed line the
        // cell draws. The group keeps its background, which is painted per row
        // and is defect DK's half of this.
        foreach ($rows as $row) {
            $row->border = null;

            if ($row->rowGroupBox !== null) {
                $row->rowGroupBox->border = null;
            }
        }

        // CSS 2.1 §17.6.2: a table has no border of its own in the collapsing
        // model. What it declares is one more border competing for the outer
        // grid lines, so what it keeps here is the resolved lines: reserved
        // half by the rim above and half by the cells that share them, and
        // painted by the table because only a line drawn across the whole side
        // closes the corners.
        $table->border      = $lines === [] ? null : $lines;
        $table->borderIsRim = true;

        return $outer;
    }

    /** Is this cell's own box on the table's outer edge on this side? */
    private function onTableRim(object $cell, int $columns, int $last, string $side): bool
    {
        $node = $cell->node;

        return match ($side) {
            'top'    => $cell->originRow === 0,
            'left'   => $cell->originCol === 0,
            'right'  => $cell->originCol + max(1, $node->colspan) >= $columns,
            default  => $cell->originRow + max(1, $node->rowspan) > $last,
        };
    }

    /**
     * The first and last row index of every row group, keyed by the group's
     * own id. A group's border is on the perimeter of those two rows and of
     * nothing between them, which is what makes it a competitor for some of a
     * table's lines and not for all of them.
     *
     * @param  Node[] $rows
     * @return array<int,array{0:int,1:int}>
     */
    private function groupSpans(array $rows): array
    {
        $spans = [];

        foreach ($rows as $i => $row) {
            if ($row->rowGroup === null) {
                continue;
            }

            $spans[$row->rowGroup] = [$spans[$row->rowGroup][0] ?? $i, $i];
        }

        return $spans;
    }

    /**
     * Every border competing for one side of one cell, in the order CSS 2.1
     * §17.6.2.1 breaks a tie: all the cells first, then the rows, then the row
     * groups, then the table, and within a level the topmost or leftmost box.
     *
     * A horizontal line has the row above it and the row below it on it, so a
     * cell's top is its neighbor's bottom and the two must resolve to one
     * width or the line is reserved twice at two different sizes. A vertical
     * line is the same one axis along, except that a row's and a group's own
     * left and right edges are the table's outer columns, so neither competes
     * for an interior one.
     *
     * @param  array<int,array<int,object|null>> $grid
     * @param  Node[]                            $rows
     * @param  array<int,array{0:int,1:int}>     $spans
     * @return list<array{width:float,style:string,color:array{0:float,1:float,2:float,3:float}}>
     */
    private function competitors(
        Node $table,
        array $grid,
        array $rows,
        array $spans,
        int $columns,
        object $cell,
        string $side,
    ): array {
        $node    = $cell->node;
        $rowspan = max(1, $node->rowspan);
        $colspan = max(1, $node->colspan);
        $last    = count($rows) - 1;

        $cells = [];
        $bands = [];
        $group = [];
        $strip = [];
        $rim   = [];

        if ($side === 'top' || $side === 'bottom') {
            $line   = $side === 'top' ? $cell->originRow : $cell->originRow + $rowspan;
            $before = $line - 1;

            for ($c = $cell->originCol; $c < $cell->originCol + $colspan; $c++) {
                $cells[] = ($grid[$before][$c] ?? null)?->node->border['bottom'] ?? null;
                $cells[] = ($grid[$line][$c] ?? null)?->node->border['top'] ?? null;

                // A column's own top edge is the line above the FIRST row and
                // its bottom edge the line below the LAST, so it competes for
                // two of a table's horizontal lines and for none between them.
                $outer = $line === 0 ? 'top' : ($line > $last ? 'bottom' : null);

                if ($outer === null) {
                    continue;
                }

                $strip[] = $table->columnBorders[$c][$outer] ?? null;
                $strip[] = $table->columnGroupBorders[$c][$outer] ?? null;
            }

            $bands[] = ($rows[$before] ?? null)?->border['bottom'] ?? null;
            $bands[] = ($rows[$line] ?? null)?->border['top'] ?? null;

            $group[] = $this->groupEdge($rows, $spans, $before, 'bottom');
            $group[] = $this->groupEdge($rows, $spans, $line, 'top');

            $rim[] = $line === 0 ? ($table->border['top'] ?? null) : null;
            $rim[] = $line > $last ? ($table->border['bottom'] ?? null) : null;
        }

        if ($side === 'left' || $side === 'right') {
            $line   = $side === 'left' ? $cell->originCol : $cell->originCol + $colspan;
            $before = $line - 1;
            $edge   = $line === 0 || $line >= $columns;

            for ($r = $cell->originRow; $r < $cell->originRow + $rowspan; $r++) {
                $cells[] = ($grid[$r][$before] ?? null)?->node->border['right'] ?? null;
                $cells[] = ($grid[$r][$line] ?? null)?->node->border['left'] ?? null;

                if (!$edge) {
                    continue;
                }

                $outer   = $line === 0 ? 'left' : 'right';
                $bands[] = ($rows[$r] ?? null)?->border[$outer] ?? null;
                $group[] = $rows[$r]->rowGroupBox?->border[$outer] ?? null;
            }

            // A vertical line is one column's right edge and the next one's
            // left, which is every line a column competes for.
            $strip[] = $table->columnBorders[$before]['right'] ?? null;
            $strip[] = $table->columnBorders[$line]['left'] ?? null;
            $strip[] = $table->columnGroupBorders[$before]['right'] ?? null;
            $strip[] = $table->columnGroupBorders[$line]['left'] ?? null;

            $rim[] = $line === 0 ? ($table->border['left'] ?? null) : null;
            $rim[] = $line >= $columns ? ($table->border['right'] ?? null) : null;
        }

        return array_values(array_filter([...$cells, ...$bands, ...$group, ...$strip, ...$rim]));
    }

    /**
     * A row group's border on one side, but only where the row given is the
     * group's own first or last row, because a group's top edge is the line
     * above its first row and its bottom edge the line below its last.
     *
     * @param  Node[]                        $rows
     * @param  array<int,array{0:int,1:int}> $spans
     * @return array{width:float,style:string,color:array{0:float,1:float,2:float,3:float}}|null
     */
    private function groupEdge(array $rows, array $spans, int $row, string $side): ?array
    {
        $node = $rows[$row] ?? null;

        if ($node === null || $node->rowGroup === null || $node->rowGroupBox === null) {
            return null;
        }

        $span = $spans[$node->rowGroup] ?? null;

        if ($span === null) {
            return null;
        }

        if ($side === 'top' && $span[0] !== $row) {
            return null;
        }

        if ($side === 'bottom' && $span[1] !== $row) {
            return null;
        }

        return $node->rowGroupBox->border[$side] ?? null;
    }

    /**
     * Which of the borders competing for one grid line is drawn on it.
     *
     * CSS 2.1 §17.6.2.1: the widest wins, and on a tie the caller's own
     * ordering decides, which is why this takes an ordered list rather than a
     * set. Style is not compared, which is the fidelity the engine had here
     * before this and is a separate question from which box owns the line.
     *
     * @param  list<array{width:float,style:string,color:array{0:float,1:float,2:float,3:float}}> $competing
     * @return array{width:float,style:string,color:array{0:float,1:float,2:float,3:float}}|null
     */
    private function winningLine(array $competing): ?array
    {
        $winner = null;

        foreach ($competing as $border) {
            if ($winner !== null && $border['width'] <= $winner['width']) {
                continue;
            }

            $winner = $border;
        }

        return $winner;
    }

    /**
     * In collapse mode every grid line is shared, so each cell draws only its
     * top and left edge; the last column and last row close the outside.
     *
     * The cells on the rim carry the line they share with the table, resolved
     * in `collapseGeometry()`, so what they draw there is the table's border
     * when the table won it. The table draws the same lines again across whole
     * sides, because only a line that runs the length of a side closes the
     * corners, and a repeating header carries its own onto every page it
     * reaches where the table's decoration slice does not.
     */
    private function collapseBorders(array $grid, array $rows, int $columns): void
    {
        foreach ($grid as $i => $row) {
            foreach ($row as $j => $cell) {
                if ($cell === null || $cell->originRow !== $i || $cell->originCol !== $j) {
                    continue;
                }

                $node = $cell->node;

                if ($node->border === null) {
                    continue;
                }

                $edges = ['top', 'left'];

                if ($j + max(1, $node->colspan) >= $columns) {
                    $edges[] = 'right';
                }

                if ($i + max(1, $node->rowspan) >= count($rows)) {
                    $edges[] = 'bottom';
                }

                $node->borderEdges = $edges;
            }
        }
    }

    /** Push a cell's content down without moving the cell box itself. */
    private function shiftChildren(Node $cell, float $dy): void
    {
        foreach ($cell->children as $child) {
            $child->y += $dy;
        }
    }

    /** @return Node[] */
    private function rows(Node $table): array
    {
        $rows = [];

        foreach ($table->children as $child) {
            if ($child->display === 'table-row') {
                $rows[] = $child;
            }
        }

        return $rows;
    }

    /**
     * Place cells into a grid, walking around slots already claimed by an
     * earlier colspan or rowspan.
     *
     * @param Node[] $rows
     *
     * @return array<int, array<int, ?object>>
     */
    /**
     * Which header cells each data cell belongs to. Defect HA.
     *
     * Read off Chrome's own tagged export of `TP-table-headers.html`, five
     * tables and 38 cells: a data cell names **every column header above it in
     * its own column** and **every row header to its left in its own row**, in
     * that order, and nothing else. The two-level header is what makes it worth
     * spelling out: the cell under `Q1` names the spanning `2026` as well as
     * `Q1`, so it is every header above and not the nearest one.
     *
     * The corner cell of a table with a row-header column falls out of the same
     * rule rather than needing one of its own: nothing in its column is a DATA
     * cell, so no cell names it, and Chrome gives it no `/ID` either.
     *
     * This wants the resolved grid and not the markup, because `rowspan` and
     * `colspan` are exactly what decides which header sits above which cell.
     *
     * @param array<int,array<int,object|null>> $grid
     */
    private function associateHeaders(array $grid): void
    {
        foreach ($grid as $r => $row) {
            foreach ($row as $c => $entry) {
                if ($entry === null || $entry->originRow !== $r || $entry->originCol !== $c) {
                    continue;
                }

                $cell = $entry->node;

                if ($cell->role !== 'TD') {
                    continue;
                }

                $headers = [];

                for ($above = 0; $above < $r; $above++) {
                    $held = $grid[$above][$c] ?? null;

                    if ($held !== null && $held->node->headerScope === 'Column' && !in_array($held->node, $headers, true)) {
                        $headers[] = $held->node;
                    }
                }

                for ($left = 0; $left < $c; $left++) {
                    $held = $grid[$r][$left] ?? null;

                    if ($held !== null && $held->node->headerScope === 'Row' && !in_array($held->node, $headers, true)) {
                        $headers[] = $held->node;
                    }
                }

                $cell->headerCells = $headers;
            }
        }
    }

    private function buildGrid(array $rows): array
    {
        $grid = array_map(function ($_) { return []; }, $rows);

        foreach ($rows as $i => $row) {
            $col = 0;

            foreach ($row->children as $node) {
                if ($node->display !== 'table-cell' || $node->isOutOfFlow()) {
                    continue;
                }

                while (isset($grid[$i][$col])) {
                    $col++;
                }

                $entry   = (object) ['node' => $node, 'originRow' => $i, 'originCol' => $col];
                $colspan = max(1, $node->colspan);
                $rowspan = max(1, $node->rowspan);

                for ($r = 0; $r < $rowspan && $i + $r < count($rows); $r++) {
                    for ($c = 0; $c < $colspan; $c++) {
                        $grid[$i + $r][$col + $c] = $entry;
                    }
                }

                $col += $colspan;
            }
        }

        // Normalize every row to the same length
        $width = $this->columnCount($grid);

        foreach ($grid as $i => $row) {
            for ($j = 0; $j < $width; $j++) {
                $grid[$i][$j] ??= null;
            }

            ksort($grid[$i]);
        }

        return $grid;
    }

    private function columnCount(array $grid): int
    {
        $max = 0;
        foreach ($grid as $row) {
            $max = max($max, $row === [] ? 0 : max(array_keys($row)) + 1);
        }

        return $max;
    }

    /**
     * Per-column intrinsic widths, and the cells that name a width of their
     * own. Cells spanning several columns contribute only the shortfall,
     * spread across the columns they cover.
     *
     * The two are read apart because a cell's `width` is an input to the
     * column algorithm beside its content rather than a replacement for it:
     * a column carrying a `width: 0` cell is still as wide as that cell's
     * longest word, which is what Chrome makes it.
     *
     * @return array{0:float[],1:float[],2:array<int,array{0:int,1:int,2:Node}>}
     */
    private function columnIntrinsics(array $grid, int $columns, float $spacing): array
    {
        $min   = array_fill(0, $columns, 0.0);
        $max   = array_fill(0, $columns, 0.0);
        $spans = [];
        $sized = [];

        foreach ($grid as $i => $row) {
            foreach ($row as $j => $cell) {
                if ($cell === null || $cell->originRow !== $i || $cell->originCol !== $j) {
                    continue;
                }

                $node    = $cell->node;
                $cellMin = $this->engine->contentMinWidth($node);
                $cellMax = $this->engine->contentMaxWidth($node);
                $span    = max(1, min($node->colspan, $columns - $j));

                // Chrome reads `min-width` on a cell as a floor under both of
                // the column's intrinsic widths and `max-width` as no ceiling
                // over either: `max-width: 40px` on a cell that declares no
                // width leaves the column exactly where it was, where
                // `min-width: 120px` takes it from 67.540 to 164.250.
                if ($node->minWidth !== null) {
                    $cellMin = max($cellMin, $node->minWidth);
                    $cellMax = max($cellMax, $node->minWidth);
                }

                if ($node->width !== null && $node->width !== 'auto') {
                    $sized[] = [$j, $span, $node];
                }

                if ($node->colspan <= 1) {
                    $min[$j] = max($min[$j], $cellMin);
                    $max[$j] = max($max[$j], $cellMax);

                    continue;
                }

                // A length on a spanning cell is a floor under the columns it
                // covers rather than a width for any of them, so it belongs in
                // the intrinsic widths, where `spreadSpan` shares it out and
                // does nothing once they already add up to more. Chrome leaves
                // a `colspan="2"` cell declaring 150pt over two columns that
                // want 70 exactly where they were.
                if (is_float($node->width)) {
                    $cellMin = max($cellMin, $node->width);
                    $cellMax = max($cellMax, $node->width);
                }

                $spans[] = [$j, $span, $cellMin, $cellMax];
            }
        }

        foreach ($spans as [$start, $span, $cellMin, $cellMax]) {
            $gutter = $spacing * ($span - 1);
            $this->spreadSpan($min, $start, $span, $cellMin - $gutter);
            $this->spreadSpan($max, $start, $span, $cellMax - $gutter);
        }

        return [$min, $max, $sized];
    }

    /**
     * Settle every cell's `min-width` and `max-width` against the table.
     *
     * @param array<int,array<int,object|null>> $grid
     */
    /**
     * A percentage `padding` or `margin` on every cell, against the table's
     * used width, once the columns are settled. Defect AO.
     *
     * @param array<int,array<int,object|null>> $grid
     */
    private function resolveCellPadding(array $grid, int $columns, float $tableWidth): void
    {
        foreach ($grid as $i => $row) {
            foreach ($row as $j => $cell) {
                if ($cell === null || $cell->originRow !== $i || $cell->originCol !== $j || $j >= $columns) {
                    continue;
                }

                foreach (['top', 'right', 'bottom', 'left'] as $side) {
                    $padding = $cell->node->resolveLength($cell->node->paddingPercent[$side], $tableWidth);

                    if ($padding !== null) {
                        $cell->node->padding[$side] = max(0.0, $padding);
                    }
                }
            }
        }
    }

    private function resolveCellConstraints(
        array $grid,
        int $columns,
        float $width,
        bool $definite = false,
    ): void {
        foreach ($grid as $i => $row) {
            foreach ($row as $j => $cell) {
                if ($cell === null || $cell->originRow !== $i || $cell->originCol !== $j || $j >= $columns) {
                    continue;
                }

                if ($definite) {
                    $cell->node->resolveAgainstContainingBlock($width);

                    continue;
                }

                $cell->node->resolveConstraints($width);
            }
        }
    }

    /**
     * Every width a column has been given a value for, from `<col>` and from
     * the cells alike. A percentage is kept as a fraction, because what it is
     * a percentage of is the table, which is not known yet.
     *
     * @param  array<int,array{0:int,1:int,2:Node}> $sized
     * @param  array<int,float|string>              $columnWidths
     * @return array<int,array{col:int,span:int,value:float,percent:bool,cell:?Node}>
     */
    private function widthDeclarations(array $sized, array $columnWidths, int $columns): array
    {
        $out = [];

        foreach ($columnWidths as $j => $value) {
            if ($j >= $columns) {
                continue;
            }

            $named = $this->namedWidth($value);

            if ($named !== null) {
                $out[] = ['col' => $j, 'span' => 1, 'value' => $named[0], 'percent' => $named[1], 'cell' => null];
            }
        }

        foreach ($sized as [$j, $span, $node]) {
            $named = $this->namedWidth($node->width);

            if ($named !== null) {
                $out[] = ['col' => $j, 'span' => $span, 'value' => $named[0], 'percent' => $named[1], 'cell' => $node];
            }
        }

        return $out;
    }

    /**
     * A `width` value as a number and whether that number is a fraction. A
     * negative width is not a width and neither is anything the parser could
     * not reduce to a length or a percentage, so both read as absent.
     *
     * @return array{0:float,1:bool}|null
     */
    private function namedWidth(float|string|null $value): ?array
    {
        if (is_float($value)) {
            return $value >= 0.0 ? [$value, false] : null;
        }

        if (!is_string($value) || !str_ends_with($value, '%')) {
            return null;
        }

        $percent = (float) rtrim($value, '%');

        return $percent >= 0.0 ? [$percent / 100.0, true] : null;
    }

    /**
     * What each column is pinned at by its own declarations.
     *
     * A pinned column keeps its width where an automatic one grows with the
     * table's surplus, and it never goes below what its content needs: Chrome
     * takes a `width: 0` cell up to its own longest word rather than clipping
     * it. Two rows disagreeing gives the larger of the two, and a cell
     * spanning several columns pins them only as a group, sharing what it asks
     * for in proportion to what they want.
     *
     * @param  array<int,array{col:int,span:int,value:float,percent:bool,cell:?Node}> $declared
     * @param  float[]                                                                $min
     * @param  float[]                                                                $max
     * @return array<int,float>
     */
    private function columnPins(array $declared, float $basis, array $min, array $max): array
    {
        $pins  = [];
        $spans = [];

        foreach ($declared as $entry) {
            $width = $this->resolveDeclared($entry, $basis);

            if ($entry['span'] <= 1) {
                $pins[$entry['col']] = max($pins[$entry['col']] ?? 0.0, $width);

                continue;
            }

            // Only a percentage on a spanning cell pins the columns it covers.
            // A length is a floor and {@see columnIntrinsics} has already
            // spread it; Chrome reads the two differently, and a `colspan="2"`
            // cell declaring 80% of the table lands its columns on 72.000 and
            // 168.000 exactly where one declaring 150pt moves neither.
            if ($entry['percent']) {
                $spans[] = [$entry['col'], $entry['span'], $width];
            }
        }

        foreach ($spans as [$start, $span, $width]) {
            $wanted = 0.0;

            for ($k = 0; $k < $span; $k++) {
                $wanted += $max[$start + $k];
            }

            for ($k = 0; $k < $span; $k++) {
                $share             = $wanted > 0.0 ? $max[$start + $k] / $wanted : 1.0 / $span;
                $pins[$start + $k] = max($pins[$start + $k] ?? 0.0, $width * $share);
            }
        }

        foreach ($pins as $j => $width) {
            $pins[$j] = max($width, $min[$j]);
        }

        ksort($pins);

        return $pins;
    }

    /** @param array{col:int,span:int,value:float,percent:bool,cell:?Node} $entry */
    private function resolveDeclared(array $entry, float $basis): float
    {
        $width = $entry['percent'] ? $entry['value'] * $basis : $entry['value'];
        $cell  = $entry['cell'];

        if ($cell === null) {
            return max(0.0, $width);
        }

        // A cell's declaration is a content width unless `box-sizing` says
        // otherwise, so its own padding and border sit outside it, and
        // `min-width` and `max-width` bound it exactly as they bound any
        // other box: Chrome pins a `width: 96px; max-width: 40px` column at
        // the 40.
        return max(0.0, $this->engine->clampSize($cell->toBorderBoxWidth($width), $cell->minWidth, $cell->maxWidth));
    }

    /**
     * The width a percentage column is a percentage of, for a table that has
     * no width of its own.
     *
     * Such a table has to be solved for, because its width is what the
     * percentages are against and also what they help decide: with P of it
     * spoken for by percentages and A points of columns that are not, the
     * table is A / (1 - P) wide, and it is at least wide enough for every
     * percentage column to hold its own content. That is what makes
     * `<col style="width: 50%">` half of the table rather than half of the
     * page. P at or above 1 has no solution **unless every column names one**,
     * in which case the shares are clamped to a running total of 1 and the
     * table is still solvable: `width: 80%` on both columns of a two-column
     * table leaves the second 20%, so the table is 100.137 rather than the
     * whole containing block (`OV-percent-column-more.html` `v3`). Where a
     * column names nothing there is no solution and the containing block
     * stands in, which is what this engine has always done (`v4`).
     *
     * The number this returns is also the width the table has to **reach**,
     * not only the basis its percentages read against, and that is defect DA:
     * the basis was right since round 11 and nothing made the table wide
     * enough to honor it, so a `width: 50%` column got its content width and
     * the table stopped at the sum of its columns (`OP` `p0`, 66.546 against
     * Chrome's 93.094). {@see percentFloor} is the same computation as a
     * floor, and returns 0.0 where no percentage binds.
     *
     * @param array<int,array{col:int,span:int,value:float,percent:bool,cell:?Node}> $declared
     * @param float[]                                                                $max
     */
    private function autoPercentBasis(array $declared, int $columns, float $fallback, array $max): float
    {
        $floor = $this->percentFloor($declared, $columns, $max);

        return $floor > 0.0 ? $floor : $fallback;
    }

    /**
     * The width CSS 2.1 §17.5.2.2 makes an automatic table at least, so that
     * every percentage column can hold its own content, or 0.0 where no
     * percentage binds one.
     *
     * @param array<int,array{col:int,span:int,value:float,percent:bool,cell:?Node}> $declared
     * @param float[]                                                                $max
     */
    /**
     * CSS 2.1 §17.5.2.2: the percentages on a table's columns are honored in
     * the order they are written and cut off at 100% between them, so a column
     * past the total takes what is left rather than what it asked for.
     *
     * `width: 80%` on both columns of a two-column table leaves the second
     * 20%, and Chrome sizes the table so that 20% holds the second column's
     * content: 100.137, with the columns on 80.109 and 20.027
     * (`OV-percent-column-more.html` `v3`). Without the cut, the two pins ask
     * for 160% of a table that has to fit them, and every number after that is
     * a proportional scale of an impossible one.
     *
     * @param  array<int,array{col:int,span:int,value:float,percent:bool,cell:?Node}> $declared
     * @return array<int,array{col:int,span:int,value:float,percent:bool,cell:?Node}>
     */
    private function clampPercentShares(array $declared, int $columns): array
    {
        $running = 0.0;
        $taken   = array_fill(0, $columns, 0.0);

        foreach ($declared as $i => $entry) {
            if (!$entry['percent'] || $entry['span'] > 1) {
                continue;
            }

            // A column declared twice keeps the larger share and pays for it
            // once, which is what `widthDeclarations` already resolves to.
            $room = max(0.0, 1.0 - $running + $taken[$entry['col']]);
            $cut  = min($entry['value'], $room);

            $running += $cut - $taken[$entry['col']];
            $taken[$entry['col']] = $cut;

            $declared[$i]['value'] = $cut;
        }

        return $declared;
    }

    private function percentFloor(array $declared, int $columns, array $max): float
    {
        $percent = array_fill(0, $columns, 0.0);
        $length  = array_fill(0, $columns, 0.0);

        foreach ($declared as $entry) {
            if ($entry['span'] > 1) {
                continue;
            }

            if ($entry['percent']) {
                $percent[$entry['col']] = max($percent[$entry['col']], $entry['value']);

                continue;
            }

            $length[$entry['col']] = max($length[$entry['col']], $entry['value']);
        }

        $spoken = array_sum($percent);

        if ($spoken <= 0.0) {
            return 0.0;
        }

        // A column that names nothing needs room no percentage has left it, so
        // there is nothing to solve for and the containing block stands in.
        if ($spoken >= 1.0 && $percent !== array_filter($percent)) {
            return 0.0;
        }

        $rest  = 0.0;
        $needs = 0.0;

        foreach ($percent as $j => $share) {
            if ($share <= 0.0) {
                $rest += max($max[$j], $length[$j]);

                continue;
            }

            $needs = max($needs, $max[$j] / $share);
        }

        // Every column named a share, so there is no remainder to divide.
        if ($spoken >= 1.0) {
            return $needs;
        }

        return max($rest / (1.0 - $spoken), $needs);
    }

    /**
     * A declaration bigger than the table cannot have all of it. What is left
     * once every column that named nothing has its own content minimum is the
     * most the pinned ones can take between them, shared in proportion and
     * never cut below what their own content needs: Chrome leaves a
     * `width: 150%` cell 246.750 of a 300pt table rather than all 450.
     *
     * @param  array<int,float> $pinned
     * @param  float[]          $floor
     * @return array<int,float>
     */
    private function capPins(array $pinned, array $floor, float $available): array
    {
        if ($pinned === []) {
            return $pinned;
        }

        $room = $available;

        foreach ($floor as $j => $w) {
            if (!isset($pinned[$j])) {
                $room -= $w;
            }
        }

        $spoken = array_sum($pinned);

        if ($spoken <= $room || $spoken <= 0.0) {
            return $pinned;
        }

        $scale = max(0.0, $room) / $spoken;

        foreach ($pinned as $j => $w) {
            $pinned[$j] = max($floor[$j], $w * $scale);
        }

        return $pinned;
    }

    /**
     * Every column spoken for, and the table not the sum of them: CSS 2.1
     * §17.5.2 leaves the difference "distributed over the columns" and Chrome
     * shares it in proportion, so three columns each declaring 24% of a table
     * end up a third of it rather than 72% of it with a gap on the end.
     *
     * @param  float[] $widths
     * @return float[]
     */
    private function scaleToFill(array $widths, float $available): array
    {
        $total = array_sum($widths);

        if ($total <= 0.0) {
            $share = $available / max(1, count($widths));

            return array_map(static fn(): float => $share, $widths);
        }

        $scale = $available / $total;

        return array_map(static fn(float $w): float => $w * $scale, $widths);
    }

    /** @param float[] $widths */
    private function spreadSpan(array &$widths, int $start, int $span, float $required): void
    {
        $current = 0.0;

        for ($k = 0; $k < $span; $k++) {
            $current += $widths[$start + $k];
        }

        if ($required <= $current + 1e-9) {
            return;
        }

        $extra = $required - $current;

        for ($k = 0; $k < $span; $k++) {
            $share               = $current > 0 ? $widths[$start + $k] / $current : 1.0 / $span;
            $widths[$start + $k] += $extra * $share;
        }
    }

    /**
     * CSS 2.1 §17.5.2.2: below the minimum the table overflows; above the
     * maximum the surplus is shared out; in between, each column gets its
     * minimum plus a share of the slack proportional to its own flexibility.
     *
     * @param float[] $min
     * @param float[] $max
     *
     * @return float[]
     */
    /**
     * CSS 2.1 §17.5.2.1, the fixed algorithm: column widths come from `<col>`
     * first, then from the **first row's** cells, and whatever is left over is
     * split equally between the columns that named nothing. Content is never
     * consulted, which is the whole point: a long cell wraps instead of
     * stretching its column.
     *
     * Chrome on a 240px table whose first column asks for 40px: 31.50 / 74.25
     * / 74.25pt, where the auto algorithm gives 31.50 / 142.50 / 6.00.
     *
     * `$basis` is what a percentage is a percentage of, and it is the table
     * rather than the table's own containing block: a `<col style="width:24%">`
     * on a 400px table inside a 400pt page is 72pt wide, not 96.
     *
     * @param  array<int,array<int,object|null>> $grid
     * @param  array<int,float|string>           $columnWidths
     * @return array<int,float>
     */
    private function fixedWidths(
        array $grid,
        int $columns,
        float $available,
        array $columnWidths,
        float $basis,
    ): array {
        $widths = array_fill(0, $columns, null);

        $used = static function (float|string|null $value) use ($basis): ?float {
            if ($value === null) {
                return null;
            }

            if (is_string($value)) {
                return str_ends_with($value, '%')
                    ? (float) rtrim($value, '%') / 100.0 * $basis
                    : null;
            }

            return $value > 0.0 ? $value : null;
        };

        foreach ($columnWidths as $j => $value) {
            if ($j < $columns) {
                $widths[$j] = $used($value);
            }
        }

        foreach ($grid[0] ?? [] as $j => $cell) {
            if ($cell === null || $j >= $columns || $widths[$j] !== null) {
                continue;
            }

            if ($cell->originRow !== 0 || $cell->originCol !== $j) {
                continue;
            }

            $declared = $used($cell->node->width);

            if ($declared === null) {
                continue;
            }

            // A cell's declared width is a content width; the column has to
            // carry its edges too, the same conversion a box makes.
            $span = max(1, min($cell->node->colspan, $columns - $j));

            for ($k = 0; $k < $span; $k++) {
                $widths[$j + $k] = ($declared + $cell->node->edgeMain(true)) / $span;
            }
        }

        $spoken = 0.0;
        $free   = [];

        foreach ($widths as $j => $w) {
            if ($w === null) {
                $free[] = $j;

                continue;
            }

            $spoken += $w;
        }

        $share = $free === [] ? 0.0 : max(0.0, $available - $spoken) / count($free);

        foreach ($free as $j) {
            $widths[$j] = $share;
        }

        $out = array_map(static fn(?float $w): float => max(0.0, $w ?? 0.0), $widths);

        // §17.5.2.1 leaves a surplus "distributed over the columns" and there
        // is nobody left to give it to when every column named a width, so it
        // is shared in proportion: three columns each declaring 24% of a table
        // fill it rather than leaving 28% of it empty.
        return $free === [] && $available > $spoken
            ? $this->scaleToFill($out, $available)
            : $out;
    }

    private function distribute(array $min, array $max, float $available, array $pinned = []): array
    {
        // Pinned columns take their width off the top; the rest share what's left.
        if ($pinned !== []) {
            $fixed   = 0.0;
            $freeMin = [];
            $freeMax = [];

            foreach ($min as $j => $w) {
                if (isset($pinned[$j])) {
                    $fixed += $max[$j];
                    continue;
                }

                $freeMin[$j] = $w;
                $freeMax[$j] = $max[$j];
            }

            if ($freeMin === []) {
                $out = [];

                foreach ($min as $j => $_) {
                    $out[$j] = $max[$j];
                }

                return $out;
            }

            $shared = $this->distribute($freeMin, $freeMax, max($available - $fixed, 0.0));

            $out = [];

            foreach ($min as $j => $_) {
                $out[$j] = isset($pinned[$j]) ? $max[$j] : $shared[$j];
            }

            return $out;
        }

        $sumMin = array_sum($min);
        $sumMax = array_sum($max);
        $n      = count($min);

        if ($available <= $sumMin || $sumMin >= $sumMax) {
            if ($available > $sumMin) {
                // Nothing is flexible, so share the surplus by proportion,
                // unless there is nothing to be in proportion to. A table whose
                // columns are **all** empty has no content to scale from, and
                // Chrome splits the declared width equally between them:
                // `OB-table-zero-columns.html` `b1` is a `width: 120px` table of
                // two empty cells, 90.000 wide with two 45.000 columns, and `b2`
                // is three of 30.000. This returned the zeros it started with,
                // so the table, its rows and its cells were 0.000 (defect CD).
                //
                // One column with anything at all in it is enough to make the
                // proportional share the right answer, and it is Chrome's too:
                // `b3` gives an empty column 0.000 and the column beside it the
                // whole 90.000, and `b9`, whose only content is a cell's own
                // padding, does the same. Both were already exact.
                $extra = $available - $sumMin;

                foreach ($min as $j => $w) {
                    $min[$j] = $sumMin > 0 ? $w + $extra * ($w / $sumMin) : $extra / $n;
                }
            }

            return $min;
        }

        if ($available >= $sumMax) {
            $extra = $available - $sumMax;
            $out   = array_map(fn($w) => $sumMax > 0 ? $w + $extra * ($w / $sumMax) : $available / $n, $max);

            return $out;
        }

        $slack     = $available - $sumMin;
        $flexTotal = $sumMax - $sumMin;
        $out       = [];

        foreach ($min as $j => $w) {
            $flex    = $max[$j] - $w;
            $out[$j] = $w + ($flexTotal > 0 ? $slack * ($flex / $flexTotal) : $slack / $n);
        }

        return $out;
    }
}
