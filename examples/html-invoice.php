<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use FlexPDF\Engine\Html;

require_once __DIR__ . '/../tests/Engine/support/bootstrap.php';

define('DEJAVU', dejavu_dir());

// A small logo, so the demo exercises image embedding too.
$logo = __DIR__ . '/logo.png';
if (!is_file($logo)) {
    $im = imagecreatetruecolor(240, 240);
    imagesavealpha($im, true);
    imagefill($im, 0, 0, imagecolorallocatealpha($im, 0, 0, 0, 127));
    $blue = imagecolorallocate($im, 36, 102, 217);
    $pale = imagecolorallocate($im, 150, 185, 245);
    imagefilledarc($im, 120, 120, 200, 200, 0, 260, $blue, IMG_ARC_PIE);
    imagefilledarc($im, 120, 120, 200, 200, 260, 360, $pale, IMG_ARC_PIE);
    imagefilledellipse($im, 120, 120, 96, 96, imagecolorallocatealpha($im, 0, 0, 0, 127));
    imagepng($im, $logo);
    imagedestroy($im);
}

$items = [
    ['Layout engine architecture', 'Design + spec review', '24', '120.00'],
    ['Flexbox solver — CSS Flexible Box §9', 'Including §9.7 freeze loop', '31', '120.00'],
    ['Inline formatting context', 'Line boxes, baselines, justification', '18', '120.00'],
    ['TrueType subsetting & embedding', 'Identity-H, ToUnicode CMap', '22', '120.00'],
    ['Fragmentation across page breaks', 'Orphans, widows, repeating headers', '16', '135.00'],
    ['CSS parser and cascade', 'Selectors, specificity, inheritance', '27', '120.00'],
    ['Test suite', '93 tests across four layers', '14', '95.00'],
];

$rows = '';
$subtotal = 0.0;
foreach ($items as [$name, $note, $hours, $rate]) {
    $amount = (float) $hours * (float) $rate;
    $subtotal += $amount;
    $rows .= sprintf(
        '<tr>
           <td class="desc"><b>%s</b><br><span class="note">%s</span></td>
           <td class="qty">%s h</td>
           <td class="rate">%s</td>
           <td class="amt">%s</td>
         </tr>',
        htmlspecialchars($name), htmlspecialchars($note),
        $hours, number_format((float) $rate, 2), number_format($amount, 2)
    );
}
$vat = $subtotal * 0.21;

$html = <<<HTML
<style>
  body { font-family: DejaVu; font-size: 9.5px; color: #24262b; line-height: 1.45; }

  .head { display: flex; align-items: flex-start; gap: 20px; margin-bottom: 26px; }
  .brand { display: flex; gap: 10px; flex: 2; align-items: center; }
  .brand img { width: 34px; height: 34px; }
  .brand .name { font-size: 15px; font-weight: bold; color: #1b1d21; }
  .brand .tag { font-size: 8px; color: #7c8088; }
  .meta { flex: 1; text-align: right; }
  .meta .no { font-size: 15px; font-weight: bold; color: #2466d9; }
  .meta .due { color: #7c8088; font-size: 8.5px; }

  .parties { display: flex; gap: 20px; margin-bottom: 24px; }
  .party { flex: 1; background: #f5f6f8; padding: 11px 13px; border-radius: 5px; }
  .party h4 { font-size: 7.5px; color: #7c8088; margin: 0 0 5px 0; }
  .party .who { font-size: 11px; font-weight: bold; margin-bottom: 2px; }
  .party .addr { color: #61656d; font-size: 8.5px; }

  table { width: 100%; margin-bottom: 4px; }
  th { background: #e6e9ee; padding: 6px 8px; font-size: 7.5px; color: #4b4f57; }
  td { padding: 8px; border-bottom: 0.5pt solid #e9ecf0; vertical-align: top; }
  tbody tr:nth-child(odd) td { background: #fafbfc; }
  .note { color: #7c8088; font-size: 8px; }
  .qty  { width: 46px; text-align: right; }
  .rate { width: 62px; text-align: right; }
  .amt  { width: 72px; text-align: right; }
  th.qty, th.rate, th.amt { text-align: right; }

  .totals { display: flex; justify-content: flex-end; margin-top: 14px; }
  .totals .panel { width: 220px; }
  .tline { display: flex; padding: 3px 0; }
  .tline .k { flex: 1; color: #61656d; }
  .tline .v { width: 90px; text-align: right; }
  .grand { border: 0.5pt solid #d9dde3; margin-top: 6px; padding: 8px 0 0 0; }
  .grand .k { font-size: 11px; font-weight: bold; color: #1b1d21; }
  .grand .v { font-size: 11px; font-weight: bold; color: #2466d9; }

  .terms { margin-top: 28px; padding: 13px; background: #f5f6f8; border-radius: 5px;
           break-inside: avoid; }
  .terms h4 { font-size: 8px; margin: 0 0 5px 0; color: #4b4f57; }
  .terms p { font-size: 8.5px; color: #61656d; text-align: justify; margin: 0; }

  .foot { margin-top: 22px; color: #9aa0a8; font-size: 7.5px;
          display: flex; justify-content: space-between; }
</style>

<div class="head">
  <div class="brand">
    <img src="{$logo}">
    <div>
      <div class="name">Northbound Systems BV</div>
      <div class="tag">Document engineering</div>
    </div>
  </div>
  <div class="meta">
    <div class="no">#2026-0417</div>
    <div class="due">Issued 27 July 2026<br>Due 26 August 2026</div>
  </div>
</div>

<div class="parties">
  <div class="party">
    <h4>FROM</h4>
    <div class="who">Northbound Systems BV</div>
    <div class="addr">Keizersgracht 241<br>1016 EA Amsterdam<br>VAT NL8412.34.567.B01</div>
  </div>
  <div class="party">
    <h4>BILL TO</h4>
    <div class="who">Harbour Freight Co.</div>
    <div class="addr">Docklands 14<br>3011 BN Rotterdam<br>VAT NL8098.76.543.B02</div>
  </div>
</div>

<table>
  <thead>
    <tr>
      <th class="desc">DESCRIPTION</th>
      <th class="qty">HOURS</th>
      <th class="rate">RATE</th>
      <th class="amt">AMOUNT</th>
    </tr>
  </thead>
  <tbody>
    {$rows}
  </tbody>
</table>

<div class="totals">
  <div class="panel">
    <div class="tline"><div class="k">Subtotal</div><div class="v">SUBTOTAL</div></div>
    <div class="tline"><div class="k">VAT 21%</div><div class="v">VATVAL</div></div>
    <div class="tline grand"><div class="k">Total due</div><div class="v">TOTALVAL</div></div>
  </div>
</div>

<div class="terms">
  <h4>PAYMENT TERMS</h4>
  <p>Payment is due within thirty days of the issue date. Late payment is subject to
  statutory interest under Dutch civil law. Transfers to NL91&nbsp;ABNA&nbsp;0417&nbsp;1643&nbsp;00,
  quoting the invoice number above. Questions about this invoice should be directed to
  accounts@northbound.example within fourteen days of receipt — after that the invoice
  is considered accepted in full.</p>
</div>

<div class="foot">
  <div>Northbound Systems BV — KvK 84123456</div>
  <div>Generated in pure PHP</div>
</div>
HTML;

$html = str_replace(
    ['SUBTOTAL', 'VATVAL', 'TOTALVAL'],
    [number_format($subtotal, 2), number_format($vat, 2), number_format($subtotal + $vat, 2)],
    $html
);

$t0 = microtime(true);
$pages = Html::make($html)
    ->basePath(__DIR__)
    ->font('DejaVu', DEJAVU . 'DejaVuSans.ttf', DEJAVU . 'DejaVuSans-Bold.ttf')
    ->margin(46.0)
    ->save(__DIR__ . '/html-invoice.pdf');
$ms = (microtime(true) - $t0) * 1000;

printf(
    "%d page(s), %.0f ms, %.1f KB — from %.1f KB of HTML+CSS\n",
    $pages, $ms, filesize(__DIR__ . '/html-invoice.pdf') / 1024, strlen($html) / 1024
);
