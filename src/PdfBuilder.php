<?php

declare(strict_types=1);

namespace FlexPDF;

use FlexPDF\Engine\FontRegistry;
use FlexPDF\Engine\FontReport;
use FlexPDF\Engine\Html;
use FlexPDF\Engine\Support\Encryption;
use FlexPDF\Engine\Support\Limits;
use FlexPDF\Engine\Support\PdfPermission;
use FlexPDF\Engine\Support\RemoteImages;
use FlexPDF\Support\PageSize;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Fluent wrapper around the layout engine. Every method returns $this until
 * one of download(), stream(), inline() or save() ends the chain.
 */
class PdfBuilder
{
    protected string $html;

    /** @var array<int, array{0:string,1:array{regular:string,bold:?string,italic:?string,bold-italic:?string},2:float}> */
    protected array $fonts = [];

    /** @var string[] */
    protected array $stylesheets = [];

    /** @var string|(callable(int,int):?string)|null */
    protected $header = null;

    /** @var string|(callable(int,int):?string)|null */
    protected $footer = null;

    /** @var array{0:float,1:float} */
    protected array $pageSize;

    /** @var array{top:float,right:float,bottom:float,left:float} */
    protected array $margins;

    protected string $basePath;

    protected Limits $limits;

    /** Whether a document may fetch an image, and from where. Off by default. */
    protected RemoteImages $remoteImages;

    /** @var array<string, string> */
    protected array $metadata = [];

    /** @var array{fit:string,page:int,args:list<float>}|null how the document opens */
    protected ?array $openAction = null;

    /** Which panel a reader shows beside the page. Empty writes none. */
    protected string $pageMode = '';

    /** @var list<array{0:string,1:string,2:string,3:string,4:string}> files carried inside the document */
    protected array $attachments = [];

    /** Passwords and permissions, or null for a document anyone can open. */
    protected ?Encryption $encryption = null;

    /** Whether to write the structure tree, and the language to declare. */
    protected bool $tagged = false;

    /** Whether the document is written for archiving: PDF/A-3b. */
    protected bool $pdfa = false;

    /** The level the archival claim names, `B` unless the caller asked for `A`. */
    protected string $conformance = 'B';

    /** Whether the file claims ISO 14289-1 beside the archival claim. */
    protected bool $pdfua = false;

    protected string $lang = '';

    /**
     * Whether a character the resolved family cannot draw is looked for in the
     * bundled face. On by default: off means it goes back to being painted as
     * `?`.
     */
    protected bool $fontFallback = true;

    /** Whether a font-family list that resolves to nothing refuses the render. */
    protected bool $strictFonts = false;

    /** What the last render asked for and did not get. */
    protected ?FontReport $fontReport = null;

    /** @param array<string, mixed> $config */
    public function __construct(string $html, protected array $config = [])
    {
        $this->html = $html;

        $this->pageSize = PageSize::resolve(
            $config['page']['size'] ?? 'a4',
            $config['page']['orientation'] ?? 'portrait',
        );

        $margins       = $config['margins'] ?? [];
        $this->margins = [
            'top'    => (float) ($margins['top'] ?? 50),
            'right'  => (float) ($margins['right'] ?? 50),
            'bottom' => (float) ($margins['bottom'] ?? 50),
            'left'   => (float) ($margins['left'] ?? 50),
        ];

        $this->basePath     = (string) ($config['base_path'] ?? '');
        $this->limits       = Limits::fromArray($config['limits'] ?? []);
        $this->remoteImages = RemoteImages::fromArray($config['remote_images'] ?? []);
        $this->encryption   = Encryption::fromArray($config['encryption'] ?? []);

        $tagging      = $config['tagged'] ?? [];
        $this->tagged = (bool) ($tagging['enabled'] ?? false);
        $this->lang   = (string) ($tagging['lang'] ?? '');

        if ((bool) (($config['pdfa'] ?? [])['enabled'] ?? false)) {
            $this->pdfa(conformance: (string) (($config['pdfa'] ?? [])['conformance'] ?? 'B'));
        }

        if ((bool) (($config['pdfa'] ?? [])['ua'] ?? false)) {
            $this->pdfua();
        }

        foreach ($config['metadata'] ?? [] as $key => $value) {
            $this->metadata[(string) $key] = (string) $value;
        }

        foreach ($config['fonts'] ?? [] as $family => $paths) {
            $this->font((string) $family, $paths);
        }

        $fallback           = $config['font_fallback'] ?? [];
        $this->fontFallback = (bool) ($fallback['enabled'] ?? true);
        $this->strictFonts  = (bool) ($fallback['strict'] ?? false);
    }

    /**
     * Whether a character the resolved family cannot draw may be drawn by the
     * bundled face.
     *
     * On by default. `font-family: Arial` with Polish, Greek, Cyrillic, Hebrew
     * or Arabic text resolves to base-14 Helvetica, which is written with
     * WinAnsi and has no slot for any of them, so without this it prints rows
     * of question marks. Turning it off restores exactly that.
     *
     * It only ever draws what the resolved face could not, so a document whose
     * own faces cover its text is unaffected, down to the byte.
     */
    public function fontFallback(bool $fallback = true): static
    {
        $this->fontFallback = $fallback;

        return $this;
    }

    /**
     * Refuse the render when a `font-family` list resolves to no registered
     * face at all, or when a character no face can draw reaches a page.
     *
     * Off by default, and deliberately: a `font-family` list is written so that
     * entries can miss, and refusing on the first miss would refuse stylesheets
     * every browser draws. What is always available instead is
     * {@see fontReport()}, which names every miss without stopping anything.
     *
     * Throws {@see \FlexPDF\Engine\Exceptions\FontMissingException}.
     */
    public function strictFonts(bool $strict = true): static
    {
        $this->strictFonts = $strict;

        return $this;
    }

    /**
     * What the last render asked for and did not get: families that named no
     * registered face, and characters no face could draw.
     *
     * Null until something has been rendered.
     */
    public function fontReport(): ?FontReport
    {
        return $this->fontReport;
    }

    /** The markup this builder will render, for writing beside the PDF when debugging a template. */
    public function markup(): string
    {
        return $this->html;
    }

    /** @param string|array{0:float|int,1:float|int} $size */
    public function page(string|array $size, string $orientation = 'portrait'): static
    {
        $this->pageSize = PageSize::resolve($size, $orientation);

        return $this;
    }

    public function landscape(): static
    {
        [$width, $height] = $this->pageSize;
        $this->pageSize   = [max($width, $height), min($width, $height)];

        return $this;
    }

    public function portrait(): static
    {
        [$width, $height] = $this->pageSize;
        $this->pageSize   = [min($width, $height), max($width, $height)];

        return $this;
    }

    public function margins(float $top, ?float $right = null, ?float $bottom = null, ?float $left = null): static
    {
        $this->margins = [
            'top'    => $top,
            'right'  => $right ?? $top,
            'bottom' => $bottom ?? $top,
            'left'   => $left ?? $right ?? $top,
        ];

        return $this;
    }

    /** Directory that relative <img src> and @font-face url() paths resolve against. */
    public function basePath(string $path): static
    {
        $this->basePath = $path;

        return $this;
    }

    public function css(string $css): static
    {
        $this->stylesheets[] = $css;

        return $this;
    }

    /**
     * Register a TrueType family. Pass a path for regular only, or an array
     * keyed regular/bold/italic/bold-italic.
     *
     * **A family registered at two widths is two calls.** The registry keys a
     * face on its width the way an `@font-face` rule does, so a second set of
     * files under the same family needs to say which width it is or it
     * replaces the first: pass a `font-stretch` percentage or keyword and a
     * document asking for `font-stretch: condensed` gets the condensed files.
     * In config the same value is a `width` entry beside the paths.
     *
     *     ->font('Inter', [...])
     *     ->font('Inter', [...], 'condensed')
     *
     * @param  string|array<string, string|float>  $paths
     */
    public function font(string $family, string|array $paths, float|string|null $width = null): static
    {
        $paths = is_string($paths) ? ['regular' => $paths] : $paths;
        $width ??= $paths['width'] ?? 100.0;

        unset($paths['width']);

        if (! isset($paths['regular'])) {
            throw new \InvalidArgumentException(
                sprintf('Font family [%s] needs at least a "regular" path.', $family),
            );
        }

        $this->fonts[] = [
            $family,
            [
                'regular'     => (string) $paths['regular'],
                'bold'        => isset($paths['bold']) ? (string) $paths['bold'] : null,
                'italic'      => isset($paths['italic']) ? (string) $paths['italic'] : null,
                'bold-italic' => isset($paths['bold-italic']) ? (string) $paths['bold-italic'] : null,
            ],
            is_string($width) ? FontRegistry::width($width) : (float) $width,
        ];

        return $this;
    }

    /**
     * Running header drawn in the top margin. {page} and {pages} are
     * substituted. A callable is handed the 1-based page number and the page
     * count and returns that page's markup, or null for no header there:
     *
     *     ->header(fn (int $page): ?string => $page === 1 ? null : '<div>…</div>')
     *
     * @param string|(callable(int,int):?string) $html
     */
    public function header(string|callable $html): static
    {
        $this->header = $html;

        return $this;
    }

    /**
     * Running footer drawn in the bottom margin, with the same callable form
     * as {@see header()}.
     *
     * @param string|(callable(int,int):?string) $html
     */
    public function footer(string|callable $html): static
    {
        $this->footer = $html;

        return $this;
    }

    /**
     * Document metadata, written to the PDF's /Info dictionary. Recognised
     * keys are title, author, subject, keywords, creator, producer,
     * creationDate and modDate.
     *
     * No date is written unless you pass one, so two renders of the same
     * document stay byte-identical.
     *
     * @param  array<string, string>  $entries
     */
    public function metadata(array $entries): static
    {
        foreach ($entries as $key => $value) {
            $this->metadata[$key] = $value;
        }

        return $this;
    }

    /**
     * How a reader should open the document.
     *
     *     ->initialView('Fit')                 // the whole page, on page one
     *     ->initialView('FitH', 2, [280.0])    // page two, that y at the top
     *
     * $fit takes the eight destination types of PDF 32000-1 table 151 without
     * regard to case: Fit, FitH, FitV, FitR, FitB, FitBH, FitBV and XYZ, so a
     * lowercase `fitH` is accepted unchanged. $args carries whatever
     * coordinates the type needs,
     * in the spec's own order, and one left out is written as `null`, which
     * tells a reader to keep that axis as it found it.
     *
     * @param  list<float>  $args
     */
    public function initialView(string $fit = 'Fit', int $page = 1, array $args = []): static
    {
        $this->openAction = ['fit' => $fit, 'page' => $page, 'args' => $args];

        return $this;
    }

    /**
     * Which panel a reader shows beside the page.
     *
     *     ->pageMode('UseOutlines')      // open with the bookmarks showing
     *     ->pageMode('UseAttachments')   // open with the attachments showing
     *
     * One of UseNone, UseOutlines, UseThumbs, FullScreen, UseOC or
     * UseAttachments. A document that writes bookmarks and does not ask for
     * this opens with them hidden.
     */
    public function pageMode(string $mode): static
    {
        $this->pageMode = $mode;

        return $this;
    }

    /**
     * Carry a file inside the document.
     *
     *     ->attach('factur-x.xml', $xml, 'text/xml', relationship: 'Data')
     *
     * This is the half of PDF/A-3 the format exists for: an archived invoice
     * travels with the machine-readable original it was rendered from, which
     * is what Factur-X and ZUGFeRD e-invoicing are built on. The file is
     * written as an associated file, so the catalog names it in /AF and the
     * /AFRelationship says what it is to the document.
     *
     * $relationship is one of Source, Data, Alternative, Supplement or
     * Unspecified: Data where this PDF is the original and the payload
     * describes it, Source where the payload is the original.
     */
    public function attach(
        string $name,
        string $bytes,
        string $mime = 'application/octet-stream',
        string $description = '',
        string $relationship = 'Data',
    ): static {
        $this->attachments[] = [$name, $bytes, $mime, $description, $relationship];

        return $this;
    }

    /**
     * Encrypt the document with AES-256, revision 6.
     *
     * The user password opens it, and a reader holds whoever opened it that
     * way to $allow. The owner password opens it with no restriction at all,
     * so leave it out and one nobody holds is invented: reusing the user's
     * would make every restriction bypassable by anyone who can open the file.
     *
     *     ->encrypt('invoice-2026')                          // a password, nothing withheld
     *     ->encrypt('invoice-2026', allow: ['print'])        // and print only
     *     ->encrypt(allow: ['print'])                        // opens freely, print only
     *
     * $allow takes PdfPermission cases or their string values, and null (the
     * default) is every permission. Passing [] withholds all of them.
     *
     * An encrypted document is not byte-reproducible: the file key, the
     * password salts and every initialization vector are random per render.
     *
     * @param ?array<int, PdfPermission|string> $allow
     */
    public function encrypt(string $password = '', string $ownerPassword = '', ?array $allow = null): static
    {
        $this->encryption = new Encryption(
            $password,
            $ownerPassword,
            $allow === null ? null : Encryption::permissionsFrom($allow),
        );

        return $this;
    }

    /**
     * Write the document's structure as well as its ink: Tagged PDF.
     *
     * Every piece of ink is wrapped in a marked-content sequence and a tree of
     * structure elements says which paragraph, heading or table cell it
     * belongs to, in reading order. That is what a screen reader, a reflow
     * view and an HTML export read, and it is what PDF/UA and PDF/A-1a ask
     * for. Backgrounds, borders and running headers are marked as decoration,
     * so a reader skips them.
     *
     * Roles come from the element: `<p>` is a P, `<th>` a TH, `<img>` a Figure
     * carrying its own `alt` as `/Alt`. `$lang` is written to `/Lang`; leave
     * it empty and the document's own `<html lang>` is used.
     *
     * Off by default, because tagging rewrites every content stream and a
     * document's bytes change the day it is turned on.
     */
    public function tagged(bool $tagged = true, string $lang = ''): static
    {
        $this->tagged = $tagged;

        if ($lang !== '') {
            $this->lang = $lang;
        }

        return $this;
    }

    /**
     * Write the document for archiving: PDF/A-3b, ISO 19005-3 level B.
     *
     * A conforming file carries everything it needs to be drawn the same way
     * in fifty years: every font embedded, an sRGB profile saying what its
     * colours mean, and an XMP packet declaring the conformance so an archive
     * can check the claim without opening the pages.
     *
     *     Pdf::view('pdf.invoice', $data)->pdfa()->save($path);
     *
     * Two things it refuses rather than quietly dropping, because writing
     * anything at all would be writing a claim that is not true. A document
     * that reaches one of the 14 standard faces (Helvetica, Times, Courier)
     * has no font file to embed, so register a TrueType family for it. And
     * PDF/A forbids encryption in every part and at every level, so `pdfa()`
     * and `encrypt()` cannot both be on. Both throw `PdfaConformanceException`.
     *
     * It turns tagging on, since an archival document should say what its ink
     * means as well as where it went. Call `->tagged(false)` after this for a
     * conforming file with no structure tree.
     *
     * **`$conformance` is `'A'` or `'B'` and it is a promise rather than a
     * setting.** Level B says the file will be drawn the same way forever;
     * level A says its structure is meaningful too, so a reader can take the
     * document apart. The engine writes everything level A asks for and refuses
     * the claim when the document cannot back it, which is a structure tree and
     * a language:
     *
     *     Pdf::view('pdf.invoice', $data)->pdfa(conformance: 'A')->lang('en')->save($path);
     */
    public function pdfa(bool $pdfa = true, string $conformance = 'B'): static
    {
        $this->pdfa        = $pdfa;
        $this->conformance = $conformance;

        if ($pdfa) {
            $this->tagged = true;
        }

        return $this;
    }

    /**
     * Claim ISO 14289-1, PDF/UA-1, beside the archival claim.
     *
     * PDF/UA is about a document being usable rather than about it surviving:
     * every piece of ink owned by an element that says what it is, in the order
     * a reader should meet it, in a language a reader can announce.
     *
     *     Pdf::view('pdf.invoice', $data)->pdfua()->lang('en')->save($path);
     *
     * It is refused the same way and for the same reason as level A: PDF/UA
     * needs the structure tree and a language, and a file claiming it without
     * them would be contradicted by the first validator that read it.
     */
    public function pdfua(bool $pdfua = true): static
    {
        $this->pdfua = $pdfua;

        if ($pdfua) {
            $this->tagged = true;
        }

        return $this;
    }

    public function limits(Limits $limits): static
    {
        $this->limits = $limits;

        return $this;
    }

    public function timeout(float $seconds): static
    {
        $this->limits = new Limits(
            maxPages        : $this->limits->maxPages,
            maxDepth        : $this->limits->maxDepth,
            maxLength       : $this->limits->maxLength,
            maxFontSize     : $this->limits->maxFontSize,
            timeoutSeconds  : $seconds,
            maxGradientStops: $this->limits->maxGradientStops,
            maxImageBytes   : $this->limits->maxImageBytes,
        );

        return $this;
    }

    /** The rendered PDF as a byte string. */
    public function output(): string
    {
        return $this->render()[0];
    }

    /** Writes the PDF to disk and returns the page count. */
    public function save(string $path): int
    {
        [$bytes, $pages] = $this->render();

        $directory = dirname($path);
        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        file_put_contents($path, $bytes);

        return $pages;
    }

    public function download(string $filename = 'document.pdf'): Response
    {
        return $this->response($this->output(), $filename, 'attachment');
    }

    /** Displayed in the browser rather than saved, sent as a streamed response. */
    public function stream(string $filename = 'document.pdf'): StreamedResponse
    {
        $bytes = $this->output();

        return new StreamedResponse(
            static function () use ($bytes): void {
                echo $bytes;
            },
            200,
            $this->headers($bytes, $filename, 'inline'),
        );
    }

    /** Displayed in the browser rather than saved. */
    public function inline(string $filename = 'document.pdf'): Response
    {
        return $this->response($this->output(), $filename, 'inline');
    }

    /** @return array{0:string,1:int} bytes, page count */
    protected function render(): array
    {
        /*
         * The registry is a process-wide singleton, so under Octane or a queue
         * worker a family registered by one render would otherwise still be
         * visible to the next. Reset before each render to keep them isolated.
         */
        FontRegistry::reset();

        $registry = FontRegistry::default();
        $registry->report()->strict($this->strictFonts);

        if (! $this->fontFallback) {
            $registry->fallback(null);
        }

        $this->fontReport = $registry->report();

        $document = Html::make($this->html)
            ->page($this->pageSize[0], $this->pageSize[1])
            ->margin(
                $this->margins['top'],
                $this->margins['right'],
                $this->margins['bottom'],
                $this->margins['left'],
            )
            ->limits($this->limits)
            ->remoteImages($this->remoteImages);

        if ($this->basePath !== '') {
            $document->basePath($this->basePath);
        }

        foreach ($this->fonts as [$family, $paths, $width]) {
            $registry->registerTrueType(
                $family,
                $paths['regular'],
                $paths['bold'],
                $paths['italic'],
                $paths['bold-italic'],
                $width,
            );
        }

        foreach ($this->stylesheets as $css) {
            $document->css($css);
        }

        if ($this->header !== null) {
            $document->header($this->header);
        }

        if ($this->footer !== null) {
            $document->footer($this->footer);
        }

        if ($this->metadata !== []) {
            $document->info($this->metadata);
        }

        if ($this->openAction !== null) {
            $document->initialView(
                $this->openAction['fit'],
                $this->openAction['page'],
                $this->openAction['args'],
            );
        }

        if ($this->pageMode !== '') {
            $document->pageMode($this->pageMode);
        }

        foreach ($this->attachments as [$name, $bytes, $mime, $description, $relationship]) {
            $document->attach($name, $bytes, $mime, $description, $relationship);
        }

        if ($this->encryption !== null) {
            $document->encrypt($this->encryption);
        }

        // The archival flag goes on first, because it turns tagging on and the
        // caller may have turned it back off after asking for it.
        if ($this->pdfa) {
            $document->pdfa(true, $this->conformance);
        }

        if ($this->pdfua) {
            $document->pdfua();
        }

        $document->tagged($this->tagged, $this->lang);

        return $document->render();
    }

    protected function response(string $bytes, string $filename, string $disposition): Response
    {
        return new Response($bytes, 200, $this->headers($bytes, $filename, $disposition));
    }

    /** @return array<string, string> */
    protected function headers(string $bytes, string $filename, string $disposition): array
    {
        if (! str_ends_with(strtolower($filename), '.pdf')) {
            $filename .= '.pdf';
        }

        return [
            'Content-Type'        => 'application/pdf',
            'Content-Length'      => (string) strlen($bytes),
            'Content-Disposition' => sprintf(
                '%s; filename="%s"',
                $disposition,
                str_replace('"', '', $filename),
            ),
        ];
    }
}
