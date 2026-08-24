<?php

declare(strict_types=1);

namespace FlexPDF\Engine\Exceptions;

use RuntimeException;

/**
 * The document asked for PDF/A and something in it cannot be written that way,
 * so refuse rather than hand back a file that claims a conformance it does not
 * have.
 *
 * That is the whole point of the format. A PDF/A file says in its own metadata
 * that a validator will pass it, and a caller who archives one on that promise
 * has no way to find out later that it was never true. Both refusals here are
 * cases where writing anything at all would be writing that lie.
 */
final class PdfaConformanceException extends RuntimeException
{
    /**
     * A base-14 face reached the writer.
     *
     * PDF/A needs every font embedded and this package has the metrics for
     * Helvetica, Times and Courier without having the outlines: the widths
     * were generated from the Adobe AFMs and no font file was ever needed,
     * because a reader supplies those faces itself. A conforming file cannot
     * lean on the reader for them.
     */
    public static function unembeddableFont(string $face): self
    {
        // A base-14 face is named for its style, `Helvetica-Bold`, and the
        // registry is keyed by family, so the advice has to name the family or
        // it sends the caller to register something nothing will ask for.
        $family = explode('-', $face)[0];

        return new self(
            sprintf(
                'PDF/A requires every font to be embedded and this document reaches [%s], '
                . 'one of the 14 standard faces, which this package has metrics for and no '
                . 'font file for. Register a TrueType family under the name the document '
                . 'asks for, ->font(\'%s\', \'/path/to/Regular.ttf\', \'/path/to/Bold.ttf\'), '
                . 'or give the document a font-family that is registered already, and render '
                . 'it again.',
                $face,
                $family,
            ),
        );
    }

    /**
     * Both `pdfa()` and `encrypt()` were asked for.
     *
     * PDF/A forbids encryption outright, in every part and at every level, so
     * these two are not a combination that produces a weaker document: they
     * produce one no validator accepts. Refusing is the only answer that does
     * not silently drop one of the two things the caller asked for.
     */
    public static function encrypted(): self
    {
        return new self(
            'PDF/A forbids encryption, so a document cannot be both archival and '
            . 'password protected. Drop one of ->pdfa() and ->encrypt().',
        );
    }

    /**
     * A conformance level was claimed that this document cannot back.
     *
     * Level A and PDF/UA-1 are both claims about the document's structure being
     * meaningful, and both need the structure tree and a language a reader can
     * announce the text in. Writing the claim anyway would put a promise in the
     * metadata that the first validator to read the file contradicts, which is
     * the one failure this whole class exists to prevent.
     */
    public static function unclaimable(string $claim, string $missing): self
    {
        return new self(
            sprintf(
                '%s needs %s and this document has none. Call ->tagged(true, \'en\') with the '
                . 'document\'s own language, or give the markup an <html lang> attribute, and '
                . 'render it again. Drop the claim with ->pdfa() on its own, which writes level '
                . 'B, if the document should not make it.',
                $claim,
                $missing,
            ),
        );
    }
}
