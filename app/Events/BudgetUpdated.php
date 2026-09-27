<?php

namespace App\Events;

use App\Models\Budget;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Dipanggil setiap kali limit anggaran tersimpan (baru maupun diperbarui) dan
 * `budget_progress_cache` untuk kategori/bulan itu sudah disegarkan di DB
 * transaction yang sama (ARCHITECTURE.md §2.3).
 *
 * Dipakai listener Fase 6 untuk invalidate cache dashboard. Yang penting
 * sekarang: ada satu titik trigger yang jelas, jadi tidak ada jalur lain yang
 * boleh menulis limit anggaran.
 */
class BudgetUpdated
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly Budget $budget,
        public readonly ?int $actorId = null,
    ) {}

    /**
     * Bulan anggaran `Y-m`.
     */
    public function month(): string
    {
        return $this->budget->monthKey();
    }

    public function workspaceId(): int
    {
        return (int) $this->budget->workspace_id;
    }
}
