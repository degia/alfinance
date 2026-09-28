<?php

namespace App\Listeners;

use App\Events\AccountUpdated;
use App\Events\BudgetUpdated;
use App\Events\DebtPaymentRecorded;
use App\Events\TransactionDeleted;
use App\Events\TransactionSaved;
use App\Services\Cache\WorkspaceCache;

/**
 * Write-behind invalidation (ARCHITECTURE.md §2.1 butir 2, §2.2).
 *
 * Listener ini hanya menghapus kunci cache. Tidak ada agregasi, tidak ada
 * `SUM()` — angka susulannya dihitung {@see ScheduleAggregateRecompute}
 * di antrean, supaya request yang menyimpan transaksi tidak ikut menunggu.
 *
 * Invalidasi sengaja melebar ke semua konteks turunan workspace: satu
 * perubahan transaksi bisa memengaruhi KPI, tren, donut, laporan, dan skor
 * kesehatan sekaligus, dan menentukan dependensinya satu per satu di titik ini
 * justru membuka celah lupa (report lama masih tampil basi).
 * Hapus satu blok Redis murahan; menghitung ulang blok yang tidak terpengaruh
 * tetap murah karena sumbernya tabel agregat.
 */
class InvalidateWorkspaceCache
{
    public function __construct(private readonly WorkspaceCache $cache) {}

    public function handleTransactionSaved(TransactionSaved $event): void
    {
        $this->flush($event->workspaceId());
    }

    public function handleTransactionDeleted(TransactionDeleted $event): void
    {
        $this->flush($event->workspaceId());
    }

    public function handleBudgetUpdated(BudgetUpdated $event): void
    {
        $this->flush($event->workspaceId());
    }

    public function handleAccountUpdated(AccountUpdated $event): void
    {
        $this->flush($event->workspaceId());
    }

    public function handleDebtPaymentRecorded(DebtPaymentRecorded $event): void
    {
        $this->flush($event->workspaceId());
    }

    private function flush(int $workspaceId): void
    {
        $this->cache->forgetAll($workspaceId);
    }
}
