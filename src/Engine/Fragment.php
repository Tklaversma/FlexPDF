<?php

declare(strict_types=1);

namespace FlexPDF\Engine;

/**
 * A single painted piece of a node on one page. A node that straddles a page
 * boundary produces two of these: this is the "fragment" of CSS Fragmentation.
 */
final class Fragment
{
    public function __construct(
        public Node $node,
        public float $x,
        public float $y,
        public float $w,
        public float $h,
        /** @var LineBox[] */
        public array $lines = [],
        public bool $isContinuation = false,
        public bool $splitsAfter = false,
        /** @var array{0:float,1:float,2:float,3:float,4:float}|null x,y,w,h,radius */
        public ?array $clip = null,

        /*
         * The same clips before they were flattened, each with the number of
         * subtree effects that were live when it was pushed.
         *
         * A clip declared above a transform cuts in the space the box that
         * declared it is in, and one declared under it cuts in the transformed
         * space. The flattened rectangle above cannot tell the two apart, and
         * the painter has to: pushing an outer clip inside a group turns it
         * with the content, which is defect FA.
         */
        /** @var list<array{0:float,1:float,2:float,3:float,4:array<int,float>,5:int}> x,y,w,h,radii,depth */
        public array $clipStack = [],

        /*
         * The subtree effects an ancestor wraps this piece in: opacity, blend
         * mode and transform, each with the geometry it was pushed at, in the
         * same page-local coordinates $clip uses. Outermost first.
         *
         * A fragment is painted on its own, so the graphics state the walk
         * that emitted it was inside no longer exists by then and the chain
         * has to travel with the piece. Without it an `opacity: 0.5` panel
         * fades and its words stay at full strength, which is defect DW.
         */
        /** @var list<array{0:Node,1:float,2:float,3:float,4:float}> node,x,y,w,h */
        public array $effects = [],

        /*
         * A slice continuing a line taller than its page. The box's own
         * decoration was already continued onto this page by clampOverflow(),
         * so painting it again here would draw the background twice, in two
         * different places. Only the lines belong to this fragment.
         */
        public bool $linesOnly = false,

        /*
         * Where this piece starts inside the whole box, and how tall the whole
         * box is, for a box the fold cut.
         *
         * A `background-image` is sized to the box and sliced, so every page
         * shows its own band of one gradient rather than a fresh gradient of
         * its own: Chrome's ramp takes 324 rows over a 320px box cut in two,
         * and one restarted per page takes 160. A container carries this on
         * the proxy node its decoration is painted through, which is a new
         * node per page; a childless box is the same node on every page and
         * has to carry it here.
         */
        /** @var array{0:float,1:float}|null */
        public ?array $slicedBackground = null,
    ) {}

    /**
     * The one rectangle a list of clips leaves, which is what a painter
     * pushes, and null where the list is empty.
     *
     * The corner radius is the largest any of them asked for, because a
     * rounded clip inside a square one is the rounded one and two rounded
     * clips of different radii cannot both be honored by a rectangle.
     *
     * @param  list<array{0:float,1:float,2:float,3:float,4:array<int,float>,5?:int}> $clips
     * @return array{0:float,1:float,2:float,3:float,4:array<int,float>}|null
     */
    public static function intersectClips(array $clips): ?array
    {
        if ($clips === []) {
            return null;
        }

        $x      = -1e9;
        $y      = -1e9;
        $right  = 1e9;
        $bottom = 1e9;
        $radii  = [0.0, 0.0, 0.0, 0.0];

        foreach ($clips as [$cx, $cy, $cw, $ch, $cr]) {
            $x      = max($x, $cx);
            $y      = max($y, $cy);
            $right  = min($right, $cx + $cw);
            $bottom = min($bottom, $cy + $ch);

            foreach ($radii as $i => $r) {
                $radii[$i] = max($r, $cr[$i]);
            }
        }

        return [$x, $y, max(0.0, $right - $x), max(0.0, $bottom - $y), $radii];
    }
}
