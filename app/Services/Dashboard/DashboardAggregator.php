<?php

namespace App\Services\Dashboard;

use App\Enums\TransactionType;
use App\Jobs\RecomputeDashboardSnapshotJob;
use App\Models\DashboardSnapshot;
use App\Models\Transaction;
use App\Services\Budgets\BudgetService;
use App\Support\Money;
use App\Support\MonthPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Membangun ulang `dashboard_snapshots` untuk satu workspace + bulan
 * (ARCHITECTURE.md §2.3 butir 3, PRD.md §3.1).
 *
 * Ini satu-satunya tempat yang menjumlahkan `transactions` untuk kebutuhan
 * dashboard, dan ia hanya berjalan di dalam {@see RecomputeDashboardSnapshotJob}
 * — bukan di jalur request. Pembacaan dashboard memakai
 * {@see DashboardService} yang hanya membaca tabel ini lewat cache.
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
     * Hitung & simpan rekap satu bulan.
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
            // tidak ada model yang perlu di-hydrate untuk tiga angka ini.
            ->toBase()
            ->selectRaw('type, COALESCE(SUM(amount), 0) as type_total, COUNT(*) as type_count')
            ->groupBy('type')
            ->get();

        $income = '0.00';
        $expense = '0.00';
        $transfer = '0.00';
        $count = 0;

        foreach ($rows as $row) {
            $total = Money::fromDatabaseSum($row->type_total);
            $count += (int) $row->type_count;

            switch ((string) $row->type) {
                case TransactionType::Income->value:
                    $income = $total;
                    break;
                case TransactionType::Expense->value:
                    $expense = $total;
                    break;
                case TransactionType::Transfer->value:
                    $transfer = $total;
                    break;
            }
        }

        return DB::transaction(function () use ($workspaceId, $month, $income, $expense, $transfer, $count): DashboardSnapshot {
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
            $snapshot->generated_at = CarbonImmutable::now();
            $snapshot->save();

            return $snapshot;
        });
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
