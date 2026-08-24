<?php

declare(strict_types=1);

/*
 * Renders the example documents into examples/output/.
 *
 *   php examples/render.php                 every document
 *   php examples/render.php invoice report  only these
 *   php examples/render.php --html invoice  also write the rendered HTML
 *
 * The only setup is `composer install` in the package: this script boots a
 * minimal Laravel application through Orchestra Testbench, the package's own
 * development dependency, so the Pdf facade and Blade work exactly as they do
 * in your app. Nothing below the "bootstrap" block is specific to this script.
 * In your own application, copy a view from examples/views and the builder
 * chain from the matching function here, and you are done.
 */

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/Content.php';

use FlexPDF\Facades\Pdf;
use FlexPDF\FlexPdfServiceProvider;
use FlexPDF\PdfBuilder;
use Orchestra\Testbench\Foundation\Application;

// --- bootstrap: only needed because this runs outside a Laravel app ----------

$app = Application::create(options: [
    'enables_package_discoveries' => false,
    'extra'                       => ['providers' => [FlexPdfServiceProvider::class]],
]);

$app['view']->addLocation(__DIR__ . '/views');

// --- the documents ---------------------------------------------------------

/**
 * What every example shares: the fonts, and where relative image paths
 * resolve. In an app this goes in config/flexpdf.php once, under 'fonts' and
 * 'base_path', and the builder chains below lose these lines.
 */
function document(string $view, array $data = []): PdfBuilder
{
    $fonts = __DIR__ . '/fonts/';

    return Pdf::view($view, $data)
        ->font('Plex Sans', [
            'regular'     => $fonts . 'IBMPlexSans-Regular.ttf',
            'bold'        => $fonts . 'IBMPlexSans-Bold.ttf',
            'italic'      => $fonts . 'IBMPlexSans-Italic.ttf',
            'bold-italic' => $fonts . 'IBMPlexSans-BoldItalic.ttf',
        ])
        ->font('Plex Mono', [
            'regular' => $fonts . 'IBMPlexMono-Regular.ttf',
            'bold'    => $fonts . 'IBMPlexMono-Bold.ttf',
        ])
        ->font('Plex Arabic', $fonts . 'IBMPlexSansArabic-Regular.ttf')
        ->font('Plex Hebrew', $fonts . 'IBMPlexSansHebrew-Regular.ttf')
        ->font('Baskerville', [
            'regular' => $fonts . 'LibreBaskerville-Regular.ttf',
            'bold'    => $fonts . 'LibreBaskerville-Bold.ttf',
            'italic'  => $fonts . 'LibreBaskerville-Italic.ttf',
        ])
        ->basePath(__DIR__ . '/images');
}

/**
 * A running header or footer is laid out on its own, so the document's own
 * <style> block does not reach it. Rules for them are passed with ->css().
 */
function runningStyles(): string
{
    return <<<'CSS'
        .run-head, .run-foot, .deck-foot {
            display: flex;
            justify-content: space-between;
            font-family: 'Plex Sans';
            font-size: 7.5pt;
            color: #7c8a83;
        }
        .run-head { border-bottom: 0.5pt solid #d8e2dc; padding-bottom: 4pt }
        .run-foot { border-top: 0.5pt solid #d8e2dc; padding-top: 4pt }
        .deck-foot { color: #9aa8a1; font-size: 7pt; padding: 0 34pt 14pt }
    CSS;
}

/**
 * An invoice: flex layout, an items table, totals, a payment block, a forced
 * page break to an appendix with a <tfoot>, two-column terms, and a running
 * footer built from a Blade partial that knows the page count.
 */
function invoice(): PdfBuilder
{
    $company = Content::company();
    $invoice = Content::invoice();

    return document('invoice', [
        'company' => $company,
        'invoice' => $invoice,
        'totals'  => Content::invoiceTotals($invoice['rows']),
    ])
        ->page('a4')
        ->margins(46, 44, 58, 44)
        ->footer(fn (int $page, int $pages): string => view('partials.invoice-footer', [
            'company' => $company,
            'invoice' => $invoice,
        ])->render())
        ->metadata([
            'title'   => 'Invoice ' . $invoice['number'],
            'author'  => $company['name'],
            'subject' => 'Invoice',
        ]);
}

/**
 * A bank statement: a 36-row table across three pages with a repeating
 * <thead>, a header that appears from page 2 only, summary cards, a bar chart
 * in plain HTML and a sparkline in inline SVG.
 */
function statement(): PdfBuilder
{
    $company = Content::company();
    $rows    = Content::transactions();

    return document('statement', [
        'company'    => $company,
        'rows'       => $rows,
        'opening'    => 18_425.60,
        'closing'    => $rows[count($rows) - 1]['balance'],
        'categories' => Content::spendingByCategory($rows),
    ])
        ->page('a4')
        ->margins(44, 40, 54, 40)
        ->header(fn (int $page): ?string => $page === 1 ? null : view('partials.statement-header')->render())
        ->footer('<div class="run-foot">Statement, August 2026 &nbsp;&middot;&nbsp; ' . $company['iban'] . ' &nbsp;&middot;&nbsp; page {page} of {pages}</div>')
        ->css(runningStyles())
        ->metadata(['title' => 'Statement, August 2026', 'author' => $company['name']]);
}

/**
 * An annual report: a cover with an image and an absolutely positioned plate,
 * a contents list, a four-column CSS grid of figures, an SVG bar chart drawn
 * from the data, justified and hyphenated two-column text, a pull quote,
 * footnotes and a dark colophon. Header and footer skip the cover.
 */
function report(): PdfBuilder
{
    $company = Content::company();

    return document('report', [
        'company'  => $company,
        'quarters' => Content::quarters(),
        'segments' => Content::segments(),
    ])
        ->page('a4')
        ->margins(52, 48, 60, 48)
        ->header(fn (int $page): ?string => $page <= 2
            ? null
            : '<div class="run-head"><span>' . $company['name'] . '</span><span>Annual report 2026</span></div>')
        ->footer(fn (int $page, int $pages): ?string => $page === 1
            ? null
            : '<div class="run-foot"><span>Annual report 2026</span><span>{page} / {pages}</span></div>')
        ->css(runningStyles())
        ->metadata(['title' => 'Annual report 2026', 'author' => $company['name']]);
}

/**
 * A slide deck on a 16:9 sheet: full-bleed image slides, dark and pale slide
 * masters, a three-column grid of cards, bar rows, a timeline, and a footer
 * that skips the first and last slide.
 */
function presentation(): PdfBuilder
{
    $company = Content::company();

    return document('presentation', [
        'company'  => $company,
        'slides'   => Content::slides(),
        'segments' => Content::segments(),
        'quarters' => Content::quarters(),
    ])
        ->page([720.0, 405.0])
        ->margins(0)
        ->footer(fn (int $page, int $pages): ?string => $page === 1 || $page === $pages
            ? null
            : '<div class="deck-foot"><span>' . $company['name'] . ' &middot; investor update 2026</span><span>{page}</span></div>')
        ->css(runningStyles())
        ->metadata(['title' => 'Investor update 2026', 'author' => $company['name']]);
}

/**
 * A product catalog: a two-column grid of product cards with images and
 * badges that never split across a page, a comparison table with rowspan,
 * price columns and a two-column FAQ.
 */
function catalog(): PdfBuilder
{
    $company = Content::company();

    return document('catalog', [
        'company'  => $company,
        'products' => Content::products(),
    ])
        ->page('a4')
        ->margins(44, 42, 52, 42)
        ->header(fn (int $page): ?string => $page === 1
            ? null
            : '<div class="run-head"><span>' . $company['name'] . ' product guide</span><span>2026 edition</span></div>')
        ->footer(fn (int $page): ?string => $page === 1
            ? null
            : '<div class="run-foot"><span>' . $company['site'] . '/pricing</span><span>{page} of {pages}</span></div>')
        ->css(runningStyles())
        ->metadata(['title' => 'Product guide 2026', 'author' => $company['name']]);
}

/**
 * The CSS showcase: type scale, five embedded families, justification against
 * ragged setting, Arabic and Hebrew, flexbox and grid in every alignment,
 * multi-column balancing, floats, gradients, shadows, opacity, transforms,
 * absolute positioning, nested lists and SVG. One page per group.
 */
function showcase(): PdfBuilder
{
    return document('showcase')
        ->page('a4')
        ->margins(40)
        ->footer('<div class="run-foot"><span>FlexPDF showcase</span><span>{page} / {pages}</span></div>')
        ->css(runningStyles())
        ->metadata(['title' => 'FlexPDF showcase', 'author' => 'FlexPDF']);
}

/**
 * A fillable form: <input>, <textarea> and <select> become real PDF form
 * fields (text, password, checkbox, radio, combo and list boxes) that a
 * reader can fill in and save. Only elements with a name become fields.
 */
function form(): PdfBuilder
{
    return document('form', ['company' => Content::company()])
        ->page('a4')
        ->margins(48, 46, 52, 46)
        ->metadata(['title' => 'Account application', 'author' => Content::company()['name']]);
}

/**
 * An e-invoice: the same invoice as a PDF/A-3 file with the machine-readable
 * XML travelling inside it as an associated file, which is what Factur-X and
 * ZUGFeRD are built on. Tagged for accessibility, and the reader opens it on
 * the attachments panel so the payload is visible. The XML here is a minimal
 * sketch of the Factur-X shape, not a complete profile.
 */
function eInvoice(): PdfBuilder
{
    $invoice = Content::invoice();
    $totals  = Content::invoiceTotals($invoice['rows']);

    $xml = sprintf(
        '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
        . '<rsm:CrossIndustryInvoice xmlns:rsm="urn:un:unece:uncefact:data:standard:CrossIndustryInvoice:100">' . "\n"
        . '  <rsm:ExchangedDocument><ram:ID>%s</ram:ID><ram:TypeCode>380</ram:TypeCode></rsm:ExchangedDocument>' . "\n"
        . '  <rsm:SupplyChainTradeTransaction>' . "\n"
        . '    <ram:GrandTotalAmount>%.2f</ram:GrandTotalAmount>' . "\n"
        . '    <ram:TaxTotalAmount currencyID="GBP">%.2f</ram:TaxTotalAmount>' . "\n"
        . '  </rsm:SupplyChainTradeTransaction>' . "\n"
        . '</rsm:CrossIndustryInvoice>' . "\n",
        $invoice['number'],
        $totals['gross'],
        $totals['gross'] - $totals['net'],
    );

    return invoice()
        ->tagged(lang: 'en')
        ->pdfa()
        ->attach('factur-x.xml', $xml, 'text/xml', 'Factur-X invoice data', 'Data')
        ->initialView('Fit')
        ->pageMode('UseAttachments');
}

/**
 * The invoice again, encrypted with AES-256: opens with the password "reader"
 * and may be printed but not copied from or modified. The key material is
 * random, so this is the one example whose bytes differ on every run.
 */
function protectedInvoice(): PdfBuilder
{
    return invoice()->encrypt('reader', allow: ['print']);
}

// --- run -------------------------------------------------------------------

$documents = [
    'invoice'      => invoice(...),
    'statement'    => statement(...),
    'report'       => report(...),
    'presentation' => presentation(...),
    'catalog'      => catalog(...),
    'showcase'     => showcase(...),
    'form'         => form(...),
    'e-invoice'    => eInvoice(...),
    'protected'    => protectedInvoice(...),
];

$arguments = array_slice($argv, 1);
$writeHtml = in_array('--html', $arguments, true);
$wanted    = array_values(array_filter($arguments, fn (string $argument): bool => $argument !== '--html'));

foreach ($wanted as $name) {
    if (! isset($documents[$name])) {
        fwrite(STDERR, "Unknown document \"$name\". Known: " . implode(', ', array_keys($documents)) . "\n");
        exit(2);
    }
}

$selected = $wanted === [] ? $documents : array_intersect_key($documents, array_flip($wanted));
$output   = __DIR__ . '/output';
$failed   = 0;

if (! is_dir($output)) {
    mkdir($output, 0755, true);
}

foreach ($selected as $name => $build) {
    $path    = "$output/$name.pdf";
    $started = microtime(true);

    try {
        $builder = $build();

        if ($writeHtml) {
            file_put_contents("$output/$name.html", $builder->markup());
        }

        $pages = $builder->save($path);
    } catch (Throwable $exception) {
        $failed++;
        printf("%-14s FAILED  %s: %s\n", $name, $exception::class, $exception->getMessage());

        continue;
    }

    printf(
        "%-14s %d page%s  %6.1f KB  %4d ms\n",
        $name,
        $pages,
        $pages === 1 ? ' ' : 's',
        filesize($path) / 1024,
        (int) ((microtime(true) - $started) * 1000),
    );
}

printf("\n%d rendered, %d failed, in %s\n", count($selected) - $failed, $failed, $output);

exit($failed === 0 ? 0 : 1);
