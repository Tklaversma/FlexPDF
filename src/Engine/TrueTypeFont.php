<?php

declare(strict_types=1);

namespace FlexPDF\Engine;

use RuntimeException;

/**
 * A real TrueType face: parses the tables needed for layout, maps Unicode to
 * glyph IDs, and can emit a subset suitable for embedding in a PDF.
 *
 * Exposes the same measurement surface as Font (the base-14 face), so the
 * inline formatter doesn't care which kind it is given.
 */
final class TrueTypeFont
{
    private string $data;
    /** @var array<string,array{offset:int,length:int}> */
    private array $tables = [];

    public int  $unitsPerEm       = 1000;
    public int  $numGlyphs        = 0;
    private int $indexToLocFormat = 0;
    private int $numberOfHMetrics = 0;

    public int   $typoAscent  = 0;
    public int   $typoDescent = 0;
    public int   $lineGap     = 0;
    public int   $capHeight   = 0;

    /**
     * `hhea`'s own ascender and descender, kept apart from the pair above.
     *
     * `parseOs2()` overwrites `typoAscent` and `typoDescent` with OS/2's
     * `sTypoAscender` and `sTypoDescender`, which are the right numbers for
     * placing glyphs and the wrong ones for a line box: **a browser derives
     * `line-height: normal` from `hhea`.** On DejaVu Sans the two disagree by
     * 16 percent, 1556/-492 against 1901/-483, and the sTypo pair sums to
     * exactly one em, which is what made a `line-height: normal` line box come
     * out 1.000 em where Chrome lays out 1.164. Measured on
     * `RD-normal-lineheight-sweep.html`.
     */
    public int $hheaAscent = 0;

    public int $hheaDescent = 0;

    /** OS/2's `sxHeight`, which only exists from version 2. Zero means absent. */
    public int   $sxHeight    = 0;
    public array $bbox        = [0, 0, 1000, 1000];
    public int   $italicAngle = 0;
    public int   $stemV       = 80;
    public int   $flags       = 32;

    /** @var array<int,int>|null codepoint => glyph id */
    private ?array $cmap = null;

    /** @var array<int,int> glyph id => advance width in font units */
    private array $advances = [];

    /** @var array<int,int> */
    private array $loca = [];

    /** Glyphs actually used, so we can subset. glyph id => true */
    private array $usedGlyphs = [0 => true];

    /** @var array<string,float> memoised text widths */
    private array $measured = [];

    /** @var array<string,array{0:list<int>,1:list<list<int>>,2:list<float>}> memoised shaping results */
    private array $shaped = [];

    private ?OpenTypeLayout $shaper = null;

    private bool $shaperBuilt = false;

    public function __construct(
        public readonly string $path,
        public readonly string $postScriptName,
        public readonly bool $bold = false,
        // Which slot this face fills, so the per-character fallback can reach
        // for the bundled face that matches it. A face registered through
        // `@font-face` carries nothing in its name that says so, which is why
        // this is stated rather than inferred.
        public readonly bool $italic = false,
        // Whether this face IS one of the bundled fallback faces. One of them
        // must never fall back to another, or a character none of them carries
        // would walk the whole bundle on every measurement.
        public readonly bool $isFallback = false,
    ) {
        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new RuntimeException("cannot read font: $path");
        }

        $this->data = $raw;
        $this->parseDirectory();
        $this->parseHead();
        $this->parseHhea();
        $this->parseMaxp();
        $this->parseOs2();
        $this->parsePost();
        $this->parseLoca();
        $this->parseHmtx();
    }

    // ---------------------------------------------------------------
    // binary readers
    // ---------------------------------------------------------------
    /**
     * Every offset below is read out of the file being parsed, so a truncated
     * or hostile face can point anywhere. Reading past the end used to hand
     * unpack() a short string, which returned false and surfaced as a
     * TypeError from a private reader: an unhandled crash rather than a
     * rejected font. Refuse the read instead, so a bad font is catchable.
     */
    private function at(int $o, int $bytes): string
    {
        if ($o < 0 || $o + $bytes > strlen($this->data)) {
            throw new RuntimeException("malformed font: read of $bytes bytes past the end at $o");
        }

        return substr($this->data, $o, $bytes);
    }

    private function u8(int $o): int { return ord($this->at($o, 1)); }

    private function u16(int $o): int { return unpack('n', $this->at($o, 2))[1]; }

    private function s16(int $o): int
    {
        $v = $this->u16($o);

        return $v >= 0x8000 ? $v - 0x10000 : $v;
    }

    private function u32(int $o): int { return unpack('N', $this->at($o, 4))[1]; }

    private function table(string $tag): ?array
    {
        return $this->tables[$tag] ?? null;
    }

    private function parseDirectory(): void
    {
        $numTables = $this->u16(4);

        for ($i = 0; $i < $numTables; $i++) {
            $rec = 12 + $i * 16;
            $tag = substr($this->data, $rec, 4);

            $this->tables[$tag] = [
                'offset' => $this->u32($rec + 8),
                'length' => $this->u32($rec + 12),
            ];
        }
    }

    private function parseHead(): void
    {
        $t                = $this->table('head') ?? throw new RuntimeException('no head table');
        $o                = $t['offset'];
        $this->unitsPerEm = $this->u16($o + 18);

        $this->bbox = [
            $this->s16($o + 36),
            $this->s16($o + 38),
            $this->s16($o + 40),
            $this->s16($o + 42),
        ];

        $this->indexToLocFormat = $this->s16($o + 50);
    }

    private function parseHhea(): void
    {
        $t                      = $this->table('hhea') ?? throw new RuntimeException('no hhea table');
        $o                      = $t['offset'];
        $this->typoAscent       = $this->s16($o + 4);
        $this->typoDescent      = $this->s16($o + 6);
        $this->hheaAscent       = $this->typoAscent;
        $this->hheaDescent      = $this->typoDescent;
        $this->lineGap          = $this->s16($o + 8);
        $this->numberOfHMetrics = $this->u16($o + 34);
    }

    /**
     * What `line-height: normal` resolves to for this face, as a multiple of
     * the font size: the em box plus the designer's line gap, which is how
     * browsers derive it.
     */
    public function normalLineHeight(): float
    {
        $em = $this->lineAscent() + $this->lineDescent() + $this->lineGap;

        return $this->unitsPerEm > 0 && $em > 0 ? $em / $this->unitsPerEm : 1.15;
    }

    /** The ascender a line box is built from, which is `hhea`'s. */
    private function lineAscent(): int
    {
        return $this->hheaAscent !== 0 ? $this->hheaAscent : $this->typoAscent;
    }

    /** And the descender, as a positive number of font units. */
    private function lineDescent(): int
    {
        return abs($this->hheaDescent !== 0 ? $this->hheaDescent : $this->typoDescent);
    }

    /** One CSS pixel in points, which is the grid a line box lands on. */
    private const float CSS_PIXEL = 0.75;

    /**
     * The height of a `line-height: normal` line box, quantized the way Chrome
     * quantizes it. Defect DQ.
     *
     * **Each of the three terms is rounded to a whole CSS pixel and then they
     * are added**, which is exact rather than close: across 97 font sizes from
     * 8px to 32px on `RD-normal-lineheight-sweep.html`, this reproduces
     * Chrome's line box **97 of 97 times** for DejaVu Sans. The ascender and
     * descender the fit recovered, 0.928 and 0.236, are that face's own to four
     * places, which is what says the rule is the browser's and not a curve
     * fitted to one font.
     *
     * A face with no `unitsPerEm` has no metrics to round, so it falls back to
     * rounding the total the way the base-14 table does.
     */
    public function lineSpacing(float $size): float
    {
        if ($this->unitsPerEm <= 0) {
            return round($this->normalLineHeight() * $size / self::CSS_PIXEL) * self::CSS_PIXEL;
        }

        $px = $size / self::CSS_PIXEL;

        return (
            round($this->lineAscent() / $this->unitsPerEm * $px)
            + round($this->lineDescent() / $this->unitsPerEm * $px)
            + round($this->lineGap / $this->unitsPerEm * $px)
        ) * self::CSS_PIXEL;
    }

    /**
     * The ascent a line box is built from, rounded to a whole CSS pixel.
     *
     * It is `hhea`'s rather than OS/2's, for the same reason
     * {@see lineSpacing} uses that pair: a browser builds a line box from
     * `hhea`, and a bullet's own size and place are fractions of the same
     * ascent. On DejaVu Sans the two disagree by 16 percent, which is two
     * pixels of bullet at 24px. {@see BoxPainter::paintMarkerShape}.
     */
    public function pixelAscent(float $size): float
    {
        if ($this->unitsPerEm <= 0) {
            return round($this->ascent($size) / self::CSS_PIXEL);
        }

        return round($this->lineAscent() / $this->unitsPerEm * ($size / self::CSS_PIXEL));
    }

    /**
     * The ascent and descent a browser builds a line box from, each rounded to
     * a whole CSS pixel.
     *
     * It is `hhea`'s pair, the same one {@see lineSpacing} sums for the
     * height, and the quantisation is the same too. Measured on
     * `SV-face-baseline.html` over five faces: this pair reproduces Chrome 88
     * of 88 where the face's own `usWinAscent` pair reproduces 47 and OS/2's
     * `sTypoAscender` pair, which {@see ascent} returns, reproduces 21.
     *
     * A face with no `unitsPerEm` has no metrics to round.
     *
     * @return array{0:float,1:float}
     */
    private function lineExtents(float $size): array
    {
        if ($this->unitsPerEm <= 0) {
            return [$this->ascent($size), $this->descent($size)];
        }

        $px = $size / self::CSS_PIXEL;

        return [
            round($this->lineAscent() / $this->unitsPerEm * $px) * self::CSS_PIXEL,
            round($this->lineDescent() / $this->unitsPerEm * $px) * self::CSS_PIXEL,
        ];
    }

    /**
     * The band this face's own box covers, above and below the baseline.
     *
     * **It is the rounded pair and nothing else**: no half-leading and no line
     * gap. `SW-face-inlinebg.html` reads it off Chrome's own fill rects on 13
     * embedded-face bands and all 13 agree, and STIX Two Text and Khmer Sangam
     * MN are what say the gap is excluded, because DejaVu Sans has none and
     * cannot tell the font box from the normal line box at all.
     *
     * @return array{0:float,1:float}
     */
    public function fontBox(float $size): array
    {
        [$ascent, $descent] = $this->lineExtents($size);

        if ($this->unitsPerEm > 0) {
            return [$ascent, $descent];
        }

        $leading = ($this->normalLineHeight() * $size - $ascent - $descent) / 2.0;

        return [$ascent + $leading, $descent + $leading];
    }

    /**
     * Where a line box built from this face puts its baseline, and how far it
     * reaches below it, for a used `line-height` in points.
     *
     * **The half-leading above the baseline is floored to a whole CSS pixel**,
     * which is the other half of the rule and is invisible on a face whose
     * half-leading is already whole at every size. The tolerance is not
     * cosmetic: `line-height: normal` is carried as a multiple of the font
     * size and multiplied back here, so a half-leading of exactly one pixel
     * arrives as 1 minus 1.2e-15 and floors to zero. At 13px on DejaVu that is
     * the only size on the whole ladder that misses.
     *
     * @return array{0:float,1:float}
     */
    public function lineBand(float $size, float $lineHeight): array
    {
        [$ascent, $descent] = $this->lineExtents($size);
        $half               = ($lineHeight - ($ascent + $descent)) / 2.0;

        if ($this->unitsPerEm <= 0) {
            return [$ascent + $half, $descent + $half];
        }

        $above = $ascent + floor($half / self::CSS_PIXEL + 1e-9) * self::CSS_PIXEL;

        return [$above, $lineHeight - $above];
    }

    private function parseMaxp(): void
    {
        $t               = $this->table('maxp') ?? throw new RuntimeException('no maxp table');
        $this->numGlyphs = $this->u16($t['offset'] + 4);
    }

    private function parseOs2(): void
    {
        $t = $this->table('OS/2');

        if ($t === null) {
            return;
        }

        $o           = $t['offset'];
        $version     = $this->u16($o);
        $weight      = $this->u16($o + 4);
        $this->stemV = (int) round(50 + ($weight / 100) ** 2 * 1.5);
        $ta          = $this->s16($o + 68);
        $td          = $this->s16($o + 70);

        if ($ta !== 0) {
            $this->typoAscent = $ta;
        }

        if ($td !== 0) {
            $this->typoDescent = $td;
        }

        $this->capHeight = $version >= 2 ? $this->s16($o + 88) : (int) ($this->typoAscent * 0.7);
        $this->sxHeight  = $version >= 2 ? $this->s16($o + 86) : 0;
    }

    private function parsePost(): void
    {
        $t = $this->table('post');

        if ($t === null) {
            return;
        }

        $fixed             = $this->u32($t['offset'] + 4);
        $this->italicAngle = (int) round(($fixed >= 0x80000000 ? $fixed - 0x100000000 : $fixed) / 65536);

        if ($this->italicAngle !== 0) {
            $this->flags |= 64;
        }
    }

    private function parseLoca(): void
    {
        $t = $this->table('loca');

        if ($t === null) {
            return;
        }

        $o = $t['offset'];
        $n = $this->numGlyphs + 1;

        for ($i = 0; $i < $n; $i++) {
            $this->loca[$i] = $this->indexToLocFormat === 0
                ? $this->u16($o + $i * 2) * 2
                : $this->u32($o + $i * 4);
        }
    }

    private function parseHmtx(): void
    {
        $t    = $this->table('hmtx') ?? throw new RuntimeException('no hmtx table');
        $o    = $t['offset'];
        $last = 0;

        for ($g = 0; $g < $this->numGlyphs; $g++) {
            if ($g < $this->numberOfHMetrics) {
                $last = $this->u16($o + $g * 4);
            }

            $this->advances[$g] = $last;
        }
    }

    // ---------------------------------------------------------------
    // character mapping
    // ---------------------------------------------------------------
    private function buildCmap(): void
    {
        if ($this->cmap !== null) {
            return;
        }

        $this->cmap = [];

        $t    = $this->table('cmap') ?? throw new RuntimeException('no cmap table');
        $base = $t['offset'];
        $n    = $this->u16($base + 2);

        $best      = null;
        $bestScore = -1;

        for ($i = 0; $i < $n; $i++) {
            $rec      = $base + 4 + $i * 8;
            $platform = $this->u16($rec);
            $encoding = $this->u16($rec + 2);
            $offset   = $this->u32($rec + 4);

            // Prefer full Unicode (3,10) then BMP (3,1) then (0,x)
            $score = match (true) {
                $platform === 3 && $encoding === 10 => 4,
                $platform === 0 && $encoding === 4  => 3,
                $platform === 3 && $encoding === 1  => 2,
                $platform === 0                     => 1,
                default                             => 0,
            };

            if ($score > $bestScore) {
                $bestScore = $score;
                $best      = $base + $offset;
            }
        }

        if ($best === null) {
            return;
        }

        $format = $this->u16($best);

        if ($format === 4) {
            $this->parseCmap4($best);
        } elseif ($format === 12) {
            $this->parseCmap12($best);
        }
    }

    /**
     * A character map cannot usefully hold more entries than Unicode has
     * codepoints, and a font claiming otherwise is spending the host's CPU
     * rather than describing glyphs.
     */
    private const int MAX_CMAP_ENTRIES = 0x110000;

    private function parseCmap4(int $o): void
    {
        $segCount  = $this->u16($o + 6) >> 1;
        $endBase   = $o + 14;
        $startBase = $endBase + $segCount * 2 + 2;
        $deltaBase = $startBase + $segCount * 2;
        $rangeBase = $deltaBase + $segCount * 2;

        for ($i = 0; $i < $segCount; $i++) {
            if (count($this->cmap) >= self::MAX_CMAP_ENTRIES) {
                return;
            }

            $end         = $this->u16($endBase + $i * 2);
            $start       = $this->u16($startBase + $i * 2);
            $delta       = $this->s16($deltaBase + $i * 2);
            $rangeOffset = $this->u16($rangeBase + $i * 2);

            if ($start > $end) {
                continue;
            }

            for ($c = $start; $c <= $end && $c !== 0xFFFF; $c++) {
                if ($rangeOffset === 0) {
                    $g = ($c + $delta) & 0xFFFF;
                } else {
                    $addr = $rangeBase + $i * 2 + $rangeOffset + ($c - $start) * 2;

                    if ($addr + 1 >= strlen($this->data)) {
                        continue;
                    }

                    $g = $this->u16($addr);

                    if ($g !== 0) {
                        $g = ($g + $delta) & 0xFFFF;
                    }
                }

                if ($g !== 0) {
                    $this->cmap[$c] = $g;
                }
            }
        }
    }

    private function parseCmap12(int $o): void
    {
        // Both counts here are read straight out of the file, so a hostile
        // one can claim far more than it carries: a 32-bit group count costs
        // hours of looping, and groups the subtable is too short to hold are
        // read past the end of the data. Bound the count by what the
        // subtable's own length can contain.
        $declared = $this->u32($o + 4);
        $carried  = intdiv(max(0, min($declared, strlen($this->data) - $o) - 16), 12);
        $nGroups  = min($this->u32($o + 12), $carried);

        for ($i = 0; $i < $nGroups; $i++) {
            if (count($this->cmap) >= self::MAX_CMAP_ENTRIES) {
                return;
            }

            $rec      = $o + 16 + $i * 12;
            $start    = $this->u32($rec);
            $end      = $this->u32($rec + 4);
            $startGid = $this->u32($rec + 8);

            if ($end - $start > 0xFFFF) {
                continue;
            }

            for ($c = $start; $c <= $end; $c++) {
                $this->cmap[$c] = $startGid + ($c - $start);
            }
        }
    }

    /** @return int[] codepoints of a UTF-8 string */
    public static function codepoints(string $utf8): array
    {
        $out = [];
        $len = strlen($utf8);

        for ($i = 0; $i < $len;) {
            $c = ord($utf8[$i]);

            if ($c < 0x80) {
                $out[] = $c;
                $i++;
            } elseif ($c < 0xE0) {
                $out[] = (($c & 0x1F) << 6) | (ord($utf8[$i + 1]) & 0x3F);
                $i     += 2;
            } elseif ($c < 0xF0) {
                $out[] = (($c & 0x0F) << 12) | ((ord($utf8[$i + 1]) & 0x3F) << 6) | (ord($utf8[$i + 2]) & 0x3F);
                $i     += 3;
            } else {
                $out[] = (($c & 0x07) << 18) | ((ord($utf8[$i + 1]) & 0x3F) << 12)
                    | ((ord($utf8[$i + 2]) & 0x3F) << 6) | (ord($utf8[$i + 3]) & 0x3F);
                $i     += 4;
            }
        }

        return $out;
    }

    public function glyphFor(int $codepoint): int
    {
        $this->buildCmap();

        return $this->cmap[$codepoint] ?? 0;
    }

    /**
     * Whether this face can draw a character at all.
     *
     * Glyph 0 is `.notdef`, which every face has and which draws an empty box
     * or nothing. A face that answers 0 does not carry the character, however
     * many outlines it has.
     */
    public function carries(int $codepoint): bool
    {
        return $this->glyphFor($codepoint) !== 0;
    }

    /** The bundled fallback in force, or null on a bundled face itself. */
    private function fallback(): ?FontFallback
    {
        return $this->isFallback ? null : FontFallback::active();
    }

    // ---------------------------------------------------------------
    // shaping
    // ---------------------------------------------------------------
    /**
     * A shaping cache that grows once per distinct piece of text is a cache
     * that grows with the document. 8,000 census documents share one registered
     * face, so it is emptied rather than allowed to hold every word ever
     * measured.
     */
    private const int MAX_SHAPED = 20000;

    /** The face's own GSUB, GPOS and `kern`, or null where it carries none. */
    private function shaper(): ?OpenTypeLayout
    {
        if ($this->shaperBuilt) {
            return $this->shaper;
        }

        $this->shaperBuilt = true;
        $shaper            = new OpenTypeLayout(
            $this->data,
            $this->tables,
            fn(int $gid): int => $this->advanceOf($gid),
        );

        return $this->shaper = $shaper->isEmpty() ? null : $shaper;
    }

    /**
     * The OpenType feature tags this face registers, which is what says whether
     * a probe measures the property or the font.
     *
     * @return list<string>
     */
    public function featureTags(): array
    {
        return $this->shaper()?->availableTags() ?? [];
    }

    /**
     * Text turned into the glyphs that will be drawn, the characters each one
     * stands for, and the advance each one asks to have added, in thousandths
     * of an em.
     *
     * **The adjustment is quantized here rather than where it is written**,
     * because a `TJ` array carries thousandths of an em and the width this
     * engine laid the line out with has to be the width the writer draws. A
     * kern rounded in the writer alone would put the measurement and the ink a
     * fraction of a unit apart on every pair in the document.
     *
     * The fourth element is the PLACEMENT of each glyph, also in thousandths of
     * an em, which is what a combining mark needs and what nothing else uses. It
     * moves the glyph and not the pen, so no caller that measures a width reads
     * it: a mark's advance is zero either way. Defect GT.
     *
     * @return array{0:list<int>,1:list<list<int>>,2:list<float>,3:list<array{0:float,1:float}>}
     */
    private function shape(string $text, string $features): array
    {
        $key = $features . "\0" . $text;

        if (isset($this->shaped[$key])) {
            return $this->shaped[$key];
        }

        if (count($this->shaped) >= self::MAX_SHAPED) {
            $this->shaped = [];
        }

        $this->buildCmap();

        $gids = [];
        $cps  = [];

        foreach (self::codepoints($text) as $cp) {
            $gids[] = $this->cmap[$cp] ?? 0;
            $cps[]  = BidiText::toBaseCodepoints($cp);
        }

        $wanted = self::parseFeatures($features);
        $shaper = $wanted === [] ? null : $this->shaper();

        if ($shaper === null || $this->unitsPerEm <= 0) {
            return $this->shaped[$key] = [
                $gids,
                $cps,
                array_fill(0, count($gids), 0.0),
                array_fill(0, count($gids), [0.0, 0.0]),
            ];
        }

        // Punctuation and digits carry no script of their own, so a run with no
        // letter in it is shaped under the face's default script rather than
        // under `latn`, which is what a shaper does and what decides which of
        // DejaVu Sans's two `dlig` lookups reaches `!?`.
        $latin = preg_match('/\p{L}/u', $text) === 1;
        $pairs = [];

        foreach ($gids as $i => $gid) {
            $pairs[] = [$gid, $cps[$i]];
        }

        $gids = [];
        $cps  = [];

        foreach ($shaper->substitute($pairs, $wanted, $latin) as [$gid, $characters]) {
            $gids[] = $gid;
            $cps[]  = $characters;
        }

        $adjust = [];
        $place  = [];
        $scale  = 1000.0 / $this->unitsPerEm;

        foreach ($shaper->adjust($gids, $wanted, $latin) as [$units, $x, $y]) {
            $adjust[] = round($units * $scale, 1);
            $place[]  = [round($x * $scale, 1), round($y * $scale, 1)];
        }

        return $this->shaped[$key] = [$gids, $cps, $adjust, $place];
    }

    /**
     * `tag=value` pairs, which is the shape a run carries a resolved feature
     * set in. An empty string is no shaping at all, which is what every caller
     * that has no style to read wants.
     *
     * @return array<string,int>
     */
    private static function parseFeatures(string $features): array
    {
        if ($features === '') {
            return [];
        }

        $out = [];

        foreach (explode(',', $features) as $pair) {
            $eq = strpos($pair, '=');

            if ($eq === false) {
                continue;
            }

            $out[substr($pair, 0, $eq)] = (int) substr($pair, $eq + 1);
        }

        return $out;
    }

    /**
     * How many glyphs this text becomes, which is what `letter-spacing` lands
     * after.
     *
     * A run split across faces asks each face for its own piece: the two shape
     * differently, so one face's count of the whole string is not the number of
     * glyphs the writer will show.
     */
    public function glyphCount(string $text, string $features): int
    {
        $segments = $this->fallback()?->segments($this, $text);

        if ($segments === null) {
            return count($this->shape($text, $features)[0]);
        }

        $count = 0;

        foreach ($segments as [$face, $part]) {
            $count += $face === $this
                ? count($this->shape($part, $features)[0])
                : $face->glyphCount($part, $features);
        }

        return $count;
    }

    // ---------------------------------------------------------------
    // measurement (same surface as the base-14 Font)
    // ---------------------------------------------------------------
    public function stringWidth(string $text, float $size, string $features = ''): float
    {
        $key = $features . "\0" . $size . "\0" . $text;

        if (isset($this->measured[$key])) {
            return $this->measured[$key];
        }

        $segments = $this->fallback()?->segments($this, $text);

        if ($segments === null) {
            return $this->measured[$key] = $this->ownWidth($text, $size, $features);
        }

        $total = 0.0;

        foreach ($segments as [$face, $part]) {
            $total += $face === $this
                ? $this->ownWidth($part, $size, $features)
                : $face->stringWidth($part, $size, $features);
        }

        return $this->measured[$key] = $total;
    }

    /**
     * This face's own advance for a piece of text, with no fallback in it.
     *
     * The pieces {@see FontFallback::segments()} leaves here are the ones this
     * face draws, plus the ones nothing at all can draw, which shape to
     * `.notdef` and measure as its advance. That is what they painted as before
     * a fallback existed and it is still what they paint as.
     */
    private function ownWidth(string $text, float $size, string $features): float
    {
        [$gids, , $adjust] = $this->shape($text, $features);

        $units = 0;
        $kerns = 0.0;

        foreach ($gids as $i => $gid) {
            $units += $this->advances[$gid] ?? 0;
            $kerns += $adjust[$i];
        }

        return $units * $size / $this->unitsPerEm + $kerns * $size / 1000.0;
    }

    public function ascent(float $size): float
    {
        return $this->typoAscent * $size / $this->unitsPerEm;
    }

    public function descent(float $size): float
    {
        return abs($this->typoDescent) * $size / $this->unitsPerEm;
    }

    /**
     * The height of a lowercase `x`, from OS/2's `sxHeight` where the face
     * carries one and off the `x` glyph's own outline where it does not.
     *
     * **`sxHeight` only exists from OS/2 version 2 and DejaVu is version 1**,
     * so the constant this used to fall back to was Helvetica's 0.523 on a
     * face whose own ratio is 1120/2048, which is 0.547. That is invisible to
     * `vertical-align: middle`, which is what the fallback was written for, and
     * it is 4.6 percent of the answer to `font-size-adjust`, which reads this
     * number as the thing it is adjusting. Chrome measures the glyph, and
     * `RW-font-width.html` `x5` says so: 270 pixels against the 282 the
     * constant gives.
     *
     * The glyph's `yMax` is the fourth `int16` of its `glyf` entry, so this
     * costs one table lookup and eight bytes. Helvetica's ratio is still the
     * answer for a face with neither, which is one with no outlines to measure.
     *
     * **The glyph wins over `sxHeight` where a face carries both**, which is
     * round 37's own docblock finally reaching its own code: it said Chrome
     * measures the glyph and then read the table first anyway. On most faces
     * the two agree to the unit, so nothing said otherwise until a face turned
     * up where they do not. Khmer Sangam MN declares 500 and draws an `x`
     * 1018 units tall, which is a factor of two, and
     * `SX-face-decoration.html` `m-k32` reads Chrome using the outline.
     */
    public function xHeight(float $size): float
    {
        if (!$this->xHeightRead) {
            $this->xHeightRead   = true;
            $this->measuredXHeight = $this->readXHeight();
        }

        if ($this->measuredXHeight !== null) {
            return $this->measuredXHeight * $size;
        }

        return $this->sxHeight > 0
            ? $this->sxHeight * $size / $this->unitsPerEm
            : 0.523 * $size;
    }

    /**
     * The metric `font-size-adjust` holds constant, in the same units as the
     * size, so the caller's aspect is this over the size.
     *
     * CSS Fonts 5 section 5.3 names five. Four are read off this face and
     * `ic-height` is the vertical advance of U+6C34, which needs vertical
     * metrics this engine has nowhere to read, so it is one em: that is the
     * fallback the spec asks for where the face carries no such advance, and it
     * is the number Chrome writes for it on a face with no `vmtx`.
     * `ic-width` falls back the same way where there is no U+6C34 glyph, which
     * is every face on this machine that is not a CJK one.
     */
    public function sizeAdjustMetric(string $metric, float $size): float
    {
        return match ($metric) {
            'cap-height' => $this->capHeight > 0 && $this->unitsPerEm > 0
                ? $this->capHeight * $size / $this->unitsPerEm
                : 0.0,
            'ch-width'   => $this->stringWidth('0', $size),
            'ic-width'   => $this->glyphFor(0x6C34) > 0 ? $this->stringWidth("\u{6C34}", $size) : $size,
            'ic-height'  => $size,
            default      => $this->xHeight($size),
        };
    }

    /** The `x` glyph's own height as a fraction of the em, memoised. */
    private ?float $measuredXHeight = null;

    private bool $xHeightRead = false;

    /** The `x` glyph's own `yMax`, or null where there is no outline to read. */
    private function readXHeight(): ?float
    {
        $glyf = $this->table('glyf');
        $gid  = $this->glyphFor(0x78);   // 'x'

        if ($glyf === null || $gid <= 0 || !isset($this->loca[$gid], $this->loca[$gid + 1])) {
            return null;
        }

        if ($this->loca[$gid + 1] - $this->loca[$gid] < 10) {
            return null;
        }

        $top = $this->s16($glyf['offset'] + $this->loca[$gid] + 8);

        return $top > 0 ? $top / $this->unitsPerEm : null;
    }

    /**
     * How far this face's own box reaches above the baseline, which is where
     * `text-decoration` is placed from and what `vertical-align: text-top`
     * measures a parent by.
     *
     * **It is {@see fontBox()} and nothing else.** It used to be the OS/2 typo
     * pair plus half the line gap, which is a third answer to a question round
     * 57 had already settled twice, and it put an overline about a twelfth of
     * the em below Chrome's on every embedded face.
     * `SX-face-decoration.html` reads the overline's lower edge off Chrome's
     * own fill rects on six embedded-face bands and all six are this pair.
     */
    public function boxAscent(float $size): float
    {
        return $this->fontBox($size)[0];
    }

    /** The other half of the font box, which is the rounded descent alone. */
    public function boxDescent(float $size): float
    {
        return $this->fontBox($size)[1];
    }

    // ---------------------------------------------------------------
    // encoding for output
    // ---------------------------------------------------------------
    /** Record usage and return the Identity-H hex string for this text. */
    public function encode(string $text, string $features = ''): string
    {
        [$gids, $cps] = $this->shape($text, $features);
        $hex          = '';

        foreach ($gids as $i => $gid) {
            $this->usedGlyphs[$gid] = true;
            $this->toUnicode[$gid]  = $cps[$i];
            $hex                    .= sprintf('%04X', $gid);
        }

        return $hex;
    }

    /**
     * The show operand and its operator: `<hex> Tj` where nothing moves the
     * pen, and a `TJ` array where a kern does.
     *
     * A `TJ` number is thousandths of an em **subtracted** from the advance, so
     * a kern that pulls two letters together is written positive here and the
     * pair the face widens is written negative.
     *
     * **A PLACEMENT IS NOT AN ADVANCE**, which is what a combining mark needs
     * and what makes this more than one operand. Sideways it is a `TJ` number
     * before the glyph and its negation after, so the glyph moves and the pen
     * ends where it was. Vertically there is no `TJ` at all: `Ts` is the only
     * operator that moves a baseline, it is text state rather than an array
     * element, so a mark with a y placement breaks the run and puts `Ts` back to
     * zero afterwards. Defect GT.
     *
     * `Ts` is in UNSCALED text space units, so the size has to be applied here,
     * and `$flipped` says whether the caller's text matrix runs y downwards:
     * `drawTextInUserSpace()` writes `1 0 0 -1 ... Tm` and the line painter does
     * not, so the same placement is two different signs.
     */
    public function showText(string $text, string $features, float $size = 0.0, bool $flipped = false): string
    {
        [$gids, $cps, $adjust, $place] = $this->shape($text, $features);

        $groups  = [];
        $parts   = [];
        $current = '';

        $close = static function () use (&$parts, &$current): void {
            if ($current !== '') {
                $parts[] = '<' . $current . '>';
                $current = '';
            }
        };

        $flush = static function () use (&$groups, &$parts, &$current, $close): void {
            $close();

            if ($parts === []) {
                return;
            }

            $groups[] = count($parts) === 1 && str_starts_with($parts[0], '<')
                ? $parts[0] . ' Tj'
                : '[' . implode(' ', $parts) . '] TJ';
            $parts = [];
        };

        foreach ($gids as $i => $gid) {
            $this->usedGlyphs[$gid] = true;
            $this->toUnicode[$gid]  = $cps[$i];

            [$dx, $dy] = $place[$i];
            $rises     = $dy !== 0.0 && $size > 0.0;

            if ($rises) {
                $flush();
                $groups[] = self::number(($flipped ? -$dy : $dy) * $size / 1000.0) . ' Ts';
            }

            if ($dx !== 0.0) {
                $close();
                $parts[] = self::number(-$dx);
            }

            $current .= sprintf('%04X', $gid);

            if ($dx !== 0.0) {
                $close();
                $parts[] = self::number($dx);
            }

            if ($adjust[$i] !== 0.0) {
                $close();
                $parts[] = self::number(-$adjust[$i]);
            }

            if ($rises) {
                $flush();
                $groups[] = '0 Ts';
            }
        }

        // Empty text still has to show something, and `$flush()` closes the
        // accumulator itself: pushing it here as well drew the last glyph twice,
        // which `TL-mark-attach.html`'s two single-glyph controls caught and the
        // 500 suites did not.
        if ($current === '' && $parts === [] && $groups === []) {
            $parts[] = '<>';
        }

        $flush();

        return implode(' ', $groups);
    }

    /** One decimal at most, and no trailing zero, so a whole kern writes as an integer. */
    private static function number(float $value): string
    {
        $text = sprintf('%.1f', $value);

        return str_ends_with($text, '.0') ? substr($text, 0, -2) : $text;
    }

    /** @var array<int,int[]> glyph id => codepoint(s), for the ToUnicode CMap */
    private array $toUnicode = [];

    public function usedGlyphIds(): array
    {
        $g = array_keys($this->usedGlyphs);
        sort($g);

        return $g;
    }

    public function advanceOf(int $gid): int
    {
        return $this->advances[$gid] ?? 0;
    }

    public function toUnicodeMap(): array
    {
        return $this->toUnicode;
    }

    // ---------------------------------------------------------------
    // subsetting
    // ---------------------------------------------------------------
    /** Composite glyphs reference other glyphs; those must survive too. */
    private function expandComposites(array $used): array
    {
        $glyf = $this->table('glyf');

        if ($glyf === null) {
            return $used;
        }

        $queue = array_keys($used);

        while ($queue !== []) {
            $gid = array_pop($queue);

            if (!isset($this->loca[$gid], $this->loca[$gid + 1])) {
                continue;
            }

            $start = $glyf['offset'] + $this->loca[$gid];
            $len   = $this->loca[$gid + 1] - $this->loca[$gid];

            if ($len < 10) {
                continue;
            }

            if ($this->s16($start) >= 0) {
                continue;
            } // simple glyph

            $o = $start + 10;

            do {
                $flags        = $this->u16($o);
                $componentGid = $this->u16($o + 2);

                if (!isset($used[$componentGid])) {
                    $used[$componentGid] = true;
                    $queue[]             = $componentGid;
                }

                $o += 4;
                $o += ($flags & 0x0001) ? 4 : 2; // ARG_1_AND_2_ARE_WORDS

                if ($flags & 0x0008) {
                    $o += 2;
                } // WE_HAVE_A_SCALE
                elseif ($flags & 0x0040) {
                    $o += 4;
                } // X_AND_Y_SCALE
                elseif ($flags & 0x0080) {
                    $o += 8;
                } // TWO_BY_TWO
            } while ($flags & 0x0020); // MORE_COMPONENTS
        }

        return $used;
    }

    /**
     * Build a subset font file.
     *
     * Glyph IDs are preserved (the PDF uses CIDToGIDMap /Identity, so CID ==
     * GID). Unused glyphs become zero-length loca entries, which is valid and
     * removes essentially all of the outline data.
     */
    public function subset(): string
    {
        $used      = $this->expandComposites($this->usedGlyphs);
        $glyfTable = $this->table('glyf');

        // --- new glyf + loca ---
        $glyf = '';
        $loca = [];

        for ($gid = 0; $gid < $this->numGlyphs; $gid++) {
            $loca[$gid] = strlen($glyf);

            if (isset($used[$gid]) && $glyfTable !== null) {
                $off = $glyfTable['offset'] + $this->loca[$gid];
                $len = $this->loca[$gid + 1] - $this->loca[$gid];

                if ($len > 0) {
                    $glyf .= substr($this->data, $off, $len);

                    if (strlen($glyf) % 4 !== 0) {
                        $glyf .= str_repeat("\0", 4 - strlen($glyf) % 4);
                    }
                }
            }
        }

        $loca[$this->numGlyphs] = strlen($glyf);

        // Long loca format keeps this simple and always representable.
        $locaData = '';

        foreach ($loca as $v) {
            $locaData .= pack('N', $v);
        }

        // --- head with indexToLocFormat = 1 and a cleared checksum ---
        $head = $this->rawTable('head');
        $head = substr($head, 0, 8) . pack('N', 0) . substr($head, 12);
        $head = substr($head, 0, 50) . pack('n', 1) . substr($head, 52);

        $out = [
            'head' => $head,
            'hhea' => $this->rawTable('hhea'),
            'maxp' => $this->rawTable('maxp'),
            'hmtx' => $this->rawTable('hmtx'),
            'loca' => $locaData,
            'glyf' => $glyf,
        ];

        foreach (['cvt ', 'fpgm', 'prep'] as $opt) {
            if ($this->table($opt) !== null) {
                $out[$opt] = $this->rawTable($opt);
            }
        }

        ksort($out);

        return $this->assemble($out);
    }

    private function rawTable(string $tag): string
    {
        $t = $this->table($tag);

        return $t === null ? '' : substr($this->data, $t['offset'], $t['length']);
    }

    /** @param array<string,string> $tables */
    private function assemble(array $tables): string
    {
        $n             = count($tables);
        $searchRange   = 16 * (2 ** (int) floor(log($n, 2)));
        $entrySelector = (int) floor(log($n, 2));
        $rangeShift    = $n * 16 - $searchRange;

        $header = pack('N', 0x00010000) . pack('nnnn', $n, $searchRange, $entrySelector, $rangeShift);

        $offset    = strlen($header) + $n * 16;
        $directory = '';
        $body      = '';

        foreach ($tables as $tag => $data) {
            $len    = strlen($data);
            $padded = $data;

            if ($len % 4 !== 0) {
                $padded .= str_repeat("\0", 4 - $len % 4);
            }

            $directory .= $tag . pack('N', $this->checksum($padded)) . pack('NN', $offset, $len);
            $body      .= $padded;
            $offset    += strlen($padded);
        }

        return $header . $directory . $body;
    }

    private function checksum(string $data): int
    {
        $sum = 0;
        $n   = strlen($data) >> 2;

        for ($i = 0; $i < $n; $i++) {
            $sum = ($sum + unpack('N', substr($data, $i * 4, 4))[1]) & 0xFFFFFFFF;
        }

        return $sum;
    }

    /** Six-letter tag PDF requires on subset font names. */
    public function subsetTag(): string
    {
        $hash = substr(md5($this->postScriptName . implode(',', $this->usedGlyphIds())), 0, 6);
        $tag  = '';

        foreach (str_split($hash) as $c) {
            $tag .= chr(65 + (hexdec($c) % 26));
        }

        return $tag;
    }
}
