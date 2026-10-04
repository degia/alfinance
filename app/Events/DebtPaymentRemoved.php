<?php

namespace App\Events;

use App\Models\DebtPayment;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Dipanggil setelah satu cicilan utang/piutang dicabut — transaksi yang
 * mencicilnya dihapus atau diubah sehingga bukan lagi pembayaran (PRD.md §3.7).
 *
 * dibutuhkan terpisah dari `DebtPaymentRecorded` karena sisa utang, dashboard,
 * dan net worth semuanya bergantung pada `debts.remaining`: tanpa sinyal ini,
 * angka yang sudah disegarkan saat cicilan tercatat akan tertinggal sampai
 * penjadwalan bulanan.
 */
class DebtPaymentRemoved
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly DebtPayment $payment,
    ) {}

    /**
     * Bulan `paid_at` cicilan yang dicabut — kunci cache agregat yang ikut
     * di-invalidate.
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
