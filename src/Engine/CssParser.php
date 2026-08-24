<?php

declare(strict_types=1);

namespace FlexPDF\Engine;

final class CssParser
{
    /** @var CssRule[] */
    public array $rules = [];

    /** @page-derived page setup, if present. */
    public array $page = [];

    /**
     * The `@page` blocks that carry a selector, by selector, parsed and kept
     * but **not applied**: every page here shares one geometry, so there is
     * nowhere for `:first` to differ. They are recorded rather than dropped so
     * a reader can see what a document asked for.
     *
     * @var array<string,array<string,array{value:string,important:bool}>>
     */
    public array $pageSelectors = [];

    /**
     * The `@page` margin boxes, by box name without the `@`, as declared in
     * the unqualified `@page` block. CSS Paged Media 3 section 5 gives sixteen
     * of them.
     *
     * @var array<string,array<string,array{value:string,important:bool}>>
     */
    public array $pageMargins = [];

    /**
     * The `@page` margin boxes a qualified or named block declares, by that
     * block's selector and then by box name.
     *
     * Kept apart from {@see pageMargins} for the same reason
     * {@see pageSelectors} is kept apart from {@see page}: these apply to some
     * pages rather than all of them, and `Html` layers them over the
     * unqualified block's on the pages they match.
     *
     * @var array<string,array<string,array<string,array{value:string,important:bool}>>>
     */
    public array $pageSelectorMargins = [];

    /** @var array<int,array<string,array{value:string,important:bool}>> @font-face blocks */
    public array $fontFaces = [];

    private int $order = 0;

    /**
     * @param string[]         $context enclosing selectors, for CSS nesting
     * @param ContainerQuery[] $queries the `@container` preludes this block is
     *                                  inside, outermost first
     */
    public function parse(string $css, array $context = [], array $queries = []): void
    {
        // Strip comments
        $css = preg_replace('!/\*.*?\*/!s', '', $css) ?? $css;

        $len = strlen($css);
        $i   = 0;

        while ($i < $len) {
            // Skip whitespace, otherwise an at-rule after a newline is
            // mistaken for a selector and its block is mis-nested.
            while ($i < $len && ctype_space($css[$i])) {
                $i++;
            }
            if ($i >= $len) {
                break;
            }

            // At-rules
            if ($css[$i] === '@') {
                $i = $this->parseAtRule($css, $i, $context, $queries);
                continue;
            }

            $brace = $this->openBrace($css, $i);

            if ($brace === false) {
                break;
            }

            $close = $this->matchBrace($css, $brace);

            if ($close === false) {
                break;
            }

            $selectorText = trim(substr($css, $i, $brace - $i));
            $body         = substr($css, $brace + 1, $close - $brace - 1);

            if ($selectorText !== '') {
                $this->addRule($selectorText, $body, $context, $queries);
            }

            $i = $close + 1;
        }
    }

    /**
     * Record one rule, then descend into whatever it nests. A nested block is
     * resolved against its enclosing selectors, so `.card { & p { } }` and
     * `.card { p { } }` both become `.card p`.
     *
     * @param string[]         $context
     * @param ContainerQuery[] $queries
     */
    private function addRule(string $selectorText, string $body, array $context, array $queries = []): void
    {
        $selectors = [];

        foreach ($this->splitTopLevel($selectorText, ',') as $part) {
            foreach ($this->resolveNested($part, $context) as $resolved) {
                $selectors[] = $resolved;
            }
        }

        [$declarations, $nested] = $this->splitBody($body);

        $parsed = $this->parseDeclarations($declarations);

        if ($parsed !== []) {
            foreach ($selectors as $selector) {
                $compiled = Selector::parse($selector);

                if ($compiled !== null) {
                    $this->rules[] = new CssRule(
                        $compiled,
                        $parsed,
                        $this->order++,
                        CssRule::ORIGIN_USER_AGENT,
                        $queries,
                    );
                }
            }
        }

        foreach ($nested as [$childSelector, $childBody]) {
            if (str_starts_with(ltrim($childSelector), '@')) {
                $this->parse($childSelector . '{' . $childBody . '}', $selectors, $queries);

                continue;
            }

            $this->addRule($childSelector, $childBody, $selectors, $queries);
        }
    }

    /**
     * Substitute `&` for each enclosing selector. A nested selector with no
     * `&` is a descendant of its parent, which is what CSS Nesting says.
     *
     * @param  string[]  $context
     * @return string[]
     */
    private function resolveNested(string $selector, array $context): array
    {
        $selector = trim($selector);

        if ($context === []) {
            return [str_replace('&', '', $selector)];
        }

        $out = [];

        foreach ($context as $parent) {
            $out[] = str_contains($selector, '&')
                ? str_replace('&', $parent, $selector)
                : $parent . ' ' . $selector;
        }

        return $out;
    }

    /**
     * Separate a block's own declarations from the rules nested inside it.
     *
     * @return array{0:string,1:array<int,array{0:string,1:string}>}
     */
    private function splitBody(string $body): array
    {
        $declarations = '';
        $nested       = [];
        $len          = strlen($body);
        $start        = 0;

        for ($i = 0; $i < $len; $i++) {
            if ($body[$i] === '"' || $body[$i] === "'") {
                $i = $this->skipString($body, $i);

                continue;
            }

            if ($body[$i] !== '{') {
                continue;
            }

            $close = $this->matchBrace($body, $i);

            if ($close === false) {
                break;
            }

            // The selector runs back to the end of the previous declaration.
            $chunk    = substr($body, $start, $i - $start);
            $lastSemi = strrpos($chunk, ';');
            $selector = trim($lastSemi === false ? $chunk : substr($chunk, $lastSemi + 1));

            $declarations .= $lastSemi === false ? '' : substr($chunk, 0, $lastSemi + 1);

            if ($selector !== '') {
                $nested[] = [$selector, substr($body, $i + 1, $close - $i - 1)];
            }

            $i     = $close;
            $start = $close + 1;
        }

        $declarations .= substr($body, $start);

        return [$declarations, $nested];
    }

    /**
     * @param string[]         $context
     * @param ContainerQuery[] $queries
     */
    private function parseAtRule(string $css, int $i, array $context = [], array $queries = []): int
    {
        if (!preg_match('/^@([\w-]+)([^;{]*)/', substr($css, $i), $m)) {
            return $i + 1;
        }

        $name    = strtolower($m[1]);
        $prelude = trim($m[2]);
        $after   = $i + strlen($m[0]);

        // Statement at-rule (@import, @charset, a bare @layer declaration)
        if (isset($css[$after]) && $css[$after] === ';') {
            return $after + 1;
        }

        $brace = strpos($css, '{', $after - 1);

        if ($brace === false) {
            return $after;
        }

        $close = $this->matchBrace($css, $brace);

        if ($close === false) {
            return $after;
        }

        $body = substr($css, $brace + 1, $close - $brace - 1);

        match ($name) {
            // CSS Paged Media §3: `@page` takes a selector, and `:first`,
            // `:left` and `:right` each qualify the block to some pages. A
            // qualified block is kept apart from the unqualified one rather
            // than merged into it, and `Html` layers it back over the page box
            // of the pages it matches. Merging them together instead made
            // `@page :first { margin: 60pt }` the margin of every page, and on
            // a page that could not spare it that was a `DivisionByZeroError`
            // rather than a wrong margin.
            'page'      => $this->collectPage($prelude, $body),
            'font-face' => $this->fontFaces[] = $this->parseDeclarations($body),
            // Honor print and all; skip screen-only blocks.
            'media'     => $this->parseConditional(
                $prelude === '' || str_contains(strtolower($prelude), 'print')
                    || str_contains(strtolower($prelude), 'all'),
                $body,
                $context,
                $queries,
            ),
            /*
             * @layer and @supports wrap ordinary rules. Layer ordering is not
             * modelled, so a layered rule cascades on its own specificity;
             * dropping the block instead would apply nothing at all, which is
             * what a compiled Tailwind sheet used to do here.
             */
            'layer'     => $this->parse($body, $context, $queries),
            'supports'  => $this->parseConditional($this->supports($prelude), $body, $context, $queries),
            /*
             * @container carries a condition, so unwrapping it the way @layer
             * is unwrapped applied every rule inside one to every document.
             * That was defect EK and it is worse than not supporting the
             * feature: a block saying `display: none` above a width the paper
             * never reaches took the box off the page. The condition travels
             * with the rules now and {@see StyleResolver::winningDeclarations()}
             * asks it about the element's own ancestor containers.
             */
            'container' => $this->parse($body, $context, [...$queries, ContainerQuery::parse($prelude)]),
            'scope'     => $this->parseScope($prelude, $body, $context, $queries),
            default     => null,
        };

        return $close + 1;
    }

    /**
     * `@scope (.card) { p { color: red } }` styles the paragraphs inside a
     * `.card` and nothing else. Unwrapping the block let a scoped rule reach
     * the whole document, which is the same fault as EK one at-rule along.
     *
     * Two things make it more than a descendant selector.
     *
     * **The scoping root contributes no specificity**, which CSS Cascade 6
     * section 3 says outright, so it is written `:where(.card)` rather than as
     * a bare prefix. `:scope` inside the block is the root itself and *does*
     * carry a pseudo-class's specificity, so that one is `:is(.card)`: this
     * parser's own specificity counter reads `:where()` as nothing and `:is()`
     * as one class, which is exactly the pair CSS asks for.
     *
     * **An ordinary selector inside the block does not reach the scoping root
     * itself**, and that is measured rather than read off the spec. The first
     * try here compiled each rule a second time with the root folded into its
     * leftmost compound, on the reading that the root is in scope. Chrome
     * disagrees on all three shapes of `RU-scope.html`'s own check, a class, a
     * type selector and the root's own class: only `:scope` reaches the root.
     *
     * **The scoping limit is the one part a selector cannot carry.**
     * `@scope (.card) to (.footer)` cuts the scope off below a `.footer`, and
     * "not below an element matching this" has no selector spelling, so the
     * limit is attached to the rules this block produced and the cascade walks
     * for it. The rules are indexed rather than threaded through the parse,
     * which is what keeps a nested `@scope` composing for free: its rules are
     * inside the outer block's range too.
     *
     * @param string[]         $context
     * @param ContainerQuery[] $queries
     */
    private function parseScope(string $prelude, string $body, array $context, array $queries): void
    {
        $roots = $this->scopeSelectors($prelude, $context, '/^\(\s*(.+?)\s*\)/s');

        if ($roots === []) {
            $this->parse($body, $context, $queries);

            return;
        }

        // `&` is what stops the root being prefixed as an ancestor: it is the
        // scoping root *itself*, not a descendant of it, and
        // {@see self::resolveNested()} substitutes rather than prefixes for a
        // selector that carries one. `:is()` beside it is what carries the
        // specificity, since `:where()` contributes none and `:scope` is a
        // pseudo-class.
        //
        // The replacement is built rather than passed as a pattern
        // replacement, because a scoping root may carry an attribute selector
        // and `$=` in one would be read as a backreference.
        $scope = '&:is(' . implode(', ', $roots) . ')';

        $body = preg_replace_callback('/:scope\b/i', static fn(): string => $scope, $body) ?? $body;

        $wrapped = array_map(static fn(string $root): string => ':where(' . $root . ')', $roots);
        $first   = count($this->rules);

        $this->parse($body, $wrapped, $queries);

        $limits = $this->scopeSelectors($prelude, $context, '/\bto\s*\(\s*(.+?)\s*\)\s*$/s');

        if ($limits === []) {
            return;
        }

        $bound = [
            'roots'  => $this->compileAll($roots),
            'limits' => $this->compileAll($limits),
        ];

        if ($bound['limits'] === []) {
            return;
        }

        for ($i = $first, $last = count($this->rules); $i < $last; $i++) {
            $this->rules[$i]->scopeBounds[] = $bound;
        }
    }

    /**
     * One half of an `@scope` prelude, resolved against the enclosing
     * selectors.
     *
     * @param  string[] $context
     * @return string[]
     */
    private function scopeSelectors(string $prelude, array $context, string $pattern): array
    {
        if (preg_match($pattern, $prelude, $m) !== 1) {
            return [];
        }

        $out = [];

        foreach ($this->splitTopLevel($m[1], ',') as $part) {
            if (trim($part) === '') {
                continue;
            }

            foreach ($this->resolveNested(trim($part), $context) as $resolved) {
                $out[] = $resolved;
            }
        }

        return $out;
    }

    /**
     * @param  string[]   $selectors
     * @return Selector[]
     */
    private function compileAll(array $selectors): array
    {
        $out = [];

        foreach ($selectors as $selector) {
            $compiled = Selector::parse($selector);

            if ($compiled !== null) {
                $out[] = $compiled;
            }
        }

        return $out;
    }

    /**
     * @param string[]         $context
     * @param ContainerQuery[] $queries
     */
    private function parseConditional(bool $matches, string $body, array $context, array $queries = []): void
    {
        if ($matches) {
            $this->parse($body, $context, $queries);
        }
    }

    /**
     * A rough `@supports` evaluation: a declaration is supported when the
     * property is one the engine reads. Anything it cannot judge is treated as
     * supported, since applying a rule the engine partly understands beats
     * dropping it.
     */
    private function supports(string $condition): bool
    {
        if (str_contains(strtolower($condition), 'not ')) {
            return false;
        }

        if (!preg_match_all('/\(\s*([a-z-]+)\s*:/i', $condition, $m)) {
            return true;
        }

        foreach ($m[1] as $property) {
            if (in_array(strtolower($property), self::UNSUPPORTED, true)) {
                return false;
            }
        }

        return true;
    }

    /** Properties the engine does not implement, so `@supports` must say no. */
    private const array UNSUPPORTED = [
        'grid-template-areas-subgrid',
        'backdrop-filter',
        'filter',
        'mask',
    ];

    private function matchBrace(string $s, int $open): int|false
    {
        $depth = 0;
        $len   = strlen($s);

        for ($i = $open; $i < $len; $i++) {
            if ($s[$i] === '"' || $s[$i] === "'") {
                $i = $this->skipString($s, $i);

                continue;
            }

            if ($s[$i] === '{') {
                $depth++;
            } elseif ($s[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
            }
        }

        return false;
    }

    /** The brace that opens the next block, skipping any that a string carries. */
    private function openBrace(string $css, int $from): int|false
    {
        $len = strlen($css);

        for ($i = $from; $i < $len; $i++) {
            if ($css[$i] === '"' || $css[$i] === "'") {
                $i = $this->skipString($css, $i);

                continue;
            }

            if ($css[$i] === '{') {
                return $i;
            }
        }

        return false;
    }

    /**
     * The index the string opening at $open ends on: its closing quote, the
     * character before the newline that cuts it short, or the last index of $s
     * when neither arrives.
     *
     * A brace inside a string is text, not structure, and reading it as
     * structure cost more than the one declaration: `content: "}"` closed its
     * own rule early and left the parser scanning from inside the sheet, so
     * **every rule after it was lost**. Measured against Chrome on a sheet of
     * six rules, where the engine printed none of the last five.
     *
     * Ending on a newline is the other half of that, and skipping it cost the
     * mirrored failure: CSS Syntax §4.3.5 makes a newline inside a string a
     * parse error that ends the string, so Chrome recovers at the line break
     * and applies every rule after it. Reading to the closing quote instead
     * swallowed the rest of the sheet, which is what a single stray `"` in a
     * hand-written stylesheet does. Measured at 83.250pt against Chrome's
     * 83.250pt where running on gave the 400.000pt UA default.
     *
     * A backslash still escapes whatever follows, a newline included, because
     * that is a line continuation and stays inside the string.
     */
    private function skipString(string $s, int $open): int
    {
        $quote = $s[$open];
        $len   = strlen($s);

        for ($i = $open + 1; $i < $len; $i++) {
            if ($s[$i] === '\\') {
                $i++;

                continue;
            }

            if ($s[$i] === "\n" || $s[$i] === "\r" || $s[$i] === "\f") {
                return $i - 1;
            }

            if ($s[$i] === $quote) {
                return $i;
            }
        }

        return $len - 1;
    }

    /**
     * Split on a separator that is not inside brackets or a string. A naive
     * split breaks `:is(a, b)` on the comma and `url(data:image/png;base64,…)`
     * on the semicolon.
     *
     * @return string[]
     */
    private function splitTopLevel(string $value, string $separator): array
    {
        $parts = [];
        $depth = 0;
        $quote = '';
        $start = 0;
        $len   = strlen($value);

        for ($i = 0; $i < $len; $i++) {
            $char = $value[$i];

            if ($quote !== '') {
                if ($char === $quote && ($i === 0 || $value[$i - 1] !== '\\')) {
                    $quote = '';
                }

                continue;
            }

            if ($char === '"' || $char === "'") {
                $quote = $char;
            } elseif ($char === '(' || $char === '[') {
                $depth++;
            } elseif ($char === ')' || $char === ']') {
                $depth--;
            } elseif ($char === $separator && $depth <= 0) {
                $parts[] = substr($value, $start, $i - $start);
                $start   = $i + 1;
            }
        }

        $parts[] = substr($value, $start);

        return $parts;
    }

    /** One `@page` block, filed under its selector or under the page itself. */
    private function collectPage(string $prelude, string $body): void
    {
        $selector = strtolower(trim($prelude));

        if ($selector === '') {
            $this->page = array_merge($this->page, $this->parsePageBody($body));

            return;
        }

        $this->pageSelectors[$selector] = $this->parsePageBody($body, $selector);
    }

    /**
     * An `@page` block's own declarations, with its margin boxes taken out.
     *
     * A margin box is a nested at-rule inside the block, so its body reaches
     * `parseDeclarations()` as one long declaration and the `content` inside
     * it is read as a property of the page. Splitting them apart here is what
     * keeps `@top-center { content: "x" }` from becoming a `@top-center`
     * declaration on the page itself.
     *
     * @param  string $selector the block's own selector, or the empty string
     *                          for the unqualified block
     * @return array<string,array{value:string,important:bool}>
     */
    private function parsePageBody(string $body, string $selector = ''): array
    {
        $rest = '';
        $i    = 0;
        $len  = strlen($body);

        while ($i < $len) {
            $at = strpos($body, '@', $i);

            if ($at === false) {
                $rest .= substr($body, $i);

                break;
            }

            $brace = strpos($body, '{', $at);

            if ($brace === false
                || preg_match('/^([\w-]+)\s*$/', substr($body, $at + 1, $brace - $at - 1), $m) !== 1) {
                $rest .= substr($body, $i);

                break;
            }

            $close = $this->matchBrace($body, $brace);

            if ($close === false) {
                $rest .= substr($body, $i);

                break;
            }

            $rest .= substr($body, $i, $at - $i);

            $name         = strtolower($m[1]);
            $declarations = $this->parseDeclarations(substr($body, $brace + 1, $close - $brace - 1));

            if ($selector === '') {
                $this->pageMargins[$name] = array_merge($this->pageMargins[$name] ?? [], $declarations);
            } else {
                $this->pageSelectorMargins[$selector][$name] = array_merge(
                    $this->pageSelectorMargins[$selector][$name] ?? [],
                    $declarations,
                );
            }

            $i = $close + 1;
        }

        return $this->parseDeclarations($rest);
    }

    /** @return array<string,array{value:string,important:bool}> */
    public function parseDeclarations(string $body): array
    {
        $out = [];
        foreach ($this->splitTopLevel($body, ';') as $decl) {
            $decl = trim($decl);

            if ($decl === '') {
                continue;
            }

            $colon = strpos($decl, ':');

            if ($colon === false) {
                continue;
            }

            $prop = trim(substr($decl, 0, $colon));

            if (!str_starts_with($prop, '--')) {
                $prop = strtolower($prop);
            }

            $value     = trim(substr($decl, $colon + 1));
            $important = false;

            if (preg_match('/(.*?)\s*!\s*important$/i', $value, $m)) {
                $value     = trim($m[1]);
                $important = true;
            }

            if ($prop === '' || $value === '') {
                continue;
            }

            if (self::isInvalidDeclaration($prop, $value)) {
                continue;
            }

            $out[$prop] = ['value' => $value, 'important' => $important];
        }

        return $out;
    }

    /** `<display-outside>`, CSS Display 3 section 2.1. */
    private const array DISPLAY_OUTSIDE = [
        'block' => true, 'inline' => true, 'run-in' => true,
    ];

    /** `<display-inside>`, CSS Display 3 section 2.2. */
    private const array DISPLAY_INSIDE = [
        'flow' => true, 'flow-root' => true, 'table' => true,
        'flex' => true, 'grid' => true, 'ruby' => true,
    ];

    /**
     * The `display` values that carry a whole box type and take no second
     * keyword, plus the two `-webkit-` spellings `HtmlBuilder::isWebkitBox()`
     * reads.
     */
    private const array DISPLAY_ALONE = [
        'contents' => true, 'none' => true,
        'table-row-group' => true, 'table-header-group' => true,
        'table-footer-group' => true, 'table-row' => true,
        'table-cell' => true, 'table-column-group' => true,
        'table-column' => true, 'table-caption' => true,
        'ruby-base' => true, 'ruby-text' => true,
        'ruby-base-container' => true, 'ruby-text-container' => true,
        'inline-block' => true, 'inline-table' => true,
        'inline-flex' => true, 'inline-grid' => true,
        '-webkit-box' => true, '-webkit-inline-box' => true,
    ];

    /** `<absolute-size>` and `<relative-size>`, CSS Fonts 4 section 3.1. */
    private const array FONT_SIZES = [
        'xx-small' => true, 'x-small' => true, 'small' => true, 'medium' => true,
        'large' => true, 'x-large' => true, 'xx-large' => true, 'xxx-large' => true,
        'smaller' => true, 'larger' => true, 'math' => true,
    ];

    /** The CSS-wide keywords, valid on every property. */
    private const array WIDE = [
        'inherit' => true, 'initial' => true, 'unset' => true,
        'revert' => true, 'revert-layer' => true,
    ];

    /**
     * Whether a declaration is invalid as written, so it is dropped here rather
     * than kept and corrected later.
     *
     * Dropping it at parse time is what lets the declaration that lost to it
     * win, including an earlier declaration of the same property in the same
     * block, which nothing downstream can recover once this method has let it
     * be overwritten.
     *
     * This asks what CSS calls invalid, never what this engine can render. A
     * value carrying a function is not judged at all, because `var()` cannot be
     * resolved yet and the rest can be spelled in ways no parser here reads:
     * `lab()` and `color(display-p3 ...)` are valid CSS that
     * `StyleResolver::rgba()` returns null for, and dropping those would throw
     * away working declarations to fix broken ones.
     */
    private static function isInvalidDeclaration(string $prop, string $value): bool
    {
        if (str_starts_with($prop, '--') || str_contains($value, '(')) {
            return false;
        }

        $v = strtolower(trim($value));

        if ($v === '' || isset(self::WIDE[$v])) {
            return false;
        }

        return match ($prop) {
            'line-height' => !($v === 'normal' || self::nonNegativeNumber($v) || self::nonNegativeLength($v)),
            'font-size' => !(isset(self::FONT_SIZES[$v]) || self::nonNegativeLength($v)),
            'color', 'background-color' => !self::isColor($v),
            'display' => !self::isValidDisplay($v),
            default => false,
        };
    }

    private static function nonNegativeNumber(string $v): bool
    {
        return preg_match('/^[-+]?(?:\d+(?:\.\d*)?|\.\d+)(?:[eE][-+]?\d+)?$/', $v) === 1
            && (float) $v >= 0.0;
    }

    /**
     * A unitless zero is a length and a unitless anything else is not. The unit
     * itself is accepted on shape rather than against a list, so a unit this
     * engine has never heard of survives to be ignored downstream instead of
     * taking the declaration with it.
     */
    private static function nonNegativeLength(string $v): bool
    {
        if (preg_match('/^([-+]?(?:\d+(?:\.\d*)?|\.\d+)(?:[eE][-+]?\d+)?)(%|[a-z]+)?$/', $v, $m) !== 1) {
            return false;
        }

        if ((float) $m[1] < 0.0) {
            return false;
        }

        return ($m[2] ?? '') !== '' || (float) $m[1] === 0.0;
    }

    private static function isColor(string $v): bool
    {
        if ($v === 'transparent' || $v === 'currentcolor') {
            return true;
        }

        if ($v[0] === '#') {
            return preg_match('/^#(?:[0-9a-f]{3,4}|[0-9a-f]{6}|[0-9a-f]{8})$/', $v) === 1;
        }

        return StyleResolver::isNamedColor($v);
    }

    /**
     * Whether a value parses as `display`, CSS Display 3 section 2.
     *
     * Public because `StyleResolver::usableValue()` asks the same question of a
     * value that has been through `var()` substitution, and one grammar that
     * two callers share cannot drift apart.
     */
    public static function isValidDisplay(string $value): bool
    {
        $parts = preg_split('/\s+/', strtolower(trim($value))) ?: [];

        if (count($parts) === 1 && isset(self::DISPLAY_ALONE[$parts[0]])) {
            return true;
        }

        $outside  = null;
        $inside   = null;
        $listItem = false;

        foreach ($parts as $part) {
            if (isset(self::DISPLAY_OUTSIDE[$part]) && $outside === null) {
                $outside = $part;

                continue;
            }

            if (isset(self::DISPLAY_INSIDE[$part]) && $inside === null) {
                $inside = $part;

                continue;
            }

            if ($part === 'list-item' && !$listItem) {
                $listItem = true;

                continue;
            }

            return false;
        }

        // `<display-listitem>` combines with `flow` and `flow-root` only.
        if ($listItem) {
            return $inside === null || $inside === 'flow' || $inside === 'flow-root';
        }

        return $outside !== null || $inside !== null;
    }
}
