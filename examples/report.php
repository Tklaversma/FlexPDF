<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use FlexPDF\Engine\Html;

require_once __DIR__ . '/../tests/Engine/support/bootstrap.php';

define('DEJAVU', dejavu_dir());

$regions = ['Noord-Holland', 'Zuid-Holland', 'Utrecht', 'Gelderland', 'Noord-Brabant', 'Limburg'];
$products = ['Anchor plate', 'Winch cable', 'Bilge pump', 'Mooring line', 'Deck cleat',
             'Shackle 12mm', 'Fender — large', 'Navigation lamp', 'Chain 8mm',
             'Bow roller', 'Stanchion base', 'Hatch seal'];

$body = '';
$grand = 0.0;
foreach ($regions as $ri => $region) {
    $regionTotal = 0.0;
    $lines = '';
    foreach ($products as $pi => $product) {
        $units = 40 + (($ri * 7 + $pi * 13) % 260);
        $price = 12.5 + (($pi * 17) % 90);
        $total = $units * $price;
        $regionTotal += $total;
        $lines .= sprintf(
            '<tr><td>%s</td><td class="n">%d</td><td class="n">%s</td><td class="n">%s</td></tr>',
            htmlspecialchars($product), $units,
            number_format($price, 2), number_format($total, 2)
        );
    }
    $grand += $regionTotal;

    // The region cell spans every product row beneath it.
    $lines = preg_replace(
        '/^<tr>/',
        sprintf('<tr><td rowspan="%d" class="region">%s</td>', count($products), htmlspecialchars($region)),
        $lines,
        1
    );
    $body .= $lines;
    $body .= sprintf(
        '<tr class="sub"><td colspan="4">%s subtotal</td><td class="n">%s</td></tr>',
        htmlspecialchars($region), number_format($regionTotal, 2)
    );
}

$fontDir = DEJAVU;

$html = <<<HTML
<style>
  @font-face { font-family: Report; src: url("{$fontDir}DejaVuSans.ttf"); }
  @font-face { font-family: Report; src: url("{$fontDir}DejaVuSans-Bold.ttf"); font-weight: bold; }
  @font-face { font-family: Report; src: url("{$fontDir}DejaVuSans-Oblique.ttf"); font-style: italic; }

  :root {
    --ink:    #24262b;
    --muted:  #7c8088;
    --brand:  #2466d9;
    --rule:   #cdd2da;
    --pad:    5pt;
  }

  body { font-family: Report; font-size: 9px; color: var(--ink); }
  h1 { font-size: 17px; margin: 0 0 2px 0; }
  .sub-title { color: var(--muted); font-size: 8.5px; margin-bottom: 16px; }
  .sub-title em { font-style: italic; }

  table { width: 100%; border-collapse: collapse; }
  th { background: #23262c; color: #ffffff; font-size: 7.5px;
       padding: 7px 8px; text-align: left; }
  th.n { text-align: right; }
  td { padding: var(--pad) calc(var(--pad) * 1.6); border: 0.4pt solid #e9ecf0; }
  td.n { text-align: right; }
  tbody tr:nth-child(even) td { background: #fafbfc; }

  .region { background: #eef2fa; font-weight: bold; vertical-align: middle;
            color: var(--brand); }
  .sub td { background: #f1f3f6; font-weight: bold; border-bottom: 0.8pt solid var(--rule); }

  .grand { display: flex; justify-content: flex-end; margin-top: 14px; }
  .grand .k { font-size: 11px; font-weight: bold; margin-right: 14px; }
  .grand .v { font-size: 11px; font-weight: bold; color: var(--brand); }
</style>

<h1>Regional sales — Q2 2026</h1>
<div class="sub-title">Every page repeats the header row<sup>1</sup>. Rows are never sliced across a break, and
<em>region groups</em> span their rows.</div>

<table>
  <colgroup><col style="width:92pt"><col><col><col><col></colgroup>
  <thead>
    <tr>
      <th>Region</th><th>Product</th><th class="n">Units</th>
      <th class="n">Unit price</th><th class="n">Total</th>
    </tr>
  </thead>
  <tbody>
    {$body}
  </tbody>
</table>

<div class="grand">
  <div class="k">Grand total</div>
  <div class="v">GRANDVAL</div>
</div>
HTML;

$html = str_replace('GRANDVAL', number_format($grand, 2), $html);

$t0 = microtime(true);
$pages = Html::make($html)
    ->basePath($fontDir)
    ->margin(52.0)
    ->header(
        '<div style="display:flex;justify-content:space-between;font-family:Report;'
        . 'font-size:7.5px;color:#9aa0a8">'
        . '<div><b>Northbound Systems BV</b></div><div>Regional sales, Q2 2026</div></div>'
    )
    ->footer(
        '<div style="display:flex;justify-content:space-between;font-family:Report;'
        . 'font-size:7.5px;color:#9aa0a8">'
        . '<div><sup>1</sup> Generated in pure PHP</div><div>Page {page} of {pages}</div></div>'
    )
    ->save(__DIR__ . '/report.pdf');
$ms = (microtime(true) - $t0) * 1000;

printf("%d pages, %.0f ms, %.1f KB\n", $pages, $ms, filesize(__DIR__ . '/report.pdf') / 1024);
