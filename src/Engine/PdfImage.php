<?php

declare(strict_types=1);

namespace FlexPDF\Engine;

/**
 * An image prepared for embedding.
 *
 * JPEG and simple PNG are passed through untouched: the PDF imaging model
 * speaks DCTDecode and FlateDecode-with-PNG-predictors natively, so
 * re-encoding would only lose quality. Anything else (palette with alpha,
 * interlaced, GIF, WebP) is normalized through GD.
 */
final readonly class PdfImage
{
    private function __construct(
        public int $width,
        public int $height,
        public string $data,
        public string $filter, // DCTDecode | FlateDecode | ''
        public string $colorSpace, // DeviceRGB | DeviceGray | Indexed...
        public int $bitsPerComponent,
        public string $decodeParms = '',
        public ?string $softMask = null,
        public int $softMaskLen = 0,
        public bool $interpolate = false,
    ) {}

    /**
     * Per-sample color with per-sample alpha, for what PDF has no shading
     * for: a conic gradient, and any gradient whose stops disagree on their
     * alpha, since a shading carries one constant alpha and no more.
     *
     * @param string $rgb   three bytes per sample, row major
     * @param string $alpha one byte per sample, or empty for fully opaque
     */
    public static function samples(int $width, int $height, string $rgb, string $alpha = ''): self
    {
        $mask = $alpha === '' ? null : gzcompress($alpha, 9);

        return new self(
            $width,
            $height,
            gzcompress($rgb, 9),
            'FlateDecode',
            'DeviceRGB',
            8,
            '',
            $mask,
            $mask === null ? 0 : strlen($mask),
            true,
        );
    }

    /**
     * A flat color carrying a per-sample alpha, which is how a blurred
     * shadow reaches the page: PDF has no blur operator, so the coverage is
     * computed by the painter and travels as this image's soft mask.
     *
     * @param array{0:float,1:float,2:float} $rgb
     * @param string                         $alpha one byte of coverage per sample, row major
     */
    public static function flat(int $width, int $height, string $alpha, array $rgb): self
    {
        $pixel = chr((int) round(max(0.0, min(1.0, $rgb[0])) * 255))
            . chr((int) round(max(0.0, min(1.0, $rgb[1])) * 255))
            . chr((int) round(max(0.0, min(1.0, $rgb[2])) * 255));

        return self::samples($width, $height, str_repeat($pixel, $width * $height), $alpha);
    }

    /**
     * This picture's alpha channel as a gray picture of its own, or null where
     * it has none and is opaque everywhere.
     *
     * A `mask-image: url()` masks by the source's alpha, and PDF's soft mask
     * reads luminosity, so the alpha has to become the thing that is drawn.
     * It is already carried here as one byte per sample for the picture's own
     * soft mask entry, so this reads it back out rather than decoding
     * anything: the same bytes, named `DeviceGray` instead.
     */
    public function alphaChannel(): ?self
    {
        if ($this->softMask === null) {
            return null;
        }

        return new self(
            $this->width,
            $this->height,
            $this->softMask,
            'FlateDecode',
            'DeviceGray',
            8,
            '',
            null,
            0,
            true,
        );
    }

    public static function load(string $path): ?self
    {
        if (!is_file($path) || !is_readable($path)) {
            return null;
        }

        $raw = file_get_contents($path);

        return $raw === false ? null : self::parse($raw);
    }

    /** Decode image bytes that never came from a file, such as a `data:` URI. */
    public static function parse(string $raw): ?self
    {
        if ($raw === '') {
            return null;
        }

        if (str_starts_with($raw, "\xFF\xD8")) {
            return self::fromJpeg($raw) ?? self::viaGd($raw);
        }

        if (str_starts_with($raw, "\x89PNG\r\n\x1a\n")) {
            return self::fromPng($raw) ?? self::viaGd($raw);
        }

        return self::viaGd($raw);
    }

    // -----------------------------------------------------------------
    private static function fromJpeg(string $raw): ?self
    {
        $len = strlen($raw);
        $i   = 2;

        while ($i < $len - 1) {
            if ($raw[$i] !== "\xFF") {
                $i++;
                continue;
            }

            $marker = ord($raw[$i + 1]);
            $i      += 2;

            if ($marker === 0xD8 || $marker === 0x01 || ($marker >= 0xD0 && $marker <= 0xD7)) {
                continue;
            }

            if ($i + 1 >= $len) {
                break;
            }

            $segLen = (ord($raw[$i]) << 8) | ord($raw[$i + 1]);

            // SOF0..SOF15 except DHT(C4), JPG(C8), DAC(CC)
            if ($marker >= 0xC0 && $marker <= 0xCF
                && $marker !== 0xC4 && $marker !== 0xC8 && $marker !== 0xCC) {
                $h          = (ord($raw[$i + 3]) << 8) | ord($raw[$i + 4]);
                $w          = (ord($raw[$i + 5]) << 8) | ord($raw[$i + 6]);
                $components = ord($raw[$i + 7]);
                $cs         = match ($components) {
                    1       => 'DeviceGray',
                    4       => 'DeviceCMYK',
                    default => 'DeviceRGB',
                };

                return new self($w, $h, $raw, 'DCTDecode', $cs, ord($raw[$i + 2]));
            }

            $i += $segLen;
        }

        return null;
    }

    private static function fromPng(string $raw): ?self
    {
        $w           = unpack('N', substr($raw, 16, 4))[1];
        $h           = unpack('N', substr($raw, 20, 4))[1];
        $bits        = ord($raw[24]);
        $colorType   = ord($raw[25]);
        $compression = ord($raw[26]);
        $filter      = ord($raw[27]);
        $interlace   = ord($raw[28]);

        // Only the straightforward cases pass through; GD handles the rest.
        if ($compression !== 0 || $filter !== 0 || $interlace !== 0) {
            return null;
        }

        if (!in_array($colorType, [0, 2, 3], true)) {
            return null;   // alpha channels need a soft mask; use GD
        }

        $idat    = '';
        $palette = '';
        $offset  = 8;
        $len     = strlen($raw);

        while ($offset + 8 <= $len) {
            $chunkLen = unpack('N', substr($raw, $offset, 4))[1];
            $type     = substr($raw, $offset + 4, 4);
            $body     = substr($raw, $offset + 8, $chunkLen);

            if ($type === 'IDAT') {
                $idat .= $body;
            } elseif ($type === 'PLTE') {
                $palette = $body;
            } elseif ($type === 'tRNS') {
                return null;
            }// transparency: use GD
            elseif ($type === 'IEND') {
                break;
            }

            $offset += 12 + $chunkLen;
        }

        if ($idat === '') {
            return null;
        }

        $colors = match ($colorType) {
            0       => 1,
            2       => 3,
            3       => 1,
            default => 3
        };

        $colorSpace = match ($colorType) {
            0       => 'DeviceGray',
            3       => sprintf('[/Indexed /DeviceRGB %d <%s>]', intdiv(strlen($palette), 3) - 1, bin2hex($palette)),
            default => 'DeviceRGB',
        };

        // PDF understands PNG predictors directly, so the filter bytes in the
        // scanlines need no undoing.
        $parms = sprintf(
            '/DecodeParms << /Predictor 15 /Colors %d /BitsPerComponent %d /Columns %d >>',
            $colors,
            $bits,
            $w,
        );

        return new self($w, $h, $idat, 'FlateDecode', $colorSpace, $bits, $parms);
    }

    private static function viaGd(string $raw): ?self
    {
        if (!function_exists('imagecreatefromstring')) {
            return null;
        }

        $im = @imagecreatefromstring($raw);

        if ($im === false) {
            return null;
        }

        $w        = imagesx($im);
        $h        = imagesy($im);
        $hasAlpha = false;

        $rgb   = '';
        $alpha = '';

        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $c   = imagecolorat($im, $x, $y);
                $rgb .= chr(($c >> 16) & 0xFF) . chr(($c >> 8) & 0xFF) . chr($c & 0xFF);
                $a   = ($c >> 24) & 0x7F; // GD alpha: 0 opaque, 127 clear

                if ($a !== 0) {
                    $hasAlpha = true;
                }

                $alpha .= chr((int) round((127 - $a) * 255 / 127));
            }
        }

        imagedestroy($im);

        $mask    = null;
        $maskLen = 0;

        if ($hasAlpha) {
            $mask    = gzcompress($alpha, 9);
            $maskLen = strlen($mask);
        }

        return new self(
            $w, $h, gzcompress($rgb, 9), 'FlateDecode', 'DeviceRGB', 8, '',
            $mask, $maskLen,
        );
    }
}
