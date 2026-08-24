<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Page setup
    |--------------------------------------------------------------------------
    |
    | Defaults for every document. The size is a named size ('a4', 'letter',
    | 'legal', 'a3', 'a5') or a [width, height] pair in points. Margins are in
    | points; 72pt is one inch.
    |
    */

    'page' => [
        'size'        => 'a4',
        'orientation' => 'portrait',
    ],

    'margins' => [
        'top'    => 50,
        'right'  => 50,
        'bottom' => 50,
        'left'   => 50,
    ],

    /*
    |--------------------------------------------------------------------------
    | Remote images
    |--------------------------------------------------------------------------
    |
    | Whether an <img src> may name an https URL, and which hosts it may name.
    | Off by default, and an empty allowlist with it on reaches nothing rather
    | than everything: a URL in a document is author-controlled, so fetching
    | one sends a request from your network to somewhere the document chose.
    |
    | https only, an exact host match, private and link-local addresses
    | refused, no redirect following, the body capped while it is read, and the
    | fetch bounded by the render's own timeout. A stylesheet and a font are
    | never fetched whatever this says.
    |
    */

    'remote_images' => [
        'enabled'       => false,
        'allowed_hosts' => [],
        'max_bytes'     => 2_000_000,
        'timeout'       => 5.0,
    ],

    /*
    |--------------------------------------------------------------------------
    | Base path
    |--------------------------------------------------------------------------
    |
    | The only directory a rendered document may read files from. A <link>
    | href, an <img src> and an @font-face url() are all author-controlled, so
    | each is resolved against this path and refused if it lands outside it.
    | Keep it as narrow as the templates allow: an empty value reaches nothing.
    |
    */

    'base_path' => public_path(),

    /*
    |--------------------------------------------------------------------------
    | Fonts
    |--------------------------------------------------------------------------
    |
    | TrueType families registered at boot, keyed by the family name used in
    | CSS. Only 'regular' is required; the engine degrades one axis at a time
    | when a requested weight or style is missing.
    |
    |   'Inter' => [
    |       'regular'     => resource_path('fonts/Inter-Regular.ttf'),
    |       'bold'        => resource_path('fonts/Inter-Bold.ttf'),
    |       'italic'      => resource_path('fonts/Inter-Italic.ttf'),
    |       'bold-italic' => resource_path('fonts/Inter-BoldItalic.ttf'),
    |   ],
    |
    | A 'width' entry beside the paths says which font-stretch the files are,
    | as a keyword or a percentage. It is what lets one family hold two
    | widths: without it the second registration replaces the first and every
    | word comes out in whichever files were registered last.
    |
    |   'Inter Condensed' is a different family; the same family at two widths
    |   is the same key registered twice, which config cannot express, so the
    |   second one goes through ->font('Inter', [...], 'condensed') on the
    |   builder.
    |
    | The 14 standard PDF fonts (Helvetica, Times, Courier) need no
    | registration, but cover Latin-1 only.
    |
    */

    'fonts' => [
        //
    ],

    /*
    |--------------------------------------------------------------------------
    | Font fallback
    |--------------------------------------------------------------------------
    |
    | What happens when the family a document resolved cannot draw one of its
    | characters. A font-family list resolves to ONE family and stops, which is
    | what the property means, but the family that wins is not necessarily one
    | that covers the text: 'Arial' resolves to the standard Helvetica, which is
    | written with WinAnsi and has no slot for Polish, Greek, Cyrillic, Hebrew
    | or Arabic. Without a fallback those print as question marks.
    |
    | So the fallback happens per CHARACTER. The family a document asked for
    | still draws everything it can, and only the characters it cannot are
    | looked for in the bundled DejaVu Sans, which carries Latin, Greek,
    | Cyrillic, Hebrew and Arabic. A document whose own faces cover its text
    | never reaches it and is written byte for byte as it was.
    |
    | The bundled faces live in the package's own resources/fonts and carry
    | their licence beside them. Nothing is ever fetched.
    |
    |   enabled  Off restores the older behaviour: an unavailable character is
    |            painted as '?' on a standard face and as an empty box on an
    |            embedded one.
    |   strict   Refuse the render when a font-family list resolves to no
    |            registered face at all, or a character no face can draw reaches
    |            a page. Off by default, because a font-family list is written
    |            so that entries CAN miss. What misses is always available from
    |            ->fontReport() whether this is on or not.
    |
    | Both are overridable per document with ->fontFallback() and
    | ->strictFonts(). Strict throws
    | FlexPDF\Engine\Exceptions\FontMissingException.
    |
    */

    'font_fallback' => [
        'enabled' => true,
        'strict'  => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Document metadata
    |--------------------------------------------------------------------------
    |
    | Written to the PDF's /Info dictionary, and overridable per document with
    | ->metadata([...]). Recognised keys are title, author, subject, keywords,
    | creator, producer, creationDate and modDate.
    |
    | No date is written unless you set one, which keeps two renders of the
    | same document byte-identical.
    |
    */

    'metadata' => [
        //
    ],

    /*
    |--------------------------------------------------------------------------
    | Tagged PDF
    |--------------------------------------------------------------------------
    |
    | Whether every document carries its structure as well as its ink. A tagged
    | document says which of its marks are a heading, a paragraph or a table
    | cell, and in what reading order, which is what a screen reader, a reflow
    | view and an HTML export need, and what PDF/UA and PDF/A-1a require.
    |
    | Roles come from the element: <p> is a P, <th> a TH, <img> a Figure
    | carrying its own alt as /Alt. Backgrounds, borders and running headers
    | are marked as decoration so a reader skips them.
    |
    | 'lang' is written to /Lang and names the document's language for a reader
    | that has to pronounce it. Leave it empty and the document's own
    | <html lang> is used.
    |
    | Off by default: tagging rewrites every content stream, so a document's
    | bytes change the day it is turned on. Overridable per document with
    | ->tagged().
    |
    */

    'tagged' => [
        'enabled' => false,
        'lang'    => '',
    ],

    /*
    |--------------------------------------------------------------------------
    | PDF/A
    |--------------------------------------------------------------------------
    |
    | Whether every document is written for archiving: PDF/A-3b, ISO 19005-3
    | level B. A conforming file carries everything it needs to be drawn the
    | same way in fifty years, which is what an archive, a tax authority or a
    | procurement portal asks for when it says "PDF/A".
    |
    | What it adds: every font embedded, an sRGB profile saying what the
    | document's colors mean, an XMP metadata stream declaring the conformance
    | so an archive can check the claim without opening the pages, and a file
    | identifier. It also turns tagging on, since an archival document should
    | say what its ink means as well as where it went; call ->tagged(false)
    | after ->pdfa() for a conforming file without the structure tree.
    |
    | Two things it refuses rather than quietly dropping, because writing
    | anything at all would be writing a claim that is not true:
    |
    |   A document that reaches one of the 14 standard faces (Helvetica, Times,
    |   Courier) has no font file to embed, since this package has the metrics
    |   for those and not the outlines. Register a TrueType family under
    |   'fonts' above and give the document a font-family that names it.
    |
    |   PDF/A forbids encryption in every part and at every level, so this and
    |   'encryption' cannot both be on for one document.
    |
    | Both throw FlexPDF\Engine\Exceptions\PdfaConformanceException.
    |
    | Off by default, and overridable per document with ->pdfa().
    |
    | 'conformance' is the level the file claims, 'B' or 'A'. Level B says the
    | document will be drawn the same way forever; level A says its structure is
    | meaningful as well, so a reader can take it apart and read it out loud.
    | 'ua' claims ISO 14289-1, PDF/UA-1, beside it, which is the accessibility
    | standard a public body usually means when it asks for an accessible PDF.
    |
    | Both are promises rather than settings, and both are refused when the
    | document cannot back them: each needs the structure tree and a language,
    | so set 'tagged.enabled' and 'tagged.lang' with them, or give the markup
    | an <html lang> attribute.
    |
    */

    'pdfa' => [
        'enabled'     => false,
        'conformance' => 'B',
        'ua'          => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Encryption
    |--------------------------------------------------------------------------
    |
    | Whether every document is encrypted, and what a reader may let the
    | recipient do with one. Off by default, and overridable per document with
    | ->encrypt(...), which is where a password that differs per invoice
    | belongs.
    |
    | AES-256 at revision 6 is the one handler. The older revisions encrypt
    | page content with AES too, but derive the file key with MD5 and compute
    | the password checks with RC4, so there is nothing weak to reach here.
    | An encrypted document is written as %PDF-2.0 and reads in Acrobat 9 and
    | later, macOS Preview, pdf.js, pdfium and Ghostscript.
    |
    |   user_password   Asked for when the document is opened. Empty means it
    |                   opens without one and the permissions below still hold.
    |   owner_password  Opens the document with every restriction lifted.
    |                   Leave it empty and one nobody holds is invented, which
    |                   is what keeps the permissions meaningful: a reader
    |                   ignores all of them for whoever knows this one.
    |   allow           print, copy, modify, annotate, fill_forms, assemble,
    |                   print_high_quality. Extracting text for accessibility
    |                   is always allowed, which PDF 2.0 requires.
    |
    | An encrypted document is not byte-reproducible. The file key, the salts
    | and every initialization vector are random per render, which is what
    | stops two documents sharing key material.
    |
    */

    'encryption' => [
        'enabled'        => false,
        'user_password'  => '',
        'owner_password' => '',
        'allow'          => [
            'print',
            'copy',
            'modify',
            'annotate',
            'fill_forms',
            'assemble',
            'print_high_quality',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Safety limits
    |--------------------------------------------------------------------------
    |
    | These are security controls, not performance tuning.
    |
    | Layout is a fixpoint computation and some inputs never converge. Without
    | these ceilings a few KB of hostile HTML consumes unbounded CPU. If you
    | render HTML that any user can influence, treat every value here as part
    | of your attack surface, and lower them rather than raise them.
    |
    |   max_pages        Pagination that will not terminate is stopped here.
    |                    Throws PageLimitExceededException rather than
    |                    returning a document quietly missing its tail.
    |   max_depth        Recursion ceiling for pathologically nested boxes.
    |   max_length       Largest resolved CSS length, in points. Absurd
    |                    lengths produce flows that cannot be paginated.
    |   max_font_size    Largest font-size honored, in points. Past this a
    |                    single line box exceeds any page.
    |   timeout_seconds  Wall-clock budget for one render, checked at the loop
    |                    guards where non-convergence shows up. Throws
    |                    LayoutTimeoutException when exceeded. Set to 0 to
    |                    disable, which is only safe for trusted input.
    |   max_gradient_stops
    |                    How many gradient color stops one render may keep.
    |                    A tiled repeating gradient asks for thousands per
    |                    box per page, so a long document runs out of memory
    |                    painting them. Throws
    |                    GradientLimitExceededException rather than dying.
    |
    | The timeout covers layout and pagination. Parsing a hostile font or SVG
    | happens outside those loops and is not yet bounded by it.
    |
    */

    'limits' => [
        'max_pages'          => 2000,
        'max_depth'          => 64,
        'max_length'         => 200000.0,
        'max_font_size'      => 2000.0,
        'timeout_seconds'    => 30.0,
        'max_gradient_stops' => 500000,
    ],

];
