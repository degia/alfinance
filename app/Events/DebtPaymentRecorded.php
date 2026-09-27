<?php

namespace App\Events;

use App\Models\DebtPayment;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Dipanggil setelah satu cicilan utang/piutang tercatat dan `remaining` +
 * `status` sudah disegarkan di DB transaction yang sama (PRD.md §3.7).
 *
 * Dipakai listener yang menyegarkan snapshot net worth bulan berjalan: sisa
 * utang ikut terhitung di net worth kalau opt-in menyala, jadi trennya harus
 * menyusul.
 */
class DebtPaymentRecorded
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly DebtPayment $payment,
        public readonly ?int $actorId = null,
    ) {}

    /**
     * Bulan pembayaran `Y-m`.
     */
    public function month(): string
    {
        return $this->payment->paid_at->format('Y-m');
    }

    public function workspaceId(): int
    {
        return (int) $this->payment->workspace_id;
    }
}
