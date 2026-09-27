<?php

namespace App\Listeners;

use App\Events\TransactionDeleted;
use App\Events\TransactionSaved;
use App\Jobs\RecomputeBudgetProgressJob;
use App\Services\Budgets\BudgetService;

/**
 * Setiap transaksi yang tersimpan atau terhapus membuat progress anggaran
 * dihitung ulang lewat antrean (ARCHITECTURE.md §2.3).
 *
 * Listener ini sengaja tipis: ia hanya menerjemahkan event transaksi
 * (workspace + bulan) menjadi job agregat. Semua perhitungan nominal tetap
 * di {@see BudgetService} supaya tidak ada dua
 * implementasi aturan yang bisa berbeda.
 *
 * Event dikirim setelah commit, jadi job tidak mungkin membaca transaksi yang
 * sebenarnya sudah dibatalkan.
 */
class RecomputeBudgetProgress
{
    public function handleSaved(TransactionSaved $event): void
    {
        $this->queueFor($event->workspaceId(), $event->month());
    }

    public function handleDeleted(TransactionDeleted $event): void
    {
        $this->queueFor($event->workspaceId(), $event->month());
    }

    private function queueFor(int $workspaceId, string $month): void
    {
        RecomputeBudgetProgressJob::dispatch($workspaceId, $month);
    }
}
