# FlexPDF examples

Nine real documents, each a Blade template plus the exact builder chain that
renders it. Copy a view from `views/` and the matching function from
`render.php` into your app and you have a working starting point.

## Running them

```bash
composer install
php examples/render.php              # all nine, into examples/output/
php examples/render.php invoice      # one of them
php examples/render.php --html form  # also write the rendered HTML beside the PDF
```

`render.php` boots a minimal Laravel application through Orchestra Testbench,
the package's own development dependency, so `Pdf::view()` and Blade work here
exactly as they do in your app. Everything above the `document()` function in
that file is bootstrapping you will never write yourself.

## The documents

| Example | Pages | What it shows |
|---|---|---|
| `invoice` | 2 | Flex masthead and party blocks, an items table, a totals block with one VAT line per rate, a payment card, footnotes, a forced page break to an appendix with a `<tfoot>`, two-column justified terms with `<ol start>`, and a running footer from a Blade partial that knows the page count |
| `statement` | 3 | A 36-row table over three pages with a repeating `<thead>`, a running header that appears from page 2 only, summary cards, a bar chart in plain HTML and a sparkline in inline SVG |
| `report` | 5 | A cover with an image and an absolutely positioned plate, a contents list, a four-column CSS grid of figures, an SVG bar chart drawn from the data, justified and hyphenated two-column text with a drop cap, a pull quote, footnotes, a dark colophon; header and footer that skip the cover |
| `presentation` | 8 | A 16:9 sheet (`->page([720, 405])`), full-bleed image slides, dark and pale slide masters, a three-column grid of cards, bar rows, a timeline, a footer that skips the first and last slide |
| `catalog` | 3 | A two-column grid of product cards with images and badges that never split across a page, a comparison table with `rowspan`, price columns, a two-column FAQ |
| `showcase` | 3 | The CSS tour: type scale, five embedded families, justification against ragged setting, Arabic and Hebrew (both with a named font and through the bundled fallback), flexbox in every alignment including `order` and auto margins, grid with spans and template areas, balanced columns, floats, gradients, shadows, opacity, transforms, CSS variables, `calc()`, `clamp()`, `oklch()`, `color-mix()`, absolute positioning, nested lists, links, bookmarks, SVG |
| `form` | 1 | An application form whose `<input>`, `<select>` and `<textarea>` elements become real, fillable PDF form fields: text, password, checkbox, radio, drop-down |
| `e-invoice` | 2 | The invoice as PDF/A-3, tagged for accessibility, with a Factur-X style XML attached as an associated file, opening on the attachments panel |
| `protected` | 2 | The invoice encrypted with AES-256: password `reader`, printing allowed, copying and editing not |

## What to copy into your app

1. The view, into `resources/views/`. Every template is plain HTML and CSS
   with ordinary Blade in it; nothing is specific to this folder.
2. The builder chain from the matching function in `render.php`, minus the
   `document()` wrapper: the fonts and `basePath` it sets belong in
   `config/flexpdf.php` in an app, under `fonts` and `base_path`.
3. Images referenced as `<img src="mark.png">` resolve against `base_path`, so
   put them in the directory the config points at.

## Things worth knowing before you write your own template

- **A running header or footer is styled by the document's own stylesheet.**
  A `<style>` block inside the header or footer markup is dropped. Put the
  rules in the document's `<style>`, or pass them with `->css()` when the
  header is a plain string, which is what `runningStyles()` does here.
- **`box-sizing` is `content-box` by default, exactly as in a browser.** A
  slide sized `height: 405pt` with `padding: 34pt` is 473pt tall without a
  `* { box-sizing: border-box }` reset. The slide and catalog templates carry
  that reset for this reason.
- **A block you never want split across pages gets `break-inside: avoid`.**
  The product cards, the payment block and the pull quote use it.
- **The showcase's float section is wrapped in `break-inside: avoid` on
  purpose.** Without it, a paragraph beside a float lands below the float
  when a page break follows later in the document. That is an open engine
  defect, listed in [ROADMAP.md](../ROADMAP.md); the wrapper is the
  workaround until it is fixed.
- **Inline `<svg>` is the easy way to draw a chart.** `linearGradient`,
  `path`, `polyline`, `polygon`, `text` and `opacity` all work, and the data
  can be written into the SVG from Blade like anywhere else.
- **The fonts here are only needed by these templates.** The package draws
  with Helvetica, Times, Courier and the bundled DejaVu Sans with no font
  files at all; register your own when you want your own typeface.

## Fonts and images

`fonts/` holds IBM Plex Sans, IBM Plex Mono, IBM Plex Sans Arabic, IBM Plex
Sans Hebrew and Libre Baskerville, all under the SIL Open Font License, with
the two licence files beside them. `images/` holds the generated artwork the
templates reference. Neither is part of the package you install with
Composer; they exist for these examples only.
