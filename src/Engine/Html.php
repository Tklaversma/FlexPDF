<?php

declare(strict_types=1);

namespace FlexPDF\Engine;

use DOMDocument;
use DOMElement;
use DOMXPath;
use FlexPDF\Engine\Exceptions\PdfaConformanceException;
use FlexPDF\Engine\Support\AssetPath;
use FlexPDF\Engine\Support\Deadline;
use FlexPDF\Engine\Support\Encryption;
use FlexPDF\Engine\Support\Limits;
use FlexPDF\Engine\Support\Pdfa;
use FlexPDF\Engine\Support\RemoteImages;
use FlexPDF\Engine\Support\TagRepair;

/**
 * The whole pipeline in one call: HTML + CSS in, a paginated PDF out.
 *
 *     Html::make($html)->save('out.pdf');
 */
final class Html
{
    private string $html        = '';
    private array  $stylesheets = [];
    private array  $fonts       = [];

    private float   $pageWidth    = 595.28; // A4
    private float   $pageHeight   = 841.89;
    private array   $margins      = ['top' => 50.0, 'right' => 50.0, 'bottom' => 50.0, 'left' => 50.0];
    private float   $rootFontSize = 12.0;
    private string  $basePath     = '';
    /** @var string|(callable(int,int):?string)|null */
    private $header = null;

    /** @var string|(callable(int,int):?string)|null */
    private $footer = null;
    private Limits  $limits;

    /*
     * One budget spans a whole render: layout, pagination and painting share
     * it, so a document that spends its time in any single stage still stops
     * at the ceiling the caller set. layout() opens it and render() paints
     * against whatever is left.
     */
    private ?Deadline $deadline = null;

    /** @var array<string,string> /Info entries: title, author, subject, ... */
    private array $metadata = [];

    /** @var array{fit:string,page:int,args:list<float>}|null how the document opens */
    private ?array $openAction = null;

    /** Which panel a reader shows beside the page. Empty writes none. */
    private string $pageMode = '';

    /** @var list<array{0:string,1:string,2:string,3:string,4:string}> files carried inside the document */
    private array $attachments = [];

    /*
     * Whether the caller set the geometry explicitly. `@page` fills in only
     * what was left at its default, so a ->page() call is never overridden by
     * a stylesheet the caller may not control.
     */
    private bool $pageWasSet     = false;
    private bool $marginsWereSet = false;

    /**
     * The page box of each kind of page a qualified `@page` block asked for.
     *
     * Empty for every document that declares none, and the whole of `@page
     * :first` / `:left` / `:right` is inert while it is empty.
     *
     * @var array<string,array{width:float,height:float,top:float,right:float,bottom:float,left:float}>
     */
    private array $pageBoxes = [];

    /**
     * Whether the first page is a `:left` page, which is what a right-to-left
     * document makes it. {@see pageKindBoxes}.
     */
    private bool $pagesAreRtl = false;

    /**
     * The page type each page ended up carrying, by 0-based page index, for the
     * pages a `page: <name>` box reached.
     *
     * Empty for every document that names no page, and it is the map the final
     * pagination was produced with rather than a later reading of it, so the
     * painter cannot disagree with the fragmenter about which page is which.
     *
     * @var array<int,string>
     */
    private array $pageNames = [];

    /** Whether a document may fetch an image, and from where. Off by default. */
    private RemoteImages $remoteImages;

    /** Passwords and permissions, or null for a document anyone can open. */
    private ?Encryption $encryption = null;

    /**
     * Whether to write the structure tree, and the language to declare.
     *
     * Off by default. Tagging rewrites every content stream in the document,
     * so turning it on moves every byte-level baseline in the repository, and
     * that is a decision to take once rather than a side effect of upgrading.
     */
    private bool $tagged = false;

    /**
     * Whether the document is written for archiving: PDF/A-3b.
     *
     * Off by default, like tagging and for the same reason, and it is a
     * stricter document rather than a differently drawn one. See
     * `Support\Pdfa` for what it adds and
     * `Exceptions\PdfaConformanceException` for the two things it refuses.
     */
    private bool $pdfa = false;

    /** The level the archival claim names, `B` unless the caller asked for `A`. */
    private string $conformance = Pdfa::CONFORMANCE;

    /** Whether the file claims ISO 14289-1 beside the archival claim. */
    private bool $pdfua = false;

    private string $lang = '';

    /** `<html lang>`, read while parsing and used where the caller named none. */
    private string $documentLang = '';

    /**
     * The resolver a `@page` margin box is built against, which carries **no
     * author stylesheet**: its containing block is the page rather than a box
     * in the tree, so nothing the document says about `body` reaches it.
     */
    private ?StyleResolver $marginBoxStyles = null;

    public function __construct()
    {
        $this->limits       = new Limits();
        $this->remoteImages = new RemoteImages();
    }

    public static function make(string $html): self
    {
        $self       = new self();
        $self->html = $html;

        return $self;
    }

    /** Safety ceilings and the wall-clock budget. See Limits. */
    public function limits(Limits $limits): self
    {
        $this->limits = $limits;

        return $this;
    }

    /**
     * Whether an `<img src>` may name an `https` URL, and which hosts.
     *
     * Off by default, and an empty allowlist with the feature on reaches
     * nothing rather than everything. See `Support\RemoteImages` for what a
     * fetch is bounded by; a stylesheet and a font stay local whatever this
     * says.
     */
    public function remoteImages(RemoteImages $remoteImages): self
    {
        $this->remoteImages = $remoteImages;

        return $this;
    }

    /**
     * Encrypt the document: AES-256, revision 6.
     *
     * The output stops being byte-reproducible, since the file key, the salts
     * and every initialization vector are random per render. See `Encryption`.
     */
    public function encrypt(Encryption $encryption): self
    {
        $this->encryption = $encryption;

        return $this;
    }

    /**
     * Write the document's structure as well as its ink: Tagged PDF.
     *
     * Every piece of ink is wrapped in a marked-content sequence and a tree of
     * structure elements says which paragraph, heading or table cell it
     * belongs to, in reading order. That is what a screen reader, a reflow
     * view and an HTML export read, and it is what PDF/UA and PDF/A-1a ask
     * for.
     *
     * `$lang` is written to `/Lang`; leave it empty and the document's own
     * `<html lang>` is used.
     */
    public function tagged(bool $tagged = true, string $lang = ''): self
    {
        $this->tagged = $tagged;
        $this->lang   = $lang;

        return $this;
    }

    /**
     * Write the document for archiving: PDF/A-3b, ISO 19005-3 level B.
     *
     * The file carries everything it needs to be drawn the same way in fifty
     * years: every font embedded, an sRGB profile saying what its colors
     * mean, and an XMP packet declaring the conformance so an archive can
     * check the claim without opening the pages.
     *
     * Two things it refuses rather than quietly dropping, both because
     * writing anything at all would be writing a claim that is not true. A
     * document that reaches one of the 14 standard faces has no font file to
     * embed, and PDF/A forbids encryption in every part and at every level.
     * Both throw `PdfaConformanceException`.
     *
     * It turns tagging on, since an archival document should say what its ink
     * means as well as where it went. Call `->tagged(false)` after this to
     * write a conforming file without the structure tree.
     *
     * **`$conformance` is `'A'` or `'B'` and it is a promise rather than a
     * setting.** Level B says the file will be drawn the same way forever;
     * level A says its structure is meaningful as well, so a reader can take
     * the document apart and put it back together. The engine writes everything
     * level A asks for, which `verapdf -f 3a` says at 154 of 155 rules, and the
     * one thing it cannot know is whether the caller means the claim. So the
     * letter is asked for rather than assumed, and a claim the file cannot back
     * is refused: level A needs the structure tree and a document language.
     */
    public function pdfa(bool $pdfa = true, string $conformance = Pdfa::CONFORMANCE): self
    {
        $this->pdfa        = $pdfa;
        $this->conformance = strtoupper(trim($conformance));

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
     * a reader should meet it, in a language a reader can announce. The engine
     * writes all of that, and `verapdf -f ua1` passes the four `SM` probe pages
     * with the identification schema as the only failure, which is what this
     * adds.
     *
     * **It is a claim and it is refused when the file cannot back it**, the
     * same way `pdfa()` refuses a base-14 face: PDF/UA needs the structure tree
     * and a document language, and a file that has neither would be claiming
     * something a validator will contradict.
     */
    public function pdfua(bool $pdfua = true): self
    {
        $this->pdfua = $pdfua;

        if ($pdfua) {
            $this->tagged = true;
        }

        return $this;
    }

    /** Directory that relative @font-face and <img src> paths resolve against. */
    public function basePath(string $dir): self
    {
        $this->basePath = $dir;

        return $this;
    }

    public function css(string $css): self
    {
        $this->stylesheets[] = $css;

        return $this;
    }

    /**
     * Register a family, in the four slots the registry has.
     *
     * All four, because `FontRegistry::registerTrueType()` and the Laravel
     * `PdfBuilder::font()` both take four and this took two: a document that
     * named an italic face through here got the regular one, so `<em>`, `<i>`
     * and `font-style: italic` came out upright with no way to say otherwise.
     * Defect EA.
     *
     * **`$width` is the face's own `font-stretch` as a percentage**, which a
     * `@font-face` rule has been able to say since round 37 and this could
     * not: two widths of one family registered here landed on one key and the
     * second won, so every word came out in whichever file was registered
     * last. Defect HK.
     */
    public function font(
        string $family,
        string $regular,
        ?string $bold = null,
        ?string $italic = null,
        ?string $boldItalic = null,
        float $width = 100.0,
    ): self {
        $this->fonts[] = [$family, $regular, $bold, $italic, $boldItalic, $width];

        return $this;
    }

    /**
     * Running header, drawn in the top margin of every page.
     * {page} and {pages} are substituted per page.
     *
     * **Pass a callable to choose per page.** It is handed the 1-based page
     * number and the page count and returns the markup for that page, or null
     * for no header at all there, which is what leaves a cover page bare:
     *
     *     ->header(fn (int $page, int $total): ?string => $page === 1
     *         ? null
     *         : '<div>Invoice, page {page} of {pages}</div>')
     *
     * The count is the real one: pagination is finished before the first
     * header is asked for, so a callable can say "last page" as easily as
     * "first".
     *
     * @param string|(callable(int,int):?string) $html
     */
    public function header(string|callable $html): self
    {
        $this->header = $html;

        return $this;
    }

    /**
     * Running footer, drawn in the bottom margin of every page. Takes the same
     * callable as {@see header()}.
     *
     * @param string|(callable(int,int):?string) $html
     */
    public function footer(string|callable $html): self
    {
        $this->footer = $html;

        return $this;
    }

    /**
     * One page's header or footer markup, or null where that page has none.
     *
     * @param string|(callable(int,int):?string)|null $running
     */
    private function runningFor($running, int $pageNo, int $total): ?string
    {
        if ($running === null) {
            return null;
        }

        $html = is_callable($running) ? $running($pageNo, $total) : $running;

        return $html === null || trim($html) === '' ? null : $html;
    }

    /**
     * Document metadata, written to the PDF's /Info dictionary.
     *
     * Recognised keys are title, author, subject, keywords, creator and
     * producer. Empty values are dropped rather than written blank.
     *
     * @param array<string,string> $entries
     */
    public function info(array $entries): self
    {
        foreach ($entries as $key => $value) {
            $this->metadata[$key] = $value;
        }

        return $this;
    }

    /**
     * How a reader should open the document.
     *
     * `$fit` is one of the eight destination types of PDF 32000-1 table 151,
     * matched without regard to case: `Fit`, `FitH`, `FitV`, `FitR`, `FitB`,
     * `FitBH`, `FitBV` and `XYZ`, so a lowercase `fitH` travels unchanged.
     *
     * @param list<float> $args the coordinates the type needs, in the spec's order
     */
    public function initialView(string $fit = 'Fit', int $page = 1, array $args = []): self
    {
        $this->openAction = ['fit' => $fit, 'page' => $page, 'args' => $args];

        return $this;
    }

    /**
     * Which panel a reader shows beside the page.
     *
     * `UseOutlines` opens the bookmarks panel, which is what a document that
     * writes bookmarks almost always wants and never got: without it a reader
     * opens with them hidden and nothing on the page says they exist.
     */
    public function pageMode(string $mode): self
    {
        $this->pageMode = $mode;

        return $this;
    }

    /**
     * A file carried inside the document, as a PDF/A-3 associated file.
     *
     * The archival format exists so a machine-readable original can travel
     * with the rendering of it, which is what Factur-X and ZUGFeRD e-invoicing
     * are built on. `$relationship` says what the file is TO the document:
     * `Data` where this PDF is the original and the payload describes it,
     * `Source` where the payload is the original and this PDF renders it.
     */
    public function attach(
        string $name,
        string $bytes,
        string $mime = 'application/octet-stream',
        string $description = '',
        string $relationship = 'Data',
    ): self {
        $this->attachments[] = [$name, $bytes, $mime, $description, $relationship];

        return $this;
    }

    public function page(float $width, float $height): self
    {
        $this->pageWidth   = $width;
        $this->pageHeight  = $height;
        $this->pageWasSet  = true;

        return $this;
    }

    public function margin(float $top, ?float $right = null, ?float $bottom = null, ?float $left = null): self
    {
        $this->margins = [
            'top'    => $top,
            'right'  => $right ?? $top,
            'bottom' => $bottom ?? $top,
            'left'   => $left ?? $right ?? $top,
        ];

        $this->marginsWereSet = true;

        return $this;
    }

    /** Named page sizes in points, portrait. */
    private const array PAGE_SIZES = [
        'a3'     => [841.89, 1190.55],
        'a4'     => [595.28, 841.89],
        'a5'     => [419.53, 595.28],
        'a6'     => [297.64, 419.53],
        'b4'     => [708.66, 1000.63],
        'b5'     => [498.90, 708.66],
        'letter' => [612.00, 792.00],
        'legal'  => [612.00, 1008.00],
        'ledger' => [1224.00, 792.00],
        'tabloid' => [792.00, 1224.00],
    ];

    /**
     * `@page { size: ... ; margin: ... }`. An explicit ->page() or ->margin()
     * call is the author's most specific instruction, so the stylesheet only
     * fills in what the API left at its default.
     */
    private function applyPageRule(StyleResolver $resolver): void
    {
        $page = $resolver->pageStyle;

        if ($page === []) {
            return;
        }

        $box = $this->layerPageBox($this->withoutApiOverrides($page), $this->declaredBox(), $resolver);

        [$this->pageWidth, $this->pageHeight] = [$box['width'], $box['height']];

        foreach (['top', 'right', 'bottom', 'left'] as $edge) {
            $this->margins[$edge] = $box[$edge];
        }
    }

    /**
     * One `@page` block's declarations with whatever the API already settled
     * taken out.
     *
     * An explicit `->page()` or `->margin()` call is the author's most specific
     * instruction and beats the stylesheet, and **a qualified block is still
     * the stylesheet**: `@page :first { margin-top: 135pt }` in a document
     * rendered through a runner that passes `->margin(0)` has to reach nothing,
     * exactly as the unqualified block does. `probebytes.sh` renders all 645
     * probe pages that way and caught this as ten moved pages.
     *
     * `->margin()` replaces all four edges, so the `margin` shorthand goes with
     * the longhands rather than being expanded and half-kept.
     *
     * @param  array<string,mixed> $page
     * @return array<string,mixed>
     */
    private function withoutApiOverrides(array $page): array
    {
        if ($this->pageWasSet) {
            unset($page['size']);
        }

        if ($this->marginsWereSet) {
            unset(
                $page['margin'],
                $page['margin-top'],
                $page['margin-right'],
                $page['margin-bottom'],
                $page['margin-left'],
            );
        }

        return $page;
    }

    /**
     * The page box as the API and the unqualified `@page` block leave it.
     *
     * @return array{width:float,height:float,top:float,right:float,bottom:float,left:float}
     */
    private function declaredBox(): array
    {
        return [
            'width'  => $this->pageWidth,
            'height' => $this->pageHeight,
            'top'    => $this->margins['top'],
            'right'  => $this->margins['right'],
            'bottom' => $this->margins['bottom'],
            'left'   => $this->margins['left'],
        ];
    }

    /**
     * One `@page` block's declarations laid over a page box.
     *
     * The margins resolve against the width the same block's `size` left, which
     * is the order the box model asks for: a percentage margin is a percentage
     * of the page it is on.
     *
     * @param  array<string,mixed> $page declarations, as ['value' => ..., 'important' => ...]
     * @param  array{width:float,height:float,top:float,right:float,bottom:float,left:float} $box
     * @return array{width:float,height:float,top:float,right:float,bottom:float,left:float}
     */
    private function layerPageBox(array $page, array $box, StyleResolver $resolver): array
    {
        $declared = static function (string $property) use ($page): ?string {
            $entry = $page[$property] ?? null;

            if ($entry === null) {
                return null;
            }

            return is_array($entry) ? (string) ($entry['value'] ?? '') : (string) $entry;
        };

        $size = $declared('size');

        if ($size !== null) {
            [$box['width'], $box['height']] = $this->sizeFrom($size, $box['width'], $box['height']);
        }

        $shorthand = $declared('margin');
        $fromShorthand = $shorthand === null
            ? []
            : $this->expandMarginShorthand($shorthand, $resolver);

        foreach (['top', 'right', 'bottom', 'left'] as $edge) {
            $longhand = $declared("margin-$edge");

            $value = $longhand !== null
                ? $resolver->length($longhand, $this->rootFontSize, $this->rootFontSize, $box['width'])
                : ($fromShorthand[$edge] ?? null);

            if ($value !== null) {
                $box[$edge] = $value;
            }
        }

        return $box;
    }

    private function applyPageSize(string $size): void
    {
        [$this->pageWidth, $this->pageHeight] = $this->sizeFrom($size, $this->pageWidth, $this->pageHeight);
    }

    /**
     * A `@page { size }` value as a pair of lengths, over the pair in force.
     *
     * Pure, so a qualified block can be asked the same question without moving
     * the document's own page size.
     *
     * @return array{0:float,1:float}
     */
    private function sizeFrom(string $size, float $width, float $height): array
    {
        $parts = preg_split('/\s+/', strtolower(trim($size))) ?: [];
        $parts = array_values(array_filter($parts, static fn(string $p): bool => $p !== ''));

        if ($parts === [] || $parts[0] === 'auto') {
            return [$width, $height];
        }

        $orientation = null;

        foreach ($parts as $i => $part) {
            if ($part === 'landscape' || $part === 'portrait') {
                $orientation = $part;
                unset($parts[$i]);
            }
        }

        $parts = array_values($parts);

        if (isset(self::PAGE_SIZES[$parts[0] ?? ''])) {
            [$width, $height] = self::PAGE_SIZES[$parts[0]];
        } elseif ($parts !== []) {
            // A pair of lengths, or one length for a square page.
            $resolver = new StyleResolver($this->limits, $this->remoteImages);
            $declared = $resolver->length($parts[0], $this->rootFontSize, $this->rootFontSize);
            $second   = isset($parts[1])
                ? $resolver->length($parts[1], $this->rootFontSize, $this->rootFontSize)
                : $declared;

            if ($declared === null || $second === null || $declared <= 0.0 || $second <= 0.0) {
                return [$width, $height];
            }

            [$width, $height] = [$declared, $second];
        }

        if ($orientation === 'landscape') {
            [$width, $height] = [max($width, $height), min($width, $height)];
        }

        if ($orientation === 'portrait') {
            [$width, $height] = [min($width, $height), max($width, $height)];
        }

        return [$width, $height];
    }

    /**
     * The page box of every kind of page a document can have,
     * {@see pageKinds} for what those kinds are.
     *
     * `:first` beats `:left` and `:right` on SPECIFICITY rather than on source
     * order, which is measured rather than read: `UT-page-first-cascade.html`
     * and `UU-page-first-order.html` are the same document with the two blocks
     * swapped and Chrome gives both the same six page counts.
     *
     * Which parity page 1 is depends on the document's direction and that is
     * not resolved until the tree is built. `UR-page-parity-top.html` puts 9
     * paragraphs on page 1 and `UV-page-parity-rtl.html` puts 6, so Chrome
     * reads the first page of a right-to-left document as a LEFT page.
     *
     * @return array<string,array{width:float,height:float,top:float,right:float,bottom:float,left:float}>
     */
    private function pageKindBoxes(StyleResolver $resolver): array
    {
        $base  = $this->declaredBox();
        $kinds = $this->pageKinds($resolver);

        $boxes  = [];
        $reached = false;

        foreach ($kinds as $kind => $selectors) {
            $box = $base;

            foreach ($selectors as $selector) {
                $declarations = $this->withoutApiOverrides($resolver->pageSelectors[$selector] ?? []);

                if ($declarations !== []) {
                    $box     = $this->layerPageBox($declarations, $box, $resolver);
                    $reached = true;
                }
            }

            $boxes[$kind] = $box;
        }

        // Nothing survived the API's own settings, so the document has one page
        // box after all and the whole of this is inert again.
        if (!$reached) {
            return [];
        }

        return $boxes;
    }

    /**
     * Every page kind wider than the document itself given the document's own
     * inline geometry back.
     *
     * This is the answer for a document whose lines will not fit a page
     * narrower than the widest kind, {@see inkFitsNarrowPages}, and it is what
     * every such document got before round 87 without being laid out wide
     * first. A kind that is given the document's width back cannot keep the
     * height it asked for either, because the two came from one `size`, and a
     * kind whose width was never its own keeps its height: that is every
     * height-only block such as `UQ-page-first-size.html`.
     *
     * The margins are the same rule one property along. A kind can be wider
     * than the document with the document's own sheet, by asking for narrower
     * margins, so the width is taken back first and the margins are asked
     * again afterwards. `US-page-parity-inline.html` mirrors its margins so
     * every page agrees on the width and keeps them.
     *
     * @param  array<string,array{width:float,height:float,top:float,right:float,bottom:float,left:float}> $boxes
     * @return array<string,array{width:float,height:float,top:float,right:float,bottom:float,left:float}>
     */
    private function clampWideKinds(array $boxes): array
    {
        $base      = $this->declaredBox();
        $baseWidth = round(self::boxContentWidth($base), 3);

        foreach ($boxes as $kind => $box) {
            if (self::boxContentWidth($box) > self::boxContentWidth($base) + 0.001) {
                $box['width']  = $base['width'];
                $box['height'] = $base['height'];
            }

            if (round(self::boxContentWidth($box), 3) > $baseWidth) {
                $box['width'] = $base['width'];
                $box['left']  = $base['left'];
                $box['right'] = $base['right'];
            }

            $boxes[$kind] = $box;
        }

        return $boxes;
    }

    /**
     * The `@page` selectors each kind of page is composed from, in the order
     * they are layered.
     *
     * CSS Paged Media 3 section 3 gives a document three kinds and no more:
     * the first page, and then the two parities. Four keys rather than three,
     * because which parity page 1 is depends on the document's direction.
     *
     * A named `@page` is a fifth kind and then some: one key per name the
     * document declares a block for, plus the composed `<name>:first` where
     * that exists, which `VJ-page-named-firstpage.html` says Chrome honors
     * over the bare name on the page where both match. A name is spelled with
     * an `@` here so it cannot collide with a parity kind.
     *
     * This is the one definition of what a page inherits from the stylesheet.
     * The page box reads it through {@see pageKindBoxes} and the margin boxes
     * through {@see marginBoxesForPage}, so the two cannot drift apart.
     *
     * @return array<string,list<string>>
     */
    private function pageKinds(StyleResolver $resolver): array
    {
        $kinds = [
            'first-odd'  => [':right', ':first'],
            'first-even' => [':left', ':first'],
            'right'      => [':right'],
            'left'       => [':left'],
        ];

        foreach ($this->declaredPageNames($resolver) as $name) {
            $kinds['@' . $name] = [$name];

            if (isset($resolver->pageSelectors[$name . ':first'])) {
                $kinds['@' . $name . ':first'] = [$name, $name . ':first'];
            }
        }

        return $kinds;
    }

    /**
     * Every page type the document declares an `@page` block for, without the
     * pseudo-classes, in the order they were declared.
     *
     * `CssParser` keys a qualified block by its whole prelude, so `@page cover`
     * is `cover` and `@page cover:first` is `cover:first`, and a block with no
     * name at all starts with the colon.
     *
     * @return list<string>
     */
    private function declaredPageNames(StyleResolver $resolver): array
    {
        $names = [];

        foreach (array_keys($resolver->pageSelectors) as $selector) {
            if (str_starts_with($selector, ':')) {
                continue;
            }

            $name = explode(':', $selector, 2)[0];

            if ($name !== '' && !in_array($name, $names, true)) {
                $names[] = $name;
            }
        }

        return $names;
    }

    /**
     * The page box one page uses, by page number and by the page type its
     * content asked for.
     *
     * A name wins over the parity, because the two cannot both be honored and
     * `page: cover` is the more specific of them: `VD-page-named.html` puts the
     * cover on page 2 and Chrome gives that page the cover's own sheet.
     *
     * @param array<string,array{width:float,height:float,top:float,right:float,bottom:float,left:float}> $boxes
     * @return array{width:float,height:float,top:float,right:float,bottom:float,left:float}
     */
    private static function boxForPage(array $boxes, int $pageNo, bool $rtl, string $name = ''): array
    {
        return $boxes[self::kindForPage($boxes, $pageNo, $rtl, $name)];
    }

    /**
     * Which kind of page number $pageNo is, as a key into {@see pageKinds}.
     *
     * A page the `page` property named takes its name's kind and no parity at
     * all: `VD-page-named.html` and `VH-page-named-run.html` read that off
     * Chrome, and `@page <name>:first` beats the bare name where both match.
     *
     * @param array<string,mixed> $kinds keyed the way {@see pageKinds} keys it
     */
    private static function kindForPage(array $kinds, int $pageNo, bool $rtl, string $name = ''): string
    {
        if ($name !== '') {
            if ($pageNo === 1 && isset($kinds['@' . $name . ':first'])) {
                return '@' . $name . ':first';
            }

            if (isset($kinds['@' . $name])) {
                return '@' . $name;
            }
        }

        $odd = $pageNo % 2 === 1;

        if ($pageNo === 1) {
            return $odd !== $rtl ? 'first-odd' : 'first-even';
        }

        return $odd !== $rtl ? 'right' : 'left';
    }

    /**
     * The `@page` margin boxes in force on one page, by box name.
     *
     * A margin box declared in a qualified or named block is layered over the
     * unqualified block's in the same order and by the same kind map the page
     * box itself is composed with, so `@page :first { @top-right { ... } }`
     * reaches page 1 and nothing else.
     *
     * **Chrome is the reference here and it answers.** Round 74's brief said
     * Chrome renders no margin box at all; it renders all sixteen, and
     * `VT-page-margin-qualified.html` and `VU-page-margin-named.html` are the
     * two documents that read a qualified and a named one back off the raster.
     *
     * @return array<string,array<string,array{value:string,important:bool}>>
     */
    private function marginBoxesForPage(StyleResolver $resolver, int $pageNo, string $name): array
    {
        $boxes = $resolver->pageMargins;

        if ($resolver->pageSelectorMargins === []) {
            return $boxes;
        }

        $kinds = $this->pageKinds($resolver);

        foreach ($kinds[self::kindForPage($kinds, $pageNo, $this->pagesAreRtl, $name)] as $selector) {
            foreach ($resolver->pageSelectorMargins[$selector] ?? [] as $box => $declarations) {
                $boxes[$box] = array_merge($boxes[$box] ?? [], $declarations);
            }
        }

        return $boxes;
    }

    /**
     * Whether every page narrower than the width the document was laid out at
     * can show its own ink inside its own content box.
     *
     * A line that fits a narrower box was not broken by the wider one: the word
     * that ended it did not fit the wider width either, so it cannot fit the
     * narrower, and both widths break the line in the same place. That is what
     * makes one layout exact on a page it was not fitted to, and it stops being
     * true the moment any line or any painted box reaches past the narrower box.
     *
     * A box that paints nothing is not ink. `UP-page-first-inline.html`'s
     * paragraphs are as wide as the page they were laid out for and carry no
     * background, so what has to fit is the text on them.
     *
     * @param Fragment[][] $pages
     */
    private function inkFitsNarrowPages(array $pages, float $layoutWidth): bool
    {
        if ($this->pageBoxes === []) {
            return true;
        }

        foreach ($pages as $index => $fragments) {
            $box    = self::boxForPage($this->pageBoxes, $index + 1, $this->pagesAreRtl, $this->pageNames[$index] ?? '');
            $narrow = $box['width'] - $box['left'] - $box['right'];

            if ($narrow >= $layoutWidth - 0.001) {
                continue;
            }

            foreach ($fragments as $fragment) {
                $node = $fragment->node;

                if (($node->background !== null || $node->border !== null || $node->image !== null
                    || $node->svg !== null || $node->backgroundLayers !== [])
                    && $fragment->x + $fragment->w > $narrow + 0.001) {
                    return false;
                }

                foreach ($fragment->lines as $line) {
                    foreach ($line->items as $item) {
                        $width = $item->isAtomic() ? $item->run->box->outerWidth() : $item->width;

                        if ($fragment->x + $item->x + $width > $narrow + 0.001) {
                            return false;
                        }
                    }
                }
            }
        }

        return true;
    }

    /** The content height of a page box, floored above zero the way the fragmenter floors its own. */
    private static function boxContentHeight(array $box): float
    {
        return max(1.0, $box['height'] - $box['top'] - $box['bottom']);
    }

    /** The content width of a page box, which is the width its lines would be fitted to. */
    private static function boxContentWidth(array $box): float
    {
        return $box['width'] - $box['left'] - $box['right'];
    }

    /** @return array<string,float> */
    private function expandMarginShorthand(string $value, StyleResolver $resolver): array
    {
        $parts = preg_split('/\s+/', trim($value)) ?: [];
        $parts = array_values(array_filter($parts, static fn(string $p): bool => $p !== ''));

        $lengths = [];

        foreach ($parts as $part) {
            $lengths[] = $resolver->length($part, $this->rootFontSize, $this->rootFontSize, $this->pageWidth);
        }

        [$top, $right, $bottom, $left] = match (count($lengths)) {
            1       => [$lengths[0], $lengths[0], $lengths[0], $lengths[0]],
            2       => [$lengths[0], $lengths[1], $lengths[0], $lengths[1]],
            3       => [$lengths[0], $lengths[1], $lengths[2], $lengths[1]],
            4       => $lengths,
            default => [null, null, null, null],
        };

        return array_filter(
            ['top' => $top, 'right' => $right, 'bottom' => $bottom, 'left' => $left],
            static fn(?float $v): bool => $v !== null,
        );
    }

    /**
     * The page box actually in effect, after `@page` has been folded in.
     * Only meaningful once layout() has run.
     *
     * @return array{0:float,1:float}
     */
    public function pageBox(): array
    {
        return [$this->pageWidth, $this->pageHeight];
    }

    /** @return array{0:Node,1:Fragment[][],2:StyleResolver} tree, pages, resolver */
    public function layout(): array
    {
        $deadline       = $this->limits->deadline();
        $this->deadline = $deadline;

        foreach ($this->fonts as [$family, $regular, $bold, $italic, $boldItalic, $width]) {
            FontRegistry::default()->registerTrueType($family, $regular, $bold, $italic, $boldItalic, $width);
        }

        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">' . TagRepair::apply($this->html), LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();

        $this->documentLang = trim($dom->documentElement?->getAttribute('lang') ?? '');

        $resolver           = new StyleResolver($this->limits, $this->remoteImages);
        $resolver->basePath = $this->basePath;

        foreach ($this->extractStyles($dom) as $css) {
            $resolver->addStylesheet($css);
        }

        foreach ($this->stylesheets as $css) {
            $resolver->addStylesheet($this->expandImports($css));
        }

        // Fonts declared in CSS, registered after the explicit ->font() calls
        // so an explicit registration still wins.
        foreach ($resolver->fontFaces as $face) {
            $registry = FontRegistry::default();
            $registry->register(
                $face['family'],
                $face['bold'],
                new TrueTypeFont($face['src'], $face['family'], $face['bold'], $face['italic']),
                $face['italic'],
                $face['width'],
            );
        }

        $this->applyPageRule($resolver);
        $resolver->viewport($this->pageWidth, $this->pageHeight);

        $this->pageBoxes = $resolver->pageSelectors === [] ? [] : $this->pageKindBoxes($resolver);

        /**
         * Lay the document out for one set of page boxes and cut it into pages.
         *
         * It is a closure rather than straight-line code because a document
         * asking for a page WIDER than itself may have to be laid out twice,
         * and the two passes differ in nothing but the boxes they are given.
         * The second pass replaces the first tree outright, which is what makes
         * it safe: fragments and the structure tree come from one layout, never
         * from two, exactly as the container-query passes above already work.
         *
         * @return array{0:Node,1:Fragment[][],2:float} tree, pages, layout width
         */
        $paginate = function (array $pageBoxes) use ($resolver, $deadline, $dom): array {
            $this->pageBoxes = $pageBoxes;

            $contentWidth = $this->pageWidth - $this->margins['left'] - $this->margins['right'];

            // The flow is one strip cut every $contentHeight, so the strip has
            // to be as tall as the tallest page and every shorter page holds
            // back the difference at its foot. With no qualified `@page` block
            // there is one height and nothing is held back, which is why this
            // cannot move a document that writes none.
            $contentHeight = $this->pageHeight - $this->margins['top'] - $this->margins['bottom'];

            // The WIDEST content box any page actually uses, because that is
            // the one the lines have to be fitted to: a narrower page then
            // shows those same lines inside its own margins, which is only
            // sound while they fit, and {@see inkFitsNarrowPages} is what asks.
            // Every page is one of the four kinds, so the unqualified box is
            // not a candidate once any kind exists.
            $kindWidths = [];

            foreach ($pageBoxes as $box) {
                $kindWidths[]  = self::boxContentWidth($box);
                $contentHeight = max($contentHeight, self::boxContentHeight($box));
            }

            if ($kindWidths !== []) {
                $contentWidth = max($kindWidths);
            }

            $build = function () use ($resolver, $deadline, $contentWidth, $contentHeight, $dom): array {
                $builder = new HtmlBuilder($resolver, $deadline);
                $root    = $builder->build($dom, $this->rootFontSize);

                new FlexLayout(deadline: $deadline)->layout($root, $contentWidth, $contentHeight);

                return [$builder, $root];
            };

            [$builder, $root] = $build();
            $root             = $this->resolveContainerQueries($resolver, $builder, $root, $build);

            $deadline->check('layout');

            $this->pagesAreRtl = $root->direction === 'rtl';

            $boxes = $pageBoxes;
            $rtl   = $this->pagesAreRtl;

            // Which pages a `page: <name>` box lands on is pagination's own
            // answer and a named page's box is a term in that answer, so the
            // fragmenter is asked rather than told: it knows the page type in
            // force before any content lands on the page, because a named run
            // is bracketed by forced breaks. One pass, and the painter reads
            // the same map back.
            $reserve = $boxes === []
                ? null
                : static fn(int $page, string $name): float
                    => $contentHeight - self::boxContentHeight(
                        self::boxForPage($boxes, $page + 1, $rtl, $name),
                    );

            $fragmenter = new Fragmenter(
                $contentHeight,
                limits     : $this->limits,
                deadline   : $deadline,
                pageReserve: $reserve,
            );

            $pages           = $fragmenter->fragment($root);
            $this->pageNames = $fragmenter->pageTypes();

            return [$root, $pages, $contentWidth];
        };

        [$root, $pages, $contentWidth] = $paginate($this->pageBoxes);

        // A page kind asking for a content box WIDER than the document declares
        // is laid out at its width and every narrower page shows those same
        // lines inside its own margins. That is exact while the ink fits, for
        // the reason {@see inkFitsNarrowPages} gives, and where it does not the
        // document is laid out again at the width it declares, with every kind
        // that was wider giving its sheet back. Nothing mixes: the second pass
        // throws the first tree away.
        // A document declaring no wider kind cannot reach this at all, because
        // its layout width is the widest box it has.
        if ($contentWidth > self::boxContentWidth($this->declaredBox()) + 0.001
            && !$this->inkFitsNarrowPages($pages, $contentWidth)) {
            [$root, $pages, $contentWidth] = $paginate($this->clampWideKinds($this->pageBoxes));
        }

        // A page whose own content box is narrower than the width the lines were
        // fitted to keeps its own inline margins only while its ink fits inside
        // them. Where it does not, every page falls back to the unqualified
        // page's inline geometry, which is what the whole of this did before:
        // the alternative is a line painted into a margin the document asked to
        // keep clear, and painting is the only thing this decides, because the
        // inline axis is not a term in the flow at all.
        // A kind that is given the document's width back cannot keep the height
        // it asked for either, because the two came from one `size` and half of
        // a sheet nobody declared is a worse answer than the whole sheet the
        // document did. A kind whose width was never its own, which is every
        // height-only block such as `UQ-page-first-size.html`, keeps its height.
        if (!$this->inkFitsNarrowPages($pages, $contentWidth)) {
            $declaredBox = $this->declaredBox();

            foreach ($this->pageBoxes as $kind => $box) {
                if (abs($box['width'] - $declaredBox['width']) > 0.001) {
                    $this->pageBoxes[$kind]['height'] = $declaredBox['height'];
                }

                $this->pageBoxes[$kind]['width'] = $declaredBox['width'];
                $this->pageBoxes[$kind]['left']  = $declaredBox['left'];
                $this->pageBoxes[$kind]['right'] = $declaredBox['right'];
            }
        }

        return [$root, $pages, $resolver];
    }

    /**
     * How many times a document that uses container queries may be laid out
     * before the answers are taken as they stand.
     *
     * CSS gets away with one pass because `container-type` implies size
     * containment, so a rule inside a query cannot change the size of the
     * container it was asked about. This engine does not model containment, so
     * a document that does change it is bounded here rather than left to spin.
     */
    private const int CONTAINER_QUERY_PASSES = 4;

    /**
     * Lay the document out again with the container sizes the first pass
     * produced, until they stop moving.
     *
     * A container query needs a used size and the cascade runs while the box
     * tree is being built, so the first pass is taken with every query false.
     * That is not an approximation to be apologized for: it is the answer CSS
     * gives a document that establishes no container, and the rules inside a
     * query style what is *inside* the container rather than the container
     * itself, so the sizes that pass measures are the ones the query is about.
     *
     * A document that declares no `container-type`, or writes no `@container`
     * block, never reaches the loop at all and is laid out exactly once.
     *
     * @param callable():array{0:HtmlBuilder,1:Node} $build
     */
    private function resolveContainerQueries(
        StyleResolver $resolver,
        HtmlBuilder $builder,
        Node $root,
        callable $build,
    ): Node {
        // A container query unit needs a used size exactly as a query does, so
        // a document that writes `50cqw` and no `@container` block at all still
        // takes the second pass. `usesContainerUnits` counts units the first
        // pass actually resolved rather than declarations found in a sheet,
        // which is what makes it see one written in a `style` attribute.
        if (!$resolver->hasContainerQueries && !$resolver->usesContainerUnits) {
            return $root;
        }

        $sizes = $builder->containerSizes();

        for ($pass = 1; $pass < self::CONTAINER_QUERY_PASSES && $sizes !== []; $pass++) {
            $resolver->setContainers($sizes);

            [$builder, $root] = $build();

            $next = $builder->containerSizes();

            if ($next === $sizes) {
                break;
            }

            $sizes = $next;
        }

        return $root;
    }

    public function save(string $path): int
    {
        [$bytes, $total] = $this->render();
        file_put_contents($path, $bytes);

        return $total;
    }

    /** The finished PDF as bytes, for streaming and downloads. */
    public function output(): string
    {
        return $this->render()[0];
    }

    /** @return array{0:string,1:int} bytes, page count */
    public function render(): array
    {
        // Both of these are the caller's own settings, so they can be refused
        // before a page is laid out rather than after one has been painted.
        if ($this->pdfa && $this->encryption !== null) {
            throw PdfaConformanceException::encrypted();
        }

        [$root, $pages, $resolver] = $this->layout();
        $total = count($pages);

        $structure = null;

        if ($this->tagged) {
            $structure       = new StructureTree();
            $structure->lang = $this->lang !== '' ? $this->lang : $this->documentLang;

            // Layout generates boxes of its own, so which element owns which
            // box can only be settled once there are no more of them.
            $structure->own($root);
        }

        // A level A or PDF/UA claim needs the document to say what language it
        // is in, and `<html lang>` is where most documents already say it, so
        // the check waits until the markup has been read.
        $claim = match (true) {
            $this->pdfua                             => 'PDF/UA-1',
            $this->pdfa && $this->conformance !== 'B' => 'PDF/A-' . Pdfa::PART . strtolower($this->conformance),
            default                                  => '',
        };

        if ($claim !== '' && ($structure === null || $structure->lang === '')) {
            throw PdfaConformanceException::unclaimable(
                $claim,
                $structure === null ? 'a structure tree' : 'a document language',
            );
        }

        $pdf = new Pdf(
            $this->pageWidth,
            $this->pageHeight,
            deadline   : $this->deadline,
            limits     : $this->limits,
            encryption : $this->encryption,
            structure  : $structure,
            pdfa       : $this->pdfa || $this->pdfua,
            conformance: $this->conformance,
            pdfua      : $this->pdfua,
        );
        $pdf->info($this->metadata);

        if ($this->openAction !== null) {
            $pdf->openAt($this->openAction['fit'], $this->openAction['page'], $this->openAction['args']);
        }

        if ($this->pageMode !== '') {
            $pdf->pageMode($this->pageMode);
        }

        foreach ($this->attachments as [$name, $bytes, $mime, $description, $relationship]) {
            $pdf->attach($name, $bytes, $mime, $description, $relationship);
        }
        $pdf->structureRoot($root);

        // Every paint site below reads the page geometry off these three, so a
        // page whose own `@page` block moved its margins or its sheet is painted
        // by putting that page's box in force for the length of its turn. The
        // document's own box goes back afterwards, because `render()` may be
        // called again and layout reads the same three fields.
        $declared = [$this->pageWidth, $this->pageHeight, $this->margins];

        try {
            foreach ($pages as $i => $page) {
                $this->deadline?->check('painting');
                $pdf->beginPage();
                $pdf->selectPage($i);

                if ($this->pageBoxes !== []) {
                    $box = self::boxForPage(
                        $this->pageBoxes,
                        $i + 1,
                        $this->pagesAreRtl,
                        $this->pageNames[$i] ?? '',
                    );

                    [$this->pageWidth, $this->pageHeight] = [$box['width'], $box['height']];

                    foreach (['top', 'right', 'bottom', 'left'] as $edge) {
                        $this->margins[$edge] = $box[$edge];
                    }

                    $pdf->pageSize($box['width'], $box['height']);
                }

                $marginBoxes = $this->marginBoxesForPage($resolver, $i + 1, $this->pageNames[$i] ?? '');

                // The canvas is the page's own paper and a running header repeats
                // furniture, so neither is part of what the document says. A
                // reader that read them would say the page number out loud in the
                // middle of a sentence.
                $this->asArtifact($structure, fn() => $this->paintCanvas($pdf, $root));

                $running = $this->runningFor($this->header, $i + 1, $total);

                if ($running !== null) {
                    $this->asArtifact(
                        $structure,
                        fn() => $this->paintRunning($pdf, $resolver, $running, $i + 1, $total, true),
                    );
                }

                // A `@page` margin box is the stylesheet's spelling of a running
                // header, so an explicit `->header()` wins over it exactly as an
                // explicit `->page()` wins over `@page { size }`. It is furniture
                // either way, so it is an artifact to a reader.
                if ($this->header === null) {
                    $this->asArtifact(
                        $structure,
                        fn() => $this->paintMarginBoxes($pdf, $marginBoxes, $i + 1, $total, 'top'),
                    );
                }

                // The two side columns have no API spelling to lose to.
                $this->asArtifact(
                    $structure,
                    fn() => $this->paintMarginBoxes($pdf, $marginBoxes, $i + 1, $total, 'sides'),
                );

                // A page's fragments are emitted in flow order and are already
                // independent of one another, each carrying its own clip and its
                // own chain of subtree effects, so sorting them here is the whole
                // of what CSS 2.1 Appendix E asks for. One flat list is also the
                // only place the engine can express it: a box's path says which
                // stacking contexts it is inside, so a raised child stays under
                // the sibling its parent lost to. Stable, so boxes asking for the
                // same place keep flow order.
                usort($page, static fn(Fragment $a, Fragment $b): int => BoxPainter::compareStack($a->node, $b->node));

                $this->paintIsolated($pdf, $page);

                $running = $this->runningFor($this->footer, $i + 1, $total);

                if ($running !== null) {
                    $this->asArtifact(
                        $structure,
                        fn() => $this->paintRunning($pdf, $resolver, $running, $i + 1, $total, false),
                    );
                }

                if ($this->footer === null) {
                    $this->asArtifact(
                        $structure,
                        fn() => $this->paintMarginBoxes($pdf, $marginBoxes, $i + 1, $total, 'bottom'),
                    );
                }

                $pdf->endPage();
            }
        } finally {
            [$this->pageWidth, $this->pageHeight, $this->margins] = $declared;
        }

        return [$pdf->output(), $total];
    }

    /** Run $paint with everything it draws marked as decoration, if tagging is on. */
    private function asArtifact(?StructureTree $structure, callable $paint): void
    {
        if ($structure === null) {
            $paint();

            return;
        }

        $structure->asArtifact($paint);
    }

    /**
     * The canvas background, on every page, behind everything else.
     *
     * CSS Backgrounds 3 section 2.11.2 hands the canvas the root element's
     * background, or the `<body>`'s where the root declares none, and the
     * painting area is the whole canvas rather than the box that declared it.
     * Chrome's printed canvas is the **page area**, so a `@page { margin }`
     * insets the fill: `PQ-root-paint-pagemargin.html` fills 30..449 by
     * 30..269 of a 480x300 sheet with a 30pt page margin, where
     * `PO-root-paint.html` at margin 0 fills all of it.
     */
    private function paintCanvas(Pdf $pdf, Node $root): void
    {
        if ($root->canvasBackground === null && $root->canvasBackgroundLayers === []) {
            return;
        }

        $canvas                   = new Node(['display' => 'rect']);
        $canvas->background       = $root->canvasBackground;
        $canvas->backgroundLayers = $root->canvasBackgroundLayers;

        BoxPainter::paint(
            $pdf,
            $canvas,
            $this->margins['left'],
            $this->margins['top'],
            $this->pageWidth - $this->margins['left'] - $this->margins['right'],
            $this->pageHeight - $this->margins['top'] - $this->margins['bottom'],
            [],
        );
    }

    /**
     * Running headers and footers live in the page margin, outside the flow,
     * and are rebuilt per page because {page} changes on each one.
     */
    private function paintRunning(
        Pdf $pdf,
        StyleResolver $resolver,
        string $html,
        int $pageNo,
        int $total,
        bool $isHeader,
    ): void {
        $html = str_replace(['{page}', '{pages}'], [(string) $pageNo, (string) $total], $html);

        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">' . TagRepair::apply($html), LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();

        $contentWidth = $this->pageWidth - $this->margins['left'] - $this->margins['right'];
        $box          = new HtmlBuilder($resolver)->build($dom, $this->rootFontSize);
        $box->width   = $contentWidth;
        new FlexLayout()->layout($box, $contentWidth, $this->margins[$isHeader ? 'top' : 'bottom']);

        // Headers hang above the content box; footers sit below it.
        $offsetY = $isHeader
            ? max(0.0, $this->margins['top'] - $box->layoutHeight - 8.0)
            : $this->pageHeight - $this->margins['bottom'] + 8.0;

        $this->paintOutsideFlow($pdf, $box, $this->margins['left'], $offsetY);
    }

    /**
     * A box that never reached the fragmenter, painted where it was told.
     *
     * Decoration goes through `BoxPainter` like every other path, so a header,
     * a footer and a `@page` margin box all render the same as the same markup
     * in the flow.
     */
    private function paintOutsideFlow(Pdf $pdf, Node $box, float $dx, float $dy): void
    {
        $paint = function (Node $n, float $dx, float $dy) use (&$paint, $pdf): void {
            BoxPainter::paint(
                $pdf,
                $n,
                $n->x + $dx,
                $n->y + $dy,
                $n->layoutWidth,
                $n->layoutHeight,
                $n->lineBoxes,
            );

            foreach ($n->children as $c) {
                $paint($c, $dx, $dy);
            }
        };

        $paint($box, $dx, $dy);
    }

    /**
     * The rectangle and the default alignment of every `@page` margin box.
     *
     * CSS Paged Media 3 section 5 divides the margin into four corner squares,
     * a row across the top and the bottom between them, and a column down each
     * side. Section 5.3.2 gives each box a default `text-align` and
     * `vertical-align`, and all of them are measured here rather than read off
     * the spec, on `RN-page-margin-boxes.html` and `RN-page-margin-edges.html`
     * at a 40pt margin on a 300pt page:
     *
     * - `@top-left` starts at the content's left edge (39.75), `@top-right`
     *   ends at its right edge (258.75 of 260) and `@top-center` is centred on
     *   the row (144.00..154.50 about 150), so a top or bottom box is laid out
     *   across the whole content width and aligned by its own name;
     * - a corner box aligns **towards the page**: `@top-left-corner` ends at
     *   38.25 of a 0..40 square and `@top-right-corner` starts at 260.25 of a
     *   260..300 one;
     * - a side box is centred across its column (`@left-middle` at
     *   15.00..24.00 of 0..40) and placed down the content's own height by its
     *   name, `@left-top` at 40.50 and `@left-middle` at 147.00 of a 40..260
     *   range whose centre is 150.
     *
     * A line is centred in its box unless the box's name says otherwise: a 9pt
     * line's ink in a 40pt band reads 16.50..22.50 in Chrome, which is a
     * `normal` line box of 10.48pt with (40 - 10.48) / 2 above it.
     *
     * @return array<string,array{0:float,1:float,2:float,3:float,4:string,5:string}>
     *         x, width, y, height, text-align, vertical placement
     */
    private function marginBoxRects(): array
    {
        ['top' => $top, 'right' => $right, 'bottom' => $bottom, 'left' => $left] = $this->margins;

        $innerWidth  = $this->pageWidth - $left - $right;
        $innerHeight = $this->pageHeight - $top - $bottom;
        $lastRow     = $this->pageHeight - $bottom;

        $rects = [
            'top-left-corner'     => [0.0, $left, 0.0, $top, 'right', 'middle'],
            'top-right-corner'    => [$this->pageWidth - $right, $right, 0.0, $top, 'left', 'middle'],
            'bottom-left-corner'  => [0.0, $left, $lastRow, $bottom, 'right', 'middle'],
            'bottom-right-corner' => [$this->pageWidth - $right, $right, $lastRow, $bottom, 'left', 'middle'],
        ];

        foreach (['top' => [0.0, $top], 'bottom' => [$lastRow, $bottom]] as $edge => [$y, $band]) {
            foreach (['left', 'center', 'right'] as $align) {
                $rects["{$edge}-{$align}"] = [$left, $innerWidth, $y, $band, $align, 'middle'];
            }
        }

        foreach (
            [
                'left'  => [0.0, $left],
                'right' => [$this->pageWidth - $right, $right],
            ] as $edge => [$x, $band]
        ) {
            foreach (['top', 'middle', 'bottom'] as $place) {
                $rects["{$edge}-{$place}"] = [$x, $band, $top, $innerHeight, 'center', $place];
            }
        }

        return $rects;
    }

    /**
     * The `@page` margin boxes of one side of the page, painted in place.
     *
     * `$side` is `top`, `bottom` or `sides`: the top row and the bottom row
     * are what a running header and footer duplicate, so each is skipped when
     * the API set one, and the two side columns have no API spelling at all.
     *
     * **A margin box takes nothing from the document.** Its containing block
     * is the page rather than a box in the tree, so it is built against a
     * resolver carrying no author stylesheet at all: an undeclared font is the
     * initial one, which is Times 16px in Chrome and Helvetica 12px here, and
     * that is why the probes declare a family on every box. Building it
     * against the document's own resolver instead let `body { line-height:
     * 20px }` reach it, which made the box 15.25pt tall where Chrome's is 8.25
     * and put `@left-top` 3.75pt down its column.
     */
    /**
     * @param array<string,array<string,array{value:string,important:bool}>> $margins
     *        the boxes in force on this page, {@see marginBoxesForPage}
     */
    private function paintMarginBoxes(
        Pdf $pdf,
        array $margins,
        int $pageNo,
        int $total,
        string $side,
    ): void {
        if ($margins === []) {
            return;
        }

        $styles = $this->marginBoxStyles ??= new StyleResolver($this->limits, $this->remoteImages);
        $styles->viewport($this->pageWidth, $this->pageHeight);

        foreach ($this->marginBoxRects() as $name => [$x, $width, $y, $height, $align, $place]) {
            if (self::marginBoxSide($name) !== $side) {
                continue;
            }

            if ($width <= 0.0 || $height <= 0.0) {
                continue;
            }

            $declarations = $margins[$name] ?? [];
            $content      = self::marginBoxContent($declarations, $pageNo, $total);

            if ($content === null) {
                continue;
            }

            // The box's own declarations come after the default alignment, so
            // a `text-align` it sets for itself wins the way a later
            // declaration does.
            $style = sprintf('text-align:%s;', $align);

            foreach ($declarations as $property => $entry) {
                if ($property === 'content') {
                    continue;
                }

                $style .= sprintf('%s:%s;', $property, is_array($entry) ? ($entry['value'] ?? '') : $entry);
            }

            $dom = new DOMDocument();
            libxml_use_internal_errors(true);
            $dom->loadHTML(
                sprintf(
                    // The synthesized document's own `<body>` margin would be
                    // part of the box's height, which is what places it down
                    // its band: 8px of it puts `@left-top` 6pt below the
                    // content's top edge where Chrome puts it on the edge.
                    '<?xml encoding="UTF-8"><body style="margin:0"><div style="%s">%s</div></body>',
                    $style,
                    htmlspecialchars($content, ENT_QUOTES | ENT_HTML5),
                ),
                LIBXML_NOERROR | LIBXML_NOWARNING,
            );
            libxml_clear_errors();

            $box        = new HtmlBuilder($styles)->build($dom, $this->rootFontSize);
            $box->width = $width;
            new FlexLayout()->layout($box, $width, $height);

            $slack = max(0.0, $height - $box->layoutHeight);

            $this->paintOutsideFlow($pdf, $box, $x, $y + match ($place) {
                'top'    => 0.0,
                'bottom' => $slack,
                default  => $slack / 2.0,
            });
        }
    }

    /** Which of the three groups a margin box belongs to. */
    private static function marginBoxSide(string $name): string
    {
        return match (true) {
            str_starts_with($name, 'top-')    => 'top',
            str_starts_with($name, 'bottom-') => 'bottom',
            default                           => 'sides',
        };
    }

    /**
     * What a margin box's `content` says on this page, or null where it says
     * nothing.
     *
     * A `content` value is a list of quoted strings and counters, joined. The
     * two counters a page needs are `page` and `pages`, which are the same two
     * the `->header()` string spells `{page}` and `{pages}`; anything else is
     * dropped rather than printed as its own name.
     *
     * @param array<string,array{value:string,important:bool}|string> $declarations
     */
    private static function marginBoxContent(array $declarations, int $pageNo, int $total): ?string
    {
        $entry = $declarations['content'] ?? null;

        if ($entry === null) {
            return null;
        }

        $value = trim(is_array($entry) ? (string) ($entry['value'] ?? '') : (string) $entry);

        if ($value === '' || strtolower($value) === 'none' || strtolower($value) === 'normal') {
            return null;
        }

        $out = '';

        preg_match_all(
            '/"((?:[^"\\\\]|\\\\.)*)"|\'((?:[^\'\\\\]|\\\\.)*)\'|counter\(\s*([\w-]+)[^)]*\)/i',
            $value,
            $matches,
            PREG_SET_ORDER,
        );

        foreach ($matches as $match) {
            if (($match[3] ?? '') !== '') {
                $out .= match (strtolower($match[3])) {
                    'page'  => (string) $pageNo,
                    'pages' => (string) $total,
                    default => '',
                };

                continue;
            }

            $out .= stripcslashes($match[1] !== '' ? $match[1] : ($match[2] ?? ''));
        }

        return $out === '' ? null : $out;
    }

    /**
     * Paint a page's fragments, inside the page's own isolated group where
     * anything on the page blends.
     *
     * CSS Compositing 1 section 3.1 makes the root element an isolated group,
     * so an element's blending backdrop is the document's own content and stops
     * short of the canvas. The canvas is the paper the page is printed on and
     * `paintCanvas()` has already put it down, outside this group, along with
     * the running headers and the `@page` margin boxes, which are furniture
     * rather than document content and belong under it in the same way.
     *
     * **A page that blends nothing is not isolated**, because the two answers
     * are the same picture there and the group is not free: it is a form
     * XObject per page, and pdfium fits glyphs inside one differently from the
     * way it fits them on a page, which round 29 measured at 0.09 of a pixel.
     *
     * @param Fragment[] $fragments
     */
    private function paintIsolated(Pdf $pdf, array $fragments): void
    {
        if (!self::blends($fragments)) {
            $this->paintFragments($pdf, $fragments, 0);

            return;
        }

        $pdf->beginGroup();
        $this->paintFragments($pdf, $fragments, 0);

        ['name' => $name] = $pdf->closeGroup(
            [0.0, 0.0, $this->pageWidth, $this->pageHeight],
            isolate: true,
        );

        if ($name !== null) {
            $pdf->drawGroup($name);
        }
    }

    /**
     * Whether anything on this page asks to be blended with what is under it.
     *
     * A fragment carries its ancestors' effects, so a blended box whose own
     * piece painted nothing is still found through the pieces of its children.
     *
     * @param Fragment[] $fragments
     */
    private static function blends(array $fragments): bool
    {
        foreach ($fragments as $fragment) {
            if (Pdf::blendable($fragment->node->blendMode)) {
                return true;
            }

            foreach ($fragment->effects as $effect) {
                if (Pdf::blendable($effect[0]->blendMode)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Paint a page's fragments, compositing each subtree that asks to be
     * composited as one into a transparency group of its own.
     *
     * A box with an `opacity`, a `mix-blend-mode` or a `mask-image` makes a
     * stacking context, and {@see BoxPainter::compareStack()} compares paths
     * term by term, so every fragment of such a subtree sorts into **one
     * unbroken run** of this list: the box's own piece first, because its path
     * is a prefix of its descendants'. That run is what goes into the group,
     * and the effects are pushed around the group's own drawing rather than
     * around each fragment in it.
     *
     * The recursion is the nesting: a faded panel inside a faded page is two
     * groups, and each composites once against what it sits on.
     *
     * @param Fragment[] $fragments sharing the first $depth groups
     */
    private function paintFragments(Pdf $pdf, array $fragments, int $depth): void
    {
        $count = count($fragments);
        $at    = 0;

        while ($at < $count) {
            $plan = self::groupPlan($fragments[$at]);

            if (count($plan['groups']) <= $depth) {
                $this->paintFragment($pdf, $fragments[$at], $plan['tail']);
                $at++;

                continue;
            }

            ['root' => $root, 'above' => $above, 'band' => $banded, 'index' => $index] = $plan['groups'][$depth];

            $end = $at + 1;

            while ($end < $count) {
                $next = self::groupPlan($fragments[$end])['groups'];

                if (count($next) <= $depth || $next[$depth]['root'][0] !== $root[0]) {
                    break;
                }

                $end++;
            }

            $run   = array_slice($fragments, $at, $end - $at);
            $piece = $fragments[$at];
            $at    = $end;

            $pdf->beginGroup();
            $this->paintFragments($pdf, $run, $depth + 1);

            [, $gx, $gy, $gw, $gh] = $root;

            ['name' => $name, 'inline' => $inline] = $pdf->closeGroup([
                $gx + $this->margins['left'],
                $gy + $this->margins['top'],
                $gw,
                $gh,
            ], null, $root[0]->opacity);

            if ($name !== null || $inline !== '') {
                // Whatever sits between this group and the one outside it goes
                // on before the group's own compositing does, because that is
                // where the walk met it: a mask under a rotated ancestor is
                // drawn through the ancestor's matrix, and a clip declared
                // between two matrices cuts in the space the first one made.
                //
                // The whole run shares the chain down to the root, so any
                // piece of it answers for the clips above the root.
                $pushed = $this->pushChain($pdf, $piece, $index - count($above), $above);

                // The clips the walk recorded just before this root, which cut
                // in the space outside its own matrix. `$index` is the same
                // number for every piece in the run: a descendant carries the
                // root on its chain at that index, and the root's own piece
                // carries the chain above it and nothing else, so its own
                // effect count is that index too.
                $pushed += $this->pushClipsAt($pdf, $piece, $index);

                $pushed += BoxPainter::pushGroupEffects(
                    $pdf,
                    $root[0],
                    $gx + $this->margins['left'],
                    $gy + $this->margins['top'],
                    $gw,
                    $gh,
                    $banded ? $this->pageBand() : null,
                );

                $name === null ? $pdf->raw($inline) : $pdf->drawGroup($name);

                BoxPainter::popEffects($pdf, $pushed);
            }
        }
    }

    /**
     * Push the clips this piece recorded at one depth in its own effect chain,
     * and say how many graphics states that took.
     *
     * A clip's depth is how many of the piece's ancestor effects the walk had
     * already met when it declared the clip, so it names the one place in the
     * chain of matrices where the clip belongs: after every matrix pushed
     * before it, and before every matrix pushed after it. Flattening the whole
     * stack into one page rectangle and pushing it outermost is right only
     * while nothing above the box carries a matrix at all.
     *
     * Several clips can share a depth, and those intersect: they are all in
     * the same coordinate space, which is the whole point of the depth.
     */
    private function pushClipsAt(Pdf $pdf, Fragment $f, int $depth): int
    {
        $clip = Fragment::intersectClips(array_values(array_filter(
            $f->clipStack,
            static fn(array $entry): bool => $entry[5] === $depth,
        )));

        if ($clip === null) {
            return 0;
        }

        [$cx, $cy, $cw, $ch, $radii] = $clip;
        $pdf->pushClip($cx + $this->margins['left'], $cy + $this->margins['top'], $cw, $ch, $radii);

        return 1;
    }

    /**
     * Which subtree effects on a fragment are composited as groups, in the
     * order the walk met them, and which are left for the fragment itself.
     *
     * A box's own effects come last, because its group holds its own drawing
     * as well as its descendants'. `band` says the rect came off the chain
     * rather than off this piece, which is the one that needs cutting back to
     * the page: an ancestor's rect is the whole box, and a fragment's is
     * already the slice this page holds.
     *
     * `index` is where the root sits in the piece's own chain, which is what a
     * clip's depth is compared against.
     *
     * @return array{
     *     groups: list<array{root:array{0:Node,1:float,2:float,3:float,4:float},above:list<array{0:Node,1:float,2:float,3:float,4:float}>,band:bool,index:int}>,
     *     tail: list<array{0:Node,1:float,2:float,3:float,4:float}>
     * }
     */
    private static function groupPlan(Fragment $f): array
    {
        $groups = [];
        $above  = [];

        foreach ($f->effects as $i => $effect) {
            if (!BoxPainter::makesGroup($effect[0])) {
                $above[] = $effect;

                continue;
            }

            $groups[] = ['root' => $effect, 'above' => $above, 'band' => true, 'index' => $i];
            $above    = [];
        }

        if (BoxPainter::makesGroup($f->node)) {
            $groups[] = [
                'root'  => [$f->node, $f->x, $f->y, $f->w, $f->h],
                'above' => $above,
                'band'  => false,
                'index' => count($f->effects),
            ];

            $above = [];
        }

        return ['groups' => $groups, 'tail' => $above];
    }

    /**
     * Push a run of ancestor effects, outermost first, with the clips the walk
     * recorded between them, and say how many graphics states that took.
     *
     * @param int $from the index this run starts at in the piece's own chain,
     *        which is what a clip's depth is compared against
     * @param list<array{0:Node,1:float,2:float,3:float,4:float}> $chain
     */
    private function pushChain(Pdf $pdf, Fragment $f, int $from, array $chain): int
    {
        $pushed = 0;

        foreach ($chain as $i => [$ancestor, $ax, $ay, $aw, $ah]) {
            $pushed += $this->pushClipsAt($pdf, $f, $from + $i);
            $pushed += BoxPainter::pushGroupEffects(
                $pdf,
                $ancestor,
                $ax + $this->margins['left'],
                $ay + $this->margins['top'],
                $aw,
                $ah,
                $this->pageBand(),
            );
        }

        return $pushed;
    }

    /**
     * The page's own top and bottom.
     *
     * An ancestor's rect is the whole box, moved up by a page height for every
     * fold it has crossed, so on a continuation it starts above the paper.
     * That is right for a transform, whose origin is the box's, and wrong for
     * a mask, which Chrome restarts on each fragment: the band is what cuts
     * one back to the slice this page holds.
     *
     * @return array{0:float,1:float}
     */
    /**
     * Whether this piece lies wholly outside the sheet, so nothing it holds
     * can reach the paper.
     *
     * Chrome drops such a box from the content stream AND from the structure
     * tree: `YU-offsheet-tagged.html` puts one paragraph at `left: 900pt`
     * between two ordinary ones, and Chrome's tagged PDF carries **two**
     * elements where this engine carried three, so a screen reader was read a
     * paragraph no sighted reader could see.
     *
     * **The test is the BOX and never a text run's origin.** Culling by where a
     * show operator starts deletes ink: `YS-run-starts-off-sheet.html` is one
     * `white-space: nowrap` line at `left: -200pt`, whose run begins 200pt off
     * the left edge and paints onto the page, and removing it costs 802 pixels.
     * Four of eight corpus documents lose ink the same way.
     *
     * **A TRANSFORM IS ASKED WHERE IT PUTS THE BOX, NOT WHETHER IT EXISTS.**
     * Round 86 kept every piece owing an effect, because a transform can carry
     * a box the layout put outside the sheet back ONTO it and the matrix that
     * would decide it had not been pushed at this point. The matrix was
     * knowable all along: a fragment's effects carry the ancestor node and the
     * box it was pushed at, so {@see matrixOver()} composes the same matrix
     * {@see Pdf::pushTransform()} paints through, and the rectangle is
     * compared where it actually lands. Defect IT, and
     * `YY-offsheet-transform-tagged.html` is the probe: Chrome exports two
     * paragraphs and 65 glyphs where this engine exported three and 122.
     */
    private function fallsOffTheSheet(Fragment $f, float $x, float $y): bool
    {
        $n = $f->node;

        // A shadow, an outline or an outline offset all paint outside the box
        // they belong to, by a distance this does not try to compute. A piece
        // carrying one keeps its place.
        if ($n->boxShadow !== [] || $n->textShadow !== [] || $n->outline !== null) {
            return false;
        }

        // **THE BOX DOES NOT BOUND WHAT THE PIECE PAINTS.** Its lines start at
        // the box origin and may be wider and taller than the box is:
        // `WQ-break-after-overflow.html` holds a 12pt box carrying an 18pt
        // line, so its ink reaches 6pt onto a sheet its box has already left.
        // Culling on the box alone cost visible ink on 15 of 24 probe pages.
        //
        // **AND A LINE'S OWN WIDTH DOES NOT BOUND ITS ITEMS EITHER.** A piece
        // is placed at its own x inside the line and can sit well past the
        // width the line recorded: over 600 census documents 509 of the 3,159
        // pieces culled here carry items outside it, and `cdoc-1-350` holds a
        // 318pt line box whose items run 1,226pt from its origin and paint
        // onto the sheet. Round 86 shipped clean on the narrower bound because
        // no piece in that corpus was both outside it and on the paper, and
        // taking the effects exclusion away made the pair meet.
        $from   = 0.0;
        $width  = $f->w;
        $height = $f->h;
        $reach  = 0.0;

        foreach ($f->lines as $line) {
            $width   = max($width, $line->width);
            $height += $line->height;

            foreach ($line->items as $item) {
                $from  = min($from, $item->x);
                $width = max($width, $item->x + $item->width);
                $reach = max($reach, $item->run->fontSize);
            }
        }

        // **AND A LINE'S HEIGHT DOES NOT BOUND ITS GLYPHS.** A glyph sits on a
        // baseline and reaches above and below it, so a line whose recorded
        // height is zero still paints: `cdoc-1-360` holds nine of them inside
        // a box 0pt tall, 0.75pt above the top edge once its own matrix has
        // moved it, and culling on the boxes alone took 143 pixels off the
        // paper. One em on every side is the allowance, which is generous on
        // purpose: this bound is only ever asked whether ink CANNOT reach the
        // paper, so erring wide keeps ink and erring narrow deletes it.
        [$left, $top, $right, $bottom] = $this->paintedBounds(
            $f,
            $x + $from - $reach,
            $y - $reach,
            $x + $width + $reach,
            $y + $height + $reach,
        );

        return $right <= 0.0
            || $bottom <= 0.0
            || $left >= $this->pageWidth
            || $top >= $this->pageHeight;
    }

    /**
     * The smallest upright rectangle holding a piece's own rectangle once
     * every matrix above it has turned it.
     *
     * The four corners are mapped rather than the two the caller passed,
     * because a rotation puts the corners of the answer where the corners of
     * the question are not.
     *
     * @return array{0:float,1:float,2:float,3:float} left, top, right, bottom
     */
    private function paintedBounds(Fragment $f, float $left, float $top, float $right, float $bottom): array
    {
        $m = $this->matrixOver($f);

        if ($m === null) {
            return [$left, $top, $right, $bottom];
        }

        $xs = [];
        $ys = [];

        foreach ([[$left, $top], [$right, $top], [$left, $bottom], [$right, $bottom]] as [$cx, $cy]) {
            $xs[] = $cx * $m[0] + $cy * $m[2] + $m[4];
            $ys[] = $cx * $m[1] + $cy * $m[3] + $m[5];
        }

        return [min($xs), min($ys), max($xs), max($ys)];
    }

    /**
     * The one matrix every transform above this piece, and the piece's own,
     * comes to. Null where nothing turns it at all, which is the ordinary
     * case and pays no arithmetic.
     *
     * A `cm` is met by a point in the opposite order to the one it was issued
     * in, so the piece's own matrix goes on first and the outermost ancestor's
     * last. Only a transform is here: an opacity, a blend mode and a mask
     * composite what a piece paints and move none of it, and a clip only ever
     * takes ink away.
     *
     * @return array{0:float,1:float,2:float,3:float,4:float,5:float}|null
     */
    private function matrixOver(Fragment $f): ?array
    {
        $m     = null;
        $boxes = [[$f->node, $f->x, $f->y, $f->w, $f->h], ...array_reverse($f->effects)];

        foreach ($boxes as [$node, $bx, $by, $bw, $bh]) {
            if ($node->transform === []) {
                continue;
            }

            [$ox, $oy] = Pdf::originPoint($node->transformOrigin, $bw, $bh);

            $own = Pdf::turnAbout(
                Pdf::transformMatrix($node->transform),
                $bx + $this->margins['left'] + $ox,
                $by + $this->margins['top'] + $oy,
            );

            $m = $m === null ? $own : Pdf::composeMatrix($m, $own);
        }

        return $m;
    }

    private function pageBand(): array
    {
        return [$this->margins['top'], $this->pageHeight - $this->margins['bottom']];
    }

    /**
     * @param list<array{0:Node,1:float,2:float,3:float,4:float}> $chain the
     *        ancestor effects this piece still owes, which are the ones no
     *        group around it has already pushed
     */
    private function paintFragment(Pdf $pdf, Fragment $f, array $chain = []): void
    {
        $n = $f->node;
        $x = $f->x + $this->margins['left'];
        $y = $f->y + $this->margins['top'];

        if ($this->fallsOffTheSheet($f, $x, $y)) {
            return;
        }

        // Fragments are painted independently, so everything an ancestor wraps
        // this piece in travels on the fragment and is pushed here rather than
        // being live on the graphics stack from the walk that emitted it.
        //
        // The chain is what no group around this piece has already pushed: an
        // ancestor that composites its subtree as one is on the graphics state
        // already, wrapped around the whole group rather than around this
        // piece of it. Whatever that group pushed, it pushed the clips down to
        // its own root with it, so the ones this piece still owes are exactly
        // the ones recorded from the chain's first index onwards.
        $from      = count($f->effects) - count($chain);
        $inherited = $this->pushChain($pdf, $f, $from, $chain);

        // Everything the walk recorded after the last of this piece's ancestor
        // effects, which is where a clip that no matrix stands between lands.
        // A box that composites its own subtree has a group of its own at that
        // same index, and that group has already pushed them.
        if (!BoxPainter::makesGroup($n)) {
            $inherited += $this->pushClipsAt($pdf, $f, count($f->effects));
        }

        // `box-decoration-break` defaults to `slice`: a fold is not an edge of
        // the box, so a continuation draws no top border and a piece that
        // carries on draws no bottom one. `clone` draws all four on every
        // page, which boxes the content in at every fold and gives every
        // fragment all four of its corners.
        $clone = $n->decorationBreak === 'clone';
        $edges = ['right', 'left'];

        if ($clone || !$f->isContinuation) {
            $edges[] = 'top';
        }

        if ($clone || !$f->splitsAfter) {
            $edges[] = 'bottom';
        }

        $pushed = BoxPainter::pushEffects($pdf, $n, $x, $y, $f->w, $f->h, null, BoxPainter::makesGroup($n));

        BoxPainter::paint(
            $pdf,
            $n,
            $x,
            $y,
            $f->w,
            $f->h,
            $f->lines,
            !$f->linesOnly,
            $edges,
            // A `clone` fragment's background is its own rather than a band of
            // the whole box's, which is the other half of what the value
            // means: a gradient restarts on every page instead of carrying on
            // across the fold.
            $clone ? null : $f->slicedBackground,
        );
        BoxPainter::popEffects($pdf, $pushed);

        BoxPainter::popEffects($pdf, $inherited);
    }

    /** @return string[] contents of every <style> element */
    private function extractStyles(DOMDocument $dom): array
    {
        $out = [];

        /*
         * Document order matters: a <link> before a <style> loses to it, and
         * the cascade only sees the order these are added in. So walk the whole
         * tree once rather than each tag name in turn.
         */
        foreach ($this->styleElements($dom) as $element) {
            if (strtolower($element->nodeName) === 'style') {
                $out[] = $this->expandImports($element->textContent);

                continue;
            }

            $sheet = $this->fetchStylesheet($element->getAttribute('href'));

            if ($sheet !== null) {
                $out[] = $this->expandImports($sheet);
            }
        }

        return $out;
    }

    /**
     * Splice `@import`ed sheets into the text that imported them.
     *
     * The import goes through the same sandbox `<link rel="stylesheet">` uses,
     * because it is the same threat: an href an author controls and an
     * operator does not. Substituting the text in place is also what puts the
     * imported rules where the cascade expects them, ahead of everything the
     * importing sheet declares after the `@import`.
     *
     * A media condition on the import is re-emitted as an `@media` block
     * around the imported text rather than evaluated here, so `print` and
     * `screen` keep meaning exactly what they mean everywhere else.
     *
     * @param array<string,true> $seen hrefs already pulled in, so a sheet that
     *                                 imports itself terminates
     */
    private function expandImports(string $css, array $seen = [], int $depth = 0): string
    {
        if ($depth >= 8 || stripos($css, '@import') === false) {
            return $css;
        }

        $pattern = '/@import\s+(?:url\(\s*([\'"]?)([^)\'"]*)\1\s*\)|([\'"])([^\'"]*)\3)([^;]*);/i';

        return preg_replace_callback(
            $pattern,
            function (array $m) use ($seen, $depth): string {
                $href      = trim($m[2] !== '' ? $m[2] : ($m[4] ?? ''));
                $condition = trim($m[5] ?? '');

                if ($href === '' || isset($seen[$href])) {
                    return '';
                }

                $sheet = $this->fetchStylesheet($href);

                if ($sheet === null) {
                    return '';
                }

                $seen[$href] = true;
                $sheet       = $this->expandImports($sheet, $seen, $depth + 1);

                return $condition === '' ? $sheet : sprintf('@media %s {%s}', $condition, $sheet);
            },
            $css,
        ) ?? $css;
    }

    /** @return DOMElement[] every <style> and stylesheet <link>, in document order */
    private function styleElements(DOMDocument $dom): array
    {
        $out   = [];
        $xpath = new DOMXPath($dom);
        $nodes = $xpath->query('//style | //link');

        if ($nodes === false) {
            return $out;
        }

        foreach ($nodes as $node) {
            if (!$node instanceof DOMElement) {
                continue;
            }

            if (strtolower($node->nodeName) === 'style') {
                $out[] = $node;

                continue;
            }

            $rel = strtolower(trim($node->getAttribute('rel')));

            if ($rel === 'stylesheet' && $node->getAttribute('href') !== '') {
                $out[] = $node;
            }
        }

        return $out;
    }

    /**
     * Read a linked stylesheet. Only `data:` URIs and files under the
     * configured base path are read, which is `AssetPath`'s rule and the same
     * one `<img src>` and `@font-face src` go through. Remote sheets stay
     * unsupported on purpose.
     */
    private function fetchStylesheet(string $href): ?string
    {
        $href = trim($href);

        if ($href === '') {
            return null;
        }

        if (preg_match('/^data:([^,]*),(.*)$/is', $href, $m)) {
            return str_contains(strtolower($m[1]), ';base64')
                ? (base64_decode($m[2], true) ?: null)
                : rawurldecode($m[2]);
        }

        $path = AssetPath::resolve($href, $this->basePath);

        return $path === null ? null : (file_get_contents($path) ?: null);
    }
}
