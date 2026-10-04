<?php

namespace App\Services\Debt;

use App\Models\Debt;
use App\Models\DebtPayment;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;

/**
 * Satu-satunya tempat yang menulis `debts.remaining` dan `debts.status`
 * (PRD.md §3.7).
 *
 * Aturan yang dijaga: `remaining` SELALU = pokok dikurangi total
 * `debt_payments`. Karena itu kalkulator ini tidak butuh `TransactionManager`
 * maupun apa pun yang bergantung pada `ActiveWorkspace` — dua write path yang
 * berbeda (catat cicilan dari modul Utang, dan catat pengeluaran "Bayar
 * utang" dari modul Transaksi) cukup memanggil {@see self::recalculate()} di
 * dalam DB transaction masing-masing, dan sisa utang tidak mungkin berbeda
 * dari riwayat pembayarannya.
 *
 * Dipisah dari {@see DebtService} karena `DebtService` menyuntik
 * `TransactionManager`; `TransactionManager` sendiri perlu menulis pembayaran
 * dari sisi modul Transaksi. Memecah kalkulasi ke sini membuat kedua sisi
 * memakai satu implementasi tanpa dependency melingkar.
 */
class DebtBalanceCalculator
{
    /**
     * Hitung ulang `remaining` & `status` dari riwayat pembayaran.
     *
     * Sisa tidak pernah negatif: pembayaran yang melebihi pokok dipotong ke nol
     * dan status menjadi `settled`.
     */
    public function recalculate(Debt $debt): Debt
    {
        $debt->remaining = $this->availableToPay($debt);
        $debt->status = $debt->currentStatus();
        $debt->save();

        return $debt;
    }

    /**
     * Sisa utang yang masih boleh dibayar dengan satu pembayaran baru.
     *
     * `$except` mengecualikan satu baris pembayaran dari hitungan. Ini dipakai
     * saat cicilan yang sudah tercatat diedit: nilai lama ikut memotong sisa,
     * jadi nominal baru harus dibandingkan dengan sisa yang belum termasuk baris
     * itu — kalau tidak, cicilan kedua tidak akan pernah bisa dilunasi.
     */
    public function availableToPay(Debt $debt, ?DebtPayment $except = null): string
    {
        return Money::atLeastZero(Money::subtract($debt->principal, $this->paidAmount($debt, $except)));
    }

    /**
     * Total pembayaran sebuah utang, dengan satu baris boleh dikecualikan.
     *
     * Query memakai `allWorkspaces()` + filter `workspace_id` eksplisit karena
     * `remaining` adalah invariant yang tidak boleh salah, dan kalkulasi boleh
     * dipanggil dari job atau console tanpa workspace aktif — global scope akan
     * mengembalikan nol baris di sana, membuat sisa utang terlihat seolah belum
     * ada cicilan sama sekali.
     */
    private function paidAmount(Debt $debt, ?DebtPayment $except = null): string
    {
        // `SUM(amount)` mengembalikan satuan rupiah sebagai string desimal
        // ("1000000.00"), BUKAN integer sen — cast `(int)` akan memotong dua
        // desimalnya dan membuat sisa utang 100x terlalu besar.
        $total = DebtPayment::allWorkspaces()
            ->where('workspace_id', $debt->workspace_id)
            ->where('debt_id', $debt->id)
            ->when(
                $except !== null && (int) $except->debt_id === (int) $debt->id,
                fn (Builder $query): Builder => $query->whereKeyNot($except->id),
            )
            ->sum('amount');

        return Money::fromDatabaseSum($total);
    }
}
