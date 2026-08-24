<?php

declare(strict_types=1);

namespace FlexPDF\Engine;

/**
 * Paints one box's decoration and content into a PDF.
 *
 * Both the whole-tree painter and the fragment painter need identical
 * behavior for backgrounds, borders, images, SVG and text; keeping it in one
 * place stops the two drifting apart.
 */
final class BoxPainter
{
    /**
     * How many copies of one `background-image` a single box may paint. A one
     * point tile on an A4 page is already 600,000 of them, so this is a
     * safety ceiling on author-controlled input, not a design limit.
     */
    private const int MAX_TILES = 4096;

    /** The broken-image placeholder's square, in points. Chrome's is 16 CSS pixels. */
    private const float BROKEN_ICON = 12.0;

    /**
     * Draw a single box. Children are not touched: the caller walks the tree.
     *
     * @param LineBox[]     $lines     line boxes to draw, which for a fragment
     *                                 may be only part of what the node holds
     * @param bool          $decorates whether this piece owns the box's
     *                                 background, border and replaced content,
     *                                 or only its lines
     * @param string[]|null $edges     which of the box's four edges this piece
     *                                 actually owns. `box-decoration-break`
     *                                 defaults to `slice`, so a page break is
     *                                 not an edge: the border and the outline
     *                                 close only where the box itself does.
     *                                 Null means the whole box.
     */
    public static function paint(
        Pdf $pdf,
        Node $n,
        float $x,
        float $y,
        float $w,
        float $h,
        array $lines,
        bool $decorates = true,
        ?array $edges = null,
        ?array $slice = null,
    ): void {
        $edges = self::ownedEdges($n, $edges);

        // Document structure, registered wherever a box is painted so both
        // painting paths pick it up rather than only the paginated one.
        if ($n->anchorId !== null) {
            $pdf->addAnchor($n->anchorId, $y);
        }

        if ($n->outlineTitle !== '') {
            $pdf->addOutlineEntry(
                $n->outlineTitle,
                $n->outlineLevel - 1,
                $y,
                'n' . spl_object_id($n),
            );
        }

        // An interactive field draws nowhere near here: its ink goes into the
        // widget's own appearance stream, which a reader paints over the page
        // and replaces the moment anyone fills the field in.
        if ($n->formField !== null && $n->visible && self::paintField($pdf, $n, $x, $y, $w, $h, $edges)) {
            return;
        }

        if ($n->formOwner !== null && $n->visible && self::paintFieldValue($pdf, $n, $x, $y, $lines)) {
            return;
        }

        /*
         * A tagged document wraps this box's ink in marked-content sequences
         * so the structure tree can point at them. Every call below is a no-op
         * on an untagged document, and they are here rather than in either
         * caller because this is the one place both painting paths already
         * meet.
         */

        // `visibility: hidden` suppresses this box's own ink and nothing else.
        // Its children are painted by the caller and decide for themselves,
        // which is what lets a visible descendant show through a hidden
        // ancestor.
        if ($n->visible && $decorates) {
            /*
             * A background, a border and a shadow say how the box looks and
             * not what it says, so a tagged document marks them as artifacts
             * and a reader skips them. Keeping them out is what stops a
             * `Table` element holding its own border as content, which is the
             * shape a conformance checker refuses, and it drops every box that
             * exists only to be a colored bar out of the tree entirely.
             *
             * It is painted OUTSIDE the box's own overflow clip, below,
             * because CSS cuts overflow at the padding box and the border,
             * the outline and an outer shadow all sit on the far side of that
             * edge. Defect GN.
             */
            $pdf->openArtifact();

            try {
                self::paintDecoration($pdf, $n, $x, $y, $w, $h, $edges, true, $slice);
            } finally {
                $pdf->closeContent();
            }
        }

        // What the box holds rather than what the box is: its picture, its
        // marker and its own text, all of which the overflow clip cuts. The
        // caller pushes the same clip around the descendants.
        $clipped = self::pushOverflowClip($pdf, $n, $x, $y, $w, $h, $edges);

        try {
            if ($n->visible && $decorates) {
                // A picture is content, so it goes back inside the element.
                $pdf->openContent($n);

                try {
                    if ($n->image !== null && $w > 0 && $h > 0) {
                        self::drawFitted($pdf, $n, $x, $y, $w, $h);
                    }

                    if ($n->svg !== null && $w > 0 && $h > 0) {
                        self::drawSvg($pdf, $n, $x, $y, $w, $h);
                    }

                    if ($n->brokenImage !== 'none' && $w > 0 && $h > 0) {
                        self::paintBrokenImage($pdf, $n, $x, $y, $w, $h);
                    }
                } finally {
                    $pdf->closeContent();
                }
            }

            // An item that hosts its own marker is not a text box and has no
            // lines to be handed, so it cannot go through the branch below.
            if ($n->display !== 'text') {
                self::paintHostedLabel($pdf, $n, $x, $y);
            }

            if ($n->display === 'text' && $lines !== []) {
                self::paintLabel($pdf, $n, $x, $y, $lines);

                // Ordinal 0 is the start of the box's own content, which is
                // where the text before the first atomic inline on its lines
                // sits.
                $pdf->openContent($n, 0);

                try {
                    self::paintText($pdf, $n, $x, $y, $lines);
                } finally {
                    $pdf->closeContent();
                }
            }
        } finally {
            self::popEffects($pdf, $clipped);
        }
    }

    /**
     * The edges this piece draws: what the caller allows and what the box
     * itself still owns, which are two different cuts of the same box.
     *
     * A page break tells the caller ({@see Html::paintFragment}) and a column
     * boundary tells the box ({@see Node::$fragmentEdges}), so a box a column
     * cut and a page cut again owns only what both leave it. `clone` draws all
     * four on every piece, which is the whole of what the value asks for.
     *
     * @param  string[]|null $edges
     * @return string[]|null
     */
    private static function ownedEdges(Node $n, ?array $edges): ?array
    {
        if ($n->fragmentEdges === null || $n->decorationBreak === 'clone') {
            return $edges;
        }

        if ($edges === null) {
            return $n->fragmentEdges;
        }

        return array_values(array_intersect($edges, $n->fragmentEdges));
    }

    /**
     * A form control as an interactive field, or false where the box cannot
     * carry one and has to be drawn as an ordinary box after all.
     *
     * A checkbox and a radio have two appearances over one rectangle, so the
     * decoration is drawn twice: once with the mark and once without. Every
     * other field has one, and its value is drawn into it by the text child.
     *
     * @param string[]|null $edges
     */
    private static function paintField(
        Pdf $pdf,
        Node $n,
        float $x,
        float $y,
        float $w,
        float $h,
        ?array $edges,
    ): bool {
        $fresh = $pdf->beginWidget($n, $x, $y, $w, $h);

        if (!$fresh) {
            return $pdf->widgetHeight($n) !== null;
        }

        $pdf->captureWidget($n, 'off', static function () use ($pdf, $n, $w, $h, $edges): void {
            self::paintDecoration($pdf, $n, 0.0, 0.0, $w, $h, $edges, false);
        });

        if ($n->formField?->type->isToggle() === true) {
            $pdf->captureWidget($n, 'on', static function () use ($pdf, $n, $w, $h, $edges): void {
                self::paintDecoration($pdf, $n, 0.0, 0.0, $w, $h, $edges, true);
            });
        }

        return true;
    }

    /**
     * The value a control shows, drawn into its widget's appearance stream.
     *
     * The offset is where this box was painted less where the control was, so
     * it is right in whichever space the caller was working in. Reading the
     * child's own `x` and `y` off the node is what a flex item breaks: it is
     * blockified, so it reaches the fragmenter and its text child arrives here
     * with the page's coordinates rather than the control's.
     *
     * @param LineBox[] $lines
     */
    private static function paintFieldValue(Pdf $pdf, Node $n, float $x, float $y, array $lines): bool
    {
        $owner  = $n->formOwner;
        $origin = $owner === null ? null : $pdf->widgetOrigin($owner);

        if ($owner === null || $origin === null) {
            return false;
        }

        $pdf->captureWidget($owner, 'value', static function () use ($pdf, $n, $x, $y, $origin, $lines): void {
            if ($n->display === 'text' && $lines !== []) {
                self::paintText($pdf, $n, $x - $origin[0], $y - $origin[1], $lines);
            }
        });

        return true;
    }

    /**
     * The box's own background, border, outline and shadows.
     *
     * @param string[]|null $edges
     * @param bool          $mark  whether a checked control's mark is drawn,
     *                             which is false for the off state of a field
     * @param array{0:float,1:float}|null $slice this piece's own offset into
     *                             the whole box and the whole box's height,
     *                             for a box the fold cut
     */
    private static function paintDecoration(
        Pdf $pdf,
        Node $n,
        float $x,
        float $y,
        float $w,
        float $h,
        ?array $edges,
        bool $mark = true,
        ?array $slice = null,
    ): void {
        self::paintShadows($pdf, $n, $x, $y, $w, $h, false);

        // A checked checkbox's mark is a fill over the whole border box, and it
        // stands in for the box's own background rather than joining it: that
        // is what the box drew before it had two states to draw.
        $fill = ($mark ? $n->checkMark : null) ?? $n->background;

        if ($fill !== null) {
            // The colour is painted in the area the LAST `background-clip`
            // names, which is the one the builder keeps on the node. The
            // initial value is the border box, so this is the same rectangle
            // the painter filled before the property was read.
            [$fillX, $fillY, $fillW, $fillH, $fillRadius] =
                self::boxArea($n, $n->backgroundClip, $x, $y, $w, $h);

            $fillRadius = self::radiusFor($fillRadius, $edges);

            // A table row paints its background behind its cells and not
            // through the border spacing between them, which is the table's
            // own to show (CSS 2.1 §17.5.1). Every other box fills its whole
            // border box. {@see Node::$backgroundBands}.
            if ($n->backgroundBands === []) {
                $pdf->fillRect($fillX, $fillY, $fillW, $fillH, $fill, $fillRadius);
            } else {
                foreach ($n->backgroundBands as [$offset, $bandWidth]) {
                    $pdf->fillRect($x + $offset, $fillY, $bandWidth, $fillH, $fill, $fillRadius);
                }
            }
        }

        // One page's slice of a split box paints the whole box's layers and
        // shows only its own band of them.
        //
        // The offset comes off the fragment when there is one, because every
        // page's slice of a childless box is the **same** node and a node can
        // hold one offset. A container's is on the proxy node its decoration
        // is painted through, which is a fresh node per page.
        $sliced = $n->backgroundLayers === [] ? null : ($slice ?? $n->slicedBackground);

        if ($sliced !== null) {
            $pdf->pushClip($x, $y, $w, $h);
        }

        foreach ($n->backgroundLayers as $layer) {
            self::paintBackgroundImage(
                $pdf,
                $n,
                $layer,
                $x,
                $y - ($sliced[0] ?? 0.0),
                $w,
                $sliced[1] ?? $h,
            );
        }

        if ($sliced !== null) {
            $pdf->pop();
        }

        // CSS puts an inner shadow above the background and below the border,
        // which is what stops a translucent border showing it.
        self::paintShadows($pdf, $n, $x, $y, $w, $h, true);

        self::strokeColumnRules($pdf, $n, $x, $y);

        // A border image replaces the border rather than decorating it: a box
        // whose source loads paints no `border-color` anywhere, which is what
        // `RM-border-image.html` `s1` says with no `#999` on it at all.
        if ($n->borderImage !== null && self::paintBorderImage($pdf, $n, $x, $y, $w, $h)) {
            // painted
        } elseif ($n->border !== null) {
            self::strokeBorder($pdf, $n, $x, $y, $w, $h, $edges);
        }

        if ($n->outline !== null) {
            self::strokeOutline($pdf, $n, $x, $y, $w, $h, $edges);
        }
    }

    /**
     * The lines this piece of the box owns, and everything sitting on them.
     *
     * @param LineBox[] $lines
     */
    private static function paintText(Pdf $pdf, Node $n, float $x, float $y, array $lines): void
    {
        // The inline boxes' own backgrounds and borders go down before
        // anything on the line, so neither an atomic inline inside one nor the
        // glyphs beside it can be covered by a band drawn after them.
        $pdf->paintInlineBoxes($lines, $x, $y);

        // Atomic inlines go down before the glyphs beside them, so a box with
        // a background can never cover its neighbours' text.
        self::paintAtomicInlines($pdf, $lines, $x, $y);

        self::paintInsideMarkers($pdf, $n, $lines, $x, $y);

        // PDF has no text shadow; draw an offset copy underneath per layer.
        // Blur is not reproducible, so it is deliberately ignored rather than
        // faked with multiple passes. The list is front to back, so it is
        // walked in reverse: the last shadow goes down first and the first one
        // ends up on top of the others and under the text.
        foreach (array_reverse($n->textShadow) as $shadow) {
            $pdf->paintLines(
                $lines,
                $x + $shadow['x'],
                $y + $shadow['y'],
                $shadow['color'],
            );
        }

        $pdf->paintLines($lines, $x, $y, null, $n);
    }

    /**
     * Draw the `inside` list markers sitting on these lines.
     *
     * An `inside` marker is on the line rather than beside it, so it has an
     * advance of its own and the item's own text starts after it. The advance
     * is layout's, {@see InlineRun::markerMetrics}, and what is left here is
     * the drawing: the shape starts at the item's own left edge, which is
     * where the line put it, and sits half a box above that line's baseline,
     * exactly as the `outside` spelling does.
     *
     * A shape and a picture are ink rather than characters, so this is where
     * their `Lbl` has to be opened: they never reach {@see Pdf::paintLines},
     * which is where an `<ol>`'s number gets its own. The box's sequence is
     * split around this drawing and reopened after it, exactly as that loop
     * splits around an atomic inline, so the marker's ink is one mark of its
     * own inside the `LI` rather than part of the item's `LBody`. Defect FS,
     * and the ordinal is the marker's own place on the line, so a reader gets
     * it before the words it labels.
     *
     * @param LineBox[] $lines
     */
    private static function paintInsideMarkers(Pdf $pdf, Node $n, array $lines, float $x, float $y): void
    {
        $cursor = $y;
        $order  = $pdf->inlineOrder($n);

        foreach ($lines as $line) {
            foreach ($line->items as $item) {
                $run = $item->run;

                if ($item->isAtomic() || !$run->visible) {
                    continue;
                }

                $at = $order[spl_object_id($item)] ?? null;

                // A picture is placed off its own box and a shape off the
                // ascent, so the two take different branches here even though
                // the line gave both the same left edge.
                if ($run->markerImage !== null) {
                    self::inLabel($pdf, $run, $at, static function () use (
                        $pdf, $run, $item, $line, $x, $cursor,
                    ): void {
                        self::drawMarkerImage(
                            $pdf,
                            $run->markerImage,
                            $run->markerImageWidth,
                            $run->markerImageHeight,
                            $x + $item->x,
                            $cursor,
                            $line,
                        );
                    });

                    continue;
                }

                if ($run->markerShape === null) {
                    continue;
                }

                $metrics = $run->markerMetrics();

                if ($metrics['shape'] <= 0.0) {
                    continue;
                }

                self::inLabel($pdf, $run, $at, static function () use (
                    $pdf, $run, $item, $line, $x, $cursor, $metrics,
                ): void {
                    self::drawMarkerShape(
                        $pdf,
                        $run,
                        $x + $item->x,
                        $cursor + $line->baseline,
                        $metrics,
                    );
                });
            }

            $cursor += $line->height;
        }
    }

    /**
     * Run $draw with the box's content sequence split around it, so this
     * marker's ink is a `Lbl` of its own.
     *
     * The box's own sequence is reopened afterwards at the next ordinal, which
     * is where the item's words start, so the split leaves the text exactly
     * where it was. On an untagged document `splitContent()` is a no-op and
     * this costs one closure.
     */
    private static function inLabel(Pdf $pdf, InlineRun $run, ?int $at, callable $draw): void
    {
        if ($at === null) {
            $draw();

            return;
        }

        $pdf->splitContent($at, 'Lbl', (string) spl_object_id($run));

        try {
            $draw();
        } finally {
            $pdf->splitContent($at + 1);
        }
    }

    /**
     * Draw the atomic inlines sitting on these lines.
     *
     * The box was laid out in its own coordinate space, so the line decides
     * where it goes: its baseline sits on the line's baseline, and everything
     * inside it moves by the same delta. Nothing absolute is stored on the
     * box, which is what lets the identical box paint on whichever page its
     * line was fragmented onto.
     *
     * @param LineBox[] $lines
     */
    private static function paintAtomicInlines(Pdf $pdf, array $lines, float $x, float $y): void
    {
        $cursor = $y;

        foreach ($lines as $line) {
            foreach ($line->items as $item) {
                if (!$item->isAtomic()) {
                    continue;
                }

                $box = $item->run->box;

                self::paintSubtree(
                    $pdf,
                    $box,
                    $x + $item->x + $box->margin['left'],
                    $cursor + $line->baseline - $item->baselineShift
                        - $box->baselineOffset() + $box->margin['top'],
                );
            }

            $cursor += $line->height;
        }
    }

    /**
     * Paint a box and its descendants, starting at a given position.
     *
     * The subtree holds the parent-relative offsets layout produced, never
     * accumulated ones, so the sum happens here on the way down. That is what
     * keeps the box paintable more than once: on two pages, or after a second
     * measuring pass.
     */
    /**
     * A box's children in document order, with a flex container's `order`
     * applied. CSS Flexbox §5.4, and it is only observable where two flex
     * items overlap.
     *
     * `HtmlBuilder` hands out its stacking serial numbers in this order too,
     * so the numbers and the painter agree about which item came first.
     *
     * @return Node[]
     */
    public static function orderedChildren(Node $n): array
    {
        $children = $n->children;

        if ($n->display === 'flex') {
            usort($children, static fn(Node $a, Node $b): int => $a->order <=> $b->order);
        }

        return $children;
    }

    /**
     * The order a box's children are painted in.
     *
     * The sort is stable, so children that ask for the same place keep the
     * order above, which is what an unset `z-index` and an unset `order`
     * mean.
     *
     * **A sibling sort cannot express Appendix E on its own.** A raised
     * grandchild that belongs in the stacking context above its parent has to
     * be lifted out of the recursion, and this walk never leaves the subtree
     * it is in. `Html::render()` sorts a whole page's fragments in one list
     * and does express it; this path is used by the whole-tree painter and by
     * the contents of an atomic inline, and neither can be reached with a
     * document that needs the difference.
     *
     * @return Node[]
     */
    public static function paintOrder(Node $n): array
    {
        $children = self::orderedChildren($n);

        usort($children, static fn(Node $a, Node $b): int => self::compareStack($a, $b));

        return $children;
    }

    /**
     * CSS 2.1 Appendix E's painting order for two boxes, read off the paths
     * `HtmlBuilder` gave them. Negative when $a paints first.
     *
     * Term by term, and the path that runs out first paints first: that is a
     * box making a stacking context painting its own background under
     * everything inside it, which is Appendix E's first step.
     */
    public static function compareStack(Node $a, Node $b): int
    {
        $shared = min(count($a->stackPath), count($b->stackPath));

        for ($i = 0; $i < $shared; $i++) {
            if ($a->stackPath[$i] !== $b->stackPath[$i]) {
                return $a->stackPath[$i] <=> $b->stackPath[$i];
            }
        }

        return count($a->stackPath) <=> count($b->stackPath);
    }

    private static function paintSubtree(Pdf $pdf, Node $n, float $x, float $y): void
    {
        $inGroup = self::makesGroup($n);

        if ($inGroup) {
            $pdf->beginGroup();
        }

        $pushed = self::pushEffects($pdf, $n, $x, $y, $n->layoutWidth, $n->layoutHeight, null, $inGroup);

        self::paint($pdf, $n, $x, $y, $n->layoutWidth, $n->layoutHeight, $n->lineBoxes);

        // The box's own decoration is outside this clip and everything the box
        // holds is inside it, which is what a padding-box clip edge means.
        // Defect GN.
        $clipped = self::pushOverflowClip($pdf, $n, $x, $y, $n->layoutWidth, $n->layoutHeight);

        foreach (self::paintOrder($n) as $child) {
            self::paintSubtree($pdf, $child, $x + $child->x, $y + $child->y);
        }

        self::popEffects($pdf, $clipped);
        self::popEffects($pdf, $pushed);

        if (!$inGroup) {
            return;
        }

        ['name' => $name, 'inline' => $inline] = $pdf->closeGroup(
            [$x, $y, $n->layoutWidth, $n->layoutHeight],
            null,
            $n->opacity,
        );

        if ($name === null && $inline === '') {
            return;
        }

        $composite = self::pushGroupEffects($pdf, $n, $x, $y, $n->layoutWidth, $n->layoutHeight);

        $name === null ? $pdf->raw($inline) : $pdf->drawGroup($name);

        self::popEffects($pdf, $composite);
    }

    /**
     * The list marker, in a marked-content sequence of its own.
     *
     * An `outside` marker hangs to the left of the content box, on the first
     * line's baseline, and only where that first line is on this page: a
     * bullet must not repeat on the item's continuation.
     *
     * **It gets its own sequence because it is its own structure.** Chrome's
     * tagged export puts the marker in a `Lbl` inside the `LI` and the item's
     * own text straight into the `LI` beside it, which is round 25's third
     * tagging limit measured rather than assumed: the limit was recorded as
     * `Lbl` plus `LBody` and **there is no `LBody` in the browser's answer**.
     * Sharing the item's id could never express that, because one id cannot be
     * read in two places.
     *
     * The marker is drawn beside the box's lines rather than on them, so it
     * carries no ordinal and sorts ahead of the text the way every other piece
     * of placeless ink does.
     *
     * @param LineBox[] $lines
     */
    private static function paintLabel(Pdf $pdf, Node $n, float $x, float $y, array $lines): void
    {
        $first = $n->lineBoxes[0] ?? null;

        if (($n->marker === null && $n->markerImage === null) || $lines[0] !== $first) {
            return;
        }

        self::drawLabel($pdf, $n, $x, $y, $lines[0]);
    }

    /**
     * The same marker on a line the item hosts itself.
     *
     * An item whose only children are blocks that hold no line has no line box
     * anywhere under it, so there is no text box to carry the marker and
     * nothing painted at all before round 54. The item carries it instead and
     * the line is its own strut's, which is the line its `minHeight` floor
     * leaves room for. `ST-marker-rows.html` t5 and t6.
     */
    private static function paintHostedLabel(Pdf $pdf, Node $n, float $x, float $y): void
    {
        $seed = $n->strut ?? $n->marker;

        if (($n->marker === null && $n->markerImage === null) || $seed === null) {
            return;
        }

        $line = (new InlineFormatter())->emptyLine($seed);

        /*
         * A marker and the item's first line box share a baseline. Where the
         * content sits lower than this strut would, layout has already
         * declined to push it and recorded how far down the marker has to go
         * to meet it instead. Zero unless a nested list carries a bigger font
         * than the item around it.
         */
        $line->baseline += $n->markerBaselineShift;
        $line->height   += $n->markerBaselineShift;

        self::drawLabel($pdf, $n, $x, $y, $line);
    }

    /** The marker's ink, in a marked-content sequence of its own. */
    private static function drawLabel(Pdf $pdf, Node $n, float $x, float $y, LineBox $line): void
    {
        $pdf->openContent($n, null, 'Lbl');

        /*
         * Back to the item's own content edge, which is where a marker hangs.
         * This box is a descendant of the item wherever the item's content is a
         * block child, and the two are as far apart as that child's own margin
         * and padding put them: `SN-list-marker.html` m7 indents its child by
         * 40px and Chrome leaves the bullet where m1's is. Both x's come out of
         * the same layout pass, so their difference is the distance whatever
         * the page they are painted on. Defect FD.
         */
        $host  = $n->markerHost;
        $inset = $host === null ? 0.0 : $n->x - $host->x - $host->edge('left');

        try {
            if ($n->marker !== null && $n->marker->markerShape !== null) {
                self::paintMarkerShape($pdf, $n->marker, $x - $inset, $y, $line);
            } elseif ($n->marker !== null) {
                self::paintMarker($pdf, $n->marker, $x - $inset, $y, $line);
            }

            if ($n->markerImage !== null) {
                self::paintMarkerImage($pdf, $n, $x - $inset, $y, $line);
            }
        } finally {
            $pdf->closeContent();
        }
    }

    /** Draw a list marker just outside the left edge of its item's content. */
    private static function paintMarker(Pdf $pdf, InlineRun $marker, float $x, float $y, LineBox $first): void
    {
        $width = $marker->font()->stringWidth($marker->text, $marker->fontSize, $marker->fontFeatures);

        $line           = new LineBox();
        $line->baseline = $first->baseline;
        $line->height   = $first->height;
        $line->items    = [new InlineItem($marker, $marker->text, 0.0, $width)];

        $pdf->paintLines([$line], $x - $width, $y);
    }

    /**
     * One CSS pixel in points, which is the grid a marker's own box lands on.
     */
    private const float MARKER_PIXEL = 0.75;

    /**
     * What a browser leaves between a marker's box and the item's content
     * edge, in CSS pixels.
     *
     * {@see InlineRun::MARKER_GAP} is the one place it is written down, and
     * this file used to hold it twice: once here for a shape and once as
     * `MARKER_IMAGE_GAP` for a picture. Round 54 measured the same 7 a third
     * time, as an `inside` marker image's advance, which is layout rather than
     * ink and therefore cannot live in a painter at all.
     */
    private const float MARKER_SHAPE_GAP = InlineRun::MARKER_GAP;

    /**
     * Draw a `disc`, `circle` or `square` marker as the shape it is.
     *
     * **A browser draws these three rather than setting a character**, which
     * Chrome's own content stream is what says: a `disc` comes out as four
     * Bezier arcs inside the `/Lbl` sequence with no text operator anywhere
     * near it. Drawing the face's bullet glyph instead put this engine's
     * marker four raster columns right of Chrome's and two rows high, on every
     * list item in every document, because a glyph's size and side bearings
     * are the face's business and the shape's are the browser's.
     *
     * The numbers are {@see InlineRun::markerMetrics}, which the `inside`
     * spelling reads too. What this method adds is where an `outside` marker
     * goes: its box hangs a constant 7 CSS pixels left of the content edge and
     * the shape's centre sits half a box above the baseline, exact at all
     * nineteen sizes on `SQ-marker-metrics.html`.
     */
    private static function paintMarkerShape(Pdf $pdf, InlineRun $marker, float $x, float $y, LineBox $first): void
    {
        $metrics = $marker->markerMetrics();
        $size    = $metrics['shape'];

        if ($size <= 0.0) {
            return;
        }

        $left = $x - $metrics['box'] - self::MARKER_SHAPE_GAP * self::MARKER_PIXEL;

        self::drawMarkerShape($pdf, $marker, $left, $y + $first->baseline, $metrics);
    }

    /**
     * The shape itself, its top a whole number of CSS pixels above the
     * baseline and left-aligned in its own box, which is where Chrome draws it
     * in both spellings.
     *
     * @param array{box:float,shape:float,rise:float,advance:float} $metrics
     */
    private static function drawMarkerShape(Pdf $pdf, InlineRun $marker, float $left, float $baseline, array $metrics): void
    {
        $size = $metrics['shape'];

        // The shape's own rectangle is a whole number of CSS pixels in the
        // browser, where a baseline is not, so it lands on the pixel grid
        // rather than wherever half a box above the baseline falls. Without
        // this the bullet is antialiased across one row more than Chrome's at
        // five of the eight bands, which is a bullet a pixel taller than the
        // browser draws.
        //
        // **The baseline is snapped first and the rise is subtracted after**,
        // which is defect GF: Chrome's own first baseline is a whole number of
        // CSS pixels here and its bullet's top is a whole number of pixels
        // above it, so a rounding of the two together is a rounding of a
        // quantity Chrome never forms. {@see InlineRun::markerMetrics} is the
        // rise.
        $top = round($baseline / self::MARKER_PIXEL) * self::MARKER_PIXEL - $metrics['rise'];

        if ($marker->markerShape === 'square') {
            $pdf->fillRect($left, $top, $size, $size, $marker->color);

            return;
        }

        if ($marker->markerShape === 'circle') {
            // A stroke is centred on its path and `strokeRect` insets by half
            // its width, so the box handed over is the shape's own grown by a
            // whole line width to leave the path where the disc would be.
            $line = self::MARKER_PIXEL;

            $pdf->strokeRect(
                $left - $line / 2.0,
                $top - $line / 2.0,
                $size + $line,
                $size + $line,
                $marker->color,
                $line,
                ($size + $line) / 2.0,
            );

            return;
        }

        $pdf->fillRect($left, $top, $size, $size, $marker->color, $size / 2.0);
    }

    /**
     * Draw a `list-style-image` marker where the bullet would be.
     *
     * Its bottom edge sits on the first line's baseline, which is exact on all
     * fourteen font sizes probed, and its top is clamped to the top of that
     * line: a marker taller than the line hangs below it rather than above,
     * which is what Chrome does with a 60px picture on a 16px line.
     */
    private static function paintMarkerImage(Pdf $pdf, Node $n, float $x, float $y, LineBox $first): void
    {
        if ($n->markerImage === null) {
            return;
        }

        self::drawMarkerImage(
            $pdf,
            $n->markerImage,
            $n->markerImageWidth,
            $n->markerImageHeight,
            $x - $n->markerImageWidth - self::MARKER_SHAPE_GAP * self::MARKER_PIXEL,
            $y,
            $first,
        );
    }

    /**
     * The picture itself, with its bottom edge on the line's baseline.
     *
     * Both spellings draw the same picture the same way and differ only in
     * where its left edge is, exactly as the two shape marker paths do: an
     * `outside` one hangs a gap left of the content edge and an `inside` one
     * starts at the item's own left edge, where the line put it.
     *
     * @param array{image:?PdfImage,svg:?SvgDocument,gradient:?array} $layer
     */
    private static function drawMarkerImage(
        Pdf $pdf,
        array $layer,
        float $w,
        float $h,
        float $left,
        float $y,
        LineBox $first,
    ): void {
        if ($w <= 0.0 || $h <= 0.0) {
            return;
        }

        /*
         * `100% 100%` rather than `auto`, because the box is already the
         * answer: the builder has resolved the source's own size or the 0.45em
         * default, and `auto` would send an SVG carrying a `viewBox` and no
         * size back to its viewBox instead.
         */
        self::paintBackgroundImage(
            $pdf,
            new Node(['display' => 'rect']),
            [...$layer, 'size' => '100% 100%', 'position' => '0% 0%', 'repeat' => 'no-repeat'],
            $left,
            $y + max(0.0, $first->baseline - $h),
            $w,
            $h,
        );
    }

    /**
     * Paint the `box-shadow` layers of one polarity.
     *
     * An outer shadow's shape is the border box, an inner one's the padding
     * box, each moved by the shadow's offset and inflated by its spread. CSS
     * paints the first shadow of the list on top, so the list is walked
     * backwards.
     *
     * A corner that is square stays square however far the shadow spreads;
     * one that is round keeps a radius the spread grows with it, floored at
     * zero so a negative spread cannot invert it.
     */
    private static function paintShadows(
        Pdf $pdf,
        Node $n,
        float $x,
        float $y,
        float $w,
        float $h,
        bool $inset,
    ): void {
        if ($n->boxShadow === []) {
            return;
        }

        $reference = $inset
            ? [
                $x + $n->borderWidth('left'),
                $y + $n->borderWidth('top'),
                max(0.0, $w - $n->borderWidth('left') - $n->borderWidth('right')),
                max(0.0, $h - $n->borderWidth('top') - $n->borderWidth('bottom')),
            ]
            : [$x, $y, $w, $h];

        $shrink = max($n->borderWidth('left'), $n->borderWidth('top'));

        $radii = $inset
            ? array_map(
                static fn(array $corner): array => [
                    max(0.0, $corner[0] - $shrink),
                    max(0.0, $corner[1] - $shrink),
                ],
                $n->borderRadius,
            )
            : $n->borderRadius;

        $hole = [...$reference, ...$radii];

        foreach (array_reverse($n->boxShadow) as $shadow) {
            if ($shadow['inset'] !== $inset) {
                continue;
            }

            $grow = $inset ? -$shadow['spread'] : $shadow['spread'];

            $pdf->fillShadow(
                $reference[0] + $shadow['x'] - $grow,
                $reference[1] + $shadow['y'] - $grow,
                $reference[2] + 2.0 * $grow,
                $reference[3] + 2.0 * $grow,
                $shadow['blur'],
                $shadow['color'],
                array_map(
                    static fn(array $corner): array => [
                        $corner[0] > 0.0 ? max(0.0, $corner[0] + $grow) : 0.0,
                        $corner[1] > 0.0 ? max(0.0, $corner[1] + $grow) : 0.0,
                    ],
                    $radii,
                ),
                $hole,
                $inset,
            );
        }
    }

    /**
     * Stroke the border edge by edge, since each side carries its own width
     * and color. Edges that share both still go out as one rectangle: that
     * is one path instead of four, and it is what a rounded corner needs.
     *
     * `borderEdges` masks the result for `border-collapse: collapse`, where a
     * shared grid line must be drawn by one cell only, and `$owned` masks it
     * again for one page's slice of a box that spans a break.
     *
     * @param string[]|null $owned
     */
    private static function strokeBorder(
        Pdf $pdf,
        Node $n,
        float $x,
        float $y,
        float $w,
        float $h,
        ?array $owned = null,
    ): void {
        $edges = [];

        foreach ($n->borderEdges as $edge) {
            if ($owned !== null && !in_array($edge, $owned, true)) {
                continue;
            }

            // A fully transparent edge is kept by the builder because it takes
            // its width in the box model, and there is nothing to draw for it.
            if (isset($n->border[$edge]) && ($n->border[$edge]['color'][3] ?? 1.0) > 0.0) {
                $edges[$edge] = $n->border[$edge];
            }
        }

        if ($edges === []) {
            return;
        }

        $first   = reset($edges);
        $uniform = array_filter($edges, fn(array $side): bool => $side !== $first) === [];

        // A collapsed cell reserves half a grid line on each side, so the line
        // belongs on the border box edge rather than inside it. That also rules
        // out the one-rectangle path, which can only inset.
        $inset = !$n->collapsedBorder;

        // One path per box is cheaper and it is what a rounded corner needs,
        // but a dash pattern has to restart at every corner to land where
        // Chrome puts it, so a patterned border goes out edge by edge unless
        // the corners are round.
        $rounded = $n->hasBorderRadius();

        // One rounded path is the whole box's, so a fragment that does not own
        // a horizontal edge cannot use it: under `box-decoration-break: slice`
        // the fold is not an edge and the border does not close there. A piece
        // that owns all four takes the same route it always did, which is
        // every unfragmented box and every fragment under `clone`.
        $cut = $owned !== null
            && (!in_array('top', $owned, true) || !in_array('bottom', $owned, true));

        if ($inset && $uniform && !$cut && ($rounded || (count($edges) === 4 && $first['style'] === 'solid'))) {
            $pdf->strokeRect($x, $y, $w, $h, $first['color'], $first['width'], $n->borderRadius, $first['style']);

            return;
        }

        if ($uniform) {
            $pdf->strokeEdges(
                $x, $y, $w, $h,
                $first['color'], $first['width'], array_keys($edges), $first['style'], $inset,
            );

            return;
        }

        // A rounded box whose sides differ cannot be one stroked path, and the
        // edge-by-edge route below draws four straight rectangles: the radius
        // was dropped whole, so a card with a heavier bottom rule lost every
        // corner it declared. `TJ-corner-sides.html` j9 is 29 by 29 pixels of
        // corner in Chrome and 0 by 0 here. The ring is filled per side
        // instead, which carries any width, any colour and the join between
        // two of them.
        //
        // A side whose style is not solid takes the same wedge with a stroke
        // inside it, which is defect GV: j14 is a double border on the same
        // shape and lost all four corners the same way. What it does NOT fix is
        // where a dash falls along the side, because that is the phase the
        // uniform spelling already gets wrong on j13.
        $solid = array_filter($edges, static fn(array $side): bool => $side['style'] !== 'solid') === [];

        if ($inset && !$cut && $rounded) {
            $box    = self::boxArea($n, 'padding-box', $x, $y, $w, $h);
            $oneHue = array_filter($edges, static fn(array $side): bool => $side['color'] !== $first['color']) === [];
            $whole  = $oneHue && $solid && count($edges) === 4;

            if ($whole) {
                $pdf->fillBorderSide(null, $x, $y, $w, $h, $box, $n->borderRadius, $first['color']);

                return;
            }

            foreach ($edges as $edge => $side) {
                $pdf->fillBorderSide(
                    $edge, $x, $y, $w, $h, $box, $n->borderRadius,
                    $side['color'], $side['style'], $side['width'],
                );
            }

            return;
        }

        foreach ($edges as $edge => $side) {
            [$ex, $ey, $ew, $eh] = self::junctionRect($n, $edges, $edge, $side['width'], $x, $y, $w, $h);

            $pdf->strokeEdges($ex, $ey, $ew, $eh, $side['color'], $side['width'], [$edge], $side['style'], $inset);
        }
    }

    /**
     * One collapsed edge's rect, shortened where a wider line crosses its end.
     *
     * CSS 2.1 §17.6.2.1: where two collapsed borders meet, the wider one has
     * the higher priority and is the one that shows. A cell on the table's rim
     * carries the rim line on one edge and its own narrower internal line on
     * the perpendicular one, and both straddle the border box edge, so the
     * internal line ran half the rim's width into it and painted over it. It
     * starts at the rim's inner edge instead, which is where Chrome starts it.
     *
     * The line itself does not move: only the end it reaches to does, so a grid
     * whose lines are all one width is untouched.
     *
     * @param  array<string,array{color:array<int,float>,width:float,style:string}> $edges
     * @return array{0:float,1:float,2:float,3:float}
     */
    private static function junctionRect(
        Node $n,
        array $edges,
        string $edge,
        float $width,
        float $x,
        float $y,
        float $w,
        float $h,
    ): array {
        if (!$n->collapsedBorder) {
            return [$x, $y, $w, $h];
        }

        $vertical = $edge === 'left' || $edge === 'right';
        [$a, $b]  = $vertical ? ['top', 'bottom'] : ['left', 'right'];

        $head = ($edges[$a]['width'] ?? 0.0) > $width ? $edges[$a]['width'] / 2.0 : 0.0;
        $tail = ($edges[$b]['width'] ?? 0.0) > $width ? $edges[$b]['width'] / 2.0 : 0.0;

        return $vertical
            ? [$x, $y + $head, $w, max(0.0, $h - $head - $tail)]
            : [$x + $head, $y, max(0.0, $w - $head - $tail), $h];
    }

    /**
     * Stroke the outline, which sits wholly *outside* the border box: its
     * inner edge is `outline-offset` out from the box and it grows outward
     * from there. Layout never sees it, so this is the only place it exists.
     */
    private static function strokeOutline(
        Pdf $pdf,
        Node $n,
        float $x,
        float $y,
        float $w,
        float $h,
        ?array $owned = null,
    ): void {
        $out    = $n->outline['width'] + $n->outlineOffset;
        $radius = array_map(
            static fn(array $corner): array => [
                $corner[0] > 0.0 ? $corner[0] + $out : 0.0,
                $corner[1] > 0.0 ? $corner[1] + $out : 0.0,
            ],
            $n->borderRadius,
        );

        if ($owned !== null && count($owned) < 4) {
            // One page's slice of a split box: the edges the fold cut are not
            // edges of the box, so they are not drawn. A corner radius needs
            // the single-path form, and a slice has no corners to round.
            $pdf->strokeEdges(
                $x - $out,
                $y - $out,
                $w + 2.0 * $out,
                $h + 2.0 * $out,
                $n->outline['color'],
                $n->outline['width'],
                $owned,
                $n->outline['style'],
            );

            self::strokeFoldBands($pdf, $n, $x, $y, $w, $h, $out, $owned);

            return;
        }

        $pdf->strokeRect(
            $x - $out,
            $y - $out,
            $w + 2.0 * $out,
            $h + 2.0 * $out,
            $n->outline['color'],
            $n->outline['width'],
            $radius,
            $n->outline['style'],
        );
    }

    /**
     * The outline edge that belongs to the fragment on the other side of a
     * fold, which Chrome paints on this page and a sliced outline does not.
     *
     * **Chrome does not slice an outline.** It draws a closed one around each
     * fragment in the continuous flow and then cuts that flow into pages, so
     * the edge belonging to the fragment on the next page lands as a band near
     * the bottom of this one, and the edge belonging to the fragment on the
     * previous page lands near the top of it. Each sits one `outline-offset`
     * plus half an outline width in from the fold, which is where a closed
     * outline's own top and bottom sit.
     *
     * Measured on `RN-fold-outline.html`, a 4px outline at a 16px offset over
     * a fold at 300pt: Chrome paints a band at y 286.500 on the page above the
     * fold and at 13.500 on the page below it, both spanning the full outline
     * width, and this engine painted neither. The verticals of the neighbour's
     * outline land on this page too and are not drawn here, because they sit
     * on top of the verticals this slice already draws.
     *
     * It is a quirk rather than a rule: CSS says nothing about it and it is
     * about 4 percent of the pixels of an interior page.
     *
     * @param string[] $owned the edges this slice draws for itself
     */
    private static function strokeFoldBands(
        Pdf $pdf,
        Node $n,
        float $x,
        float $y,
        float $w,
        float $h,
        float $out,
        array $owned,
    ): void {
        $lead = $n->outline['width'] / 2.0;
        $band = $n->outlineOffset + $lead;

        // `strokeEdges` puts a `top` line half a width down from the rect it is
        // given, so a zero-height rect is the one-line spelling of a band.
        $bands = ['top' => $y + $band - $lead, 'bottom' => $y + $h - $band - $lead];

        foreach ($bands as $edge => $top) {
            if (in_array($edge, $owned, true)) {
                continue;
            }

            $pdf->strokeEdges(
                $x - $out,
                $top,
                $w + 2.0 * $out,
                0.0,
                $n->outline['color'],
                $n->outline['width'],
                ['top'],
                $n->outline['style'],
            );
        }
    }

    /**
     * `column-rule`, one stroke down the middle of every gutter.
     *
     * It takes no space, exactly like an outline: the gutter is already
     * `column-gap` wide and the rule is centred in it whatever its own width,
     * so a rule wider than the gap overlaps the columns either side rather
     * than pushing them apart. CSS paints it above the box's background and
     * below its border, which is where this call sits.
     *
     * The count comes from what the content filled rather than from
     * `column-count`, because a rule goes between two columns that both hold
     * something. A `column-span: all` child cuts the flow into more than one
     * set of columns, and each set gets its own strokes: a rule that ran the
     * whole box height would cross the spanner. {@see Node::$columnBoxes}.
     */
    private static function strokeColumnRules(Pdf $pdf, Node $n, float $x, float $y): void
    {
        $rule  = $n->columnRule;
        $boxes = $n->columnBoxes;

        if ($rule === null || $boxes === null || $boxes['gap'] <= 0.0) {
            return;
        }

        $left = $x + $n->edge('left');
        $top  = $y + $n->edge('top');

        foreach ($boxes['rows'] as $row) {
            if ($row['count'] < 2 || $row['height'] <= 0.0) {
                continue;
            }

            for ($gutter = 1; $gutter < $row['count']; $gutter++) {
                // The gutter's own centre: the far edge of the column before
                // it, plus half the gap. `strokeEdges` insets a line by half
                // its own width, so the box it is given starts half a width
                // to the left.
                $centre = $left + $gutter * ($boxes['width'] + $boxes['gap']) - $boxes['gap'] / 2.0;

                $pdf->strokeEdges(
                    $centre - $rule['width'] / 2.0,
                    $top + $row['top'],
                    0.0,
                    $row['height'],
                    $rule['color'],
                    $rule['width'],
                    ['left'],
                    $rule['style'],
                );
            }
        }
    }

    /**
     * Paint `background-image`, tiled and placed the way the three properties
     * beside it ask.
     *
     * CSS puts the positioning area at the padding box and the paint area at
     * the border box, so a tile is measured from inside the border and then
     * drawn under it. Every tile is clipped to the border box, which is what
     * lets `no-repeat` on a small box show a slice of a large image.
     */
    private static function paintBackgroundImage(
        Pdf $pdf,
        Node $n,
        array $layer,
        float $x,
        float $y,
        float $w,
        float $h,
    ): void {
        if ($w <= 0.0 || $h <= 0.0) {
            return;
        }

        // `background-origin` says which box a tile is measured from and
        // defaults to the padding box, which is the border box less the
        // border: padding is inside the positioning area, so a tile starts
        // under the padding rather than after it.
        [$areaX, $areaY, $areaW, $areaH] =
            self::boxArea($n, $layer['origin'] ?? 'padding-box', $x, $y, $w, $h);

        // A gradient has no size of its own, so `auto` is the positioning
        // area and the gradient is drawn to whatever box comes out of the
        // placement, which is the box a tile of an image would land in.
        $intrinsic = match (true) {
            $layer['image'] !== null => [$layer['image']->width * 0.75, $layer['image']->height * 0.75],
            $layer['svg'] !== null   => [$layer['svg']->width * 0.75, $layer['svg']->height * 0.75],
            default                  => [$areaW, $areaH],
        };

        [$tileW, $tileH] = self::backgroundTile($layer['size'], $intrinsic, $areaW, $areaH);

        [$alongX, $alongY] = self::repeatAxes($layer['repeat']);

        // `round` decides the tile's own size, so it happens before the
        // position is resolved against it.
        $tileW = self::roundedTile($alongX, $tileW, $areaW);
        $tileH = self::roundedTile($alongY, $tileH, $areaH);

        if ($tileW <= 0.01 || $tileH <= 0.01) {
            return;
        }

        [$offsetX, $offsetY] = self::backgroundOrigin(
            $layer['position'],
            $areaW - $tileW,
            $areaH - $tileH,
            $tileW,
            $tileH,
        );

        [$startX, $columns, $pitchX] = self::tileRun($alongX, $x, $w, $areaX, $areaW, $offsetX, $tileW);
        [$startY, $rows, $pitchY]    = self::tileRun($alongY, $y, $h, $areaY, $areaH, $offsetY, $tileH);

        // A degenerate tile against a large box is a way to spend the whole
        // render on one decoration, so the count is bounded the way every
        // other loop over author-controlled input is.
        if ($columns * $rows > self::MAX_TILES) {
            $columns = min($columns, self::MAX_TILES);
            $rows    = min($rows, (int) max(1, self::MAX_TILES / $columns));
        }

        // `background-clip` says which box the ink is cut to and defaults to
        // the border box, so a tile is measured from inside the border and
        // then drawn under it.
        [$clipX, $clipY, $clipW, $clipH, $clipRadius] =
            self::boxArea($n, $layer['clip'] ?? 'border-box', $x, $y, $w, $h);

        $pdf->pushClip($clipX, $clipY, $clipW, $clipH, $clipRadius);

        for ($row = 0; $row < $rows; $row++) {
            for ($col = 0; $col < $columns; $col++) {
                $tx = $startX + $col * $pitchX;
                $ty = $startY + $row * $pitchY;

                if ($layer['gradient'] !== null) {
                    $pdf->fillGradient($layer['gradient'], $tx, $ty, $tileW, $tileH);

                    continue;
                }

                if ($layer['svg'] !== null) {
                    $layer['svg']->render($pdf, $tx, $ty, $tileW, $tileH);

                    continue;
                }

                $pdf->drawImage($layer['image'], $tx, $ty, $tileW, $tileH);
            }
        }

        $pdf->pop();
    }

    /**
     * The x and y keywords of a `background-repeat` or `mask-repeat` value.
     *
     * CSS gives the property one keyword per axis and two shorthands for the
     * pairs, so everything downstream can ask about one axis at a time. A
     * keyword that is none of the five behaves as `no-repeat`, which is what
     * the painter did with every value it did not know.
     *
     * @return array{0:string,1:string}
     */
    private static function repeatAxes(string $value): array
    {
        $parts = preg_split('/\s+/', strtolower(trim($value))) ?: [];
        $first = $parts[0] ?? 'repeat';

        return match ($first) {
            'repeat-x' => ['repeat', 'no-repeat'],
            'repeat-y' => ['no-repeat', 'repeat'],
            default    => [$first, $parts[1] ?? $first],
        };
    }

    /**
     * `round` rescales a tile so that a whole number of them fills the area.
     *
     * Chrome's, read off `SN-repeat-space-round.html`: r2 is a 24px tile in a
     * 160px box, which fits 6.67 times, and the browser paints **seven** tiles
     * of 160/7 rather than six and a stub. r5 is the same rule with a tile
     * wider than the box, 200px in 160, which rounds to one and is rescaled
     * down to the box.
     */
    private static function roundedTile(string $repeat, float $tile, float $area): float
    {
        if ($repeat !== 'round' || $tile <= 0.01 || $area <= 0.0) {
            return $tile;
        }

        return $area / max(1.0, round($area / $tile));
    }

    /**
     * Where the first tile on one axis goes, how many there are and how far
     * apart, which is the whole of what the five repeat keywords decide.
     *
     * `repeat` and `round` tile at the tile's own length from wherever the
     * position put the first one, back-filled so a positioned tile repeats
     * *through* the origin rather than only after it.
     *
     * **`space` keeps the tile and spreads what is left over between whole
     * copies**, first and last touching the edges of the positioning area, so
     * the position is not read on that axis at all. With room for fewer than
     * two it is one tile placed by the position, which is `SN-repeat-space-round.html`
     * r4 and is why that slot agreed before this rule existed.
     *
     * @param  float $paintStart where the ink may go, which is the border box
     * @param  float $areaStart  where a tile is measured from
     * @return array{0:float,1:int,2:float} start, count, pitch
     */
    private static function tileRun(
        string $repeat,
        float $paintStart,
        float $paintLength,
        float $areaStart,
        float $areaLength,
        float $offset,
        float $tile,
    ): array {
        if ($repeat === 'space') {
            $fits = (int) floor(($areaLength + 0.01) / $tile);

            if ($fits < 2) {
                return [$areaStart + $offset, 1, $tile];
            }

            return [$areaStart, $fits, ($areaLength - $fits * $tile) / ($fits - 1) + $tile];
        }

        if ($repeat !== 'repeat' && $repeat !== 'round') {
            return [$areaStart + $offset, 1, $tile];
        }

        $start = $areaStart + $offset;
        $start -= ceil(max(0.0, $start - $paintStart) / $tile) * $tile;

        return [$start, (int) ceil(($paintStart + $paintLength - $start) / $tile), $tile];
    }

    /**
     * `border-image`: the source sliced into nine regions and drawn over the
     * border box. Returns false when nothing was painted, which is what puts
     * the ordinary border back.
     *
     * The mapping is one rule applied nine times. Each region has a rectangle
     * in the source and a rectangle on the page, and drawing it is drawing the
     * **whole** source scaled so that its own region lands on the destination,
     * clipped to that destination. That works for a picture, an SVG and a
     * gradient without any of them needing to know they are being sliced.
     *
     * `stretch` is what this paints, and the other three repeat modes take it
     * too. `RM-border-image.html` `s8` and `s9` are `repeat` and `round` and
     * Chrome renders both **identically to `stretch`** on that page, because
     * each region of the source is one flat color there and a stretched flat
     * color and a tiled one are the same pixels. So the probe says the three
     * agree on it and says nothing about a source they would not agree on.
     */
    private static function paintBorderImage(Pdf $pdf, Node $n, float $x, float $y, float $w, float $h): bool
    {
        $spec = $n->borderImage;

        if ($spec === null || $w <= 0.0 || $h <= 0.0) {
            return false;
        }

        $borders = [
            $n->borderWidth('top'), $n->borderWidth('right'),
            $n->borderWidth('bottom'), $n->borderWidth('left'),
        ];

        $outset = self::borderImageEdges($spec['outset'], $borders, [$h, $w, $h, $w], $borders);

        // The border image area is the border box grown by the outset.
        $areaX = $x - $outset[3];
        $areaY = $y - $outset[0];
        $areaW = $w + $outset[1] + $outset[3];
        $areaH = $h + $outset[0] + $outset[2];

        if ($areaW <= 0.0 || $areaH <= 0.0) {
            return false;
        }

        // A gradient has no size of its own, so the source is the area, which
        // is what makes a `30` slice on a 60pt-tall box meet in the middle.
        [$srcW, $srcH] = match (true) {
            $spec['layer']['image'] !== null => [
                $spec['layer']['image']->width * 0.75,
                $spec['layer']['image']->height * 0.75,
            ],
            $spec['layer']['svg'] !== null && $spec['layer']['svg']->hasIntrinsicSize => [
                $spec['layer']['svg']->width * 0.75,
                $spec['layer']['svg']->height * 0.75,
            ],
            default => [$areaW, $areaH],
        };

        if ($srcW <= 0.0 || $srcH <= 0.0) {
            return false;
        }

        // A slice number is source pixels, which are CSS pixels, and a
        // percentage is of the source. Both clamp to the source.
        $slice = [];

        foreach ([$srcH, $srcW, $srcH, $srcW] as $side => $extent) {
            $edge     = $spec['slice'][$side];
            $slice[]  = min($extent, max(0.0, $edge['unit'] === 'pct'
                ? $edge['v'] * $extent
                : $edge['v'] * 0.75));
        }

        $width = self::borderImageEdges($spec['width'], $borders, [$areaH, $areaW, $areaH, $areaW], $slice);

        // Two facing widths that together overrun the area are scaled down
        // together, CSS Backgrounds §6.5, so a corner can never fold over.
        foreach ([[0, 2, $areaH], [3, 1, $areaW]] as [$a, $b, $extent]) {
            $sum = $width[$a] + $width[$b];

            if ($sum > $extent && $sum > 0.0) {
                $factor     = $extent / $sum;
                $width[$a] *= $factor;
                $width[$b] *= $factor;
            }
        }

        $midW = max(0.0, $areaW - $width[1] - $width[3]);
        $midH = max(0.0, $areaH - $width[0] - $width[2]);
        $srcMidW = max(0.0, $srcW - $slice[1] - $slice[3]);
        $srcMidH = max(0.0, $srcH - $slice[0] - $slice[2]);

        // column and row edges, on the page and in the source
        $cols = [[$areaX, $width[3]], [$areaX + $width[3], $midW], [$areaX + $areaW - $width[1], $width[1]]];
        $rows = [[$areaY, $width[0]], [$areaY + $width[0], $midH], [$areaY + $areaH - $width[2], $width[2]]];
        $srcCols = [[0.0, $slice[3]], [$slice[3], $srcMidW], [$srcW - $slice[1], $slice[1]]];
        $srcRows = [[0.0, $slice[0]], [$slice[0], $srcMidH], [$srcH - $slice[2], $slice[2]]];

        $painted = false;

        foreach ($rows as $row => [$destY, $destH]) {
            foreach ($cols as $col => [$destX, $destW]) {
                if ($row === 1 && $col === 1 && !$spec['fill']) {
                    continue;
                }

                [$sx, $sw] = $srcCols[$col];
                [$sy, $sh] = $srcRows[$row];

                if ($destW <= 0.01 || $destH <= 0.01 || $sw <= 0.01 || $sh <= 0.01) {
                    continue;
                }

                $scaleX = $destW / $sw;
                $scaleY = $destH / $sh;

                $pdf->pushClip($destX, $destY, $destW, $destH);
                self::drawWholeSource(
                    $pdf,
                    $spec['layer'],
                    $destX - $sx * $scaleX,
                    $destY - $sy * $scaleY,
                    $srcW * $scaleX,
                    $srcH * $scaleY,
                );
                $pdf->pop();

                $painted = true;
            }
        }

        return $painted;
    }

    /**
     * One `border-image-width` or `-outset` edge set, resolved against the
     * things only the painter knows.
     *
     * A number is a multiple of that side's border width, a percentage is of
     * the area in that axis, `auto` is the slice, and a length is itself.
     *
     * @param  array<int, array{v:float,unit:string}> $edges
     * @param  array<int, float>                      $borders
     * @param  array<int, float>                      $extents
     * @param  array<int, float>                      $autos
     * @return array<int, float>
     */
    private static function borderImageEdges(array $edges, array $borders, array $extents, array $autos): array
    {
        $out = [];

        foreach ([0, 1, 2, 3] as $side) {
            $edge  = $edges[$side] ?? ['v' => 0.0, 'unit' => 'pt'];
            $out[] = max(0.0, match ($edge['unit']) {
                'num'   => $edge['v'] * ($borders[$side] ?? 0.0),
                'pct'   => $edge['v'] * ($extents[$side] ?? 0.0),
                'auto'  => $autos[$side] ?? 0.0,
                default => $edge['v'],
            });
        }

        return $out;
    }

    /**
     * Draw a layer's whole source into one rectangle, whatever it is made of.
     *
     * @param array{image:?PdfImage,svg:?SvgDocument,gradient:?array} $layer
     */
    private static function drawWholeSource(Pdf $pdf, array $layer, float $x, float $y, float $w, float $h): void
    {
        if ($layer['gradient'] !== null) {
            $pdf->fillGradient($layer['gradient'], $x, $y, $w, $h);

            return;
        }

        if ($layer['svg'] !== null) {
            $layer['svg']->render($pdf, $x, $y, $w, $h);

            return;
        }

        if ($layer['image'] !== null) {
            $pdf->drawImage($layer['image'], $x, $y, $w, $h);
        }
    }

    /**
     * The used size of one tile.
     *
     * `auto` is the image's own size, `cover` and `contain` scale it to the
     * positioning area, and a length pair sizes it directly, with `auto` on
     * one axis keeping the intrinsic ratio.
     *
     * @param  array{0:float,1:float} $intrinsic
     * @return array{0:float,1:float}
     */
    private static function backgroundTile(string $size, array $intrinsic, float $areaW, float $areaH): array
    {
        [$iw, $ih] = $intrinsic;

        if ($iw <= 0.0 || $ih <= 0.0) {
            return [$areaW, $areaH];
        }

        $ratio = $iw / $ih;

        if ($size === 'cover' || $size === 'contain') {
            $wider = $areaH > 0.0 && $ratio > $areaW / $areaH;
            $fillHeight = $size === 'cover' ? $wider : !$wider;

            return $fillHeight
                ? [$areaH * $ratio, $areaH]
                : [$areaW, $areaW / $ratio];
        }

        $parts = preg_split('/\s+/', trim($size)) ?: ['auto'];
        $w     = self::backgroundLength($parts[0] ?? 'auto', $areaW);
        $h     = self::backgroundLength($parts[1] ?? 'auto', $areaH);

        return match (true) {
            $w === null && $h === null => [$iw, $ih],
            $w === null                => [$h * $ratio, $h],
            $h === null                => [$w, $w / $ratio],
            default                    => [$w, $h],
        };
    }

    /**
     * Where the first tile sits inside the positioning area.
     *
     * A percentage aligns the same fraction of the image with that fraction of
     * the area, which is why it resolves against the *leftover* space rather
     * than against the area itself, and it is what makes `50%` center.
     *
     * @return array{0:float,1:float}
     */
    private static function backgroundOrigin(
        string $position,
        float $freeX,
        float $freeY,
        float $tileW,
        float $tileH,
    ): array {
        $parts = preg_split('/\s+/', trim($position)) ?: [];
        $x     = $parts[0] ?? '0%';
        $y     = $parts[1] ?? '50%';

        // A single vertical keyword means the horizontal half is `center`.
        if (count($parts) === 1 && ($x === 'top' || $x === 'bottom')) {
            [$x, $y] = ['center', $x];
        }

        if ($x === 'top' || $x === 'bottom' || $y === 'left' || $y === 'right') {
            [$x, $y] = [$y, $x];
        }

        return [
            self::backgroundEdge($x, $freeX, ['left' => '0%', 'right' => '100%']),
            self::backgroundEdge($y, $freeY, ['top' => '0%', 'bottom' => '100%']),
        ];
    }

    /**
     * The rectangle a `background-clip` or `background-origin` keyword names,
     * with the corner radii that belong to it.
     *
     * `border-box` is the box exactly as it is painted, which is both
     * properties' behaviour before either was read: `background-clip` starts
     * there and `background-origin` is one edge in, at the padding box.
     * `content-box` is one edge further, inside the padding. A corner shrinks
     * with the edge that insets it, which is what `paintShadows()` already
     * does for the inset half of a `box-shadow`.
     *
     * Anything else, `text` included, is treated as the border box: it is the
     * value that leaves the box as it was rather than a guess at what the
     * keyword wanted.
     *
     * @return array{0:float,1:float,2:float,3:float,4:array<int,float>}
     */
    /**
     * The corners a fragment of a rounded box actually owns.
     *
     * A fold is not an edge of the box under `box-decoration-break: slice`, so
     * the two corners on a cut edge are square there and round under `clone`.
     * The engine drew all four on every fragment, which is `clone` by
     * accident: on `RS-fold-decoration-slice.html` it is **172 pixels of
     * 36,000 from Chrome on the first page and 130 on the second**, and on the
     * `-clone` twin the same renders are 14 and 12. Reading the edge mask here
     * makes the two spellings differ by exactly what Chrome's differ by.
     *
     * @param list<array{0:float,1:float}>|float $radius top-left, top-right,
     *        bottom-right, bottom-left, each a horizontal and a vertical half
     * @param string[]|null $edges
     * @return list<array{0:float,1:float}>|float
     */
    private static function radiusFor(array|float $radius, ?array $edges): array|float
    {
        if ($edges === null || ! is_array($radius)) {
            return $radius;
        }

        $top    = in_array('top', $edges, true);
        $bottom = in_array('bottom', $edges, true);

        if ($top && $bottom) {
            return $radius;
        }

        $square = [0.0, 0.0];

        return [
            $top ? $radius[0] : $square,
            $top ? $radius[1] : $square,
            $bottom ? $radius[2] : $square,
            $bottom ? $radius[3] : $square,
        ];
    }

    private static function boxArea(
        Node $n,
        string $which,
        float $x,
        float $y,
        float $w,
        float $h,
    ): array {
        if ($which !== 'padding-box' && $which !== 'content-box') {
            return [$x, $y, $w, $h, $n->borderRadius];
        }

        $left   = $n->borderWidth('left');
        $top    = $n->borderWidth('top');
        $right  = $n->borderWidth('right');
        $bottom = $n->borderWidth('bottom');

        if ($which === 'content-box') {
            $left   += $n->padding['left'];
            $top    += $n->padding['top'];
            $right  += $n->padding['right'];
            $bottom += $n->padding['bottom'];
        }

        return [
            $x + $left,
            $y + $top,
            max(0.0, $w - $left - $right),
            max(0.0, $h - $top - $bottom),
            $n->shrunkRadii($left, $top, $right, $bottom, $w, $h),
        ];
    }

    /** @param array<string,string> $keywords */
    private static function backgroundEdge(string $value, float $free, array $keywords): float
    {
        $value = $keywords[$value] ?? ($value === 'center' ? '50%' : $value);

        if (str_ends_with($value, '%')) {
            return $free * (float) rtrim($value, '%') / 100.0;
        }

        return self::backgroundLength($value, $free) ?? 0.0;
    }

    /** A `background-size` or `-position` component in points, or null for `auto`. */
    private static function backgroundLength(string $value, float $basis): ?float
    {
        $value = trim($value);

        if ($value === '' || $value === 'auto') {
            return null;
        }

        if (str_ends_with($value, '%')) {
            return $basis * (float) rtrim($value, '%') / 100.0;
        }

        return match (true) {
            str_ends_with($value, 'pt') => (float) $value,
            str_ends_with($value, 'px') => (float) $value * 0.75,
            str_ends_with($value, 'in') => (float) $value * 72.0,
            str_ends_with($value, 'cm') => (float) $value * 28.3465,
            str_ends_with($value, 'mm') => (float) $value * 2.83465,
            is_numeric($value)          => (float) $value,
            default                     => null,
        };
    }

    /**
     * The placeholder for an `<img>` whose file will not load.
     *
     * Chrome draws a 16x16 pixel mark **at its own size in the corner**, never
     * stretched: the same square sits in a 200pt block as in a 16px inline box,
     * measured on a 160x120 one where the ink is a rule round the whole border
     * box and a 12.000pt figure at its origin. `framed` is that rule, and it
     * appears only where the box declared both of its axes, which is the only
     * shape a broken image has a size of its own to frame.
     *
     * The figure itself is a picture frame with a hill and a sun in it, drawn
     * from rectangles because it has to survive a page with no font and no
     * raster in it. It is a UA decoration rather than a layout rule, so what is
     * measurable about it is where it sits and how big it is, not its artwork.
     */
    private static function paintBrokenImage(Pdf $pdf, Node $n, float $x, float $y, float $w, float $h): void
    {
        $ink = [0.55, 0.55, 0.55];

        if ($n->brokenImage === 'framed') {
            $pdf->strokeRect($x, $y, $w, $h, $ink, 0.75);
        }

        $side = min(self::BROKEN_ICON, $w, $h);

        if ($side <= 0.0) {
            return;
        }

        $unit = $side / 16.0;
        $clip = $side < self::BROKEN_ICON - 0.001;

        if ($clip) {
            $pdf->pushClip($x, $y, $w, $h);
        }

        $pdf->strokeRect($x, $y, $side, $side, $ink, max(0.4, $unit));
        $pdf->fillRect($x + 4.0 * $unit, $y + 3.5 * $unit, 2.5 * $unit, 2.5 * $unit, $ink);

        // The hill, as a staircase: four steps up and four down, which reads as
        // a picture at 12pt and needs no path operator to draw.
        for ($step = 0; $step < 4; $step++) {
            $rise = (2.0 + 2.0 * $step) * $unit;
            $pdf->fillRect($x + (3.0 + $step) * $unit, $y + $side - 2.0 * $unit - $rise, $unit, $rise, $ink);
            $pdf->fillRect($x + (10.0 - $step) * $unit, $y + $side - 2.0 * $unit - $rise, $unit, $rise, $ink);
        }

        if ($clip) {
            $pdf->pop();
        }
    }

    /**
     * Place an image inside its box according to `object-fit`, at the point
     * `object-position` asks for.
     */
    private static function drawFitted(Pdf $pdf, Node $n, float $x, float $y, float $w, float $h): void
    {
        $image = $n->image;
        if ($image === null) {
            return;
        }

        if ($n->objectFit === 'fill' || $image->width <= 0 || $image->height <= 0) {
            $pdf->drawImage($image, $x, $y, $w, $h);

            return;
        }

        [$dx, $dy, $dw, $dh] = self::fittedRect(
            $n,
            $x,
            $y,
            $w,
            $h,
            $image->width * 0.75,
            $image->height * 0.75,
        );

        self::clipped($pdf, $n, $x, $y, $w, $h, $dw, $dh, static function () use ($pdf, $image, $dx, $dy, $dw, $dh): void {
            $pdf->drawImage($image, $dx, $dy, $dw, $dh);
        });
    }

    /**
     * Draw the box's own SVG, which `object-fit` places exactly as it places a
     * picture when the SVG is an `<img>`'s source.
     *
     * An inline `<svg>` is left alone: it is a viewport rather than a picture
     * and Chrome reads no `object-fit` on one at all, so `SP-svg-objectfit.html`
     * p8 and p9 draw the same letterboxed picture under `cover` and `none` as
     * under `contain`. The file keeps letterboxing itself inside whatever
     * region it is given either way, which is `preserveAspectRatio`'s own
     * default and why `fill` and `contain` are one picture for an SVG source:
     * both hand it a region it fits by its own ratio.
     */
    private static function drawSvg(Pdf $pdf, Node $n, float $x, float $y, float $w, float $h): void
    {
        $svg = $n->svg;
        if ($svg === null) {
            return;
        }

        if (!$n->svgAsImage || $n->objectFit === 'fill' || $svg->width <= 0 || $svg->height <= 0) {
            $svg->render($pdf, $x, $y, $w, $h);

            return;
        }

        [$dx, $dy, $dw, $dh] = self::fittedRect(
            $n,
            $x,
            $y,
            $w,
            $h,
            $svg->width * 0.75,
            $svg->height * 0.75,
            $svg->hasIntrinsicSize,
        );

        self::clipped($pdf, $n, $x, $y, $w, $h, $dw, $dh, static function () use ($svg, $pdf, $dx, $dy, $dw, $dh): void {
            $svg->render($pdf, $dx, $dy, $dw, $dh);
        });
    }

    /**
     * CSS Images §5.5's concrete object size for one `object-fit` value, put
     * where `object-position` asks for it.
     *
     * `fill` takes the box, `contain` letterboxes, `cover` fills and overflows,
     * `none` is the picture's own size and `scale-down` is whichever of the
     * last two is smaller, which is the whole of the difference between them:
     * a picture larger than its box shrinks to `contain` and a smaller one
     * stays put. **A source with a ratio and no size of its own has no
     * intrinsic size to draw at**, and §5.2 gives `none` the default object
     * size instead, which for a replaced element is its own content box.
     *
     * `object-position` resolves the way `background-position` does, against
     * the room the placement left over, so its initial `50% 50%` is the
     * centring every fit but `fill` did before the property was read.
     *
     * @return array{0:float,1:float,2:float,3:float} x, y, width and height
     */
    private static function fittedRect(
        Node $n,
        float $x,
        float $y,
        float $w,
        float $h,
        float $iw,
        float $ih,
        bool $sized = true,
    ): array {
        $ratio    = $iw / $ih;
        $boxRatio = $w / max($h, 0.0001);

        $contain = $ratio > $boxRatio ? [$w, $w / $ratio] : [$h * $ratio, $h];
        $none    = $sized ? [$iw, $ih] : [$w, $h];
        $fits    = $none[0] <= $contain[0] + 0.01 && $none[1] <= $contain[1] + 0.01;

        [$dw, $dh] = match ($n->objectFit) {
            'contain'    => $contain,
            'cover'      => $ratio > $boxRatio ? [$h * $ratio, $h] : [$w, $w / $ratio],
            'scale-down' => $fits ? $none : $contain,
            default      => $none,
        };

        [$offsetX, $offsetY] = self::backgroundOrigin($n->objectPosition, $w - $dw, $h - $dh, $dw, $dh);

        return [$x + $offsetX, $y + $offsetY, $dw, $dh];
    }

    /** Draw inside the box where the placement overflows it, and plainly where it does not. */
    private static function clipped(
        Pdf $pdf,
        Node $n,
        float $x,
        float $y,
        float $w,
        float $h,
        float $dw,
        float $dh,
        callable $draw,
    ): void {
        $needsClip = $dw > $w + 0.01 || $dh > $h + 0.01;

        if ($needsClip) {
            $pdf->pushClip($x, $y, $w, $h, $n->borderRadius);
        }

        $draw();

        if ($needsClip) {
            $pdf->pop();
        }
    }

    /**
     * Whether this box's subtree is composited as one, which is what an
     * `opacity`, a `mix-blend-mode` or a `mask-image` asks for.
     *
     * A transform makes a stacking context and composites nothing, so on its
     * own it is a group root only where something below it blends and the
     * isolation is therefore observable. A transform declared on a box that
     * ALSO composites is a different question: the opacity, blend or mask
     * still needs its subtree drawn once and composited once, and gating that
     * on {@see Node::blendsBelow()} asked about the descendants when the
     * question was about the box itself. `SS-transform-clip.html` j1 and j2
     * read `(6, 18, 13)` against Chrome's `(60, 35, 14)`, a child blended
     * twice: once against its own parent's background and once against what
     * the pair of them stand on.
     */
    public static function makesGroup(Node $n): bool
    {
        if ($n->transform !== [] && !$n->compositesSubtree()) {
            return $n->blendsBelow();
        }

        return $n->wrapsSubtree();
    }

    /**
     * The effects that composite a whole subtree as one: opacity, blend mode,
     * transform and mask. Returns how many graphics states were pushed, so the
     * caller can balance them.
     *
     * Separate from the clip below it because the two travel differently. A
     * fragment carries its ancestors' clips already flattened into one page
     * rectangle on {@see Fragment::$clip}, and carries the ancestors
     * themselves for these, since a transform is a matrix rather than a region
     * and cannot be flattened the same way.
     *
     * **These are pushed around a group's drawing, not around each box in
     * it.** Set on the graphics state and left there, a constant alpha
     * composites every fill it covers against the one under it, so a border
     * shows through the background it stands on and a child shows through its
     * parent. The caller draws the subtree into a transparency group first and
     * pushes these around that, which is one composite for the group and is
     * what CSS asks for. See {@see Html::paintFragment}.
     */
    public static function pushGroupEffects(
        Pdf $pdf,
        Node $n,
        float $x,
        float $y,
        float $w,
        float $h,
        ?array $band = null,
    ): int {
        $pushed = 0;

        if ($n->opacity < 1.0) {
            $pdf->pushOpacity($n->opacity);
            $pushed++;
        }

        if ($n->blendMode !== 'normal') {
            $pdf->pushBlend($n->blendMode);
            $pushed++;
        }

        if ($n->transform !== []) {
            $pdf->pushTransform($n->transform, $x, $y, $w, $h, $n->transformOrigin);
            $pushed++;
        }

        // After the transform, because a mask is in the element's own space:
        // rotating a masked box rotates its mask with it, and the box's page
        // coordinates are what the `cm` above is already drawing through.
        if ($n->maskLayers !== []) {
            [$my, $mh] = self::maskBand($y, $h, $band);

            $pdf->pushSoftMask(
                (string) json_encode($n->maskLayers),
                $x,
                $my,
                $w,
                $mh,
                static fn(Pdf $into): null => self::paintMask($into, $n, $x, $my, $w, $mh),
            );

            $pushed++;
        }

        return $pushed;
    }

    /**
     * Every `mask-image` layer, drawn into the stream the soft mask is being
     * built in.
     *
     * **`mask-composite` decides how each layer combines with the ones BELOW
     * it.** Defect GX. **CSS lists the layers first-on-top, so they are drawn
     * LAST FIRST**, and the bottom one is drawn plain because its own operator
     * composites it with an empty backdrop and yields the layer itself, which is
     * what all four single-layer slots of `TN-mask-composite.html` read on both
     * engines. That is why the order matters even where the arithmetic does not:
     * it decides WHOSE operator counts, and n7 is the slot that says the bottom
     * layer's is ignored.
     *
     * **A layer paints WHITE with the mask's own alpha, so a blend mode sees a
     * source of 1 wherever the layer is there and no source at all where it is
     * not.** That is what decides which operators are one blend mode and which
     * are not. `add` is `a + b - ab`, and `Screen` gives it. `exclude` is
     * `a + b - 2ab`, and `Exclusion` gives it. **`intersect` is `a * b` and
     * `Multiply` does NOT give it**: `Multiply(1, b)` is `b`, so the layer
     * leaves the accumulated mask exactly as it found it and the box keeps the
     * bottom layer whole, which is 2,304 pixels of `TN` n6 against Chrome's 576.
     * **`subtract` is `a * (1 - b)` and no PDF blend mode is that either.** Both
     * need the accumulated mask to become a group the layer can multiply or
     * invert, and both are recorded as GY rather than painted wrongly: an
     * unimplemented operator composites as `add` here, which is one predictable
     * answer instead of a second wrong one.
     *
     * The area outside every layer is left as the group's own black backdrop,
     * which is alpha zero and hides the box. That is what `mask-repeat:
     * no-repeat` on a `mask-size` smaller than the box means.
     */
    private static function paintMask(Pdf $pdf, Node $n, float $x, float $y, float $w, float $h): null
    {
        $layers = array_reverse($n->maskLayers);

        foreach ($layers as $index => $layer) {
            if ($index > 0 && isset(self::MASK_GROUP_ONLY[$layer['composite'] ?? 'add'])) {
                self::compositeMaskLayers($pdf, $n, $layers, $x, $y, $w, $h);

                return null;
            }
        }

        foreach ($layers as $index => $layer) {
            $blend = $index === 0 ? null : self::MASK_BLENDS[$layer['composite'] ?? 'add'] ?? 'screen';

            if ($blend !== null) {
                $pdf->pushBlend($blend);
            }

            self::placeMaskLayer($pdf, $n, $layer, $x, $y, $w, $h);

            if ($blend !== null) {
                $pdf->pop();
            }
        }

        return null;
    }

    /**
     * One PDF blend mode per CSS compositing operator, on the greys a mask
     * layer's alpha travels as.
     *
     * @var array<string,string>
     */
    private const array MASK_BLENDS = [
        'add'       => 'screen',
        'exclude'   => 'exclusion',
        'intersect' => 'screen',
        'subtract'  => 'screen',
    ];

    /**
     * The two operators no blend mode reaches against a painted layer, so the
     * accumulated mask has to become a group first. Defect GY.
     *
     * @var array<string,true>
     */
    private const array MASK_GROUP_ONLY = [
        'intersect' => true,
        'subtract'  => true,
    ];

    /**
     * One PDF blend mode per CSS operator, on GROUPS whose luminosity is
     * already the alpha rather than on a layer painting white.
     *
     * `subtract` is a `Multiply` too, because the group it multiplies into is
     * the INVERTED accumulation rather than the accumulation.
     *
     * @var array<string,string>
     */
    private const array MASK_GROUP_BLENDS = [
        'add'       => 'screen',
        'exclude'   => 'exclusion',
        'intersect' => 'multiply',
        'subtract'  => 'multiply',
    ];

    /**
     * One mask layer clipped by `mask-clip` and placed against `mask-origin`.
     *
     * Both routes through {@see self::paintMask()} need exactly this, so it is
     * one method rather than two copies: the difference between them is what
     * the layer is drawn INTO, not where it goes.
     *
     * @param array<string,mixed> $layer
     */
    private static function placeMaskLayer(Pdf $pdf, Node $n, array $layer, float $x, float $y, float $w, float $h): void
    {
        $clip = $layer['clip'] ?? 'border-box';

        if ($clip !== 'border-box' && $clip !== 'no-clip') {
            [$cx, $cy, $cw, $ch, $radii] = self::boxArea($n, $clip, $x, $y, $w, $h);
            $pdf->pushClip($cx, $cy, $cw, $ch, $radii);
        }

        [$ox, $oy, $ow, $oh] = self::boxArea($n, $layer['origin'] ?? 'border-box', $x, $y, $w, $h);

        self::paintMaskLayer($pdf, $layer, $ox, $oy, $ow, $oh);

        if ($clip !== 'border-box' && $clip !== 'no-clip') {
            $pdf->pop();
        }
    }

    /**
     * Every layer as a transparency group, which is what `intersect` and
     * `subtract` need and what nothing else does. Defect GY.
     *
     * A layer paints WHITE with its own alpha, so against the accumulated mask
     * `Multiply` sees a source of 1 and leaves the accumulation alone. Drawn
     * into a group over a BLACK fill instead, the same layer comes out as a
     * luminosity that IS its alpha, and then every operator is a blend:
     *
     *     add        Screen(a, b)     = a + b - ab
     *     exclude    Exclusion(a, b)  = a + b - 2ab
     *     intersect  Multiply(a, b)   = a * b
     *     subtract   Multiply(1 - a, b)
     *
     * **`subtract` is the SOURCE outside the destination**, `b * (1 - a)` and
     * not `a * (1 - b)`, which CSS Masking 1 §4.3 gives as source-out. The two
     * paint the same NUMBER of pixels on any page whose layers are the same
     * size and a different picture, so the box is what says which: on
     * `TN-mask-composite.html` n8 the right answer is the top layer's own
     * square less the overlap, at 0 to 48, and the wrong one is the bottom
     * layer's, at 24 to 72. The inversion is a white fill with the accumulation
     * drawn over it in `Difference`.
     *
     * This route is taken ONLY when a layer asks for one of the two, so every
     * mask that does not is byte-identical to what round 64 left.
     *
     * @param list<array<string,mixed>> $layers bottom first
     */
    private static function compositeMaskLayers(Pdf $pdf, Node $n, array $layers, float $x, float $y, float $w, float $h): void
    {
        $black = [0.0, 0.0, 0.0];
        $white = [1.0, 1.0, 1.0];

        $accumulated = null;

        foreach ($layers as $index => $layer) {
            $pdf->beginGroup();
            $pdf->fillRect($x, $y, $w, $h, $black);
            self::placeMaskLayer($pdf, $n, $layer, $x, $y, $w, $h);
            $alone = $pdf->closeGroup([$x, $y, $w, $h], null, 1.0, true)['name'];

            if ($alone === null) {
                continue;
            }

            if ($accumulated === null) {
                $accumulated = $alone;

                continue;
            }

            $operator = $layer['composite'] ?? 'add';
            $inverts  = $operator === 'subtract';

            $pdf->beginGroup();
            $pdf->fillRect($x, $y, $w, $h, $inverts ? $white : $black);

            if ($inverts) {
                $pdf->pushBlend('difference');
            }

            $pdf->drawGroup($accumulated);

            if ($inverts) {
                $pdf->pop();
            }

            $pdf->pushBlend(self::MASK_GROUP_BLENDS[$operator] ?? 'screen');
            $pdf->drawGroup($alone);
            $pdf->pop();

            $accumulated = $pdf->closeGroup([$x, $y, $w, $h], null, 1.0, true)['name'] ?? $accumulated;
        }

        if ($accumulated !== null) {
            $pdf->drawGroup($accumulated);
        }
    }

    /**
     * One mask layer, placed by `mask-size`, `mask-position` and
     * `mask-repeat`.
     *
     * The three read exactly as their `background-*` counterparts do, against
     * the box `mask-origin` names, which the caller has already resolved: a
     * background has one box for positioning and another for clipping and so has
     * a mask, and both defaulting to the border box is what made one box look
     * like enough for 33 rounds.
     *
     * @param array<string,mixed> $layer
     */
    private static function paintMaskLayer(Pdf $pdf, array $layer, float $x, float $y, float $w, float $h): void
    {
        $intrinsic = match (true) {
            $layer['image'] !== null => [$layer['image']->width * 0.75, $layer['image']->height * 0.75],
            $layer['svg'] !== null   => [$layer['svg']->width * 0.75, $layer['svg']->height * 0.75],
            default                  => [$w, $h],
        };

        [$tileW, $tileH] = self::backgroundTile($layer['size'], $intrinsic, $w, $h);

        [$alongX, $alongY] = self::repeatAxes($layer['repeat']);

        $tileW = self::roundedTile($alongX, $tileW, $w);
        $tileH = self::roundedTile($alongY, $tileH, $h);

        if ($tileW <= 0.01 || $tileH <= 0.01) {
            return;
        }

        [$offsetX, $offsetY] = self::backgroundOrigin(
            $layer['position'],
            $w - $tileW,
            $h - $tileH,
            $tileW,
            $tileH,
        );

        [$startX, $columns, $pitchX] = self::tileRun($alongX, $x, $w, $x, $w, $offsetX, $tileW);
        [$startY, $rows, $pitchY]    = self::tileRun($alongY, $y, $h, $y, $h, $offsetY, $tileH);

        // A degenerate tile against a large box is a way to spend the whole
        // render on one decoration, so the count is bounded the way the
        // background tiling is.
        if ($columns * $rows > self::MAX_TILES) {
            $columns = min($columns, self::MAX_TILES);
            $rows    = min($rows, (int) max(1, self::MAX_TILES / $columns));
        }

        // No clip: the soft mask is a form XObject whose `/BBox` is the box
        // itself, so a tile that runs past the edge is already cut there and
        // an extra path would be two more operators saying the same thing.
        for ($row = 0; $row < $rows; $row++) {
            for ($col = 0; $col < $columns; $col++) {
                $tx = $startX + $col * $pitchX;
                $ty = $startY + $row * $pitchY;

                if ($layer['gradient'] !== null) {
                    $pdf->maskGradient($layer['gradient'], $layer['mode'], $tx, $ty, $tileW, $tileH);

                    continue;
                }

                if ($layer['svg'] !== null) {
                    $layer['svg']->render($pdf, $tx, $ty, $tileW, $tileH);

                    continue;
                }

                $pdf->maskImage($layer['image'], $layer['mode'], $tx, $ty, $tileW, $tileH);
            }
        }
    }

    /**
     * A mask's own y and height, cut down to the page the box is being
     * painted on.
     *
     * **Chrome restarts a mask's ramp on every fragment of a box it cuts**,
     * which is the opposite of what it does to a `background-image` on the
     * same box: that one is sized to the whole box and sliced.
     * `RQ-mask-fold.html` measures both on one page, and the two answers are
     * 46 grey levels apart over the same 120pt.
     *
     * A descendant of a masked container carries its ancestor's whole rect,
     * moved up by a page height for every page it has travelled, so on a
     * continuation the rect starts above the paper. Clipping it to the band
     * the page can hold is what turns that back into the slice this page
     * owns, and it is a no-op on the box that does not cross a fold at all.
     *
     * @param  array{0:float,1:float}|null $band the page's own top and bottom
     * @return array{0:float,1:float}
     */
    private static function maskBand(float $y, float $h, ?array $band): array
    {
        if ($band === null) {
            return [$y, $h];
        }

        $top    = max($y, $band[0]);
        $bottom = min($y + $h, $band[1]);

        return $bottom > $top ? [$top, $bottom - $top] : [$y, $h];
    }

    /**
     * Everything that wraps a whole subtree, the box's own decoration
     * included: the group effects above plus `clip-path`. Returns how many
     * graphics states were pushed, so the caller can balance them.
     *
     * The OVERFLOW clip is not here, because it cuts a box's content and not
     * the box: {@see pushOverflowClip()} is pushed inside this, once around the
     * box's own content by {@see paint()} and once around its descendants by
     * the caller.
     *
     * `$inGroup` says the box's own drawing is already inside the group its
     * effects are composited around, so only the clipping is left to push here.
     * A clip belongs inside rather than outside: it cuts what the box paints,
     * and a transform outside the group turns the cut piece with everything
     * else.
     */
    public static function pushEffects(
        Pdf $pdf,
        Node $n,
        float $x,
        float $y,
        float $w,
        float $h,
        ?array $band = null,
        bool $inGroup = false,
    ): int {
        $pushed = $inGroup ? 0 : self::pushGroupEffects($pdf, $n, $x, $y, $w, $h, $band);

        // `clip-path` cuts the box and its whole subtree, the box's own border
        // and background included, so it belongs here rather than around the
        // box's content. It stacks with the overflow clip: a box that declares
        // both keeps the intersection, which is what CSS asks for.
        if ($n->clipPath !== null) {
            $pdf->pushClipPath($n->clipPath, $x, $y, $w, $h);

            $pushed++;
        }

        return $pushed;
    }

    /**
     * A box's own overflow clip, which cuts its content and its descendants and
     * leaves its own decoration alone.
     *
     * The edge is the PADDING box, moved out by any `overflow-clip-margin`, and
     * `Node::overflowClipInset()` is the one rule the two painters and
     * `Fragmenter::pushClip()` all read. The box's own border and background
     * are painted outside this clip, in {@see paint()}, because the border sits
     * on the far side of the edge the clip cuts at. Defects GN and GM.
     *
     * Per axis, because Chrome clips per axis: an axis whose computed
     * `overflow` is `visible` keeps the ±1e9 span rather than the border box,
     * and a band with no bounded corner takes no radius with it.
     * `ZZ-overflow-clip-axes.html` `w4` and `w5`.
     *
     * @param string[]|null $edges which of the box's four edges this piece
     *                             owns, so a fold that has already taken one
     *                             away moves the clip nowhere on that side
     */
    public static function pushOverflowClip(
        Pdf $pdf,
        Node $n,
        float $x,
        float $y,
        float $w,
        float $h,
        ?array $edges = null,
    ): int {
        if ($n->overflow !== 'hidden') {
            return 0;
        }

        $clipsX = $n->overflowX !== 'visible';
        $clipsY = $n->overflowY !== 'visible';

        $owns = static fn(string $side): bool => $edges === null || in_array($side, $edges, true);

        [$left, $top, $right, $bottom] = [
            $owns('left') ? $n->overflowClipInset('left') : 0.0,
            $owns('top') ? $n->overflowClipInset('top') : 0.0,
            $owns('right') ? $n->overflowClipInset('right') : 0.0,
            $owns('bottom') ? $n->overflowClipInset('bottom') : 0.0,
        ];

        $clipsX && $clipsY
            ? $pdf->pushClip(
                $x + $left,
                $y + $top,
                $w - $left - $right,
                $h - $top - $bottom,
                $n->overflowClipRadii($w, $h),
            )
            : $pdf->pushClip(
                $clipsX ? $x + $left : -1e9,
                $clipsY ? $y + $top : -1e9,
                $clipsX ? $w - $left - $right : 2e9,
                $clipsY ? $h - $top - $bottom : 2e9,
            );

        return 1;
    }

    public static function popEffects(Pdf $pdf, int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            $pdf->pop();
        }
    }
}
