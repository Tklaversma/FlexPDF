<?php

declare(strict_types=1);

namespace FlexPDF\Engine;

final class Painter
{
    public function __construct(
        private Pdf $pdf,
        private readonly Font $font = new Font(),
    ) {}

    public function paint(Node $n): void
    {
        $pushed = BoxPainter::pushEffects(
            $this->pdf,
            $n,
            $n->x,
            $n->y,
            $n->layoutWidth,
            $n->layoutHeight,
        );

        BoxPainter::paint(
            $this->pdf,
            $n,
            $n->x,
            $n->y,
            $n->layoutWidth,
            $n->layoutHeight,
            $n->lineBoxes,
        );

        // The box's own decoration is outside this clip and everything the box
        // holds is inside it, which is what a padding-box clip edge means.
        // Defect GN.
        $clipped = BoxPainter::pushOverflowClip(
            $this->pdf,
            $n,
            $n->x,
            $n->y,
            $n->layoutWidth,
            $n->layoutHeight,
        );

        // A raised sibling paints over the ones it was declared before, and a
        // flex item paints in order-modified document order. Both live in
        // BoxPainter so the two painting paths cannot disagree about it.
        foreach (BoxPainter::paintOrder($n) as $child) {
            $this->paint($child);
        }

        BoxPainter::popEffects($this->pdf, $clipped);
        BoxPainter::popEffects($this->pdf, $pushed);
    }
}
