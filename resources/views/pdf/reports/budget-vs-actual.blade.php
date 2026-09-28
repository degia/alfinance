<!DOCTYPE html>
<html lang="id">
    <head>
        <meta charset="utf-8">
        <title>Anggaran vs Realisasi</title>
        @include('pdf.partials.style')
    </head>
    <body>
        <div class="header">
            <h1>Anggaran vs Realisasi</h1>
            <p>{{ $workspace }} &middot; {{ \App\Support\MonthPeriod::label($period['from']) }} &ndash; {{ \App\Support\MonthPeriod::label($period['to']) }}</p>
            <p>Dibuat {{ $generated_at }}</p>
        </div>

        <table>
            <thead>
                <tr>
                    <th>Kategori</th>
                    <th>Bulan</th>
                    <th class="right">Limit</th>
                    <th class="right">Terpakai</th>
                    <th class="right">Sisa</th>
                    <th class="right">Persen</th>
                    <th class="center">Melebihi</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($payload['categories'] as $category)
                    @foreach ($payload['months'] as $month)
                        @php
                            $cell = $category['cells'][$month] ?? [
                                'limit' => '0.00',
                                'used' => '0.00',
                                'remaining' => '0.00',
                                'percent' => null,
                                'is_over' => false,
                            ];
                            $percent = $cell['percent'] === null ? '–' : number_format($cell['percent'], 1, ',', '.');
                        @endphp
                        <tr>
                            <td>{{ $category['name'] }}</td>
                            <td class="center">{{ \App\Support\MonthPeriod::label($month) }}</td>
                            <td class="right">{{ \App\Support\Money::format($cell['limit']) }}</td>
                            <td class="right">{{ \App\Support\Money::format($cell['used']) }}</td>
                            <td class="right {{ $cell['is_over'] ? 'over' : '' }}">{{ \App\Support\Money::format($cell['remaining']) }}</td>
                            <td class="right">{{ $percent }}</td>
                            <td class="center">{{ $cell['is_over'] ? 'Ya' : 'Tidak' }}</td>
                        </tr>
                    @endforeach
                    <tr class="total">
                        <td colspan="2">{{ $category['name'] }} (total)</td>
                        <td class="right">{{ \App\Support\Money::format($category['limit_total']) }}</td>
                        <td class="right">{{ \App\Support\Money::format($category['used_total']) }}</td>
                        <td class="right {{ $category['is_over'] ? 'over' : '' }}">{{ \App\Support\Money::format($category['remaining_total']) }}</td>
                        <td class="right">{{ $category['percent_total'] === null ? '–' : number_format($category['percent_total'], 1, ',', '.') }}</td>
                        <td class="center">{{ $category['is_over'] ? 'Ya' : 'Tidak' }}</td>
                    </tr>
                @endforeach
                <tr class="total">
                    <td colspan="2">Semua kategori</td>
                    <td class="right">{{ \App\Support\Money::format($payload['totals']['limit']) }}</td>
                    <td class="right">{{ \App\Support\Money::format($payload['totals']['used']) }}</td>
                    <td class="right {{ \App\Support\Money::isNegative($payload['totals']['remaining']) ? 'over' : '' }}">{{ \App\Support\Money::format($payload['totals']['remaining']) }}</td>
                    <td class="right">{{ $payload['totals']['percent'] === null ? '–' : number_format($payload['totals']['percent'], 1, ',', '.') }}</td>
                    <td class="center">{{ $payload['totals']['over_count'] }}</td>
                </tr>
            </tbody>
        </table>

        <div class="footer">
            Alfinance &middot; Anggaran vs realisasi {{ $workspace }} &middot; dicetak {{ $generated_at }}
        </div>
    </body>
</html>