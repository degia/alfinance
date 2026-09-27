<?php

namespace App\Events;

use App\Models\Transaction;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Dipanggil setiap kali transaksi tersimpan (baru maupun diperbarui) dan
 * saldo akun sudah ikut ter-update di DB transaction yang sama
 * (ARCHITECTURE.md §2.2).
 *
 * Listener penginvalidasi cache baru dibuat di Fase 6; event ini sengaja
 * dikirim sekarang supaya write path Fase 3 sudah final dan tidak perlu
 * diubah saat listener ditambahkan nanti.
 */
class TransactionSaved
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly Transaction $transaction,
        public readonly bool $created = false,
    ) {}

    /**
     * Bulan transaksi dalam format `Y-m` — kunci cache agregat yang ikut
     * di-invalidate (dashboard, budget progress).
     */
    public function month(): string
    {
        return $this->transaction->occurred_at->format('Y-m');
    }

    public function workspaceId(): int
    {
        return $this->transaction->workspace_id;
    }
}
