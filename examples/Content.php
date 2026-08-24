<?php

declare(strict_types=1);

/**
 * Sample data for the example documents.
 *
 * Everything here is invented: Northbound, its customers and every
 * transaction below are fictional, and the addresses and numbers are the
 * reserved or example ranges meant for documentation.
 */
final class Content
{
    /** @return array<string, mixed> */
    public static function company(): array
    {
        return [
            'name'    => 'Northbound',
            'tagline' => 'Bookkeeping without the fuss',
            'address' => ['14 Harbour Row', 'Bristol BS1 4AA'],
            'company' => '08741226',
            'vat'     => 'GB 863 4215 50',
            'iban'    => 'GB29 NWBK 6016 1331 9268 19',
            'email'   => 'accounts@northbound.example',
            'phone'   => '+44 117 496 0210',
            'site'    => 'northbound.example',
        ];
    }

    /** @return array<string, mixed> */
    public static function invoice(): array
    {
        $lines = [
            ['Ledger integration set-up', 'General ledger, journals and VAT codes', 14.0, 'hours', 95.00, 20],
            ['Migration of historical entries', 'Financial years 2023 and 2024, reconciled', 21.5, 'hours', 95.00, 20],
            ['Bank feed connection', 'Open Banking link, one-off', 1.0, 'item', 245.00, 20],
            ['Northbound Pro subscription', 'Annual licence, five users', 1.0, 'year', 1188.00, 20],
            ['Bookkeeping training', 'Two half-day sessions on site', 2.0, 'sessions', 480.00, 20],
            ['Postage and delivery', 'Recharged costs, zero-rated', 1.0, 'item', 18.50, 0],
            ['Accounting advice', 'Quarterly review, reduced rate', 3.0, 'hours', 110.00, 5],
        ];

        $rows = [];

        foreach ($lines as [$title, $description, $quantity, $unit, $price, $rate]) {
            $rows[] = [
                'title'       => $title,
                'description' => $description,
                'quantity'    => $quantity,
                'unit'        => $unit,
                'price'       => $price,
                'rate'        => $rate,
                'net'         => round($quantity * $price, 2),
            ];
        }

        return [
            'number'    => '2026-0418',
            'date'      => '18 August 2026',
            'due'       => '17 September 2026',
            'terms'     => 30,
            'reference' => 'PO-2026-3391',
            'customer'  => [
                'name'    => 'Harlow & Reed Architects',
                'attn'    => 'For the attention of Ms S. Harlow',
                'address' => ['22 Quayside', 'Newcastle upon Tyne NE1 3DE'],
                'vat'     => 'GB 004 5128 89',
            ],
            'rows'  => $rows,
            'notes' => 'Work carried out in weeks 29 to 33. Hours are itemised per week in appendix A.',
        ];
    }

    /**
     * The totals block of the invoice: net, one VAT line per rate, gross.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array{net: float, vat: array<int, array{rate: int, net: float, vat: float}>, gross: float}
     */
    public static function invoiceTotals(array $rows): array
    {
        $perRate = [];
        $net     = 0.0;

        foreach ($rows as $row) {
            $rate = (int) $row['rate'];
            $perRate[$rate] ??= ['rate' => $rate, 'net' => 0.0, 'vat' => 0.0];
            $perRate[$rate]['net'] += $row['net'];
            $net += $row['net'];
        }

        foreach ($perRate as $rate => $bucket) {
            $perRate[$rate]['vat'] = round($bucket['net'] * $rate / 100, 2);
        }

        krsort($perRate);

        $vat = array_sum(array_column($perRate, 'vat'));

        return [
            'net'   => round($net, 2),
            'vat'   => array_values($perRate),
            'gross' => round($net + $vat, 2),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    public static function transactions(): array
    {
        $lines = [
            ['Barclays', 'Payment received, invoice 2026-0392', 'Revenue', 4235.75],
            ['Stripe Payments', 'Card payout, batch 88213', 'Revenue', 1892.40],
            ['BT Business', 'Fibre broadband, August', 'Phone and internet', -78.65],
            ['Shell', 'Fuel, M4 services', 'Motor expenses', -96.32],
            ['Amazon Business', 'Office supplies, order 44 812', 'Office supplies', -142.18],
            ['HMRC', 'VAT return, Q2', 'VAT paid', -3410.00],
            ['Harlow & Reed Architects', 'Part payment, invoice 2026-0401', 'Revenue', 2500.00],
            ['Trainline', 'Rail travel, July', 'Travel', -214.60],
            ['Adobe', 'Creative Cloud, annual', 'Software', -718.20],
            ['Bristol City Council', 'Business rates, 2026', 'Taxes', -186.00],
            ['Client escrow account', 'Release, Waterfront project', 'Revenue', 8750.00],
            ['Octopus Energy', 'Electricity, August', 'Premises', -245.00],
            ['Dell', 'Latitude laptop', 'Equipment', -1349.00],
            ['Hiscox', 'Professional indemnity, quarterly', 'Insurance', -312.75],
            ['Virgin Media', 'Office internet', 'Phone and internet', -64.95],
            ['Meridian Works', 'Payment, invoice 2026-0388', 'Revenue', 3120.50],
            ['NCP', 'Parking, city centre', 'Motor expenses', -34.00],
            ['Ashby Accountants', 'Year-end accounts 2025', 'Professional fees', -1450.00],
            ['Payroll', 'Salaries, August', 'Staff costs', -6842.11],
            ['Pension scheme', 'Contributions, August', 'Staff costs', -1204.60],
            ['Waitrose', 'Team lunch', 'Entertaining', -68.42],
            ['Harlow & Reed Architects', 'Final payment, invoice 2026-0401', 'Revenue', 1875.25],
            ['Google', 'Advertising, August', 'Marketing', -890.00],
            ['Barclays', 'Interest and charges', 'Bank charges', -32.40],
            ['Whitfield Builders', 'Payment, invoice 2026-0410', 'Revenue', 6412.00],
            ['Lex Autolease', 'Van lease, monthly', 'Motor expenses', -689.00],
            ['HMRC', 'PAYE refund', 'Taxes', 1120.00],
            ['Microsoft', 'Microsoft 365 Business', 'Software', -226.80],
            ['Studio Quarter', 'Website photography', 'Marketing', -1250.00],
            ['Marsh Legal', 'Contract review', 'Professional fees', -975.00],
            ['Barclays', 'Payment received, invoice 2026-0415', 'Revenue', 2980.00],
            ['Hotel du Vin', 'Overnight stay, conference', 'Travel', -178.50],
            ['Zoom', 'Annual subscription', 'Software', -179.88],
            ['Newcastle City Council', 'Permit fee', 'Taxes', -412.00],
            ['Chamber of Commerce', 'Membership, 2026', 'Subscriptions', -295.00],
            ['Meridian Works', 'Deposit, Quayside project', 'Revenue', 5000.00],
        ];

        $balance = 18_425.60;
        $rows    = [];
        $day     = 1;

        foreach ($lines as $index => [$party, $description, $category, $amount]) {
            $balance += $amount;
            $day += 1 + ($index % 3);

            $rows[] = [
                'date'        => sprintf('%02d Aug', min($day, 31)),
                'party'       => $party,
                'description' => $description,
                'category'    => $category,
                'amount'      => $amount,
                'balance'     => round($balance, 2),
            ];
        }

        return $rows;
    }

    /**
     * Spending per category, the seven largest, for the statement's bar chart.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array{name: string, amount: float, share: float}>
     */
    public static function spendingByCategory(array $rows): array
    {
        $spend = [];

        foreach ($rows as $row) {
            if ($row['amount'] >= 0) {
                continue;
            }

            $spend[$row['category']] = ($spend[$row['category']] ?? 0.0) + abs($row['amount']);
        }

        arsort($spend);

        $spend = array_slice($spend, 0, 7, true);
        $total = array_sum($spend);

        return array_map(
            fn (string $name, float $amount): array => [
                'name'   => $name,
                'amount' => round($amount, 2),
                'share'  => $total > 0 ? round($amount / $total * 100, 1) : 0.0,
            ],
            array_keys($spend),
            array_values($spend),
        );
    }

    /** @return array<int, array{0: string, 1: string, 2: string, 3: float, 4: array<int, string>, 5: ?string}> */
    public static function products(): array
    {
        return [
            ['Northbound Start', 'tile-1.png', 'For the sole trader who wants to send invoices without taking a bookkeeping course first.', 12.00, ['Unlimited invoicing', 'VAT return at a glance', 'Bank feed', 'Mobile app'], 'Popular'],
            ['Northbound Pro', 'tile-2.png', 'For the business with staff, several accounts and an accountant who looks over its shoulder.', 29.00, ['Everything in Start', 'Five users', 'Time tracking', 'Accountant access'], 'Most chosen'],
            ['Northbound Practice', 'tile-3.png', 'For the accounting practice running dozens of client files side by side.', 89.00, ['Unlimited client files', 'Bulk processing', 'Your own branding', 'Priority support'], null],
            ['Bank Feed Plus', 'tile-4.png', 'Automatic recognition of counterparties and categories, trained on your own entries.', 9.00, ['Open Banking, every bank', 'Smart categories', 'Daily sync'], null],
            ['Time module', 'tile-5.png', 'Log hours to a project, bill them on an invoice, and see where the margin goes.', 7.50, ['Timer and weekly sheet', 'Project margin', 'Bill in one click'], null],
            ['Scan and Capture', 'tile-6.png', 'Photograph a receipt, get the amount and VAT out, entry ready to approve.', 6.00, ['OCR on receipts and invoices', 'VAT check', 'Seven-year archive'], 'New'],
        ];
    }

    /** @return array<int, array{0: string, 1: int, 2: int, 3: int}> */
    public static function quarters(): array
    {
        return [
            ['Q1', 214_800, 168_200, 46_600],
            ['Q2', 238_400, 179_900, 58_500],
            ['Q3', 261_100, 188_400, 72_700],
            ['Q4', 292_600, 201_800, 90_800],
        ];
    }

    /** @return array<int, array{0: string, 1: int, 2: float, 3: string}> */
    public static function segments(): array
    {
        return [
            ['Sole traders', 61_400, 8.4, '#2f6f4e'],
            ['Small businesses, up to ten staff', 28_900, 12.1, '#4f9c74'],
            ['Accounting practices', 7_250, 19.6, '#8fd6b4'],
            ['Other and trial accounts', 3_180, -4.2, '#cfe6da'],
        ];
    }

    /** @return array<int, array{0: string, 1: string}> */
    public static function slides(): array
    {
        return [
            ['What changed', 'The VAT return went from an evening of work to a four-minute check.'],
            ['Why it works', 'Every entry gets a suggestion, and the suggestion is right 94 percent of the time.'],
            ['What it costs', 'Twelve pounds a month, cancel monthly, no implementation project.'],
        ];
    }
}
