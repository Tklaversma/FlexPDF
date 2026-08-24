<?php

declare(strict_types=1);

namespace FlexPDF\Engine;

/**
 * A line box: a horizontal strip with a single shared baseline.
 * $baseline is the distance from the top of the line box down to that baseline.
 */
final class LineBox
{
    /** @var InlineItem[] */
    public array $items    = [];
    public float $width    = 0.0;
    public float $height   = 0.0;
    public float $baseline = 0.0;

    /**
     * The background of the fictional tag wrapping this line's content, in the
     * shape InlineRun::$background carries, or null where nothing asks for one.
     *
     * Only `::first-line` sets it, through the strut: CSS 2.1 §5.12.1 styles a
     * tag that wraps the line rather than any element on it, so it belongs to
     * the line and not to an item. The extent is read off the items at paint
     * time, because `text-align` moves them after the line is built.
     */
    public ?array $background = null;
}
