@php
    $money = fn (float $value): string => number_format(abs($value), 2);
    $credit = array_sum(array_map(fn (array $row): float => $row['amount'] > 0 ? $row['amount'] : 0.0, $rows));
    $debit = array_sum(array_map(fn (array $row): float => $row['amount'] < 0 ? -$row['amount'] : 0.0, $rows));
    $widest = max(array_column($categories, 'amount')) ?: 1.0;
@endphp
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Statement, August 2026</title>
    <style>
        body { font-family: 'Plex Sans'; font-size: 8.5pt; line-height: 1.45; color: #16241d; }

        .top { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 16pt; }
        .top .kind { font-size: 7pt; font-weight: bold; letter-spacing: 2.2pt; text-transform: uppercase; color: #2f6f4e; }
        .top h1 { font-size: 18pt; margin: 2pt 0 0; letter-spacing: -0.4pt; }
        .top .sub { color: #7c8a83; font-size: 8pt; }
        .top .acct { text-align: right; font-size: 7.5pt; color: #7c8a83; }
        .top .acct .iban { font-family: 'Plex Mono'; font-size: 9pt; color: #16241d; }

        .cards { display: flex; gap: 8pt; margin-bottom: 16pt; }
        .card { flex: 1; background: #f5f9f7; padding: 9pt 10pt; border-top: 2pt solid #2f6f4e; }
        .card.up { border-top-color: #2f6f4e; }
        .card.down { border-top-color: #b0741a; }
        .card.end { background: #2f6f4e; }
        .card .k { font-size: 6.6pt; letter-spacing: 1pt; text-transform: uppercase; color: #7c8a83; }
        .card .v { font-family: 'Plex Mono'; font-size: 12pt; font-weight: bold; margin-top: 2pt; }
        .card.end .k { color: #b9d8c8; }
        .card.end .v { color: #ffffff; }
        .card.down .v { color: #b0741a; }

        .split { display: flex; gap: 20pt; margin-bottom: 18pt; break-inside: avoid; }
        .split .half { flex: 1; }
        h2 { font-size: 9pt; margin: 0 0 8pt; letter-spacing: 0.2pt; }
        h2 .hint { font-weight: normal; color: #9aa8a1; font-size: 7.5pt; }

        .bars { width: 100%; }
        .bars .row { margin-bottom: 6pt; }
        .bars .meta { display: flex; justify-content: space-between; font-size: 7.4pt; margin-bottom: 2pt; }
        .bars .meta .amt { font-family: 'Plex Mono'; color: #56655e; }
        .bars .track { background: #edf3f0; height: 6pt; }
        .bars .fill { background: #2f6f4e; height: 6pt; }
        .bars .row.b2 .fill { background: #4f9c74; }
        .bars .row.b3 .fill { background: #79bd9a; }
        .bars .row.b4 .fill { background: #a7d5bf; }
        .bars .row.b5 .fill { background: #c6e3d5; }
        .bars .row.b6 .fill { background: #dcece4; }
        .bars .row.b7 .fill { background: #e8f1ec; }

        table.tx { width: 100%; border-collapse: collapse; }
        table.tx thead th {
            font-size: 6.6pt; font-weight: bold; letter-spacing: 1pt; text-transform: uppercase;
            color: #56655e; background: #eef4f1; padding: 5pt 7pt; text-align: left;
            border-bottom: 0.5pt solid #cddbd4;
        }
        table.tx thead th.r { text-align: right; }
        table.tx td { padding: 5pt 7pt; border-bottom: 0.5pt solid #f0f5f2; vertical-align: top; }
        table.tx tr.alt td { background: #fafcfb; }
        table.tx td.date, table.tx td.amt, table.tx td.bal { font-family: 'Plex Mono'; font-size: 7.6pt; }
        table.tx td.amt, table.tx td.bal { text-align: right; }
        table.tx td.amt.neg { color: #a8551a; }
        table.tx .party { font-weight: bold; }
        table.tx .desc { font-size: 7.4pt; color: #7c8a83; }
        table.tx .cat { font-size: 7.2pt; color: #56655e; }

        .close { display: flex; justify-content: space-between; align-items: center;
                 margin-top: 14pt; padding: 10pt 12pt; background: #f5f9f7; border-left: 3pt solid #2f6f4e;
                 break-inside: avoid; }
        .close .v { font-family: 'Plex Mono'; font-size: 13pt; font-weight: bold; }
        .disclaimer { font-size: 6.8pt; color: #9aa8a1; margin-top: 10pt; }
    </style>
</head>
<body>

<div class="top">
    <div>
        <div class="kind">Statement</div>
        <h1>August 2026</h1>
        <div class="sub">{{ count($rows) }} transactions &nbsp;&middot;&nbsp; statement 08 of 12</div>
    </div>
    <div class="acct">
        <div>{{ $company['name'] }}</div>
        <div class="iban">{{ $company['iban'] }}</div>
        <div>Business current account</div>
    </div>
</div>

<div class="cards">
    <div class="card">
        <div class="k">Opening balance</div>
        <div class="v">{{ $money($opening) }}</div>
    </div>
    <div class="card up">
        <div class="k">Money in</div>
        <div class="v">+ {{ $money($credit) }}</div>
    </div>
    <div class="card down">
        <div class="k">Money out</div>
        <div class="v">&minus; {{ $money($debit) }}</div>
    </div>
    <div class="card end">
        <div class="k">Closing balance</div>
        <div class="v">{{ $money($closing) }}</div>
    </div>
</div>

<div class="split">
    <div class="half">
        <h2>Spending by category <span class="hint">seven largest</span></h2>
        <div class="bars">
            @foreach ($categories as $index => $category)
                <div class="row b{{ $index + 1 }}">
                    <div class="meta">
                        <span>{{ $category['name'] }}</span>
                        <span class="amt">{{ $money($category['amount']) }} &nbsp; {{ number_format($category['share'], 1) }}%</span>
                    </div>
                    <div class="track">
                        <div class="fill" style="width: {{ max(1.0, round($category['amount'] / $widest * 100, 1)) }}%"></div>
                    </div>
                </div>
            @endforeach
        </div>
    </div>
    <div class="half">
        <h2>Balance over the month <span class="hint">per transaction</span></h2>
        @php
            $balances = array_column($rows, 'balance');
            $low = min($balances);
            $high = max($balances);
            $span = max(1.0, $high - $low);
            $points = [];
            foreach ($balances as $i => $balance) {
                $x = round(6 + $i / max(1, count($balances) - 1) * 228, 2);
                $y = round(96 - ($balance - $low) / $span * 74, 2);
                $points[] = "{$x},{$y}";
            }
            $line = implode(' ', $points);
            $area = "6,102 " . $line . " 234,102";
        @endphp
        <svg width="240" height="108" viewBox="0 0 240 108">
            <rect x="0" y="0" width="240" height="108" fill="#f7faf8"/>
            <line x1="6" y1="102" x2="234" y2="102" stroke="#cddbd4" stroke-width="0.6"/>
            <line x1="6" y1="60" x2="234" y2="60" stroke="#e4ece8" stroke-width="0.6"/>
            <polygon points="{{ $area }}" fill="#dcece4"/>
            <polyline points="{{ $line }}" fill="none" stroke="#2f6f4e" stroke-width="1.6"/>
            <circle cx="{{ explode(',', end($points))[0] }}" cy="{{ explode(',', end($points))[1] }}" r="2.6" fill="#e8a33d"/>
            <text x="6" y="14" font-size="7" fill="#7c8a83">high {{ $money($high) }}</text>
            <text x="6" y="24" font-size="7" fill="#7c8a83">low {{ $money($low) }}</text>
        </svg>
    </div>
</div>

<table class="tx">
    <thead>
        <tr>
            <th>Date</th>
            <th>Counterparty and description</th>
            <th>Category</th>
            <th class="r">Amount</th>
            <th class="r">Balance</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($rows as $index => $row)
            <tr class="{{ $index % 2 === 1 ? 'alt' : '' }}">
                <td class="date">{{ $row['date'] }}</td>
                <td>
                    <div class="party">{{ $row['party'] }}</div>
                    <div class="desc">{{ $row['description'] }}</div>
                </td>
                <td class="cat">{{ $row['category'] }}</td>
                <td class="amt {{ $row['amount'] < 0 ? 'neg' : '' }}">
                    {{ $row['amount'] < 0 ? '−' : '+' }} {{ $money($row['amount']) }}
                </td>
                <td class="bal">{{ $money($row['balance']) }}</td>
            </tr>
        @endforeach
    </tbody>
</table>

<div class="close">
    <div>
        <div class="k" style="font-size: 6.6pt; letter-spacing: 1pt; text-transform: uppercase; color: #7c8a83">Closing balance at 31 August 2026</div>
        <div style="font-size: 7.6pt; color: #56655e">{{ count($rows) }} transactions processed, all amounts in pounds sterling</div>
    </div>
    <div class="v">&pound; {{ $money($closing) }}</div>
</div>

<p class="disclaimer">
    This statement was compiled automatically from the bank feed and needs no signature.
    Check transactions within thirteen months. Categories are Northbound's suggestion and can be changed.
</p>

</body>
</html>
