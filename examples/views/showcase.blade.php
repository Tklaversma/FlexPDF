<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>FlexPDF showcase</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: 'Plex Sans'; font-size: 9pt; line-height: 1.5; color: #16241d; }

        h1 { font-family: 'Baskerville'; font-size: 26pt; margin: 0 0 4pt; letter-spacing: -0.5pt; }
        .strap { font-size: 9.5pt; color: #56655e; margin-bottom: 4pt; }
        .rule { height: 2pt; background: #2f6f4e; margin: 10pt 0 16pt; }

        h2 { font-size: 8pt; letter-spacing: 1.8pt; text-transform: uppercase; color: #2f6f4e;
             border-bottom: 0.5pt solid #dbe6e0; padding-bottom: 4pt; margin: 18pt 0 9pt; }
        .rule + h2 { margin-top: 0; }
        .note { font-size: 7.4pt; color: #9aa8a1; margin-top: 4pt; }

        /* Type scale */
        .scale div { margin-bottom: 1pt; }
        .s40 { font-family: 'Baskerville'; font-size: 26pt; letter-spacing: -0.6pt; }
        .s24 { font-family: 'Baskerville'; font-size: 17pt; }
        .s16 { font-size: 13pt; font-weight: bold; }
        .s12 { font-size: 10.5pt; }
        .s9  { font-size: 9pt; }
        .s7  { font-size: 7pt; color: #56655e; }

        /* Embedded families */
        .faces { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10pt; }
        .face { border: 0.5pt solid #e4ece8; padding: 8pt 9pt; }
        .face .k { font-size: 6.4pt; letter-spacing: 1pt; text-transform: uppercase; color: #9aa8a1; margin-bottom: 3pt; }
        .face .sample { font-size: 11pt; }
        .face.sans .sample { font-family: 'Plex Sans'; }
        .face.serif .sample { font-family: 'Baskerville'; }
        .face.mono .sample { font-family: 'Plex Mono'; }
        .face .styles { font-size: 8pt; color: #56655e; margin-top: 4pt; }

        /* Justification and hyphenation */
        .two { display: flex; gap: 18pt; }
        .two > div { flex: 1; }
        .measure { font-size: 8pt; }
        .measure.just { text-align: justify; hyphens: auto; }
        .measure.ragged { text-align: left; }

        /* Right-to-left scripts. The Arabic and Hebrew faces are registered
           explicitly here; leave them out and the bundled DejaVu Sans draws
           these characters instead, with no configuration at all. */
        .bidi p { margin: 0 0 5pt; }
        .ar { font-family: 'Plex Arabic'; font-size: 11pt; direction: rtl; }
        .he { font-family: 'Plex Hebrew'; font-size: 11pt; direction: rtl; }
        .fallback { font-size: 11pt; direction: rtl; }

        table.nums { width: 100%; border-collapse: collapse; break-inside: avoid; }
        table.nums th { font-size: 6.4pt; letter-spacing: 1pt; text-transform: uppercase; color: #56655e;
                        text-align: left; border-bottom: 0.5pt solid #cddbd4; padding: 4pt 6pt; }
        table.nums th.r, table.nums td.r { text-align: right; }
        table.nums td { padding: 4pt 6pt; border-bottom: 0.5pt solid #f0f5f2; font-size: 8pt; }
        table.nums td.r { font-family: 'Plex Mono'; }
        table.nums tfoot td { font-weight: bold; border-top: 0.5pt solid #2f6f4e; border-bottom: none; }

        /* Flexbox */
        .flexrow { display: flex; gap: 6pt; margin-bottom: 5pt; }
        .flexrow > div { background: #eef4f1; padding: 5pt; font-size: 7.5pt; }
        .flexrow.grow > div { flex: 1; }
        .flexrow.between { justify-content: space-between; }
        .flexrow.center { justify-content: center; }
        .flexrow.mixed div:nth-child(2) { flex: 2; background: #d9e9e1; }
        .flexrow.ordered div:first-child { order: 3; background: #d9e9e1; }
        .flexrow.pushed div:last-child { margin-left: auto; background: #d9e9e1; }

        /* Grid */
        .gridbox { display: grid; grid-template-columns: repeat(4, 1fr); gap: 5pt; }
        .gridbox > div { background: #f5f9f7; padding: 5pt; font-size: 7.5pt; }
        .gridbox .span2 { grid-column: span 2; background: #dcece4; }
        .areas { display: grid; grid-template-areas: "head head side" "main main side"; grid-template-columns: 1fr 1fr 90pt; gap: 5pt; margin-top: 5pt; }
        .areas .h { grid-area: head; background: #2f6f4e; color: #fff; padding: 5pt; font-size: 7.5pt; }
        .areas .m { grid-area: main; background: #eef4f1; padding: 5pt; font-size: 7.5pt; }
        .areas .s { grid-area: side; background: #dcece4; padding: 5pt; font-size: 7.5pt; }

        /* Multi-column */
        .cols3 { column-count: 3; column-gap: 14pt; font-size: 7.6pt; text-align: justify; hyphens: auto; color: #56655e; }

        /* Effects */
        .fx { display: flex; gap: 8pt; margin-top: 4pt; }
        .fx > div { flex: 1; height: 54pt; padding: 6pt; font-size: 7.5pt; }
        .fx .grad { background: linear-gradient(135deg, #2f6f4e, #8fd6b4); color: #fff; }
        .fx .radius { background: #dcece4; border-radius: 10pt; }
        .fx .shadowbox { background: #fff; border: 0.75pt solid #16241d; box-shadow: 4pt 4pt 0 #e8a33d; }
        .fx .opacity { background: #2f6f4e; color: #fff; opacity: 0.45; }
        .fx .turn { background: #ffe9c9; transform: rotate(-3deg); border: 0.5pt solid #e8a33d; }
        .textshadow { font-family: 'Baskerville'; font-size: 20pt; text-shadow: 2pt 2pt 0 #cfe6da; margin-top: 8pt; }

        /* Modern values: variables, calc(), clamp(), oklch(), color-mix() */
        :root { --accent: #2f6f4e; --pad: 5pt; }
        .values { display: flex; gap: 8pt; margin-top: 4pt; }
        .values > div { flex: 1; padding: var(--pad); font-size: 7.5pt; color: #fff; }
        .values .v1 { background: var(--accent); }
        .values .v2 { background: color-mix(in srgb, var(--accent), white 40%); color: #16241d; }
        .values .v3 { background: oklch(0.72 0.12 160); color: #16241d; }
        .values .v4 { background: hsl(38, 80%, 55%); width: clamp(60pt, 25%, 120pt); flex: none; }
        .values .v5 { background: #e8a33d80; color: #16241d; padding: calc(var(--pad) * 2); }

        /* Floats */
        .floatbox { float: right; width: 130pt; background: #f5f9f7; padding: 8pt; margin: 0 0 6pt 10pt; font-size: 7.6pt; }
        .flowtext { font-size: 8pt; text-align: justify; hyphens: auto; }

        .keep { break-inside: avoid; }

        /* Absolute positioning inside a relative box */
        .stack { position: relative; height: 96pt; background: #f5f9f7; margin-top: 4pt; break-inside: avoid; }
        .stack .abs { position: absolute; padding: 5pt; font-size: 7.5pt; }
        .stack .one { left: 8pt; top: 8pt; background: #2f6f4e; color: #fff; }
        .stack .two { left: 40pt; top: 26pt; background: #e8a33d; }
        .stack .three { left: 72pt; top: 44pt; background: #16241d; color: #fff; }
        .stack .corner { right: 8pt; bottom: 8pt; background: #ffffff; border: 0.5pt solid #cddbd4; }

        /* Lists */
        ul.marks { padding-left: 12pt; font-size: 8pt; margin: 0; }
        ol.marks { padding-left: 14pt; font-size: 8pt; margin: 0; }
        .marks ul, .marks ol { padding-left: 12pt; margin: 2pt 0 2pt; }
        .marks ul li, .marks ol li { color: #56655e; }
        .marks ul ul, .marks ol ol, .marks ol ul, .marks ul ol { margin: 1pt 0 1pt; }
        ol.roman { list-style-type: lower-roman; }
        ul.square { list-style-type: square; }
        dl { margin: 0; font-size: 8pt; }
        dt { font-weight: bold; }
        dd { margin: 0 0 4pt 12pt; color: #56655e; }

        /* Links */
        .links { font-size: 8pt; }
        .links a { color: #2f6f4e; }
    </style>
</head>
<body>

<h1>FlexPDF showcase</h1>
<div class="strap">What the engine does with a real template, in one place and at actual size.</div>
<div class="rule"></div>

<h2>Type scale</h2>
<div class="scale">
    <div class="s40">Bookkeeping without the fuss</div>
    <div class="s24">Bookkeeping without the fuss</div>
    <div class="s16">Bookkeeping without the fuss</div>
    <div class="s12">Bookkeeping without the fuss, in Plex Sans at 10.5 point</div>
    <div class="s9">Bookkeeping without the fuss, in Plex Sans at 9 point, the size of the body text</div>
    <div class="s7">Bookkeeping without the fuss, in Plex Sans at 7 point, for the small print</div>
</div>

<h2>Embedded families</h2>
<div class="faces">
    <div class="face sans">
        <div class="k">Plex Sans</div>
        <div class="sample">Handwritten 0123</div>
        <div class="styles"><b>bold</b> &nbsp; <i>italic</i> &nbsp; <b><i>bold italic</i></b></div>
    </div>
    <div class="face serif">
        <div class="k">Libre Baskerville</div>
        <div class="sample">Handwritten 0123</div>
        <div class="styles"><b>bold</b> &nbsp; <i>italic</i></div>
    </div>
    <div class="face mono">
        <div class="k">Plex Mono</div>
        <div class="sample">Handwritten 0123</div>
        <div class="styles"><b>bold</b> &nbsp; 1234567890</div>
    </div>
</div>
<div class="note">Only the characters used are embedded, so a 180 KB family usually costs a document 20 to 60 KB.</div>

<h2>Justification and hyphenation</h2>
<div class="two">
    <div>
        <div class="note" style="margin-bottom: 3pt">Justified, with hyphenation</div>
        <div class="measure just">
            Bookkeeping software with invoice-processing responsibilities needs words long enough to make
            hyphenation visible, such as uncharacteristically, straightforwardness and
            counterintuitiveness. The lines below are justified and broken with Liang's patterns, which is
            the same algorithm TeX and every browser use.
        </div>
    </div>
    <div>
        <div class="note" style="margin-bottom: 3pt">Same text, left aligned</div>
        <div class="measure ragged">
            Bookkeeping software with invoice-processing responsibilities needs words long enough to make
            hyphenation visible, such as uncharacteristically, straightforwardness and
            counterintuitiveness. The lines below are justified and broken with Liang's patterns, which is
            the same algorithm TeX and every browser use.
        </div>
    </div>
</div>

<h2>Right-to-left scripts</h2>
<div class="bidi">
    <p class="ar">مرحبا بالعالم، هذا نص عربي مع الأرقام 2026 في السطر.</p>
    <p class="he">שלום עולם, זהו טקסט עברי עם המספר 2026 בשורה.</p>
    <p style="font-size: 8pt">A Latin line with <span class="he">שלום עולם</span> in the middle, where the reading direction flips per run.</p>
    <p class="fallback">مرحبا بالعالم &nbsp; שלום עולם &nbsp; Привет мир &nbsp; Καλημέρα</p>
</div>
<div class="note">Arabic letters connect according to their position in the word, and the text stays searchable. The last line names no font at all: the bundled DejaVu Sans draws what Plex Sans cannot.</div>

<h2>Numbers in a table</h2>
<table class="nums">
    <thead>
        <tr><th>Ledger account</th><th class="r">Debit</th><th class="r">Credit</th><th class="r">Balance</th></tr>
    </thead>
    <tbody>
        <tr><td>8000 Revenue, services</td><td class="r">0.00</td><td class="r">214,800.00</td><td class="r">214,800.00</td></tr>
        <tr><td>4000 Staff costs</td><td class="r">86,421.10</td><td class="r">0.00</td><td class="r">86,421.10</td></tr>
        <tr><td>4500 Premises</td><td class="r">18,240.00</td><td class="r">1,120.00</td><td class="r">17,120.00</td></tr>
        <tr><td>1300 Trade debtors</td><td class="r">42,918.55</td><td class="r">38,402.10</td><td class="r">4,516.45</td></tr>
    </tbody>
    <tfoot>
        <tr><td>Total</td><td class="r">147,579.65</td><td class="r">254,322.10</td><td class="r">322,857.55</td></tr>
    </tfoot>
</table>

<div>
    <h2>Flexbox</h2>
    <div class="flexrow grow"><div>flex 1</div><div>flex 1</div><div>flex 1</div></div>
    <div class="flexrow mixed grow"><div>1</div><div>flex 2, wider</div><div>1</div></div>
    <div class="flexrow between"><div>space</div><div>between</div><div>three items</div></div>
    <div class="flexrow center"><div>centred</div><div>side by side</div></div>
    <div class="flexrow ordered grow"><div>order: 3, written first</div><div>second</div><div>third</div></div>
    <div class="flexrow pushed"><div>left</div><div>left</div><div>margin-left: auto</div></div>

    <h2>Grid</h2>
    <div class="gridbox">
        <div>1</div><div>2</div><div class="span2">3, spans two columns</div>
        <div>5</div><div class="span2">6, spans two</div><div>8</div>
    </div>
    <div class="areas">
        <div class="h">grid-template-areas: head</div>
        <div class="m">main, two columns wide</div>
        <div class="s">side, two rows tall</div>
    </div>

    <h2>Columns</h2>
    <div class="cols3">
        Multi-column text is balanced across the available columns, so the last column is not left empty.
        That is what a magazine does and what a report with a long introduction needs. The text here runs
        until all three columns are about equally full, including hyphenation at the line end and
        justification to the right edge of every column.
    </div>

    {{-- Wrapped in break-inside: avoid on purpose: without it the paragraph
         lands below the float instead of beside it when a later page break
         exists. That is an open engine defect (see ROADMAP.md), and this is
         the workaround until it is fixed. --}}
    <div class="keep">
    <h2>Floats</h2>
    <div class="floatbox">
        This block floats right. The text beside it wraps around it, with a real exclusion of the line
        boxes rather than a margin.
    </div>
    <div class="flowtext">
        A floated block takes space away from the line boxes beside it, and the lines stay shorter for as
        long as the block is next to them. Once the text runs out below the block, the lines return to the
        full width. That is the difference between a real float and a block with a margin beside it: with a
        margin, every line stays short, including the ones below the block. This paragraph is made long
        enough to show the difference, so the first lines sit beside the block and the last lines run on
        below it across the full column width of this page.
    </div>
    </div>

    <h2>Effects</h2>
    <div class="fx">
        <div class="grad">linear-gradient</div>
        <div class="radius">border-radius 10pt</div>
        <div class="shadowbox">box-shadow</div>
        <div class="opacity">opacity 0.45</div>
        <div class="turn">rotate(-3deg)</div>
    </div>
    <div class="textshadow">text-shadow on a heading</div>

    <h2>Variables, calc() and modern colors</h2>
    <div class="values">
        <div class="v1">var(--accent)</div>
        <div class="v2">color-mix()</div>
        <div class="v3">oklch()</div>
        <div class="v4">clamp() width</div>
        <div class="v5">calc() padding, hex alpha</div>
    </div>

    <div class="keep">
    <h2>Stacking and absolute positioning</h2>
    <div class="stack">
        <div class="abs one">first</div>
        <div class="abs two">second</div>
        <div class="abs three">third</div>
        <div class="abs corner">anchored bottom right</div>
    </div>
    </div>

    <h2>Lists</h2>
    <div class="two">
        <div>
            <ul class="marks">
                <li>A bulleted item</li>
                <li>A second item, with a sublist below it
                    <ul>
                        <li>First sub-item</li>
                        <li>Second sub-item, with one more level
                            <ul class="square">
                                <li>Third level, square marker</li>
                                <li>Third level, second item</li>
                            </ul>
                        </li>
                    </ul>
                </li>
                <li>A third item, somewhat longer, so the text runs on to a second line and indents neatly</li>
            </ul>
        </div>
        <div>
            <ol class="marks">
                <li>Numbered, first step
                    <ol>
                        <li>Sub-step under the first</li>
                        <li>Sub-step with a third level
                            <ol class="roman">
                                <li>Roman numbered</li>
                                <li>Roman, second item</li>
                            </ol>
                        </li>
                    </ol>
                </li>
                <li>Numbered, second step
                    <ul>
                        <li>A bulleted list inside a numbered one</li>
                        <li>Its second item</li>
                    </ul>
                </li>
                <li>Numbered, third step</li>
            </ol>
        </div>
        <div>
            <dl>
                <dt>Ledger</dt>
                <dd>The set of accounts that entries are posted to.</dd>
                <dt>Journal</dt>
                <dd>The door entries come in through.</dd>
            </dl>
        </div>
    </div>

    <h2>Links and bookmarks</h2>
    <p class="links">
        External links become real link annotations: <a href="https://github.com/Tklaversma/FlexPDF">the FlexPDF repository</a>.
        Internal ones jump inside the document: <a href="#svg-section">the SVG section below</a>.
        Every heading on these pages is also in the reader's bookmarks panel.
    </p>

    <h2 id="svg-section">SVG</h2>
    <svg width="470" height="120" viewBox="0 0 470 120">
        <defs>
            <linearGradient id="sg" x1="0" y1="0" x2="1" y2="1">
                <stop offset="0%" stop-color="#2f6f4e"/>
                <stop offset="100%" stop-color="#8fd6b4"/>
            </linearGradient>
        </defs>
        <rect x="0" y="0" width="150" height="120" fill="url(#sg)"/>
        <circle cx="75" cy="60" r="34" fill="#ffffff" opacity="0.35"/>
        <path d="M175 100 C 205 20, 245 20, 275 100 S 345 180, 375 100" fill="none" stroke="#2f6f4e" stroke-width="2.5"/>
        <polygon points="400,20 460,20 430,70" fill="#e8a33d"/>
        <rect x="400" y="80" width="60" height="30" fill="none" stroke="#16241d" stroke-width="1.5"/>
        <text x="404" y="99" font-size="9" fill="#16241d">text in svg</text>
    </svg>
</div>

</body>
</html>
