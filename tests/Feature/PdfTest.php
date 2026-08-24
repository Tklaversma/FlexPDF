<?php

declare(strict_types=1);

use FlexPDF\Engine\Exceptions\LayoutTimeoutException;
use FlexPDF\Engine\Support\Limits;
use FlexPDF\Facades\Pdf;
use FlexPDF\PdfBuilder;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

it('renders an HTML string to a PDF', function () {
    $bytes = Pdf::html('<h1>Hello</h1>')->output();

    expect($bytes)->toStartWith('%PDF-')
        ->and($bytes)->toContain('%%EOF')
        ->and(pdfPageCount($bytes))->toBe(1);
});

it('renders a view by name with data', function () {
    $bytes = Pdf::view('invoice', ['title' => 'Invoice 42', 'customer' => 'Acme'])->output();

    expect($bytes)->toStartWith('%PDF-');
});

it('renders a View instance', function () {
    $view = View::make('invoice', ['title' => 'Invoice 7', 'customer' => 'Globex']);

    expect(Pdf::view($view)->output())->toStartWith('%PDF-');
});

it('renders a file from disk', function () {
    $bytes = Pdf::loadFile(__DIR__ . '/../fixtures/report.html')->output();

    expect($bytes)->toStartWith('%PDF-');
});

it('rejects a missing file', function () {
    Pdf::loadFile('/does/not/exist.html');
})->throws(RuntimeException::class);

it('saves to disk and returns the page count', function () {
    $path = sys_get_temp_dir() . '/flexpdf-save-test/out.pdf';

    $pages = Pdf::html('<h1>Saved</h1>')->save($path);

    expect($pages)->toBe(1)
        ->and(file_get_contents($path))->toStartWith('%PDF-');

    unlink($path);
    rmdir(dirname($path));
});

it('returns a download response', function () {
    $response = Pdf::html('<p>x</p>')->download('invoice.pdf');

    expect($response)->toBeInstanceOf(Response::class)
        ->and($response->headers->get('content-type'))->toBe('application/pdf')
        ->and($response->headers->get('content-disposition'))->toBe('attachment; filename="invoice.pdf"');
});

it('returns an inline response', function () {
    $response = Pdf::html('<p>x</p>')->inline('report.pdf');

    expect($response->headers->get('content-disposition'))->toBe('inline; filename="report.pdf"');
});

it('returns a streamed response', function () {
    $response = Pdf::html('<p>x</p>')->stream('report.pdf');

    expect($response)->toBeInstanceOf(StreamedResponse::class)
        ->and($response->headers->get('content-disposition'))->toBe('inline; filename="report.pdf"');

    ob_start();
    $response->sendContent();
    $body = (string) ob_get_clean();

    expect($body)->toStartWith('%PDF-');
});

it('appends a pdf extension when the filename lacks one', function () {
    $response = Pdf::html('<p>x</p>')->download('invoice');

    expect($response->headers->get('content-disposition'))->toBe('attachment; filename="invoice.pdf"');
});

it('paginates long documents', function () {
    $html = '<div>' . str_repeat('<p>Filler paragraph for pagination.</p>', 200) . '</div>';

    $bytes = Pdf::html($html)->output();

    expect(pdfPageCount($bytes))->toBeGreaterThan(1);
});

it('draws running headers and footers', function () {
    $bytes = Pdf::html('<p>Body</p>')
        ->header('<div>Statement</div>')
        ->footer('<div>Page {page} of {pages}</div>')
        ->output();

    expect($bytes)->toStartWith('%PDF-');
});

it('honours the configured page size', function () {
    config()->set('flexpdf.page.size', 'letter');

    $bytes = Pdf::html('<p>x</p>')->output();

    expect($bytes)->toContain('612');
});

it('accepts a custom page size in points', function () {
    $bytes = Pdf::html('<p>x</p>')->page([200, 400])->output();

    expect($bytes)->toContain('200');
});

it('rejects an unknown page size', function () {
    Pdf::html('<p>x</p>')->page('a99');
})->throws(InvalidArgumentException::class);

it('swaps dimensions in landscape', function () {
    $portrait  = Pdf::html('<p>x</p>');
    $landscape = Pdf::html('<p>x</p>')->landscape();

    expect($portrait->output())->not->toBe($landscape->output());
});

it('resolves the facade to a builder', function () {
    expect(Pdf::html('<p>x</p>'))->toBeInstanceOf(PdfBuilder::class);
});

it('reads the safety limits from config', function () {
    config()->set('flexpdf.limits.max_pages', 3);

    $limits = Limits::fromArray(config('flexpdf.limits'));

    expect($limits->maxPages)->toBe(3)
        ->and($limits->maxDepth)->toBe(64);
});

it('drops a raster image whose decoded size passes the ceiling', function () {
    $canvas = imagecreatetruecolor(2, 2);
    ob_start();
    imagepng($canvas);
    $png  = ob_get_clean();
    $html = '<img src="data:image/png;base64,' . base64_encode($png) . '">';

    $kept      = Pdf::html($html)->output();
    $dropped   = Pdf::html($html)->limits(new Limits(maxImageBytes: 8))->output();
    $unlimited = Pdf::html($html)->limits(new Limits(maxImageBytes: 0))->output();

    expect($kept)->toContain('/Subtype /Image')
        ->and($unlimited)->toContain('/Subtype /Image')
        ->and($dropped)->not->toContain('/Subtype /Image')
        ->and($dropped)->toStartWith('%PDF-');
});

it('reads the image ceiling from config', function () {
    config()->set('flexpdf.limits.max_image_bytes', 16);

    expect(Limits::fromArray(config('flexpdf.limits'))->maxImageBytes)->toBe(16)
        ->and((new Limits())->maxImageBytes)->toBe(200_000_000);
});

it('enforces the wall clock budget', function () {
    $html = '<div>' . str_repeat('<p>Filler paragraph for pagination.</p>', 400) . '</div>';

    Pdf::html($html)->timeout(0.001)->output();
})->throws(LayoutTimeoutException::class);

it('treats a zero timeout as disabled', function () {
    $bytes = Pdf::html('<p>x</p>')->timeout(0)->output();

    expect($bytes)->toStartWith('%PDF-');
});

it('does not leak registered fonts between renders', function () {
    $font = dejavuPath();

    if ($font === null) {
        $this->markTestSkipped('No DejaVuSans.ttf available to register.');
    }

    Pdf::html('<p>x</p>')->font('Probe', $font)->output();

    $second = Pdf::html('<p style="font-family: Probe">x</p>')->output();

    expect($second)->toStartWith('%PDF-')
        ->and($second)->not->toContain('Probe');
});

it('writes the initial view the builder asked for', function () {
    $bytes = Pdf::html('<p>one</p><div style="break-before:page"></div><p>two</p>')
        ->initialView('FitH', 2, [280.0])
        ->pageMode('UseOutlines')
        ->output();

    expect($bytes)->toMatch('#/OpenAction \[\d+ 0 R /FitH 280\]#')
        ->and($bytes)->toContain('/PageMode /UseOutlines');
});

it('accepts a lowercase spelling of a fit', function () {
    $bytes = Pdf::html('<p>x</p>')->initialView('fitH', 1, [10.0])->output();

    expect($bytes)->toContain('/FitH 10');
});

it('refuses a fit nobody can render', function () {
    Pdf::html('<p>x</p>')->initialView('fitEverything')->output();
})->throws(InvalidArgumentException::class);

it('refuses a page mode nobody can show', function () {
    Pdf::html('<p>x</p>')->pageMode('UseSomething')->output();
})->throws(InvalidArgumentException::class);

it('carries a file inside the document as an associated file', function () {
    $payload = '<?xml version="1.0"?><invoice><id>A1</id></invoice>';

    $bytes = Pdf::html('<p>Invoice</p>')
        ->attach('factur-x.xml', $payload, 'text/xml', 'e-invoice payload', 'Data')
        ->output();

    expect($bytes)->toContain('/Type /Filespec')
        ->and($bytes)->toContain('/AFRelationship /Data')
        ->and($bytes)->toContain('/EmbeddedFiles')
        ->and($bytes)->toMatch('#/AF \[\d+ 0 R\]#');
});

it('refuses an attachment with no name', function () {
    Pdf::html('<p>x</p>')->attach('   ', 'body')->output();
})->throws(InvalidArgumentException::class);

it('costs a document that asks for none of them nothing at all', function () {
    $plain = Pdf::html('<p>Invoice</p>')->output();

    expect($plain)->not->toContain('/OpenAction')
        ->and($plain)->not->toContain('/PageMode')
        ->and($plain)->not->toContain('/EmbeddedFiles');
});
