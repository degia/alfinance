<?php

namespace App\Listeners;

use App\Events\AccountUpdated;
use App\Events\BudgetUpdated;
use App\Events\DebtPaymentRecorded;
use App\Events\TransactionDeleted;
use App\Events\TransactionSaved;
use App\Jobs\RecomputeDashboardSnapshotJob;
use App\Jobs\RecomputeFinancialHealthJob;
use Carbon\CarbonImmutable;

/**
 * Menerjemahkan perubahan data menjadi pekerjaan agregat di antrean
 * (ARCHITECTURE.md §2.2).
 *
 * Setelah `InvalidateWorkspaceCache` menghapus kunci, angka barunya dihitung
 * di belakang layar:
 * - `RecomputeDashboardSnapshotJob` → mengisi `dashboard_snapshots`.
 * - `RecomputeFinancialHealthJob` → mengisi `financial_health_scores`.
 *
 * Keduanya unik per workspace + bulan, jadi banyak transaksi pada bulan yang
 * sama hanya menghasilkan satu eksekusi job.
 *
 * Bedanya dengan {@see RecomputeBudgetProgress}: listener itu sudah ada sejak
 * Fase 4 dan hanya menangani transaksi, jadi progress anggaran sengaja tidak
 * digabung ke sini agar tidak ada dua listener untuk satu event.
 */
class ScheduleAggregateRecompute
{
    public function handleTransactionSaved(TransactionSaved $event): void
    {
        // Bisa lebih dari satu bulan: tanggal transaksi yang dikoreksi ke bulan
        // lain membuat snapshot bulan lamanya ikut basi.
        foreach ($event->months() as $month) {
            $this->queue($event->workspaceId(), $month);
        }
    }

    public function handleTransactionDeleted(TransactionDeleted $event): void
    {
        $this->queue($event->workspaceId(), $event->month());
    }

    public function handleBudgetUpdated(BudgetUpdated $event): void
    {
        $this->queue($event->workspaceId(), $event->month());
    }

    public function handleAccountUpdated(AccountUpdated $event): void
    {
        $this->queue($event->workspaceId(), CarbonImmutable::now()->format('Y-m'));
    }

    public function handleDebtPaymentRecorded(DebtPaymentRecorded $event): void
    {
        $this->queue($event->workspaceId(), $event->month());
    }

    private function queue(int $workspaceId, string $month): void
    {
        RecomputeDashboardSnapshotJob::dispatch($workspaceId, $month);
        RecomputeFinancialHealthJob::dispatch($workspaceId, $month);
    }
}
