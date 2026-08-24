<?php

declare(strict_types=1);

namespace FlexPDF\Engine;

/**
 * Tagged PDF: what the document means, beside what it looks like.
 *
 * A PDF's content stream says where ink goes and nothing about what it is, so
 * a reader that has to speak the page, reflow it on a small screen or export
 * it to HTML has only the geometry to guess from. A tagged document carries a
 * second tree: every piece of ink is wrapped in a marked-content sequence with
 * an id, and a tree of structure elements says which paragraph, heading or
 * table cell each id belongs to, in reading order.
 *
 * Two things make that tree hard to build here and both are answered the same
 * way. The ink arrives in **paint order**, page by page, after the fragmenter
 * has flattened the box tree and Appendix E has sorted what is left, so the
 * order marks are recorded in is not reading order. And a box that straddles a
 * fold paints twice on two pages. So marks are only recorded as they happen,
 * keyed by the box that made them, and the tree is built afterwards by walking
 * the box tree in document order and picking up each box's marks where it
 * sits. Reading order is the tree's, not the page's.
 *
 * Everything with no box behind it is an artifact: the canvas background, a
 * running header, the proxy a fold-cut box is painted through. A reader skips
 * those, which is what stops a page number being read out in the middle of a
 * sentence.
 */
final class StructureTree
{
    /**
     * HTML element name to PDF structure type.
     *
     * The types are ISO 32000-1's standard set, so no `/RoleMap` is needed.
     * `<em>` and `<strong>` map to `Span` rather than to PDF 2.0's `Em` and
     * `Strong` for the same reason. Anything not here is not a structure of
     * its own: a `<span>` in a paragraph is part of that paragraph's text and
     * gets no element, which is what keeps a sentence one sentence.
     */
    private const array ROLES = [
        'p'          => 'P',
        'h1'         => 'H1',
        'h2'         => 'H2',
        'h3'         => 'H3',
        'h4'         => 'H4',
        'h5'         => 'H5',
        'h6'         => 'H6',
        'div'        => 'Div',
        'section'    => 'Sect',
        'article'    => 'Art',
        'main'       => 'Sect',
        'header'     => 'Sect',
        'footer'     => 'Sect',
        'nav'        => 'Sect',
        'aside'      => 'Sect',
        'blockquote' => 'BlockQuote',
        'ul'         => 'L',
        'ol'         => 'L',
        'li'         => 'LI',
        'dl'         => 'L',
        'dt'         => 'Lbl',
        'dd'         => 'LBody',
        'table'      => 'Table',
        'thead'      => 'THead',
        'tbody'      => 'TBody',
        'tfoot'      => 'TFoot',
        'tr'         => 'TR',
        'td'         => 'TD',
        'th'         => 'TH',
        'caption'    => 'Caption',
        'figure'     => 'Figure',
        'figcaption' => 'Caption',
        'img'        => 'Figure',
        'svg'        => 'Figure',
        'a'          => 'Link',
        'pre'        => 'Code',
        'code'       => 'Code',

        /*
         * A `<form>` is a container and `/Form` is not one: ISO 32000-1 table
         * 340 defines it as **a form field**, beside `Figure` and `Formula`,
         * and PDF/UA asks one to hold exactly one widget's `/OBJR`. A `<form>`
         * holding a paragraph and three controls is a division of the document,
         * so it is one, and the fields inside it are the `/Form` elements.
         */
        'form'       => 'Div',
    ];

    /** Display values the anonymous boxes CSS 2.1 section 17.2.1 generates carry. */
    private const array ANONYMOUS_ROLES = [
        'table'      => 'Table',
        'table-row'  => 'TR',
        'table-cell' => 'TD',
    ];

    /**
     * Marked content, keyed by the box that painted it.
     *
     * `page` is the page it is rendered on and `stream` the content stream it
     * was written into, which is the same number until a subtree is composited
     * as a transparency group: that content lives in a form XObject of its
     * own, so it numbers its ids from zero again and needs a parent-tree key
     * of its own.
     *
     * @var array<int, list<array{page:int,stream:int,mcid:int}>>
     */
    private array $marks = [];

    /** @var array<int, int> content stream => the next MCID free in it */
    private array $nextMcid = [];

    /** @var array<int, array<int, int>> {@see inlineOrder()}, kept per box */
    private array $inlineOrders = [];

    /**
     * The interactive widgets each form control produced, keyed by the control
     * box, in the order they were painted.
     *
     * A field's ink is in its widget's appearance stream rather than on the
     * page, so it makes no marked content at all and the walk below would step
     * straight over it: a tagged form would read as the labels with nothing to
     * fill in between them. A widget reaches the tree through an `/OBJR`
     * instead, inside a `/Form` element of its own.
     *
     * @var array<int, list<int>> control box => the page each widget is on
     */
    private array $widgets = [];

    /**
     * The atomic inlines that sit inside an `<a href>`, and which link.
     *
     * A picture inside an anchor is a box rather than a run of text, so it
     * never reaches the split {@see readingOrder()} makes for a link's words
     * and its `Figure` element would sit outside the `Link` that owns it.
     * Chrome writes `Link` holding an `/OBJR` and the `Figure`, and PDF/UA-1
     * refuses the other shape, so the painter says which boxes are linked as
     * it draws them.
     *
     * @var array<int, array{anchor:string,page:int}>
     */
    private array $linkedBoxes = [];

    /** How deep inside content that means nothing the painter currently is. */
    private int $artifacts = 0;

    /** The document's language, for `/Lang`. Empty writes no entry. */
    public string $lang = '';

    public static function roleFor(string $element): string
    {
        return self::ROLES[strtolower($element)] ?? '';
    }

    public static function anonymousRoleFor(string $display): string
    {
        return self::ANONYMOUS_ROLES[$display] ?? '';
    }

    /**
     * Give every box the nearest ancestor that has a role.
     *
     * Run over the laid-out tree, once, before anything is painted. Layout
     * generates boxes of its own and the walk has to see those too, which is
     * why it runs here rather than in the builder.
     */
    public function own(Node $root, ?Node $owner = null): void
    {
        // A piece a column boundary cut off is the same element as the box it
        // came from, so its ink joins that element rather than opening a
        // second one: a paragraph across two columns is one `P` with two
        // marked-content sequences, which is what a paragraph across two pages
        // already was. The box it came from is an earlier sibling, so this
        // walk has already answered for it.
        $root->structureOwner = $root->fragmentOf?->structureOwner
            ?? ($root->role !== '' ? $root : $owner);

        foreach (self::childrenOf($root) as $child) {
            $this->own($child, $root->structureOwner);
        }
    }

    /**
     * Everything painted while $inside runs is decoration a reader skips.
     *
     * @template T
     * @param callable():T $inside
     * @return T
     */
    public function asArtifact(callable $inside): mixed
    {
        $this->artifacts++;

        try {
            return $inside();
        } finally {
            $this->artifacts--;
        }
    }

    public function inArtifact(): bool
    {
        return $this->artifacts > 0;
    }

    /**
     * Record one piece of marked content and return the id it carries.
     *
     * `$order` is where on the box's own lines the ink sits, from
     * {@see inlineOrder()}. A mark with none is ink with no place on a line, a
     * picture or an SVG, and it comes first the way it always did.
     *
     * `$wrap` is a role to put around this mark alone, for ink that is a
     * structure of its own without a box to hang it on. A list marker is the
     * one case: it is drawn beside its item rather than on its lines, and
     * Chrome's tagged export puts it in a `Lbl` inside the `LI`.
     */
    public function mark(Node $painted, int $page, ?int $stream = null, ?int $order = null, string $wrap = '', string $wrapKey = ''): int
    {
        $stream ??= $page;
        $mcid   = $this->nextMcid[$stream] ?? 0;

        $this->nextMcid[$stream]                = $mcid + 1;
        $this->marks[spl_object_id($painted)][] = [
            'page'    => $page,
            'stream'  => $stream,
            'mcid'    => $mcid,
            'order'   => $order,
            'wrap'    => $wrap,
            'wrapKey' => $wrapKey,
        ];

        return $mcid;
    }

    /**
     * Where each piece on a box's lines sits, as one running number over all of
     * them.
     *
     * This is the key reading order is sorted by, and both sides of that sort
     * read it here so they cannot drift: the painter asks for it to number the
     * marks it splits the text into, and {@see readingOrder()} asks for it to
     * place the atomic inlines between them.
     *
     * **Kept, because the painter asks for it once per page a box is on.** The
     * walk is over all of the box's lines and not only the page's, so a
     * paragraph over 400 pages would otherwise pay for its whole line list 400
     * times. Held for the render, like the marks themselves, and bounded by the
     * number of pieces the tree has.
     *
     * @return array<int,int> spl_object_id of an InlineItem => its ordinal
     */
    public function inlineOrder(Node $n): array
    {
        $key = spl_object_id($n);

        if (isset($this->inlineOrders[$key])) {
            return $this->inlineOrders[$key];
        }

        $at = [];
        $i  = 0;

        foreach ($n->lineBoxes as $line) {
            foreach ($line->items as $item) {
                $at[spl_object_id($item)] = $i++;
            }
        }

        return $this->inlineOrders[$key] = $at;
    }

    /**
     * Take back the last mark in a content stream, for a box that turned out to
     * paint nothing. Only the newest one can go, which is all the painter ever
     * asks for: sequences nest, so an inner one has already given its id back
     * by the time the one around it does.
     */
    public function unmark(Node $painted, int $stream, int $mcid): void
    {
        $key = spl_object_id($painted);

        if (($this->nextMcid[$stream] ?? 0) !== $mcid + 1 || !isset($this->marks[$key])) {
            return;
        }

        $this->nextMcid[$stream] = $mcid;
        array_pop($this->marks[$key]);

        if ($this->marks[$key] === []) {
            unset($this->marks[$key]);
        }
    }

    /** Record that this control produced a widget on a page. */
    public function widget(Node $control, int $page): void
    {
        $this->widgets[spl_object_id($control)][] = $page;
    }

    /** Record that this atomic inline sits inside a link. */
    public function linked(Node $box, string $anchor, int $page): void
    {
        $this->linkedBoxes[spl_object_id($box)] = ['anchor' => $anchor, 'page' => $page];
    }

    public function isEmpty(): bool
    {
        return $this->marks === [] && $this->widgets === [];
    }

    /** How many marked-content ids one content stream handed out. */
    public function marksOn(int $stream): int
    {
        return $this->nextMcid[$stream] ?? 0;
    }

    /**
     * The structure elements, in reading order, as a nested list.
     *
     * A box with a role becomes an element whose children are what its own
     * subtree painted; a box without one contributes its marks to whichever
     * element encloses it. A branch that painted nothing is dropped, so a
     * `display: none` subtree and an empty wrapper leave no element behind.
     *
     * @return list<array{role:string,alt:string,k:list<mixed>}>
     */
    public function elements(Node $root): array
    {
        $k = [];
        $this->collect($root, $k);

        self::nameHeaders($k);

        return $k;
    }

    /**
     * Turn the header association into the two keys a PDF carries. Defect HA.
     *
     * A header cell gets an `/ID` only if some data cell NAMES it, which is
     * what Chrome does and what keeps the corner cell of a table with a
     * row-header column out: nothing in its column is a data cell. The names
     * are `node%08d` in the order the tree meets the headers, so two runs of
     * the same document give the same file.
     *
     * @param list<mixed> $k
     */
    private static function nameHeaders(array &$k): void
    {
        $wanted = [];

        $collectWanted = static function (array $branch) use (&$collectWanted, &$wanted): void {
            foreach ($branch as $element) {
                if (!is_array($element)) {
                    continue;
                }

                foreach ($element['headers'] ?? [] as $header) {
                    $wanted[$header] = true;
                }

                if (isset($element['k']) && is_array($element['k'])) {
                    $collectWanted($element['k']);
                }
            }
        };

        $collectWanted($k);

        if ($wanted === []) {
            return;
        }

        $names = [];

        $mint = static function (array &$branch) use (&$mint, &$wanted, &$names): void {
            foreach ($branch as &$element) {
                if (!is_array($element)) {
                    continue;
                }

                $cell = $element['cell'] ?? null;

                if ($cell !== null && isset($wanted[$cell]) && !isset($names[$cell])) {
                    $names[$cell]   = sprintf('node%08d', count($names) + 1);
                    $element['id'] = $names[$cell];
                }

                if (isset($element['k']) && is_array($element['k'])) {
                    $mint($element['k']);
                }
            }
        };

        $mint($k);

        $apply = static function (array &$branch) use (&$apply, $names): void {
            foreach ($branch as &$element) {
                if (!is_array($element)) {
                    continue;
                }

                if (($element['headers'] ?? []) !== []) {
                    $element['headers'] = array_values(array_filter(array_map(
                        static fn(int $header): ?string => $names[$header] ?? null,
                        $element['headers'],
                    )));
                }

                if (isset($element['k']) && is_array($element['k'])) {
                    $apply($element['k']);
                }
            }
        };

        $apply($k);
    }

    /** @param list<mixed> $k */
    private function collect(Node $n, array &$k): void
    {
        foreach ($this->widgets[spl_object_id($n)] ?? [] as $page) {
            $k[] = ['objr' => spl_object_id($n) . ':' . $page, 'page' => $page];
        }

        // Where the element a piece belongs to already is, so the rest of a box
        // a column boundary cut joins it instead of opening a second one.
        $opened = [];

        // An anonymous box contributes to its parent's list rather than to one
        // of its own, so what this box added starts here.
        $start = count($k);

        foreach ($this->readingOrder($n) as $child) {
            if (!$child instanceof Node) {
                /*
                 * A `Link` element holds the link's text AND an `/OBJR` for
                 * every annotation the anchor drew, which is one per line it
                 * wraps over. ISO 32000-1 14.8.4.4.2 asks for it and PDF/UA-1
                 * clause 7.18.5 is what fails a file without it: an annotation
                 * that is not inside its own element is a clickable rectangle
                 * a reader cannot name.
                 */
                $objr = ($child['wrapKey'] ?? '') === ''
                    ? null
                    : ['linkobjr' => $child['wrapKey'] . ':' . $child['page'], 'page' => $child['page']];

                // A block-level `<a>` has a box whose own role is already
                // `Link`, so the mark opened no wrap of its own and the
                // annotation belongs in the element around it. Round 47 read
                // that off `SM-tag-link.html` a6, which was the last of the
                // five annotations veraPDF still refused.
                if (($child['wrap'] ?? '') === '') {
                    $k[] = $child;

                    if ($objr !== null) {
                        $k[] = $objr;
                    }

                    continue;
                }

                $k[] = [
                    'role' => $child['wrap'],
                    'alt'  => '',
                    'k'    => $objr === null ? [$child] : [$child, $objr],
                ];

                continue;
            }

            $origin = $child->fragmentOf === null ? null : ($opened[spl_object_id($child->fragmentOf)] ?? null);

            if ($origin !== null) {
                $rest = [];
                $this->collect($child, $rest);

                // The rest of a list item goes inside the item's body, not
                // beside it: an `LI` holds a `Lbl` and an `LBody` and nothing
                // else, and a page break in the middle of one must not be the
                // thing that puts a third child in it.
                $body = self::listBodyIn($k[$origin]);

                if ($body !== null) {
                    $k[$origin]['k'][$body]['k'] = [...$k[$origin]['k'][$body]['k'], ...$rest];

                    continue;
                }

                $k[$origin]['k'] = [...$k[$origin]['k'], ...$rest];

                continue;
            }

            $role = $child->role !== '' ? $child->role : $this->roleOfWidget($child);

            if ($role === '') {
                $this->collect($child, $k);

                continue;
            }

            $branch = [];
            $this->collect($child, $branch);

            $branch = match ($role) {
                'LI'    => self::wrapListBody($branch),
                'L'     => self::groupListItems($branch),
                default => $branch,
            };

            if ($branch !== []) {
                $element = [
                    'role'    => $role,
                    'alt'     => $child->altText,
                    'scope'   => $child->headerScope,
                    'cell'    => spl_object_id($child),
                    'headers' => array_map(static fn(Node $header): int => spl_object_id($header), $child->headerCells),
                    'k'       => $branch,
                ];
                $inside  = $this->linkedBoxes[spl_object_id($child)] ?? null;

                // A picture inside an `<a href>`: the `Figure` goes inside the
                // `Link` that owns it, beside the annotation's `/OBJR`.
                if ($inside !== null && $role !== 'Link') {
                    $element = ['role' => 'Link', 'alt' => '', 'k' => [
                        $element,
                        ['linkobjr' => $inside['anchor'] . ':' . $inside['page'], 'page' => $inside['page']],
                    ]];
                }

                $k[]                          = $element;
                $opened[spl_object_id($child)] = array_key_last($k);
            }
        }

        if ($n->isTableWrapper) {
            self::nestCaption($k, $start);
        }
    }

    /**
     * An `LI` holds a `Lbl` and an `LBody` and nothing else.
     *
     * **The browser is not the reference for this one and checking that cost
     * nothing.** Chrome's tagged export puts the item's own text straight into
     * the `LI` beside the `Lbl`, and veraPDF's PDF/UA-1 profile fails
     * **Chrome's own file** on ISO 14289-1 clause 7.2 test 20, "LI element may
     * contain only Lbl and LBody elements", with the same two checks it fails
     * this engine's. So round 25's row text was right where the browser is
     * wrong, and a round that had only asked Chrome would have shipped its
     * shape. `SM-tag-list.html` is the page and `verapdf -f ua1` is the
     * instrument.
     *
     * @param  list<mixed> $branch
     * @return list<mixed>
     */
    private static function wrapListBody(array $branch): array
    {
        $found = self::liftLabel($branch);
        $label = $found === null ? [] : [$found];

        if ($branch === []) {
            return $label;
        }

        return [...$label, ['role' => 'LBody', 'alt' => '', 'k' => $branch]];
    }

    /**
     * The item's own label, taken out of wherever the marker's mark landed.
     *
     * An item whose content is a block child hangs its marker on the first text
     * box under it, so the `Lbl` is written **inside** that box's element
     * rather than beside it. Chrome puts the label directly under the `LI`, and
     * so does ISO 32000-1 table 340, so it is lifted out here.
     *
     * **The walk does not descend into a nested list**, or an inner item's
     * label would be taken for the outer item's and the inner bullet would move
     * up a level in the tree.
     *
     * @param  list<mixed> $branch
     * @return array<string,mixed>|null
     */
    private static function liftLabel(array &$branch): ?array
    {
        foreach ($branch as $index => $entry) {
            if (!is_array($entry) || !isset($entry['role'])) {
                continue;
            }

            if ($entry['role'] === 'Lbl') {
                unset($branch[$index]);
                $branch = array_values($branch);

                return $entry;
            }

            if ($entry['role'] === 'L' || $entry['role'] === 'LI') {
                continue;
            }

            $inner = $entry['k'] ?? [];
            $found = self::liftLabel($inner);

            if ($found !== null) {
                $branch[$index]['k'] = $inner;

                return $found;
            }
        }

        return null;
    }

    /**
     * Where the `LBody` is in an element, if it is one that has one.
     *
     * @param array{role:string,alt:string,k:list<mixed>} $element
     */
    private static function listBodyIn(array $element): ?int
    {
        if ($element['role'] !== 'LI') {
            return null;
        }

        foreach ($element['k'] as $at => $entry) {
            if (is_array($entry) && ($entry['role'] ?? '') === 'LBody') {
                return $at;
            }
        }

        return null;
    }

    /**
     * An `L` holds `L`, `LI` and `Caption` and nothing else.
     *
     * A `<dl>` is the shape that breaks it: `<dt>` maps to `Lbl` and `<dd>` to
     * `LBody`, and both landed straight under the `L` with no `LI` between
     * them, which PDF/UA-1 refuses twice over (clause 7.2 tests 18 and 19).
     * Each `Lbl` opens an item and the `LBody` after it joins that item, which
     * is what a definition list means.
     *
     * @param  list<mixed> $branch
     * @return list<mixed>
     */
    private static function groupListItems(array $branch): array
    {
        $out  = [];
        $open = null;

        foreach ($branch as $entry) {
            $role = is_array($entry) ? ($entry['role'] ?? '') : '';

            if ($role !== 'Lbl' && $role !== 'LBody') {
                $open  = null;
                $out[] = $entry;

                continue;
            }

            if ($role === 'Lbl' || $open === null) {
                $out[] = ['role' => 'LI', 'alt' => '', 'k' => []];
                $open  = array_key_last($out);
            }

            $out[$open]['k'][] = $entry;
        }

        return $out;
    }

    /**
     * Put a table's caption inside its `Table` element.
     *
     * CSS puts a `<caption>` in the table **wrapper** box rather than in the
     * grid, because that is where its own margins and its `caption-side`
     * belong, and {@see HtmlBuilder::withCaption()} models that as an
     * anonymous block holding the two side by side. A reader wants the other
     * shape: ISO 32000-1 makes `Caption` a child of `Table` and Chrome's
     * tagged export writes it there, where this engine wrote it as the table's
     * **sibling**, so a reader met a loose run of text with nothing saying
     * which table it names. `SM-tag-table.html` t4 is the slot.
     *
     * The box tree is left exactly as it is. This is the tree a reader walks
     * and it is allowed to differ from the one layout needs, which is the same
     * licence `readingOrder()` already takes over paint order.
     *
     * `$start` bounds the search to what this wrapper contributed, because an
     * anonymous box appends to its parent's list and an earlier sibling may
     * have put a `Table` in it already.
     *
     * @param list<mixed> $k
     */
    private static function nestCaption(array &$k, int $start): void
    {
        $caption = null;
        $table   = null;

        for ($i = $start; $i < count($k); $i++) {
            $role = is_array($k[$i]) ? ($k[$i]['role'] ?? '') : '';

            if ($role === 'Caption' && $caption === null) {
                $caption = $i;
            }

            if ($role === 'Table' && $table === null) {
                $table = $i;
            }
        }

        if ($caption === null || $table === null) {
            return;
        }

        // `caption-side: bottom` puts the caption after the table in the
        // wrapper, and a reader meets it in the same place.
        $k[$table]['k'] = $caption < $table
            ? [$k[$caption], ...$k[$table]['k']]
            : [...$k[$table]['k'], $k[$caption]];

        array_splice($k, $caption, 1);
    }

    /**
     * A control that produced a widget is a `/Form` element even though its own
     * element name maps to nothing, because the field is the structure rather
     * than the `<input>` tag.
     */
    private function roleOfWidget(Node $n): string
    {
        return isset($this->widgets[spl_object_id($n)]) ? 'Form' : '';
    }

    /**
     * Every box under this one that can reach the page.
     *
     * Three lists rather than one: an out-of-flow box was hoisted out of
     * `children` and an atomic inline never was in it, and both paint. Order is
     * not a claim here, only reach: {@see readingOrder()} is what a reader
     * meets and in what order, and it runs after the page is painted where this
     * runs before it.
     *
     * @return list<Node>
     */
    private static function childrenOf(Node $n): array
    {
        $children = $n->children;

        foreach ($n->lineBoxes as $line) {
            foreach ($line->items as $item) {
                if ($item->isAtomic() && $item->run->box !== null) {
                    $children[] = $item->run->box;
                }
            }
        }

        return [...$children, ...$n->positioned];
    }

    /**
     * What a reader meets inside this box, in the order it meets it: the box's
     * own marks and everything under it that can reach the page.
     *
     * A field sits on a line with words either side of it, so its place is
     * between them and not after them. That is defect EE, and it is not only a
     * form field: an `<img>` between two words is the same atomic inline
     * reached the same way. The painter splits the box's text into one mark per
     * stretch between atomic inlines and numbers each with
     * {@see inlineOrder()}, so the two lists merge on a key both sides derive
     * from the same walk.
     *
     * Ink with no place on a line, a picture or an SVG, has no ordinal and
     * comes first. Block children come after, in the order they were written.
     * **No box in this engine holds both lines and block children**, measured
     * at 0 of 2,293 boxes over the tagging corpus, so the order between those
     * two is a question nothing asks.
     *
     * @return list<Node|array{page:int,stream:int,mcid:int,order:?int}>
     */
    private function readingOrder(Node $n): array
    {
        $order   = $this->inlineOrder($n);
        $entries = [];
        $seq     = 0;

        foreach ($this->marks[spl_object_id($n)] ?? [] as $ref) {
            $entries[] = [$ref['order'] ?? -1, $ref['order'] === null ? 0 : 1, $seq++, $ref];
        }

        foreach ($n->lineBoxes as $line) {
            foreach ($line->items as $item) {
                if ($item->isAtomic() && $item->run->box !== null) {
                    $entries[] = [$order[spl_object_id($item)] ?? -1, 2, $seq++, $item->run->box];
                }
            }
        }

        foreach (self::documentOrderChildren($n) as $child) {
            $entries[] = [PHP_INT_MAX, 3, $seq++, $child];
        }

        usort($entries, static fn(array $a, array $b): int => [$a[0], $a[1], $a[2]] <=> [$b[0], $b[1], $b[2]]);

        return array_map(static fn(array $entry): mixed => $entry[3], $entries);
    }

    /**
     * A box's block children in the order they were WRITTEN, not stored.
     *
     * {@see HtmlBuilder::partition()} holds an out-of-flow child back and
     * appends it after the block's own content, because emitting it where it
     * stands would close the line it sits on. That is a layout decision, and a
     * reader must not inherit it any more than the paint order does: an
     * absolutely positioned box was read **last** here, which
     * `childrenOf()`'s own comment has hedged since round 25 as "document
     * order for the tree the builder produced rather than a claim about where
     * a reader should meet it".
     *
     * Chrome's tagged export settles it. On all five shapes of
     * `SM-tag-outoflow.html`, including a `fixed` box and a box written first
     * and painted last, it reads alpha, beta, gamma: **where the box was
     * written, every time**. {@see HtmlBuilder::sourceOrderChildren()} is the
     * same rule for paint order, taken from the same number, and round 43
     * built it.
     *
     * A box `partition()` did not number keeps its stored place, which is what
     * a zero means: an anonymous box of text, a drop cap or a list marker, and
     * none of them was ever held back.
     *
     * @return list<Node>
     */
    private static function documentOrderChildren(Node $n): array
    {
        $ordered = [];
        $held    = [];

        foreach ([...$n->children, ...$n->positioned] as $child) {
            if ($child->isOutOfFlow() && $child->sourceOrder > 0) {
                $held[] = $child;

                continue;
            }

            $ordered[] = $child;
        }

        // Backwards, so two children wanting the same place keep the order
        // they were written in.
        foreach (array_reverse($held) as $child) {
            $at = count($ordered);

            foreach ($ordered as $index => $other) {
                if ($other->sourceOrder > $child->sourceOrder) {
                    $at = $index;

                    break;
                }
            }

            array_splice($ordered, $at, 0, [$child]);
        }

        return $ordered;
    }
}
