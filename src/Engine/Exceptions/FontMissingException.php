<?php

declare(strict_types=1);

namespace FlexPDF\Engine\Exceptions;

use RuntimeException;

/**
 * A document asked to be told when a font it named was not there, and it was
 * not there.
 *
 * This is never thrown by default. A `font-family` list is written so that
 * entries can miss, and the last entry is a generic keyword precisely because
 * the ones before it are expected to. Refusing on the first miss would refuse
 * stylesheets every browser draws. So a miss is recorded in {@see
 * \FlexPDF\Engine\FontReport} and this exists only for the caller who turned
 * strict mode on, in the same spirit as `->pdfa()`: say no rather than draw
 * something the caller did not ask for and will not find out about.
 */
final class FontMissingException extends RuntimeException
{
    public static function family(string $list): self
    {
        return new self(
            sprintf(
                'The font-family list [%s] resolved to no registered face at all, so the '
                . 'document would have been drawn in Helvetica, and strict font handling is on. '
                . 'Register one of these families, or add a generic keyword to the list.',
                $list,
            ),
        );
    }

    public static function codepoint(int $codepoint): self
    {
        return new self(
            sprintf(
                'No registered face and no bundled fallback face can draw U+%04X (%s), '
                . 'and strict font handling is on. Register a face that covers it.',
                $codepoint,
                mb_chr($codepoint, 'UTF-8') ?: '?',
            ),
        );
    }
}
