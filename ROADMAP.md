# FlexPDF roadmap

FlexPDF is in beta and in active development. This is everything the
[README](README.md) marks 🚧 (not yet) or ⚠️ (partial), collected in one
place: what happens today until it lands, and where it sits in the queue.

**Status**: 🔨 up next, being worked on now · 📋 planned · 💤 on request,
built when real documents ask for it

| Coming | What happens today | Status |
|---|---|---|
| A stable 1.0 API | in beta, names may still shift | 🔨 up next |
| Factur-X / ZUGFeRD XMP profile schema | the two big halves, PDF/A-3 and the XML attachment, already work | 🔨 up next |
| Line wrapping per named `@page` width | the sheet size is right, text wraps at the document's width | 🔨 up next |
| `radial-gradient()` | renders as no background | 📋 planned |
| `filter` (`grayscale()`, `blur()`, ...) | the declaration is dropped | 📋 planned |
| `text-shadow` blur | the shadow is drawn sharp | 📋 planned |
| Layer ordering for `@layer` | the rules apply, on their own specificity | 📋 planned |
| Subgrid | the declaration is dropped | 💤 on request |
| CJK (Chinese, Japanese, Korean) | not supported | 💤 on request |
| Indic scripts, Thai, Khmer | not supported | 💤 on request |
| Vertical writing, ruby | not supported | 💤 on request |

No dates: within each group, what real documents run into first gets built
first. Something in the 💤 group blocking a real project of yours? Open an
issue: that is exactly what moves it up.

Two things are **by design and not on this list**: an encrypted document is
not byte-reproducible (key material must be random), and the output never
contains JavaScript.
