<?php

declare(strict_types=1);

namespace FlexPDF\Engine;

/** One positioned piece of a run on a line. */
final class InlineItem
{
    public function __construct(
        public InlineRun $run,
        public string $text,
        public float $x,
        public float $width,
        public bool $isSpace = false,
        public float $baselineShift = 0.0,
        public bool $rtl = false,

        /*
         * The inline boxes this piece is covered by, in the shape
         * InlineRun::$boxes carries, outermost first.
         *
         * They sit on the item rather than being read off the run because the
         * space in front of a word belongs to the run of the word that follows
         * it, and that word may be inside an element the space is not:
         * `Alpha <span>beta</span>` puts the space on the span's run, and
         * Chrome leaves it unfilled.
         */
        public array $boxes = [],

        /*
         * The shallowest box this piece opens, and the shallowest it closes;
         * everything deeper opens and closes with it, because a box cannot be
         * open before its parent is. `count($boxes)` means neither.
         *
         * Which piece opens a box is a fact about the document and not about
         * the line: an element that wraps opens once, on its first fragment,
         * and its second fragment carries no left padding and no left border.
         * A `box-decoration-break: clone` box is the exception, and that is
         * what the two flags below carry.
         */
        public int $openFrom = PHP_INT_MAX,
        public int $closeFrom = PHP_INT_MAX,

        /*
         * Whether this piece is the first on its line, and whether it is the
         * last. A `clone` box the piece is inside gets its left edge at the
         * start of every line and its right edge at the end of every one, so
         * for that box alone the answer is a fact about the LINE.
         */
        public bool $startsLine = false,
        public bool $endsLine = false,
    ) {}

    /**
     * Whether the box at $depth carries its left edge on this piece, and
     * whether it carries its right one: because the piece opens or closes it,
     * or because the box is `clone` and the piece begins or ends a line.
     */
    public function opensAt(int $depth): bool
    {
        return $this->openFrom <= $depth
            || ($this->startsLine && ($this->boxes[$depth]['clone'] ?? false));
    }

    public function closesAt(int $depth): bool
    {
        return $this->closeFrom <= $depth
            || ($this->endsLine && ($this->boxes[$depth]['clone'] ?? false));
    }

    /**
     * The border box of the inline box at $depth, as offsets from this item's
     * own text: how far left of $x it starts where this item opens it, and how
     * far right of $x + $width it ends where this item closes it.
     *
     * Everything nested *inside* that box which opens on the same item sits
     * within its padding, so those edges count towards the offset too. A box
     * that does not carry its own edge here contributes nothing, which is what
     * lets a `clone` element wrap inside a `slice` one and the other way
     * round.
     */
    public function edgeBefore(int $depth): float
    {
        $edge = 0.0;

        for ($i = $depth, $n = count($this->boxes); $i < $n; $i++) {
            if ($this->opensAt($i)) {
                $edge += InlineRun::leftEdge($this->boxes[$i]);
            }
        }

        return $edge;
    }

    public function edgeAfter(int $depth): float
    {
        $edge = 0.0;

        for ($i = $depth, $n = count($this->boxes); $i < $n; $i++) {
            if ($this->closesAt($i)) {
                $edge += InlineRun::rightEdge($this->boxes[$i]);
            }
        }

        return $edge;
    }

    /**
     * Whether this item is the atomic inline box itself rather than text.
     *
     * The space in front of a box is attributed to the box's own run, exactly
     * as the space in front of a word is attributed to the word's, so the run
     * alone cannot tell the two apart.
     */
    public function isAtomic(): bool
    {
        return $this->run->box !== null && !$this->isSpace;
    }
}
