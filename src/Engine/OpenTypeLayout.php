<?php

declare(strict_types=1);

namespace FlexPDF\Engine;

/**
 * The `GSUB`, `GPOS` and legacy `kern` tables, read as one shaping pass.
 *
 * A face carries substitution rules (a ligature, a tabular figure, an old-style
 * figure) and positioning rules (kerning). CSS reaches them through
 * `font-kerning`, `font-variant-ligatures`, `font-variant-numeric` and
 * `font-feature-settings`, and all four end up here as a set of feature tags
 * that are on or off.
 *
 * **What is read and what is not.** GSUB lookup types 1 (single), 2 (multiple),
 * 3 (alternate), 4 (ligature), 5 (context), 6 (chained context) and 7
 * (extension) are applied, which is the whole of GSUB. GPOS types 1 (single),
 * 2 (pair), 4 (mark to base), 5 (mark to ligature), 6 (mark to mark) and 9
 * (extension) are; 3 (cursive) and 7 and 8 (contextual positioning) are not.
 *
 * **A positioning value record carries an advance and a PLACEMENT, and both are
 * read now.** Types 4, 5 and 6 are the whole reason: a combining mark has no
 * advance of its own, so an engine that reads advances alone draws it wherever
 * the mark glyph's own outline happens to sit, which is right for a lowercase
 * base and about a fifth of an em too low for a capital. Defect GT. A placement
 * costs two `TJ` elements sideways and a `Ts` vertically, both of them per mark
 * rather than per glyph.
 *
 * **The features that name those lookups are `mark` and `mkmk`**, and they are
 * on by default in every shaper, which is why {@see DEFAULT_FEATURES} carries
 * them: DejaVu Sans registers all six of its mark-to-base lookups under `mark`
 * and all five mark-to-mark ones under `mkmk`, so a default set without them
 * reaches none of this however much of it is implemented.
 *
 * **A face is a file the document names**, through `@font-face` or through the
 * registry, so every offset below comes from author input and every loop is
 * bounded by the table's own length or by a ceiling here.
 */
final class OpenTypeLayout
{
    /**
     * The features a browser turns on for horizontal Latin text with no CSS
     * asking for anything, which is what `font-kerning: auto` and
     * `font-variant-ligatures: normal` resolve to.
     *
     * @var array<string,int>
     */
    public const array DEFAULT_FEATURES = [
        'calt' => 1,
        'ccmp' => 1,
        'clig' => 1,
        'kern' => 1,
        'liga' => 1,
        'locl' => 1,
        'mark' => 1,
        'mkmk' => 1,
        'rlig' => 1,
    ];

    /** {@see DEFAULT_FEATURES} as a run carries it, so the constructor default costs no work. */
    public const string DEFAULT_KEY = 'calt=1,ccmp=1,clig=1,kern=1,liga=1,locl=1,mark=1,mkmk=1,rlig=1';

    /**
     * A feature set as one sorted string, which is what a run carries and what
     * every cache here is keyed by.
     *
     * @param array<string,int> $features
     */
    public static function describe(array $features): string
    {
        ksort($features);
        $parts = [];

        foreach ($features as $tag => $value) {
            $parts[] = $tag . '=' . $value;
        }

        return implode(',', $parts);
    }

    /** A coverage or class table cannot describe more glyphs than a face has. */
    private const int MAX_GLYPH_ID = 0x10000;

    /** A face claiming more lookups than this is spending time rather than describing glyphs. */
    private const int MAX_LOOKUPS = 512;

    /** A mark attachment subtable declaring more classes than this is not describing marks. */
    private const int MAX_MARK_CLASSES = 512;

    /** A ligature with more components than this is not a ligature. */
    private const int MAX_LIG_COMPONENTS = 64;

    /**
     * One glyph's advance in font units, which mark attachment needs and no
     * other lookup does: a placement is measured from the pen and the pen has
     * already moved past the base. It comes in from the face rather than being
     * read here, because `hmtx` is the one table this class does not own.
     *
     * @var callable(int): int
     */
    private $advanceOf;

    /** @var array<string,array{offset:int,length:int}> */
    private array $tables;

    private string $data;

    /** @var array<string,list<array{type:int,flag:int,subs:list<int>}>> feature key => GSUB lookups */
    private array $gsubPlan = [];

    /** @var array<string,list<array{type:int,flag:int,subs:list<int>}>> feature key => GPOS lookups */
    private array $gposPlan = [];

    /** @var array<int,array<int,int>> table offset => gid => coverage index */
    private array $coverages = [];

    /** @var array<int,array<int,int>> table offset => gid => class */
    private array $classes = [];

    /** @var array<int,array<int,int>> subtable offset => gid => substitute */
    private array $singles = [];

    /** @var array<int,array<int,list<array{0:list<int>,1:int}>>> subtable offset => first gid => components and result */
    private array $ligatures = [];

    /** @var array<int,array<int,array{0:int,1:int}>> pair set offset => second gid => the two x advances */
    private array $pairSets = [];

    /**
     * Context subtable offset => the glyphs it can possibly start on.
     *
     * A contextual rule set is rebuilt from the file every time it is asked,
     * which is the right shape for something consulted rarely and the wrong one
     * for something consulted per glyph per word. DejaVu Sans writes `ccmp` as
     * two chained-context lookups with eleven subtables between them, none of
     * which any Latin letter is covered by, so this gate is what turns eleven
     * rule rebuilds per glyph into eleven array lookups.
     *
     * @var array<int,array<int,int>>
     */
    private array $contextGates = [];

    /** @var array<int,array{format:int,cov:array<int,int>,format1:int,format2:int,size1:int,size2:int}> */
    private array $pairHeads = [];

    /** @var array<int,array{count1:int,count2:int,def1:array<int,int>,def2:array<int,int>}> */
    private array $pairClasses = [];

    /** @var array<string,array<int,true>> feature key => every glyph a GSUB lookup could start on */
    private array $gsubGates = [];

    /** @var array<string,array<int,true>> feature key => every glyph a GPOS lookup could start on */
    private array $gposGates = [];

    /** @var array<int,int>|null gid => GDEF glyph class */
    private ?array $glyphClasses = null;

    /** @var array<int,int>|null left << 16 | right => value, from the legacy table */
    private ?array $legacyKern = null;

    /** @var list<string>|null */
    private ?array $available = null;

    /** @param array<string,array{offset:int,length:int}> $tables */
    /** @param (callable(int): int)|null $advanceOf one glyph's advance in font units */
    public function __construct(string $data, array $tables, ?callable $advanceOf = null)
    {
        $this->data      = $data;
        $this->tables    = $tables;
        $this->end       = strlen($data);
        $this->advanceOf = $advanceOf ?? static fn(int $gid): int => 0;
    }

    /** Whether this face carries anything a shaping pass could apply. */
    public function isEmpty(): bool
    {
        return !isset($this->tables['GSUB'])
            && !isset($this->tables['GPOS'])
            && !isset($this->tables['kern']);
    }

    /**
     * Every feature tag the face registers, which is what says whether a probe
     * measures the property or the font.
     *
     * @return list<string>
     */
    public function availableTags(): array
    {
        if ($this->available !== null) {
            return $this->available;
        }

        $tags = [];

        foreach (['GSUB', 'GPOS'] as $which) {
            foreach ($this->featureRecords($which, true) as $tag => $_) {
                $tags[$tag] = true;
            }
        }

        if (isset($this->tables['kern'])) {
            $tags['kern'] = true;
        }

        $out = array_keys($tags);
        sort($out);

        return $this->available = $out;
    }

    // ---------------------------------------------------------------
    // bounds-checked readers
    // ---------------------------------------------------------------
    private int $end;

    /**
     * Reading a 16-bit field is the innermost thing this class does, several
     * times per glyph per lookup per word, so it is `ord()` arithmetic rather
     * than `substr()` into `unpack()`: the pair allocates a string and an array
     * for every field and costs about four times as much. A field past the end
     * of the file reads as zero, which is what makes a truncated face produce
     * unshaped text instead of an exception.
     */
    private function u16(int $o): int
    {
        return $o >= 0 && $o + 2 <= $this->end
            ? (ord($this->data[$o]) << 8) | ord($this->data[$o + 1])
            : 0;
    }

    private function s16(int $o): int
    {
        $v = $this->u16($o);

        return $v >= 0x8000 ? $v - 0x10000 : $v;
    }

    private function u32(int $o): int
    {
        return $o >= 0 && $o + 4 <= $this->end
            ? (ord($this->data[$o]) << 24) | (ord($this->data[$o + 1]) << 16)
                | (ord($this->data[$o + 2]) << 8) | ord($this->data[$o + 3])
            : 0;
    }

    // ---------------------------------------------------------------
    // the feature and lookup lists
    // ---------------------------------------------------------------
    /**
     * Feature tag => the lookup indices it names, for the script this engine
     * lays text out in.
     *
     * **One script, not the union of the plausible ones, and which one depends
     * on the text.** A shaper picks the script the run is written in and reads
     * that script's default language system alone. A face may register the same
     * tag twice with different lookups: DejaVu Sans gives `DFLT` a `dlig` that
     * turns `!?` into an interrobang and gives `latn` a `dlig` that only joins
     * `st`. Taking both applies rules to Latin text that no browser applies,
     * and taking `latn` for a run with no letters in it misses the ones a
     * browser does apply, because punctuation and digits have no script of
     * their own and fall to `DFLT`.
     *
     * Whichever is preferred, the other is the fallback, and a face with
     * neither falls back to its first script, which is what makes a
     * single-script face work at all.
     *
     * @return array<string,list<int>>
     */
    private function featureRecords(string $which, bool $latin): array
    {
        $table = $this->tables[$which] ?? null;

        if ($table === null) {
            return [];
        }

        $base        = $table['offset'];
        $scriptList  = $base + $this->u16($base + 4);
        $featureList = $base + $this->u16($base + 6);

        $wanted = $this->featureIndices($scriptList, $latin);

        $count = $this->u16($featureList);
        $out   = [];

        for ($i = 0; $i < $count; $i++) {
            if ($wanted !== null && !isset($wanted[$i])) {
                continue;
            }

            $rec = $featureList + 2 + $i * 6;
            $tag = substr($this->data, $rec, 4);

            if (strlen($tag) !== 4) {
                continue;
            }

            $feature      = $featureList + $this->u16($rec + 4);
            $lookupCount  = $this->u16($feature + 2);
            $lookupCount  = min($lookupCount, self::MAX_LOOKUPS);
            $indices      = [];

            for ($k = 0; $k < $lookupCount; $k++) {
                $indices[] = $this->u16($feature + 4 + $k * 2);
            }

            $out[$tag] = array_merge($out[$tag] ?? [], $indices);
        }

        return $out;
    }

    /**
     * The feature indices the chosen script's default language system names, or
     * null where the face registers no script at all and every feature counts.
     *
     * @return array<int,true>|null
     */
    private function featureIndices(int $scriptList, bool $latin): ?array
    {
        $count = $this->u16($scriptList);

        if ($count === 0) {
            return null;
        }

        $found = [];
        $first = null;

        for ($i = 0; $i < $count; $i++) {
            $rec    = $scriptList + 2 + $i * 6;
            $tag    = substr($this->data, $rec, 4);
            $script = $scriptList + $this->u16($rec + 4);

            $first ??= $script;

            if ($tag === 'latn' || $tag === 'DFLT') {
                $found[$tag] = $script;
            }
        }

        $script = $latin
            ? $found['latn'] ?? $found['DFLT'] ?? $first
            : $found['DFLT'] ?? $found['latn'] ?? $first;
        $default = $this->u16($script);

        if ($default === 0) {
            return null;
        }

        $langSys  = $script + $default;
        $required = $this->u16($langSys + 2);
        $out      = [];

        if ($required !== 0xFFFF) {
            $out[$required] = true;
        }

        $n = min($this->u16($langSys + 4), self::MAX_LOOKUPS);

        for ($k = 0; $k < $n; $k++) {
            $out[$this->u16($langSys + 6 + $k * 2)] = true;
        }

        return $out === [] ? null : $out;
    }

    /**
     * The lookups the enabled features name, in lookup-list order.
     *
     * Order is the list's and not the feature's: OpenType applies lookups by
     * index, so a ligature lookup registered under `liga` and a second one
     * under `dlig` run in the order the face wrote them rather than in the
     * order CSS named the features.
     *
     * @param  array<string,int>                                $features
     * @return list<array{type:int,flag:int,subs:list<int>}>
     */
    private function plan(string $which, array $features, bool $latin): array
    {
        $table = $this->tables[$which] ?? null;

        if ($table === null) {
            return [];
        }

        $base       = $table['offset'];
        $lookupList = $base + $this->u16($base + 8);
        $records    = $this->featureRecords($which, $latin);

        $wanted = [];

        foreach ($records as $tag => $indices) {
            if (($features[$tag] ?? 0) === 0) {
                continue;
            }

            foreach ($indices as $index) {
                $wanted[$index] = max($wanted[$index] ?? 0, $features[$tag]);
            }
        }

        if ($wanted === []) {
            return [];
        }

        ksort($wanted);

        $out = [];

        foreach ($wanted as $index => $_) {
            $lookup = $this->lookup($which, $index);

            if ($lookup !== null) {
                $out[] = $lookup;
            }
        }

        return $out;
    }

    /**
     * One lookup by its index in the table's own list.
     *
     * A contextual rule names the lookups it runs by index rather than by
     * feature, so reaching them has to be possible without a plan.
     *
     * @return array{type:int,flag:int,subs:list<int>}|null
     */
    private function lookup(string $which, int $index): ?array
    {
        $table = $this->tables[$which] ?? null;

        if ($table === null) {
            return null;
        }

        $base       = $table['offset'];
        $lookupList = $base + $this->u16($base + 8);

        if ($index < 0 || $index >= min($this->u16($lookupList), self::MAX_LOOKUPS)) {
            return null;
        }

        $lookup = $lookupList + $this->u16($lookupList + 2 + $index * 2);
        $n      = min($this->u16($lookup + 4), self::MAX_LOOKUPS);
        $subs   = [];

        for ($k = 0; $k < $n; $k++) {
            $subs[] = $lookup + $this->u16($lookup + 6 + $k * 2);
        }

        return ['type' => $this->u16($lookup), 'flag' => $this->u16($lookup + 2), 'subs' => $subs];
    }

    // ---------------------------------------------------------------
    // coverage, classes and the glyphs a lookup flag skips
    // ---------------------------------------------------------------
    /** @return array<int,int> gid => index into the lookup's own arrays */
    private function coverage(int $o): array
    {
        if (isset($this->coverages[$o])) {
            return $this->coverages[$o];
        }

        $map    = [];
        $format = $this->u16($o);

        if ($format === 1) {
            $count = min($this->u16($o + 2), self::MAX_GLYPH_ID);

            for ($i = 0; $i < $count; $i++) {
                $map[$this->u16($o + 4 + $i * 2)] = $i;
            }
        } elseif ($format === 2) {
            $count = min($this->u16($o + 2), self::MAX_GLYPH_ID);

            for ($i = 0; $i < $count; $i++) {
                $rec   = $o + 4 + $i * 6;
                $start = $this->u16($rec);
                $end   = $this->u16($rec + 2);
                $index = $this->u16($rec + 4);

                if ($end < $start || $end - $start >= self::MAX_GLYPH_ID) {
                    continue;
                }

                for ($g = $start; $g <= $end; $g++) {
                    $map[$g] = $index + ($g - $start);
                }
            }
        }

        return $this->coverages[$o] = $map;
    }

    /** @return array<int,int> gid => class, where an absent glyph is class 0 */
    private function classDef(int $o): array
    {
        if (isset($this->classes[$o])) {
            return $this->classes[$o];
        }

        $map    = [];
        $format = $this->u16($o);

        if ($format === 1) {
            $start = $this->u16($o + 2);
            $count = min($this->u16($o + 4), self::MAX_GLYPH_ID);

            for ($i = 0; $i < $count; $i++) {
                $map[$start + $i] = $this->u16($o + 6 + $i * 2);
            }
        } elseif ($format === 2) {
            $count = min($this->u16($o + 2), self::MAX_GLYPH_ID);

            for ($i = 0; $i < $count; $i++) {
                $rec   = $o + 4 + $i * 6;
                $start = $this->u16($rec);
                $end   = $this->u16($rec + 2);
                $class = $this->u16($rec + 4);

                if ($end < $start || $end - $start >= self::MAX_GLYPH_ID) {
                    continue;
                }

                for ($g = $start; $g <= $end; $g++) {
                    $map[$g] = $class;
                }
            }
        }

        return $this->classes[$o] = $map;
    }

    /** @return array<int,int> gid => 1 base, 2 ligature, 3 mark, 4 component */
    private function glyphClasses(): array
    {
        if ($this->glyphClasses !== null) {
            return $this->glyphClasses;
        }

        $gdef = $this->tables['GDEF'] ?? null;

        if ($gdef === null) {
            return $this->glyphClasses = [];
        }

        $offset = $this->u16($gdef['offset'] + 4);

        return $this->glyphClasses = $offset === 0 ? [] : $this->classDef($gdef['offset'] + $offset);
    }

    /**
     * Whether a lookup flag tells this lookup to step over the glyph.
     *
     * A kerning lookup that ignores marks has to kern the two letters either
     * side of an accent rather than the letter and the accent, so skipping is
     * part of matching rather than an optimization.
     */
    private function skips(int $gid, int $flag): bool
    {
        if (($flag & 0x000E) === 0) {
            return false;
        }

        $class = $this->glyphClasses()[$gid] ?? 0;

        return ($class === 1 && ($flag & 0x0002) !== 0)
            || ($class === 2 && ($flag & 0x0004) !== 0)
            || ($class === 3 && ($flag & 0x0008) !== 0);
    }

    // ---------------------------------------------------------------
    // substitution
    // ---------------------------------------------------------------
    /**
     * Run the substitution half over a glyph sequence.
     *
     * Each entry carries the codepoints it stands for, so a ligature that eats
     * three glyphs comes out as one glyph naming three characters and the
     * ToUnicode map stays able to give the author's text back.
     *
     * @param  list<array{0:int,1:list<int>}> $glyphs
     * @param  array<string,int>              $features
     * @return list<array{0:int,1:list<int>}>
     */
    public function substitute(array $glyphs, array $features, bool $latin): array
    {
        $key = ($latin ? 'latn|' : 'DFLT|') . self::describe($features);

        $plan = $this->gsubPlan[$key] ??= $this->plan('GSUB', $features, $latin);

        if (!$this->reaches($this->gsubGates[$key] ??= $this->startGlyphs('GSUB', $plan), $glyphs)) {
            return $glyphs;
        }

        foreach ($plan as $lookup) {
            $this->applySubstitution($glyphs, $lookup, 0);
        }

        return array_values($glyphs);
    }

    /**
     * Whether any glyph in the sequence is one an enabled lookup could start
     * on.
     *
     * **This is what makes shaping affordable on a document whose words are all
     * different.** DejaVu Sans registers `ccmp` under the Latin default set and
     * no Latin letter is in any of its eleven subtables' coverages, so a word
     * like `Amsterdam` cannot be touched by it, and the cheapest way to know
     * that is to ask once for the whole word instead of once per glyph per
     * subtable.
     *
     * @param array<int,true>                $starts
     * @param list<array{0:int,1:list<int>}> $glyphs
     */
    private function reaches(array $starts, array $glyphs): bool
    {
        foreach ($glyphs as [$gid]) {
            if (isset($starts[$gid])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Every glyph an enabled lookup could match on as its first input.
     *
     * @param  list<array{type:int,flag:int,subs:list<int>}> $plan
     * @return array<int,true>
     */
    private function startGlyphs(string $which, array $plan): array
    {
        $starts = [];

        foreach ($plan as $lookup) {
            foreach ($this->lookupCoverages($which, $lookup, 0) as $map) {
                foreach ($map as $gid => $_) {
                    $starts[$gid] = true;
                }
            }
        }

        return $starts;
    }

    /**
     * The coverage tables that decide whether a lookup can start on a glyph,
     * one per subtable, with an extension lookup resolved to what it wraps.
     *
     * @param  array{type:int,flag:int,subs:list<int>} $lookup
     * @return list<array<int,int>>
     */
    private function lookupCoverages(string $which, array $lookup, int $depth): array
    {
        $extension = $which === 'GSUB' ? 7 : 9;
        $type      = $lookup['type'];

        if ($type === $extension) {
            if ($depth >= self::MAX_NESTING) {
                return [];
            }

            $out = [];

            foreach ($lookup['subs'] as $o) {
                $inner = ['type' => $this->u16($o + 2), 'flag' => 0, 'subs' => [$o + $this->u32($o + 4)]];

                foreach ($this->lookupCoverages($which, $inner, $depth + 1) as $map) {
                    $out[] = $map;
                }
            }

            return $out;
        }

        $out = [];

        // **The context types are 5 and 6 in GSUB and 7 and 8 in GPOS**, and in
        // GPOS 5 and 6 are mark attachment instead, whose first offset is a
        // plain coverage. Reading a `MarkLigPos` subtable as a chained context
        // gate gives a start set with nothing to do with the lookup, which is
        // unreachable while `mark` and `mkmk` are off and wrong the moment they
        // are on.
        $context = $which === 'GSUB' ? [5, 6] : [7, 8];

        foreach ($lookup['subs'] as $o) {
            $out[] = in_array($type, $context, true)
                ? $this->contextGates[$o] ??= $this->contextGate($o, $type === 6 || $type === 8)
                : $this->coverage($o + $this->u16($o + 2));
        }

        return $out;
    }

    /**
     * One lookup over the whole sequence, left to right.
     *
     * @param list<array{0:int,1:list<int>}>              $glyphs
     * @param array{type:int,flag:int,subs:list<int>}     $lookup
     */
    private function applySubstitution(array &$glyphs, array $lookup, int $depth): void
    {
        for ($i = 0; $i < count($glyphs);) {
            $eaten = $this->substituteAt($glyphs, $i, $lookup, $depth);
            $i     += max(1, $eaten);
        }
    }

    /**
     * One lookup at one position, returning how many input glyphs it consumed
     * or zero where it did not match.
     *
     * A contextual rule runs other lookups at positions of its own choosing, so
     * every lookup has to be reachable one position at a time rather than only
     * as a sweep.
     *
     * @param list<array{0:int,1:list<int>}>          $glyphs
     * @param array{type:int,flag:int,subs:list<int>} $lookup
     */
    private function substituteAt(array &$glyphs, int $at, array $lookup, int $depth): int
    {
        $type = $lookup['type'];
        $flag = $lookup['flag'];

        if ($type === 7) {
            foreach ($lookup['subs'] as $o) {
                $eaten = $this->substituteAt(
                    $glyphs,
                    $at,
                    ['type' => $this->u16($o + 2), 'flag' => $flag, 'subs' => [$o + $this->u32($o + 4)]],
                    $depth,
                );

                if ($eaten > 0) {
                    return $eaten;
                }
            }

            return 0;
        }

        [$gid, $cps] = $glyphs[$at];

        if ($this->skips($gid, $flag)) {
            return 0;
        }

        foreach ($lookup['subs'] as $o) {
            if ($type === 5 || $type === 6) {
                $eaten = $this->context($glyphs, $at, $o, $flag, $type === 6, $depth);

                if ($eaten > 0) {
                    return $eaten;
                }

                continue;
            }

            if ($type === 4) {
                $result = $this->ligature($o, $glyphs, $at, $flag);

                if ($result === null) {
                    continue;
                }

                array_splice($glyphs, $at, $result[1], $result[0]);

                return count($result[0]);
            }

            $replacement = match ($type) {
                1       => $this->single($o, $gid),
                2       => $this->multiple($o, $gid),
                3       => $this->alternate($o, $gid),
                default => null,
            };

            if ($replacement === null) {
                continue;
            }

            // The characters the author wrote belong to the whole replacement,
            // so they ride on its first glyph: an `ffi` split into three pieces
            // still spells `ffi` when it is copied out.
            $pieces = [];

            foreach ($replacement as $index => $piece) {
                $pieces[] = [$piece, $index === 0 ? $cps : []];
            }

            array_splice($glyphs, $at, 1, $pieces);

            return count($pieces);
        }

        return 0;
    }

    /** A contextual rule may run another contextual rule, so the walk needs a floor. */
    private const int MAX_NESTING = 8;

    /**
     * A contextual or chained-contextual substitution at one position.
     *
     * The rule matches a run of glyphs around the position, with a backtrack
     * and a lookahead where it is chained, and then runs other lookups at
     * positions inside the match. That is what makes `calt`, `ordn` and a
     * modern face's `frac` work, and it is the only shape in GSUB whose effect
     * is decided by the neighbors rather than by the glyph.
     *
     * **A nested lookup that changes the glyph count ends the rule.** The
     * positions the match recorded no longer name the glyphs they did, and
     * recomputing them is what a full shaper does; stopping is the honest
     * simplification and it costs only the second and later records of a rule
     * whose first one ligated.
     *
     * @param list<array{0:int,1:list<int>}> $glyphs
     */
    private function context(array &$glyphs, int $at, int $o, int $flag, bool $chained, int $depth): int
    {
        if ($depth >= self::MAX_NESTING) {
            return 0;
        }

        $gid = $glyphs[$at][0];

        if (!isset(($this->contextGates[$o] ??= $this->contextGate($o, $chained))[$gid])) {
            return 0;
        }

        foreach ($this->contextRules($o, $gid, $chained) as [$back, $tail, $ahead, $records]) {
            $input = $this->matchRun($glyphs, $at + 1, 1, $tail, $flag);

            if ($input === null) {
                continue;
            }

            array_unshift($input, $at);

            if ($this->matchRun($glyphs, $at - 1, -1, $back, $flag) === null) {
                continue;
            }

            $after = $input[count($input) - 1] + 1;

            if ($this->matchRun($glyphs, $after, 1, $ahead, $flag) === null) {
                continue;
            }

            $before = count($glyphs);
            $span   = $after - $at;

            foreach ($records as [$sequenceIndex, $lookupIndex]) {
                $nested = $this->lookup('GSUB', $lookupIndex);

                if ($nested === null || !isset($input[$sequenceIndex])) {
                    continue;
                }

                $this->substituteAt($glyphs, $input[$sequenceIndex], $nested, $depth + 1);

                if (count($glyphs) !== $before) {
                    return max(1, $span + count($glyphs) - $before);
                }
            }

            return max(1, $span);
        }

        return 0;
    }

    /**
     * The coverage a context subtable's first input glyph must be in, which is
     * the subtable's own coverage in formats 1 and 2 and the first of its input
     * coverages in format 3.
     *
     * @return array<int,int>
     */
    private function contextGate(int $o, bool $chained): array
    {
        $format = $this->u16($o);

        if ($format === 1 || $format === 2) {
            return $this->coverage($o + $this->u16($o + 2));
        }

        if ($format !== 3) {
            return [];
        }

        return $chained
            ? $this->coverage($o + $this->u16($o + 6 + min($this->u16($o + 2), 64) * 2))
            : $this->coverage($o + $this->u16($o + 6));
    }

    /**
     * The rules a context subtable offers for a glyph, each as its backtrack,
     * the input after the first glyph, its lookahead and the lookups it runs.
     *
     * Format 1 keys the rule set off the glyph, format 2 off its class and
     * format 3 is one rule of coverage tables. A backtrack sequence is written
     * nearest-first, which is the order the walk reads it in, so it needs no
     * reversing here.
     *
     * @return list<array{0:list<array{0:int,1:int,2:array<int,int>}>,1:list<array{0:int,1:int,2:array<int,int>}>,2:list<array{0:int,1:int,2:array<int,int>}>,3:list<array{0:int,1:int}>}>
     */
    private function contextRules(int $o, int $gid, bool $chained): array
    {
        $format = $this->u16($o);

        if ($format === 3) {
            // Format 3 has no coverage of its own: the first input coverage is
            // both the gate and the first thing matched, so it is checked here
            // rather than left to the walk, which starts one glyph along.
            $first = $chained
                ? $this->coverage($o + $this->u16($o + 6 + min($this->u16($o + 2), 64) * 2))
                : $this->coverage($o + $this->u16($o + 6));

            if (!isset($first[$gid])) {
                return [];
            }

            return [$chained ? $this->chainedFormat3($o) : $this->plainFormat3($o)];
        }

        if ($format !== 1 && $format !== 2) {
            return [];
        }

        $index = $this->coverage($o + $this->u16($o + 2))[$gid] ?? null;

        if ($index === null) {
            return [];
        }

        $classes = [];

        if ($format === 2) {
            $classes = $chained
                ? [$this->classDef($o + $this->u16($o + 4)), $this->classDef($o + $this->u16($o + 6)), $this->classDef($o + $this->u16($o + 8))]
                : [[], $this->classDef($o + $this->u16($o + 4)), []];

            $index = $classes[1][$gid] ?? 0;
        }

        $setBase = $o + ($format === 1 ? 4 : ($chained ? 10 : 6));

        if ($index >= $this->u16($setBase)) {
            return [];
        }

        $setOffset = $this->u16($setBase + 2 + $index * 2);

        // A class with no rules writes a null offset rather than an empty set.
        if ($setOffset === 0) {
            return [];
        }

        $set   = $o + $setOffset;
        $count = min($this->u16($set), 256);
        $rules = [];

        for ($i = 0; $i < $count; $i++) {
            $rule    = $set + $this->u16($set + 2 + $i * 2);
            $rules[] = $chained
                ? $this->chainedRule($rule, $format, $classes)
                : $this->plainRule($rule, $format, $classes);
        }

        return $rules;
    }

    /**
     * @param  list<array<int,int>> $classes
     * @return array{0:list<array{0:int,1:int,2:array<int,int>}>,1:list<array{0:int,1:int,2:array<int,int>}>,2:list<array{0:int,1:int,2:array<int,int>}>,3:list<array{0:int,1:int}>}
     */
    private function plainRule(int $o, int $format, array $classes): array
    {
        $count   = min($this->u16($o), 64);
        $records = min($this->u16($o + 2), 64);
        $tail    = [];

        for ($i = 1; $i < $count; $i++) {
            $tail[] = [$format === 1 ? 0 : 1, $this->u16($o + 2 + $i * 2), $classes[1] ?? []];
        }

        return [[], $tail, [], $this->records($o + 2 + max(0, $count - 1) * 2 + 2, $records)];
    }

    /**
     * @param  list<array<int,int>> $classes
     * @return array{0:list<array{0:int,1:int,2:array<int,int>}>,1:list<array{0:int,1:int,2:array<int,int>}>,2:list<array{0:int,1:int,2:array<int,int>}>,3:list<array{0:int,1:int}>}
     */
    private function chainedRule(int $o, int $format, array $classes): array
    {
        $kind = $format === 1 ? 0 : 1;

        $backCount = min($this->u16($o), 64);
        $back      = [];

        for ($i = 0; $i < $backCount; $i++) {
            $back[] = [$kind, $this->u16($o + 2 + $i * 2), $classes[0] ?? []];
        }

        $p          = $o + 2 + $backCount * 2;
        $inputCount = min($this->u16($p), 64);
        $tail       = [];

        for ($i = 1; $i < $inputCount; $i++) {
            $tail[] = [$kind, $this->u16($p + $i * 2), $classes[1] ?? []];
        }

        $p          = $p + 2 + max(0, $inputCount - 1) * 2;
        $aheadCount = min($this->u16($p), 64);
        $ahead      = [];

        for ($i = 0; $i < $aheadCount; $i++) {
            $ahead[] = [$kind, $this->u16($p + 2 + $i * 2), $classes[2] ?? []];
        }

        $p = $p + 2 + $aheadCount * 2;

        return [$back, $tail, $ahead, $this->records($p + 2, min($this->u16($p), 64))];
    }

    /** @return array{0:list<array{0:int,1:int,2:array<int,int>}>,1:list<array{0:int,1:int,2:array<int,int>}>,2:list<array{0:int,1:int,2:array<int,int>}>,3:list<array{0:int,1:int}>} */
    private function plainFormat3(int $o): array
    {
        $count   = min($this->u16($o + 2), 64);
        $records = min($this->u16($o + 4), 64);
        $tail    = [];

        for ($i = 1; $i < $count; $i++) {
            $tail[] = [2, 0, $this->coverage($o + $this->u16($o + 6 + $i * 2))];
        }

        return [[], $tail, [], $this->records($o + 6 + $count * 2, $records)];
    }

    /** @return array{0:list<array{0:int,1:int,2:array<int,int>}>,1:list<array{0:int,1:int,2:array<int,int>}>,2:list<array{0:int,1:int,2:array<int,int>}>,3:list<array{0:int,1:int}>} */
    private function chainedFormat3(int $o): array
    {
        $backCount = min($this->u16($o + 2), 64);
        $back      = [];

        for ($i = 0; $i < $backCount; $i++) {
            $back[] = [2, 0, $this->coverage($o + $this->u16($o + 4 + $i * 2))];
        }

        $p          = $o + 4 + $backCount * 2;
        $inputCount = min($this->u16($p), 64);
        $tail       = [];

        for ($i = 1; $i < $inputCount; $i++) {
            $tail[] = [2, 0, $this->coverage($o + $this->u16($p + 2 + $i * 2))];
        }

        $p          = $p + 2 + $inputCount * 2;
        $aheadCount = min($this->u16($p), 64);
        $ahead      = [];

        for ($i = 0; $i < $aheadCount; $i++) {
            $ahead[] = [2, 0, $this->coverage($o + $this->u16($p + 2 + $i * 2))];
        }

        $p = $p + 2 + $aheadCount * 2;

        return [$back, $tail, $ahead, $this->records($p + 2, min($this->u16($p), 64))];
    }

    /** @return list<array{0:int,1:int}> sequence index and the lookup it runs there */
    private function records(int $o, int $count): array
    {
        $out = [];

        for ($i = 0; $i < $count; $i++) {
            $out[] = [$this->u16($o + $i * 4), $this->u16($o + $i * 4 + 2)];
        }

        return $out;
    }

    /**
     * Walk the sequence one way, matching each want and stepping over the
     * glyphs the lookup flag ignores.
     *
     * @param  list<array{0:int,1:list<int>}>            $glyphs
     * @param  list<array{0:int,1:int,2:array<int,int>}> $wants
     * @return list<int>|null
     */
    private function matchRun(array $glyphs, int $from, int $step, array $wants, int $flag): ?array
    {
        $positions = [];
        $at        = $from;
        $n         = count($glyphs);

        foreach ($wants as [$kind, $value, $map]) {
            while ($at >= 0 && $at < $n && $this->skips($glyphs[$at][0], $flag)) {
                $at += $step;
            }

            if ($at < 0 || $at >= $n) {
                return null;
            }

            $gid    = $glyphs[$at][0];
            $agrees = match ($kind) {
                0       => $gid === $value,
                1       => ($map[$gid] ?? 0) === $value,
                default => isset($map[$gid]),
            };

            if (!$agrees) {
                return null;
            }

            $positions[] = $at;
            $at          += $step;
        }

        return $positions;
    }

    /** @return list<int>|null */
    private function single(int $o, int $gid): ?array
    {
        if (!isset($this->singles[$o])) {
            $map      = [];
            $format   = $this->u16($o);
            $coverage = $this->coverage($o + $this->u16($o + 2));

            if ($format === 1) {
                $delta = $this->s16($o + 4);

                foreach ($coverage as $g => $_) {
                    $map[$g] = ($g + $delta) & 0xFFFF;
                }
            } elseif ($format === 2) {
                $count = $this->u16($o + 4);

                foreach ($coverage as $g => $index) {
                    if ($index < $count) {
                        $map[$g] = $this->u16($o + 6 + $index * 2);
                    }
                }
            }

            $this->singles[$o] = $map;
        }

        $to = $this->singles[$o][$gid] ?? null;

        return $to === null ? null : [$to];
    }

    /** @return list<int>|null */
    private function multiple(int $o, int $gid): ?array
    {
        if ($this->u16($o) !== 1) {
            return null;
        }

        $index = $this->coverage($o + $this->u16($o + 2))[$gid] ?? null;

        if ($index === null || $index >= $this->u16($o + 4)) {
            return null;
        }

        $sequence = $o + $this->u16($o + 6 + $index * 2);
        $count    = min($this->u16($sequence), 64);

        if ($count === 0) {
            return null;
        }

        $pieces = [];

        for ($i = 0; $i < $count; $i++) {
            $pieces[] = $this->u16($sequence + 2 + $i * 2);
        }

        return $pieces;
    }

    /**
     * The first alternate a face offers, which is what `salt` and `aalt` mean
     * with no index beside them. `font-feature-settings: 'salt' 2` asking for
     * the second is not read: the value reaches the plan and stops there.
     *
     * @return list<int>|null
     */
    private function alternate(int $o, int $gid): ?array
    {
        if ($this->u16($o) !== 1) {
            return null;
        }

        $index = $this->coverage($o + $this->u16($o + 2))[$gid] ?? null;

        if ($index === null || $index >= $this->u16($o + 4)) {
            return null;
        }

        $set = $o + $this->u16($o + 6 + $index * 2);

        if ($this->u16($set) === 0) {
            return null;
        }

        return [$this->u16($set + 2)];
    }

    /**
     * @param  list<array{0:int,1:list<int>}>                 $glyphs
     * @return array{0:list<array{0:int,1:list<int>}>,1:int}|null
     */
    private function ligature(int $o, array $glyphs, int $at, int $flag): ?array
    {
        $gid = $glyphs[$at][0];

        if (!isset($this->ligatures[$o])) {
            $sets = [];

            if ($this->u16($o) === 1) {
                $coverage = $this->coverage($o + $this->u16($o + 2));
                $count    = $this->u16($o + 4);

                foreach ($coverage as $g => $index) {
                    if ($index >= $count) {
                        continue;
                    }

                    $set     = $o + $this->u16($o + 6 + $index * 2);
                    $ligs    = min($this->u16($set), 256);
                    $entries = [];

                    for ($i = 0; $i < $ligs; $i++) {
                        $lig   = $set + $this->u16($set + 2 + $i * 2);
                        $comps = min($this->u16($lig + 2), 32);

                        if ($comps < 1) {
                            continue;
                        }

                        $tail = [];

                        for ($k = 1; $k < $comps; $k++) {
                            $tail[] = $this->u16($lig + 2 + $k * 2);
                        }

                        $entries[] = [$tail, $this->u16($lig)];
                    }

                    $sets[$g] = $entries;
                }
            }

            $this->ligatures[$o] = $sets;
        }

        $entries = $this->ligatures[$o][$gid] ?? null;

        if ($entries === null) {
            return null;
        }

        $n = count($glyphs);

        foreach ($entries as [$tail, $result]) {
            $positions = [$at];
            $cursor    = $at + 1;
            $matched   = true;

            foreach ($tail as $want) {
                while ($cursor < $n && $this->skips($glyphs[$cursor][0], $flag)) {
                    $cursor++;
                }

                if ($cursor >= $n || $glyphs[$cursor][0] !== $want) {
                    $matched = false;

                    break;
                }

                $positions[] = $cursor;
                $cursor++;
            }

            if (!$matched) {
                continue;
            }

            $cps = [];

            foreach ($positions as $p) {
                foreach ($glyphs[$p][1] as $cp) {
                    $cps[] = $cp;
                }
            }

            $pieces = [[$result, $cps]];

            // Anything the flag told the match to step over sits between the
            // components and survives the substitution, in the order it was in.
            for ($p = $at + 1; $p < $cursor; $p++) {
                if (!in_array($p, $positions, true)) {
                    $pieces[] = $glyphs[$p];
                }
            }

            return [$pieces, $cursor - $at];
        }

        return null;
    }

    // ---------------------------------------------------------------
    // positioning
    // ---------------------------------------------------------------
    /**
     * The x advance adjustment for each glyph and the placement it is drawn at,
     * both in font units.
     *
     * An advance moves the pen and every glyph after it; a placement moves this
     * glyph alone and leaves the pen where it was. A combining mark is the case
     * that needs the second one, and it is the only case here that does.
     *
     * @param  list<int>         $gids
     * @param  array<string,int> $features
     * @return list<array{0:int,1:int,2:int}> x advance, x placement, y placement
     */
    public function adjust(array $gids, array $features, bool $latin): array
    {
        $out   = array_fill(0, count($gids), 0);
        $place = array_fill(0, count($gids), [0, 0]);
        $key   = ($latin ? 'latn|' : 'DFLT|') . self::describe($features);
        $plan  = $this->gposPlan[$key] ??= $this->plan('GPOS', $features, $latin);

        $starts    = $this->gposGates[$key] ??= $this->startGlyphs('GPOS', $plan);
        $reachable = false;

        foreach ($gids as $gid) {
            if (isset($starts[$gid])) {
                $reachable = true;

                break;
            }
        }

        foreach ($reachable ? $plan : [] as $lookup) {
            $this->applyPositioning($out, $place, $gids, $lookup['type'], $lookup['flag'], $lookup['subs']);
        }

        // The legacy table is the same kerning written twice on a face that
        // carries both, so it is only read where GPOS registers no `kern`.
        //
        // **That is the FEATURE and not the plan.** Reading it as "the plan is
        // empty" held only while every feature in the default set was a GSUB
        // one: adding `mark` and `mkmk` for defect GT put a lookup in every GPOS
        // plan, which silently stopped `Times New Roman Bold` kerning at all,
        // 907 font units on `WAVAT` alone. The shape sweep is what caught it.
        $legacy = ($features['kern'] ?? 0) !== 0
            && isset($this->tables['kern'])
            && !isset($this->featureRecords('GPOS', $latin)['kern']);

        if ($legacy) {
            $this->applyLegacyKern($out, $gids);
        }

        $joined = [];

        foreach ($out as $i => $advance) {
            $joined[] = [$advance, $place[$i][0], $place[$i][1]];
        }

        return $joined;
    }

    /**
     * @param list<int>               $out
     * @param list<array{0:int,1:int}> $place
     * @param list<int>               $gids
     * @param list<int>               $subtables
     */
    private function applyPositioning(
        array &$out,
        array &$place,
        array $gids,
        int $type,
        int $flag,
        array $subtables,
    ): void {
        if ($type === 9) {
            foreach ($subtables as $o) {
                $real = $this->u16($o + 2);
                $this->applyPositioning($out, $place, $gids, $real, $flag, [$o + $this->u32($o + 4)]);
            }

            return;
        }

        if ($type === 1) {
            $this->applySinglePositioning($out, $gids, $flag, $subtables);

            return;
        }

        if ($type === 4 || $type === 5 || $type === 6) {
            $this->applyMarkAttachment($out, $place, $gids, $type, $flag, $subtables);

            return;
        }

        if ($type !== 2) {
            return;
        }

        $n = count($gids);

        for ($i = 0; $i < $n;) {
            if ($this->skips($gids[$i], $flag)) {
                $i++;

                continue;
            }

            $j = $i + 1;

            while ($j < $n && $this->skips($gids[$j], $flag)) {
                $j++;
            }

            if ($j >= $n) {
                break;
            }

            $pair = null;

            foreach ($subtables as $o) {
                $pair = $this->pair($o, $gids[$i], $gids[$j]);

                if ($pair !== null) {
                    break;
                }
            }

            if ($pair === null) {
                $i = $j;

                continue;
            }

            $out[$i] += $pair[0];
            $out[$j] += $pair[1];

            // A subtable that adjusts the second glyph has consumed it, so the
            // next pair starts after it; one that adjusts only the first leaves
            // it free to be the opening glyph of the next pair, which is what
            // kerns both halves of `AVA`.
            $i = $pair[1] !== 0 || $pair[2] ? $j + 1 : $j;
        }
    }

    /**
     * @param list<int> $out
     * @param list<int> $gids
     * @param list<int> $subtables
     */
    private function applySinglePositioning(array &$out, array $gids, int $flag, array $subtables): void
    {
        foreach ($subtables as $o) {
            $format   = $this->u16($o);
            $coverage = $this->coverage($o + $this->u16($o + 2));
            $valueFormat = $this->u16($o + 4);

            if (($valueFormat & 0x0004) === 0) {
                continue;
            }

            foreach ($gids as $i => $gid) {
                $index = $coverage[$gid] ?? null;

                if ($index === null || $this->skips($gid, $flag)) {
                    continue;
                }

                $out[$i] += $format === 1
                    ? $this->xAdvance($o + 6, $valueFormat)
                    : $this->xAdvance($o + 8 + $index * self::valueSize($valueFormat), $valueFormat);
            }
        }
    }

    /**
     * GPOS types 4, 5 and 6: a mark drawn against an anchor on the glyph before
     * it rather than after that glyph's advance.
     *
     * All three subtables are the same shape. A `MarkArray` gives every mark a
     * CLASS and an anchor of its own; the second array gives every base one
     * anchor per class, and the two anchors are made to coincide. Type 5's
     * second array has a further level, one anchor set per component of the
     * ligature, and a mark that carries no component of its own takes the LAST
     * one, which is what a shaper does with a mark written after a whole
     * ligature.
     *
     * **The placement is relative to the pen and the pen has already advanced**,
     * so the base's advance and everything between it and the mark is subtracted
     * from the horizontal half. The vertical half is the anchor difference
     * alone, because a text line has no vertical pen.
     *
     * The base is the nearest preceding glyph the lookup flag does not skip, and
     * for type 6 it is the nearest preceding MARK instead, which is the whole
     * difference between the two: `e` with an acute and a diaeresis stacks the
     * second on the first.
     *
     * @param list<int>                $out
     * @param list<array{0:int,1:int}> $place
     * @param list<int>                $gids
     * @param list<int>                $subtables
     */
    private function applyMarkAttachment(
        array &$out,
        array &$place,
        array $gids,
        int $type,
        int $flag,
        array $subtables,
    ): void {
        $classes = $this->glyphClasses();

        foreach ($subtables as $o) {
            if ($this->u16($o) !== 1) {
                continue;
            }

            $marks      = $this->coverage($o + $this->u16($o + 2));
            $bases      = $this->coverage($o + $this->u16($o + 4));
            $classCount = $this->u16($o + 6);
            $markArray  = $o + $this->u16($o + 8);
            $baseArray  = $o + $this->u16($o + 10);

            if ($classCount <= 0 || $classCount > self::MAX_MARK_CLASSES) {
                continue;
            }

            foreach ($gids as $i => $gid) {
                $markIndex = $marks[$gid] ?? null;

                if ($markIndex === null || $markIndex >= $this->u16($markArray)) {
                    continue;
                }

                $record     = $markArray + 2 + $markIndex * 4;
                $markClass  = $this->u16($record);
                $markAnchor = $this->anchor($markArray + $this->u16($record + 2));

                if ($markClass >= $classCount) {
                    continue;
                }

                $j     = $i;
                $shift = 0;

                while (--$j >= 0) {
                    $isMark = ($classes[$gids[$j]] ?? 0) === 3;

                    if ($type === 6 ? $isMark : !$isMark && !$this->skips($gids[$j], $flag)) {
                        break;
                    }

                    $shift += ($this->advanceOf)($gids[$j]) + $out[$j];
                }

                if ($j < 0) {
                    continue;
                }

                $baseIndex = $bases[$gids[$j]] ?? null;

                if ($baseIndex === null || $baseIndex >= $this->u16($baseArray)) {
                    continue;
                }

                $shift += ($this->advanceOf)($gids[$j]) + $out[$j];

                // An anchor offset is measured from the table that holds the
                // array, and for a ligature that is the `LigatureAttach` rather
                // than the `LigatureArray` above it.
                [$anchors, $from] = $type === 5
                    ? ($this->ligatureAnchors($baseArray, $baseIndex, $classCount) ?? [null, 0])
                    : [$baseArray + 2 + $baseIndex * $classCount * 2, $baseArray];

                if ($anchors === null) {
                    continue;
                }

                $offset = $this->u16($anchors + $markClass * 2);

                if ($offset === 0) {
                    continue;
                }

                $baseAnchor = $this->anchor($from + $offset);
                $place[$i]  = [
                    $baseAnchor[0] - $markAnchor[0] - $shift,
                    $baseAnchor[1] - $markAnchor[1],
                ];
            }
        }
    }

    /**
     * The anchor set for a ligature's last component and the table its offsets
     * are measured from, which is where a mark written after the whole ligature
     * goes.
     *
     * The engine does not track which component of a ligature a mark belongs to,
     * because `substitute()` returns one glyph naming several characters rather
     * than a component index. A shaper falls back to the last component in
     * exactly that case, so this is the fallback rather than a simplification.
     *
     * @return array{0:int,1:int}|null
     */
    private function ligatureAnchors(int $ligatureArray, int $index, int $classCount): ?array
    {
        if ($index >= $this->u16($ligatureArray)) {
            return null;
        }

        $attach     = $ligatureArray + $this->u16($ligatureArray + 2 + $index * 2);
        $components = $this->u16($attach);

        if ($components <= 0 || $components > self::MAX_LIG_COMPONENTS) {
            return null;
        }

        return [$attach + 2 + ($components - 1) * $classCount * 2, $attach];
    }

    /**
     * An anchor's x and y in font units. All three formats carry them in the
     * same two fields; format 2's contour point and format 3's device tables are
     * hinting for a specific pixel size and a PDF has no pixel size.
     *
     * @return array{0:int,1:int}
     */
    private function anchor(int $o): array
    {
        return [$this->s16($o + 2), $this->s16($o + 4)];
    }

    /**
     * The two x advances a pair positioning subtable gives a glyph pair, plus
     * whether it declared a second value record at all.
     *
     * @return array{0:int,1:int,2:bool}|null
     */
    private function pair(int $o, int $first, int $second): ?array
    {
        // The header is eight fixed fields and a coverage table, and a pair
        // lookup is asked about every adjacent pair of every word in the
        // document, so it is read once per subtable rather than once per pair.
        $head = $this->pairHeads[$o] ??= [
            'format'  => $this->u16($o),
            'cov'     => $this->coverage($o + $this->u16($o + 2)),
            'format1' => $this->u16($o + 4),
            'format2' => $this->u16($o + 6),
            'size1'   => self::valueSize($this->u16($o + 4)),
            'size2'   => self::valueSize($this->u16($o + 6)),
        ];

        $index = $head['cov'][$first] ?? null;

        if ($index === null) {
            return null;
        }

        $format  = $head['format'];
        $format1 = $head['format1'];
        $format2 = $head['format2'];
        $size1   = $head['size1'];
        $size2   = $head['size2'];

        if ($format === 1) {
            if ($index >= $this->u16($o + 8)) {
                return null;
            }

            $set = $o + $this->u16($o + 10 + $index * 2);

            if (!isset($this->pairSets[$set])) {
                $count = min($this->u16($set), self::MAX_GLYPH_ID);
                $map   = [];

                for ($i = 0; $i < $count; $i++) {
                    $rec        = $set + 2 + $i * (2 + $size1 + $size2);
                    $map[$this->u16($rec)] = [
                        $this->xAdvance($rec + 2, $format1),
                        $this->xAdvance($rec + 2 + $size1, $format2),
                    ];
                }

                $this->pairSets[$set] = $map;
            }

            $found = $this->pairSets[$set][$second] ?? null;

            return $found === null ? null : [$found[0], $found[1], $format2 !== 0];
        }

        if ($format !== 2) {
            return null;
        }

        $classes = $this->pairClasses[$o] ??= [
            'count1' => $this->u16($o + 12),
            'count2' => $this->u16($o + 14),
            'def1'   => $this->classDef($o + $this->u16($o + 8)),
            'def2'   => $this->classDef($o + $this->u16($o + 10)),
        ];

        $class1Count = $classes['count1'];
        $class2Count = $classes['count2'];
        $c1          = $classes['def1'][$first] ?? 0;
        $c2          = $classes['def2'][$second] ?? 0;

        if ($c1 >= $class1Count || $c2 >= $class2Count) {
            return null;
        }

        $record = $o + 16 + ($c1 * $class2Count + $c2) * ($size1 + $size2);

        return [
            $this->xAdvance($record, $format1),
            $this->xAdvance($record + $size1, $format2),
            $format2 !== 0,
        ];
    }

    /** How many bytes a value record with this format occupies. */
    private static function valueSize(int $format): int
    {
        $size = 0;

        for ($bit = 1; $bit <= 0x8000; $bit <<= 1) {
            if (($format & $bit) !== 0) {
                $size += 2;
            }
        }

        return $size;
    }

    /** A value record's x advance, which is the only field a PDF text run can carry. */
    private function xAdvance(int $o, int $format): int
    {
        if (($format & 0x0004) === 0) {
            return 0;
        }

        $skip = 0;

        foreach ([0x0001, 0x0002] as $bit) {
            if (($format & $bit) !== 0) {
                $skip += 2;
            }
        }

        return $this->s16($o + $skip);
    }

    /**
     * The legacy `kern` table, format 0, which is the only format a Windows
     * face writes and the one this engine reads.
     *
     * @param list<int> $out
     * @param list<int> $gids
     */
    private function applyLegacyKern(array &$out, array $gids): void
    {
        if ($this->legacyKern === null) {
            $this->legacyKern = $this->parseLegacyKern();
        }

        if ($this->legacyKern === []) {
            return;
        }

        for ($i = 0, $n = count($gids) - 1; $i < $n; $i++) {
            $out[$i] += $this->legacyKern[($gids[$i] << 16) | $gids[$i + 1]] ?? 0;
        }
    }

    /** @return array<int,int> */
    private function parseLegacyKern(): array
    {
        $table = $this->tables['kern'] ?? null;

        if ($table === null) {
            return [];
        }

        $base = $table['offset'];
        $end  = $base + $table['length'];

        if ($this->u16($base) !== 0) {
            return [];
        }

        $subtables = min($this->u16($base + 2), 64);
        $o         = $base + 4;
        $map       = [];

        for ($t = 0; $t < $subtables && $o < $end; $t++) {
            $length   = $this->u16($o + 2);
            $coverage = $this->u16($o + 4);

            if ($length < 14) {
                break;
            }

            $horizontal = ($coverage & 0x0001) !== 0;
            $minimum    = ($coverage & 0x0002) !== 0;
            $format     = ($coverage >> 8) & 0xFF;

            if ($horizontal && !$minimum && $format === 0) {
                $pairs = min($this->u16($o + 6), self::MAX_GLYPH_ID);

                for ($i = 0; $i < $pairs; $i++) {
                    $rec = $o + 14 + $i * 6;

                    if ($rec + 6 > $end) {
                        break;
                    }

                    $map[($this->u16($rec) << 16) | $this->u16($rec + 2)] = $this->s16($rec + 4);
                }
            }

            $o += $length;
        }

        return $map;
    }

}
