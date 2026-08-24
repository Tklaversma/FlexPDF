<?php

declare(strict_types=1);

namespace FlexPDF\Engine;

/**
 * CSS Grid Layout: placement (§8) and track sizing (§12).
 *
 * The shape of the work: build the implicit/explicit grid and place every
 * item into it, derive each track's base size and growth limit from its
 * sizing function and the items that touch it, grow the bases into the free
 * space, then hand whatever is left to the `fr` tracks. Rows are sized the
 * same way once the columns are known, because an item's height depends on
 * the width it ended up with.
 *
 * Implemented: explicit and auto placement in both flow directions, spans,
 * implicit tracks, `fr`, `minmax()`, `repeat()`, `min-content`/`max-content`,
 * percentages, row and column gaps, and item/content alignment.
 *
 * Not implemented: named lines and `grid-template-areas`, `auto-fill` and
 * `auto-fit` repetition counts, dense packing, subgrid, baseline alignment.
 */
final class GridLayout
{
    private const float INF = 1.0e9;

    public function __construct(
        private readonly FlexLayout $engine,
    ) {}

    public function layout(Node $grid, ?float $availWidth, ?float $availHeight): void
    {
        $items = array_values(
            array_filter(
                $grid->children,
                static fn(Node $n): bool => !$n->isOutOfFlow(),
            ),
        );

        $outerWidth = $this->engine->usedWidth($grid, $availWidth ?? 0.0) ?? $availWidth ?? 0.0;
        $innerWidth = max(0.0, $outerWidth - $grid->edgeMain(true));

        $this->engine->resolveChildEdges($grid, $innerWidth);

        // `repeat(auto-fill, ...)` can only be expanded now that the
        // container width is known.
        $explicitCols = $grid->gridTemplateColumns;
        $explicitRows = $grid->gridTemplateRows;

        if (StyleResolver::needsContainerSize($grid->gridColumnsRaw)) {
            $resolver = new StyleResolver();
            $names    = [];

            $expanded = $resolver->expandAutoRepeat(
                $grid->gridColumnsRaw,
                $innerWidth,
                $grid->columnGap,
                $grid->gridFontSize,
                $grid->gridRootFontSize,
            );

            $explicitCols = $resolver->trackList(
                $expanded,
                $grid->gridFontSize,
                $grid->gridRootFontSize,
                $names,
            );

            $grid->gridColumnNames = $names + $grid->gridColumnNames;
        }

        // Template areas define the explicit grid when no template is given.
        if ($grid->gridAreas !== []) {
            $rowsNeeded = 0;
            $colsNeeded = 0;

            foreach ($grid->gridAreas as [$r, $c, $rs, $cs]) {
                $rowsNeeded = max($rowsNeeded, $r + $rs);
                $colsNeeded = max($colsNeeded, $c + $cs);
            }

            while (count($explicitCols) < $colsNeeded) {
                $explicitCols[] = [
                    'minType'  => 'auto',
                    'minValue' => 0.0,
                    'maxType'  => 'auto',
                    'maxValue' => 0.0,
                ];
            }

            while (count($explicitRows) < $rowsNeeded) {
                $explicitRows[] = [
                    'minType'  => 'auto',
                    'minValue' => 0.0,
                    'maxType'  => 'auto',
                    'maxValue' => 0.0,
                ];
            }
        }

        // Nothing to lay out into: behave like an empty block. That includes
        // going through the clamp every other path here goes through, which
        // is what floors a used width at zero: a grid inside a block whose
        // margins are wider than it is otherwise reports a negative one.
        if ($items === []) {
            $grid->layoutWidth           = $this->engine->clampSize(
                $outerWidth,
                $grid->minWidth,
                $grid->maxWidth,
            );
            $grid->layoutHeight          = $this->engine->usedHeight(
                $grid,
                $availHeight ?? 0.0,
            ) ?? $grid->edgeCross(true);
            $grid->collapsedMarginTop    = $grid->margin['top'];
            $grid->collapsedMarginBottom = $grid->margin['bottom'];

            return;
        }

        $placement = $this->place($grid, $items, count($explicitCols), count($explicitRows));
        [$cells, $columnCount, $rowCount] = $placement;

        $columns = $this->tracksFor($explicitCols, $columnCount, $grid->gridAutoColumns, $grid);
        $rows    = $this->tracksFor($explicitRows, $rowCount, $grid->gridAutoRows, $grid);

        $colGap = $grid->columnGap;
        $rowGap = $grid->rowGap;

        // --- columns ---
        $colWidths = $this->sizeTracks(
            $columns,
            $innerWidth,
            max(0, $columnCount - 1) * $colGap,
            fn(int $track, string $mode): float => $this->columnContribution($cells, $track, $mode),
            fn(array $span, string $mode): array => $this->spanningColumns($cells, $span, $mode),
            $columnCount,
        );

        // --- measure items at their resolved widths, then size rows ---
        foreach ($cells as $cell) {
            $width = $this->extent($colWidths, $cell['col'], $cell['colSpan'], $colGap);
            $this->engine->measure($cell['node'], max(0.0, $width), null);
        }

        /*
         * The border-box height layout works in, the way `$outerWidth` above
         * is: `usedHeight()` is where `box-sizing` is read, and reading the
         * declaration directly took a `content-box` height as a border-box one
         * and then subtracted the padding from it a second time. A grid with
         * `padding: 20px` and `height: 40px` came out 40 CSS pixels tall with
         * no content box at all, where Chrome and this engine's own block and
         * flex paths both give 80. Defect GK,
         * `TA-static-flexgrid.html` a6.
         */
        $definiteHeight = $this->engine->usedHeight($grid, $availHeight ?? 0.0);
        $innerHeight    = $definiteHeight !== null
            ? max(0.0, $definiteHeight - $grid->edgeCross(true))
            : null;

        $rowHeights = $this->sizeTracks(
            $rows,
            $innerHeight ?? 0.0,
            max(0, $rowCount - 1) * $rowGap,
            fn(int $track, string $mode): float => $this->rowContribution($cells, $track),
            fn(array $span, string $mode): array => $this->spanningRows($cells, $span),
            $rowCount,
            $innerHeight === null,
        );

        // §12.8, in both axes: what is left over goes into the `auto` tracks
        // before anything is offset. The inline axis always has a definite
        // size to be left over from; the block axis only has one where the
        // grid declares a height.
        $colWidths = $this->stretchAuto(
            $colWidths,
            $columns,
            $columnCount,
            $grid->justifyContent,
            $innerWidth - max(0, $columnCount - 1) * $colGap,
        );

        if ($innerHeight !== null) {
            $rowHeights = $this->stretchAuto(
                $rowHeights,
                $rows,
                $rowCount,
                $grid->alignContent,
                $innerHeight - max(0, $rowCount - 1) * $rowGap,
            );
        }

        // --- position ---
        $contentWidth  = array_sum($colWidths) + max(0, $columnCount - 1) * $colGap;
        $contentHeight = array_sum($rowHeights) + max(0, $rowCount - 1) * $rowGap;

        [$colOffset, $colExtra] = $this->distribution(
            $grid->justifyContent,
            $innerWidth - $contentWidth,
            $columnCount,
        );

        [$rowOffset, $rowExtra] = $this->distribution(
            $grid->alignContent,
            ($innerHeight ?? $contentHeight) - $contentHeight,
            $rowCount,
        );

        $colStarts = [];
        $cursor    = $grid->edge('left') + $colOffset;

        foreach ($colWidths as $i => $w) {
            $colStarts[$i] = $cursor;
            $cursor        += $w + $colGap + $colExtra;
        }

        $rowStarts = [];
        $cursor    = $grid->edge('top') + $rowOffset;

        foreach ($rowHeights as $i => $h) {
            $rowStarts[$i] = $cursor;
            $cursor        += $h + $rowGap + $rowExtra;
        }

        foreach ($cells as $cell) {
            $node  = $cell['node'];
            $areaX = $colStarts[$cell['col']] ?? $grid->edge('left');
            $areaY = $rowStarts[$cell['row']] ?? $grid->edge('top');
            $areaW = $this->extent($colWidths, $cell['col'], $cell['colSpan'], $colGap + $colExtra);
            $areaH = $this->extent($rowHeights, $cell['row'], $cell['rowSpan'], $rowGap + $rowExtra);

            $justify = $node->justifySelf ?? $grid->justifyItems;
            $align   = $node->alignSelf ?? $grid->alignItems;

            $hasWidth = $node->width !== null;
            $inner    = max(0.0, $areaW - $node->marginMain(true));

            if (!$hasWidth) {
                if ($justify === 'stretch') {
                    $this->engine->measure($node, $inner, null);
                    $node->layoutWidth = $inner;
                } else {
                    // Anything other than stretch shrink-wraps first, then is
                    // aligned inside the track.
                    $fit = min($this->engine->maxContentWidth($node), $inner);
                    $this->engine->measure($node, max(0.0, $fit), null);
                    $node->layoutWidth = max(0.0, $fit);
                }
            }

            // An item with a ratio already has a block size, so `stretch`
            // gives way to it: CSS Box Alignment §6.2 makes the default
            // behave as `start` for exactly that case.
            if ($align === 'stretch' && $node->height === null && $node->aspectRatio === null) {
                $node->layoutHeight = max(0.0, $areaH - $node->marginCross(true));
            }

            $node->x = $areaX + $node->margin['left'] + $this->alignOffset(
                    $justify,
                    $areaW - $node->layoutWidth - $node->marginMain(true),
                );
            $node->y = $areaY + $node->margin['top'] + $this->alignOffset(
                    $align,
                    $areaH - $node->layoutHeight - $node->marginCross(true),
                );
        }

        $grid->layoutWidth           = $this->engine->clampSize($outerWidth, $grid->minWidth, $grid->maxWidth);
        $grid->layoutHeight          = $this->engine->clampSize(
            $definiteHeight ?? ($contentHeight + $grid->edgeCross(true)),
            $grid->minHeight,
            $grid->maxHeight,
        );
        $grid->collapsedMarginTop    = $grid->margin['top'];
        $grid->collapsedMarginBottom = $grid->margin['bottom'];
    }

    // -----------------------------------------------------------------
    // placement
    // -----------------------------------------------------------------
    /**
     * @param Node[] $items
     *
     * @return array{0:array<int,array{node:Node,row:int,col:int,rowSpan:int,colSpan:int}>,1:int,2:int}
     */
    private function place(Node $grid, array $items, int $explicitCols, int $explicitRows): array
    {
        $columnFlow = $grid->gridAutoFlow === 'column';
        $fixedAxis  = $columnFlow
            ? max(1, $explicitRows)
            : max(1, $explicitCols);

        $occupied = [];
        $cells    = [];
        $auto     = [];

        $mark = static function (array &$occupied, int $row, int $col, int $rowSpan, int $colSpan): void {
            for ($r = 0; $r < $rowSpan; $r++) {
                for ($c = 0; $c < $colSpan; $c++) {
                    $occupied[($row + $r) . ':' . ($col + $c)] = true;
                }
            }
        };

        // Explicitly placed items claim their cells first.
        foreach ($items as $item) {
            // A `grid-area: name` reference wins outright.
            if ($item->gridAreaName !== '' && isset($grid->gridAreas[$item->gridAreaName])) {
                [$ar, $ac, $ars, $acs] = $grid->gridAreas[$item->gridAreaName];
                $mark($occupied, $ar, $ac, $ars, $acs);
                $cells[] = [
                    'node'    => $item,
                    'row'     => $ar,
                    'col'     => $ac,
                    'rowSpan' => $ars,
                    'colSpan' => $acs,
                ];

                continue;
            }

            [$col, $colSpan] = $this->resolveAxis(
                $this->namedLine($item->gridColumnStartName, $grid->gridColumnNames, $item->gridColumnStart),
                $this->namedLine($item->gridColumnEndName, $grid->gridColumnNames, $item->gridColumnEnd),
                $item->gridColumnSpan,
            );

            [$row, $rowSpan] = $this->resolveAxis(
                $this->namedLine($item->gridRowStartName, $grid->gridRowNames, $item->gridRowStart),
                $this->namedLine($item->gridRowEndName, $grid->gridRowNames, $item->gridRowEnd),
                $item->gridRowSpan,
            );

            if ($col === null || $row === null) {
                $auto[] = [$item, $row, $col, $rowSpan, $colSpan];
                continue;
            }

            $mark($occupied, $row, $col, $rowSpan, $colSpan);

            $cells[] = [
                'node'    => $item,
                'row'     => $row,
                'col'     => $col,
                'rowSpan' => $rowSpan,
                'colSpan' => $colSpan,
            ];
        }

        // Then auto placement sweeps the grid in flow order.
        $cursorMajor = 0;
        $cursorMinor = 0;

        foreach ($auto as [$item, $row, $col, $rowSpan, $colSpan]) {
            // Dense packing back-fills holes, so each item starts its search
            // from the beginning rather than from the running cursor.
            if ($grid->gridDense) {
                $cursorMajor = 0;
                $cursorMinor = 0;
            }

            // An item with one axis pinned keeps it and searches the other,
            // rather than being swept along with fully-auto items.
            if ($col !== null || $row !== null) {
                $fixedRow = $row;
                $fixedCol = $col;
                $probe    = 0;

                while ($probe < 1000) {
                    $r = $fixedRow ?? $probe;
                    $c = $fixedCol ?? $probe;

                    $free = true;

                    for ($dr = 0; $dr < $rowSpan && $free; $dr++) {
                        for ($dc = 0; $dc < $colSpan; $dc++) {
                            if (isset($occupied[($r + $dr) . ':' . ($c + $dc)])) {
                                $free = false;
                                break;
                            }
                        }
                    }

                    if ($free) {
                        $mark($occupied, $r, $c, $rowSpan, $colSpan);
                        $cells[] = [
                            'node'    => $item,
                            'row'     => $r,
                            'col'     => $c,
                            'rowSpan' => $rowSpan,
                            'colSpan' => $colSpan,
                        ];

                        break;
                    }

                    $probe++;
                }

                continue;
            }

            $span = $columnFlow ? $rowSpan : $colSpan;
            $span = max(1, min($span, $fixedAxis));

            while (true) {
                $minor = $cursorMinor;

                if ($minor + $span > $fixedAxis) {
                    $cursorMajor++;
                    $cursorMinor = 0;
                    continue;
                }

                $r = $columnFlow ? $minor : $cursorMajor;
                $c = $columnFlow ? $cursorMajor : $minor;

                $free = true;

                for ($dr = 0; $dr < $rowSpan && $free; $dr++) {
                    for ($dc = 0; $dc < $colSpan; $dc++) {
                        if (isset($occupied[($r + $dr) . ':' . ($c + $dc)])) {
                            $free = false;
                            break;
                        }
                    }
                }

                if (!$free) {
                    $cursorMinor++;
                    continue;
                }

                $mark($occupied, $r, $c, $rowSpan, $colSpan);

                $cells[] = [
                    'node'    => $item,
                    'row'     => $r,
                    'col'     => $c,
                    'rowSpan' => $rowSpan,
                    'colSpan' => $colSpan,
                ];

                $cursorMinor += $span;

                break;
            }
        }

        $columnCount = max($explicitCols, 1);
        $rowCount    = max($explicitRows, 1);

        foreach ($cells as $cell) {
            $columnCount = max($columnCount, $cell['col'] + $cell['colSpan']);
            $rowCount    = max($rowCount, $cell['row'] + $cell['rowSpan']);
        }

        return [$cells, $columnCount, $rowCount];
    }

    /**
     * A named line resolves to a 1-based line number; otherwise the numeric
     * value already parsed from the stylesheet stands.
     *
     * @param array<string,int> $names
     */
    private function namedLine(string $name, array $names, ?int $fallback): ?int
    {
        if ($name !== '' && isset($names[$name])) {
            return $names[$name] + 1;
        }

        return $fallback;
    }

    /** @return array{0:?int,1:int} zero-based start line and span */
    private function resolveAxis(?int $start, ?int $end, int $span): array
    {
        if ($start !== null && $start > 0) {
            $index = $start - 1;

            if ($end !== null && $end > $start) {
                return [$index, $end - $start];
            }

            return [$index, max(1, $span)];
        }

        if ($end !== null && $end > 1) {
            $length = max(1, $span);

            return [max(0, $end - 1 - $length), $length];
        }

        return [null, max(1, $span)];
    }

    // -----------------------------------------------------------------
    // track sizing
    // -----------------------------------------------------------------
    /** @return array<int,array{minType:string,minValue:float,maxType:string,maxValue:float}> */
    private function tracksFor(array $explicit, int $needed, ?array $auto, Node $grid): array
    {
        $tracks   = $explicit;
        $implicit = $auto ?? [
            'minType'  => 'auto',
            'minValue' => 0.0,
            'maxType'  => 'auto',
            'maxValue' => 0.0,
        ];

        while (count($tracks) < $needed) {
            $tracks[] = $implicit;
        }

        return array_slice($tracks, 0, max($needed, 1));
    }

    /**
     * @param array<int,array{minType:string,minValue:float,maxType:string,maxValue:float}> $tracks
     * @param callable(int,string):float                                                    $contribution
     * @param callable(array,string):array                                                  $spanning
     *
     * @return float[]
     */
    private function sizeTracks(
        array $tracks,
        float $available,
        float $gaps,
        callable $contribution,
        callable $spanning,
        int $count,
        bool $contentSized = false,
    ): array {
        $space = max(0.0, $available - $gaps);

        $base      = [];
        $limit     = [];
        $frWeights = [];

        for ($i = 0; $i < $count; $i++) {
            $t = $tracks[$i] ?? [
                'minType'  => 'auto',
                'minValue' => 0.0,
                'maxType'  => 'auto',
                'maxValue' => 0.0,
            ];

            $base[$i] = match ($t['minType']) {
                'fixed'       => $t['minValue'],
                'percent'     => $t['minValue'] / 100.0 * $space,
                'max-content' => $contribution($i, 'max'),
                default       => $contribution($i, 'min'),
            };

            $limit[$i] = match ($t['maxType']) {
                'fixed'       => $t['maxValue'],
                'percent'     => $t['maxValue'] / 100.0 * $space,
                'min-content' => $contribution($i, 'min'),
                'fr'          => self::INF,
                default       => $contribution($i, 'max'),
            };

            $frWeights[$i] = $t['maxType'] === 'fr' ? $t['maxValue'] : 0.0;

            if ($limit[$i] < $base[$i]) {
                $limit[$i] = $base[$i];
            }
        }

        // Items spanning several tracks must fit across them.
        foreach ($spanning([], 'min') as [$start, $span, $required]) {
            $slice = [];

            for ($k = 0; $k < $span; $k++) {
                $slice[] = $start + $k;
            }

            $this->spread($base, $slice, $required);
        }
        foreach ($spanning([], 'max') as [$start, $span, $required]) {
            $slice = [];

            for ($k = 0; $k < $span; $k++) {
                $slice[] = $start + $k;
            }

            $this->spread($limit, $slice, $required);
        }

        for ($i = 0; $i < $count; $i++) {
            if ($limit[$i] < $base[$i]) {
                $limit[$i] = $base[$i];
            }
        }

        // A content-sized axis simply takes each track's base.
        if ($contentSized) {
            $out = [];

            for ($i = 0; $i < $count; $i++) {
                $out[$i] = $frWeights[$i] > 0 ? $base[$i] : min($base[$i], $limit[$i]);
                $out[$i] = max($out[$i], $base[$i]);
            }

            return $out;
        }

        // Grow bases toward their limits, freezing each as it is reached.
        $free     = $space - array_sum($base);
        $flexible = [];

        for ($i = 0; $i < $count; $i++) {
            if ($frWeights[$i] <= 0 && $limit[$i] > $base[$i] + 1e-9) {
                $flexible[$i] = true;
            }
        }

        $guard = 0;

        while ($free > 1e-6 && $flexible !== [] && $guard++ < 64) {
            $share = $free / count($flexible);
            $used  = 0.0;

            foreach (array_keys($flexible) as $i) {
                $room     = $limit[$i] - $base[$i];
                $grow     = min($share, $room);
                $base[$i] += $grow;
                $used     += $grow;

                if ($room - $grow <= 1e-9) {
                    unset($flexible[$i]);
                }
            }

            if ($used <= 1e-9) {
                break;
            }

            $free -= $used;
        }

        // §12.7: an `fr` track is *set* to its share of the leftover space,
        // not grown from its base, so its content contribution does not get
        // added on top of the fraction it is entitled to.
        $totalFr = array_sum($frWeights);
        if ($totalFr > 0) {
            $rigid = 0.0;

            for ($i = 0; $i < $count; $i++) {
                if ($frWeights[$i] <= 0) {
                    $rigid += $base[$i];
                }
            }

            $leftover = max(0.0, $space - $rigid);
            $fraction = $leftover / $totalFr;

            for ($i = 0; $i < $count; $i++) {
                if ($frWeights[$i] > 0) {
                    $base[$i] = max($base[$i], $frWeights[$i] * $fraction);
                }
            }
        }

        return $base;
    }

    /** @param float[] $sizes @param int[] $indices */
    private function spread(array &$sizes, array $indices, float $required): void
    {
        $current = 0.0;

        foreach ($indices as $i) {
            $current += $sizes[$i] ?? 0.0;
        }

        if ($required <= $current + 1e-9 || $indices === []) {
            return;
        }

        $extra = $required - $current;

        foreach ($indices as $i) {
            $share     = $current > 0 ? ($sizes[$i] ?? 0.0) / $current : 1.0 / count($indices);
            $sizes[$i] = ($sizes[$i] ?? 0.0) + $extra * $share;
        }
    }

    private function columnContribution(array $cells, int $track, string $mode): float
    {
        $max = 0.0;

        foreach ($cells as $cell) {
            if ($cell['col'] !== $track || $cell['colSpan'] !== 1) {
                continue;
            }

            $node = $cell['node'];

            $w = $mode === 'min'
                ? $this->engine->minContentWidth($node)
                : $this->engine->maxContentWidth($node);

            $max = max($max, $w + $node->marginMain(true));
        }

        return $max;
    }

    /** @return array<int,array{0:int,1:int,2:float}> */
    private function spanningColumns(array $cells, array $_unused, string $mode): array
    {
        $out = [];

        foreach ($cells as $cell) {
            if ($cell['colSpan'] <= 1) {
                continue;
            }

            $node = $cell['node'];

            $w = $mode === 'min'
                ? $this->engine->minContentWidth($node)
                : $this->engine->maxContentWidth($node);

            $out[] = [$cell['col'], $cell['colSpan'], $w + $node->marginMain(true)];
        }

        return $out;
    }

    private function rowContribution(array $cells, int $track): float
    {
        $max = 0.0;

        foreach ($cells as $cell) {
            if ($cell['row'] !== $track || $cell['rowSpan'] !== 1) {
                continue;
            }

            $max = max($max, $cell['node']->layoutHeight + $cell['node']->marginCross(true));
        }

        return $max;
    }

    /** @return array<int,array{0:int,1:int,2:float}> */
    private function spanningRows(array $cells, array $_unused): array
    {
        $out = [];

        foreach ($cells as $cell) {
            if ($cell['rowSpan'] <= 1) {
                continue;
            }

            $out[] = [
                $cell['row'],
                $cell['rowSpan'],
                $cell['node']->layoutHeight + $cell['node']->marginCross(true),
            ];
        }

        return $out;
    }

    // -----------------------------------------------------------------
    // geometry helpers
    // -----------------------------------------------------------------
    /** @param float[] $sizes */
    private function extent(array $sizes, int $start, int $span, float $gap): float
    {
        $total = 0.0;

        for ($i = 0; $i < $span; $i++) {
            $total += $sizes[$start + $i] ?? 0.0;
        }

        return $total + max(0, $span - 1) * $gap;
    }

    /**
     * CSS Grid §12.8, the step between sizing the tracks and placing them.
     *
     * `align-content` and `justify-content` are `normal` at their initial
     * value and `normal` behaves as STRETCH on a grid container, which expands
     * the tracks whose max sizing function is `auto` by dividing the free
     * space EQUALLY among them.
     *
     * **Equally among them, not to a common size.** On
     * `TG-grid-stretch.html` s9 the two `auto` columns hold words 1.344px
     * apart in width and Chrome's two tracks are still 1.344px apart after the
     * stretch. s7 is the same rule with one auto track beside a fixed one, and
     * the whole of the free space lands on the auto one.
     *
     * A grid with no `auto` track has nothing to stretch, so it falls back to
     * the `start` every other value already goes through, which s6 measures
     * with `grid-template-rows: 12px 12px`, and a declared `start` suppresses
     * it outright, which s10 measures. Defect GO.
     *
     * @param  float[] $sizes
     * @param  array<int,array{minType:string,minValue:float,maxType:string,maxValue:float}> $tracks
     * @param  float   $space the axis's own size with the gaps already off it
     * @return float[]
     */
    private function stretchAuto(array $sizes, array $tracks, int $count, string $mode, float $space): array
    {
        if ($mode !== 'normal' && $mode !== 'stretch') {
            return $sizes;
        }

        $auto = [];

        for ($i = 0; $i < $count; $i++) {
            if (($tracks[$i]['maxType'] ?? 'auto') === 'auto') {
                $auto[] = $i;
            }
        }

        $free = $space - array_sum($sizes);

        if ($auto === [] || $free <= 0.01) {
            return $sizes;
        }

        $share = $free / count($auto);

        foreach ($auto as $i) {
            $sizes[$i] += $share;
        }

        return $sizes;
    }

    /** @return array{0:float,1:float} leading offset and per-gap extra */
    private function distribution(string $mode, float $free, int $count): array
    {
        if ($free <= 0.01 || $count <= 0) {
            return [0.0, 0.0];
        }

        return match ($mode) {
            'flex-end', 'end' => [$free, 0.0],
            'center'          => [$free / 2, 0.0],
            'space-between'   => [0.0, $count > 1 ? $free / ($count - 1) : 0.0],
            'space-around'    => [$free / $count / 2, $free / $count],
            'space-evenly'    => [$free / ($count + 1), $free / ($count + 1)],
            default           => [0.0, 0.0],
        };
    }

    private function alignOffset(string $mode, float $free): float
    {
        return $this->engine->alignOffset($mode, $free);
    }
}
