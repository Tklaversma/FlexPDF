<?php
declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use FlexPDF\Engine\{TrueTypeFont, FontRegistry, InlineRun, InlineFormatter, Node, FlexLayout, Pdf};

$pass = 0; $fail = 0;
function ok(string $name, bool $cond, string $detail = ''): void
{
    global $pass, $fail;
    if ($cond) { $pass++; printf("  \033[32mPASS\033[0m  %s%s\n", $name, $detail ? "  ($detail)" : ''); }
    else       { $fail++; printf("  \033[31mFAIL\033[0m  %s%s\n", $name, $detail ? "  ($detail)" : ''); }
}

require_once __DIR__ . '/support/bootstrap.php';

define('DEJAVU', dejavu_dir() . 'DejaVuSans.ttf');

echo "\nTrueType parsing, subsetting and embedding\n\n";

// 1. Parsing ---------------------------------------------------------
$f = new TrueTypeFont(DEJAVU, 'DejaVuSans');
ok('parses head/hhea/maxp/OS2',
    $f->unitsPerEm === 2048 && $f->numGlyphs > 6000 && $f->typoAscent > 0 && $f->typoDescent < 0,
    sprintf('upem %d, %d glyphs', $f->unitsPerEm, $f->numGlyphs));

// 2. UTF-8 decoding --------------------------------------------------
$cases = ['A' => 0x41, 'é' => 0xE9, 'ł' => 0x142, 'ж' => 0x436, '€' => 0x20AC, '😀' => 0x1F600];
$allOk = true;
foreach ($cases as $ch => $cp) {
    if (TrueTypeFont::codepoints($ch) !== [$cp]) { $allOk = false; }
}
ok('UTF-8 decoding across 1-, 2-, 3- and 4-byte sequences', $allOk);

// 3. cmap ------------------------------------------------------------
ok('cmap resolves Latin, Latin-Extended, Cyrillic and Greek',
    $f->glyphFor(0x41) > 0 && $f->glyphFor(0x142) > 0
    && $f->glyphFor(0x436) > 0 && $f->glyphFor(0x3BE) > 0);

ok('unmapped codepoints resolve to .notdef, not a wrong glyph',
    $f->glyphFor(0x5317) === 0, 'CJK absent from DejaVu Sans');

// 4. Metrics against a known-good reference --------------------------
// Advance widths are read straight from hmtx; cross-check with fontTools.
$expected = json_decode(shell_exec(
    'python3 -c "'
    . 'from fontTools.ttLib import TTFont; import json;'
    . "f=TTFont('" . DEJAVU . "');"
    . 'hm=f[chr(104)+chr(109)+chr(116)+chr(120)].metrics;'
    . 'cm=f.getBestCmap();'
    . 'print(json.dumps({hex(c): hm[cm[c]][0] for c in [0x41,0x61,0x20,0xE9,0x142,0x436,0x20AC]}))"'
) ?: '{}', true);

$mismatch = [];
foreach ($expected as $hex => $adv) {
    $cp = hexdec($hex);
    $got = $f->advanceOf($f->glyphFor($cp));
    if ($got !== $adv) { $mismatch[] = "$hex: got $got want $adv"; }
}
ok('advance widths match fontTools exactly',
    $expected !== [] && $mismatch === [],
    $mismatch === [] ? count($expected) . ' codepoints' : implode(', ', $mismatch));

// 5. Subsetting ------------------------------------------------------
$f->encode('Hello, Kraków! Привет');
$subset = $f->subset();
$origSize = filesize(DEJAVU);
ok('subset is much smaller than the original',
    strlen($subset) < $origSize * 0.15,
    sprintf('%.1f KB from %.1f KB', strlen($subset) / 1024, $origSize / 1024));

file_put_contents('/tmp/t_subset.ttf', $subset);
$probe = shell_exec(
    'python3 -c "'
    . 'from fontTools.ttLib import TTFont;'
    . "t=TTFont('/tmp/t_subset.ttf', checkChecksums=0);"
    . 'g=t[chr(103)+chr(108)+chr(121)+chr(102)];'
    . 'n=[x for x in t.getGlyphOrder() if g[x].numberOfContours != 0];'
    . 'print(len(n), t[chr(109)+chr(97)+chr(120)+chr(112)].numGlyphs)"'
);
[$outlined, $numGlyphs] = array_map('intval', explode(' ', trim((string) $probe)));
ok('subset font parses and keeps only the used outlines',
    $outlined > 0 && $outlined < 40 && $numGlyphs === $f->numGlyphs,
    "$outlined outlined of $numGlyphs slots");

ok('glyph IDs are preserved so CIDToGIDMap /Identity is valid',
    $numGlyphs === $f->numGlyphs);

// 6. Composite glyphs ------------------------------------------------
// 'é' is a composite of 'e' + acute in DejaVu; both components must survive.
$g = new TrueTypeFont(DEJAVU, 'DejaVuSans');
$g->encode('é');
$before = count($g->usedGlyphIds());
$sub2 = $g->subset();
file_put_contents('/tmp/t_comp.ttf', $sub2);
$comp = trim((string) shell_exec(
    'python3 -c "'
    . 'from fontTools.ttLib import TTFont;'
    . "t=TTFont('/tmp/t_comp.ttf', checkChecksums=0);"
    . 'g=t[chr(103)+chr(108)+chr(121)+chr(102)];'
    . 'n=[x for x in t.getGlyphOrder() if g[x].numberOfContours != 0];'
    . 'print(len(n))"'
));
ok('composite glyph components are pulled into the subset',
    (int) $comp > $before,
    sprintf('%d outlines from %d requested glyphs', (int) $comp, $before));

// 7. Registry fallback -----------------------------------------------
FontRegistry::reset();
FontRegistry::default()->registerTrueType('DejaVu', DEJAVU);
$reg = FontRegistry::default();
ok('missing bold weight falls back to the regular face of the same family',
    $reg->get('DejaVu', true) === $reg->get('DejaVu', false));
ok('unknown family falls back to base-14',
    $reg->get('NoSuchFamily', false) instanceof FlexPDF\Engine\Font);

// 8. End-to-end embedding --------------------------------------------
FontRegistry::reset();
FontRegistry::default()->registerTrueType('DejaVu', DEJAVU);

$text = 'Příliš žluťoučký kůň — Ålesund, Łódź, Съешь';
$node = new Node(['display' => 'text', 'text' => $text, 'fontSize' => 11.0, 'fontFamily' => 'DejaVu']);
$root = new Node(['display' => 'flex', 'width' => 400.0], [$node]);
(new FlexLayout())->layout($root, 400.0, 200.0);

$pdf = new Pdf();
$pdf->beginPage();
$pdf->paintLines($node->lineBoxes, 40.0, 40.0);
$pdf->endPage();
$pdf->save('/tmp/t_embed.pdf');

$out = shell_exec(
    'python3 -c "'
    . 'from pypdf import PdfReader;'
    . "r=PdfReader('/tmp/t_embed.pdf');p=r.pages[0];"
    . "fs=p['/Resources']['/Font'];"
    . "k=list(fs.keys())[0];fo=fs[k].get_object();"
    . "d=fo['/DescendantFonts'][0].get_object();"
    . "ff=d['/FontDescriptor']['/FontFile2'];"
    . 'import json;'
    . "print(json.dumps({'subtype': str(fo['/Subtype']), 'enc': str(fo['/Encoding']),"
    . "'cid2gid': str(d['/CIDToGIDMap']), 'embedded': len(ff.get_data()),"
    . "'text': p.extract_text().strip()}))\""
);
$meta = json_decode(trim((string) $out), true) ?: [];

ok('written as Type0 with Identity-H',
    ($meta['subtype'] ?? '') === '/Type0' && ($meta['enc'] ?? '') === '/Identity-H');
ok('CIDToGIDMap is /Identity', ($meta['cid2gid'] ?? '') === '/Identity');
ok('FontFile2 stream is present and non-trivial',
    ($meta['embedded'] ?? 0) > 1000, sprintf('%d bytes', $meta['embedded'] ?? 0));
ok('ToUnicode CMap round-trips the text exactly',
    ($meta['text'] ?? '') === $text, $meta['text'] ?? '(nothing extracted)');

// 9. Base-14 reaches the bundled face per CHARACTER -------------------
//
// Before round 90 the answer here was `?`, byte 63, for every character
// WinAnsi has no slot for, and the case asserted it. `ZD-font-fallback-
// percharacter.html` is Chrome answering the same question: under a
// `font-family` naming one base-14 family it draws real Cyrillic, real
// Arabic and even real CJK, so the fallback belongs per character and not
// per family.
$b14 = new Node(['display' => 'text', 'text' => 'Kraków — Привет', 'fontSize' => 11.0]);
$r2 = new Node(['display' => 'flex', 'width' => 400.0], [$b14]);
(new FlexLayout())->layout($r2, 400.0, 200.0);
$p2 = new Pdf();
$p2->beginPage();
$p2->paintLines($b14->lineBoxes, 40.0, 40.0);
$p2->endPage();
$p2->save('/tmp/t_b14.pdf');
$t2 = trim((string) shell_exec(
    'python3 -c "from pypdf import PdfReader; print(PdfReader(\'/tmp/t_b14.pdf\').pages[0].extract_text().strip())"'
));
ok('base-14 draws Cyrillic through the bundled face rather than as ?',
    str_contains($t2, 'Kraków') && str_contains($t2, 'Привет') && ! str_contains($t2, '?'),
    $t2);

// 10. The fallback is metrically the same as naming the face ----------
//
// The one invariant the whole design rests on: a run that reaches the
// bundled face has to measure as though the document had asked for that face
// by name, or the line it sits on is broken at a width nothing draws at.
$registry = FontRegistry::default();
$bundled  = \FlexPDF\Engine\FontFallback::bundledDirectory();
$registry->registerTrueType('DejaVu Sans', $bundled . '/DejaVuSans.ttf');

$helvetica = $registry->get('helvetica', false);
$dejavu    = $registry->get('DejaVu Sans', false);

$viaFallback = $helvetica->stringWidth('Привет', 20.0);
$viaName     = $dejavu->stringWidth('Привет', 20.0);
ok('a fallback run measures exactly as the named face does',
    abs($viaFallback - $viaName) < 1e-9,
    sprintf('%.4f vs %.4f', $viaFallback, $viaName));

$mixed = $helvetica->stringWidth('ab Привет cd', 20.0);
$sum   = $helvetica->stringWidth('ab ', 20.0)
    + $dejavu->stringWidth('Привет', 20.0)
    + $helvetica->stringWidth(' cd', 20.0);
ok('a mixed run is its pieces added up, each in the face that draws it',
    abs($mixed - $sum) < 1e-9,
    sprintf('%.4f vs %.4f', $mixed, $sum));

// The control, and it is about over-reach rather than under-reach: once a run
// has switched to the fallback it must switch BACK, or the Latin after a
// Cyrillic word comes out in a typeface the page never named. Comparing widths
// is too weak to see it, because a run that keeps only the tail is still not
// the same width as one drawn entirely in the fallback. The shape of the split
// is what says it.
$pieces = \FlexPDF\Engine\FontFallback::active()->segments($helvetica, 'ab Привет cd');
ok('the Latin either side stays in the face the page asked for',
    $pieces !== null
        && count($pieces) === 3
        && $pieces[0][0] === $helvetica
        && $pieces[1][0] !== $helvetica
        && $pieces[2][0] === $helvetica
        && $pieces[2][1] === ' cd',
    $pieces === null ? 'no split at all' : implode(' | ', array_map(
        static fn(array $piece): string => $piece[0]->postScriptName ?? $piece[0]->name,
        $pieces,
    )));

// 11. What nothing carries is still a substitute, and is REPORTED ------
$before = count($registry->report()->codepoints());
$helvetica->stringWidth('漢字', 20.0);
$reported = $registry->report()->codepoints();
ok('a character no face carries is named in the report rather than passed over',
    count($reported) === $before + 2 && in_array(0x6F22, $reported, true),
    $registry->report()->summary());

ok('and it still measures as the substitute it is still painted as',
    abs($helvetica->stringWidth('漢字', 20.0) - $helvetica->stringWidth('??', 20.0)) < 1e-9);

printf("\n  %d passed, %d failed\n\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
