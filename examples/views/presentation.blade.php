@php
    $money = fn (float $value): string => number_format($value);
    $revenue = array_sum(array_column($quarters, 1));
    $peak = max(array_column($quarters, 1));
    $accounts = array_sum(array_column($segments, 1));
@endphp
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Investor update 2026</title>
    <style>
        * { box-sizing: border-box; }
        html, body { margin: 0; padding: 0; }
        body { font-family: 'Plex Sans'; font-size: 11pt; line-height: 1.4; color: #16241d; }

        /* One slide is one page: the sheet is 720x405pt and every slide is exactly that tall. */
        .slide { height: 405pt; position: relative; padding: 34pt 40pt; break-after: page; }
        .slide.last { break-after: auto; }
        .slide.dark { background: #16241d; color: #e6f0ea; }
        .slide.pale { background: #f2f7f4; }
        .slide.bleed { padding: 0; }

        .slide .full { position: absolute; left: 0; top: 0; }
        .slide .full img { width: 720pt; height: 405pt; object-fit: cover; }

        .eyebrow { font-size: 8pt; font-weight: bold; letter-spacing: 3pt; text-transform: uppercase; color: #2f6f4e; }
        .dark .eyebrow, .onimage .eyebrow { color: #8fd6b4; }

        h1 { font-family: 'Baskerville'; font-size: 40pt; line-height: 1.05; margin: 12pt 0 10pt; letter-spacing: -1pt; }
        h2 { font-family: 'Baskerville'; font-size: 27pt; line-height: 1.1; margin: 8pt 0 16pt; letter-spacing: -0.5pt; }
        h3 { font-size: 11pt; margin: 0 0 4pt; }
        p { margin: 0 0 8pt; }

        .onimage { position: absolute; left: 40pt; top: 96pt; width: 420pt; color: #ffffff; }
        .onimage h1 { color: #ffffff; }
        .onimage .strap { font-size: 12pt; color: #cfe6da; }
        .stamp { position: absolute; right: 40pt; bottom: 34pt; text-align: right; color: #cfe6da; font-size: 9pt; }
        .stamp .big { font-family: 'Plex Mono'; font-size: 13pt; color: #ffffff; }

        .agenda { display: flex; gap: 28pt; margin-top: 6pt; }
        .agenda .col { flex: 1; }
        .agenda .item { display: flex; gap: 12pt; padding: 9pt 0; border-top: 0.75pt solid #2f6f4e; }
        .dark .agenda .item { border-top-color: #3f5b4d; }
        .agenda .n { font-family: 'Plex Mono'; font-size: 10pt; color: #8fd6b4; }
        .agenda .t { font-size: 12pt; font-weight: bold; }
        .agenda .s { font-size: 9pt; color: #9fb5a9; }

        .metrics { display: flex; gap: 20pt; margin-top: 20pt; }
        .metric { flex: 1; border-top: 3pt solid #2f6f4e; padding-top: 12pt; }
        .metric .v { font-family: 'Plex Mono'; font-size: 30pt; font-weight: bold; letter-spacing: -1.2pt; }
        .metric .k { font-size: 9pt; color: #56655e; margin-top: 2pt; }
        .metric .d { font-size: 8.5pt; color: #2f6f4e; margin-top: 6pt; }

        .quote { font-family: 'Baskerville'; font-style: italic; font-size: 30pt; line-height: 1.25; letter-spacing: -0.4pt; }
        .quote .who { display: block; font-family: 'Plex Sans'; font-style: normal; font-size: 10pt;
                      letter-spacing: 1.4pt; text-transform: uppercase; color: #7c8a83; margin-top: 20pt; }

        .cards { display: grid; grid-template-columns: repeat(3, 1fr); gap: 14pt; margin-top: 14pt; }
        .card { background: #ffffff; padding: 14pt; border-top: 2.5pt solid #2f6f4e; }
        .card .k { font-family: 'Plex Mono'; font-size: 8pt; color: #2f6f4e; }
        .card h3 { margin: 6pt 0 3pt; }
        .card p { font-size: 9pt; color: #56655e; margin: 0; }

        .split { display: flex; gap: 26pt; margin-top: 10pt; }
        .split .l { flex: 1.1; }
        .split .r { flex: 1; }
        .split img { width: 280pt; height: 210pt; object-fit: cover; }

        .bars { margin-top: 10pt; }
        .bars .row { display: flex; align-items: center; gap: 10pt; margin-bottom: 8pt; }
        .bars .name { flex: 0 0 190pt; font-size: 10pt; }
        .bars .track { flex: 1; background: #e4ece8; height: 12pt; }
        .dark .bars .track { background: #24382e; }
        .bars .fill { height: 12pt; }
        .bars .val { flex: 0 0 68pt; text-align: right; font-family: 'Plex Mono'; font-size: 9.5pt; }

        .timeline { display: flex; gap: 0; margin-top: 22pt; }
        .timeline .step { flex: 1; padding-right: 14pt; }
        .timeline .dot { width: 10pt; height: 10pt; border-radius: 5pt; background: #2f6f4e; margin-bottom: 8pt; }
        .timeline .when { font-family: 'Plex Mono'; font-size: 8.5pt; color: #2f6f4e; }
        .timeline .what { font-size: 10pt; font-weight: bold; margin-top: 2pt; }
        .timeline .why { font-size: 8.5pt; color: #56655e; margin-top: 3pt; }
        .rail { height: 1.5pt; background: #cfe6da; margin-top: 8pt; }

        .close { position: absolute; left: 40pt; top: 130pt; }
        .close h1 { font-size: 46pt; }
        .close .contact { display: flex; gap: 34pt; margin-top: 26pt; font-size: 10pt; color: #56655e; }
        .close .contact .k { font-size: 7.5pt; letter-spacing: 1.4pt; text-transform: uppercase; color: #9aa8a1; }
    </style>
</head>
<body>

<div class="slide bleed">
    <div class="full"><img src="cover-mesh.png" alt=""></div>
    <div class="onimage">
        <div class="eyebrow">Investor update</div>
        <h1>The books stopped being the job</h1>
        <div class="strap">{{ $company['name'] }} &nbsp;&middot;&nbsp; financial year 2026</div>
    </div>
    <div class="stamp">
        <div>Revenue, financial year</div>
        <div class="big">&pound; {{ $money($revenue) }}</div>
    </div>
</div>

<div class="slide dark">
    <div class="eyebrow">Agenda</div>
    <h2>What we will cover today</h2>
    <div class="agenda">
        <div class="col">
            <div class="item"><div class="n">01</div><div><div class="t">The year in figures</div><div class="s">Revenue, result and margin</div></div></div>
            <div class="item"><div class="n">02</div><div><div class="t">What changed</div><div class="s">Recognising bank transactions</div></div></div>
            <div class="item"><div class="n">03</div><div><div class="t">Where the growth is</div><div class="s">Segments and churn</div></div></div>
        </div>
        <div class="col">
            <div class="item"><div class="n">04</div><div><div class="t">The product in 2027</div><div class="s">The annual tax return</div></div></div>
            <div class="item"><div class="n">05</div><div><div class="t">The plan</div><div class="s">Four milestones to December</div></div></div>
            <div class="item"><div class="n">06</div><div><div class="t">Questions</div><div class="s">And where to find us</div></div></div>
        </div>
    </div>
</div>

<div class="slide">
    <div class="eyebrow">The year in figures</div>
    <h2>Growth without extra sales staff</h2>
    <div class="metrics">
        <div class="metric">
            <div class="v">{{ $money($revenue) }}</div>
            <div class="k">Revenue in pounds</div>
            <div class="d">+ 36.2% year on year</div>
        </div>
        <div class="metric">
            <div class="v">{{ $money($accounts) }}</div>
            <div class="k">Active accounts</div>
            <div class="d">+ 9,410 net</div>
        </div>
        <div class="metric">
            <div class="v">94%</div>
            <div class="k">Transactions with a correct suggestion</div>
            <div class="d">was 71% in January</div>
        </div>
    </div>

    <div class="bars">
        @foreach ($quarters as $quarter)
            <div class="row">
                <div class="name">{{ $quarter[0] }} 2026</div>
                <div class="track"><div class="fill" style="width: {{ round($quarter[1] / $peak * 100) }}%; background: #2f6f4e"></div></div>
                <div class="val">{{ $money($quarter[1]) }}</div>
            </div>
        @endforeach
    </div>
</div>

<div class="slide pale">
    <div class="eyebrow">What changed</div>
    <div class="split">
        <div class="l">
            <h2>A model that learns from your own entries</h2>
            @foreach ($slides as [$title, $body])
                <h3>{{ $title }}</h3>
                <p style="font-size: 10pt; color: #56655e">{{ $body }}</p>
            @endforeach
        </div>
        <div class="r">
            <img src="slide-warm.png" alt="">
            <p style="font-size: 8.5pt; color: #7c8a83; margin-top: 8pt">
                Since February, recognition runs per set of books instead of on a shared category list.
            </p>
        </div>
    </div>
</div>

<div class="slide dark">
    <div class="eyebrow">Where the growth is</div>
    <h2>Accounting practices grow the fastest</h2>
    <div class="bars">
        @foreach ($segments as $segment)
            <div class="row">
                <div class="name">{{ $segment[0] }}</div>
                <div class="track"><div class="fill" style="width: {{ round($segment[1] / $accounts * 100) }}%; background: {{ $segment[3] }}"></div></div>
                <div class="val">{{ $money($segment[1]) }}</div>
            </div>
        @endforeach
    </div>
    <p style="font-size: 9pt; color: #9fb5a9; margin-top: 14pt">
        Share of all active accounts. Accounting practices are 7.2 percent of the accounts and
        21.4 percent of the revenue.
    </p>
</div>

<div class="slide pale">
    <div class="eyebrow">The product in 2027</div>
    <h2>Six things on the list</h2>
    <div class="cards">
        <div class="card"><div class="k">01</div><h3>Annual tax return</h3><p>A screen where everything is already right, with a button to approve it.</p></div>
        <div class="card"><div class="k">02</div><h3>Scan and capture</h3><p>Photograph a receipt, get the amount and VAT out, entry ready to approve.</p></div>
        <div class="card"><div class="k">03</div><h3>Multiple currencies</h3><p>Rates per entry date, with exchange differences on their own ledger account.</p></div>
        <div class="card"><div class="k">04</div><h3>Practice portal</h3><p>Client files side by side, with a work queue per practice.</p></div>
        <div class="card"><div class="k">05</div><h3>Time module</h3><p>Hours on a project, billed on an invoice, margin per project in view.</p></div>
        <div class="card"><div class="k">06</div><h3>Mobile</h3><p>Send invoices and capture receipts without a laptop.</p></div>
    </div>
</div>

<div class="slide">
    <div class="eyebrow">The plan</div>
    <h2>Four milestones to December</h2>
    <div class="rail"></div>
    <div class="timeline">
        <div class="step"><div class="dot"></div><div class="when">Q1 2027</div><div class="what">Tax return in beta</div><div class="why">Fifty users, manual review afterwards.</div></div>
        <div class="step"><div class="dot"></div><div class="when">Q2 2027</div><div class="what">Scan and capture for everyone</div><div class="why">Rolled out to every plan, Start included.</div></div>
        <div class="step"><div class="dot"></div><div class="when">Q3 2027</div><div class="what">Practice portal</div><div class="why">Work queue per practice and a shared inbox.</div></div>
        <div class="step"><div class="dot"></div><div class="when">Q4 2027</div><div class="what">Multiple currencies</div><div class="why">Exchange differences booked automatically.</div></div>
    </div>

    <div class="quote" style="font-size: 20pt; margin-top: 26pt">
        The VAT return is no longer an evening. It is a four-minute check.
        <span class="who">Sarah Harlow, customer since 2023</span>
    </div>
</div>

<div class="slide last dark">
    <div class="close">
        <div class="eyebrow">Thank you</div>
        <h1>Questions?</h1>
        <div class="contact">
            <div><div class="k">Email</div><div style="color: #e6f0ea">investors@{{ $company['site'] }}</div></div>
            <div><div class="k">Phone</div><div style="color: #e6f0ea">{{ $company['phone'] }}</div></div>
            <div><div class="k">Address</div><div style="color: #e6f0ea">{{ $company['address'][0] }}, {{ $company['address'][1] }}</div></div>
        </div>
    </div>
</div>

</body>
</html>
