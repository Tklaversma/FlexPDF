# FlexPDF

[![Latest Version on Packagist](https://img.shields.io/packagist/v/tklaversma/flexpdf.svg?style=flat-square)](https://packagist.org/packages/tklaversma/flexpdf)
[![GitHub Tests Action Status](https://github.com/tklaversma/flexpdf/actions/workflows/run-tests.yml/badge.svg)](https://github.com/tklaversma/flexpdf/actions?query=workflow%3Arun-tests+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/tklaversma/flexpdf.svg?style=flat-square)](https://packagist.org/packages/tklaversma/flexpdf)

**HTML to PDF for Laravel, in pure PHP, with a modern CSS layout engine.**

Write your PDF templates as Blade views with flexbox, grid, custom fonts and
real typography. Render them in the same PHP process as the rest of your
request. No headless browser, no `wkhtmltopdf`, no Gotenberg container, no
Node.js, no binaries to ship.

```php
use FlexPDF\Facades\Pdf;

Pdf::view('invoices.show', ['invoice' => $invoice])->download('invoice.pdf');
```

> [!NOTE]
> **FlexPDF is in beta.** The engine is heavily tested and renders real
> production templates today, but the API may still shift before 1.0.
> Anything marked 🚧 below is not a dead end: it is on the list and being
> worked on. See [ROADMAP.md](ROADMAP.md).

## Why FlexPDF

Every HTML-to-PDF approach in PHP sits somewhere on one trade-off: layout
quality against deployment weight.

- **Browser-based tools** (Browsershot, Snappy, Gotenberg) produce excellent
  output, at the cost of a Chrome or wkhtmltopdf process next to your app:
  something to install, patch, monitor and scale, with an out-of-process
  failure mode.
- **Classic pure-PHP libraries** (dompdf, mpdf, TCPDF) deploy with `composer
  require` and nothing else, but their CSS support predates flexbox. Layouts
  get built out of tables and absolute positioning, and the template you wrote
  is not the page you get.

FlexPDF keeps the pure-PHP deployment model and closes the layout gap with an
engine written from scratch: it parses your HTML and CSS, runs the real
flexbox, grid and table algorithms to give every box its geometry, cuts the
result into pages, and writes the PDF itself, fonts subset and embedded. Not a
browser behind an API, and not HTML mapped onto PDF tables.

So flexbox, grid, container queries, `@page`, custom properties and modern
color functions all work, and the CSS you write for the screen is close to
the CSS you write for paper. And because everything happens in your PHP
process, a render is one function call: no temp files, no shelling out,
nothing to keep running.

Two things about the output are worth knowing up front:

- **Same input, same file. Always.** Render the same HTML twice and you get
  the exact same PDF, byte for byte: today, next week, on your laptop, on the
  server. Most tools cannot do this, because they put timestamps and random
  IDs in the file. Here you can write a test that says "the output must equal
  this saved file", cache by content hash, and re-render last year's invoice
  into a provably identical document. (The one exception is an encrypted
  document, because encryption keys must be random.)
- **Bad input cannot hang your server.** A broken or hostile document can
  send a layout engine into an endless loop, and this one runs inside your
  PHP process. So every render has hard limits: pages, nesting depth, and a
  wall-clock timeout. Cross one and you get a normal exception to catch, not
  a request that eats CPU until something kills it.

## What CSS is supported

The rule of thumb: **the CSS you write for a modern browser mostly works
here.** The tables give the honest picture, gaps included:
✅ works today, ⚠️ partial, 🚧 not yet, in development.

**Layouts**

| | Feature | Notes |
|---|---|---|
| ✅ | Flexbox | `gap`, `order`, reverse directions, auto margins, baseline alignment |
| ✅ | Grid | `fr`, `minmax()`, `repeat()`, `auto-fill` / `auto-fit`, template areas, spans |
| 🚧 | Subgrid | |
| ✅ | Tables | `colspan` / `rowspan`, `border-collapse`; `<thead>` and `<tfoot>` repeat on every page a long table crosses |
| ✅ | Floats | with real text wrap around them |
| ✅ | Positioning | `relative`, `absolute`, `fixed` |
| ✅ | Multi-column | balanced |
| ✅ | Box model | margin collapsing, `box-sizing`, percentage padding and margins, `aspect-ratio`, `overflow: hidden` |

**Values, colors and conditional rules**

| | Feature | Notes |
|---|---|---|
| ✅ | CSS variables | `var()`, also inside `calc()` |
| ✅ | Math functions | `calc()`, `min()`, `max()`, `clamp()` |
| ✅ | Viewport units | `vw` / `vh` resolve against the page size |
| ✅ | Modern colors | `hsl()`, `oklch()`, `color-mix()`, hex with alpha |
| ✅ | Conditional rules | `@media print`, `@supports`, `@container` queries, `@scope`, `@import` |
| ⚠️ | `@layer` | rules inside apply; layer ordering is ignored |
| ✅ | Generated content | `::before` / `::after`, counters |

**Pages and breaks**

| | Feature | Notes |
|---|---|---|
| ✅ | `@page` | paper size and margins from CSS, `:first`, named pages |
| ✅ | Forced breaks | `break-before` / `break-after: page` |
| ✅ | Keeping content together | `break-inside: avoid`, `orphans`, `widows` |
| ⚠️ | Named page with another width | the sheet size is right, line wrapping still uses the document's width |

**Graphics and decoration**

| | Feature | Notes |
|---|---|---|
| ✅ | Images | PNG, JPEG, GIF, WebP, `data:` URIs, `object-fit` |
| ✅ | SVG | inline and as `<img>`, full path grammar |
| ✅ | Transforms | translate, rotate, scale, matrix |
| ✅ | Effects | `opacity`, `mix-blend-mode` |
| 🚧 | `filter` | `grayscale()`, `blur()` and the rest are dropped for now |
| ✅ | `linear-gradient()` | |
| 🚧 | `radial-gradient()` | renders as no background for now |
| ✅ | Borders and shadows | `border-radius`, `box-shadow` |
| ⚠️ | `text-shadow` | offset and color drawn, blur radius ignored |

Anything the engine does not know is skipped the way a browser skips unknown
CSS: that declaration is dropped and the rest of your stylesheet still
applies. What is missing beyond CSS is under
[Current limitations](#current-limitations).

## Requirements

- PHP 8.4+
- Laravel 11+
- The `dom`, `gd` and `zlib` extensions (all commonly enabled)

## Installation

```bash
composer require tklaversma/flexpdf
```

Optionally publish the config file:

```bash
php artisan vendor:publish --tag="flexpdf-config"
```

This gives you `config/flexpdf.php` with defaults for page size, margins,
fonts, metadata, safety limits, remote images, tagging, PDF/A and encryption.
Everything in it can also be set per document on the builder.

## Quick start

The most common case: a controller that turns a Blade view into a PDF the
browser downloads.

```php
use FlexPDF\Facades\Pdf;

class InvoiceController extends Controller
{
    public function download(Invoice $invoice)
    {
        return Pdf::view('invoices.show', ['invoice' => $invoice])
            ->download("invoice-{$invoice->number}.pdf");
    }
}
```

Every call follows the same shape: **where the HTML comes from, then what to
do with the PDF.** Anything in between (page size, fonts, metadata, and so
on) is optional and covered in the sections below.

**Where the HTML comes from.** Pick one:

```php
Pdf::view('invoices.show', ['invoice' => $invoice])   // a Blade view, like view()
Pdf::html('<h1>Hello</h1>')                           // an HTML string you already have
Pdf::loadFile(resource_path('templates/report.html')) // an HTML file on disk
```

**What to do with the PDF.** Pick one:

```php
->download('invoice.pdf')                        // browser shows a save dialog
->inline('invoice.pdf')                          // opens right in the browser tab
->save(storage_path('app/invoices/invoice.pdf')) // write to disk
->output()                                       // the raw bytes, when you need them yourself
```

The `download()` and `inline()` functions return responses, so you `return` them 
straight from a controller. The function `stream()` also exists and behaves like
`inline()`, as a streamed response. The function `save()` writes the file and 
returns the page count.

The function `output()` is for everything else Laravel does with bytes, like 
storing on a disk or attaching to a mail:

```php
// Store on disk
Storage::disk('s3')->put(
    'invoices/invoice.pdf',
    Pdf::view('invoices.show', $data)->output()
);

// Use in a Mailable
$this->attachData(
    Pdf::view('invoices.show', $data)->output(),
    'invoice.pdf',
    ['mime' => 'application/pdf']
);
```

Not using Laravel? The engine has no Laravel dependency:
`FlexPDF\Engine\Html::make($html)` exposes the same capabilities directly.

## Page setup, headers and footers

```php
Pdf::view('report')
    ->page('a4') // a3, a4, a5, letter, legal, tabloid, or [w, h] in points
    ->landscape()
    ->margins(40, 30) // top/bottom, left/right (CSS shorthand order)
    ->header('<div>Quarterly report</div>')
    ->footer('<div>Page {page} of {pages}</div>')
    ->inline('report.pdf');
```

`@page` in your CSS works too, including named pages, so a document can carry
its own geometry:

```css
@page { size: A4; margin: 20mm }
@page cover { size: A4 landscape; margin: 0 }
@page :first { margin-top: 60pt }

.cover { page: cover }
```

**Headers and footers can differ per page.** Pass a callable: it receives the
1-based page number and the total, and returns that page's markup or `null`
for none. The count is real, because pagination finishes before the first
header is drawn, so "last page only" is as easy as "first":

```php
Pdf::view('report')
    ->header(fn (int $page): ?string => $page === 1
        ? null
        : '<div>Quarterly report</div>')
    ->footer(fn (int $page, int $total): ?string => $page === $total
        ? '<div>End of report</div>'
        : '<div>{page} / {pages}</div>')
    ->inline('report.pdf');
```

## Fonts and writing systems

**Built in, zero setup.** The classic PDF fonts Helvetica, Times and Courier,
plus DejaVu Sans, which ships with the package. Common names map onto them,
so `font-family: Arial`, `sans-serif`, `serif` or `monospace` just work.

If your text contains a character the classic fonts cannot draw (ą, ć, ß,
Greek, Cyrillic, Hebrew, Arabic), it is drawn from DejaVu Sans automatically
instead of coming out as `?`. Nothing to configure.

**Your own fonts.** Point the config at your TTF files:

```php
'fonts' => [
    'Inter' => [
        'regular'     => resource_path('fonts/Inter-Regular.ttf'),
        'bold'        => resource_path('fonts/Inter-Bold.ttf'),
        'italic'      => resource_path('fonts/Inter-Italic.ttf'),
        'bold-italic' => resource_path('fonts/Inter-BoldItalic.ttf'),
    ],
],
```

then use `font-family: Inter` in your CSS. Only `regular` is required: a
missing bold or italic is generated from it, like a browser does. Fonts can
also be registered for a single document with `->font('Inter', $paths)`, or
declared in the CSS itself with `@font-face`.

Embedding stays small: only the characters you actually use go into the PDF,
so a 742 KB font typically adds around 54 KB to the document.

**Writing systems.** Supported out of the box:

- **Latin**, so every language written in it: English, Dutch, German, French,
  Polish, Czech, Turkish, Vietnamese, ...
- **Greek**
- **Cyrillic**: Russian, Ukrainian, Bulgarian, Serbian, ...
- **Hebrew** and **Arabic**: right-to-left just works, Arabic letters connect
  correctly, and the text stays selectable and searchable in the PDF.

Not supported: CJK (Chinese, Japanese, Korean), Indic scripts such as
Devanagari (Hindi), Thai, and Khmer.

The typography details are handled too: hyphenation, justification, kerning,
ligatures and small caps.

If a document asks for a font that is not there, it still renders (in a
fallback font) and `->fontReport()` tells you afterwards what was missing.
Prefer failing loudly? `->strictFonts()` throws instead.

## What the PDF itself can do

The output is not just pictures of pages:

- **Selectable, searchable text**, in every script the engine sets
- **Links**: external URLs and internal `#anchor` jumps become real
  annotations
- **Bookmarks**: the heading structure becomes the reader's outline panel
- **Fillable forms**: `<input>`, `<textarea>` and `<select>` become AcroForm
  fields (text, password, checkbox, radio, combo and list boxes)
- **Metadata**: title, author, subject, keywords, creator, producer

```php
Pdf::view('report')
    ->metadata(['title' => 'Q3 Report', 'author' => 'Acme B.V.'])
    ->initialView('FitH', page: 1)     // how the reader opens the document
    ->pageMode('UseOutlines')          // with the bookmarks panel showing
    ->save($path);
```

### Accessible and archival PDF

```php
Pdf::view('invoices.show', $data)
    ->tagged(lang: 'en')   // tagged PDF: a real structure tree and /Lang
    ->pdfa()               // PDF/A-3 (ISO 19005-3), level B, or 'A'
    ->pdfua()              // claim PDF/UA-1 (ISO 14289-1) as well
    ->save($path);
```

Tagging gives every piece of content a role and a reading order, which is what
screen readers consume. PDF/A adds the color profile, XMP metadata and font
rules an archival file needs; the output validates against veraPDF. Claims are
honest: asking for level A or PDF/UA without a structure tree and language is
refused with an exception rather than written as an empty promise.

### Encryption

```php
Pdf::view('payslip', $data)
    ->encrypt('user-password', allow: ['print'])
    ->download('payslip.pdf');
```

AES-256 (PDF 2.0, revision 6), with the standard permission set: `print`,
`copy`, `modify`, `annotate`, `fill_forms`, `assemble`,
`print_high_quality`. Older, broken revisions are deliberately not offered.
Output opens in Acrobat 9+, macOS Preview, pdf.js, pdfium and Ghostscript.

### File attachments and e-invoicing

A file can travel inside the document, which is what PDF/A-3 exists for and
what Factur-X and ZUGFeRD e-invoicing are built on:

```php
Pdf::view('invoices.show', $data)
    ->pdfa()
    ->attach('factur-x.xml', $xml, 'text/xml', 'Invoice data', 'Data')
    ->save($path);
```

The attachment is written as an **associated file**: the catalog's `/AF` array
names it and its `/AFRelationship` (`Source`, `Data`, `Alternative`,
`Supplement`, `Unspecified`) says what it is to the document, which is the
pair an e-invoice consumer looks for. Attachments are encrypted along with
everything else when the document is.

## Rendering untrusted HTML

**The supported input is a template you control.** If you render HTML that
users influence, read this section.

Layout is a fixpoint computation, and some inputs never converge. Without
ceilings, a few KB of hostile HTML consumes unbounded CPU. The limits are
therefore **security controls, not tuning knobs**:

```php
'limits' => [
    'max_pages'          => 2000,
    'max_depth'          => 64,
    'max_length'         => 200000.0,
    'max_font_size'      => 2000.0,
    'timeout_seconds'    => 30.0,
    'max_gradient_stops' => 500000,
],
```

A render that exceeds them throws a named exception
(`LayoutTimeoutException`, `PageLimitExceededException`,
`GradientLimitExceededException`) rather than returning a document quietly
missing its tail. Override the wall clock per render with
`->timeout($seconds)`.

**File access is scoped.** `base_path` is the only directory a document can
read: `<img src>`, `@font-face src` and stylesheet hrefs resolve against it
and are refused outside it, symlinks followed. With no base path, no file is
reachable at all.

**The network is not touched unless you turn it on.** Remote images are off by
default, and enabling them requires naming the hosts:

```php
'remote_images' => [
    'enabled'       => true,
    'allowed_hosts' => ['cdn.example.com'],
    'max_bytes'     => 2_000_000,
    'timeout'       => 5.0,
],
```

Fetches are `https` only, exact host match, private and loopback addresses
refused, no redirects, body size capped while reading, bytes sniffed rather
than the content type believed. **A remote stylesheet or font is never
fetched**: a stylesheet is a second document, and a font is glyph data copied
into your output.

Still, rendering is CPU work in your process. If the input is not yours, put
it behind a queue.

## Performance

Real templates, rendered on a laptop, single process:

| Document | Pages | Time | Output |
|---|---|---|---|
| Invoice (flex layout, custom font, SVG logo) | 1 | ~180 ms | 49 KB |
| Bank statement (tables across pages) | 3 | ~330 ms | 43 KB |
| Report (charts, grid, images) | 5 | ~320 ms | 245 KB |
| Presentation (full-bleed pages) | 8 | ~190 ms | 466 KB |

Your numbers will vary with content; the point is the order of magnitude. A
typical business document is a few hundred milliseconds, in-process, with no
container or browser to keep warm.

## Current limitations

The CSS gaps are marked 🚧 and ⚠️ in the tables under
[What CSS is supported](#what-css-is-supported). Beyond those, not here yet:

- **Writing systems**: no Chinese, Japanese, Korean, Indic scripts, Thai or
  Khmer, and no vertical writing or ruby. See
  [Fonts and writing systems](#fonts-and-writing-systems).

And two things that are **by design and will stay this way**:

- **An encrypted document is not byte-reproducible.** Key material is random
  per render, as it should be. Everything else in the writer is deterministic.
- **No JavaScript in the output.** A PDF that executes code is an attack
  surface this package chooses not to have.

## Roadmap

FlexPDF is in active development. Everything marked 🚧 or ⚠️ on this page is
collected in [ROADMAP.md](ROADMAP.md), with what happens today until each item
lands. No dates and no fixed order: what real documents run into first gets
built first.

## Testing

```bash
composer test          # Pest, the Laravel integration
composer test-engine   # the 9 engine suites, 596 tests
composer test-all      # both
```

The engine suites verify output by parsing the generated PDFs back, and the
rasterizing checks shell out to Python. To run them locally:

```bash
python3 -m venv .venv
.venv/bin/pip install pypdf pypdfium2 numpy pillow fonttools

# macOS
brew install --cask font-dejavu
# Debian/Ubuntu
sudo apt-get install fonts-dejavu-core
```

None of this is needed to *use* the package, only to run the engine suites.

There is also a fuzzer, which has found more bugs than every hand-written test
combined:

```bash
php tests/Engine/fuzz.php <seed> <iterations>
```

It generates random documents and asserts invariants: finite geometry, no
negative sizes, nothing painting past a page edge, no content lost, pagination
proportional to content, and PDFs that parse back.

## Credits

- [Tim Klaversma](https://github.com/tklaversma)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
