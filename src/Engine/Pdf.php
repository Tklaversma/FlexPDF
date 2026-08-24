<?php

declare(strict_types=1);

namespace FlexPDF\Engine;

use FlexPDF\Engine\Exceptions\GradientLimitExceededException;
use FlexPDF\Engine\Exceptions\PdfaConformanceException;
use FlexPDF\Engine\Support\Deadline;
use FlexPDF\Engine\Support\Encryption;
use FlexPDF\Engine\Support\Limits;
use FlexPDF\Engine\Support\Pdfa;
use InvalidArgumentException;

/**
 * Multipage PDF writer that embeds subset TrueType fonts.
 *
 * Base-14 faces are written as simple Type1 fonts with WinAnsiEncoding.
 * TrueType faces are written as Type0/CIDFontType2 with Identity-H, a
 * subset FontFile2, and a ToUnicode CMap so the text stays extractable.
 */
final class Pdf
{
    /**
     * How many circles one dotted edge may draw before it is spelled as a
     * dash array instead. A border width is author-controlled and can be
     * arbitrarily small, so the dot count is bounded rather than trusted.
     */
    private const int MAX_DOTS = 2048;

    /**
     * How many half-waves a `text-decoration-style: wavy` line may draw. The
     * step is a function of the thickness and the thickness is
     * author-controlled, so a hairline under a box as wide as `max_length`
     * allows asks for a curve per few points. Past this the line stops and
     * what is drawn is the part of the wave that fits.
     */
    private const int MAX_WAVE_SEGMENTS = 8192;

    /**
     * The shear a synthesized oblique is drawn with, as the text matrix's own
     * `c` term.
     *
     * Read off Chrome on `RW-font-synth.html`: the same stem sits at 5.81,
     * 3.31 and 70.75 CSS pixels on raster rows 20, 30 and 36 where the upright
     * one sits at 2.56, 2.56 and 71.50, which is a shift of +3.25, +0.75 and
     * -0.75 and a slope of exactly **one quarter** through the baseline.
     */
    private const float OBLIQUE_SKEW = 0.25;

    private array  $streams = [];
    private string $content = '';

    /** @var array<string, Font|TrueTypeFont> resource name => face */
    private array $used = [];

    /** @var array<string, PdfImage> resource name => image */
    private array $images = [];

    /** @var array<string, array{alpha:float,stroke:float,blend:string}> resource name => state */
    private array $alphas = [];

    /** How many times a constant-alpha state has been named for the stream. */
    private int $alphaWrites = 0;

    /** How many times a blend state has been named for the stream, which is a separate dictionary. */
    private int $blendWrites = 0;

    /** @var array<string, array{0:float,1:float,2:float,3:float,4:array}> name => axis + stops */
    private array $shadings = [];

    /** Gradient color stops kept so far, against `max_gradient_stops`. */
    private int $gradientStops = 0;

    /** @var array<string, string> resource name => PDF blend mode */
    private array $blends = [];

    /**
     * Luminosity soft masks, each the content stream of the form XObject that
     * paints one and the box it covers.
     *
     * @var array<string, array{key:string, bbox:array{0:float,1:float,2:float,3:float}, content:string}>
     */
    private array $softMasks = [];

    /**
     * Transparency groups, each the content stream of a form XObject that
     * holds a whole subtree's drawing and the box it is clipped to.
     *
     * CSS composites an `opacity`, a `mix-blend-mode` or a `mask-image`
     * **once for the element and everything under it**. Setting a constant
     * alpha and drawing the subtree with it composites each drawing on its own
     * instead, so two boxes of the faded subtree show through one another: a
     * border shows through the background it stands on, which is every
     * bordered box there is. The group is the shape PDF has for the other
     * answer, and `mark` is the parent-tree key its own marked content took.
     *
     * @var array<string, array{bbox:array{0:float,1:float,2:float,3:float}, content:string, mark:int}>
     */
    private array $groups = [];

    /**
     * Content diverted into a group, innermost last: the outer stream, the
     * parent-tree key marks were being counted against, and the sequences
     * still open around it.
     *
     * @var list<array{content:string, stream:int, marked:list<array{at:int,after:int,mcid:?int,node:?Node}>, alphas:int}>
     */
    private array $groupStack = [];

    /** Parent-tree keys handed to groups, which are negative so a page's own key cannot collide with one. */
    private int $groupSerial = 0;

    /**
     * Which content stream {@see openContent()} is counting marked-content ids
     * against: the page while the page is being painted, and a key of its own
     * inside a group, because ids number from zero per stream.
     */
    private int $markStream = 0;

    /*
     * Character and word spacing currently in effect. They are text state, so
     * they carry across text objects and have to be tracked rather than set
     * and forgotten. Each page starts a fresh content stream at the defaults.
     */
    private float $charSpacing = 0.0;

    private float $wordSpacing = 0.0;

    /** @var array<string,string> /Info entries, unescaped */
    private array $metadata = [];

    /**
     * Link rectangles per page, in CSS coordinates. A destination beginning
     * with `#` is an internal jump, resolved against $anchors at write time.
     *
     * @var array<int,array<int,array{x:float,y:float,w:float,h:float,to:string}>>
     */
    private array $links = [];

    /** @var array<string,array{page:int,y:float}> anchor name => where it sits */
    private array $anchors = [];

    /**
     * The interactive widgets this document carries, keyed by the control box
     * and the page it landed on.
     *
     * A field's ink is kept here rather than written into the page's content
     * stream. A reader draws a widget's appearance over the page, so leaving
     * the control's own drawing behind would put the template's value under
     * whatever someone fills in: two answers on one box, the old one showing
     * wherever the new one is shorter.
     *
     * @var array<string,array{field:FormField,page:int,rect:array{0:float,1:float,2:float,3:float},da:string,off:string,on:string,value:string}>
     */
    private array $widgets = [];

    /**
     * Where an appearance stream's coordinates come from while one is being
     * captured, and null while the page is being painted.
     *
     * `top` is the control's own y on the page, because the capture draws the
     * box at its own origin rather than where it sits; `base` is the bottom
     * edge the `/Rect` was written with. {@see self::ty()} turns the two into
     * the page's answer and then takes the origin off, which is the whole of
     * the coordinate change and what lets the same `BoxPainter` code draw a
     * control onto the page and into an appearance stream identically.
     *
     * @var array{top:float,base:float}|null
     */
    private ?array $capture = null;

    /** @var array<string,int> widget key => the object number it was written as */
    private array $widgetObjects = [];

    /** @var array<string,int> widget key => its `/StructParent` in the parent tree */
    private array $widgetStructKeys = [];

    /**
     * The link annotations each `<a href>` produced, so the `Link` element
     * built for its text can point back at them.
     *
     * A link that wraps gets one rectangle per line, so one anchor on one page
     * can own several annotations and the `Link` element holds an `/OBJR` for
     * each. Keyed by the anchor's own run and the page, which is what
     * {@see paintLines()} splits the marked content on.
     *
     * @var array<string,list<int>> anchor key => the annotation objects
     */
    private array $linkObjects = [];

    /** @var array<string,list<int>> anchor key => their `/StructParent` keys */
    private array $linkStructKeys = [];

    /** How many parent-tree keys the link annotations took between them. */
    private function linkStructKeyCount(): int
    {
        return array_sum(array_map('count', $this->linkStructKeys));
    }

    /** @var array<int,array{title:string,level:int,page:int,y:float}> */
    private array $outline = [];

    /** Which page subsequent link and anchor registrations belong to. */
    private int $currentPage = 0;

    /**
     * How a reader should open the document, or null to leave it alone.
     *
     * `/OpenAction` is written as a DESTINATION ARRAY rather than as a `GoTo`
     * action dictionary. Both are legal and a reader treats them the same, and
     * the array is the one an archival profile has nothing to say about: what
     * ISO 19005 restricts is the kinds of ACTION a catalog may carry, and this
     * carries none.
     *
     * @var array{fit:string,page:int,args:list<float>}|null
     */
    private ?array $openAction = null;

    /** The panel a reader shows beside the page, `/PageMode`. Empty writes none. */
    private string $pageMode = '';

    /**
     * Files traveling inside the document.
     *
     * @var list<array{name:string,bytes:string,mime:string,description:string,relationship:string}>
     */
    private array $attachments = [];

    /** The eight destination types of PDF 32000-1 table 151, and what each needs. */
    private const array FIT_ARGUMENTS = [
        'XYZ'   => 3,
        'Fit'   => 0,
        'FitH'  => 1,
        'FitV'  => 1,
        'FitR'  => 4,
        'FitB'  => 0,
        'FitBH' => 1,
        'FitBV' => 1,
    ];

    /** The page modes of PDF 32000-1 table 28. */
    private const array PAGE_MODES = [
        'UseNone', 'UseOutlines', 'UseThumbs', 'FullScreen', 'UseOC', 'UseAttachments',
    ];

    /**
     * Where and how the document opens.
     *
     * `$fit` is matched without regard to case, so a caller can pass a
     * lowercase `fitH` unchanged. A type
     * that needs coordinates gets them from `$args`, in the order PDF 32000-1
     * table 151 lists them, and a missing one is written as `null`, which the
     * spec defines as "leave this one as it is".
     *
     * @param list<float> $args
     *
     * @throws InvalidArgumentException on an unknown destination type or a page below one
     */
    public function openAt(string $fit = 'Fit', int $page = 1, array $args = []): void
    {
        $named = null;

        foreach (array_keys(self::FIT_ARGUMENTS) as $candidate) {
            if (strcasecmp($fit, $candidate) === 0) {
                $named = $candidate;
            }
        }

        if ($named === null) {
            throw new InvalidArgumentException(sprintf(
                'unknown initial view "%s", expected one of %s',
                $fit,
                implode(', ', array_keys(self::FIT_ARGUMENTS)),
            ));
        }

        if ($page < 1) {
            throw new InvalidArgumentException("initial view page $page is below one");
        }

        $this->openAction = ['fit' => $named, 'page' => $page, 'args' => array_values($args)];
    }

    /**
     * Which panel a reader shows beside the page.
     *
     * `UseOutlines` is the one worth naming: a document that writes bookmarks
     * and does not ask for this opens with them hidden, which is the whole
     * value of having written them.
     *
     * @throws InvalidArgumentException on an unknown mode
     */
    public function pageMode(string $mode): void
    {
        foreach (self::PAGE_MODES as $candidate) {
            if (strcasecmp($mode, $candidate) === 0) {
                $this->pageMode = $candidate;

                return;
            }
        }

        throw new InvalidArgumentException(sprintf(
            'unknown page mode "%s", expected one of %s',
            $mode,
            implode(', ', self::PAGE_MODES),
        ));
    }

    /**
     * A file carried inside the document.
     *
     * This is the half of PDF/A-3 the format exists for: an archived invoice
     * travels with the machine-readable original it was rendered from, and
     * Factur-X and ZUGFeRD are both built on exactly this. The file is written
     * as an associated file, so the catalog's `/AF` array names it and the
     * `/AFRelationship` says what it is TO the document rather than only that
     * it is attached.
     *
     * `$relationship` is one of `Source`, `Data`, `Alternative`, `Supplement`
     * or `Unspecified`. An e-invoice payload is `Data` when the PDF is the
     * original and `Source` when the XML is.
     *
     * @throws InvalidArgumentException on an empty name or an unknown relationship
     */
    public function attach(
        string $name,
        string $bytes,
        string $mime = 'application/octet-stream',
        string $description = '',
        string $relationship = 'Data',
    ): void {
        $name = trim($name);

        if ($name === '') {
            throw new InvalidArgumentException('an attachment needs a file name');
        }

        $known = ['Source', 'Data', 'Alternative', 'Supplement', 'Unspecified'];
        $named = null;

        foreach ($known as $candidate) {
            if (strcasecmp($relationship, $candidate) === 0) {
                $named = $candidate;
            }
        }

        if ($named === null) {
            throw new InvalidArgumentException(sprintf(
                'unknown attachment relationship "%s", expected one of %s',
                $relationship,
                implode(', ', $known),
            ));
        }

        $this->attachments[] = [
            'name'         => $name,
            'bytes'        => $bytes,
            'mime'         => $mime,
            'description'  => $description,
            'relationship' => $named,
        ];
    }

    private readonly Deadline $deadline;

    private readonly Limits $limits;

    /**
     * Document information. Empty values are dropped, so a caller can pass a
     * sparse array without writing blank entries into the file.
     *
     * @param array<string,string> $entries title|author|subject|keywords|creator|producer
     */
    public function info(array $entries): void
    {
        foreach ($entries as $key => $value) {
            if (trim($value) !== '') {
                $this->metadata[$key] = $value;
            }
        }
    }

    public function selectPage(int $index): void
    {
        $this->currentPage = $index;
        $this->markStream  = $index;
    }

    /**
     * A clickable rectangle. `$to` is a URL, or `#name` for an internal jump.
     *
     * Text arrives one laid-out piece at a time, so a three-word link would
     * otherwise become six annotations, one per word and space. Pieces that
     * continue the previous one on the same line are merged into it; a link
     * that wraps still gets one rectangle per line, which is what it needs.
     */
    public function addLink(float $x, float $y, float $w, float $h, string $to, string $says = '', string $anchor = ''): void
    {
        if ($w <= 0.0 || $h <= 0.0 || trim($to) === '') {
            return;
        }

        $this->links[$this->currentPage] ??= [];

        $onPage = &$this->links[$this->currentPage];
        $last   = $onPage === [] ? null : $onPage[count($onPage) - 1];

        if ($last !== null
            && $last['to'] === $to
            && abs($last['y'] - $y) < 0.01
            && abs($last['h'] - $h) < 0.01
            && $x >= $last['x'] && $x - ($last['x'] + $last['w']) < 0.5
        ) {
            $onPage[count($onPage) - 1]['w'] = max($last['w'], $x + $w - $last['x']);
            $onPage[count($onPage) - 1]['says'] .= $says;

            return;
        }

        $onPage[] = ['x' => $x, 'y' => $y, 'w' => $w, 'h' => $h, 'to' => $to, 'says' => $says, 'anchor' => $anchor];
    }

    /** Record a jump target. The first definition of a name wins. */
    public function addAnchor(string $name, float $y): void
    {
        $this->anchors[$name] ??= ['page' => $this->currentPage, 'y' => $y];
    }

    /** @var array<string,true> outline keys already registered */
    private array $outlineSeen = [];

    /**
     * A bookmark in the document outline. Level 0 is top level.
     *
     * A heading that straddles a page boundary is painted once per fragment,
     * and the whole-tree painter may revisit a node as well, so $key identifies
     * the heading itself and only its first registration counts.
     */
    public function addOutlineEntry(string $title, int $level, float $y, string $key = ''): void
    {
        if (trim($title) === '') {
            return;
        }

        if ($key !== '') {
            if (isset($this->outlineSeen[$key])) {
                return;
            }

            $this->outlineSeen[$key] = true;
        }

        $this->outline[] = [
            'title' => $title,
            'level' => max(0, $level),
            'page'  => $this->currentPage,
            'y'     => $y,
        ];
    }

    public function __construct(
        private float $pageWidth = 595.28,
        private float $pageHeight = 841.89,
        private readonly bool $compress = true,
        ?Deadline $deadline = null,
        ?Limits $limits = null,
        private readonly ?Encryption $encryption = null,
        private readonly ?StructureTree $structure = null,
        private readonly bool $pdfa = false,
        private readonly string $conformance = Pdfa::CONFORMANCE,
        private readonly bool $pdfua = false,
    ) {
        $this->limits   = $limits ?? new Limits();
        $this->deadline = $deadline ?? $this->limits->deadline();

        if ($this->pdfa && $this->encryption !== null) {
            throw PdfaConformanceException::encrypted();
        }
    }

    /**
     * The laid-out tree, which is the reading order the structure tree is
     * written in. Painting records where the ink went; this says what it meant
     * and in what order, and only the caller holds it.
     */
    public function structureRoot(Node $root): void
    {
        $this->structureRoot = $root;
    }

    private ?Node $structureRoot = null;

    /**
     * Marked-content sequences currently open, innermost last.
     *
     * @var list<array{at:int,after:int,mcid:?int,node:?Node}>
     */
    private array $marked = [];

    /**
     * Open a marked-content sequence around what this box is about to paint.
     *
     * A box whose ink belongs to an element carries that element's id, so the
     * structure tree can point back at it. Everything else is an artifact: the
     * canvas, a running header, the proxy a fold-cut box paints through. It is
     * a no-op on an untagged document, which is why both painting paths can
     * call it unconditionally.
     */
    public function openContent(Node $n, ?int $order = null, string $wrap = '', string $wrapKey = ''): void
    {
        // An appearance stream is not part of the page's content, so a mark in
        // one would point the parent tree at a sequence no page holds. A widget
        // reaches the structure tree through an /OBJR instead.
        if ($this->structure === null || $this->capture !== null) {
            return;
        }

        $at    = strlen($this->content);
        $owner = $n->structureOwner;
        $mcid  = null;

        // A block-level `<a>` has a box of its own and the box already carries
        // the `Link` role, so wrapping its text in a second one would nest a
        // link inside itself. The key stays, because the annotation still needs
        // its `/OBJR` and it belongs in that outer element.
        // `SM-tag-link.html` a6.
        if ($wrap !== '' && $owner?->role === $wrap) {
            $wrap = '';
        }

        if ($owner === null || $this->structure->inArtifact()) {
            $this->content .= "/Artifact BMC\n";
        } else {
            $mcid          = $this->structure->mark($n, $this->currentPage, $this->markStream, $order, $wrap, $wrapKey);
            $this->content .= sprintf("/%s <</MCID %d>> BDC\n", $wrap !== '' ? $wrap : $owner->role, $mcid);
        }

        $this->marked[] = ['at' => $at, 'after' => strlen($this->content), 'mcid' => $mcid, 'node' => $n];
    }

    /**
     * Where each piece on this box's lines sits, for a painter that has to
     * split the box's sequence around one of them.
     *
     * {@see paintLines} asks the structure tree for the same list directly.
     * This is the same question from outside `Pdf`, and it answers with an
     * empty list on an untagged document so the caller needs no second
     * predicate for the common case.
     *
     * @return array<int,int> spl_object_id of an InlineItem => its ordinal
     */
    public function inlineOrder(Node $n): array
    {
        return $this->structure === null || $this->capture !== null
            ? []
            : $this->structure->inlineOrder($n);
    }

    /**
     * End the sequence around the text and start another at $order.
     *
     * An atomic inline is read where it sits on the line, so the words either
     * side of it cannot share one marked-content id: a structure element points
     * at an id, and one id cannot be read in two places. A stretch that put no
     * ink down leaves no mark, because {@see closeContent()} takes an empty
     * sequence back out.
     */
    public function splitContent(int $order, string $wrap = '', string $wrapKey = ''): void
    {
        if ($this->structure === null || $this->capture !== null || $this->marked === []) {
            return;
        }

        $open = $this->marked[array_key_last($this->marked)];

        if ($open['node'] === null || $open['mcid'] === null) {
            return;
        }

        $node = $open['node'];

        $this->closeContent();
        $this->openContent($node, $order, $wrap, $wrapKey);
    }

    /** Open a sequence for ink that means nothing, whatever box it came from. */
    public function openArtifact(): void
    {
        if ($this->structure === null || $this->capture !== null) {
            return;
        }

        $at            = strlen($this->content);
        $this->content .= "/Artifact BMC\n";

        $this->marked[] = ['at' => $at, 'after' => strlen($this->content), 'mcid' => null, 'node' => null];
    }

    /**
     * Close it, or take it back out where the box painted nothing at all.
     *
     * Which boxes paint is decided in a dozen places inside `BoxPainter`, and
     * a predicate here would have to agree with all of them forever. Measuring
     * the content stream instead cannot drift: an empty sequence is one that
     * added no bytes, and leaving it in would put an empty paragraph in the
     * tree for every box that only holds other boxes.
     */
    public function closeContent(): void
    {
        if ($this->structure === null || $this->capture !== null || $this->marked === []) {
            return;
        }

        $open = array_pop($this->marked);

        if (strlen($this->content) === $open['after']) {
            $this->content = substr($this->content, 0, $open['at']);

            if ($open['mcid'] !== null && $open['node'] !== null) {
                $this->structure->unmark($open['node'], $this->markStream, $open['mcid']);
            }

            return;
        }

        $this->content .= "EMC\n";
    }

    /**
     * The render's wall-clock budget, for the drawing code that runs after
     * layout. A single `<path>` can carry six figures of curves, so painting
     * is as unbounded as layout is and needs the same ceiling.
     */
    public function budget(): Deadline
    {
        return $this->deadline;
    }

    /** Wrap a stream body, deflating it when compression is on. */
    private function stream(string $data, string $extra = ''): string
    {
        if ($this->compress && function_exists('gzcompress')) {
            $packed = gzcompress($data, 9);

            return sprintf(
                "<< /Length %d /Filter /FlateDecode%s >>\nstream\n%s\nendstream",
                strlen($packed),
                $extra,
                $packed,
            );
        }

        return sprintf("<< /Length %d%s >>\nstream\n%s\nendstream", strlen($data), $extra, $data);
    }

    public function beginPage(): void
    {
        $this->content     = '';
        $this->charSpacing = 0.0;
        $this->wordSpacing = 0.0;
    }

    /** Append raw content-stream operators. Used by the SVG renderer. */
    public function raw(string $ops): void { $this->content .= $ops; }

    public function pageHeightValue(): float { return $this->pageHeight; }

    /**
     * The sheet one page is written on, where it is not the document's own.
     *
     * `@page :first { size: ... }` gives one page a `/MediaBox` of its own, and
     * every coordinate this writer prints is measured from the page height, so
     * the size has to be in force before anything is painted on that page
     * rather than only when the box is written out. Call it after
     * {@see selectPage} and before the first drawing call.
     *
     * @var array<int,array{0:float,1:float}>
     */
    private array $pageSizes = [];

    public function pageSize(float $width, float $height): void
    {
        $this->pageWidth  = $width;
        $this->pageHeight = $height;

        $this->pageSizes[$this->currentPage] = [$width, $height];
    }

    public function endPage(): void
    {
        $this->streams[] = $this->content;
        $this->content   = '';
    }

    private function ty(float $y, float $h = 0.0): float
    {
        if ($this->capture === null) {
            return $this->pageHeight - $y - $h;
        }

        /*
         * An appearance stream's coordinates are the page's less the origin the
         * `/Rect` was written with, and the page value is rounded **first**.
         * Rounding the two halves separately instead puts them either side of a
         * tie: a baseline at 121.5625 goes to 121.562 on the page and to
         * 116.588 plus 4.975 through a widget, and the thousandth of a point
         * between them is a visible gray level on a glyph edge at high zoom.
         * The origin is already exact to three places, so subtracting it after
         * the rounding cannot move the result.
         */
        return self::quantise($this->pageHeight - $this->capture['top'] - $y - $h) - $this->capture['base'];
    }

    /**
     * A coordinate rounded the way this writer prints one.
     *
     * `round()` is not that: PHP breaks a tie away from zero and `printf`
     * breaks it to even, so a baseline at 121.5625 is 121.563 through one and
     * 121.562 through the other. Everything in an appearance stream is measured
     * from a number the `/Rect` already printed, so a coordinate that rounds
     * the other way lands a thousandth of a point off the ink it is standing in
     * for, which is a gray level on a glyph edge at high zoom.
     */
    private static function quantise(float $value): float
    {
        return (float) sprintf('%.3f', $value);
    }

    /**
     * Register the widget this control box is, and say whether it is new.
     *
     * False means the same box already has a widget on this page, which is a
     * repeating header painting its control a second time. The first rectangle
     * wins and nothing is captured again.
     */
    public function beginWidget(Node $control, float $x, float $y, float $w, float $h): bool
    {
        if ($control->formField === null || $w <= 0.0 || $h <= 0.0) {
            return false;
        }

        $key = $this->widgetKey($control);

        if (isset($this->widgets[$key])) {
            return false;
        }

        $this->widgets[$key] = [
            'field' => $control->formField,
            'page'  => $this->currentPage,
            'rect'  => [$x, $y, $w, $h],
            'da'    => $this->defaultAppearance($control),
            'off'   => '',
            'on'    => '',
            'value' => '',
        ];

        $this->structure?->widget($control, $this->currentPage);

        return true;
    }

    /**
     * Draw into one of a widget's appearance streams instead of onto the page.
     *
     * The three slots are the two states a checkbox has and the value a text or
     * choice field shows, and they are concatenated in that order at write
     * time, so a control's decoration is under its value however the painting
     * walk happened to reach the two boxes.
     */
    public function captureWidget(Node $control, string $slot, callable $draw): void
    {
        $key = $this->widgetKey($control);

        if (!isset($this->widgets[$key])) {
            return;
        }

        $outer  = $this->content;
        $charSp = $this->charSpacing;
        $wordSp = $this->wordSpacing;
        $outerCapture = $this->capture;

        [$x, $top, , $height] = $this->widgets[$key]['rect'];

        $this->content     = '';
        $this->charSpacing = 0.0;
        $this->wordSpacing = 0.0;
        $this->capture     = ['top' => $top, 'base' => $this->widgetCorner($x, $top, $height)[1]];

        try {
            $draw();
            $this->widgets[$key][$slot] .= $this->content;
        } finally {
            // Restored rather than cleared: a control holds a text box and
            // nothing that could hold a second control, but a capture that
            // ends by asserting there was no outer one is a rule the next
            // change has to keep, and this is not.
            $this->capture     = $outerCapture;
            $this->content     = $outer;
            $this->charSpacing = $charSp;
            $this->wordSpacing = $wordSp;
        }
    }

    /** The height of the widget this control owns, for a child drawing into it. */
    public function widgetHeight(Node $control): ?float
    {
        return $this->widgets[$this->widgetKey($control)]['rect'][3] ?? null;
    }

    /**
     * Where the control's box was painted, so a child can work out its own
     * offset inside the appearance stream by subtraction.
     *
     * Reading the child's own `x` and `y` off the node instead looks simpler
     * and is wrong: a form control that is a flex item is blockified, so it
     * reaches the fragmenter and its text child is a fragment of its own with
     * coordinates in the page's space rather than the control's. That put a
     * flexed control's value at -10.025 in a box 14.625 tall, which is outside
     * the `/BBox` and therefore invisible.
     *
     * @return array{0:float,1:float}|null
     */
    public function widgetOrigin(Node $control): ?array
    {
        $rect = $this->widgets[$this->widgetKey($control)]['rect'] ?? null;

        // The x is the value the `/Rect` printed, so a child's own offset is
        // exact once it is printed too; the y is the raw one, because `ty()`
        // adds it straight back on.
        return $rect === null ? null : [self::quantise($rect[0]), $rect[1]];
    }

    /**
     * The bottom left corner a widget's `/Rect` is written with, rounded to the
     * places a PDF coordinate carries so that anything measured from it stays
     * exact once it is written down.
     *
     * @return array{0:float,1:float}
     */
    private function widgetCorner(float $x, float $y, float $h): array
    {
        return [self::quantise($x), self::quantise($this->pageHeight - $y - $h)];
    }

    private function widgetKey(Node $control): string
    {
        return spl_object_id($control) . ':' . $this->currentPage;
    }

    /**
     * A field's `/DA`: the face, the size and the color a reader draws a value
     * it did not get from us in.
     *
     * It is taken off the control box rather than defaulted, so a filled field
     * looks like the template's own text and not like the reader's idea of a
     * form. The face is registered here as a side effect, which is what puts it
     * in `/DR` as well as in the page's resources.
     */
    private function defaultAppearance(Node $control): string
    {
        $face = FontRegistry::default()->get(
            $control->fontFamily,
            $control->bold,
            $control->italic,
            $control->fontStretch,
        );
        [$r, $g, $b] = $control->color ?? [0.0, 0.0, 0.0];

        return sprintf(
            '/%s %.2f Tf %.4f %.4f %.4f rg',
            $this->resourceFor($face),
            $control->fontSize,
            $r,
            $g,
            $b,
        );
    }

    private static function esc(string $s): string
    {
        return strtr($s, ['\\' => '\\\\', '(' => '\\(', ')' => '\\)']);
    }

    /**
     * A PDF name token, `#`-escaped for everything a bare name may not hold.
     *
     * A MIME type is the reason this exists: `/text/xml` is two name tokens
     * and a stray solidus, and `/text#2Fxml` is the one the spec asks for.
     */
    private static function nameToken(string $s): string
    {
        $out = '';

        foreach (str_split($s) as $byte) {
            $code = ord($byte);

            $out .= $code > 0x20 && $code < 0x7F
                && !in_array($byte, ['#', '/', '%', '(', ')', '<', '>', '[', ']', '{', '}'], true)
                ? $byte
                : sprintf('#%02X', $code);
        }

        return $out === '' ? 'application#2Foctet-stream' : $out;
    }

    /** A number in a PDF array, trimmed so an integer does not carry a tail of zeros. */
    private static function num(float $value): string
    {
        return rtrim(rtrim(sprintf('%.3f', $value), '0'), '.') ?: '0';
    }

    /**
     * A PDF text string. Literal `(...)` can only carry PDFDocEncoding, so
     * anything outside ASCII goes out as UTF-16BE with a byte order mark.
     * Without this a title like "Factuur café" reaches a reader as mojibake.
     */
    private static function textString(string $s): string
    {
        if (mb_check_encoding($s, 'ASCII')) {
            return '(' . self::esc($s) . ')';
        }

        return '<FEFF' . strtoupper(bin2hex(mb_convert_encoding($s, 'UTF-16BE', 'UTF-8'))) . '>';
    }

    /**
     * Assign (and remember) a PDF resource name for a face.
     *
     * Small capitals get a resource of their own over the same face. They are
     * drawn as capitals, so the code on the page is `A` where the author
     * wrote `a`, and the only way back is a `ToUnicode` map. That is per
     * font, so the two cannot share one.
     */
    private function resourceFor(Font|TrueTypeFont $face, bool $smallCaps = false): string
    {
        foreach ($this->used as $name => $entry) {
            if ($entry['face'] === $face && $entry['smallCaps'] === $smallCaps) {
                return $name;
            }
        }

        $name = 'F' . (count($this->used) + 1);

        $this->used[$name] = ['face' => $face, 'smallCaps' => $smallCaps];

        return $name;
    }

    /**
     * Sampled images, keyed by what they were computed from.
     *
     * A shadow or a gradient on a box that spans a page break is painted once
     * per page, and a repeating header repeats it again. The samples are the
     * same every time, so computing them again is pure cost, and a fresh
     * `PdfImage` is a fresh resource, so it lands in the file again too.
     *
     * @var array<string, PdfImage>
     */
    private array $sampled = [];

    /** @param callable():PdfImage $build */
    private function sampledImage(string $key, callable $build): string
    {
        return $this->resourceForImage($this->sampled[$key] ??= $build());
    }

    private function resourceForImage(PdfImage $img): string
    {
        foreach ($this->images as $name => $i) {
            if ($i === $img) {
                return $name;
            }
        }

        $name = 'Im' . (count($this->images) + 1);

        $this->images[$name] = $img;

        return $name;
    }

    /**
     * Images are drawn into a unit square, so the CTM carries the size.
     *
     * `object-fit` changes the rect the image is drawn into; anything that
     * would spill outside the box is clipped to it.
     */
    public function drawImage(
        PdfImage $img,
        float $x,
        float $y,
        float $w,
        float $h,
        string $fit = 'fill',
    ): void {
        $res = $this->resourceForImage($img);

        [$dx, $dy, $dw, $dh] = $this->fitRect($fit, $x, $y, $w, $h, $img->width, $img->height);
        $clip = abs($dw - $w) > 0.01 || abs($dh - $h) > 0.01;

        if ($clip) {
            $this->pushClip($x, $y, $w, $h);
        }

        $this->content .= sprintf(
            "q %.3f 0 0 %.3f %.3f %.3f cm /%s Do Q\n",
            $dw,
            $dh,
            $dx,
            $this->ty($dy, $dh),
            $res,
        );

        if ($clip) {
            $this->pop();
        }
    }

    /** @return array{0:float,1:float,2:float,3:float} */
    private function fitRect(
        string $fit,
        float $x,
        float $y,
        float $w,
        float $h,
        int $iw,
        int $ih,
    ): array {
        if ($fit === 'fill' || $iw <= 0 || $ih <= 0 || $w <= 0 || $h <= 0) {
            return [$x, $y, $w, $h];
        }

        $natural = [$iw * 0.75, $ih * 0.75];

        $scale = match ($fit) {
            'contain'    => min($w / $iw, $h / $ih),
            'cover'      => max($w / $iw, $h / $ih),
            'none'       => 0.75,
            'scale-down' => min(0.75, min($w / $iw, $h / $ih)),
            default      => 1.0,
        };

        $dw = $iw * $scale;
        $dh = $ih * $scale;

        // Centred, which is the `object-position: 50% 50%` default
        return [$x + ($w - $dw) / 2, $y + ($h - $dh) / 2, $dw, $dh];
    }

    // ---------------------------------------------------------------
    // painting
    // ---------------------------------------------------------------
    /**
     * A filled rectangle, and one with no area is not written at all.
     *
     * A fill has no caps and no line width, so a rectangle with a zero side
     * covers no pixel: the operator is bytes and no ink. Chrome writes no path
     * for one either, which is defect HI and is measured on
     * `TU-table-float.html`: a `width: 0` box with a background prints a
     * `0.000 x 9.000` path here and nothing at all there, and an EMPTY
     * `display: table`, which is shrink-to-fit around no content, is the same
     * box arrived at from the markup instead of from a declaration. The
     * negative case goes with it: a PDF rectangle with a negative side extends
     * the other way and paints where nothing asked it to.
     *
     * @param list<array{0:float,1:float}>|array{0:float,1:float,2:float,3:float}|float $radius
     */
    public function fillRect(float $x, float $y, float $w, float $h, array $rgb, array|float $radius = 0.0): void
    {
        if ($w <= 0.0 || $h <= 0.0) {
            return;
        }

        [$r, $g, $b] = $rgb;
        $transparency = $this->beginAlpha($rgb[3] ?? 1.0);
        $this->content .= sprintf("%.4f %.4f %.4f rg\n", $r, $g, $b);

        if (!self::rounded($radius)) {
            $this->content .= sprintf("%.3f %.3f %.3f %.3f re f\n", $x, $this->ty($y, $h), $w, $h);
            $this->endAlpha($transparency);

            return;
        }

        $this->roundedPath($x, $y, $w, $h, self::corners($radius));
        $this->content .= "f\n";
        $this->endAlpha($transparency);
    }

    /**
     * Colors carry their alpha as a fourth component. Anything below 1 needs an
     * ExtGState around the paint, since PDF has no per-operator alpha.
     */
    private function beginAlpha(float $alpha): bool
    {
        if ($alpha >= 1.0) {
            return false;
        }

        $this->content .= "q\n";
        $this->content .= sprintf("/%s gs\n", $this->alphaState(max(0.0, $alpha), 'normal'));

        return true;
    }

    private function endAlpha(bool $pushed): void
    {
        if ($pushed) {
            $this->content .= "Q\n";
        }
    }

    /**
     * Registers an alpha and blend pair, reusing an equivalent state.
     *
     * The stroke alpha is the fill's unless it is given, because everything in
     * CSS fades a box whole. SVG does not: `fill-opacity` and `stroke-opacity`
     * are separate paints on one shape, and `SK-svg-opacity.html` k5 and k6 are
     * Chrome fading one of the two and leaving the other solid.
     */
    private function alphaState(float $alpha, string $blend, ?float $stroke = null): string
    {
        $stroke ??= $alpha;
        $this->alphaWrites++;

        foreach ($this->alphas as $existing => $state) {
            if (abs($state['alpha'] - $alpha) < 1e-6
                && abs($state['stroke'] - $stroke) < 1e-6
                && $state['blend'] === $blend
            ) {
                return $existing;
            }
        }

        $name                = 'GS' . count($this->alphas);
        $this->alphas[$name] = ['alpha' => $alpha, 'stroke' => $stroke, 'blend' => $blend];

        return $name;
    }

    /**
     * How many constant-alpha states have been named for the stream so far.
     *
     * {@see closeGroup} needs to know whether the group it is closing set an
     * alpha of its own, and every caller of {@see alphaState} writes the name
     * it gets, so counting the calls answers it. Searching the group's content
     * for the names answers it too and is O(states x content) on input an
     * author controls, which is a document with a thousand distinct opacities
     * away from being a real cost.
     */
    private function alphaStateWrites(): int
    {
        return $this->alphaWrites;
    }

    /**
     * Name an ExtGState that fades a fill and a stroke by different amounts,
     * for a caller writing its own operators. Returns null when neither is
     * faded, so the caller can leave the graphics state alone.
     */
    public function paintAlphaState(float $fill, float $stroke): ?string
    {
        if ($fill >= 1.0 && $stroke >= 1.0) {
            return null;
        }

        return $this->alphaState(
            max(0.0, min(1.0, $fill)),
            'normal',
            max(0.0, min(1.0, $stroke)),
        );
    }

    /**
     * Fill a box with a CSS linear gradient, as a PDF axial shading.
     *
     * The gradient line runs through the box center at the CSS angle, and its
     * length is the projection of the box onto that direction, which is what
     * makes `to bottom` on a tall box run corner to corner rather than stop
     * short. The shading is painted through a clip, so `sh` needs no pattern
     * color space and the corners follow `border-radius` for free.
     *
     * @param array{angle:float,stops:array<int,array{0:float,1:array}>} $gradient
     * @param array{0:float,1:float,2:float,3:float}|float               $radius
     */
    /**
     * One run of text drawn in the **current** user space, at a baseline
     * origin, with the y axis flipped back.
     *
     * Everything else in this class draws text in page coordinates, where
     * `ty()` does the flip. An SVG is painted under a `cm` that has already
     * flipped y once for the whole tree, so glyphs drawn through the ordinary
     * path come out mirrored and in the wrong place. The text matrix here
     * carries the counter-flip, which is what SVG `<text>` needs and nothing
     * else does. Defect DO.
     *
     * @param array{0:float,1:float,2:float,3?:float} $color
     */
    public function drawTextInUserSpace(
        Font|TrueTypeFont $face,
        string $text,
        float $size,
        float $x,
        float $y,
        array $color,
    ): void {
        if ($text === '' || $size <= 0.0) {
            return;
        }

        $this->deadline->check('painting');

        // An SVG label is shaped with the same default features body text is,
        // and {@see SvgDocument} measures it with the same string, so a chart's
        // title kerns like the paragraph above it rather than being the one
        // piece of the document that does not.
        [$res, $show] = $this->showFor($face, $text, OpenTypeLayout::DEFAULT_KEY, $size, flipped: true);

        $transparency = $this->beginAlpha($color[3] ?? 1.0);

        /*
         * Tc and Tw are text state and survive BT/ET, so the tracking of the
         * last run of body text is still in force here. An SVG label carries
         * no letter-spacing of its own, so whatever is set has to be put back
         * to zero: without this a chart title drawn after a heading with
         * `letter-spacing` comes out spaced like the heading. Defect IW.
         *
         * The tracked values are deliberately NOT updated. Every caller draws
         * inside the `q` and `Q` {@see SvgDocument::render} wraps the whole
         * tree in, so the `Q` puts the outer spacing back and the fields still
         * describe what is in force on the page after it. Updating them here
         * makes the next run of body text think a reset it never emitted is
         * already in effect, and the paragraph after an SVG comes out spaced
         * like the heading before it, which is the bug one line over.
         */
        $spacing = '';

        if (abs($this->charSpacing) > 1e-9) {
            $spacing .= '0.000 Tc ';
        }

        if (abs($this->wordSpacing) > 1e-9) {
            $spacing .= '0.000 Tw ';
        }

        $this->content .= sprintf(
            "BT /%s %.2f Tf %s%.4f %.4f %.4f rg 1 0 0 -1 %.3f %.3f Tm %s ET\n",
            $res,
            $size,
            $spacing,
            $color[0],
            $color[1],
            $color[2],
            $x,
            $y,
            $show,
        );

        $this->endAlpha($transparency);
    }

    /**
     * An axial shading painted in the **current** user space, between two
     * points, with the path already on the stack as its clip.
     *
     * `fillGradient()` above works in page coordinates: it flips y itself and
     * pushes its own rectangular clip, which is right for a CSS box and wrong
     * inside an SVG, where a `cm` is already in force and the shape is a path
     * rather than a rect. PDF's `sh` operator paints in whatever space is
     * current, so this is the same shading machinery with the page arithmetic
     * taken out. Defect DP.
     *
     * @param array<int,array{0:float,1:array{0:float,1:float,2:float,3?:float}}> $stops
     */
    public function shadeAxial(array $stops, float $x0, float $y0, float $x1, float $y1): void
    {
        if (count($stops) < 2) {
            return;
        }

        $this->deadline->check('painting');

        $name = $this->addShading('axial', [$x0, $y0, $x1, $y1], $stops);

        $this->content .= sprintf("/%s sh\n", $name);
    }

    /**
     * Keep one shading and hand back the resource name to draw it with.
     *
     * Every gradient goes through here so the stop budget has one place to
     * live. A stop list is the expensive part: a repeating gradient resolves
     * to up to {@see MAX_GRADIENT_SPANS} of them, one box may tile
     * {@see BoxPainter::MAX_TILES} copies on a single page, and a document
     * may have `max_pages` pages. Each of those three is capped and their
     * product was not, which is defect DU: 1,276 pages of a tiled repeating
     * gradient lay out in 18 MB and then exhaust memory here.
     *
     * @param  array<int,float>                                                     $geometry
     * @param  array<int,array{0:float,1:array{0:float,1:float,2:float,3?:float}}>  $stops
     * @return string the resource name
     */
    private function addShading(string $kind, array $geometry, array $stops): string
    {
        $this->gradientStops += count($stops);

        if ($this->gradientStops > $this->limits->maxGradientStops) {
            throw GradientLimitExceededException::at($this->limits->maxGradientStops);
        }

        $name                  = 'Sh' . count($this->shadings);
        $this->shadings[$name] = [$kind, $geometry, $stops];

        return $name;
    }

    public function fillGradient(
        array $gradient,
        float $x,
        float $y,
        float $w,
        float $h,
        array|float $radius = 0.0,
    ): void {
        if ($w <= 0.0 || $h <= 0.0 || count($gradient['stops'] ?? []) < 2) {
            return;
        }

        $this->deadline->check('painting');

        // PDF has an axial and a radial shading and nothing else, and a
        // shading carries one constant alpha. Everything outside that is
        // sampled into an image instead, which is what a browser printing to
        // PDF does with the same shapes.
        if (($gradient['type'] ?? 'linear') === 'conic' || self::alphaVaries($gradient['stops'])) {
            $this->paintGradientSamples($gradient, $x, $y, $w, $h, $radius);

            return;
        }

        if (($gradient['type'] ?? 'linear') === 'radial') {
            $this->fillRadialGradient($gradient, $x, $y, $w, $h, $radius);

            return;
        }

        $angle = $gradient['angle'];

        // A corner keyword's angle is only knowable here: CSS puts the
        // gradient line perpendicular to the diagonal joining the other two
        // corners, so it follows the box's aspect ratio.
        if (($gradient['corner'] ?? null) !== null) {
            [$sx, $sy] = $gradient['corner'];
            $angle     = rad2deg(atan2($sx * $h, -$sy * $w));
        }

        // CSS measures clockwise from "up"; PDF y grows upward, so the sine
        // and cosine land on the axes the other way round from the usual.
        $radians = deg2rad($angle);
        $dx      = sin($radians);
        $dy      = cos($radians);
        $length  = abs($w * $dx) + abs($h * $dy);

        $cx = $x + $w / 2.0;
        $cy = $this->ty($y, $h) + $h / 2.0;

        $stops = self::resolveStops($gradient, $length);

        if (count($stops) < 2) {
            return;
        }

        $name = $this->addShading(
            'axial',
            [
                $cx - $dx * $length / 2.0,
                $cy - $dy * $length / 2.0,
                $cx + $dx * $length / 2.0,
                $cy + $dy * $length / 2.0,
            ],
            $stops,
        );

        $transparency = $this->beginAlpha($stops[0][1][3] ?? 1.0);
        $this->pushClip($x, $y, $w, $h, $radius);
        $this->content .= sprintf("/%s sh\n", $name);
        $this->pop();
        $this->endAlpha($transparency);
    }

    /**
     * A CSS radial gradient, as a PDF radial shading.
     *
     * PDF's radial shading is two circles, so an elliptical ending shape is
     * drawn as a circle under a scale about the center. The ending shape's
     * size comes from the extent keyword, which measures to a side or to a
     * corner and is the only part of the syntax that needs the box.
     *
     * @param list<array{0:float,1:float}>|array{0:float,1:float,2:float,3:float}|float $radius
     */
    private function fillRadialGradient(
        array $gradient,
        float $x,
        float $y,
        float $w,
        float $h,
        array|float $radius,
    ): void {
        $cx = $x + self::positionAlong($gradient['at'][0] ?? '50%', $w);
        $cy = $y + self::positionAlong($gradient['at'][1] ?? '50%', $h);

        [$rx, $ry] = self::radialExtent($gradient, $cx - $x, $cy - $y, $w, $h);

        if ($rx <= 0.0 || $ry <= 0.0) {
            return;
        }

        $stops = self::resolveStops($gradient, $rx);

        if (count($stops) < 2) {
            return;
        }

        $flipY = $this->ty($cy);
        $ratio = $ry / $rx;

        $name = $this->addShading('radial', [$cx, $flipY, 0.0, $cx, $flipY, $rx], $stops);

        $transparency = $this->beginAlpha($stops[0][1][3] ?? 1.0);
        $this->pushClip($x, $y, $w, $h, $radius);

        // Scale about the center, so the circle the shading draws becomes the
        // ellipse CSS asked for without moving where it is centred.
        $this->content .= sprintf(
            "q 1 0 0 %.6f 0 %.4f cm\n",
            $ratio,
            $flipY * (1.0 - $ratio),
        );
        $this->content .= sprintf("/%s sh\nQ\n", $name);
        $this->pop();
        $this->endAlpha($transparency);
    }

    /**
     * The radii of a radial gradient's ending shape.
     *
     * @param array<string,mixed> $gradient
     * @return array{0:float,1:float}
     */
    private static function radialExtent(array $gradient, float $ox, float $oy, float $w, float $h): array
    {
        $circle = ($gradient['shape'] ?? 'ellipse') === 'circle';
        $size   = $gradient['size'] ?? null;

        if ($size !== null) {
            $rx = self::positionAlong($size[0], $w);
            $ry = $circle ? $rx : self::positionAlong($size[1], $h);

            return [$rx, $ry];
        }

        $left   = abs($ox);
        $right  = abs($w - $ox);
        $top    = abs($oy);
        $bottom = abs($h - $oy);

        $sideX = str_starts_with($gradient['extent'] ?? 'farthest-corner', 'closest')
            ? min($left, $right)
            : max($left, $right);
        $sideY = str_starts_with($gradient['extent'] ?? 'farthest-corner', 'closest')
            ? min($top, $bottom)
            : max($top, $bottom);

        if (str_ends_with($gradient['extent'] ?? 'farthest-corner', 'side')) {
            return $circle ? [min($sideX, $sideY), min($sideX, $sideY)] : [$sideX, $sideY];
        }

        if ($circle) {
            $r = hypot($sideX, $sideY);

            return [$r, $r];
        }

        /*
         * CSS §3.3: a corner ellipse keeps the aspect ratio the matching
         * *side* keyword would give and passes through the corner. With that
         * ratio the corner sits at (sx, sy), and an ellipse of radii k·sx by
         * k·sy through it solves to k = sqrt(2), whatever the box.
         */
        return [$sideX * M_SQRT2, $sideY * M_SQRT2];
    }

    /** A `<length-percentage>` along one axis of the box. */
    private static function positionAlong(string $value, float $extent): float
    {
        $value = strtolower(trim($value));

        return match (true) {
            $value === 'left' || $value === 'top'     => 0.0,
            $value === 'center'                       => $extent / 2.0,
            $value === 'right' || $value === 'bottom' => $extent,
            str_ends_with($value, '%')                => (float) rtrim($value, '%') / 100.0 * $extent,
            default                                   => self::lengthPoints($value),
        };
    }

    private static function lengthPoints(string $value): float
    {
        $value = strtolower(trim($value));

        return match (true) {
            str_ends_with($value, 'pt') => (float) $value,
            str_ends_with($value, 'px') => (float) $value * 0.75,
            str_ends_with($value, 'in') => (float) $value * 72.0,
            str_ends_with($value, 'cm') => (float) $value * 28.3465,
            str_ends_with($value, 'mm') => (float) $value * 2.83465,
            default                     => (float) $value,
        };
    }

    /** @param array<int, array{0:float|string|null,1:array|null}> $stops */
    private static function alphaVaries(array $stops): bool
    {
        $first = null;

        foreach ($stops as $stop) {
            if ($stop[1] === null) {
                continue;
            }

            $alpha = $stop[1][3] ?? 1.0;
            $first ??= $alpha;

            if (abs($alpha - $first) > 1e-6) {
                return true;
            }
        }

        return false;
    }

    /**
     * How many copies of a repeating gradient's pattern may be laid down. The
     * period is author-controlled and the count is the line length over it,
     * so a `0.0001pt` period is otherwise a way to spend the whole render.
     */
    private const int MAX_GRADIENT_REPEATS = 512;

    /** And how many spans the function that draws it may end up with. */
    private const int MAX_GRADIENT_SPANS = 4096;

    /**
     * Turn a parsed stop list into positioned color stops on [0, 1].
     *
     * Lengths resolve against the gradient line, an unpositioned run is
     * spread evenly between the positions around it, a position may never go
     * backwards, an interpolation hint becomes the samples that reproduce its
     * curve, and a repeating gradient tiles what is left across the line.
     *
     * @param  array<string,mixed> $gradient
     * @return array<int, array{0:float,1:array}>
     */
    private static function resolveStops(array $gradient, float $lineLength): array
    {
        $stops = [];

        foreach ($gradient['stops'] as [$position, $color]) {
            $stops[] = [
                match (true) {
                    $position === null   => null,
                    is_string($position) => $lineLength > 0.0
                        ? self::lengthPoints($position) / $lineLength
                        : 0.0,
                    default              => $position,
                },
                $color,
            ];
        }

        $last = count($stops) - 1;
        $stops[0][0] ??= 0.0;
        $stops[$last][0] ??= 1.0;

        $previous = 0;

        foreach ($stops as $i => $stop) {
            if ($stop[0] === null) {
                continue;
            }

            $gap = $i - $previous;

            for ($k = 1; $k < $gap; $k++) {
                $stops[$previous + $k][0] = $stops[$previous][0]
                    + ($stop[0] - $stops[$previous][0]) * $k / $gap;
            }

            $previous = $i;
        }

        // CSS §3.4.3: a stop never sits before the one in front of it.
        for ($i = 1; $i <= $last; $i++) {
            $stops[$i][0] = max($stops[$i][0], $stops[$i - 1][0]);
        }

        $stops = self::expandHints($stops);

        if (($gradient['repeating'] ?? false) === true) {
            $stops = self::repeatStops($stops);
        }

        $stops = array_values(array_filter($stops, static fn(array $stop): bool => $stop[1] !== null));

        return self::clipStops($stops);
    }

    /**
     * Cut the stop list down to the [0, 1] the shading's domain covers.
     *
     * A stop may legitimately sit outside it (`red -20%`, a length longer
     * than the gradient line, or any copy of a repeating pattern), and the
     * color at the boundary is what the domain has to start and end on.
     *
     * @param  array<int, array{0:float,1:array}> $stops
     * @return array<int, array{0:float,1:array}>
     */
    private static function clipStops(array $stops): array
    {
        if (count($stops) < 2) {
            return $stops;
        }

        $inside = array_values(array_filter(
            $stops,
            static fn(array $stop): bool => $stop[0] > 0.0 && $stop[0] < 1.0,
        ));

        $clipped = [
            [0.0, self::sampleStops($stops, 0.0)],
            ...$inside,
            [1.0, self::sampleStops($stops, 1.0)],
        ];

        // A type 3 function wants its bounds strictly increasing, and a hard
        // stop is written as two positions that are equal.
        for ($i = 1, $n = count($clipped); $i < $n; $i++) {
            $clipped[$i][0] = max($clipped[$i][0], $clipped[$i - 1][0] + ($i < $n - 1 ? 1e-6 : 0.0));
        }

        return $clipped;
    }

    /**
     * Replace each interpolation hint with samples of the curve it asks for.
     *
     * A hint says where the two colors around it are evenly mixed. CSS gets
     * there with a power curve whose exponent puts the midpoint at the hint,
     * and PDF has no such function, so it is sampled.
     *
     * @param  array<int, array{0:float,1:array|null}> $stops
     * @return array<int, array{0:float,1:array|null}>
     */
    private const int HINT_SAMPLES = 32;

    private static function expandHints(array $stops): array
    {
        $out = [];

        foreach ($stops as $i => $stop) {
            if ($stop[1] !== null) {
                $out[] = $stop;

                continue;
            }

            $before = $stops[$i - 1] ?? null;
            $after  = $stops[$i + 1] ?? null;

            if ($before === null || $after === null || $before[1] === null || $after[1] === null) {
                continue;
            }

            $span = $after[0] - $before[0];

            if ($span <= 0.0) {
                continue;
            }

            $ratio = ($stop[0] - $before[0]) / $span;

            if ($ratio <= 0.0 || $ratio >= 1.0) {
                continue;
            }

            $exponent = log(0.5) / log($ratio);

            for ($k = 1; $k < self::HINT_SAMPLES; $k++) {
                $t     = $k / self::HINT_SAMPLES;
                $mix   = $t ** $exponent;
                $out[] = [$before[0] + $t * $span, self::mixColor($before[1], $after[1], $mix)];
            }
        }

        return $out;
    }

    /**
     * Mix two gradient stops, in premultiplied alpha.
     *
     * CSS §3.4.4 requires it, and the difference is the whole reason
     * `red, transparent` is written that way: `transparent` is
     * `rgba(0, 0, 0, 0)`, so mixing the channels straight fades red through
     * gray, where premultiplying fades it out as red. With equal alphas the
     * two are the same arithmetic.
     *
     * @param  array{0:float,1:float,2:float,3?:float} $from
     * @param  array{0:float,1:float,2:float,3?:float} $to
     * @return array{0:float,1:float,2:float,3:float}
     */
    private static function mixColor(array $from, array $to, float $t): array
    {
        $fromAlpha = $from[3] ?? 1.0;
        $toAlpha   = $to[3] ?? 1.0;
        $alpha     = $fromAlpha + ($toAlpha - $fromAlpha) * $t;

        if ($alpha <= 0.0) {
            return [$from[0], $from[1], $from[2], 0.0];
        }

        $channel = static fn(int $i): float => (
            $from[$i] * $fromAlpha + ($to[$i] * $toAlpha - $from[$i] * $fromAlpha) * $t
        ) / $alpha;

        return [$channel(0), $channel(1), $channel(2), $alpha];
    }

    /**
     * Tile a repeating gradient's pattern across the whole gradient line.
     *
     * @param  array<int, array{0:float,1:array|null}> $stops
     * @return array<int, array{0:float,1:array|null}>
     */
    private static function repeatStops(array $stops): array
    {
        $start  = $stops[0][0];
        $period = $stops[count($stops) - 1][0] - $start;

        if ($period <= 1e-9) {
            return $stops;
        }

        $first = (int) floor((0.0 - $start) / $period);
        $last  = (int) ceil((1.0 - $start) / $period);

        // The copies multiply the stop list, and it is the resolved length
        // that becomes spans of a PDF function, so the ceiling is on the
        // product rather than on the number of copies alone.
        $copies = max(1, (int) min(self::MAX_GRADIENT_REPEATS, self::MAX_GRADIENT_SPANS / count($stops)));

        if ($last - $first > $copies) {
            $last = $first + $copies;
        }

        $out = [];

        // Every copy keeps every stop. Where two copies meet the positions
        // coincide, and that is a hard edge rather than a duplicate: dropping
        // one of them turns each boundary into a ramp across a whole period.
        for ($k = $first; $k <= $last; $k++) {
            foreach ($stops as $stop) {
                $out[] = [$stop[0] + $k * $period, $stop[1]];
            }
        }

        return $out;
    }

    /**
     * How many samples a gradient drawn as an image may hold, on the same
     * reasoning as a shadow mask's ceiling.
     */
    private const int MAX_GRADIENT_SAMPLES = 65536;

    /**
     * Draw a gradient PDF has no shading for by sampling it into an image.
     *
     * @param list<array{0:float,1:float}>|array{0:float,1:float,2:float,3:float}|float $radius
     */
    private function paintGradientSamples(
        array $gradient,
        float $x,
        float $y,
        float $w,
        float $h,
        array|float $radius,
    ): void {
        $scale = min(1.0, sqrt(self::MAX_GRADIENT_SAMPLES / ($w * $h)));
        $cols  = max(1, (int) ceil($w * $scale));
        $rows  = max(1, (int) ceil($h * $scale));

        $ramp = $this->gradientRamp($gradient, $w, $h);

        if ($ramp === null) {
            return;
        }

        [$stops, $project] = $ramp;

        $name = $this->sampledImage(
            'gradient:' . $cols . ':' . $rows . ':' . $w . ':' . $h . ':' . json_encode($gradient),
            static function () use ($cols, $rows, $w, $h, $stops, $project): PdfImage {
                $rgb   = '';
                $alpha = '';

                for ($row = 0; $row < $rows; $row++) {
                    $py = ($row + 0.5) * $h / $rows;

                    for ($col = 0; $col < $cols; $col++) {
                        $px    = ($col + 0.5) * $w / $cols;
                        $color = self::sampleStops($stops, $project($px, $py));
                        $rgb   .= chr((int) round(max(0.0, min(1.0, $color[0])) * 255))
                            . chr((int) round(max(0.0, min(1.0, $color[1])) * 255))
                            . chr((int) round(max(0.0, min(1.0, $color[2])) * 255));
                        $alpha .= chr((int) round(max(0.0, min(1.0, $color[3] ?? 1.0)) * 255));
                    }
                }

                return PdfImage::samples($cols, $rows, $rgb, $alpha);
            },
        );

        $this->pushClip($x, $y, $w, $h, $radius);
        $this->content .= sprintf(
            "q %.3f 0 0 %.3f %.3f %.3f cm /%s Do Q\n",
            $w,
            $h,
            $x,
            $this->ty($y, $h),
            $name,
        );
        $this->pop();
    }

    /**
     * The resolved stops and the function turning a point in the box into a
     * position along the gradient, for the sampled path.
     *
     * @return array{0:array<int,array{0:float,1:array}>,1:callable(float,float):float}|null
     */
    private function gradientRamp(array $gradient, float $w, float $h): ?array
    {
        $type = $gradient['type'] ?? 'linear';

        if ($type === 'conic') {
            $cx    = self::positionAlong($gradient['at'][0] ?? '50%', $w);
            $cy    = self::positionAlong($gradient['at'][1] ?? '50%', $h);
            $from  = $gradient['from'] ?? 0.0;
            $stops = self::resolveStops($gradient, 1.0);

            // CSS measures a conic clockwise from twelve o'clock, and the box
            // has y growing downward, so the arc runs the same way the angle
            // is written rather than mirrored.
            $project = static function (float $px, float $py) use ($cx, $cy, $from): float {
                $angle = rad2deg(atan2($px - $cx, $cy - $py)) - $from;

                return fmod(fmod($angle, 360.0) + 360.0, 360.0) / 360.0;
            };

            return count($stops) < 2 ? null : [$stops, $project];
        }

        if ($type === 'radial') {
            $cx        = self::positionAlong($gradient['at'][0] ?? '50%', $w);
            $cy        = self::positionAlong($gradient['at'][1] ?? '50%', $h);
            [$rx, $ry] = self::radialExtent($gradient, $cx, $cy, $w, $h);

            if ($rx <= 0.0 || $ry <= 0.0) {
                return null;
            }

            $stops   = self::resolveStops($gradient, $rx);
            $project = static fn(float $px, float $py): float => hypot(($px - $cx) / $rx, ($py - $cy) / $ry);

            return count($stops) < 2 ? null : [$stops, $project];
        }

        $angle = $gradient['angle'] ?? 180.0;

        if (($gradient['corner'] ?? null) !== null) {
            [$sx, $sy] = $gradient['corner'];
            $angle     = rad2deg(atan2($sx * $h, -$sy * $w));
        }

        $radians = deg2rad($angle);
        $dx      = sin($radians);
        $dy      = -cos($radians);
        $length  = abs($w * $dx) + abs($h * $dy);

        if ($length <= 0.0) {
            return null;
        }

        $stops = self::resolveStops($gradient, $length);
        $cx    = $w / 2.0;
        $cy    = $h / 2.0;

        $project = static fn(float $px, float $py): float
            => 0.5 + (($px - $cx) * $dx + ($py - $cy) * $dy) / $length;

        return count($stops) < 2 ? null : [$stops, $project];
    }

    /**
     * The color at one position along a resolved stop list.
     *
     * @param  array<int, array{0:float,1:array}> $stops
     * @return array{0:float,1:float,2:float,3:float}
     */
    private static function sampleStops(array $stops, float $t): array
    {
        $first = $stops[0];
        $final = $stops[count($stops) - 1];

        if ($t <= $first[0]) {
            return [...array_slice($first[1], 0, 3), $first[1][3] ?? 1.0];
        }

        if ($t >= $final[0]) {
            return [...array_slice($final[1], 0, 3), $final[1][3] ?? 1.0];
        }

        for ($i = 1, $n = count($stops); $i < $n; $i++) {
            if ($t > $stops[$i][0]) {
                continue;
            }

            $span = $stops[$i][0] - $stops[$i - 1][0];

            return self::mixColor(
                $stops[$i - 1][1],
                $stops[$i][1],
                $span <= 0.0 ? 1.0 : ($t - $stops[$i - 1][0]) / $span,
            );
        }

        return [...array_slice($final[1], 0, 3), $final[1][3] ?? 1.0];
    }

    /**
     * How many samples one shadow mask may hold. A blurred shadow is a smooth
     * field, so under-sampling a large one costs very little; leaving it
     * unbounded lets a 200,000pt box ask for a gigabyte of coverage.
     */
    private const int MAX_SHADOW_SAMPLES = 16384;

    /** How many samples the blur's own reach is worth resolving into. */
    private const float SHADOW_SAMPLES_PER_BLUR = 8.0;

    /**
     * Paint one `box-shadow` layer.
     *
     * The shape is the reference box offset by the shadow's own offset and
     * inflated by its spread. `$hole` is the box the shadow may not paint
     * inside: CSS treats the element as opaque, so an outer shadow is clipped
     * away under its own border box and an inner one is clipped to the inside
     * of the padding box.
     *
     * There is no blur operator in PDF, so a blurred shadow is drawn as a flat
     * color behind a soft mask holding the coverage, computed here.
     *
     * @param array{0:float,1:float,2:float,3:float}       $rgba
     * @param list<array{0:float,1:float}>             $radius   of the shadow shape
     * @param array{0:float,1:float,2:float,3:float,4:array{0:float,1:float},5:array{0:float,1:float},6:array{0:float,1:float},7:array{0:float,1:float}} $hole x, y, w, h then four corner pairs
     */
    public function fillShadow(
        float $x,
        float $y,
        float $w,
        float $h,
        float $blur,
        array $rgba,
        array $radius,
        array $hole,
        bool $inset,
    ): void {
        if ($w <= 0.0 || $h <= 0.0 || ($rgba[3] ?? 1.0) <= 0.0) {
            return;
        }

        $this->deadline->check('painting');

        [$hx, $hy, $hw, $hh] = $hole;
        $hr                  = array_slice($hole, 4);

        // The clip is the hole subtracted from a rectangle that contains both
        // it and the shadow, which the even-odd rule spells as two subpaths.
        $spread = self::blurExtent($blur);
        $left   = min($x - $spread, $hx) - 1.0;
        $top    = min($y - $spread, $hy) - 1.0;
        $right  = max($x + $w + $spread, $hx + $hw) + 1.0;
        $bottom = max($y + $h + $spread, $hy + $hh) + 1.0;

        $this->content .= "q\n";

        if ($inset) {
            $this->roundedPath($hx, $hy, $hw, $hh, self::corners($hr));
        } else {
            $this->content .= sprintf(
                "%.3f %.3f %.3f %.3f re\n",
                $left,
                $this->ty($top, $bottom - $top),
                $right - $left,
                $bottom - $top,
            );
            $this->roundedPath($hx, $hy, $hw, $hh, self::corners($hr));
        }

        $this->content .= $inset ? "W n\n" : "W* n\n";

        // A blur whose box widths all come out at one point does nothing at
        // all, so it goes out as the vector shape rather than as a
        // full-resolution mask of a difference nobody can see.
        if ($spread <= 1.0) {
            $this->fillShadowShape($x, $y, $w, $h, $rgba, $radius, $inset, $hole);
            $this->content .= "Q\n";

            return;
        }

        $this->paintShadowMask($x, $y, $w, $h, $blur, $rgba, $radius, $inset, $hole);
        $this->content .= "Q\n";
    }

    /**
     * A shadow with no blur is an ordinary fill: the shape itself for an outer
     * shadow, and everything the shape leaves uncovered for an inner one.
     *
     * @param array{0:float,1:float,2:float,3:float} $rgba
     * @param list<array{0:float,1:float}>           $radius
     * @param array<int,float>                       $hole
     */
    private function fillShadowShape(
        float $x,
        float $y,
        float $w,
        float $h,
        array $rgba,
        array $radius,
        bool $inset,
        array $hole,
    ): void {
        if (!$inset) {
            $this->fillRect($x, $y, $w, $h, $rgba, $radius);

            return;
        }

        $transparency = $this->beginAlpha($rgba[3] ?? 1.0);
        $this->content .= sprintf("%.4f %.4f %.4f rg\n", $rgba[0], $rgba[1], $rgba[2]);

        // The rectangle the shape is subtracted from has to contain it, or the
        // even-odd rule reads a partial overlap as a hole in the wrong place.
        $left   = min($x, $hole[0]) - 1.0;
        $top    = min($y, $hole[1]) - 1.0;
        $right  = max($x + $w, $hole[0] + $hole[2]) + 1.0;
        $bottom = max($y + $h, $hole[1] + $hole[3]) + 1.0;

        $this->content .= sprintf(
            "%.3f %.3f %.3f %.3f re\n",
            $left,
            $this->ty($top, $bottom - $top),
            $right - $left,
            $bottom - $top,
        );
        $this->roundedPath($x, $y, $w, $h, self::corners($radius));
        $this->content .= "f*\n";
        $this->endAlpha($transparency);
    }

    /**
     * Draw the blurred shadow as a flat color behind a soft mask.
     *
     * @param array{0:float,1:float,2:float,3:float} $rgba
     * @param list<array{0:float,1:float}>           $radius
     * @param array<int,float>                       $hole
     */
    private function paintShadowMask(
        float $x,
        float $y,
        float $w,
        float $h,
        float $blur,
        array $rgba,
        array $radius,
        bool $inset,
        array $hole,
    ): void {
        $extent = self::blurExtent($blur);

        // Both polarities need the mask to reach a blur's distance past the
        // shape. An inner shadow is clipped to the hole, but a mask that
        // stopped at the hole edge would blur against nothing there, and the
        // shadow is at its strongest exactly on that edge.
        [$mx, $my, $mw, $mh] = $inset
            ? [
                $hole[0] - $extent,
                $hole[1] - $extent,
                $hole[2] + 2.0 * $extent,
                $hole[3] + 2.0 * $extent,
            ]
            : [$x - $extent, $y - $extent, $w + 2.0 * $extent, $h + 2.0 * $extent];

        $mw = min($mw, 4.0 * self::MAX_SHADOW_SAMPLES);
        $mh = min($mh, 4.0 * self::MAX_SHADOW_SAMPLES);

        if ($mw <= 0.0 || $mh <= 0.0) {
            return;
        }

        /*
         * The finest thing in the mask is the ramp, and the ramp is as wide
         * as the blur reaches, so a few samples across it is all the detail
         * there is to capture. Sampling a 40pt blur at a point apiece costs a
         * hundred times what it buys, and on a document that repeats the
         * shadow down a few hundred pages it is the whole render.
         */
        $rate  = min(1.0, self::SHADOW_SAMPLES_PER_BLUR / max(1.0, $extent));
        $scale = min($rate, sqrt(self::MAX_SHADOW_SAMPLES / ($mw * $mh)));
        $cols  = max(2, (int) ceil($mw * $scale));
        $rows  = max(2, (int) ceil($mh * $scale));

        // Everything the coverage depends on, with the shape measured from
        // the mask's own origin so the same shadow on another page is the
        // same key.
        $key = implode(':', [
            'shadow', $cols, $rows, $mw, $mh, $blur, $inset ? 1 : 0,
            $x - $mx, $y - $my, $w, $h,
            // Flattened, because a corner is a pair now and a list of arrays
            // stringifies to "Array,Array,Array,Array": two shadows whose
            // radii differ would share one cached mask. Defect GL.
            implode(',', array_merge(...self::corners($radius))), $rgba[0], $rgba[1], $rgba[2],
        ]);

        $name = $this->sampledImage($key, fn(): PdfImage => PdfImage::flat(
            $cols,
            $rows,
            self::shadowCoverage(
                $cols,
                $rows,
                $mw / $cols,
                $mh / $rows,
                $mx,
                $my,
                [$x, $y, $w, $h],
                $radius,
                $blur,
                $inset,
            ),
            [$rgba[0], $rgba[1], $rgba[2]],
        ));

        $transparency = $this->beginAlpha($rgba[3] ?? 1.0);
        $this->content .= sprintf(
            "q %.3f 0 0 %.3f %.3f %.3f cm /%s Do Q\n",
            $mw,
            $mh,
            $mx,
            $this->ty($my, $mh),
            $name,
        );
        $this->endAlpha($transparency);
    }

    /**
     * The coverage field of a blurred rounded rectangle, one byte per sample.
     *
     * A Gaussian is approximated by three box blurs, which is what Skia does
     * and therefore what the browser this is measured against does. The box
     * widths come from matching the combined variance to the target sigma,
     * which CSS puts at half the blur radius.
     *
     * @param array{0:float,1:float,2:float,3:float} $shape  x, y, w, h of the shadow shape
     * @param list<array{0:float,1:float}>           $radius
     */
    private static function shadowCoverage(
        int $cols,
        int $rows,
        float $stepX,
        float $stepY,
        float $originX,
        float $originY,
        array $shape,
        array $radius,
        float $blur,
        bool $inset,
    ): string {
        $field = [];

        for ($row = 0; $row < $rows; $row++) {
            $py   = $originY + ($row + 0.5) * $stepY;
            $line = [];

            for ($col = 0; $col < $cols; $col++) {
                $px       = $originX + ($col + 0.5) * $stepX;
                $inside   = self::roundedCoverage($px, $py, $shape, $radius, max($stepX, $stepY));
                $line[]   = $inset ? 1.0 - $inside : $inside;
            }

            $field[] = $line;
        }

        $sigma = $blur / 2.0;

        foreach (self::boxWidths($sigma / max($stepX, 0.0001)) as $width) {
            $field = self::boxBlurRows($field, $width, $cols, $rows);
        }

        $field = self::transpose($field, $cols, $rows);

        foreach (self::boxWidths($sigma / max($stepY, 0.0001)) as $width) {
            $field = self::boxBlurRows($field, $width, $rows, $cols);
        }

        $field = self::transpose($field, $rows, $cols);

        $out = '';

        for ($row = 0; $row < $rows; $row++) {
            for ($col = 0; $col < $cols; $col++) {
                $out .= chr((int) round(max(0.0, min(1.0, $field[$row][$col])) * 255.0));
            }
        }

        return $out;
    }

    /**
     * How far a blur reaches past the edge of its shape. Three box passes have
     * finite support, so this is exact rather than a truncation.
     */
    private static function blurExtent(float $blur): float
    {
        if ($blur <= 0.0) {
            return 0.0;
        }

        $reach = 0.0;

        foreach (self::boxWidths($blur / 2.0) as $width) {
            $reach += ($width - 1) / 2.0;
        }

        return $reach + 1.0;
    }

    /**
     * Three odd box widths whose combined variance matches a Gaussian's.
     *
     * @return array{0:int,1:int,2:int}
     */
    private static function boxWidths(float $sigma): array
    {
        if ($sigma <= 0.0) {
            return [1, 1, 1];
        }

        $ideal = sqrt(12.0 * $sigma * $sigma / 3.0 + 1.0);
        $lower = (int) floor($ideal);

        if ($lower % 2 === 0) {
            $lower--;
        }

        $lower = max(1, $lower);
        $upper = $lower + 2;

        $count = (int) round(
            (12.0 * $sigma * $sigma - 3.0 * $lower * $lower - 12.0 * $lower - 9.0) / (-4.0 * $lower - 4.0),
        );
        $count = max(0, min(3, $count));

        return [
            $count > 0 ? $lower : $upper,
            $count > 1 ? $lower : $upper,
            $count > 2 ? $lower : $upper,
        ];
    }

    /**
     * How much of one sample the rounded rectangle covers, antialiased over a
     * band one sample wide so a sharp edge does not stair-step before the blur
     * has a chance to smooth it.
     *
     * @param array{0:float,1:float,2:float,3:float} $shape
     * @param list<array{0:float,1:float}>           $radius
     */
    private static function roundedCoverage(float $px, float $py, array $shape, array $radius, float $step): float
    {
        [$x, $y, $w, $h] = $shape;
        [$tl, $tr, $br, $bl] = array_map(
            static fn(array $corner): array => [max(0.0, $corner[0]), max(0.0, $corner[1])],
            $radius,
        );

        $scale = 1.0;

        foreach ([
            [$tl[0] + $tr[0], $w],
            [$bl[0] + $br[0], $w],
            [$tl[1] + $bl[1], $h],
            [$tr[1] + $br[1], $h],
        ] as [$pair, $extent]) {
            if ($pair > $extent && $pair > 0.0) {
                $scale = min($scale, $extent / $pair);
            }
        }

        [$tl, $tr, $br, $bl] = array_map(
            static fn(array $corner): array => [$corner[0] * $scale, $corner[1] * $scale],
            [$tl, $tr, $br, $bl],
        );

        $distance = min($px - $x, $x + $w - $px, $py - $y, $y + $h - $py);

        // Inside a corner's quadrant the edge is the arc, not the sides.
        $corners = [
            [$x + $tl[0], $y + $tl[1], $tl, $px < $x + $tl[0] && $py < $y + $tl[1]],
            [$x + $w - $tr[0], $y + $tr[1], $tr, $px > $x + $w - $tr[0] && $py < $y + $tr[1]],
            [$x + $w - $br[0], $y + $h - $br[1], $br, $px > $x + $w - $br[0] && $py > $y + $h - $br[1]],
            [$x + $bl[0], $y + $h - $bl[1], $bl, $px < $x + $bl[0] && $py > $y + $h - $bl[1]],
        ];

        // How far outside the arc a sample sits, in the ellipse's own scaled
        // space and then back in points. It is exact for a circle, where both
        // halves are the same length, and near enough for an ellipse: the only
        // thing it feeds is a one-sample antialiasing band under a blur.
        foreach ($corners as [$cx, $cy, $r, $in]) {
            if (!$in || ($r[0] <= 0.0 && $r[1] <= 0.0)) {
                continue;
            }

            $reach = hypot(
                $r[0] > 0.0 ? ($px - $cx) / $r[0] : 0.0,
                $r[1] > 0.0 ? ($py - $cy) / $r[1] : 0.0,
            );

            $distance = min($distance, (1.0 - $reach) * min($r[0] ?: $r[1], $r[1] ?: $r[0]));
        }

        $band = max($step, 0.0001);

        return max(0.0, min(1.0, $distance / $band + 0.5));
    }

    /**
     * One box blur pass along every row, with the window centred.
     *
     * Samples off the end of a row repeat the last one rather than reading as
     * zero. An outer shadow's mask is empty at its border either way, but an
     * inner shadow's is solid there, and zero padding would fade it out along
     * the very edge it is supposed to be darkest at.
     *
     * @param  array<int, array<int, float>> $field
     * @return array<int, array<int, float>>
     */
    private static function boxBlurRows(array $field, int $width, int $cols, int $rows): array
    {
        if ($width <= 1) {
            return $field;
        }

        $reach = intdiv($width - 1, 2);

        for ($row = 0; $row < $rows; $row++) {
            $line   = $field[$row];
            $prefix = [0.0];
            $sum    = 0.0;

            for ($col = 0; $col < $cols; $col++) {
                $sum      += $line[$col];
                $prefix[] = $sum;
            }

            $out = [];

            for ($col = 0; $col < $cols; $col++) {
                $from   = max(0, $col - $reach);
                $to     = min($cols, $col + $reach + 1);
                $before = max(0, $reach - $col);
                $after  = max(0, $col + $reach + 1 - $cols);

                $out[] = ($prefix[$to] - $prefix[$from]
                    + $before * $line[0]
                    + $after * $line[$cols - 1]) / $width;
            }

            $field[$row] = $out;
        }

        return $field;
    }

    /**
     * @param  array<int, array<int, float>> $field
     * @return array<int, array<int, float>>
     */
    private static function transpose(array $field, int $cols, int $rows): array
    {
        $out = [];

        for ($col = 0; $col < $cols; $col++) {
            $line = [];

            for ($row = 0; $row < $rows; $row++) {
                $line[] = $field[$row][$col];
            }

            $out[] = $line;
        }

        return $out;
    }

    /**
     * A border band sits inside its border box, so the stroke, which PDF
     * centers on the path, is inset by half its own width. A rounded corner's
     * radius follows the path inwards by the same half width.
     *
     * @param list<array{0:float,1:float}>|array{0:float,1:float,2:float,3:float}|float $radius
     */
    public function strokeRect(
        float $x,
        float $y,
        float $w,
        float $h,
        array $rgb,
        float $lw = 1.0,
        array|float $radius = 0.0,
        string $style = 'solid',
    ): void {
        if ($style === 'double') {
            $third = $lw / 3.0;

            $this->strokeRect($x, $y, $w, $h, $rgb, $third, $radius);
            $this->strokeRect(
                $x + 2.0 * $third,
                $y + 2.0 * $third,
                max(0.0, $w - 4.0 * $third),
                max(0.0, $h - 4.0 * $third),
                $rgb,
                $third,
                array_map(
                    static fn(array $corner): array => [
                        max(0.0, $corner[0] - 2.0 * $third),
                        max(0.0, $corner[1] - 2.0 * $third),
                    ],
                    self::corners($radius),
                ),
            );

            return;
        }

        [$r, $g, $b] = $rgb;
        $transparency = $this->beginAlpha($rgb[3] ?? 1.0);
        $this->content .= sprintf("%.4f %.4f %.4f RG\n%.3f w\n", $r, $g, $b, $lw);

        $half = $lw / 2.0;
        $ix   = $x + $half;
        $iy   = $y + $half;
        $iw   = max(0.0, $w - $lw);
        $ih   = max(0.0, $h - $lw);

        if (!self::rounded($radius)) {
            $dashed = $this->beginDash($style, $lw, 2.0 * ($w + $h));
            $this->content .= sprintf("%.3f %.3f %.3f %.3f re S\n", $ix, $this->ty($iy, $ih), $iw, $ih);
        } else {
            $centre = array_map(
                static fn(array $corner): array => [
                    max(0.0, $corner[0] - $half),
                    max(0.0, $corner[1] - $half),
                ],
                self::corners($radius),
            );

            $drawn   = self::scaledCorners($iw, $ih, $centre);
            $dashed  = $this->beginDashLoop(
                $style,
                $lw,
                self::roundedPerimeter($iw, $ih, $drawn),
                self::topLeftTangentAt($iw, $ih, $drawn),
            );

            $this->roundedPath($ix, $iy, $iw, $ih, $centre);
            $this->content .= "S\n";
        }

        $this->endDash($dashed);
        $this->endAlpha($transparency);
    }

    /**
     * One side of a rounded border whose four sides are not all the same.
     *
     * A uniform border is one path stroked down the middle of the ring, and
     * that cannot carry four different widths, four colors or the join
     * between two of them. So the ring itself is filled here: the border box's
     * rounded path with the padding box's rounded path inside it, filled
     * even-odd, clipped to the wedge this side owns.
     *
     * CSS 2.1 §8.5.4 splits each corner between its two sides along the line
     * from the outer corner to the inner one, and that line is two of the four
     * points of the wedge, so the arcs are cut by the rasteriser rather than
     * by this code: nothing here has to split a Bezier at an angle.
     *
     * **A side whose line style is not solid is the same wedge with a stroke
     * inside it instead of a fill**, which is defect GV. The ring is a filled
     * region and a dash or a double rule is a pattern along a line, so the
     * side's own center line is stroked with the side's own width and the wedge
     * cuts it to this side. That is `strokeRect`'s rounded path, so a `double`
     * border is two rules and a `dashed` one carries whatever phase the uniform
     * spelling carries: one route, one place to fix the phase in.
     *
     * @param array{0:float,1:float,2:float,3:float,4:list<array{0:float,1:float}>} $inner
     * @param list<array{0:float,1:float}>                                          $radius
     * @param array<int,float>                                                      $rgba
     */
    public function fillBorderSide(
        ?string $side,
        float $x,
        float $y,
        float $w,
        float $h,
        array $inner,
        array $radius,
        array $rgba,
        string $style = 'solid',
        float $width = 0.0,
    ): void {
        [$ix, $iy, $iw, $ih, $innerRadius] = $inner;

        $outer = [[$x, $y], [$x + $w, $y], [$x + $w, $y + $h], [$x, $y + $h]];
        $in    = [[$ix, $iy], [$ix + $iw, $iy], [$ix + $iw, $iy + $ih], [$ix, $iy + $ih]];

        // A whole ring in one color needs no wedge and must not have one: a
        // clip edge is antialiased on its own before the fill inside it is,
        // so two of them meeting along a miter leave a seam the ring has no
        // reason to carry. `TJ-corner-sides.html` j9 is four sides of one
        // color and reads 489 pixels off Chrome through the wedges and 231
        // without them, which is its uniform twin j4's own floor.
        $wedge = $side === null ? null : match ($side) {
            'top'    => [$outer[0], $in[0], $in[1], $outer[1]],
            'right'  => [$outer[1], $in[1], $in[2], $outer[2]],
            'bottom' => [$outer[2], $in[2], $in[3], $outer[3]],
            'left'   => [$outer[3], $in[3], $in[0], $outer[0]],
            default  => null,
        };

        if ($side !== null && $wedge === null) {
            return;
        }

        $this->content .= "q\n";

        if ($wedge !== null) {
            foreach ($wedge as $index => [$px, $py]) {
                $this->content .= sprintf("%.3f %.3f %s\n", $px, $this->ty($py), $index === 0 ? 'm' : 'l');
            }

            $this->content .= "h W n\n";
        }

        if ($style !== 'solid' && $width > 0.0) {
            $this->strokeRect($x, $y, $w, $h, $rgba, $width, $radius, $style);
            $this->content .= "Q\n";

            return;
        }

        [$r, $g, $b]  = $rgba;
        $transparency = $this->beginAlpha($rgba[3] ?? 1.0);

        $this->content .= sprintf("%.4f %.4f %.4f rg\n", $r, $g, $b);
        $this->roundedPath($x, $y, $w, $h, self::corners($radius));

        // A box whose borders eat the whole of it has no hole to leave, and an
        // inner path of no width would be read as one by the even-odd rule.
        if ($iw > 0.0 && $ih > 0.0) {
            $this->roundedPath($ix, $iy, $iw, $ih, self::corners($innerRadius));
        }

        $this->content .= "f*\n";
        $this->endAlpha($transparency);
        $this->content .= "Q\n";
    }

    /**
     * Stroke only the named edges. Collapsed table borders need this: a shared
     * grid line must be drawn once, not once per adjoining cell.
     *
     * @param string[] $edges
     */
    public function strokeEdges(
        float $x,
        float $y,
        float $w,
        float $h,
        array $rgb,
        float $lw,
        array $edges,
        string $style = 'solid',
        bool $inset = true,
    ): void {
        if ($edges === [] || $lw <= 0.0) {
            return;
        }

        // CSS paints a border inside its box, but a collapsed table's grid
        // line is shared by the cells either side of it and straddles the edge
        // instead, so that caller asks for no inset at all.
        $lead = $inset ? $lw / 2.0 : 0.0;

        if ($style === 'double') {
            $third = $lw / 3.0;

            foreach ([$third / 2.0, $lw - $third / 2.0] as $offset) {
                $this->strokeEdgeLines(
                    $x, $y, $w, $h, $rgb, $third, $edges, 'solid',
                    $offset - ($inset ? 0.0 : $lw / 2.0),
                );
            }

            return;
        }

        $this->strokeEdgeLines($x, $y, $w, $h, $rgb, $lw, $edges, $style, $lead);
    }

    /**
     * One line per edge, `$offset` in from the border box on the edge's own
     * axis. A dotted edge is shortened by half a width at each end, because a
     * round dot is centered on the point the dash pattern lands on and CSS
     * starts the first one at the corner.
     *
     * @param string[] $edges
     */
    private function strokeEdgeLines(
        float $x,
        float $y,
        float $w,
        float $h,
        array $rgb,
        float $lw,
        array $edges,
        string $style,
        float $offset,
    ): void {
        [$r, $g, $b] = $rgb;
        $transparency = $this->beginAlpha($rgb[3] ?? 1.0);
        $this->content .= sprintf("%.4f %.4f %.4f RG\n%.3f w\n", $r, $g, $b, $lw);

        $trim  = $style === 'dotted' ? $lw / 2.0 : 0.0;
        $lines = [
            'top'    => [$x + $trim, $y + $offset, $x + $w - $trim, $y + $offset, $w],
            'bottom' => [$x + $trim, $y + $h - $offset, $x + $w - $trim, $y + $h - $offset, $w],
            'left'   => [$x + $offset, $y + $trim, $x + $offset, $y + $h - $trim, $h],
            'right'  => [$x + $w - $offset, $y + $trim, $x + $w - $offset, $y + $h - $trim, $h],
        ];

        foreach ($edges as $edge) {
            if (!isset($lines[$edge])) {
                continue;
            }

            [$x1, $y1, $x2, $y2, $extent] = $lines[$edge];

            if ($style === 'dotted' && self::dashRun($lw, $lw, $extent)[0] <= self::MAX_DOTS) {
                $this->paintDots($x1, $y1, $x2, $y2, $lw, $extent, $rgb);

                continue;
            }

            $dashed = $this->beginDash($style === 'dotted' ? 'dotted' : $style, $lw, $extent);

            $this->content .= sprintf(
                "%.3f %.3f m %.3f %.3f l S\n",
                $x1,
                $this->ty($y1),
                $x2,
                $this->ty($y2),
            );

            $this->endDash($dashed);
        }

        $this->endAlpha($transparency);
    }

    /**
     * A CSS dot is a filled circle one border width across, and they are drawn
     * rather than dashed because PDF's only spelling for a round dot is a
     * zero-length dash under a round cap, which renderers space by their own
     * arithmetic: pdfium walks a 17.625pt period as 17.72 and drops the last
     * dot off a 150pt edge. A circle lands where CSS says it lands.
     *
     * One circle per dot means the loop count comes from the author: a
     * 0.001pt border on a 200,000pt edge asks for a hundred million of them,
     * so past `MAX_DOTS` the caller falls back to a dash array, which costs
     * one operator whatever the count.
     *
     * @param array{0:float,1:float,2:float,3?:float} $rgb
     */
    private function paintDots(
        float $x1,
        float $y1,
        float $x2,
        float $y2,
        float $lw,
        float $extent,
        array $rgb,
    ): void {
        [$count, $gap] = self::dashRun($lw, $lw, $extent);
        $radius        = $lw / 2.0;
        $step          = $lw + $gap;

        [$r, $g, $b] = $rgb;
        $this->content .= sprintf("%.4f %.4f %.4f rg\n", $r, $g, $b);

        for ($i = 0; $i < $count; $i++) {
            $t  = $count > 1 ? $i * $step : 0.0;
            $cx = $x1 + ($x2 > $x1 ? $t : 0.0);
            $cy = $y1 + ($y2 > $y1 ? $t : 0.0);

            $this->roundedPath(
                $cx - $radius,
                $cy - $radius,
                $lw,
                $lw,
                array_fill(0, 4, [$radius, $radius]),
            );
            $this->content .= "f\n";
        }
    }

    /**
     * Set the dash pattern a `dashed` or `dotted` edge of this length needs,
     * and say whether the graphics state was pushed.
     *
     * Chrome draws a dash twice the border width with a gap of one width, and
     * a dot one width across with a gap of one width, then stretches the gap
     * so a whole number of them spans the edge with a dash at each end.
     * Measured off Chrome's own output: a 3pt dashed border on a 150pt edge is
     * 17 dashes of 6pt separated by 3pt, and a 6pt one is 9 dashes of 12pt
     * separated by 5.25pt.
     */
    private function beginDash(string $style, float $lw, float $extent): bool
    {
        if (($style !== 'dashed' && $style !== 'dotted') || $lw <= 0.0 || $extent <= 0.0) {
            return false;
        }

        $mark    = $style === 'dashed' ? 2.0 * $lw : $lw;
        [, $gap] = self::dashRun($mark, $lw, $extent);

        $this->content .= sprintf("q\n[%.3f %.3f] 0 d\n", $mark, $gap);

        return true;
    }

    /**
     * The dash pattern a CLOSED rounded path of this length needs, with the
     * phase that puts a mark at the top-left corner's top tangent.
     *
     * An open edge carries a mark at each end, so `dashRun()` fits `n` marks
     * and `n - 1` gaps. A closed path has one end, so it fits `n` whole
     * periods instead and the pattern meets itself. Measured off Chrome on
     * `TM-dash-phase.html`: its dash count is `round(perimeter / (mark + lw))`
     * on all six rounded shapes, and its first dash starts EXACTLY at the
     * corner tangent on all six.
     *
     * `$tangent` is where along the path the top-left corner's top tangent
     * sits, and the phase follows: PDF measures the pattern from the path's
     * start, so a mark that ends at some distance begins one mark earlier.
     *
     * **A DASH STARTS AT THE TANGENT AND A DOT IS CENTRED ON IT**, which is
     * defect HB and is two rules rather than one. On `TO-dot-phase.html`
     * Chrome's first dot begins at exactly `r - lw / 2` on all six rounded
     * shapes, read off the content stream to three decimals, where its first
     * dash on the same six boxes begins at exactly `r`.
     */
    private function beginDashLoop(string $style, float $lw, float $extent, float $tangent): bool
    {
        if (($style !== 'dashed' && $style !== 'dotted') || $lw <= 0.0 || $extent <= 0.0) {
            return false;
        }

        $mark   = $style === 'dashed' ? 2.0 * $lw : $lw;
        $count  = max(1, (int) round($extent / ($mark + $lw)));
        $period = $extent / $count;
        $gap    = max(0.0, $period - $mark);

        if ($gap <= 0.0) {
            return false;
        }

        $ends  = $style === 'dotted' ? $tangent + $mark / 2.0 : $tangent;
        $phase = fmod($mark - $ends, $period);

        if ($phase < 0.0) {
            $phase += $period;
        }

        $this->content .= sprintf("q\n[%.3f %.3f] %.3f d\n", $mark, $gap, $phase);

        return true;
    }

    private function endDash(bool $pushed): void
    {
        if ($pushed) {
            $this->content .= "Q\n";
        }
    }

    /**
     * How many marks of `$dash` fit along `$extent`, and the gap between them.
     *
     * The ideal gap is one border width, and it is then stretched or squeezed
     * so a whole number of marks spans the edge with one at each end. Measured
     * off Chrome: a 3pt dashed border on a 150pt edge is 17 dashes of 6pt
     * separated by 3pt, a 6pt one is 9 dashes of 12pt separated by 5.25pt, and
     * a 9pt dotted one is 9 dots separated by 8.625pt.
     *
     * @return array{0:int,1:float}
     */
    private static function dashRun(float $dash, float $lw, float $extent): array
    {
        $count = max(1, (int) round(($extent + $lw) / ($dash + $lw)));
        $gap   = $count > 1 ? max(0.0, ($extent - $count * $dash) / ($count - 1)) : $lw;

        return [$count, $gap];
    }

    /**
     * Apply a CSS transform list around a box.
     *
     * The list comes to ONE matrix and {@see transformMatrix()} is what
     * composes it. The same helper answers where a box lands for
     * {@see Html::fallsOffTheSheet()}, which decides whether a piece can reach
     * the paper at all: composing the list in two places would let the cull
     * and the painter drift apart, and a cull that disagrees with the painter
     * deletes ink somebody can see.
     *
     * Every push must be balanced by a pop or the rest of the page inherits
     * the matrix.
     *
     * @param array<int,array{0:string,1:float,2:float}> $ops
     */
    public function pushTransform(array $ops, float $x, float $y, float $w, float $h, string $origin = '50% 50%'): void
    {
        $this->content .= "q\n";

        if ($ops === []) {
            return;
        }

        [$ox, $oy] = self::originPoint($origin, $w, $h);
        $px = $x + $ox;
        $py = $this->ty($y + $oy);

        // Moving to the turning point and back stays two operators of its own
        // rather than being folded into the matrix. Folded, the translation
        // has to be worked out from coefficients at full precision while the
        // file carries them rounded to six places, and the box then turns
        // about a point five hundred-thousandths of a point away from its own
        // origin. That is under a ten-thousandth of a pixel and it still
        // repaints 101 of `RR-outoflow-effects.html`'s pixels one gray level
        // along, which is a picture moving for no reason anybody asked for.
        $this->content .= sprintf("1 0 0 1 %.4f %.4f cm\n", $px, $py);

        [$a, $b, $c, $d, $e, $f] = self::transformMatrix($ops, true);

        $this->content .= sprintf("%.6f %.6f %.6f %.6f %.6f %.6f cm\n", $a, $b, $c, $d, $e, $f);
        $this->content .= sprintf("1 0 0 1 %.4f %.4f cm\n", -$px, -$py);
    }

    /**
     * The one matrix a CSS transform list comes to, about the origin.
     *
     * CSS reads the list right to left: the LAST function in it moves the box
     * first. Written as separate `cm` operators that came out right for free,
     * because a `cm` concatenates onto the state already there and the last
     * one issued is the first one a point meets. Composed by hand it has to be
     * spelled out, so the list is folded from its end.
     *
     * `$upward` says the caller counts y upward, which is PDF's page space
     * rather than CSS's. The two spaces differ by a reflection in the
     * horizontal axis through the turning point, and reflecting each function
     * and then multiplying gives the same answer as multiplying and then
     * reflecting once, which is why this costs one pass at the end rather than
     * a second set of formulas.
     *
     * @param  array<int,array{0:string,1:float,2:float}> $ops
     * @return array{0:float,1:float,2:float,3:float,4:float,5:float} a b c d e f
     */
    public static function transformMatrix(array $ops, bool $upward = false): array
    {
        $m = [1.0, 0.0, 0.0, 1.0, 0.0, 0.0];

        foreach (array_reverse($ops) as $op) {
            $m = self::composeMatrix($m, self::opMatrix($op));
        }

        return $upward ? [$m[0], -$m[1], -$m[2], $m[3], $m[4], -$m[5]] : $m;
    }

    /**
     * The same matrix turning about `($px, $py)` rather than about the origin:
     * move the point to it, apply the matrix, move it back.
     *
     * @param  array{0:float,1:float,2:float,3:float,4:float,5:float} $m
     * @return array{0:float,1:float,2:float,3:float,4:float,5:float}
     */
    public static function turnAbout(array $m, float $px, float $py): array
    {
        return [
            $m[0],
            $m[1],
            $m[2],
            $m[3],
            $m[4] + $px - ($px * $m[0] + $py * $m[2]),
            $m[5] + $py - ($px * $m[1] + $py * $m[3]),
        ];
    }

    /**
     * The matrix that applies `$first` and then `$then`.
     *
     * @param  array{0:float,1:float,2:float,3:float,4:float,5:float} $first
     * @param  array{0:float,1:float,2:float,3:float,4:float,5:float} $then
     * @return array{0:float,1:float,2:float,3:float,4:float,5:float}
     */
    public static function composeMatrix(array $first, array $then): array
    {
        return [
            $first[0] * $then[0] + $first[1] * $then[2],
            $first[0] * $then[1] + $first[1] * $then[3],
            $first[2] * $then[0] + $first[3] * $then[2],
            $first[2] * $then[1] + $first[3] * $then[3],
            $first[4] * $then[0] + $first[5] * $then[2] + $then[4],
            $first[4] * $then[1] + $first[5] * $then[3] + $then[5],
        ];
    }

    /**
     * One CSS transform function as a matrix, in CSS coordinates, about the
     * origin. A function this does not know turns nothing, which is what the
     * switch this replaced did with an unrecognized name.
     *
     * @param  array{0:string,1:float,2:float} $op
     * @return array{0:float,1:float,2:float,3:float,4:float,5:float}
     */
    private static function opMatrix(array $op): array
    {
        return match ($op[0]) {
            'translate' => [1.0, 0.0, 0.0, 1.0, $op[1], $op[2]],
            'scale'     => [$op[1], 0.0, 0.0, $op[2], 0.0, 0.0],
            'rotate'    => [
                cos(deg2rad($op[1])),
                sin(deg2rad($op[1])),
                -sin(deg2rad($op[1])),
                cos(deg2rad($op[1])),
                0.0,
                0.0,
            ],
            'skew' => [1.0, tan(deg2rad($op[2])), tan(deg2rad($op[1])), 1.0, 0.0, 0.0],
            'matrix' => array_map(static fn($v): float => (float) $v, $op[3] ?? [1.0, 0.0, 0.0, 1.0, 0.0, 0.0]),
            default  => [1.0, 0.0, 0.0, 1.0, 0.0, 0.0],
        };
    }

    /** @return array{0:float,1:float} */
    public static function originPoint(string $origin, float $w, float $h): array
    {
        $parts = preg_split('/\s+/', trim($origin)) ?: ['50%', '50%'];

        $axis  = function (string $token, float $extent, float $default): float {
            $token = strtolower($token);

            return match ($token) {
                'left', 'top'     => 0.0,
                'right', 'bottom' => $extent,
                'center'          => $extent / 2,
                default           => str_ends_with($token, '%')
                    ? (float) rtrim($token, '%') / 100.0 * $extent
                    : (is_numeric($token) ? (float) $token * 0.75 : $default),
            };
        };

        return [
            $axis($parts[0] ?? '50%', $w, $w / 2),
            $axis($parts[1] ?? ($parts[0] ?? '50%'), $h, $h / 2),
        ];
    }

    /** Constant alpha and blend mode for a subtree, via an ExtGState resource. */
    public function pushOpacity(float $alpha, string $blend = 'normal'): void
    {
        $this->content .= "q\n";

        if ($alpha >= 1.0 && $blend === 'normal') {
            return;
        }

        $this->content .= sprintf("/%s gs\n", $this->alphaState($alpha, $blend));
    }

    /**
     * Clip everything drawn until the matching pop to this rectangle.
     * This is how `overflow: hidden` is honored.
     */
    /** @param list<array{0:float,1:float}>|array{0:float,1:float,2:float,3:float}|float $radius */
    public function pushClip(float $x, float $y, float $w, float $h, array|float $radius = 0.0): void
    {
        $this->content .= "q\n";

        if (self::rounded($radius)) {
            $this->roundedPath($x, $y, $w, $h, self::corners($radius));
        } else {
            $this->content .= sprintf("%.3f %.3f %.3f %.3f re\n", $x, $this->ty($y, $h), $w, $h);
        }

        // W adds the path to the clip; n ends it without painting.
        $this->content .= "W n\n";
    }


    /**
     * `clip-path`, as a native PDF clipping path over everything drawn until
     * the matching pop.
     *
     * The reference box is the rectangle given, which is the border box:
     * that is `clip-path`'s initial `border-box` and the only geometry box
     * this reads. A shape whose numbers come out degenerate clips nothing
     * rather than clipping everything away, because a document that names a
     * shape the writer cannot build should lose the decoration and not the
     * content.
     *
     * @param array<string,mixed> $shape {@see Node::$clipPath}
     */
    public function pushClipPath(array $shape, float $x, float $y, float $w, float $h): void
    {
        $this->content .= "q\n";

        match ($shape['shape'] ?? '') {
            'inset'   => $this->insetPath($shape, $x, $y, $w, $h),
            'circle'  => $this->ellipsePath($shape, $x, $y, $w, $h, true),
            'ellipse' => $this->ellipsePath($shape, $x, $y, $w, $h, false),
            'polygon' => $this->polygonPath($shape, $x, $y, $w, $h),
            default   => $this->content .= sprintf("%.3f %.3f %.3f %.3f re\n", $x, $this->ty($y, $h), $w, $h),
        };

        $this->content .= "W n\n";
    }

    /** One `clip-path` component against the axis it resolves on. */
    private static function clipValue(array $part, float $basis): float
    {
        return ($part['pct'] ?? false) ? $basis * $part['v'] / 100.0 : (float) $part['v'];
    }

    /** @param array<string,mixed> $shape */
    private function insetPath(array $shape, float $x, float $y, float $w, float $h): void
    {
        [$top, $right, $bottom, $left] = $shape['edges'];

        $t = self::clipValue($top, $h);
        $r = self::clipValue($right, $w);
        $b = self::clipValue($bottom, $h);
        $l = self::clipValue($left, $w);

        $insetW = max(0.0, $w - $l - $r);
        $insetH = max(0.0, $h - $t - $b);

        if ($shape['radii'] === []) {
            $this->content .= sprintf("%.3f %.3f %.3f %.3f re\n", $x + $l, $this->ty($y + $t, $insetH), $insetW, $insetH);

            return;
        }

        $this->roundedPath(
            $x + $l,
            $y + $t,
            $insetW,
            $insetH,
            array_map(
                static fn(array $part): array => array_fill(0, 2, self::clipValue($part, $w)),
                $shape['radii'],
            ),
        );
    }

    /**
     * A circle or an ellipse as four Bezier arcs, the same 0.5523 the rounded
     * rectangle uses.
     *
     * A circle's percentage radius resolves against the diagonal over root
     * two, which is what CSS Shapes calls the reference box's own size, and an
     * ellipse's two resolve against the two axes.
     *
     * @param array<string,mixed> $shape
     */
    private function ellipsePath(array $shape, float $x, float $y, float $w, float $h, bool $circle): void
    {
        $diagonal = sqrt($w * $w + $h * $h) / M_SQRT2;

        if ($circle) {
            $rx = $ry = self::clipExtent($shape['r'], $diagonal, min($w, $h) / 2.0, max($w, $h) / 2.0);
        } else {
            $rx = self::clipExtent($shape['rx'], $w, $w / 2.0, $w / 2.0);
            $ry = self::clipExtent($shape['ry'], $h, $h / 2.0, $h / 2.0);
        }

        if ($rx <= 0.0 || $ry <= 0.0) {
            $this->content .= sprintf("%.3f %.3f %.3f %.3f re\n", $x, $this->ty($y, $h), $w, $h);

            return;
        }

        $cx = $x + self::clipValue($shape['at'][0], $w);
        $cy = $this->ty($y + self::clipValue($shape['at'][1], $h));

        $kx = $rx * 0.5523;
        $ky = $ry * 0.5523;

        $this->content .= sprintf("%.3f %.3f m\n", $cx + $rx, $cy);
        $this->content .= sprintf("%.3f %.3f %.3f %.3f %.3f %.3f c\n", $cx + $rx, $cy + $ky, $cx + $kx, $cy + $ry, $cx, $cy + $ry);
        $this->content .= sprintf("%.3f %.3f %.3f %.3f %.3f %.3f c\n", $cx - $kx, $cy + $ry, $cx - $rx, $cy + $ky, $cx - $rx, $cy);
        $this->content .= sprintf("%.3f %.3f %.3f %.3f %.3f %.3f c\n", $cx - $rx, $cy - $ky, $cx - $kx, $cy - $ry, $cx, $cy - $ry);
        $this->content .= sprintf("%.3f %.3f %.3f %.3f %.3f %.3f c\n", $cx + $kx, $cy - $ry, $cx + $rx, $cy - $ky, $cx + $rx, $cy);
        $this->content .= "h\n";
    }

    /** A radius that may be a length, a percentage, or one of the two keywords. */
    private static function clipExtent(array $part, float $basis, float $closest, float $farthest): float
    {
        return match ($part['side'] ?? null) {
            'closest'  => $closest,
            'farthest' => $farthest,
            default    => self::clipValue($part, $basis),
        };
    }

    /** @param array<string,mixed> $shape */
    private function polygonPath(array $shape, float $x, float $y, float $w, float $h): void
    {
        foreach ($shape['points'] as $i => [$px, $py]) {
            $this->content .= sprintf(
                "%.3f %.3f %s\n",
                $x + self::clipValue($px, $w),
                $this->ty($y + self::clipValue($py, $h)),
                $i === 0 ? 'm' : 'l',
            );
        }

        $this->content .= "h\n";
    }

    /** Constant alpha and blend mode for a subtree, via an ExtGState. */
    public function pushBlend(string $mode): void
    {
        $this->content .= "q\n";
        $mode          = self::BLEND_MODES[strtolower($mode)] ?? null;

        if ($mode === null) {
            return;
        }

        $name = 'BM' . count($this->blends);

        foreach ($this->blends as $existing => $value) {
            if ($value === $mode) {
                $name = $existing;
                break;
            }
        }

        $this->blendWrites++;
        $this->blends[$name] = $mode;
        $this->content       .= sprintf("/%s gs\n", $name);
    }

    /**
     * A luminosity soft mask over everything drawn until the matching pop.
     *
     * CSS masks a box by its mask image's **alpha** when the source is an
     * image, which is what a gradient is, and PDF's alpha soft mask is thinly
     * supported where its luminosity one is everywhere. So the alpha travels
     * as a gray: {@see self::maskGradient()} puts each stop's own alpha on all
     * three channels, and the luminosity of a gray is that number back again.
     *
     * The group's backdrop is black, so a mask covers exactly what `$paint`
     * drew and everything a descendant paints outside that is hidden. Which
     * rectangles `$paint` draws into is the caller's, because that is where
     * `mask-size`, `mask-position` and `mask-repeat` are read.
     *
     * `$key` says when two masks are the same mask, and it has to describe the
     * **request** rather than what the request painted: a shading takes a
     * fresh resource name every time it is registered, so two identical masks
     * would compare unequal on that alone.
     *
     * @param callable(self): void $paint
     */
    public function pushSoftMask(string $key, float $x, float $y, float $w, float $h, callable $paint): void
    {
        $this->content .= "q\n";

        if ($w <= 0.0 || $h <= 0.0) {
            return;
        }

        $this->deadline->check('painting');

        /*
         * The same mask over the same box is one mask, and it is reached
         * twice on every masked box that has anything inside it: once for the
         * box's own fragment and once for each descendant's, since a mask
         * covers a subtree and a fragment carries its ancestors' effects.
         */
        $bbox = [$x, $this->ty($y, $h), $w, $h];
        $key  = json_encode([$key, $bbox]);

        foreach ($this->softMasks as $existing => $held) {
            if ($held['key'] === $key) {
                $this->content .= sprintf("/%s gs\n", $existing);

                return;
            }
        }

        // The mask is a stream of its own, so the painter draws into a
        // diverted buffer rather than onto the page. Everything it registers
        // on the way, a shading or a sampled image, is a document resource
        // already and needs nothing more here.
        $outer         = $this->content;
        $this->content = '';

        try {
            $paint($this);
            $painted = $this->content;
        } finally {
            $this->content = $outer;
        }

        if ($painted === '') {
            return;
        }

        $name                   = 'MK' . count($this->softMasks);
        $this->softMasks[$name] = ['key' => $key, 'bbox' => $bbox, 'content' => $painted];
        $this->content          .= sprintf("/%s gs\n", $name);
    }

    /**
     * One gradient layer of a mask, drawn into the stream a
     * {@see self::pushSoftMask()} painter is diverting.
     *
     * `alpha` is the mode an image source takes, and the ramp it needs is the
     * source's own alpha on all three channels. `luminance` needs no rewrite
     * at all: a luminosity mask reads what is drawn, and drawing the gradient
     * in its own colors over the group's black backdrop gives luminance times
     * alpha, which is the number CSS Masking asks for.
     *
     * @param array<string,mixed> $gradient
     */
    public function maskGradient(array $gradient, string $mode, float $x, float $y, float $w, float $h): void
    {
        $stops = $gradient['stops'] ?? [];

        if (count($stops) < 2) {
            return;
        }

        $this->fillGradient(
            $mode === 'luminance' ? $gradient : [...$gradient, 'stops' => self::alphaAsGrey($stops)],
            $x,
            $y,
            $w,
            $h,
        );
    }

    /**
     * One picture layer of a mask, on the same terms as
     * {@see self::maskGradient()}.
     *
     * In `alpha` mode the mask is the picture's own alpha channel, which is
     * already carried beside it as a soft mask of its own and is a gray image
     * once it is read back out. A picture with no alpha channel is opaque
     * everywhere, so the mask over its tile is white.
     */
    public function maskImage(PdfImage $image, string $mode, float $x, float $y, float $w, float $h): void
    {
        if ($mode === 'luminance') {
            $this->drawImage($image, $x, $y, $w, $h);

            return;
        }

        $alpha = $image->alphaChannel();

        if ($alpha === null) {
            $this->fillRect($x, $y, $w, $h, [1.0, 1.0, 1.0]);

            return;
        }

        $this->drawImage($alpha, $x, $y, $w, $h);
    }

    /**
     * Draw everything until the matching {@see closeGroup()} into a
     * transparency group of its own rather than straight onto the page.
     *
     * Marked content numbers from zero per content stream, so a group counts
     * its own ids against a parent-tree key of its own and the sequences the
     * page has open around it are set aside: a `BDC` opened on the page cannot
     * be closed inside a form XObject.
     */
    public function beginGroup(): void
    {
        $this->groupStack[] = [
            'content' => $this->content,
            'stream'  => $this->markStream,
            'marked'  => $this->marked,
            'alphas'  => $this->alphaStateWrites(),
            'blends'  => $this->blendWrites,
        ];

        $this->content    = '';
        $this->marked     = [];
        $this->markStream = --$this->groupSerial;
    }

    /**
     * Close the group and say how to draw it: as a form XObject under `name`,
     * or straight back onto the page as `inline`, and neither where it drew
     * nothing at all.
     *
     * The caller pushes the compositing the group is for between this call and
     * whichever of the two it draws, so that the alpha, the blend mode and the
     * soft mask apply to the group's result instead of to each drawing inside
     * it.
     *
     * **The box is widened to the whole page**, because a form XObject clips
     * to its own `/BBox` and a subtree paints outside its box every time a
     * descendant overflows, a shadow spreads or an outline sits proud of an
     * edge. What the box the caller names is for is the group that a transform
     * moves onto the paper from outside it, which the page alone would cut.
     *
     * **`$bbox` is for a caller whose content is not in page coordinates.** A
     * form XObject's `/BBox` is in the space the `Do` executes in, and an SVG
     * subtree is drawn under a `cm` that puts it in the document's own user
     * units with y running the other way, so the page rectangle this method
     * derives is the wrong rectangle there. Such a caller passes the box it
     * wants, already in that space and already in PDF's own order.
     *
     * **`$outerAlpha` is the constant alpha the caller is about to push around
     * the result**, and it decides one thing only: whether content that set an
     * alpha of its own may go back on the page inline. It may not, because
     * PDF's `/ca` replaces rather than multiplies. With no outer alpha there is
     * nothing to lose and the content goes back as it was, which is what keeps
     * a blended drawing a drawing rather than making it an isolated group.
     *
     * **`$isolate` says the group is there for what it hides rather than for
     * what it composites**, so a single drawing keeps it. A blended drawing put
     * back inline blends with whatever is already on the page, which is the one
     * case where the group and the drawing are not the same picture.
     *
     * @param  array{0:float,1:float,2:float,3:float}  $box  x, y, w, h in CSS coordinates
     * @param  ?array{0:float,1:float,2:float,3:float} $bbox left, bottom, right, top, verbatim
     * @return array{name:?string,inline:string}
     */
    public function closeGroup(array $box, ?array $bbox = null, float $outerAlpha = 1.0, bool $isolate = false): array
    {
        $inner = $this->content;
        $mark  = $this->markStream;
        $held  = array_pop($this->groupStack);

        $this->content    = $held['content'];
        $this->marked     = $held['marked'];
        $this->markStream = $held['stream'];

        // PDF's `/ca` and `/CA` are graphics state parameters, so a nested one
        // REPLACES the alpha around it rather than multiplying with it. That is
        // what a transparency group is for: the group's own drawings use their
        // alphas against each other and the result meets the page at the alpha
        // in force at the `Do`. Content that set one of its own therefore
        // cannot go back on the page inline UNDER AN OUTER ALPHA, or that alpha
        // is thrown away: `SK-svg-opacity.html` k7 is an `opacity: 0.5` over a
        // `fill-opacity: 0.5` reading a half where Chrome reads a quarter.
        //
        // Under no outer alpha it still can, and that half is measured rather
        // than assumed: forbidding it outright turned a blended faded drawing
        // into an isolated group and moved 1,004 pixels of `cdoc-99-520`, which
        // is a picture change with nothing asking for it.
        $setsOwnAlpha = $outerAlpha < 1.0 && $this->alphaStateWrites() > $held['alphas'];

        // A blend inside the group is the other reason content cannot go back
        // inline, and it holds under no outer alpha at all: inline means the
        // blend meets whatever is already on the page, where the group is what
        // keeps the blend inside the box that asked to be composited as one.
        // CSS says that box is isolated, so its descendants blend with each
        // other and with nothing under it. `SN-blend-nested.html` n2 is a
        // `multiply` inside an `opacity: 0.5` wrapper over a sibling it must
        // not see.
        $setsOwnBlend = $this->blendWrites > $held['blends'];

        if (trim($inner) === '') {
            return ['name' => null, 'inline' => ''];
        }

        /*
         * A tagged document keeps the group whatever it holds. Marked content
         * numbers from zero per stream, so putting a sequence back on the page
         * would need its ids renumbered against the page's counter and the
         * `BDC` it already wrote rewritten with them.
         */
        if ($this->structure === null && self::drawsOnce($inner) && !$setsOwnAlpha && !$setsOwnBlend && !$isolate) {
            return ['name' => null, 'inline' => $inner];
        }

        [$x, $y, $w, $h] = $box;

        $left   = min(0.0, $x);
        $right  = max($this->pageWidth, $x + $w);
        $bottom = $this->ty(max($this->pageHeight, $y + $h));
        $top    = $this->ty(min(0.0, $y));

        $name                = 'GP' . count($this->groups);
        $this->groups[$name] = [
            'bbox'    => $bbox ?? [$left, $bottom, $right, $top],
            'content' => $inner,
            'mark'    => $mark,
        ];

        return ['name' => $name, 'inline' => ''];
    }

    /** Paint a form XObject registered earlier, at the current graphics state. */
    public function drawGroup(string $name): void
    {
        $this->content .= sprintf("/%s Do\n", $name);
    }

    /**
     * Whether this stream puts ink down at most once.
     *
     * A group exists so that a subtree's drawings composite against each other
     * before the result meets the backdrop, and with one drawing there is
     * nothing to composite against: the two answers are the same picture. So
     * that content goes back on the page as it is, which keeps a faded line of
     * text or a masked logo the drawing it was, spares the file a form
     * XObject, and keeps it out of pdfium's separate glyph grid-fitting for
     * form XObjects, which round 29 measured at 0.09 of a pixel.
     *
     * Strings are blanked before the operators are counted, because a `(Tj)`
     * an author wrote is text and not an operator.
     */
    private static function drawsOnce(string $content): bool
    {
        $bare = preg_replace('/\((?:\\\\.|[^\\\\()])*\)/s', '()', $content) ?? $content;

        return preg_match_all('/(?<![A-Za-z0-9*\'"])(?:f\*?|F|S|s|B\*?|b\*?|sh|Do|Tj|TJ)(?![A-Za-z0-9*])/', $bare) <= 1;
    }

    /**
     * A gradient's stops with every color replaced by its own alpha, so the
     * ramp a shading paints is the ramp the mask needs.
     *
     * A stop with no color is an interpolation hint and carries no alpha of
     * its own, so it travels unchanged.
     *
     * @param  array<int,array{0:float|string|null,1:?array{0:float,1:float,2:float,3?:float}}> $stops
     * @return array<int,array{0:float|string|null,1:?array{0:float,1:float,2:float,3:float}}>
     */
    private static function alphaAsGrey(array $stops): array
    {
        return array_map(
            static function (array $stop): array {
                if ($stop[1] === null) {
                    return $stop;
                }

                $alpha = $stop[1][3] ?? 1.0;

                return [$stop[0], [$alpha, $alpha, $alpha, 1.0]];
            },
            $stops,
        );
    }

    /**
     * Whether the writer can name this blend mode, which is what says a box
     * asks for one at all.
     *
     * `normal` is not in the table because it is the absence of a blend, and
     * neither is a keyword CSS does not define: the box tree keeps whatever the
     * declaration said, so a caller that has to know what will actually be
     * written asks here rather than comparing against `'normal'`.
     */
    public static function blendable(string $mode): bool
    {
        return isset(self::BLEND_MODES[strtolower($mode)]);
    }

    private const array BLEND_MODES = [
        'multiply'    => 'Multiply',
        'screen'      => 'Screen',
        'overlay'     => 'Overlay',
        'darken'      => 'Darken',
        'lighten'     => 'Lighten',
        'color-dodge' => 'ColorDodge',
        'color-burn'  => 'ColorBurn',
        'hard-light'  => 'HardLight',
        'soft-light'  => 'SoftLight',
        'difference'  => 'Difference',
        'exclusion'   => 'Exclusion',
        'hue'         => 'Hue',
        'saturation'  => 'Saturation',
        'color'       => 'Color',
        'luminosity'  => 'Luminosity',
    ];

    public function pop(): void
    {
        $this->content .= "Q\n";
    }

    /**
     * The four corners in CSS order: top-left, top-right, bottom-right,
     * bottom-left. PDF's y axis runs the other way, so the path is walked
     * from the bottom-left of the flipped rect and each corner picks up the
     * radius belonging to the CSS corner it lands on.
     *
     * **Every corner is a PAIR**, a horizontal half measured along the
     * horizontal edge and a vertical half measured down the vertical one,
     * because a PDF corner is a Bezier curve and an ellipse is the same curve
     * with a different control distance on each axis. Defect GL.
     *
     * @param list<array{0:float,1:float}> $radii
     */
    private function roundedPath(float $x, float $y, float $w, float $h, array $radii): void
    {
        [$tl, $tr, $br, $bl] = self::scaledCorners($w, $h, $radii);

        $k = 0.5523;
        $b = $this->ty($y, $h);
        $t = $b + $h;

        $this->content .= sprintf("%.3f %.3f m\n", $x + $bl[0], $b);
        $this->content .= sprintf("%.3f %.3f l\n", $x + $w - $br[0], $b);
        $this->content .= sprintf("%.3f %.3f %.3f %.3f %.3f %.3f c\n", $x + $w - $br[0] + $br[0] * $k, $b, $x + $w, $b + $br[1] - $br[1] * $k, $x + $w, $b + $br[1]);
        $this->content .= sprintf("%.3f %.3f l\n", $x + $w, $t - $tr[1]);
        $this->content .= sprintf("%.3f %.3f %.3f %.3f %.3f %.3f c\n", $x + $w, $t - $tr[1] + $tr[1] * $k, $x + $w - $tr[0] + $tr[0] * $k, $t, $x + $w - $tr[0], $t);
        $this->content .= sprintf("%.3f %.3f l\n", $x + $tl[0], $t);
        $this->content .= sprintf("%.3f %.3f %.3f %.3f %.3f %.3f c\n", $x + $tl[0] - $tl[0] * $k, $t, $x, $t - $tl[1] + $tl[1] * $k, $x, $t - $tl[1]);
        $this->content .= sprintf("%.3f %.3f l\n", $x, $b + $bl[1]);
        $this->content .= sprintf("%.3f %.3f %.3f %.3f %.3f %.3f c\n", $x, $b + $bl[1] - $bl[1] * $k, $x + $bl[0] - $bl[0] * $k, $b, $x + $bl[0], $b);
        $this->content .= "h\n";
    }

    /**
     * The four corners a rounded path is actually drawn with.
     *
     * CSS §5.1's overlap factor, and `Node::overlapFactor()` is the one place
     * that computes it. The inner paths arrive already scaled, because a
     * corner is shrunk from the SCALED radius rather than from the declared
     * one, so this pass is what an outer path needs and a no-op for the rest.
     *
     * @param  list<array{0:float,1:float}> $radii
     * @return list<array{0:float,1:float}>
     */
    private static function scaledCorners(float $w, float $h, array $radii): array
    {
        $corners = array_map(
            static fn(array $corner): array => [max(0.0, $corner[0]), max(0.0, $corner[1])],
            $radii,
        );

        $scale = Node::overlapFactor($corners, $w, $h);

        return array_map(
            static fn(array $corner): array => [$corner[0] * $scale, $corner[1] * $scale],
            $corners,
        );
    }

    /**
     * A quarter of the ellipse with these two half-axes.
     *
     * Ramanujan's first approximation for the whole ellipse over four, which
     * is exact for a circle and within a part in ten thousand for anything a
     * border radius produces. There is no closed form for the general case and
     * a dash pattern needs a length rather than a series.
     */
    private static function quarterArc(float $a, float $b): float
    {
        if ($a <= 0.0 || $b <= 0.0) {
            return 0.0;
        }

        return (M_PI / 4.0) * (3.0 * ($a + $b) - sqrt((3.0 * $a + $b) * ($a + 3.0 * $b)));
    }

    /**
     * How long the rounded path around this box actually is.
     *
     * The four straight runs plus the four corner arcs, which is shorter than
     * `2 * ($w + $h)` by `(8 - 2 * pi)` times the radius on a uniform circular
     * corner. Defect GW: fitting a dash pattern to the rectangle's perimeter
     * and then laying it along the rounded path stretches every gap.
     *
     * @param list<array{0:float,1:float}> $corners tl, tr, br, bl, already scaled
     */
    private static function roundedPerimeter(float $w, float $h, array $corners): float
    {
        [$tl, $tr, $br, $bl] = $corners;

        $straight = max(0.0, $w - $tl[0] - $tr[0])
            + max(0.0, $w - $bl[0] - $br[0])
            + max(0.0, $h - $tl[1] - $bl[1])
            + max(0.0, $h - $tr[1] - $br[1]);

        return $straight
            + self::quarterArc($tl[0], $tl[1])
            + self::quarterArc($tr[0], $tr[1])
            + self::quarterArc($br[0], $br[1])
            + self::quarterArc($bl[0], $bl[1]);
    }

    /**
     * How far along the path the top-left corner's TOP tangent sits.
     *
     * `roundedPath()` starts at the bottom-left corner's bottom tangent and
     * walks the bottom edge, the bottom-right corner, the right edge, the
     * top-right corner and then the top edge, so the top-left tangent is the
     * end of that fifth run. Chrome starts a dash there and lays the pattern
     * the other way round the box, which is the same set of marks: a dash that
     * ENDS at this distance covers the same piece of the top edge that
     * Chrome's first dash covers.
     *
     * @param list<array{0:float,1:float}> $corners tl, tr, br, bl, already scaled
     */
    private static function topLeftTangentAt(float $w, float $h, array $corners): float
    {
        [$tl, $tr, $br, $bl] = $corners;

        return max(0.0, $w - $bl[0] - $br[0])
            + self::quarterArc($br[0], $br[1])
            + max(0.0, $h - $br[1] - $tr[1])
            + self::quarterArc($tr[0], $tr[1])
            + max(0.0, $w - $tl[0] - $tr[0]);
    }

    /** @param list<array{0:float,1:float}>|array{0:float,1:float,2:float,3:float}|float $radii */
    private static function rounded(array|float $radii): bool
    {
        if (!is_array($radii)) {
            return $radii > 0.0;
        }

        foreach (self::corners($radii) as [$horizontal, $vertical]) {
            if ($horizontal > 0.0 || $vertical > 0.0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Four corners as horizontal and vertical pairs.
     *
     * Accepts the two older spellings too, a single length and four circular
     * corners, so a caller outside the engine that passes either keeps
     * working and every caller inside it reads one shape.
     *
     * @param  list<array{0:float,1:float}>|array{0:float,1:float,2:float,3:float}|float $radii
     * @return list<array{0:float,1:float}>
     */
    private static function corners(array|float $radii): array
    {
        if (!is_array($radii)) {
            return array_fill(0, 4, [$radii, $radii]);
        }

        return array_map(
            static fn(array|float $corner): array => is_array($corner)
                ? [(float) $corner[0], (float) $corner[1]]
                : [(float) $corner, (float) $corner],
            array_values($radii),
        );
    }

    /**
     * Draw the `text-decoration` line for one run. PDF has no text decoration,
     * so it is a stroked rule at the position the font's metrics imply.
     *
     * **None of the three is derived from the descent, and only two are
     * derived from the ascent at all.** They were, until the ascent/descent
     * split of round 21 moved all three lines and made it clear the derivation
     * was wrong rather than merely approximate. Chrome was measured over four
     * faces and eight sizes on `docs/harness/probes/QF-decoration-metrics.html`
     * and the two large-size pages beside it, and it places them like this:
     *
     *   thickness      a tenth of the font size, floored to whole device pixels
     *   underline      centred a tenth of the font size below the baseline,
     *                  which is the `UnderlinePosition -100` every base-14 AFM
     *                  carries, and which Chrome uses for a TrueType face even
     *                  when that face's own `post` table says otherwise
     *   overline       sitting on the font box, so its lower edge is the ascent
     *   line-through   centred a third of the ascent above the baseline
     *
     * The ascent here is the font box's, `boxAscent()`, not the face's bare
     * ascent: a declared `line-height` moves neither line in Chrome, which is
     * what says the anchor is the font and not the line box.
     *
     * @param array{0:float,1:float,2:float,3?:float} $color
     */
    private function decorate(InlineRun $run, float $x, float $baselineY, float $width, array $color): void
    {
        if ($width <= 0.0) {
            return;
        }

        [$y, $thickness] = $this->decorationRule($run, $baselineY);

        // `text-decoration-color` is the line's own, and `currentcolor` is the
        // initial value: the run carries null for it and the text's color is
        // what arrives here, which is what the line was drawn in before the
        // property was read.
        [$r, $g, $b] = $run->decorationColor ?? $color;

        $this->content .= sprintf("%.4f %.4f %.4f RG\n%.3f w\n", $r, $g, $b, $thickness);

        if ($run->decorationStyle === 'wavy') {
            $this->wavyDecoration($x, $y, $width, $thickness);

            return;
        }

        $dash = $this->decorationDash($run->decorationStyle, $thickness, $width);

        if ($dash !== null) {
            $this->content .= sprintf("[%.3f %.3f] 0 d\n", $dash[0], $dash[1]);
        }

        $this->strokeRule($x, $y, $width);

        /*
         * A `double` line is the same line twice, and the second one sits one
         * CSS pixel below the first: `RY-deco-derive.html` reads the pair off
         * Chrome's own stream at five declared thicknesses and at `auto`, and
         * the gap is 1 at every one of them while the first line never moves
         * from where a `solid` one would be.
         */
        if ($run->decorationStyle === 'double') {
            $this->strokeRule($x, $y + $thickness + 0.75, $width);
        }

        if ($dash !== null) {
            $this->content .= "[] 0 d\n";
        }
    }

    /** One CSS pixel in points, which is the grid Chrome snaps a rule to. */
    private const float CSS_PIXEL = 0.75;

    /**
     * Where one run's decoration line sits and how thick it is, in points.
     *
     * **Every edge Chrome writes is a whole CSS pixel** and this engine used to
     * draw where the arithmetic landed. `SX-face-decoration.html` reads 25
     * lines off Chrome's own stream over four faces and nine sizes, and every
     * one of the fifty edges is a multiple of 0.75pt, so the snapping is part
     * of the answer rather than an artifact of the reader. In whole pixels,
     * with `A` the font box's own ascent and `s` the font size:
     *
     *   thickness      max(1, floor(s / 10)), which is the tenth of the font
     *                  size every AFM implies, floored rather than spent raw
     *   overline       its LOWER edge sits on the font box, so the line grows
     *                  upward from `A`
     *   line-through   its TOP edge is `round(A / 3 + s / 20)`, rounded toward
     *                  negative infinity on a half, and the line hangs below it
     *   underline      its BOTTOM edge is `round(0.15 * s)`, rounded away from
     *                  zero on a half, and its top edge never touches the
     *                  baseline
     *
     * The two roundings differ only on an exact half. Round 58 measured each
     * on one band; `SY-deco-tie.html` gives each five, over five faces whose
     * ascent in whole pixels is 9, 24, 27, 30 and 33 and over four sizes, and
     * both directions hold on every one of them.
     *
     * **A DECLARED thickness is a different rule and not the same one with a
     * number substituted**, which is GA. It is used at whole pixels, and both
     * lines that depend on it anchor on the USED thickness rather than on the
     * font size:
     *
     *   thickness      max(1, round(t)), so 2.2 paints as 2 and 2.5 as 3
     *   overline       unchanged: its lower edge is `A` whatever it is thick
     *   line-through   its TOP edge is `round(A / 3 + t / 2)`, the same half
     *                  toward negative infinity, where the automatic line
     *                  spends `s / 20` in the same place
     *   underline      its TOP edge is `ceil(t / 2)` below the baseline, with
     *                  no font size in it at all
     *
     * `SY-deco-thickness.html` is 37 bands and it is the second axis GA was
     * short of: the same declared thickness at 12, 24, 36 and 48px gives the
     * same gap on all four, and DejaVu Sans, STIX Two Text and Helvetica give
     * the same gap as each other. The engine used to spend `0.05 * fontSize`
     * here, which has a font size in it and no thickness.
     *
     * @return array{0:float,1:float} the stroke's center in flow space, and its width
     */
    private function decorationRule(InlineRun $run, float $baselineY): array
    {
        $font     = $run->font();
        $px       = $run->fontSize / self::CSS_PIXEL;
        $declared = $run->decorationThickness;

        /*
         * Both spellings land on a whole number of CSS pixels, which is what
         * lets every edge below be one. A declared 0.2px still paints a whole
         * pixel, which `f0` on the probe page is the only band to say.
         */
        $thickness = $declared === null
            ? max(1.0, floor($px / 10.0))
            : max(1.0, round($declared / self::CSS_PIXEL));

        $ascent = round($font->boxAscent($run->fontSize) / self::CSS_PIXEL);
        $middle = $ascent / 3.0 + ($declared === null ? $px / 20.0 : $thickness / 2.0);

        $y = match ($run->textDecoration) {
            'overline'     => $baselineY - ($ascent + $thickness / 2.0) * self::CSS_PIXEL,
            'line-through' => $baselineY - (ceil($middle - 0.5) - $thickness / 2.0) * self::CSS_PIXEL,
            /*
             * `text-underline-offset` replaces the whole gap term: a declared
             * offset puts the line's own TOP edge that far below the baseline,
             * measured on five values in `RY-deco-derive.html`, and it reaches
             * an underline alone.
             */
            default        => $baselineY
                + ($run->underlineOffset ?? $this->underlineTop($declared, $px, $thickness))
                + $thickness * self::CSS_PIXEL / 2.0,
        };

        return [$y, $thickness * self::CSS_PIXEL];
    }

    /**
     * How far below the baseline an underline's own top edge sits, in points.
     *
     * The floor on the automatic line is `u-d8`'s: at 8px the tenth of the
     * font size is below a whole pixel, the thickness clamps up to one, and
     * the line still leaves a pixel of clear space rather than touching the
     * baseline.
     *
     * **A DECLARED thickness has no font size in it**, which is GA and is what
     * round 58 could not say from five points at one size. The gap is
     * `ceil(t / 2)` whole pixels on the USED thickness, so a 2.2px line, which
     * paints as 2, gets the gap of a 2px line and not the gap of 2.2. Nineteen
     * bands on `SY-deco-thickness.html` over four sizes, three faces, five
     * whole thicknesses and five fractional ones, and a ceiling is what
     * separates it from a round: 2.5 paints as 3 and takes a gap of 2 where a
     * round of 2.5/2 would take 1.
     */
    private function underlineTop(?float $declared, float $px, float $thickness): float
    {
        if ($declared !== null) {
            return ceil($thickness / 2.0) * self::CSS_PIXEL;
        }

        return max(self::CSS_PIXEL, round(0.15 * $px) * self::CSS_PIXEL - $thickness * self::CSS_PIXEL);
    }

    /**
     * How wide each item's decoration line should be drawn, where a dash, a dot
     * or a wave has to run across several items as one.
     *
     * The writer draws a decoration per item, and abutting solid segments are
     * indistinguishable from one line, which is why it has always been safe.
     * A pattern is not: every segment starts its own dash at phase zero, so
     * `Total 1234` came out with a double-length dash where the two items meet.
     * Consecutive items that abut and would draw the same rule are collected
     * into one, the first of them draws the whole width and the rest draw
     * nothing.
     *
     * **Only the three styles that carry a phase are collected.** A `solid` or
     * a `double` line looks the same either way and merging it would rewrite
     * the content stream of every underlined document in the corpus for no
     * change on the paper.
     *
     * @return array<int,float> item index => the width to draw, 0.0 for one the span swallowed
     */
    private function decorationSpans(LineBox $lb, float $baselineY): array
    {
        $spans = [];
        $head  = null;
        $key   = null;
        $end   = 0.0;

        foreach ($lb->items as $index => $item) {
            $run = $item->run;

            $patterned = $run->textDecoration !== 'none'
                && in_array($run->decorationStyle, ['dotted', 'dashed', 'wavy'], true)
                && $run->visible
                && ! $item->isAtomic();

            if (! $patterned) {
                $head = null;

                continue;
            }

            [$y, $thickness] = $this->decorationRule($run, $baselineY - $item->baselineShift);
            $here            = [$y, $thickness, $run->decorationStyle, $run->decorationColor];

            if ($head !== null && $key == $here && abs($item->x - $end) < 1e-6) {
                $spans[$head] += $item->width;
                $spans[$index] = 0.0;
                $end           = $item->x + $item->width;

                continue;
            }

            $head          = $index;
            $key           = $here;
            $end           = $item->x + $item->width;
            $spans[$index] = $item->width;
        }

        return $spans;
    }

    /**
     * How wide the stroke around a synthesized bold is, in points.
     *
     * The ratio falls with the size, which is what keeps a heading from
     * looking heavier than a paragraph: Skia interpolates it between a
     * twenty-fourth of the size at 9 CSS pixels and a thirty-second at 36, and
     * holds it flat outside that range. On `RW-font-synth.html` at 26px that
     * is 0.913 pixels and Chrome's own stem goes from 2.56 to 3.38, which is
     * 0.82 grown symmetrically.
     */
    private static function boldStroke(float $fontSize): float
    {
        $pixels = $fontSize / 0.75;
        $t      = max(0.0, min(1.0, ($pixels - 9.0) / 27.0));

        return $fontSize * ((1.0 / 24.0) + $t * ((1.0 / 32.0) - (1.0 / 24.0)));
    }

    /** One horizontal rule of the current stroke width, at a y in flow space. */
    private function strokeRule(float $x, float $y, float $width): void
    {
        $this->content .= sprintf(
            "%.3f %.3f m %.3f %.3f l S\n",
            $x,
            $this->ty($y),
            $x + $width,
            $this->ty($y),
        );
    }

    /**
     * The dash and the gap a `dotted` or `dashed` decoration is drawn with, in
     * points, or null where the line is solid.
     *
     * **Every number here is read off Chrome's own content stream** on
     * `RY-deco-derive.html`, which writes a dashed line as a path of rectangles
     * so the geometry is exact rather than rastered. Two rules came out of it
     * and neither is in the spec:
     *
     *   a dot is one thickness long with a gap of one, and a dash is three
     *   thicknesses long with a gap of two below 3 CSS pixels and two with a
     *   gap of one at 3 and above. Measured at 1, 2, 3, 4 and 6 pixels:
     *   3/2, 6/4, 6/3, 8/4 and 12/6.
     *
     *   the gap is then stretched so a whole number of dashes ends exactly on
     *   the line, and the count is the nearest whole number of periods rather
     *   than the one that fits. On a 110.766 pixel line the five thicknesses
     *   give 22, 11, 13, 10 and 6 dashes and every one of them ends flush, and
     *   at one thickness the same rule gives 2, 5, 11 and 17 dashes on lines
     *   of 14.672, 50.703, 110.766 and 164.156 pixels.
     *
     * A line with room for one period and no more scales the whole pattern
     * rather than only its gap, which is the 14.672 pixel reading: 5.25 and
     * 3.50 where the unfitted pattern is 6 and 4.
     *
     * **Chrome fits into the line's width floored to a whole pixel** and this
     * does not, because the two engines put the decoration in different
     * places: Chrome snaps the whole rect to device pixels, which is why its
     * own `re` operators carry integers, and this one draws where the advance
     * says. Flooring only one end would leave an undashed sliver at the other.
     *
     * @return array{0:float,1:float}|null
     */
    private function decorationDash(string $style, float $thickness, float $width): ?array
    {
        if ($style !== 'dotted' && $style !== 'dashed') {
            return null;
        }

        $inPixels = $thickness * 4.0 / 3.0;

        [$dash, $gap] = $style === 'dotted'
            ? [$thickness, $thickness]
            : [$thickness * ($inPixels < 3.0 ? 3.0 : 2.0), $thickness * ($inPixels < 3.0 ? 2.0 : 1.0)];

        if ($dash <= 0.0 || $width <= $dash * 2.0) {
            return null;
        }

        $periods = (int) round(($width - $dash) / ($dash + $gap));

        if ($periods <= 1) {
            $scale = $width / ($dash * 2.0 + $gap);

            return [$dash * $scale, $gap * $scale];
        }

        return [$dash, ($width - $dash) / $periods - $dash];
    }

    /**
     * A `wavy` decoration, as cubic Beziers along the line the other four
     * styles stroke straight.
     *
     * **This is the one style nothing vector can be exact against**, and the
     * reason is Chrome rather than the wave: its `--print-to-pdf` writes a
     * wavy underline as a tiling pattern over a **rasterized 150 x 36 image**,
     * so the reference itself is pixels. The geometry below is read off that
     * raster on three declared thicknesses and on `auto`:
     *
     *   thickness 1  period  6.75  peak to peak 2.88
     *   thickness 2  period 10.75  peak to peak 4.50
     *   thickness 4  period 18.62  peak to peak 7.88
     *
     * which is a period of `2.75 + 4t` and a peak-to-peak of `1.21 + 1.67t`,
     * both in CSS pixels against a thickness rounded to whole pixels. The
     * rounding is Chrome's: `auto` at 24px is 2.4 pixels thick and waves at
     * exactly the period a declared 2px does.
     *
     * **A cubic Bezier does not reach its own control points.** One with both
     * of them at the same height peaks at three quarters of it, so the control
     * offset is the wanted half-amplitude divided by 0.75, and using the
     * amplitude itself draws a wave a quarter too flat.
     */
    private function wavyDecoration(float $x, float $y, float $width, float $thickness): void
    {
        $rounded = max(1.0, round($thickness * 4.0 / 3.0));
        $step    = (2.75 + 4.0 * $rounded) * 0.75 / 2.0;
        $amp     = (1.21 + 1.67 * $rounded) * 0.75 / 2.0 / 0.75;

        $this->content .= sprintf("1 J\n%.3f %.3f m\n", $x, $this->ty($y));

        $at   = $x;
        $up   = true;
        $end  = $x + $width;
        $span = 0;

        while ($at < $end && $span < self::MAX_WAVE_SEGMENTS) {
            $next = min($at + $step, $end);
            $peak = $this->ty($y + ($up ? -$amp : $amp));

            $this->content .= sprintf(
                "%.3f %.3f %.3f %.3f %.3f %.3f c\n",
                $at + ($next - $at) / 3.0,
                $peak,
                $next - ($next - $at) / 3.0,
                $peak,
                $next,
                $this->ty($y),
            );

            $at = $next;
            $up = ! $up;
            $span++;
        }

        $this->content .= "S\n0 J\n";
    }

    /**
     * The background and the border of every inline box on one line.
     *
     * CSS fills an inline box's own font box rather than the line box it sits
     * on, so the band is carried per item and not per line: each one says how
     * far it reaches above and below the baseline, measured at the font size of
     * the element that declared it. Chrome fills 18 rows for a `font-size: 20px`
     * span on a `line-height: 16px` paragraph and 11 for a 12px one, which is
     * the font and not the line.
     *
     * The items carry a *stack* of boxes rather than one, so a fragment is
     * collected per nesting level and the whole set goes down outermost first.
     * That is what paints an outer band whole and the nested one over it, which
     * is what Chrome does and what a translucent nested fill can tell apart
     * from a notch.
     *
     * Neighboring items sharing a box are one rect. That is what makes an
     * element which wraps paint one band per line, and what stops the space
     * between two of its words from notching the band.
     *
     * The walk is one pass and not one per level, because a piece that opens
     * and closes nothing carries the stack that is already open: reading that
     * off `openFrom` and `closeFrom` keeps a line of N words inside D nested
     * elements linear in N rather than N times D.
     */
    private function fillInlineBoxes(LineBox $lb, float $x, float $baselineY): void
    {
        // `::first-line`'s band wraps the whole line's content and goes down
        // first, so an inline element on that line paints its own over it. It
        // is the fictional tag's and not any element's, so it has no rect of
        // its own to read and no border to draw.
        if ($lb->background !== null && $lb->items !== []) {
            $last = $lb->items[count($lb->items) - 1];
            $this->fillRect(
                $x + $lb->items[0]->x,
                $baselineY - $lb->background['above'],
                $last->x + $last->width - $lb->items[0]->x,
                $lb->background['above'] + $lb->background['below'],
                $lb->background['color'],
            );
        }

        /** @var list<array{0:array<string,mixed>,1:InlineItem}> level => box, first item */
        $open = [];

        /** @var list<array{0:int,1:array<string,mixed>,2:InlineItem,3:InlineItem}> */
        $fragments = [];

        $closeFrom = static function (int $from, ?InlineItem $last) use (&$open, &$fragments): void {
            if ($last === null) {
                return;
            }

            for ($level = count($open) - 1; $level >= $from; $level--) {
                [$box, $first] = $open[$level];
                $fragments[]   = [$level, $box, $first, $last];
                array_pop($open);
            }
        };

        $previous = null;

        foreach ($lb->items as $item) {
            $depth = count($item->boxes);

            /*
             * A raised piece breaks every band even where the color is the
             * same, because the band follows the baseline the piece sits on:
             * Chrome paints a `vertical-align: sub` span's background around
             * its own shifted baseline, not around the line's.
             */
            $shift = $previous !== null && $item->baselineShift !== $previous->baselineShift;

            if (
                !$shift
                && $previous !== null
                && $item->openFrom >= $depth
                && $previous->closeFrom >= count($previous->boxes)
            ) {
                $previous = $item;

                continue;
            }

            $shared = $shift ? 0 : min($item->openFrom, count($open), $depth);
            $closeFrom($shared, $previous);

            for ($level = $shared; $level < $depth; $level++) {
                $open[$level] = [$item->boxes[$level], $item];
            }

            $previous = $item;
        }

        $closeFrom(0, $previous);

        // Outermost first, so a nested band goes over the one containing it
        // rather than under it. `usort` is stable, so two fragments at the same
        // level keep the order the line put them in.
        usort($fragments, static fn(array $a, array $b): int => $a[0] <=> $b[0]);

        foreach ($fragments as [$level, $box, $first, $last]) {
            $this->paintInlineBox($box, $level, $first, $last, $x, $baselineY);
        }
    }

    /**
     * One fragment of one inline box: its background over the border box, then
     * whichever of its four edges this fragment owns.
     *
     * @param array<string,mixed> $box
     */
    private function paintInlineBox(
        array $box,
        int $level,
        InlineItem $first,
        InlineItem $last,
        float $x,
        float $baselineY,
    ): void {
        // `visibility: hidden` keeps the room the padding and the border take
        // on the line and draws neither, exactly as it does on a box.
        if (!($box['ink'] ?? true)) {
            return;
        }

        $left   = $x + $first->x - $first->edgeBefore($level);
        $right  = $x + $last->x + $last->width + $last->edgeAfter($level);
        $top    = $baselineY - $first->baselineShift - $box['above']
            - $box['padTop'] - ($box['border']['top']['width'] ?? 0.0);
        $height = $box['above'] + $box['below']
            + $box['padTop'] + $box['padBottom']
            + ($box['border']['top']['width'] ?? 0.0)
            + ($box['border']['bottom']['width'] ?? 0.0);

        if ($right <= $left) {
            return;
        }

        if ($box['color'] !== null) {
            $this->fillRect($left, $top, $right - $left, $height, $box['color']);
        }

        if ($box['border'] === null) {
            return;
        }

        // `box-decoration-break: slice`: the left and the right edge are drawn
        // only where the element itself starts and ends, so a fragment in the
        // middle of a wrapped element carries neither. Under `clone` every
        // line carries both, and the item is asked rather than its `openFrom`
        // because the line paid for exactly the edges it answers with.
        foreach ($box['border'] as $side => $edge) {
            if ($side === 'left' && !$first->opensAt($level)) {
                continue;
            }

            if ($side === 'right' && !$last->closesAt($level)) {
                continue;
            }

            // A fully transparent edge is kept for its advance on the line and
            // there is nothing to draw for it.
            if (($edge['color'][3] ?? 1.0) <= 0.0) {
                continue;
            }

            $this->strokeEdges(
                $left,
                $top,
                $right - $left,
                $height,
                $edge['color'],
                $edge['width'],
                [$side],
                $edge['style'],
            );
        }
    }

    /**
     * The inline boxes on these lines, and nothing else.
     *
     * They go down before the atomic inlines sitting on the same lines, so a
     * `<span style="background:red"><img></span>` shows its image: painting
     * them inside `paintLines()` put the band over a box that had already been
     * drawn. Chrome paints the band first too.
     *
     * @param LineBox[] $lineBoxes
     */
    public function paintInlineBoxes(array $lineBoxes, float $x, float $yTop): void
    {
        $cursor = $yTop;

        foreach ($lineBoxes as $lb) {
            $this->fillInlineBoxes($lb, $x, $cursor + $lb->baseline);
            $cursor += $lb->height;
        }
    }

    /**
     * Paint pre-computed line boxes; each run carries its own face.
     *
     * The inline boxes behind them are `paintInlineBoxes()`'s, which the caller
     * puts down first: an override color means this is the text-shadow pass,
     * which paints the same runs a second time, and a background casts no
     * shadow.
     *
     * `$marked` is the box these lines belong to, on a tagged document. It is
     * what lets the text be split into one marked-content sequence per stretch
     * between atomic inlines, so a field or a picture is read where it sits
     * rather than after every word on its line. The shadow pass passes none:
     * it paints the same runs again and must not open a second set of marks.
     */
    public function paintLines(array $lineBoxes, float $x, float $yTop, ?array $overrideColor = null, ?Node $marked = null): void
    {
        $cursor = $yTop;

        // An untagged document has no reading order to get right, and it is the
        // common case, so it must not pay for the walk this needs.
        $order = $marked === null || $this->structure === null || $this->capture !== null
            ? []
            : $this->structure->inlineOrder($marked);

        /*
         * Which structure element the open marked-content sequence belongs to,
         * as a role and a key: the box's own text is `['', '']`, an `<a href>`
         * is `['Link', the run's id]` and a list marker is `['Lbl', the run's
         * id]`.
         *
         * **It is one question rather than two.** Both answers mean the same
         * thing to this loop, that the box's sequence has to end here and
         * reopen under a different element, and asking them separately would
         * put two splits on one item arguing about which of them won.
         */
        $open = ['', ''];

        foreach ($lineBoxes as $lb) {
            $baselineY = $cursor + $lb->baseline;
            $spans     = $this->decorationSpans($lb, $baselineY);

            foreach ($lb->items as $index => $item) {
                $run = $item->run;

                // A hidden run keeps the space it measured, so the cursor and
                // every item after it stay where they are; only the glyphs,
                // the decoration and the link rectangle are skipped.
                if (!$run->visible) {
                    continue;
                }

                // An `inside` list marker is a shape rather than a character,
                // so it holds an advance and no text at all and `BoxPainter`
                // draws it. Showing its empty string would put a `BT ... ET`
                // with nothing in it on every list item in the document.
                if (($run->markerShape !== null || $run->markerImage !== null) && !$item->isAtomic()) {
                    continue;
                }

                // An atomic inline holds a box rather than text, and the box
                // painter draws it. The space in front of it is still text.
                if ($item->isAtomic()) {
                    if (isset($order[spl_object_id($item)])) {
                        $this->splitContent($order[spl_object_id($item)] + 1);
                    }

                    /*
                     * A picture inside an `<a href>` was **not clickable at
                     * all**: the run carries the href, and the only call that
                     * registered a rectangle sat in the text branch an atomic
                     * inline never reaches. `SM-tag-link.html` a4 is a linked
                     * 12 by 12 logo, and Chrome writes an annotation for it
                     * where this engine wrote none.
                     */
                    if ($run->href !== null && $overrideColor === null && $item->run->box !== null) {
                        $this->addLink(
                            $x + $item->x,
                            $cursor,
                            $item->width,
                            $lb->height,
                            $run->href,
                            // A picture's `alt` is the only description it has,
                            // and it is what a reader speaks for the link.
                            $item->run->box->altText,
                            (string) spl_object_id($run),
                        );

                        $this->structure?->linked($item->run->box, (string) spl_object_id($run), $this->currentPage);
                    }

                    $open = ['', ''];

                    continue;
                }

                /*
                 * An `<a href>` is a structure of its own and its text has to
                 * be one marked-content id, so the box's text is split where
                 * the link starts and where it ends, exactly as it already is
                 * around an atomic inline. All of a link's items share one
                 * `InlineRun`, including the pieces a line break divides, so
                 * the run is the link's identity and a stretch stays open
                 * across as many items and lines as it covers.
                 *
                 * An `<ol>`'s marker is split the same way, and it is the half
                 * of defect FS that could not be done anywhere else: a shape
                 * or a picture is drawn outside this loop and can be given a
                 * `Lbl` where it is drawn, but a number is real text sitting on
                 * the item's own line, so the number and the item's words came
                 * out as ONE mark and `LBody '1. Ordered alpha'` had nothing in
                 * it a reader could separate. The marker is its own `InlineRun`
                 * in both spellings, which is what makes this reachable at all.
                 */
                $here = match (true) {
                    $run->listMarker    => ['Lbl', (string) spl_object_id($run)],
                    $run->href !== null => ['Link', (string) spl_object_id($run)],
                    default             => ['', ''],
                };

                if ($here !== $open && isset($order[spl_object_id($item)])) {
                    $this->splitContent($order[spl_object_id($item)], $here[0], $here[1]);

                    $open = $here;
                }

                $face        = $run->font();
                $color       = $overrideColor ?? $run->color;
                [$r, $g, $b] = $color;

                // `vertical-align` moves the glyphs and everything drawn from
                // their baseline with them, which is what Chrome does with an
                // underline on a `<sup>`: it sits under the raised text.
                $itemBaseline = $baselineY - $item->baselineShift;

                [$res, $show] = $this->showFor(
                    $face,
                    $item->text,
                    $run->fontFeatures,
                    $run->fontSize,
                    $run->smallCaps,
                );

                $transparency = $this->beginAlpha($color[3] ?? 1.0);

                /*
                 * Tc and Tw are text state, and text state survives BT/ET.
                 * Emitting them only when set would leak one run's tracking
                 * into every run after it, so they are emitted whenever they
                 * differ from what is currently in effect, including back to
                 * zero.
                 */
                $spacing = '';

                if (abs($run->letterSpacing - $this->charSpacing) > 1e-9) {
                    $spacing .= sprintf('%.3f Tc ', $run->letterSpacing);
                    $this->charSpacing = $run->letterSpacing;
                }

                if (abs($run->wordSpacing - $this->wordSpacing) > 1e-9) {
                    $spacing .= sprintf('%.3f Tw ', $run->wordSpacing);
                    $this->wordSpacing = $run->wordSpacing;
                }

                /*
                 * `font-synthesis`: a family with no bold or no italic face of
                 * its own gets one made here, which is what a browser does and
                 * what this writer used to skip. The emboldening is a stroke
                 * around the fill and the slant is the text matrix's own shear
                 * term, so neither moves a glyph sideways and the advance the
                 * line was measured with is still the advance drawn.
                 */
                [$embolden, $slant] = $run->synthesis();

                $this->content .= sprintf(
                    "BT /%s %.2f Tf %s%.4f %.4f %.4f rg %s1 0 %s 1 %.3f %.3f Tm %s%s ET\n",
                    $res,
                    $run->fontSize,
                    $spacing,
                    $r,
                    $g,
                    $b,
                    $embolden
                        ? sprintf('%.4f %.4f %.4f RG %.4f w 2 Tr ', $r, $g, $b, self::boldStroke($run->fontSize))
                        : '',
                    // Spelled as the bare `0` a run that slants nothing has
                    // always written, so a document that synthesizes nothing
                    // is byte for byte the document it was.
                    $slant ? sprintf('%.3f', self::OBLIQUE_SKEW) : '0',
                    $x + $item->x,
                    $this->ty($itemBaseline),
                    $show,
                    // The rendering mode is text state and survives ET, so a
                    // run that stroked has to put it back or every later run
                    // on the page is emboldened too.
                    $embolden ? ' 0 Tr' : '',
                );

                if ($run->textDecoration !== 'none' && $overrideColor === null) {
                    $this->decorate($run, $x + $item->x, $itemBaseline, $spans[$index] ?? $item->width, $color);
                }

                /*
                 * An override color means this is the text-shadow pass, which
                 * paints the same runs a second time; registering there would
                 * duplicate every annotation.
                 */
                if ($run->href !== null && $overrideColor === null) {
                    $this->addLink(
                        $x + $item->x,
                        $cursor,
                        $item->width,
                        $lb->height,
                        $run->href,
                        $item->text,
                        (string) spl_object_id($run),
                    );
                }

                $this->endAlpha($transparency);
            }

            $cursor += $lb->height;
        }
    }

    /**
     * The show operators for one run of text, switching resource wherever the
     * face the document asked for cannot draw a character.
     *
     * **Every piece is shown inside the SAME `BT`/`ET`, and none of them is
     * given a `Tm` of its own.** A show operator advances the text matrix by
     * exactly what it drew, so the reader does the positioning arithmetic and
     * does it the way it would have for a single face. That is also what keeps
     * `Tc` and `Tw` landing where they landed, and it is why this needs no
     * offsets: computing them here would be re-deriving a number the file
     * already carries.
     *
     * **A run the resolved face can draw on its own produces one piece and no
     * `Tf` at all**, which is character for character what this wrote before
     * there was a fallback. Both `BT` blocks in this class open with their own
     * `/name size Tf`, so nothing has to be put back afterwards either.
     *
     * **The resource the block OPENS with is returned rather than assumed**,
     * because a run whose first character already falls back never draws in the
     * face the page named. Opening with that face anyway wrote a `Tf` the next
     * operator overrode, and, worse, claimed the face as a resource of the
     * page: under PDF/A a base-14 face that puts no ink down would still have
     * been refused as unembeddable.
     *
     * @see FontFallback::segments()
     *
     * @return array{0:string,1:string} the resource to open with, and the show operators
     */
    private function showFor(
        Font|TrueTypeFont $face,
        string $text,
        string $features,
        float $size,
        bool $smallCaps = false,
        bool $flipped = false,
    ): array {
        $segments = FontFallback::active()?->segments($face, $text);

        if ($segments === null) {
            return [
                $this->resourceFor($face, $smallCaps),
                $this->showOne($face, $text, $features, $size, $flipped),
            ];
        }

        $parts   = [];
        $opening = '';
        $current = null;

        foreach ($segments as [$piece, $part]) {
            if ($current === null) {
                $opening = $this->resourceFor($piece, $smallCaps);
            } elseif ($piece !== $current) {
                $parts[] = sprintf('/%s %.2f Tf', $this->resourceFor($piece, $smallCaps), $size);
            }

            $current = $piece;
            $parts[] = $this->showOne($piece, $part, $features, $size, $flipped);
        }

        return [$opening, implode(' ', $parts)];
    }

    /** One piece of text in one face, in whichever spelling that face writes. */
    private function showOne(
        Font|TrueTypeFont $face,
        string $text,
        string $features,
        float $size,
        bool $flipped,
    ): string {
        return $face instanceof TrueTypeFont
            ? $face->showText($text, $features, $size, $flipped)
            : '(' . self::esc($this->toWinAnsi($text)) . ') Tj';
    }

    /**
     * Base-14 faces can only carry WinAnsi; anything else becomes '?'.
     *
     * The map is Font's, so a curly quote or an em dash is written to the
     * slot whose width the layout was measured with.
     *
     * **A character that reaches here is one NOTHING could draw**, because
     * {@see showFor()} has already handed every character the bundled fallback
     * covers to the face that covers it.
     */
    private function toWinAnsi(string $utf8): string
    {
        $out = '';

        foreach (TrueTypeFont::codepoints($utf8) as $cp) {
            $code = Font::winAnsiByte($cp);
            $out .= $code === null ? '?' : chr($code);
        }

        return $out;
    }

    // ---------------------------------------------------------------
    // output
    // ---------------------------------------------------------------
    public function save(string $path): void
    {
        file_put_contents($path, $this->output());
    }

    /** The finished document as a byte string, for streaming and downloads. */
    public function output(): string
    {
        $objs  = [];
        $next  = 1;
        $alloc = function () use (&$next): int { return $next++; };

        $catalog  = $alloc();
        $pagesObj = $alloc();

        $pageObjs    = [];
        $contentObjs = [];

        foreach ($this->streams as $_) {
            $pageObjs[]    = $alloc();
            $contentObjs[] = $alloc();
        }

        // --- font objects ---
        $fontRefs = [];

        // A small-caps resource differs from its plain twin only in the map
        // back to Unicode, so the descendant font, the descriptor and the
        // embedded outlines are written once and both point at them.
        $descendants = [];

        foreach ($this->used as $name => $entry) {
            ['face' => $face, 'smallCaps' => $smallCaps] = $entry;

            if ($face instanceof Font) {
                /*
                 * A base-14 face is written unembedded because the reader is
                 * expected to have it, and PDF/A has no such expectation. The
                 * engine holds the widths for these faces and not the outlines,
                 * so there is nothing here to embed and nothing to substitute
                 * that would still be this face.
                 */
                if ($this->pdfa) {
                    throw PdfaConformanceException::unembeddableFont($face->name);
                }

                $id        = $alloc();
                $toUnicode = '';

                if ($smallCaps) {
                    $lowered   = $alloc();
                    $objs[$lowered] = $this->smallCapsCMap();
                    $toUnicode = sprintf(' /ToUnicode %d 0 R', $lowered);
                }

                $objs[$id] = sprintf(
                    "<< /Type /Font /Subtype /Type1 /BaseFont /%s /Encoding /WinAnsiEncoding%s >>",
                    $face->pdfName(),
                    $toUnicode,
                );
                $fontRefs[$name] = $id;

                continue;
            }

            $key = spl_object_id($face);

            if (isset($descendants[$key])) {
                $type0 = $alloc();
                $toUni = $alloc();

                $objs[$type0] = sprintf(
                    "<< /Type /Font /Subtype /Type0 /BaseFont /%s /Encoding /Identity-H "
                    . "/DescendantFonts [%d 0 R] /ToUnicode %d 0 R >>",
                    $descendants[$key][1],
                    $descendants[$key][0],
                    $toUni,
                );
                $objs[$toUni]    = $this->toUnicodeCMap($face, $smallCaps);
                $fontRefs[$name] = $type0;

                continue;
            }

            // Type0 -> CIDFontType2 -> FontDescriptor -> FontFile2
            $type0      = $alloc();
            $cidFont    = $alloc();
            $descriptor = $alloc();
            $fontFile   = $alloc();
            $toUni      = $alloc();

            $subset = $face->subset();
            $tag    = $face->subsetTag();
            $psName = $tag . '+' . str_replace(' ', '', $face->postScriptName);
            $scale  = 1000.0 / $face->unitsPerEm;

            $w = '';

            foreach ($face->usedGlyphIds() as $gid) {
                $w .= sprintf('%d[%d]', $gid, (int) round($face->advanceOf($gid) * $scale));
            }

            $objs[$type0] = sprintf(
                "<< /Type /Font /Subtype /Type0 /BaseFont /%s /Encoding /Identity-H "
                . "/DescendantFonts [%d 0 R] /ToUnicode %d 0 R >>",
                $psName,
                $cidFont,
                $toUni,
            );

            $objs[$cidFont] = sprintf(
                "<< /Type /Font /Subtype /CIDFontType2 /BaseFont /%s "
                . "/CIDSystemInfo << /Registry (Adobe) /Ordering (Identity) /Supplement 0 >> "
                . "/FontDescriptor %d 0 R /DW 1000 /W [%s] /CIDToGIDMap /Identity >>",
                $psName,
                $descriptor,
                $w,
            );

            $objs[$descriptor] = sprintf(
                "<< /Type /FontDescriptor /FontName /%s /Flags %d "
                . "/FontBBox [%d %d %d %d] /ItalicAngle %d /Ascent %d /Descent %d "
                . "/CapHeight %d /StemV %d /FontFile2 %d 0 R >>",
                $psName,
                $face->flags,
                (int) round($face->bbox[0] * $scale),
                (int) round($face->bbox[1] * $scale),
                (int) round($face->bbox[2] * $scale),
                (int) round($face->bbox[3] * $scale),
                $face->italicAngle,
                (int) round($face->typoAscent * $scale),
                (int) round($face->typoDescent * $scale),
                (int) round($face->capHeight * $scale),
                $face->stemV,
                $fontFile,
            );

            $objs[$fontFile] = $this->stream($subset, sprintf(' /Length1 %d', strlen($subset)));
            $objs[$toUni]    = $this->toUnicodeCMap($face, $smallCaps);

            $descendants[$key] = [$cidFont, $psName];
            $fontRefs[$name]   = $type0;
        }

        // --- image XObjects ---
        $imageRefs = [];

        /*
         * PDF/A refuses `/Interpolate true`, in every part, because smoothing
         * is the reader's own idea of what the samples meant and an archival
         * file may not depend on one. The engine asks for it on the two images
         * it computes rather than loads, a sampled gradient and a blurred
         * shadow's coverage mask, so the most it can cost is a harder edge on
         * those and nothing at all on a document without them. Measured rather
         * than assumed: RL-pdfa-ink.html rendered both ways is identical, 0 of
         * 800 x 920 pixels different and a maximum difference of 0.
         */
        $smooth = !$this->pdfa;

        foreach ($this->images as $name => $img) {
            $id       = $alloc();
            $smaskRef = '';

            if ($img->softMask !== null) {
                $sm        = $alloc();
                $objs[$sm] = sprintf(
                    "<< /Type /XObject /Subtype /Image /Width %d /Height %d "
                    . "/ColorSpace /DeviceGray /BitsPerComponent 8 /Filter /FlateDecode%s "
                    . "/Length %d >>\nstream\n%s\nendstream",
                    $img->width,
                    $img->height,
                    $img->interpolate && $smooth ? ' /Interpolate true' : '',
                    $img->softMaskLen,
                    $img->softMask,
                );

                $smaskRef  = sprintf(' /SMask %d 0 R', $sm);
            }

            $filter = $img->filter !== '' ? sprintf(' /Filter /%s', $img->filter) : '';

            $objs[$id] = sprintf(
                "<< /Type /XObject /Subtype /Image /Width %d /Height %d "
                . "/ColorSpace %s /BitsPerComponent %d%s %s%s%s /Length %d >>\nstream\n%s\nendstream",
                $img->width,
                $img->height,
                str_starts_with($img->colorSpace, '[') ? $img->colorSpace : '/' . $img->colorSpace,
                $img->bitsPerComponent,
                $filter,
                $img->decodeParms,
                $smaskRef,
                $img->interpolate && $smooth ? ' /Interpolate true' : '',
                strlen($img->data),
                $img->data,
            );

            $imageRefs[$name] = $id;
        }

        // --- page tree ---
        $fontDict = '';

        foreach ($fontRefs as $name => $id) {
            $fontDict .= sprintf('/%s %d 0 R ', $name, $id);
        }

        $xobjDict = '';

        foreach ($imageRefs as $name => $id) {
            $xobjDict .= sprintf('/%s %d 0 R ', $name, $id);
        }

        /*
         * A transparency group's object is allocated here and written further
         * down, because its dictionary carries a `/StructParents` key and the
         * keys a form's widgets take are not handed out until the interactive
         * form is written. The name is what the page's content stream already
         * says, so only the number is outstanding.
         */
        $groupRefs = [];

        foreach (array_keys($this->groups) as $name) {
            $groupRefs[$name] = $alloc();
            $xobjDict         .= sprintf('/%s %d 0 R ', $name, $groupRefs[$name]);
        }

        $xobjEntry = $xobjDict === '' ? '' : sprintf('/XObject << %s>> ', $xobjDict);

        $gsDict = '';

        foreach ($this->alphas as $name => $state) {
            $id = $alloc();

            $objs[$id] = sprintf(
                '<< /Type /ExtGState /ca %.3f /CA %.3f /BM /%s >>',
                $state['alpha'],
                $state['stroke'],
                $state['blend'] === 'normal' ? 'Normal' : $state['blend'],
            );

            $gsDict .= sprintf('/%s %d 0 R ', $name, $id);
        }

        // Kept apart as well as added to the page's, because a soft mask that
        // holds more than one layer screens them together and needs the blend
        // state inside its own stream. It may not have the whole dictionary:
        // that would name the soft masks from inside a soft mask.
        $blendDict = '';

        foreach ($this->blends as $name => $mode) {
            $id        = $alloc();
            $objs[$id] = sprintf('<< /Type /ExtGState /BM /%s >>', $mode);
            $gsDict    .= sprintf('/%s %d 0 R ', $name, $id);
            $blendDict .= sprintf('/%s %d 0 R ', $name, $id);
        }

        /*
         * Axial shadings. Two stops are one exponential function; more are a
         * type 3 stitching function over one exponential per span, which is
         * how PDF spells a multi-stop gradient.
         */
        $shDict = '';

        foreach ($this->shadings as $name => [$kind, $coords, $stops]) {
            $spans  = [];
            $bounds = [];
            $encode = [];

            $ramp = static fn(array $from, array $to): string => sprintf(
                '<< /FunctionType 2 /Domain [0 1] /C0 [%.4f %.4f %.4f] /C1 [%.4f %.4f %.4f] /N 1 >>',
                $from[0], $from[1], $from[2], $to[0], $to[1], $to[2],
            );

            /*
             * The axis spans the whole gradient line, so the function's domain
             * is the line and a stop's position is where its span starts. A
             * first stop past 0, or a last stop before 1, therefore needs a
             * flat span to hold the end color: without them a `25%` stop
             * simply moves the whole ramp instead of shortening it.
             */
            if ($stops[0][0] > 0.0) {
                $spans[]  = $ramp($stops[0][1], $stops[0][1]);
                $encode[] = '0 1';
                $bounds[] = sprintf('%.4f', $stops[0][0]);
            }

            for ($i = 0; $i < count($stops) - 1; $i++) {
                $spans[]  = $ramp($stops[$i][1], $stops[$i + 1][1]);
                $encode[] = '0 1';

                if ($i > 0) {
                    $bounds[] = sprintf('%.4f', $stops[$i][0]);
                }
            }

            $last = $stops[count($stops) - 1];

            if ($last[0] < 1.0) {
                $bounds[] = sprintf('%.4f', $last[0]);
                $spans[]  = $ramp($last[1], $last[1]);
                $encode[] = '0 1';
            }

            $function = count($spans) === 1
                ? $spans[0]
                : sprintf(
                    '<< /FunctionType 3 /Domain [0 1] /Functions [%s] /Bounds [%s] /Encode [%s] >>',
                    implode(' ', $spans),
                    implode(' ', $bounds),
                    implode(' ', $encode),
                );

            $id = $alloc();

            $objs[$id] = sprintf(
                '<< /ShadingType %d /ColorSpace /DeviceRGB /Coords [%s] '
                . '/Function %s /Extend [true true] >>',
                $kind === 'radial' ? 3 : 2,
                implode(' ', array_map(static fn(float $v): string => sprintf('%.3f', $v), $coords)),
                $function,
            );

            $shDict .= sprintf('/%s %d 0 R ', $name, $id);
        }

        $shEntry = $shDict === '' ? '' : sprintf('/Shading << %s>> ', $shDict);

        /*
         * Soft masks. Each is a form XObject that is its own transparency
         * group, named by an ExtGState that points at it, which is the only
         * shape PDF has for masking a whole drawing rather than one fill.
         *
         * The form's own resources are the shadings, the images and the blend
         * states, and nothing else. Handing it the page's dictionary would
         * work and would make the group name the ExtGState that names the
         * group, and a resource cycle is a thing every later reader of this
         * file has to think about for no gain.
         */
        $maskResources = sprintf('<< %s%s>>', $xobjEntry, $shEntry);

        // Only a mask that screens two layers together names a blend state,
        // and a mask that names none must not carry the dictionary: every
        // document with a `mix-blend-mode` anywhere in it would otherwise
        // write those bytes into every mask form it has.
        $blendResources = sprintf(
            '<< %s%s%s>>',
            $xobjEntry,
            sprintf('/ExtGState << %s>> ', $blendDict),
            $shEntry,
        );

        /*
         * Each of the two is written ONCE and referenced, the way the page's
         * own dictionary has been since round 46. Inlined, a document paid
         * `masks x (images + groups + shadings)` bytes for names none of its
         * masks reads, which is the same shape as round 45's quadratic-bytes
         * bug one level down. The object is allocated only if a mask actually
         * asks for it, so a document with no soft mask writes neither.
         */
        $sharedMask  = null;
        $sharedBlend = null;

        $maskResourceRef = function (bool $blends) use (&$sharedMask, &$sharedBlend, &$objs, $alloc, $maskResources, $blendResources): string {
            if ($blends) {
                $sharedBlend ??= $alloc();
                $objs[$sharedBlend] = $blendResources;

                return sprintf('%d 0 R', $sharedBlend);
            }

            $sharedMask ??= $alloc();
            $objs[$sharedMask] = $maskResources;

            return sprintf('%d 0 R', $sharedMask);
        };

        foreach ($this->softMasks as $name => $mask) {
            [$mx, $my, $mw, $mh] = $mask['bbox'];

            $form = $alloc();

            $objs[$form] = $this->stream(
                $mask['content'],
                sprintf(
                    ' /Type /XObject /Subtype /Form /FormType 1 /BBox [%.3f %.3f %.3f %.3f]'
                    . ' /Group << /S /Transparency /CS /DeviceRGB /I true /K false >>'
                    . ' /Resources %s',
                    $mx,
                    $my,
                    $mx + $mw,
                    $my + $mh,
                    $maskResourceRef(str_contains($mask['content'], '/BM')),
                ),
            );

            $id = $alloc();

            $objs[$id] = sprintf(
                '<< /Type /ExtGState /SMask << /S /Luminosity /G %d 0 R /BC [0 0 0] >> >>',
                $form,
            );

            $gsDict .= sprintf('/%s %d 0 R ', $name, $id);
        }

        $gsEntry = $gsDict === '' ? '' : sprintf('/ExtGState << %s>> ', $gsDict);

        /*
         * One object, referenced by every page, every transparency group and
         * every widget appearance stream, rather than the same bytes written
         * into each of them.
         *
         * Inlining it is quadratic in the number of groups, because the
         * dictionary names every group and every group carries the dictionary:
         * 1,600 SVG shapes with a fill, a stroke and an `opacity` came to
         * 40.6 MB where the same 1,600 with no opacity are 1,540 bytes, with
         * the time flat either way. A page repeats it once per page for the
         * same reason.
         */
        $resourcesObj = $alloc();

        $objs[$resourcesObj] = sprintf(
            '<< /Font << %s>> %s%s%s>>',
            $fontDict,
            $xobjEntry,
            $gsEntry,
            $shEntry,
        );

        $resources = sprintf('%d 0 R', $resourcesObj);

        // A widget and a link annotation both carry no marked content, so each
        // reaches the structure tree through a /StructParent of its own. Those
        // keys follow the page ones, which is why the tagged decision has to be
        // taken before either is written rather than beside the catalog entry
        // below.
        $tagged = $this->structure !== null
            && $this->structureRoot !== null
            && !$this->structure->isEmpty();

        // --- link annotations ---
        // The spec requires /Annots entries to be indirect, so each one is its
        // own object rather than a dictionary inline in the array.
        $annotRefs = [];
        $nextLink  = count($pageObjs);

        foreach ($this->links as $pageIndex => $rects) {
            if (!isset($pageObjs[$pageIndex])) {
                continue;
            }

            foreach ($rects as $link) {
                $anchor    = ($link['anchor'] ?? '') === '' ? null : $link['anchor'] . ':' . $pageIndex;
                $structKey = $tagged && $anchor !== null ? $nextLink++ : null;
                $dict      = $this->annotation($link, $pageObjs, $structKey);

                if ($dict === null) {
                    if ($structKey !== null) {
                        $nextLink--;
                    }

                    continue;
                }

                $id                     = $alloc();
                $objs[$id]              = $dict;
                $annotRefs[$pageIndex][] = sprintf('%d 0 R', $id);

                if ($anchor !== null && $structKey !== null) {
                    $this->linkObjects[$anchor][]    = $id;
                    $this->linkStructKeys[$anchor][] = $structKey;
                }
            }
        }

        // --- interactive form ---
        $acroEntry = $this->writeForm(
            $objs,
            $alloc,
            $annotRefs,
            $pageObjs,
            $resources,
            $fontDict,
            $tagged ? $nextLink : null,
        );

        /*
         * --- transparency groups ---
         *
         * Each is a form XObject that is its own isolated, non-knockout group,
         * drawn by the page under whatever alpha, blend mode and soft mask the
         * subtree asked for. The resources are the page's own, because the
         * content moved out of the page unchanged and names the same faces,
         * pictures and states it did there. That includes the groups
         * themselves, so a group nested in another finds its name.
         *
         * @var array<int,int> the parent-tree key a group's marks were counted
         *                     against => the final key, which follows the page
         *                     and widget ones the way `/Nums` has to be ordered
         */
        $groupKeys    = [];
        $groupStreams = [];
        $nextKey      = count($pageObjs) + $this->linkStructKeyCount() + count($this->widgetStructKeys);

        foreach ($this->groups as $name => $group) {
            [$gx, $gy, $gr, $gt] = $group['bbox'];

            $groupStreams[$group['mark']] = $groupRefs[$name];
            $parents                      = '';

            if ($tagged && $this->structure->marksOn($group['mark']) > 0) {
                $groupKeys[$group['mark']] = $nextKey;
                $parents                   = sprintf(' /StructParents %d', $nextKey);
                $nextKey++;
            }

            $objs[$groupRefs[$name]] = $this->stream(
                $group['content'],
                sprintf(
                    ' /Type /XObject /Subtype /Form /FormType 1 /BBox [%.3f %.3f %.3f %.3f]'
                    . ' /Group << /S /Transparency /CS /DeviceRGB /I true /K false >>'
                    . ' /Resources %s%s',
                    $gx,
                    $gy,
                    $gr,
                    $gt,
                    $resources,
                    $parents,
                ),
            );
        }

        $annots = [];

        foreach ($annotRefs as $pageIndex => $refs) {
            if ($refs !== []) {
                $annots[$pageIndex] = sprintf(' /Annots [%s]', implode(' ', $refs));
            }
        }

        // --- outlines ---
        $outlineEntry = '';

        if ($this->outline !== []) {
            $outlineRoot  = $alloc();
            $outlineEntry = sprintf(' /Outlines %d 0 R', $outlineRoot);
            $this->writeOutline($objs, $alloc, $outlineRoot, $pageObjs);
        }

        // --- document structure ---
        $structEntry = '';

        if ($tagged) {
            $structRoot  = $alloc();
            $structEntry = sprintf(
                ' /StructTreeRoot %d 0 R /MarkInfo << /Marked true >>',
                $structRoot,
            );
        }

        if ($this->structure !== null && $this->structure->lang !== '') {
            $structEntry .= ' /Lang ' . self::textString($this->structure->lang);
        }

        // --- archival conformance ---
        $archivalEntry = '';

        if ($this->pdfa) {
            $iccObj    = $alloc();
            $intentObj = $alloc();
            $metaObj   = $alloc();
            $xmp       = Pdfa::xmp($this->metadata, $this->conformance, $this->pdfua);

            $objs[$iccObj] = $this->stream(Pdfa::iccProfile(), ' /N 3');

            /*
             * The output intent is what makes every DeviceRGB color in the
             * document mean something a device cannot change: it names the
             * space those numbers are in. Without it a conforming file may not
             * use DeviceRGB at all, and this engine writes nothing else.
             */
            $objs[$intentObj] = sprintf(
                '<< /Type /OutputIntent /S /GTS_PDFA1 /OutputConditionIdentifier (%s) '
                . '/Info (%s) /RegistryName (http://www.color.org) /DestOutputProfile %d 0 R >>',
                'sRGB IEC61966-2.1',
                'sRGB IEC61966-2.1',
                $iccObj,
            );

            /*
             * The metadata stream is deliberately not compressed. It is the
             * part of an archived file a tool is meant to be able to read
             * without a PDF parser at all, and a filter is exactly what stops
             * that working.
             */
            $objs[$metaObj] = sprintf(
                "<< /Type /Metadata /Subtype /XML /Length %d >>\nstream\n%s\nendstream",
                strlen($xmp),
                $xmp,
            );

            $archivalEntry = sprintf(
                ' /OutputIntents [%d 0 R] /Metadata %d 0 R'
                . ' /ViewerPreferences << /DisplayDocTitle true >>',
                $intentObj,
                $metaObj,
            );
        }

        // --- the initial view ---
        $viewEntry = '';

        if ($this->openAction !== null) {
            $index = min($this->openAction['page'], count($pageObjs)) - 1;
            $args  = $this->openAction['args'];
            $wants = self::FIT_ARGUMENTS[$this->openAction['fit']];
            $parts = [];

            for ($i = 0; $i < $wants; $i++) {
                // `null` is the spec's own way of saying "keep this one as it
                // is", which is what a caller who named a fit and no numbers
                // means. Writing 0 instead would scroll a reader to the origin.
                $parts[] = isset($args[$i]) ? self::num($args[$i]) : 'null';
            }

            $viewEntry = sprintf(
                ' /OpenAction [%d 0 R /%s%s]',
                $pageObjs[max(0, $index)],
                $this->openAction['fit'],
                $parts === [] ? '' : ' ' . implode(' ', $parts),
            );
        }

        if ($this->pageMode !== '') {
            $viewEntry .= ' /PageMode /' . $this->pageMode;
        }

        // --- files travelling inside the document ---
        $filesEntry = '';

        if ($this->attachments !== []) {
            $specs = [];
            $names = [];

            foreach ($this->attachments as $file) {
                $embedded = $alloc();
                $spec     = $alloc();

                // `/Params /Size` is the length BEFORE any filter, so it is
                // the size the file has once it is taken back out, which is
                // the only length a reader can check itself against.
                $objs[$embedded] = $this->stream($file['bytes'], sprintf(
                    ' /Type /EmbeddedFile /Subtype /%s /Params << /Size %d /CheckSum <%s> >>',
                    self::nameToken($file['mime']),
                    strlen($file['bytes']),
                    strtoupper(md5($file['bytes'])),
                ));

                $objs[$spec] = sprintf(
                    '<< /Type /Filespec /F %s /UF %s%s /EF << /F %d 0 R /UF %d 0 R >>'
                    . ' /AFRelationship /%s >>',
                    self::textString($file['name']),
                    self::textString($file['name']),
                    $file['description'] === '' ? '' : ' /Desc ' . self::textString($file['description']),
                    $embedded,
                    $embedded,
                    $file['relationship'],
                );

                $specs[] = sprintf('%d 0 R', $spec);
                $names[] = self::textString($file['name']) . sprintf(' %d 0 R', $spec);
            }

            $embeddedNames = $alloc();
            $nameTree      = $alloc();

            $objs[$embeddedNames] = sprintf('<< /Names [%s] >>', implode(' ', $names));
            $objs[$nameTree]      = sprintf('<< /EmbeddedFiles %d 0 R >>', $embeddedNames);

            /*
             * `/AF` beside `/Names` is what makes these ASSOCIATED files
             * rather than merely attached ones, and it is the half PDF/A-3
             * exists for: a reader that understands the relationship can tell
             * an e-invoice payload from a decorative extra. `/Names` alone
             * still opens in the attachments panel; the pair is what a
             * Factur-X or ZUGFeRD consumer looks for.
             */
            $filesEntry = sprintf(' /Names %d 0 R /AF [%s]', $nameTree, implode(' ', $specs));
        }

        $kids            = implode(' ', array_map(fn($i) => "$i 0 R", $pageObjs));
        $objs[$catalog]  = "<< /Type /Catalog /Pages $pagesObj 0 R$outlineEntry$structEntry$archivalEntry$viewEntry$filesEntry$acroEntry >>";
        $objs[$pagesObj] = sprintf("<< /Type /Pages /Kids [%s] /Count %d >>", $kids, count($pageObjs));

        foreach ($this->streams as $i => $stream) {
            // A page whose ink is all artifacts has no marked content on it,
            // and a /StructParents key for an empty parent tree entry is worse
            // than none: it points a reader at a list that is not there.
            $parents = $tagged && $this->structure->marksOn($i) > 0
                ? sprintf(' /StructParents %d', $i)
                : '';

            /*
             * A page with an annotation on it has to say what order a keyboard
             * reaches them in, and `/S` is "the structure tree's order", which
             * is the only one this writer has ever produced. PDF/UA-1 clause
             * 7.18.3 asks for it and nothing was writing it.
             */
            $tabs = ($annots[$i] ?? '') === '' ? '' : ' /Tabs /S';

            [$boxWidth, $boxHeight] = $this->pageSizes[$i] ?? [$this->pageWidth, $this->pageHeight];

            $objs[$pageObjs[$i]] = sprintf(
                "<< /Type /Page /Parent %d 0 R /MediaBox [0 0 %.2f %.2f] "
                . "/Resources %s /Contents %d 0 R%s%s%s >>",
                $pagesObj,
                $boxWidth,
                $boxHeight,
                $resources,
                $contentObjs[$i],
                $annots[$i] ?? '',
                $parents,
                $tabs,
            );

            $objs[$contentObjs[$i]] = $this->stream($stream);
        }

        if ($tagged) {
            $this->writeStructure($objs, $alloc, $structRoot, $pageObjs, $groupKeys, $groupStreams);
        }

        $infoEntry = '';

        if ($this->metadata !== []) {
            $infoObj         = $alloc();
            $objs[$infoObj]  = $this->infoDictionary();
            $infoEntry       = sprintf(' /Info %d 0 R', $infoObj);
        }

        /*
         * Encryption is the last thing that happens to the objects, because it
         * rewrites every string and every stream in all of them. The /Encrypt
         * dictionary is the one object that stays in the clear: a reader has
         * to read it before it holds the key.
         */
        $trailerExtra = '';
        $handler      = $this->encryption?->handler();

        if ($handler !== null) {
            $encryptObj = $alloc();

            foreach ($objs as $num => $body) {
                $objs[$num] = $handler->sealObject($body);
            }

            $objs[$encryptObj] = $handler->dictionary();
            $trailerExtra      = sprintf(
                ' /Encrypt %d 0 R /ID [<%s> <%s>]',
                $encryptObj,
                $handler->fileId(),
                $handler->fileId(),
            );
        }

        ksort($objs);

        /*
         * The header. An archival file is written against PDF 1.7, which is
         * the version ISO 19005-3 is a profile of, and it carries the binary
         * comment the format requires: four bytes above 127 on the line after
         * the version, which is how a tool that moves the file around knows
         * not to translate its line endings. Nothing else changes for a
         * document that did not ask for PDF/A, so every byte-level baseline in
         * this repository still holds.
         */
        $pdf = match (true) {
            $this->pdfa      => '%PDF-' . Pdfa::VERSION . "\n%\xE2\xE3\xCF\xD3\n",
            $handler !== null => "%PDF-2.0\n",
            default           => "%PDF-1.4\n",
        };

        $offsets = [];

        foreach ($objs as $num => $body) {
            $offsets[$num] = strlen($pdf);
            $pdf           .= "$num 0 obj\n$body\nendobj\n";
        }

        /*
         * The file identifier. An archival file has to carry one, and it is
         * derived from the bytes above rather than invented so that rendering
         * the same document twice still gives the same file: everything else
         * in this writer is deterministic and an identifier picked at random
         * would be the one thing that is not. Both halves are equal because
         * this is the file's first version, which is what the spec says a
         * writer should do.
         */
        if ($this->pdfa && $trailerExtra === '') {
            $fileId       = strtoupper(md5($pdf));
            $trailerExtra = sprintf(' /ID [<%s> <%s>]', $fileId, $fileId);
        }

        $total   = $next;
        $xrefPos = strlen($pdf);
        $pdf     .= "xref\n0 $total\n0000000000 65535 f \n";

        for ($i = 1; $i < $total; $i++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$i] ?? 0);
        }

        $pdf .= "trailer\n<< /Size $total /Root $catalog 0 R$infoEntry$trailerExtra >>\nstartxref\n$xrefPos\n%%EOF\n";

        return $pdf;
    }

    private const array INFO_KEYS = [
        'title'        => 'Title',
        'author'       => 'Author',
        'subject'      => 'Subject',
        'keywords'     => 'Keywords',
        'creator'      => 'Creator',
        'producer'     => 'Producer',
        'creationdate' => 'CreationDate',
        'moddate'      => 'ModDate',
    ];

    /**
     * The /Info dictionary.
     *
     * No date is invented here. Stamping CreationDate with "now" would make two
     * renders of the same document differ, which costs byte-identical output
     * for no benefit the caller cannot get by passing a date explicitly.
     */
    private function infoDictionary(): string
    {
        $body = '';

        foreach ($this->metadata as $key => $value) {
            $name = self::INFO_KEYS[strtolower($key)] ?? null;

            if ($name === null) {
                continue;
            }

            $body .= sprintf(
                '/%s %s ',
                $name,
                $name === 'CreationDate' || $name === 'ModDate'
                    ? '(' . self::esc(self::pdfDate($value)) . ')'
                    : self::textString($value),
            );
        }

        return '<< ' . $body . '>>';
    }

    /** `D:YYYYMMDDHHmmSS`. A value already in that form is passed through. */
    private static function pdfDate(string $value): string
    {
        if (str_starts_with($value, 'D:')) {
            return $value;
        }

        $stamp = strtotime($value);

        return $stamp === false ? $value : date('\D\:YmdHis', $stamp);
    }

    /**
     * The interactive form, and the `/AcroForm` entry the catalog needs for it.
     *
     * Controls sharing a `name` are **one** field with one widget each, which
     * is what a radio group is and what PDF spells a checkbox group as too. A
     * field with a single widget is written as one object, which the spec
     * allows and every reader expects; one with several needs a parent holding
     * the state and kids holding the rectangles.
     *
     * `/NeedAppearances` is deliberately absent. Every widget here carries the
     * ink the page would have drawn, so asking a reader to invent its own would
     * replace a measured appearance with a guess, and PDF/A forbids it anyway.
     *
     * @param array<int,string>            $objs
     * @param array<int,array<int,string>> $annotRefs
     * @param array<int,int>               $pageObjs
     */
    private function writeForm(
        array &$objs,
        callable $alloc,
        array &$annotRefs,
        array $pageObjs,
        string $resources,
        string $fontDict,
        ?int $nextStructKey,
    ): string {
        if ($this->widgets === []) {
            return '';
        }

        $groups = [];

        foreach ($this->widgets as $key => $widget) {
            if (isset($pageObjs[$widget['page']])) {
                $groups[$widget['field']->name][] = $widget + ['key' => $key];
            }
        }

        if ($groups === []) {
            return '';
        }

        $fieldRefs = [];

        foreach ($groups as $name => $widgets) {
            $field  = $widgets[0]['field'];
            $single = count($widgets) === 1;
            $fieldId = $alloc();
            $kidIds  = [];

            foreach ($widgets as $widget) {
                $id  = $single ? $fieldId : $alloc();
                $key = null;

                if ($nextStructKey !== null) {
                    $key                                = $nextStructKey++;
                    $this->widgetStructKeys[$widget['key']] = $key;
                }

                $objs[$id] = $this->widgetDictionary(
                    $widget,
                    $objs,
                    $alloc,
                    $pageObjs,
                    $resources,
                    $single ? $this->fieldEntries($field, $name) : sprintf(' /Parent %d 0 R', $fieldId),
                    $key,
                );

                $this->widgetObjects[$widget['key']] = $id;
                $annotRefs[$widget['page']][]        = sprintf('%d 0 R', $id);
                $kidIds[]                            = $id;
            }

            if (!$single) {
                $objs[$fieldId] = sprintf(
                    '<<%s /Kids [%s] >>',
                    $this->fieldEntries($field, $name),
                    implode(' ', array_map(static fn(int $id): string => "$id 0 R", $kidIds)),
                );
            }

            $fieldRefs[] = sprintf('%d 0 R', $fieldId);
        }

        return sprintf(
            ' /AcroForm << /Fields [%s] /DA (%s) /DR << /Font << %s>> >> >>',
            implode(' ', $fieldRefs),
            self::esc($this->widgets[array_key_first($this->widgets)]['da']),
            $fontDict,
        );
    }

    /**
     * A field's own entries: its type, its name, its flags and what it holds.
     *
     * These sit on the one object a single-widget field is, and on the parent
     * of a group, which is what makes a radio group's kids share one state.
     */
    private function fieldEntries(FormField $field, string $name): string
    {
        $entries = sprintf(
            ' /FT /%s /T %s /Ff %d',
            $field->type->fieldType(),
            self::textString($name),
            $field->flags(),
        );

        if ($field->maxLength !== null) {
            $entries .= sprintf(' /MaxLen %d', $field->maxLength);
        }

        if ($field->options !== []) {
            $pairs = array_map(
                fn(array $option): string => sprintf(
                    '[%s %s]',
                    self::textString($option[0]),
                    self::textString($option[1]),
                ),
                $field->options,
            );

            $entries .= sprintf(' /Opt [%s]', implode(' ', $pairs));
        }

        return $entries . $this->fieldValue($field);
    }

    /** `/V`, and `/DV` beside it so a reader's own reset button restores it. */
    private function fieldValue(FormField $field): string
    {
        $value = match (true) {
            $field->type->isToggle()      => $field->checked ? '/' . self::name($field->export) : '/Off',
            $field->type === FormFieldType::Combo,
            $field->type === FormFieldType::ListBox => match (count($field->selected)) {
                0       => '',
                1       => self::textString($field->selected[0]),
                default => '[' . implode(' ', array_map(self::textString(...), $field->selected)) . ']',
            },
            $field->writesValue() && $field->value !== '' => self::textString($field->value),
            default                                       => '',
        };

        return $value === '' ? '' : sprintf(' /V %s /DV %s', $value, $value);
    }

    /**
     * One widget annotation, with the appearance stream the page would have
     * drawn kept beside it.
     *
     * `/F 4` is the Print flag with Hidden and NoView clear, which every widget
     * gets rather than only an archival one: a form field that does not print
     * is not a form.
     *
     * @param array{field:FormField,page:int,rect:array{0:float,1:float,2:float,3:float},da:string,off:string,on:string,value:string} $widget
     * @param array<int,string> $objs
     * @param array<int,int>    $pageObjs
     */
    private function widgetDictionary(
        array $widget,
        array &$objs,
        callable $alloc,
        array $pageObjs,
        string $resources,
        string $own,
        ?int $structKey,
    ): string {
        [$x, $y, $w, $h] = $widget['rect'];
        $field           = $widget['field'];

        /*
         * The page's own coordinates, which is the one place a widget and the
         * box it stands over have to agree exactly. The far edges are the near
         * ones plus the size rather than two more roundings, so the rectangle
         * is exactly as big as the `/BBox` and a reader has nothing to scale.
         */
        [$left, $bottom] = $this->widgetCorner($x, $y, $h);

        $rect = sprintf(' /Rect [%.3f %.3f %.3f %.3f]', $left, $bottom, $left + $w, $bottom + $h);

        $appearance = $this->appearanceEntry($widget, $objs, $alloc, $resources);

        return sprintf(
            '<< /Type /Annot /Subtype /Widget%s%s /F 4 /P %d 0 R /DA (%s)%s%s%s >>',
            $own,
            $rect,
            $pageObjs[$widget['page']],
            self::esc($widget['da']),
            $field->tooltip === '' ? '' : ' /TU ' . self::textString($field->tooltip),
            $structKey === null ? '' : sprintf(' /StructParent %d', $structKey),
            $appearance,
        );
    }

    /**
     * `/AP`, and `/AS` where the widget has more than one state to be in.
     *
     * A checkbox has two appearance streams over one rectangle and the state
     * says which is showing. Everything else has one, and it is the control's
     * decoration with its value drawn on top in that order, whichever order the
     * painting walk reached the two boxes in.
     *
     * @param array{field:FormField,page:int,rect:array{0:float,1:float,2:float,3:float},da:string,off:string,on:string,value:string} $widget
     * @param array<int,string> $objs
     */
    private function appearanceEntry(array $widget, array &$objs, callable $alloc, string $resources): string
    {
        [, , $w, $h] = $widget['rect'];
        $field       = $widget['field'];

        $form = function (string $ops) use (&$objs, $alloc, $resources, $w, $h): int {
            $id = $alloc();

            $objs[$id] = $this->stream(
                $ops,
                sprintf(
                    ' /Type /XObject /Subtype /Form /FormType 1 /BBox [0 0 %.3f %.3f] /Resources %s',
                    $w,
                    $h,
                    $resources,
                ),
            );

            return $id;
        };

        if (!$field->type->isToggle()) {
            return sprintf(' /AP << /N %d 0 R >>', $form($widget['off'] . $widget['value']));
        }

        $export = self::name($field->export);

        return sprintf(
            ' /AP << /N << /Off %d 0 R /%s %d 0 R >> >> /AS /%s',
            $form($widget['off']),
            $export,
            $form($widget['on']),
            $field->checked ? $export : 'Off',
        );
    }

    /**
     * A PDF name. Anything outside the printable ASCII a name may hold is
     * written as `#xx`, which is what stops an export value an author chose
     * from breaking the dictionary around it.
     */
    private static function name(string $raw): string
    {
        $out = '';

        foreach (str_split($raw === '' ? 'Yes' : $raw) as $byte) {
            $code = ord($byte);

            $out .= $code > 0x20 && $code < 0x7F && !str_contains('#/()<>[]{}%', $byte)
                ? $byte
                : sprintf('#%02X', $code);
        }

        return $out;
    }

    /**
     * One /Link annotation, or null when it points at an anchor the document
     * never defined. A dead annotation is worse than none: it paints a
     * clickable region that silently does nothing.
     *
     * @param array{x:float,y:float,w:float,h:float,to:string,says:string} $link
     * @param array<int,int>                                               $pageObjs
     */
    private function annotation(array $link, array $pageObjs, ?int $structKey = null): ?string
    {
        $target = $this->destination($link['to'], $pageObjs);

        if ($target === null) {
            return null;
        }

        /*
         * PDF/A wants every annotation to say it prints and that it is neither
         * hidden nor kept off screen, which is bit 3 set and bits 2 and 6
         * clear. That is what a link on a page already means, so the flags are
         * only written where they are required rather than moved into every
         * document and past every baseline.
         */
        $flags = $this->pdfa ? ' /F 4' : '';

        /*
         * `/Contents` is what a reader speaks for the annotation, and PDF/UA-1
         * asks for it twice over: clause 7.18.1 wants every annotation to
         * carry one or an `/Alt` on the element around it, and 7.18.5 wants a
         * link's alternate description here. The link's own words are the
         * honest value, and a link with none (a picture inside an `<a>`) still
         * gets no entry rather than an invented one.
         */
        $says = trim(preg_replace('/\s+/u', ' ', $link['says'] ?? '') ?? '');

        return sprintf(
            '<< /Type /Annot /Subtype /Link /Rect [%.3f %.3f %.3f %.3f] /Border [0 0 0]%s%s%s %s >>',
            $link['x'],
            $this->ty($link['y'], $link['h']),
            $link['x'] + $link['w'],
            $this->ty($link['y']),
            $flags,
            $says === '' ? '' : ' /Contents ' . self::textString($says),
            $structKey === null ? '' : sprintf(' /StructParent %d', $structKey),
            $target,
        );
    }

    /** @param array<int,int> $pageObjs */
    private function destination(string $to, array $pageObjs): ?string
    {
        if (!str_starts_with($to, '#')) {
            return sprintf('/A << /S /URI /URI (%s) >>', self::esc($to));
        }

        $anchor = $this->anchors[substr($to, 1)] ?? null;

        if ($anchor === null || !isset($pageObjs[$anchor['page']])) {
            return null;
        }

        return sprintf(
            '/Dest [%d 0 R /XYZ 0 %.3f 0]',
            $pageObjs[$anchor['page']],
            $this->ty($anchor['y']),
        );
    }

    /**
     * Write the outline as the linked tree PDF wants: every item knows its
     * parent, its two neighbors and its first and last child, and /Count
     * carries the number of descendants that are visible when it is open.
     *
     * @param array<int,string> $objs
     * @param array<int,int>    $pageObjs
     */
    private function writeOutline(array &$objs, callable $alloc, int $rootId, array $pageObjs): void
    {
        $ids = [];

        foreach (array_keys($this->outline) as $i) {
            $ids[$i] = $alloc();
        }

        // Nest by level: an item's parent is the nearest preceding item that
        // sits at a shallower level, which a stack gives in one pass.
        $parentOf   = [];
        $childrenOf = [];
        $stack      = [];

        foreach ($this->outline as $i => $item) {
            while ($stack !== [] && $stack[count($stack) - 1]['level'] >= $item['level']) {
                array_pop($stack);
            }

            $parent           = $stack === [] ? -1 : $stack[count($stack) - 1]['index'];
            $parentOf[$i]     = $parent;
            $childrenOf[$parent][] = $i;
            $stack[]          = ['index' => $i, 'level' => $item['level']];
        }

        $descendants = function (int $index) use (&$descendants, $childrenOf): int {
            $total = 0;

            foreach ($childrenOf[$index] ?? [] as $child) {
                $total += 1 + $descendants($child);
            }

            return $total;
        };

        foreach ($this->outline as $i => $item) {
            $siblings = $childrenOf[$parentOf[$i]];
            $position = (int) array_search($i, $siblings, true);
            $kids     = $childrenOf[$i] ?? [];

            $body = '<< /Title ' . self::textString($item['title'])
                . sprintf(' /Parent %d 0 R', $parentOf[$i] === -1 ? $rootId : $ids[$parentOf[$i]]);

            if ($position > 0) {
                $body .= sprintf(' /Prev %d 0 R', $ids[$siblings[$position - 1]]);
            }

            if ($position < count($siblings) - 1) {
                $body .= sprintf(' /Next %d 0 R', $ids[$siblings[$position + 1]]);
            }

            if ($kids !== []) {
                $body .= sprintf(
                    ' /First %d 0 R /Last %d 0 R /Count %d',
                    $ids[$kids[0]],
                    $ids[$kids[count($kids) - 1]],
                    $descendants($i),
                );
            }

            if (isset($pageObjs[$item['page']])) {
                $body .= sprintf(
                    ' /Dest [%d 0 R /XYZ 0 %.3f 0]',
                    $pageObjs[$item['page']],
                    $this->ty($item['y']),
                );
            }

            $objs[$ids[$i]] = $body . ' >>';
        }

        $roots = $childrenOf[-1] ?? [];

        $objs[$rootId] = $roots === []
            ? '<< /Type /Outlines /Count 0 >>'
            : sprintf(
                '<< /Type /Outlines /First %d 0 R /Last %d 0 R /Count %d >>',
                $ids[$roots[0]],
                $ids[$roots[count($roots) - 1]],
                count($this->outline),
            );
    }

    /**
     * The structure tree, the parent tree and the elements between them.
     *
     * An element whose content is all on one page names that page once in
     * `/Pg` and lists bare marked-content ids, which is the compact spelling
     * and most of a real document. A box that straddles a fold has content on
     * two pages and no single page to name, so those references are written
     * out in full as `/MCR` dictionaries carrying a page each.
     *
     * `/ParentTree` is the same information read the other way: given a page
     * and a marked-content id, which element owns it. A reader needs it to
     * answer "what is under the cursor", and a validator refuses a tagged
     * document without it.
     *
     * A subtree composited as a transparency group has its marked content in
     * a form XObject rather than in the page's own stream, so its references
     * name that stream in `/Stm` and its ids are counted against a
     * parent-tree key of the form's own. The compact spelling cannot say
     * that, so an element holding one is written out in full.
     *
     * @param array<int,string> $objs
     * @param array<int,int>    $pageObjs
     * @param array<int,int>    $groupKeys    group stream => its parent-tree key
     * @param array<int,int>    $groupStreams group stream => its form object
     */
    private function writeStructure(
        array &$objs,
        callable $alloc,
        int $rootId,
        array $pageObjs,
        array $groupKeys,
        array $groupStreams,
    ): void {
        /** @var array<int,array<int,int>> content stream => mcid => the element that owns it */
        $owners = [];

        /** @var array<int,int> /StructParent => the element that owns that annotation */
        $annotOwners = [];

        $emit = function (array $element, int $parentId) use (
            &$emit,
            &$objs,
            $alloc,
            $pageObjs,
            $groupStreams,
            &$owners,
            &$annotOwners,
        ): int {
            $id   = $alloc();
            $kids = [];

            $pages   = [];
            $grouped = false;

            foreach ($element['k'] as $child) {
                if (isset($child['mcid']) && isset($pageObjs[$child['page']])) {
                    $pages[$child['page']] = true;
                    $grouped               = $grouped || $child['stream'] !== $child['page'];
                }
            }

            $onePage = count($pages) === 1 && !$grouped ? array_key_first($pages) : null;

            foreach ($element['k'] as $child) {
                /*
                 * A widget annotation has no marked content to point at, so the
                 * element points at the annotation object itself. The page has
                 * to be named here rather than left to `/Pg`, because an
                 * element holding only widgets never set one.
                 */
                if (isset($child['objr'])) {
                    $widgetObj = $this->widgetObjects[$child['objr']] ?? null;

                    if ($widgetObj === null || !isset($pageObjs[$child['page']])) {
                        continue;
                    }

                    $kids[] = sprintf(
                        '<< /Type /OBJR /Pg %d 0 R /Obj %d 0 R >>',
                        $pageObjs[$child['page']],
                        $widgetObj,
                    );

                    $structKey = $this->widgetStructKeys[$child['objr']] ?? null;

                    if ($structKey !== null) {
                        $annotOwners[$structKey] = $id;
                    }

                    continue;
                }

                // A link's annotations, one per line the anchor wraps over.
                if (isset($child['linkobjr'])) {
                    if (!isset($pageObjs[$child['page']])) {
                        continue;
                    }

                    foreach ($this->linkObjects[$child['linkobjr']] ?? [] as $at => $annotObj) {
                        $kids[] = sprintf(
                            '<< /Type /OBJR /Pg %d 0 R /Obj %d 0 R >>',
                            $pageObjs[$child['page']],
                            $annotObj,
                        );

                        $structKey = $this->linkStructKeys[$child['linkobjr']][$at] ?? null;

                        if ($structKey !== null) {
                            $annotOwners[$structKey] = $id;
                        }
                    }

                    continue;
                }

                if (isset($child['mcid'])) {
                    if (!isset($pageObjs[$child['page']])) {
                        continue;
                    }

                    $stream = $groupStreams[$child['stream']] ?? null;

                    $kids[] = $onePage === null
                        ? sprintf(
                            '<< /Type /MCR /Pg %d 0 R%s /MCID %d >>',
                            $pageObjs[$child['page']],
                            $stream === null ? '' : sprintf(' /Stm %d 0 R', $stream),
                            $child['mcid'],
                        )
                        : (string) $child['mcid'];

                    $owners[$child['stream']][$child['mcid']] = $id;

                    continue;
                }

                $kids[] = sprintf('%d 0 R', $emit($child, $id));
            }

            /*
             * A header cell says which cells it heads. PDF/UA-1 clause 7.5
             * refuses a table whose headers cannot be worked out, and Chrome's
             * own tagged export writes the same attribute.
             */
            $attributes = [];

            if (($element['scope'] ?? '') !== '') {
                $attributes[] = sprintf('/Scope /%s', $element['scope']);
            }

            /*
             * And which headers a DATA cell belongs to, which `/Scope` alone
             * cannot say once a table has two levels of header. PDF 1.7
             * §14.8.5.2.3 puts `Headers` in the same `/O /Table` dictionary and
             * makes it a list of the `/ID` strings the header cells carry.
             * Defect HA. No checker asks for either: `verapdf -f ua1` passes at
             * 106 of 106 rules with the scope alone.
             */
            if (($element['headers'] ?? []) !== []) {
                $attributes[] = sprintf(
                    '/Headers [%s]',
                    implode(' ', array_map(self::textString(...), $element['headers'])),
                );
            }

            $scope = $attributes === []
                ? ''
                : sprintf(' /A << /O /Table %s >>', implode(' ', $attributes));

            $objs[$id] = sprintf(
                '<< /Type /StructElem /S /%s /P %d 0 R%s%s%s%s /K [%s] >>',
                $element['role'],
                $parentId,
                $onePage === null ? '' : sprintf(' /Pg %d 0 R', $pageObjs[$onePage]),
                ($element['alt'] ?? '') === '' ? '' : ' /Alt ' . self::textString($element['alt']),
                ($element['id'] ?? '') === '' ? '' : ' /ID ' . self::textString($element['id']),
                $scope,
                implode(' ', $kids),
            );

            return $id;
        };

        $documentId = $emit(
            ['role' => 'Document', 'alt' => '', 'k' => $this->structure->elements($this->structureRoot)],
            $rootId,
        );

        $nums = '';

        $entry = function (int $stream, int $key) use (&$nums, $owners): void {
            $total = $this->structure->marksOn($stream);

            if ($total === 0) {
                return;
            }

            $refs = [];

            for ($mcid = 0; $mcid < $total; $mcid++) {
                // A mark whose element was pruned cannot happen, but a null
                // entry is what the spec asks for if one ever did.
                $refs[] = isset($owners[$stream][$mcid])
                    ? sprintf('%d 0 R', $owners[$stream][$mcid])
                    : 'null';
            }

            $nums .= sprintf('%d [%s] ', $key, implode(' ', $refs));
        };

        foreach (array_keys($pageObjs) as $page) {
            $entry($page, $page);
        }

        /*
         * An annotation's entry is a single element rather than an array of
         * them, because one annotation has one owner where a page has one per
         * marked-content id. The keys sit after the page ones, which is the
         * order `/Nums` has to be in.
         */
        ksort($annotOwners);

        foreach ($annotOwners as $key => $owner) {
            $nums .= sprintf('%d %d 0 R ', $key, $owner);
        }

        // A group's form XObject is a content stream of its own, so its own
        // ids get their own entry, and its key follows the widgets' for the
        // same reason theirs follow the pages'.
        asort($groupKeys);

        foreach ($groupKeys as $stream => $key) {
            $entry($stream, $key);
        }

        $parentTree      = $alloc();
        $objs[$parentTree] = sprintf('<< /Nums [%s] >>', trim($nums));

        $objs[$rootId] = sprintf(
            '<< /Type /StructTreeRoot /K [%d 0 R] /ParentTree %d 0 R /ParentTreeNextKey %d >>',
            $documentId,
            $parentTree,
            count($pageObjs) + $this->linkStructKeyCount() + count($this->widgetStructKeys) + count($groupKeys),
        );
    }

    /** Keeps copy/paste and search working on embedded-subset text. */
    /**
     * Map back to the letters the author wrote, for a face drawn as small
     * capitals: WinAnsi is a single-byte encoding, so the code on the page is
     * the capital and the CMap is the only place the lowercase survives.
     */
    private function smallCapsCMap(): string
    {
        $entries = '';
        $count   = 0;

        for ($code = 0; $code < 256; $code++) {
            $char = mb_convert_encoding(chr($code), 'UTF-8', 'Windows-1252');
            $low  = mb_strtolower($char);

            if ($low === $char || mb_strlen($low) !== 1) {
                continue;
            }

            $entries .= sprintf("<%02X> <%04X>\n", $code, mb_ord($low));
            $count++;
        }

        return $this->wrapCMap(sprintf("1 begincodespacerange\n<00> <FF>\nendcodespacerange\n%d beginbfchar\n%sendbfchar\n", $count, $entries));
    }

    private function wrapCMap(string $body): string
    {
        return $this->stream(
            "/CIDInit /ProcSet findresource begin\n"
            . "12 dict begin\nbegincmap\n"
            . "/CIDSystemInfo << /Registry (Adobe) /Ordering (UCS) /Supplement 0 >> def\n"
            . "/CMapName /Adobe-Identity-UCS def\n/CMapType 2 def\n"
            . $body
            . "endcmap\nCMapName currentdict /CMap defineresource pop\nend\nend\n",
        );
    }

    private function toUnicodeCMap(TrueTypeFont $face, bool $smallCaps = false): string
    {
        $map = $face->toUnicodeMap();
        ksort($map);
        $entries = '';
        $count   = 0;
        $chunks  = [];

        foreach ($map as $gid => $cps) {
            $hex = '';

            foreach ((array) $cps as $cp) {
                if ($smallCaps) {
                    $low = mb_strtolower(mb_chr($cp) ?: '');
                    $cp  = mb_strlen($low) === 1 ? mb_ord($low) : $cp;
                }

                $hex .= sprintf('%04X', $cp);
            }

            $entries .= sprintf("<%04X> <%s>\n", $gid, $hex);

            if (++$count === 100) {
                $chunks[] = [$count, $entries];
                $entries  = '';
                $count    = 0;
            }
        }

        if ($count > 0) {
            $chunks[] = [$count, $entries];
        }

        $body = '';

        foreach ($chunks as [$n, $text]) {
            $body .= "$n beginbfchar\n$text" . "endbfchar\n";
        }

        return $this->wrapCMap(
            "1 begincodespacerange\n<0000> <FFFF>\nendcodespacerange\n" . $body,
        );
    }
}
