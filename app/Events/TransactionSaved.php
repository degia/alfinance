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
        /**
         * Bulan `occurred_at` SEBELUM perubahan, kalau tanggalnya berpindah
         * bulan. Null untuk transaksi baru.
         */
        public readonly ?string $previousMonth = null,
    ) {}

    /**
     * Bulan transaksi dalam format `Y-m` — kunci cache agregat yang ikut
     * di-invalidate (dashboard, budget progress).
     */
    public function month(): string
    {
        return $this->transaction->occurred_at->format('Y-m');
    }

    /**
     * Semua bulan yang ikut ter-invalidate setelah perubahan ini.
     *
     * Mengoreksi tanggal transaksi bisa memindahkannya ke bulan lain (mis.
     * 31 Jan salah input lalu dibetulkan jadi 10 Feb). Aggregate bulan lama —
     * `budget_progress_cache` dan `dashboard_snapshots` — ikut basi kalau hanya
     * bulan baru yang dihitung ulang, dan angka bulan lama itu tidak akan
     * pernah diperbaiki sendiri karena agregat hanya dihitung ulang saat ada
     * perubahan atau lewat job terjadwal bulanan.
     *
     * @return array<int, string>
     */
    public function months(): array
    {
        $current = $this->month();

        if ($this->previousMonth === null || $this->previousMonth === $current) {
            return [$current];
        }

        return [$this->previousMonth, $current];
    }

    public function workspaceId(): int
    {
        return $this->transaction->workspace_id;
    }
}
