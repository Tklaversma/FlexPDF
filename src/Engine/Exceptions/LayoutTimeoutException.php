<?php

declare(strict_types=1);

namespace FlexPDF\Engine\Exceptions;

use RuntimeException;

final class LayoutTimeoutException extends RuntimeException
{
    public static function after(float $budget, float $elapsed, string $stage): self
    {
        return new self(
            sprintf(
                'Rendering exceeded the %.1fs budget after %.1fs during %s. '
                . 'Raise flexpdf.limits.timeout_seconds, or treat the document as hostile.',
                $budget,
                $elapsed,
                $stage,
            ),
        );
    }
}
