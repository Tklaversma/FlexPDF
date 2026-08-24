<?php

declare(strict_types=1);

namespace FlexPDF\Engine;

use FlexPDF\Engine\Exceptions\FontMissingException;

/**
 * What a document asked for and did not get: families nobody registered, and
 * characters no face on the page could draw.
 *
 * A `font-family` list exists so that entries can miss. `'Helvetica Neue',
 * Arial, sans-serif` misses twice on purpose, and a renderer that threw on the
 * first miss would refuse a stylesheet every browser accepts. So the misses are
 * collected here and the caller decides what they are worth.
 *
 * Throwing is opt-in and works the way `->pdfa()` does: a document that asked
 * to be told refuses rather than quietly drawing something else.
 */
final class FontReport
{
    /** @var array<string,true> */
    private array $families = [];

    /** @var array<string,true> */
    private array $unresolved = [];

    /** @var array<int,true> */
    private array $codepoints = [];

    public function __construct(private bool $strict = false) {}

    public function strict(bool $strict = true): void
    {
        $this->strict = $strict;
    }

    /**
     * A family named in a `font-family` list that resolved to nothing.
     *
     * **This never throws, even in strict mode.** An entry that misses is what
     * the list is for: `'Helvetica Neue', Arial, sans-serif` misses twice by
     * design and every browser draws it. It is recorded because a developer
     * moving a template here wants to know which of their names never arrived,
     * not because anything went wrong.
     */
    public function noteFamily(string $family): void
    {
        $this->families[$family] = true;
    }

    /**
     * A whole `font-family` list that resolved to nothing, so the document got
     * the terminal Helvetica rather than anything it asked for.
     *
     * This is the case strict mode is about: not an entry that missed, but a
     * declaration that got none of its choices.
     *
     * @throws FontMissingException in strict mode
     */
    public function noteUnresolved(string $list): void
    {
        if (isset($this->unresolved[$list])) {
            return;
        }

        $this->unresolved[$list] = true;

        if ($this->strict) {
            throw FontMissingException::family($list);
        }
    }

    /**
     * A character neither the face the document asked for nor the bundled
     * fallback can draw. It is painted as the encoding's own substitute, which
     * is `?` on a base-14 face and the face's `.notdef` box on a TrueType one.
     *
     * @throws FontMissingException in strict mode
     */
    public function noteCodepoint(int $codepoint): void
    {
        if (isset($this->codepoints[$codepoint])) {
            return;
        }

        $this->codepoints[$codepoint] = true;

        if ($this->strict) {
            throw FontMissingException::codepoint($codepoint);
        }
    }

    /** @return list<string> */
    public function families(): array
    {
        return array_keys($this->families);
    }

    /** @return list<string> */
    public function unresolved(): array
    {
        return array_keys($this->unresolved);
    }

    /** @return list<int> */
    public function codepoints(): array
    {
        $out = array_keys($this->codepoints);
        sort($out);

        return $out;
    }

    public function isEmpty(): bool
    {
        return $this->families === [] && $this->unresolved === [] && $this->codepoints === [];
    }

    /** One line a log can carry, or an empty string when nothing missed. */
    public function summary(): string
    {
        if ($this->isEmpty()) {
            return '';
        }

        $parts = [];

        if ($this->families !== []) {
            $parts[] = 'families with no face: ' . implode(', ', $this->families());
        }

        if ($this->unresolved !== []) {
            $parts[] = 'font-family lists that resolved to nothing: ' . implode(' | ', $this->unresolved());
        }

        if ($this->codepoints !== []) {
            $parts[] = 'characters with no glyph: ' . implode(' ', array_map(
                static fn(int $cp): string => sprintf('U+%04X', $cp),
                $this->codepoints(),
            ));
        }

        return implode('; ', $parts);
    }
}
