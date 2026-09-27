<?php

namespace App\Jobs;

use App\Services\Dashboard\DashboardAggregator;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Membangun ulang `dashboard_snapshots` untuk satu workspace + bulan
 * (ARCHITECTURE.md §2.2, §2.3 butir 3).
 *
 * Dipanggil dari listener `ScheduleAggregateRecompute` setiap kali transaksi,
 * anggaran, akun, atau pembayaran utang berubah. Request tidak pernah menunggu
 * proses ini: listener hanya menghapus cache, angka susulan datang dari
 * antrean.
 *
 * Idempoten — hasil akhirnya turunan penuh dari transaksi bulan itu, jadi aman
 * dijalankan berulang. Karena itu unik per workspace + bulan: impor 500
 * transaksi hanya memicu satu agregasi.
 *
 * Job berjalan tanpa request, jadi tidak ada `ActiveWorkspace` dari middleware;
 * workspace datang dari argumen dan query di {@see DashboardAggregator} sudah
 * eksplisit `workspace_id`.
 */
class RecomputeDashboardSnapshotJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    private const UNIQUE_FOR_SECONDS = 300;

    public function __construct(
        public readonly int $workspaceId,
        public readonly string $month,
    ) {}

    public function handle(DashboardAggregator $aggregator): void
    {
        try {
            $aggregator->recomputeForMonth($this->workspaceId, $this->month);
        } catch (\Throwable $exception) {
            // Kegagalan agregat tidak boleh menggagalkan penulisan transaksi:
            // angka dashboard dibangun ulang pada eksekusi berikutnya.
            Log::error('Gagal membangun ulang snapshot dashboard.', [
                'workspace_id' => $this->workspaceId,
                'month' => $this->month,
                'exception' => $exception->getMessage(),
            ]);
        }
    }

    public function uniqueId(): string
    {
        return $this->workspaceId.':'.$this->month;
    }

    public function uniqueFor(): int
    {
        return self::UNIQUE_FOR_SECONDS;
    }
}
