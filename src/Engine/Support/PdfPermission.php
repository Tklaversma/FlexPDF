<?php

declare(strict_types=1);

namespace FlexPDF\Engine\Support;

/**
 * What a reader may let someone do with an encrypted document.
 *
 * These are the bits of the `/P` entry in ISO 32000-2 table 22, named. A
 * reader enforces them for whoever opened the file with the user password;
 * whoever opens it with the owner password is not bound by any of them, which
 * is why {@see Encryption} invents an owner password when the caller gives
 * none rather than reusing the user's.
 *
 * Extracting text for accessibility is not here on purpose. PDF 2.0 says that
 * bit is always set, so it is not a choice a caller has.
 */
enum PdfPermission: string
{
    case Print            = 'print';
    case Copy             = 'copy';
    case Modify           = 'modify';
    case Annotate         = 'annotate';
    case FillForms        = 'fill_forms';
    case Assemble         = 'assemble';
    case PrintHighQuality = 'print_high_quality';

    /** The bit this permission occupies in `/P`, counting from 1 as the spec does. */
    public function bit(): int
    {
        return match ($this) {
            self::Print            => 1 << 2,
            self::Modify           => 1 << 3,
            self::Copy             => 1 << 4,
            self::Annotate         => 1 << 5,
            self::FillForms        => 1 << 8,
            self::Assemble         => 1 << 10,
            self::PrintHighQuality => 1 << 11,
        };
    }
}
