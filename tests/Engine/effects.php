<?php
declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/support/bootstrap.php';

use FlexPDF\Engine\{StyleResolver, HtmlBuilder, FlexLayout, Node, Html, Hyphenator,
              InlineFormatter, InlineRun};

$pass = 0; $fail = 0;
function ok(string $name, bool $cond, string $detail = ''): void
{
    global $pass, $fail;
    if ($cond) { $pass++; printf("  \033[32mPASS\033[0m  %s%s\n", $name, $detail ? "  ($detail)" : ''); }
    else       { $fail++; printf("  \033[31mFAIL\033[0m  %s%s\n", $name, $detail ? "  ($detail)" : ''); }
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

function tree(string $html, string $css = '', float $w = 400.0): Node
{
    $d = new DOMDocument();
    libxml_use_internal_errors(true);
    $d->loadHTML('<?xml encoding="UTF-8"><style>' . $css . '</style>' . $html,
        LIBXML_NOERROR | LIBXML_NOWARNING);
    libxml_clear_errors();
    $r = new StyleResolver();
    $r->addStylesheet(SUITE_BODY_RESET);
    if ($css !== '') { $r->addStylesheet($css); }
    $t = (new HtmlBuilder($r))->build($d);
    $t->width = $w;
    (new FlexLayout())->layout($t, $w, 700.0);

    // Defect DG: laid out from the real root, asserted against the body.
    return bodyOf($t);
}

/** Render to PDF and report the vertical extent of dark ink, in points. */
function inkExtent(string $html, string $css): array
{
    $out = '/tmp/effects_probe.pdf';
    @unlink($out);
    Html::make('<style>' . SUITE_BODY_RESET . $css . '</style>' . $html)->margin(50.0)->save($out);
    $probe = trim((string) shell_exec(
        'python3 -c "'
        . 'import pypdfium2 as p, numpy as np;'
        . " a=np.array(p.PdfDocument('$out')[0].render(scale=1).to_pil().convert('L')).astype(int);"
        . ' ys=(a<100).nonzero()[0];'
        . ' print(int(ys.min()), int(ys.max())) if len(ys) else print(-1,-1)"'
    ));
    return array_map('intval', explode(' ', $probe));
}

echo "\nClipping, images, effects, columns and hyphenation\n\n";

// 1. overflow: hidden -------------------------------------------------
$css = '.box{width:100pt;height:30pt}.inner{width:100pt;height:200pt;background:#000}';
[$clipTop, $clipBottom] = inkExtent('<div class="box" style="overflow:hidden"><div class="inner"></div></div>', $css);
[$openTop, $openBottom] = inkExtent('<div class="box"><div class="inner"></div></div>', $css);

ok('overflow: hidden clips a child to its parent box',
    $clipBottom <= 82 && $clipTop >= 48,
    "ink y=$clipTop..$clipBottom, box is 50..80");
ok('overflow: visible lets the child overflow',
    $openBottom > 200, "ink y=$openTop..$openBottom");

[$nestTop, $nestBottom] = inkExtent(
    '<div class="o"><div class="m"><div class="i"></div></div></div>',
    '.o{overflow:hidden;height:60pt;width:120pt}.m{overflow:hidden;height:200pt;width:120pt}'
    . '.i{height:300pt;width:120pt;background:#000}'
);
ok('nested clips intersect, so the tightest one wins',
    $nestBottom <= 112, "ink y=$nestTop..$nestBottom, outer clips at 110");

// 2. object-fit -------------------------------------------------------
$png = '/tmp/effects_img.png';
if (!is_file($png)) {
    $im = imagecreatetruecolor(200, 50);
    imagefill($im, 0, 0, imagecolorallocate($im, 40, 100, 220));
    imagepng($im, $png);
    imagedestroy($im);
}

$t   = tree('<img src="' . $png . '" style="width:100pt;height:100pt;object-fit:contain">');
$img = atomicBox($t);
ok('object-fit does not change the box, only what is drawn inside it',
    $img !== null
    && abs($img->layoutWidth - 100.0) < 0.5
    && abs($img->layoutHeight - 100.0) < 0.5
    && $img->objectFit === 'contain');

$out = '/tmp/effects_fit.pdf';
Html::make('<img src="' . $png . '" style="width:100pt;height:100pt;object-fit:cover">')
    ->basePath(dirname($png))
    ->save($out);
$clips = (int) trim((string) shell_exec(
    'python3 -c "from pypdf import PdfReader;'
    . " print(PdfReader('$out').pages[0].get_contents().get_data().decode('latin-1').count('W n'))\""
));
ok('object-fit: cover clips the overflowing image', $clips >= 1, "$clips clip op(s)");

// 3. Blend modes and opacity -----------------------------------------
$out = '/tmp/effects_blend.pdf';
Html::make('<div style="mix-blend-mode:multiply;opacity:0.5;background:#4a4;width:80pt;height:30pt"></div>')
    ->save($out);
$states = trim((string) shell_exec(
    'python3 -c "from pypdf import PdfReader;'
    . " r=PdfReader('$out'); print(','.join(sorted(str(k) for k in r.pages[0]['/Resources']['/ExtGState'].keys())))\""
));
ok('opacity and a blend mode each emit a graphics state',
    substr_count($states, ',') === 1, $states);

// 4. text-shadow ------------------------------------------------------
$t = tree('<div style="text-shadow:2pt 3pt 1pt #ff0000">shadowed</div>');
$shadow = $t->children[0]->textShadow[0] ?? null;
ok('text-shadow parses offset, blur and colour',
    $shadow !== null && abs($shadow['x'] - 2.0) < 0.01 && abs($shadow['y'] - 3.0) < 0.01
    && $shadow['color'] === [1.0, 0.0, 0.0]);

$out = '/tmp/effects_shadow.pdf';
Html::make('<div style="font-size:20pt;text-shadow:2pt 2pt 0 #ff0000">Shadowed</div>')->save($out);
$reds = (int) trim((string) shell_exec(
    'python3 -c "import pypdfium2 as p, numpy as np;'
    . " a=np.array(p.PdfDocument('$out')[0].render(scale=1).to_pil().convert('RGB')).astype(int);"
    . ' print(int((((a[:,:,0]-a[:,:,2])>60)&(a[:,:,0]>150)).sum()))"'
));
ok('the shadow is actually drawn', $reds > 20, "$reds red pixels");

// 5. Multi-column -----------------------------------------------------
$t = tree('<div class="c">' . str_repeat('<p>Column text. </p>', 12) . '</div>',
    '.c{column-count:3;column-gap:12pt;width:400pt}p{margin:0}');
$columns = [];
foreach ($t->children[0]->children as $child) {
    $columns[(string) round($child->x, 0)][] = $child;
}
ok('column-count splits content into that many columns',
    count($columns) === 3, count($columns) . ' columns at x=' . implode(',', array_keys($columns)));
ok('columns are balanced to a similar height',
    count(array_unique(array_map('count', $columns))) === 1,
    implode('/', array_map('count', $columns)) . ' items per column');

$t = tree('<div class="c">' . str_repeat('<p>x</p>', 8) . '</div>',
    '.c{column-width:100pt;column-gap:10pt;width:430pt}p{margin:0}');
$columns = [];
foreach ($t->children[0]->children as $child) {
    $columns[(string) round($child->x, 0)] = true;
}
ok('column-width fits as many columns as the container allows',
    count($columns) === 4, count($columns) . ' columns');

// 6. Hyphenation ------------------------------------------------------
ok('the hyphenator finds correct English break points',
    Hyphenator::split('representation') === ['represen', 'tation']
    && Hyphenator::split('running') === ['run', 'ning'],
    implode('-', Hyphenator::split('typography')));

ok('short words are never broken',
    Hyphenator::split('cat') === ['cat'] && Hyphenator::split('the') === ['the']);

ok('a soft hyphen is honoured even with hyphens: manual',
    Hyphenator::split("extra\u{00AD}ordinary", false) === ['extra', 'ordinary']);

$formatter = new InlineFormatter();
$text = 'The representation of typography requires running';
$make = static fn(string $mode): array => [new InlineRun(
    $text, 10.0, false, [0, 0, 0], 1.35, 'Helvetica', false, false, 'baseline', 'auto', $mode
)];

$none = $formatter->format($make('none'), 70.0, 'left');
$auto = $formatter->format($make('auto'), 70.0, 'left');
ok('hyphens: auto fits more into a narrow column',
    count($auto) < count($none), count($none) . ' lines to ' . count($auto));

$firstLine = implode('', array_map(static fn($i) => $i->text, $auto[0]->items));
ok('a broken word gains a visible hyphen',
    str_ends_with($firstLine, '-'), $firstLine);

$wide = $formatter->format([new InlineRun(
    "A supercali\u{00AD}fragilistic word", 10.0, false, [0, 0, 0], 1.35,
    'Helvetica', false, false, 'baseline', 'auto', 'manual'
)], 300.0, 'left');
$whole = implode('', array_map(static fn($i) => $i->text, $wide[0]->items));
ok('an unused soft hyphen never renders',
    !str_contains($whole, "\u{00AD}") && str_contains($whole, 'supercalifragilistic'), $whole);

printf("\n  %d passed, %d failed\n\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
