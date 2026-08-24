<?php

declare(strict_types=1);

namespace FlexPDF\Engine\Support;

/**
 * Security controls, not tuning knobs.
 *
 * Layout is a fixpoint computation and some inputs never converge. Without
 * these ceilings a few KB of hostile HTML consumes unbounded CPU, so any
 * caller rendering untrusted HTML needs them reachable. The defaults are the
 * values the engine shipped with as hardcoded constants.
 */
final readonly class Limits
{
    public function __construct(
        public int $maxPages = 2000,
        public int $maxDepth = 64,
        public float $maxLength = 200000.0,
        public float $maxFontSize = 2000.0,
        public float $timeoutSeconds = 30.0,

        /**
         * How many gradient color stops one document may keep.
         *
         * The painter already caps one gradient's stops and one box's tiles
         * on one page. Nothing caps those two multiplied by the page count,
         * and a tiled repeating gradient over a long document asks for tens
         * of millions of stops: the document in
         * `docs/harness/baselines/DU-fl-42-30.html` lays out and paginates
         * to 1,276 pages in 18 MB and then runs out of memory painting them.
         *
         * 500,000 stops is about 140 MB of them, measured. It is roughly
         * 125,000 ordinary gradients or 488 fully repeating ones, which no
         * real document comes near.
         */
        public int $maxGradientStops = 500000,
        public int $maxImageBytes = 200_000_000,
    ) {}

    /** @param array<string, mixed> $config */
    public static function fromArray(array $config): self
    {
        $defaults = new self();

        return new self(
            maxPages        : (int) ($config['max_pages'] ?? $defaults->maxPages),
            maxDepth        : (int) ($config['max_depth'] ?? $defaults->maxDepth),
            maxLength       : (float) ($config['max_length'] ?? $defaults->maxLength),
            maxFontSize     : (float) ($config['max_font_size'] ?? $defaults->maxFontSize),
            timeoutSeconds  : (float) ($config['timeout_seconds'] ?? $defaults->timeoutSeconds),
            maxGradientStops: (int) ($config['max_gradient_stops'] ?? $defaults->maxGradientStops),
            maxImageBytes   : (int) ($config['max_image_bytes'] ?? $defaults->maxImageBytes),
        );
    }

    public function deadline(): Deadline
    {
        return new Deadline($this->timeoutSeconds);
    }
}
