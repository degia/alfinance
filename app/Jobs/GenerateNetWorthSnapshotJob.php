<?php

namespace App\Jobs;

use App\Services\NetWorth\NetWorthService;
use App\Support\MonthPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Menulis satu baris `net_worth_snapshots` untuk satu workspace + bulan
 * (PRD.md §3.6, ARCHITECTURE.md §2.3).
 *
 * Dua pemicu:
 * 1. scheduler tanggal 1 pukul 00:20 — menutup bulan sebelumnya supaya tren
 *    punya titik final;
 * 2. listener setelah item net worth atau cicilan utang berubah — menjaga
 *    bulan yang sedang berjalan tetap akurat, tanpa menunggu pergantian bulan.
 *
 * Idempoten: snapshot di-upsert, jadi job yang dijalankan berulang (misal
 * karena worker di-restart) tidak membuat baris ganda.
 */
class GenerateNetWorthSnapshotJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /**
     * Perhitungan net worth itu murah (beberapa SUM + daftar item), jadi kunci
     * unik dilepas cepat agar perubahan berikutnya tetap terproses.
     */
    private const UNIQUE_FOR_SECONDS = 120;

    public function __construct(
        public readonly int $workspaceId,
        public readonly string $month,
    ) {}

    public function handle(NetWorthService $netWorth): void
    {
        try {
            $netWorth->snapshot(
                $this->workspaceId,
                MonthPeriod::from($this->month),
                CarbonImmutable::today(),
            );
        } catch (\Throwable $exception) {
            // Snapshot tidak boleh menggagalkan penulisan transaksi/utang.
            // Bulan berikutnya akan ditulis ulang, jadi riwayat tren pulih
            // sendiri tanpa perlu intervensi manual.
            Log::error('Gagal menulis snapshot net worth.', [
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
