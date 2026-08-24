<?php

declare(strict_types=1);

namespace FlexPDF\Engine;

/**
 * Inline formatting context.
 *
 * This is the piece that decides whether output matches a browser. Three
 * things have to be right: words break between runs rather than within
 * them, every run on a line shares one baseline regardless of font size,
 * and the line box height comes from half-leading rather than from
 * naively multiplying the font size.
 */
final class InlineFormatter
{
    /** @var array<string,array{0:Font|TrueTypeFont,1:float,2:float}> face + above/below baseline, per style */
    private static array $metrics = [];

    /** Keyed by style, not object identity: runs are created freely. */
    private function metricsFor(InlineRun $run): array
    {
        // `font-stretch` picks a different face of the same family, so leaving
        // it out of the key hands the first slot's face to every later run
        // that agrees about everything else: on `RW-font-width.html` a
        // `condensed` slot measured and drew the regular face because the
        // control above it had already filled this entry.
        $id = $run->fontFamily . ($run->bold ? 'b' : 'r') . ($run->italic ? 'i' : '')
            . $run->verticalAlign . $run->fontSize . ':' . $run->lineHeight
            . ':' . $run->fontStretch;

        if (isset(self::$metrics[$id])) {
            return self::$metrics[$id];
        }

        $font            = $run->font();
        [$above, $below] = $font->lineBand($run->fontSize, $run->lineHeight * $run->fontSize);

        return self::$metrics[$id] = [$font, $above, $below];
    }

    /**
     * Given a line's top and height, returns [xOffset, availableWidth].
     * This is how floats shorten the line boxes beside them.
     *
     * `$strut` is the containing block's own font, which every line box
     * reserves room for whether or not anything on the line uses it.
     *
     * @param InlineRun[]                                         $runs
     * @param (callable(float,float):array{0:float,1:float})|null $constraint
     *
     * @return LineBox[]
     */
    public function format(
        array $runs,
        float $availableWidth,
        string $textAlign = 'left',
        ?callable $constraint = null,
        string $direction = 'auto',
        ?InlineRun $strut = null,
        float $textIndent = 0.0,
    ): array {
        $baseRtl = $direction === 'rtl';

        if ($direction === 'auto') {
            foreach ($runs as $run) {
                if (BidiText::containsRtl($run->text)) {
                    $baseRtl = BidiText::baseDirection($run->text) === 'rtl';
                    break;
                }
            }
        }

        if ($baseRtl && $textAlign === 'left') {
            $textAlign = 'right';
        }

        $tokens = $this->tokenize($runs);

        if (self::decorated($runs)) {
            $tokens = $this->linkInlineBoxes($tokens);
        }

        if ($tokens === []) {
            return [];
        }

        $lines   = [];
        $current = [];
        $width   = 0.0;

        /*
         * Probe height for the constraint: the tallest run in the paragraph.
         *
         * An atomic inline is measured by its own box and not by the font its
         * run happens to carry, which is the same split {@see buildLine} makes
         * when it takes the line's real extent. Leaving it out made a line
         * holding nothing but an `inline-block` as tall as the text that is
         * not on it, and at `line-height: 0` that is nothing at all: the band
         * asked about had `$top` and `$bottom` equal, `floatEdge()` skips a
         * float that starts at or after `$bottom`, and so **no float ever
         * intersected one**. That is defect EF, and `SB-zero-line-float.html`
         * f3 is what says the band is the line's own box rather than a point
         * at its top: its float starts 4px down, a point at the line's top
         * misses it, and Chrome still puts the block after it.
         *
         * Every box is already laid out by {@see layoutAtomicInlines}, so its
         * height is known here, before a line is built. It is an over-estimate
         * for a line that does not hold the tallest one, which is what this
         * probe has always been.
         */
        $probe = 0.0;

        foreach ($strut === null ? $runs : [...$runs, $strut] as $run) {
            if ($run->box !== null) {
                $probe = max($probe, $run->box->outerHeight());
            }

            [, $a, $d] = $this->metricsFor($run);
            $probe = max($probe, $a + $d);

            if ($run->firstLine !== null) {
                [, $a, $d] = $this->metricsFor($run->firstLine);
                $probe = max($probe, $a + $d);
            }
        }

        $cursorY    = 0.0;
        $lineOffset = 0.0;
        $lineWidth  = $availableWidth;

        if ($constraint !== null) {
            [$lineOffset, $lineWidth] = $constraint($cursorY, $probe);
        }

        /*
         * `text-indent` moves the first line's start and shortens it by the
         * same amount, so the rest of the paragraph is untouched. A negative
         * indent hangs the first line out to the left, which is what a
         * hanging bullet is written as.
         */
        $indent     = $textIndent;
        $lineOffset += $indent;
        $lineWidth  -= $indent;

        /*
         * `::first-line` styles the first formatted line, and which words that
         * is depends on the styling: a bigger first line holds fewer of them.
         * So each run is measured and painted through its first-line variant
         * until the first line closes, and through itself afterwards, which is
         * the order a browser resolves the same circularity in.
         */
        $onFirstLine = true;
        $styled      = static function (InlineRun $run) use (&$onFirstLine): InlineRun {
            return $onFirstLine ? ($run->firstLine ?? $run) : $run;
        };

        $close = function (bool $isLast) use (
            &$lines,
            &$current,
            &$width,
            &$cursorY,
            &$lineOffset,
            &$lineWidth,
            &$indent,
            &$onFirstLine,
            $constraint,
            $availableWidth,
            $textAlign,
            $probe,
            $baseRtl,
            $strut,
            $styled,
        ): void {
            $line = $this->buildLine(
                $current,
                $lineWidth,
                $textAlign,
                $isLast,
                $baseRtl,
                $strut === null ? null : $styled($strut),
            );

            if ($lineOffset !== 0.0) {
                foreach ($line->items as $item) {
                    $item->x += $lineOffset;
                }
            }

            $lines[]     = $line;
            $cursorY     += $line->height;
            $current     = [];
            $width       = 0.0;
            $onFirstLine = false;

            if ($indent !== 0.0) {
                $lineOffset -= $indent;
                $lineWidth  += $indent;
                $indent      = 0.0;
            }

            if ($constraint !== null) {
                [$lineOffset, $lineWidth] = $constraint($cursorY, $probe);
            }
        };

        foreach ($tokens as $index => $tok) {
            [$base, $text, $isSpace, $isBreak, $isRtl, $raw, $joins, $opens, $closes] = $tok
                + [3 => false, 4 => false, 5 => '', 6 => false, 7 => 0, 8 => 0];

            $run   = $styled($base);
            $boxes = $run->boxes;
            $seat  = $run->baselineShift;
            $text  = InlineRun::transform($text, $run->firstLineTransform);
            $raw   = $raw === '' ? '' : InlineRun::transform($raw, $run->firstLineTransform);

            $joins = $joins || $run->joinsPrevious;

            if ($isBreak) {
                // A forced break ends the line even if it is empty, so that
                // consecutive <br> produce blank lines like a browser.
                if ($current === []) {
                    $empty       = $this->emptyLine($run);
                    $lines[]     = $empty;
                    $cursorY     += $empty->height;
                    $onFirstLine = false;

                    if ($indent !== 0.0) {
                        $lineOffset -= $indent;
                        $lineWidth  += $indent;
                        $indent      = 0.0;
                    }

                    if ($constraint !== null) {
                        [$lineOffset, $lineWidth] = $constraint($cursorY, $probe);
                    }
                } else {
                    $close(true);
                }

                continue;
            }

            $w = $this->advance($run, $text, $isSpace);

            /*
             * An inline element's `padding` and `border` are advance on the
             * line: Chrome puts the left pair in front of the element's first
             * fragment and the right pair after its last one, and neither in
             * the middle of an element that wraps. So they belong to the token
             * that opens the box and to the one that closes it, and everything
             * between the two is measured exactly as before.
             */
            $edgeLeft  = 0.0;
            $edgeRight = 0.0;

            if ($boxes !== []) {
                $edgeLeft  = self::edgeSum($boxes, $opens, true, $current === []);
                $edgeRight = self::edgeSum($boxes, $closes, false);
            }

            $place = $edgeLeft + $w + $edgeRight;

            /*
             * A word an author styled part of arrives as several tokens with
             * no space between them, and it has to wrap as one: the fit is
             * measured against the whole group, and the pieces after the
             * first never end a line. That is `<b>Ham</b>burger`, `100<sup>th
             * </sup>` and a link against its comma, as well as the word split
             * for small caps this started as.
             *
             * Only the token that opens a group looks ahead, and only it reads
             * the answer. Measuring from every token in turn would be
             * quadratic in the group's length, and a group is as long as an
             * author cares to make it: `<b>a</b>` written ten thousand times
             * with no space between is one word.
             */
            $fit = $place;

            if (!$joins && !$isSpace) {
                for ($j = $index + 1; isset($tokens[$j]) && $this->continues($tokens[$j]); $j++) {
                    $next = $styled($tokens[$j][0]);
                    $fit  += $this->advance($next, $tokens[$j][1], (bool) ($tokens[$j][2] ?? false));

                    if ($next->boxes !== []) {
                        $fit += self::edgeSum($next->boxes, $tokens[$j][7] ?? 0, true)
                            + self::edgeSum($next->boxes, $tokens[$j][8] ?? 0, false);
                    }
                }
            }

            // Collapse whitespace at the start of a line, unless the source
            // spacing is content, in which case it is part of the line.
            if ($isSpace && $current === [] && $run->collapsesWhitespace()) {
                continue;
            }

            if ($joins) {
                $current[] = [$run, $text, $isSpace, $w, $isRtl, $boxes, $seat, $opens, $closes];
                $width     += $place;

                continue;
            }

            /*
             * **A box that is still OPEN where the line breaks reserves
             * nothing**, and that is the whole of defect HP. The closing edge
             * of a `box-decoration-break: clone` box is painted on the piece
             * that ends the line, and Chrome lets it HANG past the line rather
             * than breaking a word earlier to make room for it:
             * `UK-inline-close-clone.html` is eight bands of 120px, five of
             * them with 36px of closing padding somewhere in a stack of open
             * boxes, and Chrome carries thirteen words on the first line of
             * all eight. The engine carried nine in the five, at every depth
             * and its own box included, which is one word count for the whole
             * page and not the ancestors-only rule round 68 recorded off two
             * bands whose 12px padding was too small to move a break.
             *
             * `UJ-inline-close-slice.html` is the same page under `slice`,
             * where there is no closing edge on the first fragment at all:
             * both engines carry thirteen words in all eight bands, before
             * this and after it.
             */
            if (!$isSpace && $run->wraps() && $current !== [] && $width + $fit > $lineWidth + 1e-6) {
                // Before giving up on the line, see whether part of the word
                // can be carried on it with a hyphen. A word whose pieces are
                // spread over several runs is left alone: the hyphenator sees
                // one piece and would break inside a syllable it cannot see.
                // A word the first line rewrites is not hyphenated across the
                // fold: the head would carry the rewritten text and the tail
                // would have to carry the author's, and the two are only the
                // same length by luck.
                // A head that stops mid-word closes no box, so it owes nothing
                // on the right: every box it sits in is still open there and
                // an open box's closing edge hangs past the line.
                $split = $fit > $place || $run->firstLineTransform !== 'none' ? null : $this->hyphenate(
                    $run,
                    $raw !== '' ? $raw : $text,
                    $isRtl,
                    $lineWidth - $width - $edgeLeft,
                    $lineWidth,
                );

                if ($split !== null) {
                    [$head, $tail, $headWidth] = $split;

                    // The element does not end where the word does, so the head
                    // carries the left edge and the tail the right one, and
                    // neither carries the other. `box-decoration-break: slice`.
                    $current[] = [$run, $head, false, $headWidth, $isRtl, $boxes, $seat, $opens, count($boxes)];
                    $close(false);

                    // The line the head closed was the first one, so the tail
                    // is styled as the rest of the paragraph is.
                    $run      = $styled($base);
                    $boxes    = $run->boxes;
                    $text     = $tail;
                    $w        = $this->advance($run, $tail);
                    $opens    = count($boxes);
                    $edgeLeft = 0.0;
                } else {
                    $close(false);

                    // The whole token moved to the second line, so it is the
                    // author's text again rather than the first line's.
                    $run   = $styled($base);
                    $boxes = $run->boxes;
                    $text  = InlineRun::transform($tok[1], $run->firstLineTransform);
                    $w     = $this->advance($run, $text, $isSpace);
                }

                $edgeLeft  = $boxes === [] ? 0.0 : self::edgeSum($boxes, $opens, true, $current === []);
                $edgeRight = $boxes === [] ? 0.0 : self::edgeSum($boxes, $closes, false);
                $place     = $edgeLeft + $w + $edgeRight;
            }

            /*
             * The word still does not fit on a line of its own. Break it
             * mid-word when the style allows, otherwise let it overflow, which
             * is what CSS asks for.
             */
            while (!$isSpace && $fit <= $place && $run->wraps() && $run->breaksWords() && $width + $place > $lineWidth + 1e-6) {
                $piece = $this->breakWord(
                    $run,
                    $text,
                    $lineWidth - $width - $edgeLeft,
                );

                if ($piece === null) {
                    break;
                }

                [$head, $tail, $headWidth] = $piece;
                $current[] = [$run, $head, false, $headWidth, $isRtl, $boxes, $seat, $opens, count($boxes)];
                $close(false);

                $run      = $styled($base);
                $boxes    = $run->boxes;
                $text     = $tail;
                $w        = $this->advance($run, $tail);
                $opens     = count($boxes);
                $edgeLeft  = $boxes === [] ? 0.0 : self::edgeSum($boxes, $opens, true, true);
                $edgeRight = $boxes === [] ? 0.0 : self::edgeSum($boxes, $closes, false);
                $place     = $edgeLeft + $w + $edgeRight;
            }

            $current[] = [$run, $text, $isSpace, $w, $isRtl, $boxes, $seat, $opens, $closes];
            $width     += $place;
        }

        if ($current !== []) {
            $close(true);
        }

        return $lines;
    }

    /**
     * `text-overflow: ellipsis`: cut a line that overruns its box and mark the
     * cut with U+2026.
     *
     * The line keeps the items that fit alongside the ellipsis, and the last
     * one that only partly fits is shortened a character at a time rather
     * than dropped, because dropping it loses a word where a browser would
     * show most of it. A trailing space before the ellipsis is trimmed, which
     * is what Chrome paints.
     *
     * @param  LineBox[] $lines
     * @return LineBox[]
     */
    public function applyEllipsis(array $lines, float $available): array
    {
        foreach ($lines as $line) {
            $content = 0.0;

            foreach ($line->items as $item) {
                $content += $item->width;
            }

            if ($content <= $available + 0.01 || $line->items === []) {
                continue;
            }

            $this->ellipsize($line, $available);
        }

        return $lines;
    }

    /**
     * The same cut, on a line that fits.
     *
     * `-webkit-line-clamp` marks the last line it keeps whether or not that
     * line overruns, because what was dropped is the lines under it rather
     * than the tail of this one. Chrome shortens the line only as far as the
     * marker needs: `RX-clamp.html` n1 keeps `...eta theta` whole and adds the
     * marker after it, and n5 has to cut `delta` back to `del` to fit one.
     */
    public function clampEllipsis(LineBox $line, float $available): void
    {
        if ($line->items !== []) {
            $this->ellipsize($line, $available);
        }
    }

    private function ellipsize(LineBox $line, float $available): void
    {
        $marker = "\u{2026}";
        $run    = $line->items[0]->run;
        $budget = $available - $this->advance($run, $marker);

        if ($budget <= 0.0) {
            $line->items = [];
            $budget      = $available;
        }

        $kept = [];
        $used = 0.0;

        foreach ($line->items as $item) {
            if ($used + $item->width <= $budget + 0.01) {
                $kept[] = $item;
                $used   += $item->width;

                continue;
            }

            $text = $item->text;

            while ($text !== '' && $used + $this->advance($item->run, $text) > $budget) {
                $text = mb_substr($text, 0, mb_strlen($text) - 1);
            }

            if ($text !== '') {
                $width  = $this->advance($item->run, $text);
                $kept[] = new InlineItem(
                    $item->run,
                    $text,
                    $item->x,
                    $width,
                    false,
                    $item->baselineShift,
                    $item->rtl,
                    $item->boxes,
                    $item->openFrom,
                    $item->closeFrom,
                );
                $used   += $width;
            }

            break;
        }

        while ($kept !== [] && trim(end($kept)->text) === '') {
            $used -= array_pop($kept)->width;
        }

        $tail = $kept === [] ? $line->items[0] ?? null : end($kept);
        $run  = $tail?->run ?? $run;

        $kept[]      = new InlineItem(
            $run,
            $marker,
            $used,
            $this->advance($run, $marker),
            boxes: $tail?->boxes ?? [],
            closeFrom: $tail?->closeFrom ?? PHP_INT_MAX,
        );
        $line->items = $kept;
    }

    /**
     * One line box with nothing on it, at the metrics of the run given.
     *
     * A box with no tokens gets no line at all, which is right everywhere but
     * an empty list item: its marker is content on a line the item still
     * takes, so {@see FlexLayout::measureEmpty} asks for one here rather than
     * spelling the metrics out a second time.
     */
    public function emptyLine(InlineRun $run): LineBox
    {
        [, $above, $below] = $this->metricsFor($run);
        $line           = new LineBox();
        $line->baseline = $above;
        $line->height   = $above + $below;

        return $line;
    }

    /**
     * Whether any of these runs sits inside an inline box at all.
     *
     * It is read off the runs and not off the tokens, of which there are one
     * per word: a document with no inline decoration in it is the common case
     * and must not pay a pass over its own text to find that out.
     *
     * @param InlineRun[] $runs
     */
    private static function decorated(array $runs): bool
    {
        foreach ($runs as $run) {
            if ($run->boxes !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * The advance the inline boxes from $from inwards add on one side.
     *
     * $from is the shallowest box the piece opens, or closes; everything deeper
     * opens and closes with it, because a box cannot be open before its parent
     * is. It equals `count($boxes)` where the piece opens or closes none, which
     * is what makes a document with no inline decoration in it cost one
     * comparison per token and nothing else.
     *
     * $atLineEdge says the piece starts the line, when the left edge is asked
     * for, or ends it, when the right one is. A `box-decoration-break: clone`
     * box shallower than $from adds its edge there even though the piece opens
     * or closes nothing: CSS Fragmentation §4.2 gives every line of such an
     * element all four of its borders and both of its paddings. A `slice` box
     * beside it in the same stack is unaffected, which is why this is read per
     * box rather than by moving $from.
     *
     * @param list<array{padLeft:float,padRight:float,clone?:bool,border:array<string,array{width:float,style:string,color:array<int,float>}>|null}> $boxes
     */
    private static function edgeSum(array $boxes, int $from, bool $left, bool $atLineEdge = false): float
    {
        $edge = 0.0;

        for ($i = $from, $n = count($boxes); $i < $n; $i++) {
            $edge += $left ? InlineRun::leftEdge($boxes[$i]) : InlineRun::rightEdge($boxes[$i]);
        }

        return $atLineEdge ? $edge + self::cloneEdge($boxes, $from, $left) : $edge;
    }

    /**
     * The same advance for the `clone` boxes the piece does not open or close,
     * which is what a line edge owes over and above the document's own answer.
     *
     * @param list<array{padLeft:float,padRight:float,clone?:bool,border:array<string,array{width:float,style:string,color:array<int,float>}>|null}> $boxes
     */
    private static function cloneEdge(array $boxes, int $from, bool $left): float
    {
        $edge = 0.0;

        for ($i = 0, $n = min($from, count($boxes)); $i < $n; $i++) {
            if (!($boxes[$i]['clone'] ?? false)) {
                continue;
            }

            $edge += $left ? InlineRun::leftEdge($boxes[$i]) : InlineRun::rightEdge($boxes[$i]);
        }

        return $edge;
    }

    /**
     * Work out, for every token, which inline boxes it opens and which it
     * closes, by comparing its stack with the one before it.
     *
     * The answer is a fact about the document rather than about the line: an
     * element that wraps opens on its first fragment and closes on its last,
     * and CSS's default `box-decoration-break: slice` gives the fragments
     * between them no left and no right edge. So it is settled here, once,
     * before anything knows where the lines will fall.
     *
     * A forced break is transparent: `<span>a<br>b</span>` is one span, not
     * two, and the token either side of the break belongs to the same box.
     *
     * @param  array<array{0:InlineRun,1:string,2?:bool,3?:bool,4?:bool,5?:string,6?:bool}> $tokens
     * @return array<array{0:InlineRun,1:string,2?:bool,3?:bool,4?:bool,5?:string,6?:bool,7?:int,8?:int}>
     */
    private function linkInlineBoxes(array $tokens): array
    {
        $previous = null;

        foreach ($tokens as $index => $token) {
            if ($token[3] ?? false) {
                continue;
            }

            $boxes  = $token[0]->boxes;
            $shared = 0;

            if ($previous !== null) {
                // Two tokens off the same run sit in the same elements, so the
                // stacks match to the bottom without being walked. That is the
                // common case by far: it is every word of a paragraph, and
                // walking it would cost the nesting depth per word.
                if ($tokens[$previous][0] === $token[0]) {
                    $shared = count($boxes);
                } else {
                    $before = $tokens[$previous][0]->boxes;
                    $limit  = min(count($before), count($boxes));

                    while ($shared < $limit && $before[$shared]['id'] === $boxes[$shared]['id']) {
                        $shared++;
                    }
                }

                $tokens[$previous][8] = $shared;
            }

            $tokens[$index][7] = $shared;
            $tokens[$index][8] = count($boxes);
            $previous          = $index;
        }

        if ($previous !== null) {
            $tokens[$previous][8] = 0;
        }

        return $tokens;
    }

    /**
     * Whether a token continues the word before it, so the two must wrap
     * together.
     *
     * @param array{0:InlineRun,1:string,2?:bool,3?:bool,4?:bool,5?:string,6?:bool} $token
     */
    private function continues(array $token): bool
    {
        return ($token[6] ?? false) || $token[0]->joinsPrevious;
    }

    /**
     * Split runs into atomic tokens (words and single spaces), preserving
     * which run each came from. Whitespace collapses per `white-space: normal`.
     *
     * The seventh field says the token continues the one before it with no
     * white space between them, which is what makes `<b>Ham</b>burger` one
     * word rather than two. It is a property of the pair of tokens, not of
     * the run: the same run both joins what precedes it and is joined by what
     * follows, and only the first word of a group carries a false.
     *
     * A collapsed space between two words carries the run whose *text* it came
     * from rather than the run of the word that follows it, which is the one
     * emitted next. Chrome measures `Alpha <span style="font-size:20px">beta`'s
     * space at the paragraph's 12px and `<span style="font-size:20px">Alpha
     * </span>beta`'s at the span's 20px, both to the thousandth on
     * `docs/harness/probes/B12-space-owner.html`, and it is what decides the
     * space's font, letter and word spacing, color, decoration, background
     * band and baseline as well as its width.
     *
     * @param InlineRun[] $runs
     *
     * @return array<array{0:InlineRun,1:string,2:bool,3:bool,4:bool,5:string,6?:bool}>
     */
    private function tokenize(array $runs): array
    {
        $tokens       = [];
        $pendingSpace = false;
        $spaceOwner   = null;
        $joins        = false;

        foreach ($runs as $run) {
            if ($run->isBreak) {
                $tokens[]     = [$run, '', false, true, false, ''];
                $pendingSpace = false;
                $joins        = false;
                continue;
            }

            // An atomic inline is one token that never splits, and it takes
            // the space before it exactly as a word would. It is also a break
            // opportunity on both sides, so it ends any group it lands in.
            //
            // An `inside` list marker is the same shape: a browser draws it
            // rather than setting a character, so it carries no text at all
            // and takes an advance of its own.
            if ($run->box !== null || $run->markerShape !== null || $run->markerImage !== null) {
                if ($pendingSpace && $tokens !== [] && !(end($tokens)[3] ?? false)) {
                    $tokens[] = [$spaceOwner ?? $run, ' ', true, false, false, ' ', false];
                }

                $pendingSpace = false;
                $joins        = false;
                $tokens[]     = [$run, '', false, false, false, ''];

                continue;
            }

            $parts = preg_split('/(\s+)/', $run->text, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];

            foreach ($parts as $part) {
                if ($part === '') {
                    continue;
                }

                if (preg_match('/^\s+$/', $part)) {
                    // Under `pre` and friends the source spacing is the
                    // content: newlines break the line and runs of spaces keep
                    // their width instead of collapsing to one.
                    if (!$run->collapsesWhitespace()) {
                        $pendingSpace = false;
                        $joins        = false;
                        $tokens       = [
                            ...$tokens,
                            ...$this->preservedWhitespaceTokens($run, $part),
                        ];

                        continue;
                    }

                    $pendingSpace = true;
                    $spaceOwner   = $run;
                    $joins        = false;
                    continue;
                }

                if ($pendingSpace && $tokens !== [] && !(end($tokens)[3] ?? false)) {
                    $tokens[] = [$spaceOwner ?? $run, ' ', true, false, false, ' ', false];
                }

                $pendingSpace = false;

                // Arabic is shaped, then each RTL token is flipped so the
                // glyphs sit in visual order; token order is fixed per line.
                // Soft hyphens are break opportunities, not glyphs. The raw
                // form is kept for the hyphenator; everything else uses the
                // stripped form, so an unused soft hyphen never renders.
                $raw  = $part;
                $part = str_replace(Hyphenator::SOFT_HYPHEN, '', $part);

                $rtl = BidiText::containsRtl($part);

                if ($rtl) {
                    $part = BidiText::reorder(BidiText::shape($part));
                    $raw  = $part;
                }

                // UAX #14: CJK text carries no spaces, so a paragraph of it is
                // one token to a whitespace split and overflows the line
                // instead of wrapping. Segmenting it gives each character its
                // own break opportunity.
                //
                // Every character CJK names is three UTF-8 bytes led by one in
                // 0xE2 to 0xEF, so text carrying none of those needs no
                // segmenting. That is the path every Latin document takes, and
                // keeping it a byte scan costs it no call and no array.
                if ($rtl || strcspn($part, self::CJK_LEAD) === strlen($part)) {
                    $tokens[] = [$run, $part, false, false, $rtl, $raw, $joins];
                } else {
                    foreach (self::cjkSegments($part) as $k => $segment) {
                        // The first segment keeps whatever the incoming
                        // `$joins` said, so a word continued from a previous
                        // run still cannot be broken from it. Every segment
                        // after it is a break opportunity, which is the point.
                        $tokens[] = [$run, $segment, false, false, $rtl, $k === 0 ? $raw : $segment, $k === 0 && $joins];
                    }
                }

                $joins = true;
            }
        }

        return $tokens;
    }

    /**
     * Characters that carry a line break opportunity beside them: the CJK and
     * fullwidth blocks, which is UAX #14's ID class plus the kana and hangul
     * that behave like it.
     */
    private const string CJK = '\x{2E80}-\x{303E}\x{3041}-\x{33FF}\x{3400}-\x{4DBF}'
        . '\x{4E00}-\x{9FFF}\x{A000}-\x{A4CF}\x{AC00}-\x{D7A3}\x{F900}-\x{FAFF}'
        . '\x{FE30}-\x{FE4F}\x{FF00}-\x{FF60}\x{FFE0}-\x{FFE6}';

    /**
     * The UTF-8 lead bytes those ranges can start with. U+2E80 leads with 0xE2
     * and U+FFE6 with 0xEF, and every range between them sits in the same
     * three-byte band, so text holding none of these bytes holds no CJK.
     */
    private const string CJK_LEAD = "\xE2\xE3\xE4\xE5\xE6\xE7\xE8\xE9\xEA\xEB\xEC\xED\xEE\xEF";

    /**
     * UAX #14's non-starters: a line may not begin with one of these, so the
     * break goes before the character in front of it instead. Closing
     * brackets, the CJK stops and commas, the small kana and the sound marks.
     */
    private const string NO_START = '、。，．！？：；）〕］｝〉》」』】〙〗〟｠»'
        . 'ヽヾーァィゥェォッャュョヮヵヶぁぃぅぇぉっゃゅょゎ々〻‐゠–〜?!‼⁇⁈⁉・｡｣､…';

    /** The mirror: a line may not end with an opening bracket or quote. */
    private const string NO_END = '（〔［｛〈《「『【〘〖〝｟«';

    /**
     * One whitespace-delimited run of text, split where CSS Text lets a line
     * break without a space.
     *
     * A break goes between two characters when at least one of them is CJK,
     * the one after it may start a line, and the one before it may end one.
     * Text with no CJK in it comes back as a single segment, which is the
     * path every Latin document takes.
     *
     * @return list<string>
     */
    private static function cjkSegments(string $text): array
    {
        if (preg_match('/[' . self::CJK . ']/u', $text) !== 1) {
            return [$text];
        }

        $chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $out   = [];
        $piece = '';

        foreach ($chars as $i => $char) {
            $previous = $i === 0 ? '' : $chars[$i - 1];

            if ($piece !== '' && self::breaksBefore($previous, $char)) {
                $out[]  = $piece;
                $piece  = '';
            }

            $piece .= $char;
        }

        if ($piece !== '') {
            $out[] = $piece;
        }

        return $out;
    }

    private static function breaksBefore(string $previous, string $char): bool
    {
        if (mb_strpos(self::NO_START, $char) !== false || mb_strpos(self::NO_END, $previous) !== false) {
            return false;
        }

        return preg_match('/[' . self::CJK . ']/u', $char) === 1
            || preg_match('/[' . self::CJK . ']/u', $previous) === 1;
    }

    /**
     * Whitespace under `pre`, `pre-wrap` and `pre-line`. A newline is a forced
     * break in all three; the spaces around it survive only where they are not
     * being collapsed.
     *
     * @return array<array{0:InlineRun,1:string,2:bool,3:bool,4:bool,5:string}>
     */
    private function preservedWhitespaceTokens(InlineRun $run, string $whitespace): array
    {
        $tokens   = [];
        $collapse = $run->whiteSpace === 'pre-line';

        foreach (preg_split('/(\n)/', $whitespace, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [] as $chunk) {
            if ($chunk === '') {
                continue;
            }

            if ($chunk === "\n") {
                $tokens[] = [$run, '', false, true, false, ''];
                continue;
            }

            $text     = $collapse ? ' ' : str_replace("\t", '    ', $chunk);
            $tokens[] = [$run, $text, true, false, false, $text];
        }

        return $tokens;
    }

    /**
     * Advance width of a token, including the letter and word spacing CSS
     * asks for. Letter spacing lands after every character, which is what
     * browsers do and what the PDF `Tc` operator reproduces.
     */
    private function advance(InlineRun $run, string $text, bool $isSpace = false): float
    {
        if ($run->box !== null && !$isSpace) {
            return $run->box->outerWidth();
        }

        if (($run->markerShape !== null || $run->markerImage !== null) && !$isSpace) {
            return $run->markerMetrics()['advance'];
        }

        $face  = $this->metricsFor($run)[0];
        $width = $face->stringWidth($text, $run->fontSize, $run->fontFeatures);

        if ($run->letterSpacing !== 0.0) {
            // `Tc` lands after every glyph the writer shows, and a ligature is
            // one glyph where the author wrote two, so the count is the shaped
            // one rather than the character one.
            $width += $run->letterSpacing * $face->glyphCount($text, $run->fontFeatures);
        }

        if ($isSpace && $run->wordSpacing !== 0.0) {
            $width += $run->wordSpacing * mb_substr_count($text, ' ');
        }

        return $width;
    }

    /**
     * Split a word that cannot fit on a line of its own, one character at a
     * time. This is `word-break: break-all` and `overflow-wrap: break-word`;
     * without it an IBAN or a long URL paints straight past its box.
     *
     * @return array{0:string,1:string,2:float}|null head, tail, head width
     */
    private function breakWord(InlineRun $run, string $word, float $available): ?array
    {
        $length = mb_strlen($word);

        if ($length < 2 || $available <= 0.0) {
            return null;
        }

        $fitted = 0;
        $width  = 0.0;

        for ($i = 1; $i <= $length; $i++) {
            $candidate = $this->advance($run, mb_substr($word, 0, $i));

            if ($candidate > $available + 1e-6) {
                break;
            }

            $fitted = $i;
            $width  = $candidate;
        }

        if ($fitted < 1 || $fitted >= $length) {
            return null;
        }

        return [mb_substr($word, 0, $fitted), mb_substr($word, $fitted), $width];
    }

    /**
     * Try to fit the start of a word on the current line, hyphenated.
     *
     * Returns the head (with its hyphen appended), the tail, and the head's
     * width, or null when no break point leaves something worth keeping.
     *
     * @return array{0:string,1:string,2:float}|null
     */
    private function hyphenate(
        InlineRun $run,
        string $word,
        bool $isRtl,
        float $room,
        float $lineWidth,
    ): ?array {
        if ($run->hyphens === 'none' || $isRtl || $room <= 0.0) {
            return null;
        }

        $pieces = Hyphenator::split($word, $run->hyphens === 'auto');

        if (count($pieces) < 2) {
            return null;
        }

        $font        = $this->metricsFor($run)[0];
        $hyphenWidth = $this->advance($run, '-');

        $head  = '';
        $best  = null;
        $count = count($pieces);

        foreach ($pieces as $i => $piece) {
            if ($i === $count - 1) {
                break; // never break after the last piece
            }

            $head  .= $piece;
            $width = $this->advance($run, $head) + $hyphenWidth;

            if ($width > $room + 1e-6) {
                break;
            }

            // Rebuild the tail from the remaining pieces rather than slicing
            // the original: soft hyphens make byte offsets meaningless.
            $tail = implode('', array_slice($pieces, $i + 1));
            $best = [$head . '-', $tail, $width];
        }

        // A tail that cannot fit a line of its own would just move the problem
        if ($best !== null && $this->advance($run, $best[1]) > $lineWidth + 1e-6) {
            return null;
        }

        return $best;
    }

    /**
     * Reverse the visual order of tokens so RTL reads right to left, keeping
     * runs of LTR tokens (names, numbers, code) in their own order.
     *
     * @param array<array{0:InlineRun,1:string,2:bool,3:float,4:bool}> $parts
     *
     * @return array<array{0:InlineRun,1:string,2:bool,3:float,4:bool}>
     */
    private function applyBidi(array $parts, bool $baseRtl): array
    {
        $hasRtl = false;

        foreach ($parts as $p) {
            if ($p[4] ?? false) {
                $hasRtl = true;
                break;
            }
        }

        if (!$hasRtl && !$baseRtl) {
            return $parts;
        }

        if (!$baseRtl) {
            // LTR paragraph: only contiguous RTL stretches flip
            $out    = [];
            $buffer = [];

            foreach ($parts as $p) {
                if ($p[4] ?? false) {
                    $buffer[] = $p;
                    continue;
                }

                if ($buffer !== []) {
                    $out    = array_merge($out, array_reverse($buffer));
                    $buffer = [];
                }

                $out[] = $p;
            }

            return array_merge($out, array_reverse($buffer));
        }

        // RTL paragraph: reverse everything, then un-reverse LTR stretches
        $reversed = array_reverse($parts);
        $out      = [];
        $buffer   = [];

        foreach ($reversed as $p) {
            if (!($p[4] ?? false) && !($p[2] ?? false)) {
                $buffer[] = $p;
                continue;
            }

            if ($buffer !== []) {
                $out    = array_merge($out, array_reverse($buffer));
                $buffer = [];
            }

            $out[] = $p;
        }

        return array_merge($out, array_reverse($buffer));
    }

    /**
     * Seat every `vertical-align: top` and `bottom` run against the line box,
     * growing it where one does not fit.
     *
     * CSS 2.1 §10.8.1 aligns the top of such a box with the top of the line
     * box, which is circular: the line's extent comes from the items on it and
     * these two want it before they can be placed. Browsers resolve it the way
     * this does, by leaving them out of the first pass and growing the line
     * only where an aligned box turns out to be taller than what is already
     * there. The other half of defect AF.
     *
     * The shift is written back onto the part, which is what the items were
     * built from and what the painter reads.
     *
     * @param  array<int,array> $parts
     * @return array{0:float,1:float} the line's extent, possibly grown
     */
    private function seatLineRelative(array &$parts, float $above, float $below): array
    {
        $relative = [];

        foreach ($parts as $i => [$run, , $isSpace]) {
            if ($run->verticalAlign === 'top' || $run->verticalAlign === 'bottom') {
                $relative[$i] = $isSpace;
            }
        }

        if ($relative === []) {
            return [$above, $below];
        }

        if (!is_finite($above) || !is_finite($below)) {
            $above = max(0.0, $above);
            $below = max(0.0, $below);
        }

        // A box taller than the line grows it, from whichever edge it is
        // aligned to, before anything is seated against those edges.
        foreach ($relative as $i => $isSpace) {
            $run = $parts[$i][0];

            [$a, $d] = $run->box !== null && !$isSpace
                ? [$run->box->baselineOffset(), $run->box->outerHeight() - $run->box->baselineOffset()]
                : array_slice($this->metricsFor($run), 1);

            $overflow = ($a + $d) - ($above + $below);

            if ($overflow > 0.0) {
                if ($run->verticalAlign === 'top') {
                    $below += $overflow;
                } else {
                    $above += $overflow;
                }
            }
        }

        foreach ($relative as $i => $isSpace) {
            $run = $parts[$i][0];

            [$a, $d] = $run->box !== null && !$isSpace
                ? [$run->box->baselineOffset(), $run->box->outerHeight() - $run->box->baselineOffset()]
                : array_slice($this->metricsFor($run), 1);

            $parts[$i][6] = $run->verticalAlign === 'top' ? $above - $a : $d - $below;
        }

        return [$above, $below];
    }

    private function buildLine(
        array $parts,
        float $availableWidth,
        string $textAlign,
        bool $isLast,
        bool $baseRtl = false,
        ?InlineRun $strut = null,
    ): LineBox {
        // Trailing spaces hang: they don't count toward the line's width. The
        // right edge of any inline box the space closed does not hang with it:
        // the element still ends where it ends, so the edge moves onto the
        // piece that is now last.
        while ($parts !== [] && end($parts)[2]) {
            $hung = array_pop($parts);

            if ($parts !== [] && $hung[8] < count($hung[5])) {
                $last            = count($parts) - 1;
                $parts[$last][8] = min($parts[$last][8], $hung[8]);
            }
        }

        $line = new LineBox();

        if ($parts === []) {
            return $line;
        }

        /*
         * --- vertical: half-leading, per CSS inline layout ---
         *
         * Half-leading is negative whenever `line-height` is smaller than the
         * face's own ascent plus descent, and CSS lets a line box be shorter
         * than the text sitting on it: the glyphs simply overflow it. Seeding
         * these at zero rather than at the first contributor floored every line
         * at the font's own height, so a tight `line-height` produced pages
         * Chrome does not. What must not go negative is the line's *height*,
         * and that is floored below, where the invariant actually lives.
         */
        $above = -INF;
        $below = -INF;
        $seen  = [];

        // The strut sits on every line whether or not anything uses it, which
        // is what reserves a descender under a line holding only an image.
        if ($strut !== null) {
            [, $above, $below] = $this->metricsFor($strut);
        }
        foreach ($parts as [$run, , $isSpace, , , , $seat]) {
            // CSS 2.1 §10.8.1: `top` and `bottom` align against the line box,
            // so they cannot contribute to the extent that defines it. They
            // are seated below, once it is known. The other half of defect AF.
            if ($run->verticalAlign === 'top' || $run->verticalAlign === 'bottom') {
                continue;
            }

            // An atomic inline contributes its own box rather than a font's
            // metrics, and two of them are never interchangeable, so it is
            // measured before the per-style shortcut below.
            if ($run->box !== null && !$isSpace) {
                $baseline = $run->box->baselineOffset();
                $above    = max($above, $baseline + $seat);
                $below    = max($below, $run->box->outerHeight() - $baseline - $seat);

                continue;
            }

            // The shift is part of the key and not just of the contribution:
            // two runs in the same face at the same size reach different
            // heights when one of them is raised.
            $id = $run->fontFamily . ($run->bold ? 'b' : 'r') . ($run->italic ? 'i' : '')
                . $seat . $run->fontSize . ':' . $run->lineHeight;

            if (isset($seen[$id])) {
                continue;
            }

            $seen[$id] = true;
            [, $a, $d] = $this->metricsFor($run);
            $shift = $seat;

            if ($a + $shift > $above) {
                $above = $a + $shift;
            }

            if ($d - $shift > $below) {
                $below = $d - $shift;
            }
        }

        // A line nothing contributed to has no metrics to take, rather than
        // metrics of minus infinity.
        if (!is_finite($above) || !is_finite($below)) {
            $above = 0.0;
            $below = 0.0;
        }

        [$above, $below] = $this->seatLineRelative($parts, $above, $below);

        $line->baseline   = $above;
        $line->height     = max(0.0, $above + $below);
        $line->background = $strut?->boxes[0] ?? null;

        // --- horizontal ---
        //
        // A `clone` inline box pays both its edges on every line it is broken
        // over, so the piece that starts this line and the piece that ends it
        // carry more than the document alone says. This is the one place that
        // knows which two pieces those are, and the same two flags go onto the
        // items so the painter draws the edges the width was paid for.
        $contentWidth = 0.0;
        $first        = 0;
        $last         = count($parts) - 1;

        foreach ($parts as $index => [, , , $w, , $boxes, , $opens, $closes]) {
            $contentWidth += $w;

            if ($boxes !== []) {
                $contentWidth += self::edgeSum($boxes, $opens, true, $index === $first)
                    + self::edgeSum($boxes, $closes, false, $index === $last);
            }
        }

        $line->width = $contentWidth;

        $free          = $availableWidth - $contentWidth;
        $extraPerSpace = 0.0;
        $startX        = 0.0;

        if ($textAlign === 'justify' && !$isLast) {
            $spaces = 0;

            foreach ($parts as [, , $isSpace,]) {
                if ($isSpace) {
                    $spaces++;
                }
            }

            if ($spaces > 0 && $free > 0) {
                $extraPerSpace = $free / $spaces;
            }
        } else {
            $startX = match ($textAlign) {
                'right'  => max(0.0, $free),
                'center' => max(0.0, $free / 2),
                default  => 0.0,
            };
        }

        $x = $startX;

        foreach ($parts as $index => [$run, $text, $isSpace, $w, , $boxes, $seat, $opens, $closes]) {
            $advance    = $w + ($isSpace ? $extraPerSpace : 0.0);
            $startsLine = $index === $first;
            $endsLine   = $index === $last;

            // An inline box's own left edge goes down in front of the text it
            // opens, so `$x` on the item is where the glyphs start and the
            // painter walks back out to the border box from there.
            if ($boxes !== []) {
                $x += self::edgeSum($boxes, $opens, true, $startsLine);
            }

            // Spaces are emitted as glyphs, not merely as advances: without
            // them, text extraction and copy/paste run words together.
            $line->items[] = new InlineItem(
                $run,
                $text,
                $x,
                $w,
                $isSpace,
                $seat,
                boxes: $boxes,
                openFrom: $opens,
                closeFrom: $closes,
                startsLine: $startsLine,
                endsLine: $endsLine,
            );
            $x             += $advance;

            if ($boxes !== []) {
                $x += self::edgeSum($boxes, $closes, false, $endsLine);
            }
        }

        if ($extraPerSpace > 0.0) {
            $line->width = $availableWidth;
        }

        return $line;
    }

    /**
     * Widest single unbreakable token, the min-content contribution. Under
     * `nowrap` nothing is breakable, so the whole line is one token; under
     * `break-all` every character is, so the contribution collapses to one.
     */
    public function minContentWidth(array $runs): float
    {
        $max     = 0.0;
        $segment = 0.0;
        $word    = 0.0;

        $tokens = $this->tokenize($runs);
        $edged  = self::decorated($runs);

        if ($edged) {
            $tokens = $this->linkInlineBoxes($tokens);
        }

        foreach ($tokens as $token) {
            [$run, $text, $isSpace, $isBreak] = $token;

            if ($isBreak) {
                $segment = 0.0;
                $word    = 0.0;
                continue;
            }

            // An inline element's padding and border cannot be broken away
            // from the piece they sit against, so they are part of that
            // piece's contribution. A `clone` element pays both its edges on
            // every line, and at min-content every wrappable piece is a line
            // of its own, so each one pays them; a piece that cannot wrap is
            // still on the same line as its neighbors and does not.
            $edges = !$edged || $run->boxes === [] ? 0.0 : (
                self::edgeSum($run->boxes, $token[7] ?? 0, true, $run->wraps())
                + self::edgeSum($run->boxes, $token[8] ?? 0, false, $run->wraps())
            );

            // An atomic inline cannot be broken, so its whole width is a
            // min-content contribution of its own.
            if ($run->box !== null && !$isSpace) {
                $segment = 0.0;
                $word    = 0.0;
                $max     = max($max, $run->box->outerWidth() + $edges);

                continue;
            }

            if (!$run->wraps()) {
                $segment += $this->advance($run, $text, (bool) $isSpace) + $edges;
                $max     = max($max, $segment);

                continue;
            }

            $segment = 0.0;

            if ($isSpace || $text === '') {
                $word = 0.0;

                continue;
            }

            $piece = $edges + ($run->breaksWords()
                ? $this->advance($run, mb_substr($text, 0, 1))
                : $this->advance($run, $text));

            // Pieces of one word spread over several runs contribute
            // together: measured apart, the narrowest the box can be comes
            // out as the widest *piece* rather than the widest word, and a
            // shrink-to-fit box then overflows by whatever it left out.
            $word = $this->continues($token) ? $word + $piece : $piece;
            $max  = max($max, $word);
        }

        return $max;
    }

    /**
     * Everything on one line, the max-content contribution.
     *
     * A forced break ends the run being accumulated, so what the box wants to
     * be is the widest of its segments and not their sum. Chrome makes a float
     * holding `alpha be<br>cd` 34.535 wide, its first line, where summing gave
     * 44.028, and the factor was the line count exactly. The break itself is
     * skipped rather than measured: `<span>a<br>b</span>` is one span, so the
     * break token carries the span with no opening or closing index of its
     * own, and adding its edges charged the box a whole extra left and right
     * edge (`ZT` `brf`, 56.028 against Chrome's 37.535).
     *
     * A newline under `pre`, `pre-wrap` and `pre-line` is the same rule and
     * measures the same 34.535, and preserved white space before one belongs
     * to the segment it ends: `br9`, two spaces before the newline, is 39.539.
     */
    public function maxContentWidth(array $runs): float
    {
        $max   = 0.0;
        $total = 0.0;

        $tokens = $this->tokenize($runs);
        $edged  = self::decorated($runs);

        if ($edged) {
            $tokens = $this->linkInlineBoxes($tokens);
        }

        foreach ($tokens as $token) {
            [$run, $text, $isSpace, $isBreak] = $token;

            if ($isBreak) {
                $max   = max($max, $total);
                $total = 0.0;

                continue;
            }

            $total += $this->advance($run, $text, (bool) $isSpace);

            if ($edged && $run->boxes !== []) {
                $total += self::edgeSum($run->boxes, $token[7] ?? 0, true)
                    + self::edgeSum($run->boxes, $token[8] ?? 0, false);
            }
        }

        return max($max, $total);
    }
}
