<!DOCTYPE html>
<html lang="id">
    <head>
        <meta charset="utf-8">
        <title>Laporan Arus Kas</title>
        @include('pdf.partials.style')
    </head>
    <body>
        <div class="header">
            <h1>Laporan Arus Kas</h1>
            <p>{{ $workspace }} &middot; {{ \App\Support\MonthPeriod::label($period['from']) }} &ndash; {{ \App\Support\MonthPeriod::label($period['to']) }}</p>
            <p>Dibuat {{ $generated_at }}</p>
        </div>

        <table>
            <thead>
                <tr>
                    <th>Bulan</th>
                    <th class="right">Pemasukan</th>
                    <th class="right">Pengeluaran</th>
                    <th class="right">Arus bersih</th>
                    <th class="right">Transfer</th>
                    <th class="center">Jumlah transaksi</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($payload['points'] as $point)
                    <tr>
                        <td>{{ $point['label'] }}</td>
                        <td class="right">{{ \App\Support\Money::format($point['income']) }}</td>
                        <td class="right">{{ \App\Support\Money::format($point['expense']) }}</td>
                        <td class="right">{{ \App\Support\Money::format($point['net_cash_flow']) }}</td>
                        <td class="right">{{ \App\Support\Money::format($point['total_transfer']) }}</td>
                        <td class="center">{{ $point['transaction_count'] ?? 0 }}</td>
                    </tr>
                @endforeach
                <tr class="total">
                    <td>Total</td>
                    <td class="right">{{ \App\Support\Money::format($payload['totals']['income']) }}</td>
                    <td class="right">{{ \App\Support\Money::format($payload['totals']['expense']) }}</td>
                    <td class="right">{{ \App\Support\Money::format($payload['totals']['net_cash_flow']) }}</td>
                    <td class="right">{{ \App\Support\Money::format($payload['totals']['total_transfer']) }}</td>
                    <td></td>
                </tr>
            </tbody>
        </table>

        <div class="footer">
            Alfinance &middot; Laporan arus kas {{ $workspace }} &middot; dicetak {{ $generated_at }}
        </div>
    </body>
</html>