<?php

declare(strict_types=1);

namespace FlexPDF\Engine;

/** A parsed simple selector sequence plus its combinator to the previous one. */
final class SelectorPart
{
    public function __construct(
        public ?string $tag = null,
        public array $classes = [],
        public ?string $id = null,
        public array $attrs = [], // [name, op, value, case-insensitive]
        public array $pseudos = [], // ['first-child'] etc
        public string $combinator = ' ', // ' ', '>', '+'
        /** Pseudo-element written with `::`, which is generated content rather than a match. */
        public ?string $element = null,
    ) {}
}
