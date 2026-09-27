<?php

namespace App\Jobs;

use App\Services\FinancialHealth\FinancialHealthService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Menghitung ulang skor kesehatan finansial satu workspace + bulan
 * (PRD.md §3.9, ARCHITECTURE.md §2.1 butir 4).
 *
 * Berjalan di antrean, tidak sinkron di request: user menyimpan transaksi lalu
 * skornya menyusul lewat `ScheduleAggregateRecompute`.
 *
 * Idempoten — skor dihitung ulang dari agregat bulanan dan ditulis dengan
 * upsert, jadi menjalankan berkali-kali untuk workspace + bulan yang sama
 * berakhir pada baris yang sama. Unik per workspace + bulan supaya banyak
 * transaksi beruntun hanya memicu satu perhitungan.
 */
class RecomputeFinancialHealthJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    private const UNIQUE_FOR_SECONDS = 300;

    public function __construct(
        public readonly int $workspaceId,
        public readonly string $month,
    ) {}

    public function handle(FinancialHealthService $health): void
    {
        try {
            $health->recompute($this->workspaceId, $this->month);
        } catch (\Throwable $exception) {
            // Skor yang gagal dihitung tidak boleh menggagalkan penulisan
            // transaksi; angka lama tetap tampil sampai eksekusi berikutnya.
            Log::error('Gagal menghitung ulang skor kesehatan finansial.', [
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
