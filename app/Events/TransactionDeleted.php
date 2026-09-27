<?php

namespace App\Events;

use App\Models\Transaction;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Dipanggil setelah transaksi dihapus (dan dampaknya ke saldo sudah dibalik).
 *
 * Transaksi sudah tidak ada di database, jadi instance pada event hanya
 * dipakai untuk membaca id/workspace/bulan yang perlu di-invalidate.
 */
class TransactionDeleted
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly Transaction $transaction,
    ) {}

    public function month(): string
    {
        return $this->transaction->occurred_at->format('Y-m');
    }

    public function workspaceId(): int
    {
        return $this->transaction->workspace_id;
    }
}
