@php
    $money = fn (float $value): string => number_format($value, 2);
@endphp
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Product guide 2026</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: 'Plex Sans'; font-size: 9pt; line-height: 1.5; color: #16241d; }

        .hero { display: flex; gap: 22pt; align-items: flex-start; margin-bottom: 20pt; }
        .hero .text { flex: 2.1; }
        .hero .art { flex: 1; }
        .hero .art img { width: 148pt; height: 148pt; object-fit: cover; }
        .eyebrow { font-size: 7pt; font-weight: bold; letter-spacing: 2.6pt; text-transform: uppercase; color: #2f6f4e; }
        h1 { font-family: 'Baskerville'; font-size: 23pt; line-height: 1.14; margin: 8pt 0 8pt; letter-spacing: -0.5pt; }
        .hero p { margin: 0 0 7pt; color: #56655e; }

        .intro { column-count: 2; column-gap: 22pt; text-align: justify; hyphens: auto;
                 font-size: 8.5pt; color: #56655e; border-top: 2pt solid #2f6f4e; padding-top: 12pt; margin-bottom: 20pt; }
        .intro p { margin: 0 0 7pt; }

        h2 { font-size: 12pt; margin: 0 0 3pt; }
        .kicker { font-size: 7pt; letter-spacing: 1.6pt; text-transform: uppercase; color: #9aa8a1; margin-bottom: 12pt; }

        /* A card never splits across a page: break-inside: avoid moves it whole. */
        .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 14pt; }
        .product { border: 0.75pt solid #dbe6e0; break-inside: avoid; }
        .product img { width: 240pt; height: 96pt; object-fit: cover; }
        .product .body { padding: 11pt 12pt 12pt; }
        .product .head { display: flex; justify-content: space-between; align-items: flex-start; }
        .product h3 { font-size: 11pt; margin: 0; }
        .product .badge { font-size: 6.4pt; letter-spacing: 0.8pt; text-transform: uppercase;
                          background: #2f6f4e; color: #ffffff; padding: 2pt 5pt; border-radius: 7pt; }
        .product .badge.new { background: #b0741a; }
        .product .claim { font-size: 8.2pt; color: #56655e; margin: 5pt 0 8pt; }
        .product ul { margin: 0 0 10pt; padding-left: 11pt; font-size: 8pt; color: #56655e; }
        .product li { margin-bottom: 2pt; }
        .product .price { display: flex; justify-content: space-between; align-items: center;
                          border-top: 0.5pt solid #e4ece8; padding-top: 8pt; }
        .product .amount { font-family: 'Plex Mono'; font-size: 13pt; font-weight: bold; }
        .product .per { font-size: 7.4pt; color: #9aa8a1; }

        .page { break-before: page; }

        table.compare { width: 100%; border-collapse: collapse; margin-top: 4pt; }
        table.compare th {
            font-size: 6.6pt; letter-spacing: 1pt; text-transform: uppercase; color: #ffffff;
            background: #2f6f4e; padding: 6pt 7pt; text-align: left;
        }
        table.compare th.c, table.compare td.c { text-align: center; }
        table.compare td { padding: 6pt 7pt; border-bottom: 0.5pt solid #e4ece8; font-size: 8.2pt; vertical-align: top; }
        table.compare td.group {
            background: #f5f9f7; font-weight: bold; font-size: 7pt; letter-spacing: 1pt;
            text-transform: uppercase; color: #2f6f4e; vertical-align: middle;
        }
        table.compare .yes { color: #2f6f4e; font-weight: bold; }
        table.compare .no { color: #c3cfc9; }
        table.compare tr.alt td { background: #fafcfb; }

        .prices { display: flex; gap: 12pt; margin-top: 16pt; }
        .prices .col { flex: 1; background: #f5f9f7; padding: 12pt; break-inside: avoid; }
        .prices .col.hi { background: #16241d; color: #dbeae2; }
        .prices .k { font-size: 6.6pt; letter-spacing: 1.1pt; text-transform: uppercase; color: #7c8a83; }
        .prices .hi .k { color: #8fd6b4; }
        .prices .v { font-family: 'Plex Mono'; font-size: 17pt; font-weight: bold; margin: 3pt 0 4pt; }
        .prices p { margin: 0; font-size: 8pt; color: #56655e; }
        .prices .hi p { color: #a9c4b6; }

        .faq { column-count: 2; column-gap: 22pt; margin-top: 18pt; font-size: 8.2pt; }
        .faq h4 { font-size: 8.6pt; margin: 0 0 2pt; }
        .faq p { margin: 0 0 9pt; color: #56655e; }
        .faq .q { break-inside: avoid; }

        .contact { display: flex; justify-content: space-between; margin-top: 20pt;
                   border-top: 2pt solid #2f6f4e; padding-top: 12pt; font-size: 8pt; color: #56655e; }
        .contact .k { font-size: 6.6pt; letter-spacing: 1.1pt; text-transform: uppercase; color: #9aa8a1; }
    </style>
</head>
<body>

<div class="hero">
    <div class="text">
        <div class="eyebrow">Product guide 2026</div>
        <h1>Choose the plan that fits your books</h1>
        <p>
            Every plan can be cancelled monthly, includes unlimited invoicing and works with every UK bank.
            You pay per set of books, not per user.
        </p>
        <p>Prices are per month and exclude VAT.</p>
    </div>
    <div class="art">
        <img src="pattern-diagonal.png" alt="">
    </div>
</div>

<div class="intro">
    <p>
        Choosing bookkeeping software is hard because the differences only show once you are already using
        it. So this guide says, for each plan, what you actually do with it, rather than which ticks fit in
        a comparison table.
    </p>
    <p>
        Torn between two plans? Pick the smaller one. Moving up to a larger plan can happen mid-month and
        takes effect immediately; you pay the difference pro rata.
    </p>
</div>

<h2>The plans</h2>
<div class="kicker">Six products, cancel monthly</div>

<div class="grid">
    @foreach ($products as $index => [$name, $image, $claim, $price, $features, $badge])
        <div class="product">
            <img src="{{ $image }}" alt="">
            <div class="body">
                <div class="head">
                    <h3>{{ $name }}</h3>
                    @if ($badge)
                        <span class="badge {{ $badge === 'New' ? 'new' : '' }}">{{ $badge }}</span>
                    @endif
                </div>
                <p class="claim">{{ $claim }}</p>
                <ul>
                    @foreach ($features as $feature)
                        <li>{{ $feature }}</li>
                    @endforeach
                </ul>
                <div class="price">
                    <span class="amount">&pound; {{ $money($price) }}</span>
                    <span class="per">per month, excluding VAT</span>
                </div>
            </div>
        </div>
    @endforeach
</div>

<div class="page">
    <h2>What is in which plan</h2>
    <div class="kicker">Comparison</div>

    <table class="compare">
        <thead>
            <tr>
                <th style="width: 74pt">Area</th>
                <th>Feature</th>
                <th class="c" style="width: 54pt">Start</th>
                <th class="c" style="width: 54pt">Pro</th>
                <th class="c" style="width: 54pt">Practice</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td class="group" rowspan="3">Invoices</td>
                <td>Send unlimited invoices</td>
                <td class="c yes">&check;</td><td class="c yes">&check;</td><td class="c yes">&check;</td>
            </tr>
            <tr class="alt">
                <td>Automatic reminders</td>
                <td class="c no">&ndash;</td><td class="c yes">&check;</td><td class="c yes">&check;</td>
            </tr>
            <tr>
                <td>Your own branding on the invoice</td>
                <td class="c no">&ndash;</td><td class="c no">&ndash;</td><td class="c yes">&check;</td>
            </tr>
            <tr class="alt">
                <td class="group" rowspan="2">Bank</td>
                <td>Bank feed through Open Banking</td>
                <td class="c yes">&check;</td><td class="c yes">&check;</td><td class="c yes">&check;</td>
            </tr>
            <tr>
                <td>Smart categories, trained per set of books</td>
                <td class="c no">&ndash;</td><td class="c yes">&check;</td><td class="c yes">&check;</td>
            </tr>
            <tr class="alt">
                <td class="group" rowspan="3">Working together</td>
                <td>Number of users</td>
                <td class="c">1</td><td class="c">5</td><td class="c">unlimited</td>
            </tr>
            <tr>
                <td>Access for your accountant</td>
                <td class="c no">&ndash;</td><td class="c yes">&check;</td><td class="c yes">&check;</td>
            </tr>
            <tr class="alt">
                <td>Several sets of books side by side</td>
                <td class="c no">&ndash;</td><td class="c no">&ndash;</td><td class="c yes">&check;</td>
            </tr>
            <tr>
                <td class="group" rowspan="2">Returns</td>
                <td>VAT return prepared</td>
                <td class="c yes">&check;</td><td class="c yes">&check;</td><td class="c yes">&check;</td>
            </tr>
            <tr class="alt">
                <td>Annual tax return</td>
                <td class="c no">&ndash;</td><td class="c no">beta</td><td class="c no">beta</td>
            </tr>
        </tbody>
    </table>

    <div class="prices">
        <div class="col">
            <div class="k">Start</div>
            <div class="v">&pound; 12.00</div>
            <p>Per month. One set of books and one user.</p>
        </div>
        <div class="col hi">
            <div class="k">Pro, most chosen</div>
            <div class="v">&pound; 29.00</div>
            <p>Per month. Five users and access for your accountant.</p>
        </div>
        <div class="col">
            <div class="k">Practice</div>
            <div class="v">&pound; 89.00</div>
            <p>Per month. Unlimited client files and users.</p>
        </div>
    </div>

    <div class="faq">
        <div class="q">
            <h4>Can I switch plans mid-way?</h4>
            <p>Yes, at any time. Moving to a larger plan you pay the difference pro rata; moving to a smaller
                one takes effect at the start of the next month.</p>
        </div>
        <div class="q">
            <h4>What happens to my data if I stop?</h4>
            <p>You can export your books as a standard audit file and as PDF. We keep the data for another
                thirty days and then delete it permanently.</p>
        </div>
        <div class="q">
            <h4>Does the bank feed work with my bank?</h4>
            <p>The feed works with every UK bank that supports Open Banking, including Barclays, HSBC, Lloyds,
                NatWest, Santander, Monzo and Starling.</p>
        </div>
        <div class="q">
            <h4>Can my accountant see the books?</h4>
            <p>From Pro upwards you give your accountant their own access, with a role that only reads or
                also posts entries. You always see who changed what.</p>
        </div>
    </div>

    <div class="contact">
        <div><div class="k">Sales</div><div>{{ $company['email'] }}</div></div>
        <div><div class="k">Phone</div><div>{{ $company['phone'] }}</div></div>
        <div><div class="k">Address</div><div>{{ $company['address'][0] }}, {{ $company['address'][1] }}</div></div>
        <div><div class="k">Online</div><div>{{ $company['site'] }}</div></div>
    </div>
</div>

</body>
</html>
