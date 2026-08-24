<?php

declare(strict_types=1);

namespace FlexPDF\Engine\Exceptions;

use RuntimeException;

final class GradientLimitExceededException extends RuntimeException
{
    public static function at(int $ceiling): self
    {
        return new self(
            sprintf(
                'The document asks for more than the %d gradient colour stops one render may keep, '
                . 'so it would run out of memory while painting. Raise '
                . 'flexpdf.limits.max_gradient_stops, or treat the document as hostile.',
                $ceiling,
            ),
        );
    }
}
