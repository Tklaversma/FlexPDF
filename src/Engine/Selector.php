<?php

declare(strict_types=1);

namespace FlexPDF\Engine;

final class Selector
{
    /** @param SelectorPart[] $parts right-most last */
    public function __construct(
        public array $parts,
        public int $specificity,
        public string $source,
    ) {}

    public static function parse(string $text): ?self
    {
        $text = trim($text);
        if ($text === '') {
            return null;
        }

        $tokens = self::tokenize($text);

        if ($tokens === []) {
            return null;
        }

        $parts      = [];
        $combinator = ' ';

        foreach ($tokens as $tok) {
            if ($tok === '>' || $tok === '+' || $tok === '~') {
                $combinator = $tok;
                continue;
            }

            $part = self::parseCompound($tok);

            if ($part === null) {
                return null;
            }

            $part->combinator = $combinator;
            $combinator       = ' ';
            $parts[]          = $part;
        }

        if ($parts === []) {
            return null;
        }

        $ids = $classes = $types = 0;

        foreach ($parts as $p) {
            if ($p->id !== null) {
                $ids++;
            }

            $classes += count($p->classes) + count($p->attrs);

            foreach ($p->pseudos as $pseudo) {
                // `:where()` contributes nothing, by design. `:is()` and
                // `:not()` take the specificity of their strongest argument;
                // approximating that as one class is close enough and keeps
                // the counter an integer.
                if (!str_starts_with($pseudo, 'where(')) {
                    $classes++;
                }
            }

            if ($p->tag !== null && $p->tag !== '*') {
                $types++;
            }

            // A pseudo-element counts as a type selector.
            if ($p->element !== null) {
                $types++;
            }
        }

        return new self($parts, $ids * 10000 + $classes * 100 + $types, $text);
    }

    /**
     * Split into compound selectors and combinators. A regex cannot do this
     * once `:is(.a, .b > .c)` exists: the spaces and combinators inside a
     * functional pseudo belong to its argument, not to this selector.
     *
     * @return string[]
     */
    private static function tokenize(string $text): array
    {
        $tokens = [];
        $buffer = '';
        $depth  = 0;
        $len    = strlen($text);

        $flush = static function () use (&$tokens, &$buffer): void {
            if (trim($buffer) !== '') {
                $tokens[] = trim($buffer);
            }

            $buffer = '';
        };

        for ($i = 0; $i < $len; $i++) {
            $char = $text[$i];

            if ($char === '(' || $char === '[') {
                $depth++;
            } elseif ($char === ')' || $char === ']') {
                $depth--;
            }

            if ($depth > 0) {
                $buffer .= $char;
                continue;
            }

            if ($char === '>' || $char === '+' || $char === '~') {
                $flush();
                $tokens[] = $char;
                continue;
            }

            if (ctype_space($char)) {
                $flush();
                continue;
            }

            $buffer .= $char;
        }

        $flush();

        return $tokens;
    }

    private static function parseCompound(string $s): ?SelectorPart
    {
        $part   = new SelectorPart();
        $offset = 0;
        $len    = strlen($s);

        // Leading type selector or *
        if (preg_match('/^([a-zA-Z][\w-]*|\*)/', $s, $m)) {
            $part->tag = strtolower($m[1]);
            $offset    = strlen($m[1]);
        }

        while ($offset < $len) {
            $c = $s[$offset];

            if ($c === '.') {
                if (!preg_match('/^\.([\w-]+)/', substr($s, $offset), $m)) {
                    return null;
                }

                $part->classes[] = $m[1];
                $offset          += strlen($m[0]);
            } elseif ($c === '#') {
                if (!preg_match('/^#([\w-]+)/', substr($s, $offset), $m)) {
                    return null;
                }

                $part->id = $m[1];
                $offset   += strlen($m[0]);
            } elseif ($c === '[') {
                // Selectors §6.3.3's case-sensitivity flag is part of the
                // syntax, not an extra: HTML's own UA sheet writes
                // `[hidden=until-found i]`, and a parser that rejects the flag
                // drops the whole rule rather than the flag.
                if (!preg_match(
                    '/^\[([\w-]+)(?:([~|^$*]?=)\s*("[^"]*"|\'[^\']*\'|[^\s\]]*)\s*([iIsS])?)?\s*\]/',
                    substr($s, $offset),
                    $m,
                )) {
                    return null;
                }

                $part->attrs[] = [
                    $m[1],
                    $m[2] ?? '',
                    trim($m[3] ?? '', '"\''),
                    strtolower($m[4] ?? '') === 'i',
                ];
                $offset += strlen($m[0]);
            } elseif ($c === ':') {
                $doubled = str_starts_with(substr($s, $offset), '::');

                if (!preg_match('/^::?([\w-]+)/', substr($s, $offset), $m)) {
                    return null;
                }

                $consumed = strlen($m[0]);
                $argument = '';

                // A functional pseudo may nest, so the closing paren has to be
                // matched rather than found: `:not(:is(.a, .b))`.
                if (($s[$offset + $consumed] ?? '') === '(') {
                    $depth = 0;

                    for ($j = $offset + $consumed; $j < $len; $j++) {
                        if ($s[$j] === '(') {
                            $depth++;
                        } elseif ($s[$j] === ')') {
                            $depth--;

                            if ($depth === 0) {
                                $argument = substr($s, $offset + $consumed, $j - $offset - $consumed + 1);
                                $consumed += strlen($argument);
                                break;
                            }
                        }
                    }

                    if ($argument === '') {
                        return null;
                    }
                }

                $name = strtolower($m[1]);

                if ($doubled) {
                    $part->element = $name;
                } else {
                    $part->pseudos[] = $name . $argument;
                }

                $offset += $consumed;
            } else {
                return null;
            }
        }

        return $part;
    }
}
