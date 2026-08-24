<?php

declare(strict_types=1);

namespace FlexPDF\Engine\Support;

use FlexPDF\Engine\Exceptions\LayoutTimeoutException;

/**
 * A wall-clock budget for one render.
 *
 * set_time_limit() is not a substitute: it does not reliably interrupt tight
 * PHP loops and is unusable inside queue workers. This is checked explicitly
 * at the loop guards and recursion entry points that the page and depth
 * ceilings already sit on, which is where non-convergence actually shows up.
 */
final readonly class Deadline
{
    private float $startedAt;

    private bool $enabled;

    public function __construct(private float $seconds)
    {
        $this->startedAt = hrtime(true) / 1e9;
        $this->enabled   = $seconds > 0.0;
    }

    public function elapsed(): float
    {
        return hrtime(true) / 1e9 - $this->startedAt;
    }

    /**
     * What is left of the budget, floored at zero.
     *
     * A remote fetch has to be bounded by the render's own wall clock rather
     * than by a timeout of its own, or a slow host extends a render past
     * `timeout_seconds` while the deadline sits there unable to interrupt a
     * blocking read.
     */
    public function remaining(): float
    {
        return max(0.0, $this->seconds - $this->elapsed());
    }

    public function exceeded(): bool
    {
        return $this->enabled && $this->elapsed() > $this->seconds;
    }

    public function check(string $stage): void
    {
        if (!$this->exceeded()) {
            return;
        }

        throw LayoutTimeoutException::after($this->seconds, $this->elapsed(), $stage);
    }
}
