<?php

declare(strict_types=1);

namespace FlexPDF\Engine\Support;

use InvalidArgumentException;

/**
 * Whether a document is encrypted, with which passwords, and what a reader may
 * let the recipient do with it.
 *
 * One handler is implemented, the standard one at `/V 5 /R 6`: AES-256 in CBC
 * mode with the key derived by ISO 32000-2's algorithm 2.B, which is SHA-2 and
 * AES throughout. The older revisions are deliberately absent. Revision 4
 * encrypts page content with AES too, but it derives the file key with MD5 and
 * computes `/O` and `/U` with RC4, and an attacker goes at the derivation
 * rather than at the cipher. There is nothing weak to reach here.
 *
 * The cost is the header: an encrypted document is written as `%PDF-2.0`,
 * where an unencrypted one stays `%PDF-1.4`. Revision 6 reads in Acrobat 9 and
 * later, macOS Preview, pdf.js, pdfium and Ghostscript.
 *
 * **An encrypted document is not byte-reproducible and cannot be.** The file
 * key, both password salts and every string and stream's initialization vector
 * are random per render, which is what stops two documents sharing key
 * material. Everything else in this writer is deterministic on purpose; this
 * is the one place it must not be.
 */
final readonly class Encryption
{
    /**
     * The bits `/P` carries whatever the caller asks for.
     *
     * Bits 7 and 8 and everything from 13 up are reserved and set; bits 1 and
     * 2 are reserved and clear. Bit 10, extracting text for accessibility, is
     * always granted because PDF 2.0 requires it, so it is not in
     * {@see PdfPermission}.
     */
    private const int RESERVED_BITS = 0xFFFFF0C0 | (1 << 9);

    /**
     * What the recipient may do. Null is every permission, which is the
     * default because the day-one ask is a password on the file rather than a
     * restriction on the reader; an empty array is no permission at all.
     *
     * @var list<PdfPermission>
     */
    public array $allow;

    /** @param ?list<PdfPermission> $allow */
    public function __construct(
        public string $userPassword = '',
        public string $ownerPassword = '',
        ?array $allow = null,
    ) {
        $this->allow = $allow === null ? PdfPermission::cases() : array_values($allow);
    }

    /**
     * @param array<string, mixed> $config
     *
     * @throws InvalidArgumentException when `allow` names something that is not a permission
     */
    public static function fromArray(array $config): ?self
    {
        if (!(bool) ($config['enabled'] ?? false)) {
            return null;
        }

        $allow = array_key_exists('allow', $config)
            ? self::permissionsFrom((array) $config['allow'])
            : PdfPermission::cases();

        return new self(
            userPassword : (string) ($config['user_password'] ?? ''),
            ownerPassword: (string) ($config['owner_password'] ?? ''),
            allow        : $allow,
        );
    }

    /**
     * @param array<int|string, mixed> $names
     *
     * @throws InvalidArgumentException
     * @return list<PdfPermission>
     *
     */
    public static function permissionsFrom(array $names): array
    {
        $allow = [];

        foreach ($names as $name) {
            if ($name instanceof PdfPermission) {
                $allow[] = $name;

                continue;
            }

            $permission = PdfPermission::tryFrom(strtolower(trim((string) $name)));

            if ($permission === null) {
                throw new InvalidArgumentException(
                    sprintf(
                        'Unknown PDF permission [%s]. Known ones are %s.',
                        (string) $name,
                        implode(', ', array_column(PdfPermission::cases(), 'value')),
                    ),
                );
            }

            $allow[] = $permission;
        }

        return $allow;
    }

    /** `/P`, as the signed 32-bit integer the file carries. */
    public function permissionBits(): int
    {
        $bits = self::RESERVED_BITS;

        foreach ($this->allow as $permission) {
            $bits |= $permission->bit();
        }

        return $bits - 0x100000000;
    }

    /**
     * Fresh key material for one document.
     *
     * A handler is derived per call rather than kept, so rendering the same
     * builder twice never gives two documents the same file key.
     */
    public function handler(): SecurityHandler
    {
        return new SecurityHandler($this);
    }
}
