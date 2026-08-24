# Changelog

All notable changes to `FlexPDF` will be documented in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and the package uses [Semantic Versioning](https://semver.org/spec/v2.0.0.html).
While the major version is 0, minor releases may change the public API.

## [Unreleased]

## [0.1.3] - 2026-08-24

### Fixed

- A paragraph beside a float landed below the float, by exactly the float's
  height, when a page break followed later in the document. The section no
  longer needs a `break-inside: avoid` wrapper, and the showcase example lost
  its one.

## [0.1.2] - 2026-08-24

### Added

- `examples/`: nine complete documents (invoice, bank statement, annual report,
  slide deck, product catalog, CSS showcase, fillable form, PDF/A-3 e-invoice
  with an attached XML, encrypted document), each a Blade template plus the
  builder chain that renders it. `php examples/render.php` renders them into
  `examples/output/` from a plain clone by booting Laravel through Testbench.
- `PdfBuilder::markup()` returns the HTML the builder will render, for writing
  the markup beside the PDF while debugging a template.
- Dependabot configuration for GitHub Actions and Composer dev dependencies.

### Changed

- The CI workflow pins every third-party action to a commit SHA and runs with
  `permissions: contents: read`.
- The README gained an "Examples" section and the roadmap lists the one known
  layout defect: a paragraph beside a float lands below the float when a page
  break follows later in the document (wrap the section in
  `break-inside: avoid` until it is fixed).

### Removed

- The seven earlier example scripts (`examples/*.php` writing PDFs into
  `examples/`) were replaced by the Blade documents above.

## [0.1.1] - 2026-08-24

First public release.

### Added

- A pure PHP HTML to PDF engine with a CSS layout engine: block and inline
  layout, floats, flexbox, CSS grid, tables, transforms, `@page` with named
  pages, margin boxes, page counters and running headers and footers, forced
  and avoided page breaks, and multi-column text.
- Fonts: TrueType and OpenType embedding with subsetting, `@font-face`, a
  bundled DejaVu family with per-character fallback, and text shaping for
  Latin, Cyrillic, Greek, Arabic and Hebrew including bidirectional text.
- PDF features: bookmarks from headings, link annotations, document metadata,
  tagged PDF and PDF/UA-1, PDF/A-3 (level B or A), AES-256 encryption, AcroForm
  fields, file attachments with `/AF` relationships for Factur-X and ZUGFeRD,
  the initial view and page mode.
- A Laravel layer: the `Pdf` facade and `PdfBuilder` with `view()`, `html()`,
  `page()`, `font()`, `save()`, `download()`, `inline()` and the document
  options above, plus a publishable `config/flexpdf.php`.
- Controls for rendering untrusted HTML: ceilings on pages, nesting depth,
  lengths and wall-clock time that throw named exceptions, file access scoped
  to `base_path`, and remote images off by default behind a host allowlist.

[Unreleased]: https://github.com/Tklaversma/FlexPDF/compare/v0.1.3...HEAD
[0.1.3]: https://github.com/Tklaversma/FlexPDF/compare/v0.1.2...v0.1.3
[0.1.2]: https://github.com/Tklaversma/FlexPDF/compare/v0.1.1...v0.1.2
[0.1.1]: https://github.com/Tklaversma/FlexPDF/releases/tag/v0.1.1
