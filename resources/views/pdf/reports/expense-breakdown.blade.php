<!DOCTYPE html>
<html lang="id">
    <head>
        <meta charset="utf-8">
        <title>Komposisi Pengeluaran</title>
        @include('pdf.partials.style')
    </head>
    <body>
        <div class="header">
            <h1>Komposisi Pengeluaran</h1>
            <p>{{ $workspace }} &middot; {{ \App\Support\MonthPeriod::label($period['from']) }} &ndash; {{ \App\Support\MonthPeriod::label($period['to']) }}</p>
            <p>Dibuat {{ $generated_at }}</p>
        </div>

        <table>
            <thead>
                <tr>
                    <th>Kategori</th>
                    <th class="right">Total</th>
                    <th class="right">Persen</th>
                    @foreach ($payload['months'] as $month)
                        <th class="center">{{ \App\Support\MonthPeriod::shortLabel($month) }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach ($payload['items'] as $item)
                    <tr>
                        <td>{{ $item['name'] }}</td>
                        <td class="right">{{ \App\Support\Money::format($item['total']) }}</td>
                        <td class="right">{{ $item['percent'] === null ? '–' : number_format($item['percent'], 1, ',', '.').'%' }}</td>
                        @foreach ($payload['months'] as $month)
                            <td class="right">{{ \App\Support\Money::format($item['by_month'][$month] ?? '0.00') }}</td>
                        @endforeach
                    </tr>
                @endforeach
                <tr class="total">
                    <td>Total</td>
                    <td class="right">{{ \App\Support\Money::format($payload['total']) }}</td>
                    <td class="right">100%</td>
                    @foreach ($payload['months'] as $month)
                        <td class="right">{{ \App\Support\Money::format($payload['months_totals'][$month] ?? '0.00') }}</td>
                    @endforeach
                </tr>
            </tbody>
        </table>

        <div class="footer">
            Alfinance &middot; Komposisi pengeluaran {{ $workspace }} &middot; dicetak {{ $generated_at }}
        </div>
    </body>
</html>