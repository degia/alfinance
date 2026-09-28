<?php

namespace App\Services\Reports;

use App\Models\Budget;
use App\Models\BudgetProgress;
use App\Models\DashboardSnapshot;
use App\Models\Transaction;
use App\Services\Cache\CacheContext;
use App\Services\Cache\WorkspaceCache;
use App\Support\Money;
use App\Support\MonthPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Tiga laporan ringkasan (PRD.md §3.8).
 *
 * Semua laporan membaca tabel agregat, bukan menjumlahkan `transactions` di
 * setiap request (ARCHITECTURE.md §2.1 butir 3):
 * - Arus kas & budget vs actual → `dashboard_snapshots` & `budget_progress_cache`.
 * - Expense breakdown → `budget_progress_cache`.
 *
 * Rentang tanggal menjadi bagian dari cache key
 * (`report-cash-flow:2026-01:2026-09`), jadi mengganti filter hanya membaca
 * cache yang berbeda: tidak ada recompute manual, dan tidak ada satu kunci
 * yang ikut berubah karena filter lain (ARCHITECTURE.md §2.1 butir 1).
 */
class ReportService
{
    public function __construct(private readonly WorkspaceCache $cache) {}

    /**
     * Cash Flow Statement: total in/out & net per bulan (PRD.md §3.8).
     *
     * Tanpa filter akun, angkanya dibaca apa adanya dari `dashboard_snapshots`.
     * Dengan filter akun, tabel agregat tidak punya dimensi akun, jadi service
     * menjalankan SATU agregasi lalu langsung menyimpannya ke cache
     * (read-through, ARCHITECTURE.md §2.1 butir 1) — biayanya dibayar sekali
     * per perubahan data, bukan sekali per klik filter.
     *
     * @return array<string, mixed>
     */
    public function cashFlow(
        int $workspaceId,
        CarbonImmutable $from,
        CarbonImmutable $to,
        ?int $accountId = null,
    ): array {
        $period = $this->period($from, $to, $accountId === null ? null : 'ac'.$accountId);

        /** @var array<string, mixed> $payload */
        $payload = $this->cache->remember(
            $workspaceId,
            CacheContext::ReportCashFlow,
            $period,
            fn (): array => $accountId === null
                ? $this->cashFlowFromSnapshots($workspaceId, $from, $to)
                : $this->cashFlowForAccount($workspaceId, $from, $to, $accountId),
        );

        return $payload;
    }

    /**
     * Budget vs Actual per kategori, ditandai kalau lewat limit (PRD.md §3.8).
     *
     * Dimensinya adalah kategori, bukan akun — anggaran memang disusun per
     * kategori, jadi filter akun tidak berlaku di laporan ini.
     *
     * @return array<string, mixed>
     */
    public function budgetVsActual(
        int $workspaceId,
        CarbonImmutable $from,
        CarbonImmutable $to,
        ?int $categoryId = null,
    ): array {
        $period = $this->period($from, $to, $categoryId === null ? null : 'cat'.$categoryId);

        /** @var array<string, mixed> $payload */
        $payload = $this->cache->remember(
            $workspaceId,
            CacheContext::ReportBudget,
            $period,
            function () use ($workspaceId, $from, $to, $categoryId): array {
                $months = $this->monthRange($from, $to);
                $dates = $this->monthDates($months);

                $rows = Budget::allWorkspaces()
                    // `toBase()`: baris laporan di-hydrate sebagai objek datar
                    // berisi kolom select di bawah, bukan model `Budget` —
                    // model tidak punya properti `category_name`.
                    ->toBase()
                    ->join('categories', 'categories.id', '=', 'budgets.category_id')
                    ->leftJoin('budget_progress_cache', function ($join) use ($workspaceId): void {
                        $join->on('budget_progress_cache.category_id', '=', 'budgets.category_id')
                            ->on('budget_progress_cache.month', '=', 'budgets.month')
                            ->where('budget_progress_cache.workspace_id', '=', $workspaceId);
                    })
                    ->where('budgets.workspace_id', $workspaceId)
                    ->where('categories.workspace_id', $workspaceId)
                    ->whereNull('categories.parent_id')
                    ->whereIn('budgets.month', $dates)
                    ->when($categoryId !== null, fn ($query) => $query->where('budgets.category_id', $categoryId))
                    ->orderBy('categories.name')
                    ->orderBy('budgets.month')
                    ->get([
                        'budgets.category_id',
                        'budgets.month',
                        'budgets.limit_amount',
                        'categories.name as category_name',
                        'categories.color as category_color',
                        'categories.icon as category_icon',
                        'budget_progress_cache.used_amount as progress_used_amount',
                    ]);

                $byCategory = [];

                foreach ($rows as $row) {
                    $key = (int) $row->category_id;

                    $byCategory[$key] ??= [
                        'category_id' => $key,
                        'name' => (string) $row->category_name,
                        'color' => (string) $row->category_color,
                        'icon' => $row->category_icon,
                        'cells' => [],
                        'limit_total' => 0,
                        'used_total' => 0,
                    ];

                    $monthKey = MonthPeriod::key(CarbonImmutable::parse($row->month));
                    $limit = Money::fromDatabaseSum($row->limit_amount);
                    $used = Money::fromDatabaseSum($row->progress_used_amount);

                    $byCategory[$key]['cells'][$monthKey] = [
                        'limit' => $limit,
                        'used' => $used,
                        'remaining' => Money::subtract($limit, $used),
                        'percent' => Money::ratioOf($used, $limit),
                        'is_over' => Money::toCents($used) > Money::toCents($limit),
                    ];

                    $byCategory[$key]['limit_total'] = Money::add($byCategory[$key]['limit_total'], $limit);
                    $byCategory[$key]['used_total'] = Money::add($byCategory[$key]['used_total'], $used);
                }

                $categories = [];

                foreach ($byCategory as $category) {
                    $cells = [];

                    // Bulan tanpa baris budget tetap ditampilkan sebagai nol
                    // supaya lebar matriks seragam di UI.
                    foreach ($months as $month) {
                        $cells[$month] = $category['cells'][$month] ?? [
                            'limit' => '0.00',
                            'used' => '0.00',
                            'remaining' => '0.00',
                            'percent' => null,
                            'is_over' => false,
                        ];
                    }

                    $categories[] = [
                        'category_id' => $category['category_id'],
                        'name' => $category['name'],
                        'color' => $category['color'],
                        'icon' => $category['icon'],
                        'cells' => $cells,
                        'limit_total' => $category['limit_total'],
                        'used_total' => $category['used_total'],
                        'remaining_total' => Money::subtract($category['limit_total'], $category['used_total']),
                        'percent_total' => Money::ratioOf($category['used_total'], $category['limit_total']),
                        'is_over' => Money::isNegative(
                            Money::subtract($category['limit_total'], $category['used_total'])
                        ),
                    ];
                }

                $limitTotal = Money::sum(array_column($categories, 'limit_total'));
                $usedTotal = Money::sum(array_column($categories, 'used_total'));

                return [
                    'from' => MonthPeriod::key($from),
                    'to' => MonthPeriod::key($to),
                    'months' => $months,
                    'categories' => $categories,
                    'totals' => [
                        'limit' => $limitTotal,
                        'used' => $usedTotal,
                        'remaining' => Money::subtract($limitTotal, $usedTotal),
                        'percent' => Money::ratioOf($usedTotal, $limitTotal),
                        'over_count' => count(array_filter($categories, fn (array $row): bool => $row['is_over'])),
                    ],
                    'has_data' => $categories !== [],
                ];
            },
        );

        return $payload;
    }

    /**
     * Expense breakdown per kategori + top-N (PRD.md §3.8).
     *
     * Sumbernya `budget_progress_cache`, jadi "expense" di sini memakai definisi
     * yang sama dengan progress anggaran: transaksi posted bertipe expense.
     * Transfer antar akun tidak dihitung sebagai pengeluaran.
     *
     * @return array<string, mixed>
     */
    public function expenseBreakdown(
        int $workspaceId,
        CarbonImmutable $from,
        CarbonImmutable $to,
        ?int $categoryId = null,
    ): array {
        $period = $this->period($from, $to, $categoryId === null ? null : 'cat'.$categoryId);

        /** @var array<string, mixed> $payload */
        $payload = $this->cache->remember(
            $workspaceId,
            CacheContext::ReportExpense,
            $period,
            function () use ($workspaceId, $from, $to, $categoryId): array {
                $months = $this->monthRange($from, $to);

                $rows = BudgetProgress::allWorkspaces()
                    // Sama seperti matriks anggaran: objek datar hasil select.
                    ->toBase()
                    ->join('categories', 'categories.id', '=', 'budget_progress_cache.category_id')
                    ->where('budget_progress_cache.workspace_id', $workspaceId)
                    ->where('categories.workspace_id', $workspaceId)
                    ->whereNull('categories.parent_id')
                    ->whereIn('budget_progress_cache.month', $this->monthDates($months))
                    ->where('budget_progress_cache.used_amount', '>', 0)
                    ->when($categoryId !== null, fn ($query) => $query->where('budget_progress_cache.category_id', $categoryId))
                    ->get([
                        'budget_progress_cache.category_id',
                        'budget_progress_cache.month',
                        'budget_progress_cache.used_amount',
                        'categories.name as category_name',
                        'categories.color as category_color',
                        'categories.icon as category_icon',
                    ]);

                $byCategory = [];

                foreach ($rows as $row) {
                    $key = (int) $row->category_id;
                    $monthKey = MonthPeriod::key(CarbonImmutable::parse($row->month));
                    $used = Money::toCents($row->used_amount);

                    $byCategory[$key] ??= [
                        'category_id' => $key,
                        'name' => (string) $row->category_name,
                        'color' => (string) $row->category_color,
                        'icon' => $row->category_icon,
                        'total_cents' => 0,
                        'month_cents' => [],
                    ];

                    $byCategory[$key]['total_cents'] += $used;
                    $byCategory[$key]['month_cents'][$monthKey] =
                        ($byCategory[$key]['month_cents'][$monthKey] ?? 0) + $used;
                }

                $totalCents = 0;
                $items = [];

                foreach ($byCategory as $category) {
                    $totalCents += $category['total_cents'];
                    $byMonth = [];

                    foreach ($category['month_cents'] as $monthKey => $cents) {
                        $byMonth[$monthKey] = Money::fromCents($cents);
                    }

                    $items[] = [
                        'category_id' => $category['category_id'],
                        'name' => $category['name'],
                        'color' => $category['color'],
                        'icon' => $category['icon'],
                        'total' => Money::fromCents($category['total_cents']),
                        'by_month' => $byMonth,
                    ];
                }

                $grandTotal = Money::fromCents($totalCents);

                usort(
                    $items,
                    fn (array $a, array $b): int => Money::toCents($b['total']) <=> Money::toCents($a['total'])
                );

                foreach ($items as &$item) {
                    $item['percent'] = Money::percentageOf($item['total'], $grandTotal);
                }
                unset($item);

                $monthsTotals = [];

                foreach ($months as $month) {
                    $sum = 0;

                    foreach ($items as $item) {
                        $sum += Money::toCents($item['by_month'][$month] ?? '0.00');
                    }

                    $monthsTotals[$month] = Money::fromCents($sum);
                }

                return [
                    'from' => MonthPeriod::key($from),
                    'to' => MonthPeriod::key($to),
                    'months' => $months,
                    'total' => $grandTotal,
                    'items' => $items,
                    'months_totals' => $monthsTotals,
                    'top_categories' => array_slice($items, 0, 5),
                    'has_data' => $items !== [],
                ];
            },
        );

        return $payload;
    }

    /**
     * Arus kas dari tabel agregat bulanan.
     *
     * @return array<string, mixed>
     */
    private function cashFlowFromSnapshots(int $workspaceId, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $months = $this->monthRange($from, $to);

        $rows = $this->indexByMonth(
            DashboardSnapshot::allWorkspaces()
                ->where('workspace_id', $workspaceId)
                ->betweenMonths($from, $to)
                ->get(),
        );

        $sums = ['income' => 0, 'expense' => 0, 'net' => 0, 'transfer' => 0];
        $points = [];

        foreach ($months as $month) {
            $snapshot = $rows[$month] ?? null;

            // Bulan tanpa snapshot dijumlahkan sebagai nol supaya total
            // rentang tetap konsisten dengan tabel yang ditampilkan.
            $income = $snapshot === null ? '0.00' : $snapshot->total_income;
            $expense = $snapshot === null ? '0.00' : $snapshot->total_expense;
            $net = $snapshot === null ? '0.00' : $snapshot->net_cash_flow;
            $transfer = $snapshot === null ? '0.00' : $snapshot->total_transfer;

            $sums['income'] += Money::toCents($income);
            $sums['expense'] += Money::toCents($expense);
            $sums['net'] += Money::toCents($net);
            $sums['transfer'] += Money::toCents($transfer);

            $points[] = [
                'month' => $month,
                'label' => MonthPeriod::label($month),
                'income' => $income,
                'expense' => $expense,
                'net_cash_flow' => $net,
                'total_transfer' => $transfer,
                'transaction_count' => $snapshot === null ? 0 : $snapshot->transaction_count,
                'has_data' => $snapshot !== null,
            ];
        }

        return [
            'from' => MonthPeriod::key($from),
            'to' => MonthPeriod::key($to),
            'months' => $months,
            'points' => $points,
            'totals' => [
                'income' => Money::fromCents($sums['income']),
                'expense' => Money::fromCents($sums['expense']),
                'net_cash_flow' => Money::fromCents($sums['net']),
                'total_transfer' => Money::fromCents($sums['transfer']),
            ],
            'is_account_filtered' => false,
            'has_data' => collect($points)->contains('has_data', true),
        ];
    }

    /**
     * Arus kas untuk satu akun: satu agregasi, lalu di-cache.
     *
     * Agregasi dikelompokkan per TANGGAL (bukan per bulan) karena ekpresi bulan
     * berbeda antara MySQL dan SQLite, sementara `DATE()` ada di keduanya. Hasil
     * per tanggal (paling banyak satu baris per hari per tipe) digabung ke bulan
     * di PHP — jumlah barisnya tetap kecil walau rentangnya 36 bulan.
     *
     * @return array<string, mixed>
     */
    private function cashFlowForAccount(
        int $workspaceId,
        CarbonImmutable $from,
        CarbonImmutable $to,
        int $accountId,
    ): array {
        $months = $this->monthRange($from, $to);

        $rows = Transaction::allWorkspaces()
            ->where('workspace_id', $workspaceId)
            // `involvingAccount` memasukkan transfer masuk maupun keluar, jadi
            // laporan per akun tetap menampilkan perpindahan uang.
            ->involvingAccount($accountId)
            ->posted()
            ->occurredBetween(
                $from->startOfMonth()->toDateString(),
                $to->endOfMonth()->toDateString(),
            )
            ->toBase()
            ->selectRaw('DATE(occurred_at) as day, type, COALESCE(SUM(amount), 0) as type_total')
            ->groupBy('day', 'type')
            ->get();

        $perMonth = [];

        foreach ($rows as $row) {
            $monthKey = substr((string) $row->day, 0, 7);
            $type = (string) $row->type;
            $cents = Money::toCents($row->type_total);

            $bucket = $perMonth[$monthKey] ?? ['income' => 0, 'expense' => 0, 'transfer' => 0];

            $bucket[$type] = ($bucket[$type] ?? 0) + $cents;
            $perMonth[$monthKey] = $bucket;
        }

        $points = [];
        $sums = ['income' => 0, 'expense' => 0, 'net' => 0, 'transfer' => 0];

        foreach ($months as $month) {
            $bucket = $perMonth[$month] ?? ['income' => 0, 'expense' => 0, 'transfer' => 0];

            $income = Money::fromCents($bucket['income']);
            $expense = Money::fromCents($bucket['expense']);
            $transfer = Money::fromCents($bucket['transfer']);
            $net = Money::subtract($income, $expense);

            $sums['income'] += $bucket['income'];
            $sums['expense'] += $bucket['expense'];
            $sums['net'] += Money::toCents($net);
            $sums['transfer'] += $bucket['transfer'];

            $points[] = [
                'month' => $month,
                'label' => MonthPeriod::label($month),
                'income' => $income,
                'expense' => $expense,
                'net_cash_flow' => $net,
                'total_transfer' => $transfer,
                'has_data' => $bucket['income'] > 0 || $bucket['expense'] > 0 || $bucket['transfer'] > 0,
            ];
        }

        return [
            'from' => MonthPeriod::key($from),
            'to' => MonthPeriod::key($to),
            'months' => $months,
            'points' => $points,
            'totals' => [
                'income' => Money::fromCents($sums['income']),
                'expense' => Money::fromCents($sums['expense']),
                'net_cash_flow' => Money::fromCents($sums['net']),
                'total_transfer' => Money::fromCents($sums['transfer']),
            ],
            'is_account_filtered' => true,
            'has_data' => $sums['income'] > 0 || $sums['expense'] > 0,
        ];
    }

    /**
     * Daftar bulan inklusif antara dua batas bulan.
     *
     * @return array<int, string>
     */
    private function monthRange(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $months = [];
        $cursor = $from->startOfMonth();
        $last = $to->startOfMonth();

        while ($cursor->lessThanOrEqualTo($last)) {
            $months[] = MonthPeriod::key($cursor);
            $cursor = $cursor->addMonthNoOverflow();
        }

        return $months;
    }

    /**
     * Tanggal pertama bulan untuk daftar `Y-m` — bentuk yang dipakai kolom
     * `month` di tabel agregat.
     *
     * @param  array<int, string>  $months
     * @return array<int, string>
     */
    private function monthDates(array $months): array
    {
        return array_map(
            static fn (string $month): string => MonthPeriod::from($month)->toDateString(),
            $months,
        );
    }

    /**
     * Cache key satu rentang + filter opsional.
     */
    private function period(CarbonImmutable $from, CarbonImmutable $to, ?string $suffix = null): string
    {
        $period = MonthPeriod::key($from).':'.MonthPeriod::key($to);

        return $suffix === null ? $period : $period.':'.$suffix;
    }

    /**
     * Indeks snapshot per `Y-m` sebagai array biasa, bukan hasil `keyBy()`.
     *
     * `keyBy()` mempertahankan tipe kunci generik aslinya, sehingga akses
     * dengan kunci string berikutnya lolos statis dan `?? null` terlihat
     * mustahil — padahal snapshot yang belum ada adalah kondisi normal.
     *
     * @param  Collection<int, DashboardSnapshot>  $snapshots
     * @return array<string, DashboardSnapshot>
     */
    private function indexByMonth(Collection $snapshots): array
    {
        $index = [];

        foreach ($snapshots as $snapshot) {
            $index[$snapshot->monthKey()] = $snapshot;
        }

        return $index;
    }
}
