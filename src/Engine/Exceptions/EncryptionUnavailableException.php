<?php

declare(strict_types=1);

namespace FlexPDF\Engine\Exceptions;

use RuntimeException;

/**
 * The build cannot encrypt, so refuse rather than write a document that only
 * looks encrypted.
 *
 * Every failure openssl can have here is silent: `openssl_encrypt()` returns
 * `false`, which becomes an empty string, and the result is a file with an
 * `/Encrypt` dictionary over content nobody can read. A caller asked for a
 * password and has to hear that it did not happen.
 */
final class EncryptionUnavailableException extends RuntimeException
{
    public static function missing(string $what): self
    {
        return new self(
            sprintf(
                'Encrypting a PDF needs %s, which this PHP build does not offer. '
                . 'AES-256 encryption is unavailable, so the document was not written.',
                $what,
            ),
        );
    }
}
