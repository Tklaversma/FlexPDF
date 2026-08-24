<?php

declare(strict_types=1);

namespace FlexPDF\Engine\Support;

use FlexPDF\Engine\Exceptions\EncryptionUnavailableException;

/**
 * The standard security handler at `/V 5 /R 6`, for one document.
 *
 * ISO 32000-2's algorithms 2.A, 2.B, 8, 9 and 10. A random file key encrypts
 * every string and every stream with AES-256 in CBC mode; the two passwords
 * each encrypt a copy of that key, so either one opens the file and neither is
 * stored. Revision 6 uses the file key directly, which is why there is no
 * per-object derivation here: that is revision 4's MD5 step, and revision 4 is
 * not implemented.
 *
 * One handler belongs to one render. {@see Encryption::handler()} makes a
 * fresh one every time, because reusing key material across documents is the
 * failure this class would otherwise invite.
 */
final class SecurityHandler
{
    /** ISO 32000-2 7.6.4.3.4: a password is prepared and then cut to this. */
    private const int MAX_PASSWORD_BYTES = 127;

    private readonly string $fileKey;

    private readonly string $fileId;

    private readonly string $dictionary;

    /** The three ciphers the algorithms below need, checked before any of them runs. */
    private const array CIPHERS = ['aes-256-cbc', 'aes-128-cbc', 'aes-256-ecb'];

    public function __construct(Encryption $encryption)
    {
        self::demandOpenssl();

        $this->fileKey = random_bytes(32);
        $this->fileId  = random_bytes(16);

        $user = self::preparePassword($encryption->userPassword);

        /*
         * A caller who names no owner password gets one nobody holds. Reusing
         * the user's would mean everyone who can open the document also
         * authenticates as its owner, and a reader ignores every permission
         * for an owner: "print only" would be true of nothing at all.
         */
        $owner = $encryption->ownerPassword === ''
            ? random_bytes(32)
            : self::preparePassword($encryption->ownerPassword);

        // Algorithm 8: the user password's copy of the file key.
        $userValidationSalt = random_bytes(8);
        $userKeySalt        = random_bytes(8);
        $u                  = self::hash($user, $userValidationSalt, '') . $userValidationSalt . $userKeySalt;
        $ue                 = self::wrapKey(self::hash($user, $userKeySalt, ''), $this->fileKey);

        // Algorithm 9: the owner password's copy, which is bound to /U.
        $ownerValidationSalt = random_bytes(8);
        $ownerKeySalt        = random_bytes(8);
        $o                   = self::hash($owner, $ownerValidationSalt, $u) . $ownerValidationSalt . $ownerKeySalt;
        $oe                  = self::wrapKey(self::hash($owner, $ownerKeySalt, $u), $this->fileKey);

        $permissions = $encryption->permissionBits();

        $this->dictionary = sprintf(
            '<< /Filter /Standard /V 5 /R 6 /Length 256 '
            . '/CF << /StdCF << /CFM /AESV3 /AuthEvent /DocOpen /Length 32 >> >> '
            . '/StmF /StdCF /StrF /StdCF '
            . '/O <%s> /U <%s> /OE <%s> /UE <%s> /Perms <%s> '
            . '/P %d /EncryptMetadata true >>',
            bin2hex($o),
            bin2hex($u),
            bin2hex($oe),
            bin2hex($ue),
            bin2hex(self::permsBlock($permissions, $this->fileKey)),
            $permissions,
        );
    }

    /** The `/Encrypt` dictionary. It is the one object that is not encrypted. */
    public function dictionary(): string
    {
        return $this->dictionary;
    }

    /** Both halves of `/ID`, as hex. */
    public function fileId(): string
    {
        return bin2hex($this->fileId);
    }

    /**
     * One object body with its strings and its stream encrypted.
     *
     * The bodies this writer produces are a dictionary, optionally followed by
     * a stream, so the two halves are split on the stream keyword and handled
     * apart: the dictionary is text and can be scanned for strings, and the
     * stream is arbitrary bytes and must not be. `/Length` is rewritten
     * afterwards, because AES adds an initialization vector and padding.
     */
    public function sealObject(string $body): string
    {
        $marker = strpos($body, "\nstream\n");

        if ($marker === false) {
            return $this->sealStrings($body);
        }

        $from   = $marker + strlen("\nstream\n");
        $sealed = $this->encrypt(substr($body, $from, strlen($body) - $from - strlen("\nendstream")));

        $dictionary = preg_replace(
            '#/Length\s+\d+#',
            '/Length ' . strlen($sealed),
            $this->sealStrings(substr($body, 0, $marker)),
            1,
        ) ?? '';

        return $dictionary . "\nstream\n" . $sealed . "\nendstream";
    }

    /** AES-256-CBC with a random initialization vector in front of the ciphertext. */
    public function encrypt(string $data): string
    {
        $iv = random_bytes(16);

        return $iv . (string) openssl_encrypt($data, 'aes-256-cbc', $this->fileKey, OPENSSL_RAW_DATA, $iv);
    }

    /**
     * Every string in a dictionary, encrypted and re-written as hex.
     *
     * A literal `(...)` and a hex `<...>` are both strings and both have to be
     * encrypted; `<<` and `>>` are the dictionary's own delimiters and are
     * not. Ciphertext is arbitrary bytes, so it always goes back as hex
     * whichever spelling it arrived in.
     */
    private function sealStrings(string $dictionary): string
    {
        $out    = '';
        $length = strlen($dictionary);

        for ($i = 0; $i < $length; $i++) {
            $char = $dictionary[$i];

            if (($char === '<' || $char === '>') && ($dictionary[$i + 1] ?? '') === $char) {
                $out .= $char . $char;
                $i++;

                continue;
            }

            if ($char === '<') {
                $end = strpos($dictionary, '>', $i);

                if ($end === false) {
                    $out .= substr($dictionary, $i);

                    break;
                }

                $hex = preg_replace('/\s+/', '', substr($dictionary, $i + 1, $end - $i - 1)) ?? '';
                $out .= '<' . bin2hex(
                        $this->encrypt((string) hex2bin(strlen($hex) % 2 === 0 ? $hex : $hex . '0')),
                    ) . '>';
                $i   = $end;

                continue;
            }

            if ($char === '(') {
                [$raw, $end] = self::readLiteral($dictionary, $i);
                $out .= '<' . bin2hex($this->encrypt($raw)) . '>';
                $i   = $end;

                continue;
            }

            $out .= $char;
        }

        return $out;
    }

    /**
     * The bytes of the literal string starting at `$start`, and where it ends.
     *
     * Parentheses may nest unescaped, so the depth is counted rather than the
     * first `)` taken.
     *
     * @return array{0:string,1:int}
     */
    private static function readLiteral(string $text, int $start): array
    {
        $raw    = '';
        $depth  = 1;
        $length = strlen($text);

        for ($i = $start + 1; $i < $length; $i++) {
            $char = $text[$i];

            if ($char === '\\') {
                $raw .= self::unescape($text[$i + 1] ?? '');
                $i++;

                continue;
            }

            if ($char === '(') {
                $depth++;
            }

            if ($char === ')' && --$depth === 0) {
                return [$raw, $i];
            }

            $raw .= $char;
        }

        return [$raw, $length - 1];
    }

    private static function unescape(string $char): string
    {
        return match ($char) {
            'n'     => "\n",
            'r'     => "\r",
            't'     => "\t",
            'b'     => "\x08",
            'f'     => "\x0C",
            default => $char,
        };
    }

    /**
     * Algorithm 2.B, revision 6's key derivation.
     *
     * The SHA-256 of the password is stirred through at least 64 rounds of
     * AES-128-CBC, each round choosing its digest from the ciphertext, and the
     * rounds keep going past 64 until the last byte of the ciphertext falls
     * below the round count. That data-dependent tail is the point: the work
     * cannot be predicted from the password's length.
     */
    private static function hash(string $password, string $salt, string $userKey): string
    {
        $k     = hash('sha256', $password . $salt . $userKey, true);
        $round = 0;

        do {
            $block = str_repeat($password . $k . $userKey, 64);

            $e = (string) openssl_encrypt(
                $block,
                'aes-128-cbc',
                substr($k, 0, 16),
                OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING,
                substr($k, 16, 16),
            );

            $sum = 0;

            for ($i = 0; $i < 16; $i++) {
                $sum += ord($e[$i]);
            }

            $k = hash(
                match ($sum % 3) {
                    0       => 'sha256',
                    1       => 'sha384',
                    default => 'sha512',
                },
                $e,
                true,
            );

            $round++;
        } while ($round < 64 || ord($e[strlen($e) - 1]) > $round - 32);

        return substr($k, 0, 32);
    }

    /** The file key under one password's intermediate key: AES-256-CBC, no padding, zero IV. */
    private static function wrapKey(string $intermediate, string $fileKey): string
    {
        return (string) openssl_encrypt(
            $fileKey,
            'aes-256-cbc',
            $intermediate,
            OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING,
            str_repeat("\0", 16),
        );
    }

    /**
     * Algorithm 10's `/Perms`: the permissions again, under the file key, so a
     * reader can tell that whoever wrote `/P` also held the key.
     */
    private static function permsBlock(int $permissions, string $fileKey): string
    {
        $block = pack('V', $permissions & 0xFFFFFFFF) . "\xFF\xFF\xFF\xFF" . 'T' . 'adb' . random_bytes(4);

        return (string) openssl_encrypt($block, 'aes-256-ecb', $fileKey, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING);
    }

    /**
     * Refuse before writing anything, rather than after.
     *
     * `openssl_encrypt()` reports a missing cipher by returning `false`, which
     * would reach the file as an empty string: an `/Encrypt` dictionary over
     * content nobody can read, and no error anywhere. A caller who asked for a
     * password has to hear that it did not happen.
     *
     * @throws EncryptionUnavailableException
     */
    private static function demandOpenssl(): void
    {
        if (!extension_loaded('openssl')) {
            throw EncryptionUnavailableException::missing('the openssl extension');
        }

        $available = openssl_get_cipher_methods();

        foreach (self::CIPHERS as $cipher) {
            if (!in_array($cipher, $available, true)) {
                throw EncryptionUnavailableException::missing('the ' . $cipher . ' cipher');
            }
        }
    }

    /**
     * SASLprep is what the spec asks for and what is done here is UTF-8 cut to
     * 127 bytes on a character boundary. The two agree for every ASCII
     * password, and they can disagree for one carrying a combining mark or a
     * non-breaking space, which would then open in this writer's own reader
     * and not in another's.
     */
    private static function preparePassword(string $password): string
    {
        if (strlen($password) <= self::MAX_PASSWORD_BYTES) {
            return $password;
        }

        $cut = substr($password, 0, self::MAX_PASSWORD_BYTES);

        while ($cut !== '' && !mb_check_encoding($cut, 'UTF-8')) {
            $cut = substr($cut, 0, -1);
        }

        return $cut;
    }
}
