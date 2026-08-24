<?php

declare(strict_types=1);

namespace FlexPDF\Support;

use InvalidArgumentException;

/** Named page sizes in PDF points, where 72pt is one inch. */
final class PageSize
{
    private const array SIZES = [
        'a3'      => [841.89, 1190.55],
        'a4'      => [595.28, 841.89],
        'a5'      => [419.53, 595.28],
        'letter'  => [612.0, 792.0],
        'legal'   => [612.0, 1008.0],
        'tabloid' => [792.0, 1224.0],
    ];

    /**
     * The array form arrives from user config, so it is validated rather than
     * trusted to match the shape it is documented with.
     *
     * @param string|array<array-key, mixed> $size
     *
     * @return array{0:float,1:float} width, height
     */
    public static function resolve(string|array $size, string $orientation = 'portrait'): array
    {
        if (is_array($size)) {
            if (!isset($size[0], $size[1]) || !is_numeric($size[0]) || !is_numeric($size[1])) {
                throw new InvalidArgumentException('A custom page size must be [width, height] in points.');
            }

            [$width, $height] = [(float) $size[0], (float) $size[1]];
        } else {
            $key = strtolower(trim($size));

            if (!isset(self::SIZES[$key])) {
                throw new InvalidArgumentException(
                    sprintf(
                        'Unknown page size [%s]. Known sizes: %s. Pass [width, height] in points for anything else.',
                        $size,
                        implode(', ', array_keys(self::SIZES)),
                    ),
                );
            }

            [$width, $height] = self::SIZES[$key];
        }

        if (strtolower($orientation) === 'landscape') {
            return [$height, $width];
        }

        return [$width, $height];
    }
}
