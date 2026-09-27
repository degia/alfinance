<?php

namespace App\Listeners;

use App\Events\DebtPaymentRecorded;
use App\Events\NetWorthItemSaved;
use App\Jobs\GenerateNetWorthSnapshotJob;
use Carbon\CarbonImmutable;

/**
 * Menjaga snapshot net worth bulan berjalan tetap sinkron dengan perubahan
 * yang memengaruhinya (ARCHITECTURE.md §2.3).
 *
 * Item manual dan cicilan utang bisa berubah kapan saja, sedangkan job
 * terjadwal hanya jalan sebulan sekali. Tanpa listener ini, tren net worth
 * akan tertinggal sampai pergantian bulan.
 *
 * Hanya bulan berjalan yang disegarkan: bulan yang sudah lewat bersifat final
 * dan ditulis oleh scheduler tanggal 1.
 */
class RefreshNetWorthSnapshot
{
    public function handlePayment(DebtPaymentRecorded $event): void
    {
        $this->snapshotFor($event->workspaceId());
    }

    public function handleItem(NetWorthItemSaved $event): void
    {
        $this->snapshotFor($event->workspaceId());
    }

    private function snapshotFor(int $workspaceId): void
    {
        GenerateNetWorthSnapshotJob::dispatch($workspaceId, CarbonImmutable::now()->format('Y-m'));
    }
}
