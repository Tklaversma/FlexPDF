<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use FlexPDF\Engine\Html;

require_once __DIR__ . '/../tests/Engine/support/bootstrap.php';

define('DEJAVU', dejavu_dir());

$cells = '';
$metrics = [
    ['Revenue', '€1,248,300', '+12.4%', '#22a06b'],
    ['Gross margin', '61.8%', '+2.1pt', '#22a06b'],
    ['Open invoices', '37', '-5', '#22a06b'],
    ['Overdue', '€84,120', '+18.9%', '#e8462d'],
    ['Avg. days to pay', '41', '+6', '#e8462d'],
    ['Active accounts', '312', '+9', '#22a06b'],
];
foreach ($metrics as [$label, $value, $delta, $colour]) {
    $cells .= sprintf(
        '<div class="metric"><div class="label">%s</div><div class="value">%s</div>'
        . '<div class="delta" style="color:%s">%s</div></div>',
        htmlspecialchars($label), htmlspecialchars($value), $colour, htmlspecialchars($delta)
    );
}

$rows = '';
foreach ([
    ['Noord-Holland', '412', '€384,220'],
    ['Zuid-Holland', '388', '€351,940'],
    ['Utrecht', '214', '€198,760'],
    ['Gelderland', '176', '€162,410'],
    ['Noord-Brabant', '203', '€150,970'],
] as [$region, $orders, $value]) {
    $rows .= sprintf(
        '<tr><td>%s</td><td class="n">%s</td><td class="n">%s</td></tr>',
        htmlspecialchars($region), $orders, htmlspecialchars($value)
    );
}

$html = <<<HTML
<style>
  @font-face { font-family: UI; src: url("{DEJAVU}DejaVuSans.ttf"); }
  @font-face { font-family: UI; src: url("{DEJAVU}DejaVuSans-Bold.ttf"); font-weight: bold; }

  :root { --ink: #23262c; --muted: #7c8088; --line: #e4e7ec; --pad: 11pt; }

  body { font-family: UI; font-size: 9px; color: var(--ink); }
  h1 { font-size: 18px; margin: 0 0 2px 0; }
  .sub { color: var(--muted); font-size: 8.5px; margin-bottom: 16px; }

  /* Six metric cards on a three-column grid. */
  .metrics {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 10pt;
    margin-bottom: 16pt;
  }
  .metric { border: 0.5pt solid var(--line); border-radius: 4pt; padding: var(--pad); }
  .metric .label { font-size: 7pt; color: var(--muted); }
  .metric .value { font-size: 15px; font-weight: bold; }
  .metric .delta { font-size: 8pt; }

  /* An asymmetric grid: the table takes two thirds, the notes one. */
  .split {
    display: grid;
    grid-template-columns: 2fr 1fr;
    gap: 14pt;
  }
  .panel { border: 0.5pt solid var(--line); border-radius: 4pt; padding: var(--pad); }
  .panel h2 { font-size: 10px; margin: 0 0 7pt 0; }

  table { width: 100%; border-collapse: collapse; }
  th { text-align: left; font-size: 7pt; color: var(--muted);
       border-bottom: 0.5pt solid var(--line); padding: 0 0 4pt 0; }
  th.n, td.n { text-align: right; }
  td { padding: 4pt 0; border-bottom: 0.4pt solid var(--line); }

  .note { color: var(--muted); font-size: 8pt; text-align: justify; }
  .wide { grid-column: 1 / 3; margin-top: 14pt; }
</style>

<h1>Sales dashboard</h1>
<div class="sub">Laid out with CSS Grid — pure PHP, no browser</div>

<div class="metrics">{CELLS}</div>

<div class="split">
  <div class="panel">
    <h2>Orders by region</h2>
    <table>
      <thead><tr><th>Region</th><th class="n">Orders</th><th class="n">Value</th></tr></thead>
      <tbody>{ROWS}</tbody>
    </table>
  </div>
  <div class="panel">
    <h2>Notes</h2>
    <p class="note">Overdue balances rose sharply this quarter, concentrated in two
    accounts. Collections have been briefed and the escalation path is unchanged.</p>
    <p class="note">Margin improvement is carried entirely by the services line;
    hardware margin is flat year on year.</p>
  </div>
  <div class="panel wide">
    <h2>Method</h2>
    <p class="note">This page is a grid inside a grid: six metric cards on
    <b>repeat(3, 1fr)</b>, then an asymmetric <b>2fr 1fr</b> split below, with the
    final panel spanning both tracks via <b>grid-column: 1 / 3</b>. The table uses
    <b>border-collapse: collapse</b>, so every shared rule is stroked once.</p>
  </div>
</div>
HTML;

$html = str_replace(['{DEJAVU}', '{CELLS}', '{ROWS}'], [DEJAVU, $cells, $rows], $html);

$t0 = microtime(true);
$pages = Html::make($html)
    ->basePath(DEJAVU)
    ->margin(48.0)
    ->footer('<div style="display:flex;justify-content:space-between;font-family:UI;'
        . 'font-size:7pt;color:#9aa0a8"><div>Northbound Systems BV</div>'
        . '<div>Page {page} of {pages}</div></div>')
    ->save(__DIR__ . '/dashboard.pdf');
$ms = (microtime(true) - $t0) * 1000;

printf("%d page(s), %.0f ms, %.1f KB\n", $pages, $ms, filesize(__DIR__ . '/dashboard.pdf') / 1024);
