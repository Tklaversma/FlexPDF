# Contributing

FlexPDF is in beta and the engine's internals move quickly. The most valuable
contribution right now is not code:

- **Found a rendering bug?** Open an issue with a small HTML sample that
  shows it. A trimmed-down sample is worth more than a patch: every fix here
  starts from a reproduction, gets measured against what a browser does with
  the same input, and lands with a regression test.
- **Missing a feature?** Check [ROADMAP.md](ROADMAP.md) first, then open an
  issue describing the document that needs it. Real documents are how the
  roadmap gets its order.

Both issue forms guide you through what to include.

## Sending code

Open an issue first, so we can agree on the approach before you spend time on
it. Then:

**1. Set up.** You need PHP 8.4, Composer, Python 3 and a DejaVu font:

```bash
git clone https://github.com/tklaversma/flexpdf.git && cd flexpdf
composer install

python3 -m venv .venv
.venv/bin/pip install pypdf pypdfium2 numpy pillow fonttools

# macOS                            # Debian/Ubuntu
brew install --cask font-dejavu    # sudo apt-get install fonts-dejavu-core
```

**2. Make the change.** For anything that affects rendering, the reference is
a browser, not an opinion: load the same HTML in Chrome, print it to PDF, and
take your expected numbers from there. "It looks right to me" is not a
measurement; "Chrome puts this box at 198.0pt and we put it at 258.0pt" is.

**3. Add a test that pins it.** Engine changes get a case in `tests/Engine/`
(see the style note below); Laravel-layer changes get a Pest test in
`tests/Feature/`. The test must fail without your change. Check that by
reverting the change and running the suite once.

**4. Make the checks green.** CI runs the first two on every pull request:

```bash
composer test          # the Laravel integration (Pest)
composer test-engine   # the 9 engine suites
composer analyse       # PHPStan
vendor/bin/pint --test # code style
```

**5. Open the PR** with: a link to the issue, the HTML sample, the browser
reference you measured against, and the test.

## The engine test style

The two test halves are deliberately different. The Laravel layer uses Pest,
like any Laravel package. The engine suites are plain PHP scripts on purpose:
each case builds a document, renders it, parses the PDF bytes back and
asserts against a number measured in a browser, with that measurement
documented in a comment next to the assertion. A failing case prints exactly
what moved. Please keep new engine cases in that style rather than porting
them to a framework.

## Security issues

Do not open a public issue. Use GitHub's private vulnerability reporting on
the repository's Security tab instead.
