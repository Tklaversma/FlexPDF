@php
    $money = fn (float $value): string => number_format($value, 2);
    $qty = fn (float $value): string => rtrim(rtrim(number_format($value, 2), '0'), '.');
@endphp
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Invoice {{ $invoice['number'] }}</title>
    <style>
        body {
            font-family: 'Plex Sans';
            font-size: 9pt;
            line-height: 1.5;
            color: #16241d;
        }

        .masthead { display: flex; justify-content: space-between; align-items: flex-start; }
        .mark { display: flex; gap: 10pt; align-items: center; }
        .mark img { width: 34pt; height: 34pt; }
        .mark .name { font-size: 15pt; font-weight: bold; letter-spacing: -0.3pt; color: #2f6f4e; }
        .mark .tagline { font-size: 7.5pt; color: #7c8a83; }

        .doctype { text-align: right; }
        .doctype .kind {
            font-size: 8pt; font-weight: bold; letter-spacing: 2.4pt;
            text-transform: uppercase; color: #2f6f4e;
        }
        .doctype .number { font-family: 'Plex Mono'; font-size: 17pt; color: #16241d; }
        .doctype .dates { font-size: 7.5pt; color: #7c8a83; }

        .rule { height: 2pt; background: #2f6f4e; margin: 12pt 0 18pt; }

        .parties { display: flex; gap: 26pt; margin-bottom: 18pt; }
        .party { flex: 1; }
        .party.meta { flex: 0 0 178pt; }
        .label {
            font-size: 6.8pt; font-weight: bold; letter-spacing: 1.1pt;
            text-transform: uppercase; color: #9aa8a1; margin-bottom: 4pt;
        }
        .party .who { font-weight: bold; font-size: 10pt; }
        .party p { margin: 0; }
        .party .vat { font-family: 'Plex Mono'; font-size: 7.5pt; color: #7c8a83; margin-top: 4pt; }

        .metatable { width: 100%; border-collapse: collapse; }
        .metatable td { padding: 1.5pt 0; font-size: 8pt; vertical-align: top; }
        .metatable td.k { color: #7c8a83; white-space: nowrap; }
        .metatable td.v { text-align: right; font-family: 'Plex Mono'; font-size: 7.8pt; }

        table.items { width: 100%; border-collapse: collapse; }
        table.items thead th {
            font-size: 6.8pt; font-weight: bold; letter-spacing: 1pt; text-transform: uppercase;
            color: #ffffff; background: #2f6f4e; padding: 6pt 8pt; text-align: left;
        }
        table.items thead th.r { text-align: right; }
        table.items tbody td { padding: 7pt 8pt; border-bottom: 0.5pt solid #e4ece8; vertical-align: top; }
        table.items tbody tr.alt td { background: #f7faf8; }
        table.items td.r { text-align: right; font-family: 'Plex Mono'; font-size: 8pt; }
        table.items .line-title { font-weight: bold; }
        table.items .line-sub { font-size: 7.5pt; color: #7c8a83; }
        .zero-vat { color: #9aa8a1; }

        .summary { display: flex; gap: 20pt; margin-top: 16pt; }
        .summary .note { flex: 1; font-size: 7.8pt; color: #56655e; }
        .summary .amounts { flex: 0 0 224pt; }

        .amounts table { width: 100%; border-collapse: collapse; }
        .amounts td { padding: 3.5pt 8pt; font-size: 8.5pt; }
        .amounts td.v { text-align: right; font-family: 'Plex Mono'; }
        .amounts tr.sub td { border-top: 0.5pt solid #dbe6e0; }
        .amounts tr.vat td { color: #56655e; font-size: 7.8pt; }
        .amounts tr.grand td {
            background: #2f6f4e; color: #ffffff; font-weight: bold; font-size: 11pt;
            padding: 8pt; border-radius: 2pt;
        }

        .pay {
            display: flex; gap: 14pt; margin-top: 20pt;
            background: #f2f7f4; border-left: 3pt solid #2f6f4e; padding: 12pt 14pt;
            break-inside: avoid;
        }
        .pay .block { flex: 1; }
        .pay .block.account { flex: 1.6; }
        .pay .big { font-family: 'Plex Mono'; font-size: 10pt; font-weight: bold; }
        .pay .due { color: #b0741a; }

        .footnote { font-size: 7pt; color: #8b978f; margin-top: 14pt; }
        sup { font-size: 6pt; vertical-align: super; color: #2f6f4e; }

        /* The running footer is a Blade partial; its rules live here because
           the document's stylesheet is what styles a header or footer. */
        .foot {
            display: flex; justify-content: space-between;
            font-size: 7pt; color: #8b978f;
            border-top: 0.5pt solid #dbe6e0; padding-top: 5pt;
        }
        .foot-iban { font-family: 'Plex Mono'; }

        .appendix { break-before: page; }
        h2.section {
            font-size: 12pt; color: #2f6f4e; margin: 0 0 3pt;
            border-bottom: 0.5pt solid #dbe6e0; padding-bottom: 6pt;
        }
        .lead { font-size: 8.5pt; color: #56655e; margin: 8pt 0 14pt; }

        table.weeks { width: 100%; border-collapse: collapse; margin-bottom: 18pt; }
        table.weeks th {
            font-size: 6.8pt; letter-spacing: 1pt; text-transform: uppercase; color: #7c8a83;
            border-bottom: 0.5pt solid #dbe6e0; padding: 5pt 6pt; text-align: left;
        }
        table.weeks th.r, table.weeks td.r { text-align: right; }
        table.weeks td { padding: 5pt 6pt; border-bottom: 0.5pt solid #f0f5f2; font-size: 8pt; }
        table.weeks td.r { font-family: 'Plex Mono'; font-size: 7.8pt; }
        table.weeks tfoot td { font-weight: bold; border-top: 0.5pt solid #2f6f4e; border-bottom: none; }

        .terms { column-count: 2; column-gap: 22pt; font-size: 7.6pt; color: #56655e; text-align: justify; hyphens: auto; }
        .terms h3 { font-size: 8pt; color: #16241d; margin: 0 0 4pt; }
        .terms ol { padding-left: 12pt; margin: 0; }
        .terms li { margin-bottom: 5pt; }
    </style>
</head>
<body>

<div class="masthead">
    <div class="mark">
        <img src="mark.png" alt="{{ $company['name'] }} logo">
        <div>
            <div class="name">{{ $company['name'] }}</div>
            <div class="tagline">{{ $company['tagline'] }}</div>
        </div>
    </div>
    <div class="doctype">
        <div class="kind">Invoice</div>
        <div class="number">{{ $invoice['number'] }}</div>
        <div class="dates">Issued {{ $invoice['date'] }}</div>
        <div class="dates">Due {{ $invoice['due'] }}</div>
    </div>
</div>

<div class="rule"></div>

<div class="parties">
    <div class="party">
        <div class="label">Invoice to</div>
        <p class="who">{{ $invoice['customer']['name'] }}</p>
        <p>{{ $invoice['customer']['attn'] }}</p>
        @foreach ($invoice['customer']['address'] as $line)
            <p>{{ $line }}</p>
        @endforeach
        <p class="vat">VAT {{ $invoice['customer']['vat'] }}</p>
    </div>
    <div class="party">
        <div class="label">From</div>
        <p class="who">{{ $company['name'] }}</p>
        @foreach ($company['address'] as $line)
            <p>{{ $line }}</p>
        @endforeach
        <p class="vat">Company no. {{ $company['company'] }}</p>
        <p class="vat">VAT {{ $company['vat'] }}</p>
    </div>
    <div class="party meta">
        <div class="label">Details</div>
        <table class="metatable">
            <tr><td class="k">Your reference</td><td class="v">{{ $invoice['reference'] }}</td></tr>
            <tr><td class="k">Payment terms</td><td class="v">{{ $invoice['terms'] }} days</td></tr>
            <tr><td class="k">Contact</td><td class="v">{{ $company['email'] }}</td></tr>
            <tr><td class="k">Phone</td><td class="v">{{ $company['phone'] }}</td></tr>
        </table>
    </div>
</div>

<table class="items">
    <thead>
        <tr>
            <th>Description</th>
            <th class="r">Quantity</th>
            <th class="r">Rate</th>
            <th class="r">VAT</th>
            <th class="r">Amount</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($invoice['rows'] as $index => $row)
            <tr class="{{ $index % 2 === 1 ? 'alt' : '' }}">
                <td>
                    <div class="line-title">{{ $row['title'] }}</div>
                    <div class="line-sub">{{ $row['description'] }}</div>
                </td>
                <td class="r">{{ $qty($row['quantity']) }} {{ $row['unit'] }}</td>
                <td class="r">{{ $money($row['price']) }}</td>
                <td class="r {{ $row['rate'] === 0 ? 'zero-vat' : '' }}">{{ $row['rate'] }}%</td>
                <td class="r">{{ $money($row['net']) }}</td>
            </tr>
        @endforeach
    </tbody>
</table>

<div class="summary">
    <div class="note">
        {{ $invoice['notes'] }}<sup>1</sup>
        <div class="footnote">
            <sup>1</sup> Appendix A on page 2 itemises the hours per week. The zero-rated amount is recharged
            postage on which no VAT is due.
        </div>
    </div>
    <div class="amounts">
        <table>
            <tr class="sub">
                <td>Subtotal excluding VAT</td>
                <td class="v">&pound; {{ $money($totals['net']) }}</td>
            </tr>
            @foreach ($totals['vat'] as $bucket)
                <tr class="vat">
                    <td>VAT {{ $bucket['rate'] }}% on &pound; {{ $money($bucket['net']) }}</td>
                    <td class="v">&pound; {{ $money($bucket['vat']) }}</td>
                </tr>
            @endforeach
            <tr class="grand">
                <td>Total due</td>
                <td class="v">&pound; {{ $money($totals['gross']) }}</td>
            </tr>
        </table>
    </div>
</div>

<div class="pay">
    <div class="block account">
        <div class="label">Account</div>
        <div class="big">{{ $company['iban'] }}</div>
    </div>
    <div class="block">
        <div class="label">Payment reference</div>
        <div class="big">{{ $invoice['number'] }}</div>
    </div>
    <div class="block">
        <div class="label">Due by</div>
        <div class="big due">{{ $invoice['due'] }}</div>
    </div>
</div>

<div class="appendix">
    <h2 class="section">Appendix A &nbsp;&middot;&nbsp; Hours per week</h2>
    <p class="lead">
        Hours were logged in Northbound and approved by the client week by week. The rate is the agreed
        hourly rate of &pound; 95.00 excluding VAT.
    </p>

    <table class="weeks">
        <thead>
            <tr>
                <th>Week</th>
                <th>Work</th>
                <th class="r">Hours</th>
                <th class="r">Amount</th>
            </tr>
        </thead>
        <tbody>
            @php
                $weeks = [
                    [29, 'General ledger and journals', 6.0],
                    [29, 'VAT codes and mapping', 2.5],
                    [30, 'Migration, financial year 2023', 8.0],
                    [30, 'Review of open items', 3.5],
                    [31, 'Migration, financial year 2024', 7.5],
                    [32, 'Reconciling bank transactions', 5.0],
                    [33, 'Handover and sign-off', 3.0],
                ];
                $hours = array_sum(array_column($weeks, 2));
            @endphp
            @foreach ($weeks as [$week, $what, $spent])
                <tr>
                    <td>{{ $week }}</td>
                    <td>{{ $what }}</td>
                    <td class="r">{{ number_format($spent, 2) }}</td>
                    <td class="r">{{ $money($spent * 95) }}</td>
                </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr>
                <td colspan="2">Total hours</td>
                <td class="r">{{ number_format($hours, 2) }}</td>
                <td class="r">&pound; {{ $money($hours * 95) }}</td>
            </tr>
        </tfoot>
    </table>

    <h2 class="section">Terms of payment</h2>
    <div class="lead"></div>
    <div class="terms">
        <h3>Payment</h3>
        <ol>
            <li>Payment is due within {{ $invoice['terms'] }} days of the invoice date, without deduction or set-off.</li>
            <li>Late payment attracts statutory interest under the Late Payment of Commercial Debts (Interest) Act,
                together with the fixed compensation the Act provides for.</li>
            <li>Disputing an amount on an invoice does not suspend the obligation to pay the undisputed part.</li>
        </ol>
        <h3>Delivery</h3>
        <ol start="4">
            <li>Additional work is carried out only after written approval in Northbound.</li>
            <li>Delivered configurations are deemed accepted fourteen days after handover.</li>
            <li>These terms are governed by the law of England and Wales. Disputes are referred to the courts
                of Bristol.</li>
        </ol>
    </div>
</div>

</body>
</html>
