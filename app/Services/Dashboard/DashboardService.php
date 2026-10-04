<?php

namespace App\Services\Dashboard;

use App\Jobs\RecomputeDashboardSnapshotJob;
use App\Models\Account;
use App\Models\BudgetProgress;
use App\Models\DashboardDailySnapshot;
use App\Models\DashboardSnapshot;
use App\Models\Transaction;
use App\Services\Cache\CacheContext;
use App\Services\Cache\WorkspaceCache;
use App\Services\FinancialHealth\FinancialHealthService;
use App\Support\Money;
use App\Support\MonthPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Read path dashboard (PRD.md §3.1, ARCHITECTURE.md §2.1).
 *
 * Aturan yang dipegang kelas ini: HANYA membaca Redis/`dashboard_snapshots`,
 * tidak pernah menjumlahkan `transactions` sendiri. Kalau snapshot bulan ini
 * belum ada, angka nol dikembalikan sambil terjadwalkan perhitungannya, bukan
 * dengan menghitung on-the-fly di dalam request.
 *
 * Setiap blok (KPI, tren, donut, transaksi terakhir) punya kunci cache sendiri
 * supaya request yang hanya butuh satu blok tidak memuat yang lain, dan supaya
 * invalidasi bisa bersifat selektif.
 *
 * Query ke `accounts` dan `budget_progress_cache` tetap ada, tapi hanya pada
 * cache miss: keduanya tabel kecil yang sudah berupa angka ter-maintain, bukan
 * agregasi `transactions` (ARCHITECTURE.md §2.1 butir 3).
 */
class DashboardService
{
    /**
     * Jumlah tren yang valid (PRD.md §3.1: 6/12 bulan terakhir).
     */
    public const TREND_OPTIONS = [6, 12];

    /**
     * Baris tabel transaksi terakhir (PRD.md §3.1).
     */
    public const RECENT_LIMIT = 10;

    public function __construct(
        private readonly WorkspaceCache $cache,
        private readonly FinancialHealthService $health,
    ) {}

    /**
     * Seluruh payload halaman dashboard untuk satu bulan.
     *
     * @return array<string, mixed>
     */
    public function overview(int $workspaceId, ?string $month = null, int $trendMonths = 6): array
    {
        $month = $this->resolveMonth($month);
        $trendMonths = $this->normalizeTrendMonths($trendMonths);

        return [
            'month' => MonthPeriod::key($month),
            'month_label' => MonthPeriod::label(MonthPeriod::key($month)),
            'trend_months' => $trendMonths,
            'kpi' => $this->kpi($workspaceId, $month),
            'cash_flow' => $this->cashFlowTrend($workspaceId, $month, $trendMonths),
            'daily_cash_flow' => $this->dailyCashFlow($workspaceId, $month),
            'expense_breakdown' => $this->expenseBreakdown($workspaceId, $month),
            'recent_transactions' => $this->recentTransactions($workspaceId),
            'health' => $this->health->current($workspaceId, $month),
        ];
    }

    /**
     * Empat KPI card + perbandingan terhadap bulan sebelumnya
     * (PRD.md §3.1).
     *
     * @return array<string, mixed>
     */
    public function kpi(int $workspaceId, CarbonImmutable $month): array
    {
        $period = MonthPeriod::key($month);

        $cached = $this->cache->get($workspaceId, CacheContext::Dashboard, $period);

        if (is_array($cached)) {
            return $cached;
        }

        [$current, $previous] = $this->twoMonthSnapshots($workspaceId, $month);

        if ($current === null) {
            /*
             * Snapshot bulan ini belum ada: jangan menjumlahkan transaksi di
             * sini. Job dijadwalkan supaya request berikutnya sudah punya angka,
             * dan hasilnya sengaja TIDAK disimpan ke cache — kalau ikut
             * disimpan, UI akan menampilkan "diproses" selama TTL penuh padahal
             * job selesai dalam hitungan detik.
             */
            RecomputeDashboardSnapshotJob::dispatch($workspaceId, $period);

            return $this->pendingKpi($workspaceId, $month, $period, $previous);
        }

        $payload = [
            'month' => $period,
            'month_label' => MonthPeriod::label($period),
            'is_pending' => false,
            'total_balance' => $this->totalBalance($workspaceId),
            'income' => $current->total_income,
            'expense' => $current->total_expense,
            'net_cash_flow' => $current->net_cash_flow,
            'total_transfer' => $current->total_transfer,
            'transaction_count' => $current->transaction_count,
            'has_previous' => $previous !== null,
            'generated_at' => $current->generated_at?->toIso8601String(),
            'income_change' => $previous === null
                ? null
                : $this->change(Money::subtract($current->total_income, $previous->total_income), $previous->total_income),
            'expense_change' => $previous === null
                ? null
                : $this->change(Money::subtract($current->total_expense, $previous->total_expense), $previous->total_expense),
            'net_cash_flow_change' => $previous === null
                ? null
                : $this->change(Money::subtract($current->net_cash_flow, $previous->net_cash_flow), Money::absolute($previous->net_cash_flow)),
        ];

        $this->cache->put($workspaceId, CacheContext::Dashboard, $payload, $period);

        return $payload;
    }

    /**
     * Deret tren arus kas untuk N bulan terakhir, termasuk bulan yang belum
     * punya transaksi (nilainya nol, bukan bolong) supaya garis grafik tidak
     * terputus.
     *
     * @return array<string, mixed>
     */
    public function cashFlowTrend(int $workspaceId, CarbonImmutable $month, int $months = 6): array
    {
        $months = $this->normalizeTrendMonths($months);
        $period = MonthPeriod::key($month).':'.$months;

        /** @var array<string, mixed> $payload */
        $payload = $this->cache->remember(
            $workspaceId,
            CacheContext::DashboardCashFlow,
            $period,
            function () use ($workspaceId, $month, $months): array {
                $first = $month->startOfMonth()->subMonthsNoOverflow($months - 1);
                $last = $month->startOfMonth();

                $rows = $this->indexByMonth(
                    DashboardSnapshot::allWorkspaces()
                        ->where('workspace_id', $workspaceId)
                        ->betweenMonths($first, $last)
                        ->orderBy('month')
                        ->get(),
                );

                $points = [];

                for ($offset = 0; $offset < $months; $offset++) {
                    $cursor = $first->addMonthsNoOverflow($offset);
                    $key = MonthPeriod::key($cursor);
                    $snapshot = $rows[$key] ?? null;

                    $points[] = [
                        'month' => $key,
                        'label' => MonthPeriod::shortLabel($key),
                        // Bulan tanpa snapshot dilaporkan sebagai nol, bukan
                        // error: tren harus tetap punya titik untuk setiap bulan
                        // agar sumbu waktu tidak berlubang.
                        'income' => $snapshot === null ? '0.00' : $snapshot->total_income,
                        'expense' => $snapshot === null ? '0.00' : $snapshot->total_expense,
                        'net_cash_flow' => $snapshot === null ? '0.00' : $snapshot->net_cash_flow,
                        'has_data' => $snapshot !== null,
                        'is_current' => $key === MonthPeriod::key($month),
                    ];
                }

                return [
                    'months' => $months,
                    'points' => $points,
                    'total_income' => Money::sum(array_column($points, 'income')),
                    'total_expense' => Money::sum(array_column($points, 'expense')),
                    'net_cash_flow' => Money::sum(array_column($points, 'net_cash_flow')),
                ];
            },
        );

        return $payload;
    }

    /**
     * Deret harian pemasukan/pengeluaran untuk satu bulan — sumber line chart
     * harian di dashboard.
     *
     * Bedanya dengan {@see cashFlowTrend()}: yang ini tidak boleh memakai
     * `GROUP BY DATE(occurred_at)` ke `transactions`, jadi sumbernya
     * `dashboard_daily_snapshots`, tabel agregat yang ditulis job yang sama
     * seperti snapshot bulanan.
     *
     * Setiap hari dalam bulan — termasuk hari yang belum terjadi dan hari tanpa
     * transaksi — tetap punya titik bernilai nol. Sumbu waktu harus penuh agar
     * garis tidak menggantung melompati tanggal, dan jumlah titik harus tetap
     * jumlah hari bulan itu supaya posisi titik selalu berarti tanggal yang sama.
     *
     * Hanya tanggal yang punya transaksi yang punya baris di tabel agregat, jadi
     * `indexByDate` bisa bolong dan itu memang diharapkan.
     *
     * @return array<string, mixed>
     */
    public function dailyCashFlow(int $workspaceId, CarbonImmutable $month): array
    {
        $period = MonthPeriod::key($month);

        $cached = $this->cache->get($workspaceId, CacheContext::DashboardDaily, $period);

        if (is_array($cached)) {
            return $cached;
        }

        $rows = $this->indexByDate(
            DashboardDailySnapshot::allWorkspaces()
                ->where('workspace_id', $workspaceId)
                ->ofMonth($month)
                ->get(),
        );

        if ($rows === []) {
            /*
             * Sama seperti `kpi()`: baris harian belum ada karena workspace ini
             * belum pernah punya agregat harian (tabel baru ditambahkan setelah
             * snapshot bulanan sudah terisi). Membangunnya di sini berarti
             * menjumlahkan `transactions` per tanggal di jalur request, jadi
             * sebagai gantinya job dijadwalkan.
             *
             * Hasil pending ini sengaja TIDAK masuk cache, sama seperti
             * `pendingKpi()`: job selesai dalam hitungan detik, sedangkan TTL
             * cache 10 menit akan menahan tampilan "sedang dihitung" jauh lebih
             * lama dari yang sebenarnya.
             */
            RecomputeDashboardSnapshotJob::dispatch($workspaceId, $period);

            return $this->pendingDailyCashFlow($month, $period);
        }

        $days = $month->daysInMonth;
        $first = $month->startOfMonth();
        $today = CarbonImmutable::now()->toDateString();
        $points = [];

        for ($day = 1; $day <= $days; $day++) {
            // `addDays` dari tanggal 1, bukan `setDate()`: batas `daysInMonth` sudah
            // dijamin oleh loop, jadi tidak ada risiko overflow.
            $date = $first->addDays($day - 1);
            $key = $date->toDateString();
            $snapshot = $rows[$key] ?? null;

            $points[] = [
                'date' => $key,
                // Sumbu X hanya menampilkan angka hari ("1".."31"); tanggal
                // lengkap ada di `tooltip_label` supaya label sumbu tetap
                // muat di kartu yang sempit.
                'label' => (string) $day,
                'tooltip_label' => $date->translatedFormat('j M Y'),
                'income' => $snapshot === null ? '0.00' : $snapshot->total_income,
                'expense' => $snapshot === null ? '0.00' : $snapshot->total_expense,
                'net_cash_flow' => $snapshot === null ? '0.00' : $snapshot->net_cash_flow,
                'has_data' => $snapshot !== null,
                'is_today' => $key === $today,
            ];
        }

        $payload = [
            'month' => $period,
            'is_pending' => false,
            'days' => $days,
            'points' => $points,
            'total_income' => Money::sum(array_column($points, 'income')),
            'total_expense' => Money::sum(array_column($points, 'expense')),
            'net_cash_flow' => Money::sum(array_column($points, 'net_cash_flow')),
        ];

        $this->cache->put($workspaceId, CacheContext::DashboardDaily, $payload, $period);

        return $payload;
    }

    /**
     * Donut/daftar pengeluaran per kategori induk untuk satu bulan
     * (PRD.md §3.1).
     *
     * Sumbernya `budget_progress_cache`: satu baris per kategori per bulan yang
     * nilainya sudah ditulis `RecomputeBudgetProgressJob`. Hanya kategori
     * induk yang dipakai karena baris induk sudah mencakup sub-kategorinya —
     * menjumlahkan keduanya akan menghitung dua kali.
     *
     * @return array<string, mixed>
     */
    public function expenseBreakdown(int $workspaceId, CarbonImmutable $month): array
    {
        $period = MonthPeriod::key($month);

        /** @var array<string, mixed> $payload */
        $payload = $this->cache->remember(
            $workspaceId,
            CacheContext::DashboardBreakdown,
            $period,
            function () use ($workspaceId, $month): array {
                // `toBase()` deliberate: baris di-hydrate sebagai objek datar
                // dengan kolom yang sudah dipilih. Mengambil `category` sebagai
                // relasi akan menambah satu query per kategori (N+1) hanya untuk
                // tiga kolom yang sudah ada di tabel `categories`.
                $rows = BudgetProgress::allWorkspaces()
                    ->toBase()
                    ->select([
                        'budget_progress_cache.category_id',
                        'budget_progress_cache.used_amount',
                        'categories.name as category_name',
                        'categories.color as category_color',
                        'categories.icon as category_icon',
                    ])
                    ->join('categories', 'categories.id', '=', 'budget_progress_cache.category_id')
                    // Second tenant guard: id kategori unik global, tapi
                    // kondisi ini membuat kebocoran lintas workspace mustahil
                    // meski join-nya salah someday.
                    ->where('categories.workspace_id', $workspaceId)
                    ->whereNull('categories.parent_id')
                    ->where('budget_progress_cache.workspace_id', $workspaceId)
                    ->where('budget_progress_cache.month', $month->startOfMonth()->toDateString())
                    ->orderByDesc('budget_progress_cache.used_amount')
                    ->get();

                $total = Money::sum(
                    $rows->map(fn (object $row): string => (string) $row->used_amount)->all()
                );

                $items = $rows
                    ->map(fn (object $row): array => [
                        'category_id' => (int) $row->category_id,
                        'name' => (string) $row->category_name,
                        'color' => (string) $row->category_color,
                        'icon' => (string) $row->category_icon,
                        'amount' => (string) $row->used_amount,
                        'percent' => Money::percentageOf((string) $row->used_amount, $total),
                    ])
                    ->values()
                    ->all();

                return [
                    'month' => MonthPeriod::key($month),
                    'total' => $total,
                    'items' => $items,
                    'has_data' => $items !== [],
                ];
            },
        );

        return $payload;
    }

    /**
     * Tabel 10 transaksi terakhir (PRD.md §3.1).
     *
     * Ini satu-satunya bagian dashboard yang menyentuh `transactions`, dan itu
     * memang diizinkan ARCHITECTURE.md §2.1 butir 5: query mentah boleh untuk
     * daftar on-demand asal kecil, terfilter, dan dibungkus cache singkat.
     * Bentuk payloadnya sengaja lebih ringan dari tabel transaksi penuh — tanpa
     * tag/lampiran — supaya tidak ada relasi yang memicu N+1.
     *
     * @return array<int, array<string, mixed>>
     */
    public function recentTransactions(int $workspaceId, int $limit = self::RECENT_LIMIT): array
    {
        /** @var array<int, array<string, mixed>> $payload */
        $payload = $this->cache->remember(
            $workspaceId,
            CacheContext::DashboardRecent,
            null,
            function () use ($workspaceId, $limit): array {
                return Transaction::allWorkspaces()
                    ->where('workspace_id', $workspaceId)
                    ->with([
                        'account:id,workspace_id,name,type',
                        'category:id,workspace_id,name,color,icon',
                    ])
                    ->orderByDesc('occurred_at')
                    ->orderByDesc('id')
                    ->limit($limit)
                    ->get()
                    ->map(fn (Transaction $transaction): array => [
                        'id' => $transaction->id,
                        'type' => $transaction->type->value,
                        'type_label' => $transaction->type->label(),
                        'is_transfer' => $transaction->isTransfer(),
                        'is_pending' => $transaction->isPending(),
                        'amount' => $transaction->amount,
                        'signed_amount' => Money::fromCents(
                            Money::toCents($transaction->amount) * $transaction->type->sourceSign()
                        ),
                        'note' => $transaction->note,
                        'occurred_at' => $transaction->occurred_at->toDateString(),
                        'account' => $transaction->account === null
                            ? null
                            : [
                                'id' => (int) $transaction->account->id,
                                'name' => $transaction->account->name,
                            ],
                        'category' => $transaction->category === null
                            ? null
                            : [
                                'id' => (int) $transaction->category->id,
                                'name' => $transaction->category->name,
                                'color' => $transaction->category->color,
                            ],
                    ])
                    ->values()
                    ->all();
            },
            WorkspaceCache::TTL_LIST,
        );

        return $payload;
    }

    /**
     * Total saldo seluruh akun aktif yang boleh dibelanjakan.
     *
     * Kartu kredit adalah kewajiban dan akun tabungan sengaja disisihkan, jadi
     * keduanya tidak dihitung: hanya tipe dengan `countsInTotalBalance()` yang
     * ikut.
     *
     * Semuanya dibaca dari `accounts.cached_balance` — kolom yang sengaja
     * di-maintain di jalur tulis transaksi (ARCHITECTURE.md §2.1 butir 2),
     * bukan hasil penjumlahan transaksi.
     */
    public function totalBalance(int $workspaceId): string
    {
        /** @var Collection<int, Account> $accounts */
        $accounts = Account::allWorkspaces()
            ->where('workspace_id', $workspaceId)
            ->active()
            ->get(['type', 'cached_balance']);

        $total = 0;

        foreach ($accounts as $account) {
            if (! $account->type->countsInTotalBalance()) {
                continue;
            }

            $total += Money::toCents($account->cached_balance);
        }

        return Money::fromCents($total);
    }

    /**
     * Dua snapshot berurutan (bulan ini + bulan sebelumnya) dalam satu query.
     *
     * @return array{0: DashboardSnapshot|null, 1: DashboardSnapshot|null}
     */
    private function twoMonthSnapshots(int $workspaceId, CarbonImmutable $month): array
    {
        $current = $month->startOfMonth();
        $previous = $current->subMonthsNoOverflow(1);

        $rows = $this->indexByMonth(
            DashboardSnapshot::allWorkspaces()
                ->where('workspace_id', $workspaceId)
                ->whereIn('month', [
                    $current->toDateString(),
                    $previous->toDateString(),
                ])
                ->get(),
        );

        return [$rows[MonthPeriod::key($current)] ?? null, $rows[MonthPeriod::key($previous)] ?? null];
    }

    /**
     * Indeks snapshot per `Y-m` dalam bentuk array biasa.
     *
     * Sengaja bukan `keyBy()->all()`: tipe generik `keyBy()` tidak pernah
     * berubah dari tipe kunci aslinya, sehingga akses kunci string berikutnya
     * lolos secara statis dan `?? null` ikut dianggap tidak pernah null.
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

    /**
     * Bentuk KPI saat snapshot belum ada.
     *
     * @return array<string, mixed>
     */
    private function pendingKpi(
        int $workspaceId,
        CarbonImmutable $month,
        string $period,
        ?DashboardSnapshot $previous,
    ): array {
        return [
            'month' => $period,
            'month_label' => MonthPeriod::label($period),
            'is_pending' => true,
            'total_balance' => $this->totalBalance($workspaceId),
            'income' => '0.00',
            'expense' => '0.00',
            'net_cash_flow' => '0.00',
            'total_transfer' => '0.00',
            'transaction_count' => 0,
            'has_previous' => $previous !== null,
            'generated_at' => null,
            'income_change' => null,
            'expense_change' => null,
            'net_cash_flow_change' => null,
        ];
    }

    /**
     * Indeks baris harian per `Y-m-d` dalam bentuk array biasa.
     *
     * Alasan yang sama seperti {@see indexByMonth()}: `keyBy()->all()` tidak
     * pernah berubah tipe kuncinya, sehingga akses kunci string berikutnya lolos
     * secara statis dan `?? null` ikut dianggap tidak pernah null.
     *
     * @param  Collection<int, DashboardDailySnapshot>  $snapshots
     * @return array<string, DashboardDailySnapshot>
     */
    private function indexByDate(Collection $snapshots): array
    {
        $index = [];

        foreach ($snapshots as $snapshot) {
            $index[$snapshot->dateKey()] = $snapshot;
        }

        return $index;
    }

    /**
     * Bentuk deret harian saat agregat harian belum ada.
     *
     * Tetap zero-filled per hari (bukan array kosong) supaya frontend punya satu
     * jalur render saja dan tidak perlu tahu mana yang "belum ada".
     *
     * @return array<string, mixed>
     */
    private function pendingDailyCashFlow(CarbonImmutable $month, string $period): array
    {
        $days = $month->daysInMonth;
        $first = $month->startOfMonth();
        $points = [];

        for ($day = 1; $day <= $days; $day++) {
            $date = $first->addDays($day - 1);

            $points[] = [
                'date' => $date->toDateString(),
                'label' => (string) $day,
                'tooltip_label' => $date->translatedFormat('j M Y'),
                'income' => '0.00',
                'expense' => '0.00',
                'net_cash_flow' => '0.00',
                'has_data' => false,
                'is_today' => false,
            ];
        }

        return [
            'month' => $period,
            'is_pending' => true,
            'days' => $days,
            'points' => $points,
            'total_income' => '0.00',
            'total_expense' => '0.00',
            'net_cash_flow' => '0.00',
        ];
    }

    /**
     * Selisih terhadap bulan sebelumnya, dalam bentuk siap tampil.
     *
     * @return array{direction: string, amount: string, percent: float|null}
     */
    private function change(string $delta, string $baseline): array
    {
        $cents = Money::toCents($delta);
        $baselineCents = Money::toCents($baseline);

        return [
            'direction' => match (true) {
                $cents > 0 => 'up',
                $cents < 0 => 'down',
                default => 'flat',
            },
            'amount' => Money::absolute($delta),
            // Persentase hanya bermakna kalau ada pembanding; dari bulan lalu
            // nol, arahnya sudah terlihat dari tanda `direction`.
            'percent' => $baselineCents === 0
                ? null
                : Money::ratioOf(Money::absolute($delta), Money::absolute($baseline)),
        ];
    }

    private function resolveMonth(?string $month): CarbonImmutable
    {
        return $month === null || trim($month) === ''
            ? CarbonImmutable::now()->startOfMonth()
            : MonthPeriod::from($month);
    }

    private function normalizeTrendMonths(int $months): int
    {
        return in_array($months, self::TREND_OPTIONS, true) ? $months : 6;
    }
}
