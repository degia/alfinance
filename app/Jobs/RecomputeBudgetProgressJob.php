<?php

namespace App\Jobs;

use App\Services\Budgets\BudgetService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Membangun ulang `budget_progress_cache` untuk satu workspace + bulan.
 *
 * Dipanggil dari listener `TransactionSaved` / `TransactionDeleted` (Fase 4),
 * sehingga setiap transaksi yang tersimpan atau terhapus membuat angka
 * anggaran menyusul lewat antrean, bukan lewat agregasi di dalam request
 * (ARCHITECTURE.md §2.3).
 *
 * Idempoten: hasilnya murni turunan dari data transaksi bulan itu, jadi
 * menjalankan berkali-kali untuk workspace + bulan yang sama aman dan berakhir
 * pada angka yang sama. Karena itu job ini unik per workspace + bulan — impor
 * 500 transaksi tidak memicu 500 agregasi.
 *
 * Job berjalan tanpa request, jadi tidak ada `ActiveWorkspace` dari
 * middleware; workspace datang dari argumen dan semua query di
 * {@see BudgetService} sudah eksplisit `workspace_id`.
 */
class RecomputeBudgetProgressJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /**
     * Agregasi selesai dalam hitungan detik, jadi kunci dilepas cepat dan
     * transaksi berikutnya di bulan yang sama tetap ikut terproses.
     */
    private const UNIQUE_FOR_SECONDS = 300;

    public function __construct(
        public readonly int $workspaceId,
        public readonly string $month,
    ) {}

    public function handle(BudgetService $budgets): void
    {
        try {
            $budgets->recomputeForMonth($this->workspaceId, $this->month);
        } catch (\Throwable $exception) {
            // Kegagalan agregat tidak boleh menggagalkan penulisan transaksi:
            // angka anggaran akan dibangun ulang pada eksekusi berikutnya.
            Log::error('Gagal membangun ulang progress anggaran.', [
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
