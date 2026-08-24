<?php
declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use FlexPDF\Engine\{StyleResolver, HtmlBuilder, FlexLayout, FontRegistry, Html, Node, InlineRun};

$pass = 0; $fail = 0;
function ok(string $name, bool $cond, string $detail = ''): void
{
    global $pass, $fail;
    if ($cond) { $pass++; printf("  \033[32mPASS\033[0m  %s%s\n", $name, $detail ? "  ($detail)" : ''); }
    else       { $fail++; printf("  \033[31mFAIL\033[0m  %s%s\n", $name, $detail ? "  ($detail)" : ''); }
}

require_once __DIR__ . '/support/bootstrap.php';

define('DEJAVU', dejavu_dir());

function tree(string $html, string $css = '', float $w = 400.0): Node
{
    $d = new DOMDocument();
    libxml_use_internal_errors(true);
    $d->loadHTML('<?xml encoding="UTF-8">' . ($css !== '' ? "<style>$css</style>" : '') . $html,
        LIBXML_NOERROR | LIBXML_NOWARNING);
    libxml_clear_errors();
    $r = new StyleResolver();
    $r->addStylesheet(SUITE_BODY_RESET);
    if ($css !== '') { $r->addStylesheet($css); }
    $t = (new HtmlBuilder($r))->build($d);
    (new FlexLayout())->layout($t, $w, 800.0);

    // Defect DG: laid out from the real root, asserted against the body.
    return bodyOf($t);
}

function firstText(Node $n): ?Node
{
    if ($n->display === 'text' && $n->runs !== []) { return $n; }
    foreach ($n->children as $c) { $r = firstText($c); if ($r !== null) { return $r; } }
    return null;
}

function pdfText(string $pdf): string
{
    return (string) shell_exec(
        'python3 -c "from pypdf import PdfReader; import sys;'
        . "r=PdfReader('$pdf');"
        . 'print(chr(10).join(p.extract_text() for p in r.pages))"'
    );
}

echo "\nCustom properties, calc, fonts, margins, borders, running elements\n\n";

// 1. calc() ----------------------------------------------------------
$sr = new StyleResolver();
ok('calc with subtraction and multiplication',
    abs($sr->length('calc(100pt - 2*10pt)', 12.0, 12.0) - 80.0) < 0.01);
ok('calc mixing a percentage with an absolute length',
    abs($sr->length('calc(50% + 10pt)', 12.0, 12.0, 200.0) - 110.0) < 0.01);
ok('calc respects parentheses',
    abs($sr->length('calc((4 + 2) * 3pt)', 12.0, 12.0) - 18.0) < 0.01);
ok('calc resolves em against the element and rem against the root',
    abs($sr->length('calc(2em + 1rem)', 10.0, 12.0) - 32.0) < 0.01);
ok('a malformed calc yields null rather than a wrong number',
    $sr->length('calc(10pt +)', 12.0, 12.0) === null);
ok('a percentage in calc without a basis yields null',
    $sr->length('calc(50% + 10pt)', 12.0, 12.0, null) === null);

// 2. Custom properties -----------------------------------------------
$t = tree('<div class="a">x</div><div class="b">y</div>',
    ':root { --pad: 8pt; --brand: #2466d9; --big: calc(var(--pad) * 2) }
     .a { padding: var(--pad); color: var(--brand); width: var(--big); margin-left: var(--nope, 17pt) }
     .b { --pad: 20pt; padding: var(--pad) }');
$a = $t->children[0];
$b = $t->children[1];
ok('a custom property declared on :root reaches a descendant',
    abs($a->padding['left'] - 8.0) < 0.01, sprintf('%.1fpt', $a->padding['left']));
ok('custom properties can reference each other through calc',
    abs((float) $a->width - 16.0) < 0.01, sprintf('%.1fpt', (float) $a->width));
ok('var() falls back when the property is undefined',
    abs($a->margin['left'] - 17.0) < 0.01);
ok('a colour can come from a custom property',
    abs($a->color[2] - 0xd9 / 255) < 0.01);
ok('a local redefinition shadows the inherited value',
    abs($b->padding['left'] - 20.0) < 0.01, sprintf('%.1fpt', $b->padding['left']));

// 3. Italic ----------------------------------------------------------
FontRegistry::reset();
FontRegistry::default()->registerTrueType('DejaVu',
    DEJAVU . 'DejaVuSans.ttf', DEJAVU . 'DejaVuSans-Bold.ttf',
    DEJAVU . 'DejaVuSans-Oblique.ttf', DEJAVU . 'DejaVuSans-BoldOblique.ttf');

$t = tree('<p>plain <b>bold</b> <i>italic</i> <b><i>both</i></b></p>');
$runs = array_values(array_filter(firstText($t)->runs, fn(InlineRun $r) => trim($r->text) !== ''));
$flags = array_map(fn(InlineRun $r) => ((int) $r->bold) . ((int) $r->italic), $runs);
ok('font-style flows into runs for b, i and nested b>i',
    $flags === ['00', '10', '01', '11'], implode(' ', $flags));

$reg = FontRegistry::default();
$faces = [];
foreach ([[false,false],[true,false],[false,true],[true,true]] as [$bo, $it]) {
    $faces[] = $reg->get('DejaVu', $bo, $it)->postScriptName;
}
ok('all four slots resolve to distinct faces',
    count(array_unique($faces)) === 4, implode(', ', $faces));
ok('a missing italic falls back to the regular weight, not to base-14',
    (function () {
        $r = new FontRegistry();
        $r->registerBase14();
        $r->registerTrueType('Solo', DEJAVU . 'DejaVuSerif.ttf');
        return $r->get('Solo', false, true) === $r->get('Solo', false, false);
    })());

// 4. @font-face ------------------------------------------------------
FontRegistry::reset();
$out = '/tmp/feat_ff.pdf';
$pages = Html::make(
    '<style>
       @font-face { font-family: Body; src: url("' . DEJAVU . 'DejaVuSans.ttf") }
       @font-face { font-family: Body; src: url("' . DEJAVU . 'DejaVuSans-Bold.ttf"); font-weight: bold }
       @font-face { font-family: Body; src: url("' . DEJAVU . 'DejaVuSans-Oblique.ttf"); font-style: italic }
       body { font-family: Body }
     </style>
     <p>Regular, <b>bold</b>, <i>italic</i> — Kraków Привет</p>'
)->basePath(DEJAVU)->save($out);
$meta = (string) shell_exec(
    'python3 ' . escapeshellarg(__DIR__ . '/support/fontprobe.py') . ' ' . escapeshellarg($out)
);
[$faceCount, $allEmbedded] = array_map('trim', explode("\n", trim($meta)));
ok('three @font-face declarations register three embedded faces',
    (int) $faceCount === 3 && $allEmbedded === 'True', "$faceCount faces");
ok('text set in an @font-face family still round-trips',
    str_contains(pdfText($out), 'Kraków Привет'));

// 5. Margin collapsing -----------------------------------------------
$t = tree('<div class="a">one</div><div class="a">two</div>', '.a { margin: 20pt 0 }');
$gap = $t->children[1]->y - ($t->children[0]->y + $t->children[0]->layoutHeight);
ok('equal adjoining sibling margins collapse to one',
    abs($gap - 20.0) < 0.01, sprintf('%.1fpt, not 40', $gap));

$t = tree('<div class="a">one</div><div class="b">two</div>',
    '.a { margin-bottom: 30pt } .b { margin-top: 10pt }');
$gap = $t->children[1]->y - ($t->children[0]->y + $t->children[0]->layoutHeight);
ok('unequal sibling margins collapse to the larger',
    abs($gap - 30.0) < 0.01, sprintf('%.1fpt', $gap));

$t = tree('<div class="a">one</div><div class="b">two</div>',
    '.a { margin-bottom: 30pt } .b { margin-top: -10pt }');
$gap = $t->children[1]->y - ($t->children[0]->y + $t->children[0]->layoutHeight);
ok('a negative margin is added rather than maxed',
    abs($gap - 20.0) < 0.01, sprintf('%.1fpt', $gap));

// The margin escapes `.p`, so the two boxes share a top edge, and the root
// keeps it rather than letting it out of the document. Chrome puts both at
// 25.00; asserting the child sat at an absolute 0 asserted the leak.
$t = tree('<div class="p"><div class="c">x</div></div>', '.c { margin-top: 25pt }');
$p = $t->children[0];
ok('a first child margin escapes through an open parent edge',
    abs($p->children[0]->y - $p->y) < 0.01 && abs($p->y - 25.0) < 0.01,
    sprintf('parent %.1fpt, child %.1fpt', $p->y, $p->children[0]->y));

$t = tree('<div class="p"><div class="c">x</div></div>',
    '.p { padding-top: 5pt } .c { margin-top: 25pt }');
ok('padding on the parent stops the margin escaping',
    abs($t->children[0]->children[0]->y - 30.0) < 0.01,
    sprintf('%.1fpt', $t->children[0]->children[0]->y));

$t = tree('<div class="f"><div class="c">a</div><div class="c">b</div></div>',
    '.f { display: flex; flex-direction: column } .c { margin: 20pt 0 }');
$gap = $t->children[0]->children[1]->y - ($t->children[0]->children[0]->y + $t->children[0]->children[0]->layoutHeight);
ok('flex items do NOT collapse their margins',
    abs($gap - 40.0) < 0.01, sprintf('%.1fpt, correctly not 20', $gap));

// 6. border-collapse -------------------------------------------------
$t = tree('<table><tr><td>a</td><td>b</td></tr><tr><td>c</td><td>d</td></tr></table>',
    'table { border-collapse: collapse } td { border: 1pt solid #000 }');
$tbl = $t->children[0];
$edges = [];
foreach ($tbl->children as $row) {
    foreach ($row->children as $c) { $edges[] = implode(',', $c->borderEdges); }
}
ok('collapsed cells draw each shared grid line exactly once',
    $edges === ['top,left', 'top,left,right', 'top,left,bottom', 'top,left,right,bottom'],
    implode(' | ', $edges));

$t = tree('<table><tr><td>a</td><td>b</td></tr></table>',
    'td { border: 1pt solid #000 }');
ok('separate borders still stroke all four edges',
    count($t->children[0]->children[0]->children[0]->borderEdges) === 4);

// 7. colgroup --------------------------------------------------------
$t = tree('<table><colgroup><col style="width:80pt"><col><col style="width:60pt"></colgroup>'
    . '<tr><td>aaa</td><td>bbbbbbbbbbbb</td><td>ccc</td></tr></table>');
$w = array_map(fn(Node $c) => round($c->layoutWidth, 1), $t->children[0]->children[0]->children);
// Chrome: 79.99 | 61.57 | 60.00. The pinned columns take their width and the
// free one takes its content, so the table stops at 201.56 rather than 400.
// Re-measured in round 18o with defect BV: the free column carried 4pt of UA
// padding this engine had and Chrome did not.
ok('col widths pin their columns and the free one takes its content',
    abs($w[0] - 80.0) < 0.5 && abs($w[2] - 60.0) < 0.5 && abs(array_sum($w) - 201.5) < 1.0,
    implode(' | ', $w));

$t = tree('<table><colgroup><col span="2" style="width:50pt"></colgroup>'
    . '<tr><td>a</td><td>b</td><td>c</td></tr></table>');
$w = array_map(fn(Node $c) => round($c->layoutWidth, 1), $t->children[0]->children[0]->children);
ok('col span applies one width to several columns',
    abs($w[0] - 50.0) < 0.5 && abs($w[1] - 50.0) < 0.5, implode(' | ', $w));

// 8. Shrink-to-fit regression ----------------------------------------
// A block container measured with no available width used to return zero,
// collapsing every block-level flex item down to its longest word.
$t = tree('<div class="row"><div><b>Northbound Systems</b></div><div>Quarterly statement</div></div>',
    '.row { display: flex; justify-content: space-between }');
$item = $t->children[0]->children[0];
$text = firstText($item);
ok('a block-level flex item sizes to its content, not its longest word',
    count($text->lineBoxes) === 1,
    count($text->lineBoxes) . ' line box(es)');

// 9. Running headers and footers -------------------------------------
$out = '/tmp/feat_hf.pdf';
$pages = Html::make('<style>p{margin:0 0 9pt 0}</style>'
    . str_repeat('<p>Body text long enough to spill over onto several pages for checking.</p>', 60))
    ->header('<div style="font-size:8px">Running header</div>')
    ->footer('<div style="font-size:8px">Page {page} of {pages}</div>')
    ->save($out);
$text = pdfText($out);
$headers = substr_count($text, 'Running header');
$numbered = 0;
for ($i = 1; $i <= $pages; $i++) {
    if (str_contains(str_replace("\n", ' ', $text), "Page $i of $pages")) { $numbered++; }
}
ok('the header repeats on every page', $pages > 1 && $headers === $pages, "$headers of $pages");
ok('{page} and {pages} are substituted per page', $numbered === $pages, "$numbered of $pages");

// 10. The initial view, and files carried inside the document ---------
//
// Round 91, the two open feature rows under `K. PDF document features`. Both
// are catalog entries, so both are read back out of the written file rather
// than off the builder: what the caller asked for is not evidence that the
// writer wrote it.
$viewDoc = '<style>body{font-family:Helvetica;font-size:10pt}</style>'
    . '<p>page one</p><div style="break-before:page"></div><p>page two</p>';

[$viewBytes] = Html::make($viewDoc)->page(300.0, 300.0)->margin(20.0)
    ->initialView('FitH', 2, [280.0])
    ->pageMode('UseOutlines')
    ->render();

preg_match('#/OpenAction \[(\d+) 0 R /(\w+)([^\]]*)\]#', $viewBytes, $open);
preg_match('#/PageMode /(\w+)#', $viewBytes, $mode);

ok('the initial view is written as a destination array, not as an action',
    ($open[2] ?? '') === 'FitH' && trim($open[3] ?? '') === '280',
    ($open[0] ?? 'no /OpenAction at all'));

// The second page's own object number, so the destination is checked against
// the page it names rather than against the number happening to be there.
preg_match_all('#(\d+) 0 obj\s*<< /Type /Page /#', $viewBytes, $pageNums);

ok('and it names the page the caller asked for',
    ($pageNums[1][1] ?? '') === ($open[1] ?? ''),
    sprintf('destination %s, page two is %s', $open[1] ?? '?', $pageNums[1][1] ?? '?'));

ok('the page mode is written, so a document with bookmarks can open showing them',
    ($mode[1] ?? '') === 'UseOutlines',
    $mode[0] ?? 'no /PageMode at all');

// Round-tripped through the file, because an attachment nobody can take back
// out is not an attachment. The payload is deliberately not ASCII-only: a
// filespec name and a stream are two different escapes and only one of them
// is the text-string one.
$payload = '<?xml version="1.0" encoding="UTF-8"?><invoice><total>12,50 EUR</total></invoice>';

[$fileBytes] = Html::make('<p>Invoice</p>')->page(300.0, 300.0)->margin(20.0)
    ->attach('factur-x.xml', $payload, 'text/xml', 'e-invoice payload', 'Alternative')
    ->render();

// The embedded stream is deflated like every other, so the payload is read
// back through the filter rather than searched for in the file.
$recovered = '';

// `[^>]*` cannot reach it: the dictionary carries a `/Params << ... >>` of its
// own, so the first `>>` in it is not the end of anything.
if (preg_match('#/Type /EmbeddedFile.*?stream\r?\n(.*?)\r?\nendstream#s', $fileBytes, $found) === 1) {
    $recovered = @gzuncompress($found[1]);

    if ($recovered === false) { $recovered = $found[1]; }
}

ok('an attached file comes back out of the written PDF byte for byte',
    $recovered === $payload,
    sprintf('%d bytes recovered of %d', strlen((string) $recovered), strlen($payload)));

ok('and it is an ASSOCIATED file, which is the half PDF/A-3 exists for',
    str_contains($fileBytes, '/AFRelationship /Alternative')
        && preg_match('#/AF \[\d+ 0 R\]#', $fileBytes) === 1
        && str_contains($fileBytes, '/EmbeddedFiles'),
    'AF ' . (preg_match('#/AF \[[^\]]*\]#', $fileBytes, $af) === 1 ? $af[0] : 'absent'));

// A MIME type is not a PDF name token: `/text/xml` is two tokens and a stray
// solidus, and a reader that meets one stops reading the dictionary.
ok('the media type is escaped into a legal name token',
    str_contains($fileBytes, '/Subtype /text#2Fxml'),
    preg_match('#/Type /EmbeddedFile /Subtype /[^\s]+#', $fileBytes, $st) === 1 ? $st[0] : 'no /Subtype');

// The byte-safety half. Neither feature may cost a document that asks for
// neither a single byte, which is the same promise round 90's fallback made.
[$plainA] = Html::make($viewDoc)->page(300.0, 300.0)->margin(20.0)->render();
[$plainB] = Html::make($viewDoc)->page(300.0, 300.0)->margin(20.0)
    ->initialView('Fit')->pageMode('UseNone')->attach('x.txt', 'x')->render();

ok('CONTROL: a document that asks for neither is written exactly as before',
    !str_contains($plainA, '/OpenAction')
        && !str_contains($plainA, '/PageMode')
        && !str_contains($plainA, '/EmbeddedFiles')
        && strlen($plainB) > strlen($plainA),
    sprintf('%d bytes plain, %d with all three', strlen($plainA), strlen($plainB)));

printf("\n  %d passed, %d failed\n\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
