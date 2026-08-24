<?php

declare(strict_types=1);

namespace FlexPDF\Engine\Support;

/**
 * Renames the attributes a malformed tag fabricates, so a name the document
 * never wrote cannot be matched by a selector.
 *
 * Defect CV. `<li style="grid-template-areas: "x . y"; overflow: hidden">` is
 * a parse error, and both HTML5 and `DOMDocument` recover from it the same
 * way: the value ends at the second quote and the rest of the tag is read as
 * bare attributes. The whole divergence is in the **names**, because libxml
 * drops the `"`, `;` and `,` that HTML5 keeps, so Chrome's `hidden"` is
 * libxml's `hidden` and `[hidden]` matches here and not there.
 *
 * That difference is why no UA rule may key on an attribute: HTML's own
 * `[hidden] { display: none }` deleted content from 141 of 8,000 corpus
 * documents. This is not a tokenizer and does not try to be one. Chrome's
 * names do not have to be reproduced; a fabricated name only has to stop
 * colliding with an authored one, which renaming does.
 *
 * The scan is one pass, left to right, and it only ever looks inside a tag:
 * text, comments, the doctype and the content of a raw-text element are
 * jumped over with `strpos`, never walked. A document with no malformed tag
 * in it comes back unchanged, character for character.
 */
final class TagRepair
{
    /**
     * The renamed attribute's prefix. Reserved: an author writing it gets it
     * back with a number on the end, which is the same thing that happens to
     * a name a malformed tag invents.
     */
    private const string PREFIX = 'data-flexpdf-fabricated-';

    /** Where a tag name may start, after the `<`. */
    private const string NAME_START = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';

    private const string NAME_CHARS = self::NAME_START . '0123456789-_:.';

    private const string SPACE = " \t\n\r\f";

    /**
     * Elements whose content is text rather than markup. A `<` inside one of
     * them starts no tag, so the whole element is jumped over: not repairing
     * something is always the safe direction, and repairing text would not be.
     */
    private const array RAW_TEXT = [
        'script'   => true,
        'style'    => true,
        'textarea' => true,
        'title'    => true,
        'iframe'   => true,
        'noembed'  => true,
        'noframes' => true,
        'xmp'      => true,
    ];

    public static function apply(string $html): string
    {
        $length = strlen($html);
        $i      = 0;

        // Where a tag was repaired and what it became. A document with no
        // malformed tag in it collects nothing and is handed straight back,
        // rather than being copied a piece at a time into a new string.
        $repairs = [];

        while ($i < $length) {
            $lt = strpos($html, '<', $i);

            if ($lt === false) {
                break;
            }

            $i = $lt;

            // A comment, a doctype, a CDATA section or an end tag: none of
            // them can fabricate an attribute, so each is stepped over whole.
            $skipTo = self::skipNonStartTag($html, $lt);

            if ($skipTo !== null) {
                $i = $skipTo;

                continue;
            }

            $nameLength = strspn($html, self::NAME_CHARS, $lt + 1);

            // A bare `<` in text. Carry on from the next one.
            if ($nameLength === 0 || !ctype_alpha($html[$lt + 1])) {
                $i = $lt + 1;

                continue;
            }

            $tagName = strtolower(substr($html, $lt + 1, $nameLength));
            $tag     = self::repairTag($html, $lt, $lt + 1 + $nameLength);

            // An unterminated tag is left exactly as it was: libxml recovers
            // from it today and this is not the place to change how.
            if ($tag === null) {
                $i = $lt + 1;

                continue;
            }

            [$text, $after] = $tag;

            if ($text !== null) {
                $repairs[] = [$lt, $after, $text];
            }

            $i = $after;

            if (isset(self::RAW_TEXT[$tagName])) {
                $close = stripos($html, '</' . $tagName, $i);
                $i     = $close === false ? $length : $close;
            }
        }

        if ($repairs === []) {
            return $html;
        }

        $out  = '';
        $from = 0;

        foreach ($repairs as [$start, $end, $text]) {
            $out  .= substr($html, $from, $start - $from) . $text;
            $from = $end;
        }

        return $out . substr($html, $from);
    }

    /**
     * Where to resume after something that is not a start tag, or null if a
     * start tag is what begins here.
     */
    private static function skipNonStartTag(string $html, int $lt): ?int
    {
        $length = strlen($html);

        if (str_starts_with(substr($html, $lt, 4), '<!--')) {
            $end = strpos($html, '-->', $lt + 4);

            return $end === false ? $length : $end + 3;
        }

        if (($html[$lt + 1] ?? '') === '!' || ($html[$lt + 1] ?? '') === '?') {
            $end = strpos($html, '>', $lt + 1);

            return $end === false ? $length : $end + 1;
        }

        if (($html[$lt + 1] ?? '') === '/') {
            $end = strpos($html, '>', $lt + 1);

            return $end === false ? $length : $end + 1;
        }

        return null;
    }

    /**
     * One start tag, with everything a malformed quoted value fabricated
     * renamed, and the offset just past it.
     *
     * HTML5 section 13.2.5.37: after a quoted attribute value the tokeniser
     * expects whitespace, `>` or `/`, and anything else is a parse error it
     * recovers from by reading the rest of the tag as attribute names. That
     * character **is** the malformed case, and everything from it to the end
     * of the tag is a name the document did not write.
     *
     * @return array{0:?string,1:int}|null the repaired tag, or null where the
     *                                       tag needed no repair, and the
     *                                       offset just past it
     */
    private static function repairTag(string $html, int $lt, int $i): ?array
    {
        $length    = strlen($html);
        $breakFrom = null;
        $renamed   = [];

        while ($i < $length) {
            $i += strspn($html, self::SPACE, $i);

            if ($i >= $length) {
                return null;
            }

            $c = $html[$i];

            if ($c === '>') {
                return [self::rebuild($html, $lt, $breakFrom, $renamed, '>'), $i + 1];
            }

            if ($c === '/') {
                if (($html[$i + 1] ?? '') === '>') {
                    return [self::rebuild($html, $lt, $breakFrom, $renamed, '/>'), $i + 2];
                }

                $i++;

                continue;
            }

            if ($c === '=') {
                $i++;

                continue;
            }

            $nameLength = strcspn($html, self::SPACE . '/>=', $i);
            $name       = substr($html, $i, $nameLength);

            $i += $nameLength;

            $afterName = $i + strspn($html, self::SPACE, $i);
            $value     = null;
            $quoted    = false;

            if (($html[$afterName] ?? '') === '=') {
                $i = $afterName + 1;
                $i += strspn($html, self::SPACE, $i);
                $q = $html[$i] ?? '';

                if ($q === '"' || $q === "'") {
                    $close = strpos($html, $q, $i + 1);

                    if ($close === false) {
                        return null;
                    }

                    $value  = substr($html, $i + 1, $close - $i - 1);
                    $quoted = true;
                    $i      = $close + 1;
                } else {
                    $valueLength = strcspn($html, self::SPACE . '>', $i);
                    $value       = substr($html, $i, $valueLength);
                    $i           += $valueLength;
                }
            }

            if ($breakFrom !== null) {
                $renamed[] = [$name, $value, self::survivesLibxml($name)];

                continue;
            }

            // The parse error itself: a quoted value that does not end the
            // attribute. Everything from here on is fabricated.
            $next = $html[$i] ?? '>';

            if ($quoted && $next !== '>' && $next !== '/' && strpos(self::SPACE, $next) === false) {
                $breakFrom = $i;
            }
        }

        return null;
    }

    /**
     * Whether `DOMDocument` reads this name whole.
     *
     * It is the XML Name production, and it is where the divergence lives:
     * libxml truncates a name at the first character outside it, so `hidden"`
     * arrives as `hidden` and collides with the attribute HTML gives that
     * meaning. A name inside the production arrives intact, and Chrome's is
     * then the same string, so leaving it alone is what **agrees** with
     * Chrome: `<div data-a="q"hidden>` fabricates a plain `hidden` in both and
     * `[hidden]` is meant to match it (`PV-attr-hidden.html` `w4`).
     */
    private static function survivesLibxml(string $name): bool
    {
        return preg_match('/^[a-zA-Z_:][a-zA-Z0-9_:.-]*$/', $name) === 1;
    }

    /**
     * The tag's text: everything up to the parse error unchanged, then the
     * fabricated attributes, with the ones libxml would rewrite given names
     * nothing can have written.
     *
     * Null where the tag holds no parse error at all, which is every tag in
     * an ordinary document: the caller then leaves the text alone.
     *
     * @param array<int,array{0:string,1:?string,2:bool}> $renamed
     */
    private static function rebuild(string $html, int $lt, ?int $breakFrom, array $renamed, string $close): ?string
    {
        if ($breakFrom === null) {
            return null;
        }

        $text = substr($html, $lt, $breakFrom - $lt);

        foreach ($renamed as $index => [$name, $value, $survives]) {
            $text .= ' ' . ($survives ? $name : self::PREFIX . $index);

            if ($value !== null) {
                $text .= '="' . htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE) . '"';
            }
        }

        return $text . ' ' . $close;
    }
}
