<?php

namespace App\Services\Dashboard;

use App\Enums\TransactionType;
use App\Jobs\RecomputeDashboardSnapshotJob;
use App\Models\DashboardDailySnapshot;
use App\Models\DashboardSnapshot;
use App\Models\Transaction;
use App\Services\Budgets\BudgetService;
use App\Support\Money;
use App\Support\MonthPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Membangun ulang `dashboard_snapshots` + `dashboard_daily_snapshots` untuk satu
 * workspace + bulan (ARCHITECTURE.md §2.3 butir 3, PRD.md §3.1).
 *
 * Ini satu-satunya tempat yang menjumlahkan `transactions` untuk kebutuhan
 * dashboard, dan ia hanya berjalan di dalam {@see RecomputeDashboardSnapshotJob}
 * — bukan di jalur request. Pembacaan dashboard memakai
 * {@see DashboardService} yang hanya membaca tabel ini lewat cache.
 *
 * Kedua granularitas dibangun dari SATU query: `GROUP BY DATE(occurred_at), type`.
 * Bulanan dijumlahkan dari harian di dalam PHP, jadi tidak ada data yang
 * dihitung dua kali dari dua statement yang bisa berbeda jawabannya (mis. transaksi
 * yang jatuh persis di batas tengah malam).
 *
 * Mirip {@see BudgetService::recomputeForMonth()}:
 * - Idempoten. Hasilnya turunan penuh dari transaksi bulan itu, jadi menjalankan
 *   berkali-kali berakhir pada baris yang sama.
 * - Tidak memakai `ActiveWorkspace`, karena dipanggil dari job/console tanpa
 *   request; `workspace_id` selalu eksplisit.
 */
class DashboardAggregator
{
    /**
     * Hitung & simpan rekap satu bulan (bulanan + harian).
     *
     * `total_transfer` dijumlahkan dari kedua sisi transfer (sumber + tujuan)
     * supaya nilainya berarti "nilai yang dipindahkan", bukan "perpindahan ke
     * luarworkspace" — laporan arus kas memakainya sebagai baris transfer
     * masuk/keluar yang tidak boleh ikut memengaruhi net cash flow.
     */
    public function recomputeForMonth(int $workspaceId, string|CarbonImmutable $month): DashboardSnapshot
    {
        $month = $month instanceof CarbonImmutable ? $month->startOfMonth() : MonthPeriod::from($month);

        [$from, $to] = MonthPeriod::bounds($month);

        $rows = Transaction::allWorkspaces()
            ->where('workspace_id', $workspaceId)
            ->posted()
            ->occurredBetween($from->toDateString(), $to->toDateString())
            // `toBase()` supaya hasilnya object biasa, bukan model: kolom
            // `type` lalu tetap string apa adanya (bukan enum ter-cast) dan
            // tidak ada model yang perlu di-hydrate untuk angka-angka ini.
            ->toBase()
            // `DATE(occurred_at)` tersedia di MySQL maupun SQLite, dan
            // menghitung `day` tidak butuh index karena rentang `occurred_at`
            // sudah dibatasi `occurredBetween` di atas.
            ->selectRaw('DATE(occurred_at) as day, type, COALESCE(SUM(amount), 0) as type_total, COUNT(*) as type_count')
            // Alias `day` boleh dipakai di GROUP BY pada MySQL maupun SQLite.
            ->groupBy('day', 'type')
            ->orderBy('day')
            ->get();

        $income = '0.00';
        $expense = '0.00';
        $transfer = '0.00';
        $count = 0;

        /** @var array<string, array{income: string, expense: string, count: int}> $daily */
        $daily = [];

        foreach ($rows as $row) {
            $total = Money::fromDatabaseSum($row->type_total);
            $typeCount = (int) $row->type_count;
            $count += $typeCount;

            $day = (string) $row->day;
            $bucket = $daily[$day] ?? ['income' => '0.00', 'expense' => '0.00', 'count' => 0];

            switch ((string) $row->type) {
                case TransactionType::Income->value:
                    $income = Money::add($income, $total);
                    $bucket['income'] = Money::add($bucket['income'], $total);
                    break;
                case TransactionType::Expense->value:
                    $expense = Money::add($expense, $total);
                    $bucket['expense'] = Money::add($bucket['expense'], $total);
                    break;
                case TransactionType::Transfer->value:
                    // Transfer tidak punya baris harian: grafik harian hanya
                    // menampilkan kas yang benar-benar masuk/keluar, dan
                    // transfer internal bukan keduanya.
                    $transfer = Money::add($transfer, $total);
                    break;
            }

            $bucket['count'] += $typeCount;
            $daily[$day] = $bucket;
        }

        return DB::transaction(function () use ($workspaceId, $month, $from, $to, $income, $expense, $transfer, $count, $daily): DashboardSnapshot {
            $now = CarbonImmutable::now();

            // `whereYear` + `whereMonth`, bukan `where('month', ...)`: kolom
            // DATE di SQLite menyimpan komponen waktu (`2026-09-01 00:00:00`)
            // sedangkan MySQL memangkas jadi `2026-09-01`. Tanpa ini, hitung
            // ulang bulan yang sudah punya baris tidak menemukan baris lama,
            // lalu menabrak unique index — jadi agregatnya tidak idempoten.
            $snapshot = DashboardSnapshot::allWorkspaces()
                ->where('workspace_id', $workspaceId)
                ->whereYear('month', $month->year)
                ->whereMonth('month', $month->month)
                ->lockForUpdate()
                ->first();

            if ($snapshot === null) {
                $snapshot = new DashboardSnapshot;
                $snapshot->workspace_id = $workspaceId;
                $snapshot->month = $month;
            }

            $snapshot->total_income = $income;
            $snapshot->total_expense = $expense;
            $snapshot->total_transfer = $transfer;
            $snapshot->net_cash_flow = Money::subtract($income, $expense);
            $snapshot->transaction_count = $count;
            $snapshot->generated_at = $now;
            $snapshot->save();

            $this->replaceDailyRows($workspaceId, $from, $to, $daily, $now);

            return $snapshot;
        });
    }

    /**
     * Tulis ulang baris harian satu bulan.
     *
     * Delete-then-insert, bukan upsert: tanggal yang tadinya punya transaksi lalu
     * dihapus semua harus ikut hilang, sedangkan upsert hanya menyentuh tanggal
     * yang ada di `$daily`. Menghapus per bulan membuat tabel tidak menumpuk
     * baris kosong dari bulan-bulan lama.
     *
     * Karena seluruh baris bulan tersebut sudah dihapus lebih dulu, `insert()`
     * tidak mungkin menabrak unique index `(workspace_id, date)` dan bisa
     * dijahit jadi satu statement.
     *
     * @param  array<string, array{income: string, expense: string, count: int}>  $daily
     */
    private function replaceDailyRows(
        int $workspaceId,
        CarbonImmutable $from,
        CarbonImmutable $to,
        array $daily,
        CarbonImmutable $generatedAt,
    ): void {
        DashboardDailySnapshot::allWorkspaces()
            ->where('workspace_id', $workspaceId)
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->delete();

        if ($daily === []) {
            return;
        }

        $rows = [];

        foreach ($daily as $day => $bucket) {
            $rows[] = [
                'workspace_id' => $workspaceId,
                'date' => $day,
                'total_income' => $bucket['income'],
                'total_expense' => $bucket['expense'],
                'net_cash_flow' => Money::subtract($bucket['income'], $bucket['expense']),
                'transaction_count' => $bucket['count'],
                'generated_at' => $generatedAt,
                'created_at' => $generatedAt,
                'updated_at' => $generatedAt,
            ];
        }

        DashboardDailySnapshot::allWorkspaces()->insert($rows);
    }

    /**
     * Rekap beberapa bulan sekaligus (dipakai job penyegaran terjadwal).
     *
     * @param  array<int, string>  $months  Format `Y-m`, boleh tidak urut.
     * @return array<int, DashboardSnapshot>
     */
    public function recomputeForMonths(int $workspaceId, array $months): array
    {
        $snapshots = [];

        foreach ($months as $month) {
            $snapshots[] = $this->recomputeForMonth($workspaceId, $month);
        }

        return $snapshots;
    }
}
