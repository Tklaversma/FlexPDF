<?php

declare(strict_types=1);

namespace FlexPDF\Engine;

/**
 * One `@container` prelude: which container it asks about, and what it asks.
 *
 * A container query resolves perfectly well on paper, because the container
 * has a known size by the time the query is asked. What makes it awkward here
 * is *when*: the cascade runs while the box tree is being built and a used
 * width only exists after layout, so {@see Html::layout()} lays the document
 * out once with every query false, hands the container sizes back and builds
 * again. This class is the part that does not care about any of that: given a
 * container's resolved size it says whether the condition holds.
 *
 * **A query with no container to ask is false**, which is the whole of defect
 * EK. The parser used to unwrap an `@container` block the way it unwraps
 * `@layer`, so every rule inside one was applied to every document, and a
 * template writing `@container (min-width: 600px) { .sidebar { display: none } }`
 * lost its sidebar on paper.
 *
 * **A condition this class cannot read is false too**, which is what CSS
 * Containment 3 section 5.1 says for an unknown container feature. That is the
 * opposite of the engine's `@supports` handling, and deliberately: an
 * `@supports` block the engine half understands is better applied than
 * dropped, where a container query it cannot evaluate has no container answer
 * to fall back on.
 */
final class ContainerQuery
{
    private const array FEATURES = [
        'width'       => 'width',
        'inline-size' => 'width',
        'height'      => 'height',
        'block-size'  => 'height',
    ];

    /**
     * @param ?string $name      the `container-name` this query asks for, or
     *                           null for the nearest container of any name
     * @param array   $condition the parsed condition tree
     */
    private function __construct(
        public readonly ?string $name,
        private readonly array $condition,
    ) {}

    public static function parse(string $prelude): self
    {
        $prelude = trim($prelude);
        $name    = null;

        if (preg_match('/^(-?[a-zA-Z_][\w-]*)\s*(.*)$/s', $prelude, $m) === 1) {
            $keyword = strtolower($m[1]);

            if ($keyword !== 'not') {
                $name    = $keyword;
                $prelude = trim($m[2]);
            }
        }

        return new self($name, self::condition($prelude));
    }

    /**
     * Whether this query reads the block axis, which only a
     * `container-type: size` container can answer.
     *
     * CSS Containment 3 section 5.1 picks the query container from the
     * ancestors whose type can answer *every* feature in the condition, so a
     * `min-height` query looks straight past an `inline-size` container to the
     * nearest sized one rather than reading false off the nearer box.
     */
    public function readsBlockAxis(): bool
    {
        return self::readsBlock($this->condition);
    }

    /**
     * @param ?array{type:string,inline:float,block:?float} $container the
     *        container this query resolved to, or null when nothing matched
     */
    public function holds(?array $container, StyleResolver $styles, float $rootFontSize): bool
    {
        if ($container === null) {
            return false;
        }

        return self::evaluate($this->condition, $container, $styles, $rootFontSize);
    }

    private static function readsBlock(array $node): bool
    {
        return match ($node[0]) {
            'not'         => self::readsBlock($node[1]),
            'all', 'any'  => array_any($node[1], static fn(array $child): bool => self::readsBlock($child)),
            'test'        => $node[1] === 'height',
            'orientation' => true,
            default       => false,
        };
    }

    private static function evaluate(
        array $node,
        array $container,
        StyleResolver $styles,
        float $rootFontSize,
    ): bool {
        return match ($node[0]) {
            'not'  => !self::evaluate($node[1], $container, $styles, $rootFontSize),
            'all'  => array_all(
                $node[1],
                static fn(array $c): bool => self::evaluate($c, $container, $styles, $rootFontSize),
            ),
            'any'  => array_any(
                $node[1],
                static fn(array $c): bool => self::evaluate($c, $container, $styles, $rootFontSize),
            ),
            'test' => self::compare($node, $container, $styles, $rootFontSize),
            'orientation' => self::orientation($node[1], $container),
            default       => false,
        };
    }

    private static function orientation(string $wanted, array $container): bool
    {
        $block = $container['block'];

        if ($block === null) {
            return false;
        }

        return $wanted === 'portrait'
            ? $block >= $container['inline']
            : $container['inline'] > $block;
    }

    private static function compare(
        array $node,
        array $container,
        StyleResolver $styles,
        float $rootFontSize,
    ): bool {
        [, $feature, $operator, $value] = $node;

        $measured = $feature === 'width' ? $container['inline'] : $container['block'];

        if ($measured === null) {
            return false;
        }

        $wanted = $styles->length($value, $rootFontSize, $rootFontSize);

        if ($wanted === null) {
            return false;
        }

        // A container that is exactly as wide as the query asks satisfies a
        // `min-width`, so the boundary needs a tolerance rather than an exact
        // float comparison: a width that came out of a percentage lands a
        // fraction of a point either side of the round number it was meant to
        // be.
        $epsilon = 0.0005;

        return match ($operator) {
            '<'     => $measured < $wanted - $epsilon,
            '<='    => $measured <= $wanted + $epsilon,
            '>'     => $measured > $wanted + $epsilon,
            '>='    => $measured >= $wanted - $epsilon,
            default => abs($measured - $wanted) <= $epsilon,
        };
    }

    /**
     * How deep a condition may nest before it is called unreadable.
     *
     * The prelude is author input and this parser recurses once per level of
     * parentheses, so `((((...))))` written a hundred thousand deep would take
     * the stack down with it. Nothing real nests past a handful.
     */
    private const int MAX_DEPTH = 16;

    private static function condition(string $s, int $depth = 0): array
    {
        $s = trim($s);

        if ($s === '' || $depth > self::MAX_DEPTH) {
            return ['unknown'];
        }

        if (preg_match('/^not\b\s*(.*)$/is', $s, $m) === 1) {
            return ['not', self::condition($m[1], $depth + 1)];
        }

        foreach (['and' => 'all', 'or' => 'any'] as $keyword => $kind) {
            $operands = self::split($s, $keyword);

            if (count($operands) > 1) {
                // CSS forbids mixing `and` with `or` without parentheses, and
                // an operand still carrying the other keyword at its own top
                // level is exactly that, so the whole condition is unknown.
                $other = $keyword === 'and' ? 'or' : 'and';

                foreach ($operands as $operand) {
                    if (count(self::split($operand, $other)) > 1) {
                        return ['unknown'];
                    }
                }

                return [$kind, array_map(
                    static fn(string $o): array => self::condition($o, $depth + 1),
                    $operands,
                )];
            }
        }

        if (!str_starts_with($s, '(') || self::closing($s, 0) !== strlen($s) - 1) {
            // A functional query such as `style(--x: y)` or `scroll-state(...)`,
            // which this engine cannot answer.
            return ['unknown'];
        }

        $inner = trim(substr($s, 1, -1));

        if (str_starts_with($inner, '(') || preg_match('/^not\b/i', $inner) === 1
            || count(self::split($inner, 'and')) > 1 || count(self::split($inner, 'or')) > 1) {
            return self::condition($inner, $depth + 1);
        }

        return self::test($inner);
    }

    private static function test(string $s): array
    {
        $s = trim($s);

        if (preg_match('/^([\w-]+)\s*:\s*(.+)$/s', $s, $m) === 1) {
            $feature = strtolower($m[1]);
            $value   = trim($m[2]);

            if ($feature === 'orientation') {
                $wanted = strtolower($value);

                return in_array($wanted, ['portrait', 'landscape'], true)
                    ? ['orientation', $wanted]
                    : ['unknown'];
            }

            $operator = '=';

            if (str_starts_with($feature, 'min-')) {
                $operator = '>=';
                $feature  = substr($feature, 4);
            } elseif (str_starts_with($feature, 'max-')) {
                $operator = '<=';
                $feature  = substr($feature, 4);
            }

            return isset(self::FEATURES[$feature])
                ? ['test', self::FEATURES[$feature], $operator, $value]
                : ['unknown'];
        }

        // `(400px < width < 800px)`, the two-sided range form.
        if (preg_match('/^(.+?)\s*(<=?|>=?)\s*([\w-]+)\s*(<=?|>=?)\s*(.+)$/s', $s, $m) === 1) {
            $feature = strtolower($m[3]);

            if (!isset(self::FEATURES[$feature])) {
                return ['unknown'];
            }

            return ['all', [
                ['test', self::FEATURES[$feature], self::flip($m[2]), trim($m[1])],
                ['test', self::FEATURES[$feature], $m[4], trim($m[5])],
            ]];
        }

        // `(width >= 400px)` and `(400px <= width)`.
        if (preg_match('/^(.+?)\s*(<=?|>=?|=)\s*(.+)$/s', $s, $m) === 1) {
            $left  = strtolower(trim($m[1]));
            $right = strtolower(trim($m[3]));

            if (isset(self::FEATURES[$left])) {
                return ['test', self::FEATURES[$left], $m[2], trim($m[3])];
            }

            if (isset(self::FEATURES[$right])) {
                return ['test', self::FEATURES[$right], self::flip($m[2]), trim($m[1])];
            }

            return ['unknown'];
        }

        // A bare feature name is true when the container has that axis at a
        // size other than zero.
        $feature = strtolower($s);

        return isset(self::FEATURES[$feature])
            ? ['not', ['test', self::FEATURES[$feature], '=', '0']]
            : ['unknown'];
    }

    /** The same comparison read from the other side. */
    private static function flip(string $operator): string
    {
        return match ($operator) {
            '<'     => '>',
            '<='    => '>=',
            '>'     => '<',
            '>='    => '<=',
            default => '=',
        };
    }

    /**
     * Split on a keyword that appears outside every pair of parentheses.
     *
     * @return string[]
     */
    private static function split(string $s, string $keyword): array
    {
        $out   = [];
        $start = 0;
        $len   = strlen($s);
        $width = strlen($keyword);

        for ($i = 0; $i < $len; $i++) {
            if ($s[$i] === '(') {
                $close = self::closing($s, $i);

                if ($close === false) {
                    break;
                }

                $i = $close;

                continue;
            }

            if (!ctype_space($s[$i])) {
                continue;
            }

            $rest = ltrim(substr($s, $i));

            if (strcasecmp(substr($rest, 0, $width), $keyword) !== 0) {
                continue;
            }

            $after = $rest[$width] ?? ' ';

            if (!ctype_space($after) && $after !== '(') {
                continue;
            }

            $out[] = trim(substr($s, $start, $i - $start));
            $start = strlen($s) - strlen($rest) + $width;
            $i     = $start - 1;
        }

        $out[] = trim(substr($s, $start));

        return array_values(array_filter($out, static fn(string $part): bool => $part !== ''));
    }

    private static function closing(string $s, int $open): int|false
    {
        $depth = 0;
        $len   = strlen($s);

        for ($i = $open; $i < $len; $i++) {
            if ($s[$i] === '(') {
                $depth++;
            } elseif ($s[$i] === ')') {
                $depth--;

                if ($depth === 0) {
                    return $i;
                }
            }
        }

        return false;
    }
}
