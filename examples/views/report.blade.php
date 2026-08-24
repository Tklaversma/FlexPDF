@php
    $money = fn (float $value): string => number_format($value);
    $revenue = array_sum(array_column($quarters, 1));
    $result = array_sum(array_column($quarters, 3));
    $peak = max(array_column($quarters, 1));
    $accounts = array_sum(array_column($segments, 1));
@endphp
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Annual report 2026</title>
    <style>
        body { font-family: 'Plex Sans'; font-size: 9.5pt; line-height: 1.55; color: #1b2b23; }

        .cover { height: 690pt; position: relative; }
        .cover img { width: 499pt; height: 330pt; object-fit: cover; }
        .cover .plate { position: absolute; left: 0; top: 250pt; width: 400pt; background: #ffffff; padding: 26pt 30pt 24pt; }
        .cover .eyebrow { font-size: 7.5pt; font-weight: bold; letter-spacing: 2.6pt; text-transform: uppercase; color: #2f6f4e; }
        .cover h1 { font-family: 'Baskerville'; font-size: 34pt; line-height: 1.1; margin: 10pt 0 8pt; letter-spacing: -0.6pt; }
        .cover .strap { font-size: 11pt; color: #56655e; }
        .cover .foot { position: absolute; left: 0; bottom: 0; display: flex; gap: 30pt; font-size: 8pt; color: #7c8a83; }
        .cover .foot .k { font-size: 6.6pt; letter-spacing: 1.2pt; text-transform: uppercase; color: #9aa8a1; }
        .cover .foot .v { font-family: 'Plex Mono'; font-size: 10pt; color: #1b2b23; }

        .page { break-before: page; }

        h2 { font-family: 'Baskerville'; font-size: 17pt; margin: 0 0 4pt; letter-spacing: -0.2pt; }
        h2 + .kicker { font-size: 8pt; letter-spacing: 1.6pt; text-transform: uppercase; color: #2f6f4e; margin-bottom: 14pt; }
        h3 { font-size: 10pt; margin: 16pt 0 4pt; }

        .toc { border-top: 2pt solid #2f6f4e; margin-top: 6pt; }
        .toc .row { display: flex; justify-content: space-between; border-bottom: 0.5pt solid #e4ece8; padding: 7pt 0; }
        .toc .row .t { font-weight: bold; }
        .toc .row .s { color: #7c8a83; font-size: 8.5pt; }
        .toc .row .n { font-family: 'Plex Mono'; color: #2f6f4e; }

        .kpis { display: grid; grid-template-columns: repeat(4, 1fr); gap: 10pt; margin: 16pt 0 20pt; }
        .kpi { background: #f5f9f7; padding: 11pt 12pt; border-top: 2.5pt solid #2f6f4e; }
        .kpi .k { font-size: 6.6pt; letter-spacing: 1.1pt; text-transform: uppercase; color: #7c8a83; }
        .kpi .v { font-family: 'Plex Mono'; font-size: 15pt; font-weight: bold; margin-top: 3pt; letter-spacing: -0.5pt; }
        .kpi .d { font-size: 7.4pt; color: #56655e; }
        .kpi .d.up { color: #2f6f4e; }

        .chartrow { display: flex; gap: 18pt; break-inside: avoid; }
        .chartrow .wide { flex: 1.4; }
        .chartrow .narrow { flex: 1; }

        table.data { width: 100%; border-collapse: collapse; margin-top: 6pt; }
        table.data th {
            font-size: 6.6pt; letter-spacing: 1pt; text-transform: uppercase; color: #56655e;
            text-align: left; padding: 5pt 6pt; border-bottom: 0.5pt solid #cddbd4;
        }
        table.data th.r, table.data td.r { text-align: right; }
        table.data td { padding: 5pt 6pt; border-bottom: 0.5pt solid #f0f5f2; font-size: 8.5pt; }
        table.data td.r { font-family: 'Plex Mono'; font-size: 8pt; }
        table.data tfoot td { font-weight: bold; border-top: 0.5pt solid #2f6f4e; border-bottom: none; }
        table.data .swatch { display: inline-block; width: 7pt; height: 7pt; margin-right: 5pt; }

        .body-copy { column-count: 2; column-gap: 20pt; text-align: justify; hyphens: auto; margin-top: 12pt; }
        .body-copy p { margin: 0 0 8pt; }
        .dropcap { font-family: 'Baskerville'; font-size: 15pt; color: #2f6f4e; }

        .pull {
            font-family: 'Baskerville'; font-style: italic; font-size: 14pt; line-height: 1.4;
            color: #2f6f4e; border-left: 3pt solid #8fd6b4; padding: 4pt 0 4pt 14pt;
            margin: 14pt 0; break-inside: avoid;
        }
        .pull .who { display: block; font-family: 'Plex Sans'; font-style: normal; font-size: 8pt; color: #7c8a83; margin-top: 6pt; }

        .notes { border-top: 0.5pt solid #dbe6e0; margin-top: 16pt; padding-top: 8pt; font-size: 7.4pt; color: #7c8a83; }
        .notes p { margin: 0 0 3pt; }
        sup { font-size: 6pt; vertical-align: super; color: #2f6f4e; }

        .segments { display: flex; gap: 10pt; margin: 14pt 0; }
        .segment { flex: 1; padding: 10pt; background: #f7faf8; break-inside: avoid; }
        .segment .bar { height: 4pt; margin-bottom: 7pt; }
        .segment .name { font-size: 8pt; font-weight: bold; }
        .segment .n { font-family: 'Plex Mono'; font-size: 12pt; margin-top: 2pt; }
        .segment .g { font-size: 7.4pt; color: #56655e; }

        .colophon { background: #1b2b23; color: #cfe0d7; padding: 20pt 22pt; margin-top: 20pt; }
        .colophon h3 { color: #ffffff; margin: 0 0 8pt; }
        .colophon .grid { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 14pt; font-size: 8pt; }
        .colophon .k { font-size: 6.6pt; letter-spacing: 1.1pt; text-transform: uppercase; color: #7fae95; }
    </style>
</head>
<body>

<div class="cover">
    <img src="cover-mesh.png" alt="">
    <div class="plate">
        <div class="eyebrow">Annual report</div>
        <h1>The books stopped<br>being the job</h1>
        <div class="strap">{{ $company['name'] }} on the 2026 financial year</div>
    </div>
    <div class="foot">
        <div>
            <div class="k">Revenue</div>
            <div class="v">&pound; {{ $money($revenue) }}</div>
        </div>
        <div>
            <div class="k">Result</div>
            <div class="v">&pound; {{ $money($result) }}</div>
        </div>
        <div>
            <div class="k">Active accounts</div>
            <div class="v">{{ $money($accounts) }}</div>
        </div>
    </div>
</div>

<div class="page">
    <h2>Contents</h2>
    <div class="kicker">Annual report 2026</div>
    <div class="toc">
        <div class="row"><div><div class="t">The year in figures</div><div class="s">Revenue, result and growth per quarter</div></div><div class="n">3</div></div>
        <div class="row"><div><div class="t">Where the customers come from</div><div class="s">Segments, growth and churn</div></div><div class="n">3</div></div>
        <div class="row"><div><div class="t">The story behind the year</div><div class="s">What changed in the bank feed</div></div><div class="n">4</div></div>
        <div class="row"><div><div class="t">Quarterly figures</div><div class="s">Revenue, costs and result in detail</div></div><div class="n">5</div></div>
        <div class="row"><div><div class="t">Colophon</div><div class="s">Sources and contact details</div></div><div class="n">5</div></div>
    </div>

    <h3>Summary</h3>
    <p>
        The 2026 financial year closed with revenue of &pound; {{ $money($revenue) }} and a result of
        &pound; {{ $money($result) }}. Active accounts grew to {{ $money($accounts) }}, with the strongest growth
        coming from accounting practices that run several client files side by side.
    </p>

    <div class="pull">
        The VAT return is no longer an evening. It is a four-minute check.
        <span class="who">Sarah Harlow, customer since 2023</span>
    </div>
</div>

<div class="page">
    <h2>The year in figures</h2>
    <div class="kicker">Quarter by quarter</div>

    <div class="kpis">
        <div class="kpi">
            <div class="k">Revenue</div>
            <div class="v">{{ $money($revenue) }}</div>
            <div class="d up">+ 36.2% year on year</div>
        </div>
        <div class="kpi">
            <div class="k">Result</div>
            <div class="v">{{ $money($result) }}</div>
            <div class="d up">margin 20.8%</div>
        </div>
        <div class="kpi">
            <div class="k">Accounts</div>
            <div class="v">{{ $money($accounts) }}</div>
            <div class="d up">+ 9,410 net</div>
        </div>
        <div class="kpi">
            <div class="k">Churn</div>
            <div class="v">4.1%</div>
            <div class="d">annualised</div>
        </div>
    </div>

    <div class="chartrow">
        <div class="wide">
            <h3>Revenue and costs per quarter</h3>
            <svg width="300" height="150" viewBox="0 0 300 150">
                <line x1="30" y1="120" x2="296" y2="120" stroke="#cddbd4" stroke-width="0.7"/>
                <line x1="30" y1="80" x2="296" y2="80" stroke="#eef4f1" stroke-width="0.7"/>
                <line x1="30" y1="40" x2="296" y2="40" stroke="#eef4f1" stroke-width="0.7"/>
                <text x="0" y="123" font-size="6.5" fill="#9aa8a1">0</text>
                <text x="0" y="43" font-size="6.5" fill="#9aa8a1">200k</text>
                @foreach ($quarters as $i => $quarter)
                    @php
                        $x = 44 + $i * 64;
                        $revenueHeight = round($quarter[1] / $peak * 92, 1);
                        $costHeight = round($quarter[2] / $peak * 92, 1);
                    @endphp
                    <rect x="{{ $x }}" y="{{ 120 - $revenueHeight }}" width="20" height="{{ $revenueHeight }}" fill="#2f6f4e"/>
                    <rect x="{{ $x + 22 }}" y="{{ 120 - $costHeight }}" width="20" height="{{ $costHeight }}" fill="#a7d5bf"/>
                    <text x="{{ $x + 14 }}" y="132" font-size="7" fill="#56655e" text-anchor="middle">{{ $quarter[0] }}</text>
                @endforeach
                <rect x="30" y="140" width="8" height="6" fill="#2f6f4e"/>
                <text x="42" y="146" font-size="6.5" fill="#56655e">revenue</text>
                <rect x="80" y="140" width="8" height="6" fill="#a7d5bf"/>
                <text x="92" y="146" font-size="6.5" fill="#56655e">costs</text>
            </svg>
        </div>
        <div class="narrow">
            <h3>Result</h3>
            <table class="data">
                <thead>
                    <tr><th>Quarter</th><th class="r">Revenue</th><th class="r">Result</th></tr>
                </thead>
                <tbody>
                    @foreach ($quarters as $quarter)
                        <tr>
                            <td>{{ $quarter[0] }}</td>
                            <td class="r">{{ $money($quarter[1]) }}</td>
                            <td class="r">{{ $money($quarter[3]) }}</td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr><td>Year</td><td class="r">{{ $money($revenue) }}</td><td class="r">{{ $money($result) }}</td></tr>
                </tfoot>
            </table>
        </div>
    </div>

    <h2 style="margin-top: 22pt">Where the customers come from</h2>
    <div class="kicker">Segments</div>

    <div class="segments">
        @foreach ($segments as $segment)
            <div class="segment">
                <div class="bar" style="background: {{ $segment[3] }}"></div>
                <div class="name">{{ $segment[0] }}</div>
                <div class="n">{{ $money($segment[1]) }}</div>
                <div class="g">{{ $segment[2] > 0 ? '+' : '' }}{{ number_format($segment[2], 1) }}% this year</div>
            </div>
        @endforeach
    </div>

    <table class="data">
        <thead>
            <tr><th>Segment</th><th class="r">Accounts</th><th class="r">Share</th><th class="r">Growth</th></tr>
        </thead>
        <tbody>
            @foreach ($segments as $segment)
                <tr>
                    <td><span class="swatch" style="background: {{ $segment[3] }}"></span>{{ $segment[0] }}</td>
                    <td class="r">{{ $money($segment[1]) }}</td>
                    <td class="r">{{ number_format($segment[1] / $accounts * 100, 1) }}%</td>
                    <td class="r">{{ $segment[2] > 0 ? '+' : '' }}{{ number_format($segment[2], 1) }}%</td>
                </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr><td>Total</td><td class="r">{{ $money($accounts) }}</td><td class="r">100.0%</td><td class="r">+ 9.8%</td></tr>
        </tfoot>
    </table>
</div>

<div class="page">
    <h2>The story behind the year</h2>
    <div class="kicker">Bank feed and recognition</div>

    <div class="body-copy">
        <p><span class="dropcap">T</span>he year began with a problem every bookkeeping application knows: a bank
            transaction says who paid and how much, but not what for. The user usually knows, and that is
            exactly why typing it in again every time feels like wasted work.<sup>1</sup></p>
        <p>In February, recognition was switched to a model that learns from the user's own entries rather than
            from a shared category list. That sounds like a detail, but the difference is large: a builder
            books a tank of fuel differently from a consultant, and a shared model always picks the wrong one
            of the two.</p>
        <p>The outcome is measurable. Of all transactions, 94 percent now receive a suggestion the user accepts
            unchanged. At the start of 2026 that figure was 71 percent, and the time spent categorising fell by
            almost two thirds over the same period.<sup>2</sup></p>
        <p>What followed was predictable and still surprising: users who spend less time categorising look at
            their books more often. Sessions per user per month rose from 4.2 to 7.8. A set of books that is
            right turns out to be something people like looking at.</p>
        <p>For 2027 the return itself is next. The VAT return is already a four-minute check, and the ambition
            is for the annual tax return to become the same: a screen where everything is already right, with
            a button to approve it.</p>
    </div>

    <div class="notes">
        <p><sup>1</sup> Measured over 1.4 million transactions between January and December 2026.</p>
        <p><sup>2</sup> Time measured as session length on the bank transactions screen, excluding sessions over one hour.</p>
    </div>
</div>

<div class="page">
    <h2>Quarterly figures</h2>
    <div class="kicker">In detail</div>

    <table class="data">
        <thead>
            <tr>
                <th>Quarter</th>
                <th class="r">Revenue</th>
                <th class="r">Costs</th>
                <th class="r">Result</th>
                <th class="r">Margin</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($quarters as $quarter)
                <tr>
                    <td>{{ $quarter[0] }} 2026</td>
                    <td class="r">{{ $money($quarter[1]) }}</td>
                    <td class="r">{{ $money($quarter[2]) }}</td>
                    <td class="r">{{ $money($quarter[3]) }}</td>
                    <td class="r">{{ number_format($quarter[3] / $quarter[1] * 100, 1) }}%</td>
                </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr>
                <td>Financial year 2026</td>
                <td class="r">{{ $money($revenue) }}</td>
                <td class="r">{{ $money(array_sum(array_column($quarters, 2))) }}</td>
                <td class="r">{{ $money($result) }}</td>
                <td class="r">{{ number_format($result / $revenue * 100, 1) }}%</td>
            </tr>
        </tfoot>
    </table>

    <div class="colophon">
        <h3>Colophon</h3>
        <div class="grid">
            <div>
                <div class="k">Published by</div>
                <div>{{ $company['name'] }}<br>{{ $company['address'][0] }}<br>{{ $company['address'][1] }}</div>
            </div>
            <div>
                <div class="k">Contact</div>
                <div>{{ $company['email'] }}<br>{{ $company['phone'] }}<br>{{ $company['site'] }}</div>
            </div>
            <div>
                <div class="k">Sources</div>
                <div>Figures are taken from the 2026 annual accounts and have not been audited.</div>
            </div>
        </div>
    </div>
</div>

</body>
</html>
