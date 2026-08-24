<?php

declare(strict_types=1);

namespace FlexPDF\Engine\Exceptions;

use RuntimeException;

final class PageLimitExceededException extends RuntimeException
{
    /** @param string[] $notes what the fragmenter recorded while running out of room */
    public static function at(int $ceiling, array $notes = []): self
    {
        return new self(
            sprintf(
                'The document needs more than the %d-page ceiling, so content was cut off. '
                . 'Raise flexpdf.limits.max_pages, or treat the document as hostile.%s',
                $ceiling,
                $notes === [] ? '' : ' (' . implode('; ', array_unique($notes)) . ')',
            ),
        );
    }
}
