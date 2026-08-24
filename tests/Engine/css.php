<?php
declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/support/bootstrap.php';

use FlexPDF\Engine\{CssParser, Selector, StyleResolver, HtmlBuilder, Html, Node, FlexLayout, InlineRun};

$pass = 0; $fail = 0;
function ok(string $name, bool $cond, string $detail = ''): void
{
    global $pass, $fail;
    if ($cond) { $pass++; printf("  \033[32mPASS\033[0m  %s%s\n", $name, $detail ? "  ($detail)" : ''); }
    else       { $fail++; printf("  \033[31mFAIL\033[0m  %s%s\n", $name, $detail ? "  ($detail)" : ''); }
}

function dom(string $html): DOMDocument
{
    $d = new DOMDocument();
    libxml_use_internal_errors(true);
    $d->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOERROR | LIBXML_NOWARNING);
    libxml_clear_errors();
    return $d;
}

/** Build a box tree from html + css. */
function tree(string $html, string $css = '', string $basePath = ''): Node
{
    $r = new StyleResolver();
    $r->basePath = $basePath;
    $r->addStylesheet(SUITE_BODY_RESET);
    if ($css !== '') { $r->addStylesheet($css); }
    // Defect DG: the tree is `html > body > ...` now, and every assertion here
    // is about the body's children. See `bodyOf()` in support/bootstrap.php.
    return bodyOf((new HtmlBuilder($r))->build(dom($html)));
}

/** The first atomic inline box in the tree: an <img> or an inline-block. */
function atomicBox(Node $n): ?Node
{
    foreach ($n->runs as $run) {
        if ($run->box !== null) { return $run->box; }
    }

    foreach ($n->children as $child) {
        $box = atomicBox($child);
        if ($box !== null) { return $box; }
    }

    return null;
}

function findText(Node $n, array &$out = []): array
{
    if ($n->display === 'text') { $out[] = $n; }
    foreach ($n->children as $c) { findText($c, $out); }
    return $out;
}

echo "\nCSS parsing, cascade and box tree construction\n\n";

// 1. Parsing ---------------------------------------------------------
$p = new CssParser();
$p->parse('
    /* a comment { with braces } */
    a, b .c { color: red; margin: 0 auto !important }
    @media screen { .never { color: lime } }
    @media print { .yes { color: blue } }
    @page { size: A4; margin: 2cm }
');
ok('parses rules, selector lists and comments',
    count($p->rules) === 3, count($p->rules) . ' rules');
ok('@media print is honoured, @media screen is dropped',
    array_filter($p->rules, fn($r) => str_contains($r->selector->source, 'yes')) !== []
    && array_filter($p->rules, fn($r) => str_contains($r->selector->source, 'never')) === []);
ok('@page is captured', ($p->page['margin']['value'] ?? '') === '2cm');
ok('!important is detected',
    $p->rules[0]->declarations['margin']['important'] === true
    && $p->rules[0]->declarations['color']['important'] === false);

// 2. Specificity -----------------------------------------------------
$spec = fn(string $s): int => Selector::parse($s)->specificity;
ok('specificity ordering: id > class > type',
    $spec('#a') > $spec('.a') && $spec('.a') > $spec('a'),
    sprintf('%d > %d > %d', $spec('#a'), $spec('.a'), $spec('a')));
ok('specificity accumulates across a compound selector',
    $spec('div.a.b') > $spec('div.a') && $spec('div.a') > $spec('div'));
ok('attribute and pseudo count as class-level',
    $spec('[href]') === $spec('.x') && $spec(':first-child') === $spec('.x'));

// 3. Matching --------------------------------------------------------
$d = dom('<div id="wrap" class="box hero"><p class="lead">one</p><p>two</p><span data-k="v">s</span></div>');
$r = new StyleResolver();
$wrap = $d->getElementById('wrap');
$ps = $d->getElementsByTagName('p');
$span = $d->getElementsByTagName('span')->item(0);

$m = fn(string $sel, $el): bool => $r->matches($el, Selector::parse($sel));
ok('type, class and id selectors match', $m('div', $wrap) && $m('.hero', $wrap) && $m('#wrap', $wrap));
ok('compound selector requires every part', $m('div.box.hero#wrap', $wrap) && !$m('div.box.missing', $wrap));
ok('descendant combinator', $m('div p', $ps->item(0)) && !$m('span p', $ps->item(0)));
ok('child combinator', $m('div > p', $ps->item(0)));
ok('adjacent sibling combinator', $m('p + p', $ps->item(1)) && !$m('p + p', $ps->item(0)));
ok('attribute selectors', $m('[data-k]', $span) && $m('[data-k=v]', $span) && !$m('[data-k=z]', $span));
ok(':first-child / :last-child / :nth-child',
    $m('p:first-child', $ps->item(0)) && !$m('p:first-child', $ps->item(1))
    && $m(':nth-child(2)', $ps->item(1)) && $m(':nth-child(odd)', $ps->item(0)));

// 4. Cascade ---------------------------------------------------------
$css = '
  p { color: #ff0000 }
  .x { color: #00ff00 }
  #i { color: #0000ff }
  p { color: #ffff00 }
';
$t = tree('<p id="i" class="x" >hello</p>', $css);
$texts = findText($t);
ok('higher specificity wins regardless of order',
    $texts[0]->runs[0]->color === [0.0, 0.0, 1.0, 1.0], 'id beat class and type');

$t = tree('<p class="x">hello</p>', 'p { color: #ff0000 } p { color: #00ff00 }');
ok('source order breaks specificity ties',
    findText($t)[0]->runs[0]->color === [0.0, 1.0, 0.0, 1.0]);

$t = tree('<p class="x">hi</p>', 'p { color: #ff0000 !important } #z, .x { color: #00ff00 }');
ok('!important beats higher specificity',
    findText($t)[0]->runs[0]->color === [1.0, 0.0, 0.0, 1.0]);

$t = tree('<p style="color:#00ffff">hi</p>', 'p { color: #ff0000 }');
ok('inline style attribute beats stylesheet rules',
    findText($t)[0]->runs[0]->color === [0.0, 1.0, 1.0, 1.0]);

// 5. Inheritance -----------------------------------------------------
$t = tree('<div class="p"><p>child</p></div>', '.p { color: #123456; margin-left: 40px }');
$run = findText($t)[0]->runs[0];
ok('color inherits into descendants',
    abs($run->color[0] - 0x12 / 255) < 0.001 && abs($run->color[2] - 0x56 / 255) < 0.001);
$outer = $t->children[0];                 // the div, which declared the margin
$inner = $outer->children[0];             // the p inside it
ok('margin applies to the declaring element but does not inherit',
    abs($outer->margin['left'] - 30.0) < 0.01 && abs($inner->margin['left']) < 0.001,
    sprintf('div %.1fpt, p %.1fpt', $outer->margin['left'], $inner->margin['left']));

// 6. Units -----------------------------------------------------------
$t = tree('<div id="a"></div>', '#a { width: 96px; height: 72pt; margin-left: 1in; padding-left: 2em; font-size: 20px }');
$box = $t->children[0];
ok('px converts at 96dpi (96px = 72pt)', abs($box->width - 72.0) < 0.01, sprintf('%.2fpt', $box->width));
ok('pt passes through', abs($box->height - 72.0) < 0.01);
ok('in converts (1in = 72pt)', abs($box->margin['left'] - 72.0) < 0.01);
ok('em resolves against the element font-size (20px = 15pt, 2em = 30pt)',
    abs($box->padding['left'] - 30.0) < 0.01, sprintf('%.2fpt', $box->padding['left']));

$t = tree('<div id="a"></div>', '#a { width: 50% }');
ok('percentages stay symbolic for the layout engine to resolve',
    $t->children[0]->width === '50%');

// 7. Colors ----------------------------------------------------------
$sr = new StyleResolver();
ok('#rgb shorthand expands', $sr->color('#f00') === [1.0, 0.0, 0.0]);
ok('#rrggbb parses', $sr->color('#00ff00') === [0.0, 1.0, 0.0]);
ok('rgb() parses', $sr->color('rgb(0, 0, 255)') === [0.0, 0.0, 1.0]);
ok('named colors resolve', $sr->color('navy') === [0.0, 0.0, 128 / 255]);
ok('transparent and rgba(...,0) yield null',
    $sr->color('transparent') === null && $sr->color('rgba(1,2,3,0)') === null);

// 8. Shorthands ------------------------------------------------------
$t = tree('<div id="a"></div>', '#a { margin: 4pt 8pt 12pt 16pt; padding: 2pt 6pt }');
$b = $t->children[0];
ok('margin shorthand, four values',
    [$b->margin['top'], $b->margin['right'], $b->margin['bottom'], $b->margin['left']] === [4.0, 8.0, 12.0, 16.0]);
ok('padding shorthand, two values',
    [$b->padding['top'], $b->padding['right'], $b->padding['bottom'], $b->padding['left']] === [2.0, 6.0, 2.0, 6.0]);

$t = tree('<div id="a"></div>', '#a { flex: 2 }');
$b = $t->children[0];
ok('flex: <number> expands to grow/shrink/basis',
    $b->flexGrow === 2.0 && $b->flexShrink === 1.0 && $b->flexBasis === 0.0);

$t = tree('<div id="a"></div>', '#a { border: 2pt solid #ff0000 }');
$b = $t->children[0];
ok('border shorthand expands to width/style/color on all four edges',
    $b->border !== null
    && count($b->border) === 4
    && abs($b->border['top']['width'] - 2.0) < 0.01
    && $b->border['left']['color'] === [1.0, 0.0, 0.0, 1.0]);

// 9. Inline flattening -----------------------------------------------
$t = tree('<p>Hello <b>bold</b> and <i>italic</i> text</p>');
$texts = findText($t);
ok('an inline-only block becomes ONE text box',
    count($texts) === 1, count($texts) . ' text boxes');
ok('each styled span becomes its own run',
    count($texts[0]->runs) >= 5, count($texts[0]->runs) . ' runs');
$bold = array_values(array_filter($texts[0]->runs, fn(InlineRun $r) => $r->bold));
ok('<b> produces a bold run', count($bold) === 1 && trim($bold[0]->text) === 'bold');

// 10. display:none ---------------------------------------------------
$t = tree('<div><p>visible</p><p class="h">hidden</p></div>', '.h { display: none }');
$all = implode(' ', array_map(fn(Node $n) => implode('', array_map(fn($r) => $r->text, $n->runs)), findText($t)));
ok('display:none subtrees are excluded from the box tree',
    str_contains($all, 'visible') && !str_contains($all, 'hidden'), trim($all));

// 11. Anonymous boxes ------------------------------------------------
$t = tree('<div>before<p>block</p>after</div>');
$texts = findText($t);
ok('mixed inline/block content gets anonymous boxes around the inline runs',
    count($texts) === 3, count($texts) . ' text boxes');

// 12. Flex from CSS end-to-end ---------------------------------------
$t = tree(
    '<div class="row"><div class="c">a</div><div class="c">b</div><div class="c">c</div></div>',
    '.row { display: flex; gap: 10pt } .c { flex: 1 }'
);
(new FlexLayout())->layout($t, 310.0, 500.0);
$row = $t->children[0];
$xs = array_map(fn(Node $n) => round($n->x, 2), $row->children);
$ws = array_map(fn(Node $n) => round($n->layoutWidth, 2), $row->children);
ok('display:flex + flex:1 + gap lay out through the real engine',
    $ws === [96.67, 96.67, 96.67] && $xs === [0.0, 106.67, 213.33],
    'x=' . implode(',', $xs) . ' w=' . implode(',', $ws));

// 13. Images ---------------------------------------------------------
$png = '/tmp/css_test.png';
$im = imagecreatetruecolor(200, 100);
imagefill($im, 0, 0, imagecolorallocate($im, 40, 100, 220));
imagepng($im, $png);
imagedestroy($im);

$imgBase = dirname($png);

$t = tree('<img src="' . $png . '">', '', $imgBase);
$img = atomicBox($t);
ok('image intrinsic size is read from the file',
    $img->image !== null && $img->image->width === 200 && $img->image->height === 100,
    $img->image ? "{$img->image->width}x{$img->image->height}" : 'not loaded');

$t = tree('<img src="' . $png . '" style="width:100pt">', '', $imgBase);
$img = atomicBox($t);
ok('aspect ratio is preserved when only one axis is given',
    abs((float) $img->width - 100.0) < 0.01 && abs((float) $img->height - 50.0) < 0.01,
    sprintf('%.1f x %.1f', (float) $img->width, (float) $img->height));

// 14. Full pipeline --------------------------------------------------
$out = '/tmp/css_pipeline.pdf';
$pages = Html::make('<style>body{font-size:11px} .a{display:flex;gap:8pt} .a div{flex:1}</style>'
    . '<h1>Title</h1><div class="a"><div>left</div><div>right</div></div>'
    . str_repeat('<p>Filler paragraph that should push content onto a second page. </p>', 60))
    ->save($out);
$extracted = trim((string) shell_exec(
    'python3 -c "from pypdf import PdfReader; r=PdfReader(\'' . $out . '\');'
    . 'print(len(r.pages)); print(r.pages[0].extract_text()[:40])"'
));
[$pageCount] = explode("\n", $extracted);
ok('HTML in, paginated PDF out',
    $pages > 1 && (int) $pageCount === $pages, "$pages pages");

printf("\n  %d passed, %d failed\n\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
