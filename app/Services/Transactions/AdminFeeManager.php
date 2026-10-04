<?php

namespace App\Services\Transactions;

use App\Enums\CategoryIcon;
use App\Enums\TransactionType;
use App\Models\Category;
use App\Models\Transaction;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;

/**
 * Potongan admin sebuah transfer dicatat sebagai transaksi `expense` biasa
 * pada akun sumber, dengan kategori "Biaya Admin" — atau kategori lain kalau
 * user memilih sendiri di form transfer.
 *
 * Alasan begini: biaya admin bukan bagian dari nominal transfer — uang berpindah
 * penuh dari akun sumber ke akun tujuan, lalu biaya admin keluar sebagai
 * pengeluaran tersendiri. Kalau biaya ini disatukan ke `amount` transfer, saldo
 * akun tujuan ikut berkurang dan laporan arus kas jadi salah.
 *
 * Baris turunan ini menunjuk transfer induk lewat
 * `transactions.parent_transaction_id`, sehingga:
 * - saldo tetap dihitung {@see TransactionManager} (dampak digabung ke satu peta
 *   delta per akun, tidak ada dua kali tulis);
 * - baris biaya ikut terperbarui atau terhapus bersama transfernya;
 * - baris biaya tetap sebuah baris transaksi biasa, jadi user masih bisa
 *   mengeditnya sendiri dari daftar transaksi.
 *
 * Query memakai `allWorkspaces()` + filter `workspace_id` eksplisit karena
 * manager ini juga dipanggil dari scheduled job yang berjalan tanpa
 * `ActiveWorkspace` — aturan yang sama dengan {@see TransactionManager}.
 */
class AdminFeeManager
{
    /**
     * Nama kategori tunggal untuk seluruh workspace. Kategori dibuat otomatis
     * saat pertama kali dipakai supaya user tidak wajib menyiapkan master data
     * dulu; kategori yang sudah ada dipakai ulang, bukan diduplikasi.
     */
    public const CATEGORY_NAME = 'Biaya Admin';

    /**
     * Warna kategori otomatis — merah, supaya biaya admin mudah dibedakan dari
     * pengeluaran biasa di donut chart laporan.
     */
    private const CATEGORY_COLOR = '#dc2626';

    /**
     * Samakan baris potongan admin dengan transfer induknya.
     *
     * `$amount` dalam sen; null atau 0 berarti "tidak ada potongan admin" dan
     * baris yang sudah ada dihapus. Baris turunan tidak pernah punya lampiran
     * maupun tag, jadi tidak ada file yang perlu dibersihkan di sini.
     *
     * `$categoryId` opsional: kalau diisi, kategori itulah yang dipakai untuk
     * baris biayanya (user sudah memilih sendiri, misalnya "Fee Transfer BCA"),
     * kalau tidak, kategori otomatis {@see CATEGORY_NAME} yang dipakai.
     *
     * @return Transaction|null baris biaya setelah sinkron, null kalau tidak ada
     */
    public function sync(
        Transaction $transfer,
        ?int $amount,
        ?int $actorId = null,
        ?int $categoryId = null,
    ): ?Transaction {
        $fee = $this->find($transfer);

        if ($amount === null || $amount === 0) {
            $fee?->delete();

            return null;
        }

        $attributes = [
            'account_id' => $transfer->account_id,
            'category_id' => $this->resolveCategory((int) $transfer->workspace_id, $categoryId)->id,
            'transfer_to_account_id' => null,
            'recurring_rule_id' => null,
            'type' => TransactionType::Expense,
            'amount' => Money::fromCents($amount),
            'note' => __('Potongan admin dari transfer #:id', ['id' => $transfer->id]),
            'occurred_at' => $transfer->occurred_at,
            'status' => $transfer->status,
        ];

        if ($fee !== null) {
            $fee->fill($attributes);
            $fee->updated_by = $actorId;
            $fee->save();

            return $fee;
        }

        $fee = new Transaction($attributes);
        $fee->workspace_id = $transfer->workspace_id;
        $fee->parent_transaction_id = $transfer->id;
        $fee->created_by = $actorId;
        $fee->updated_by = $actorId;
        $fee->save();

        return $fee;
    }

    /**
     * Dampak saldo yang harus dibalik ketika baris potongan admin ikut berubah.
     *
     * Dipanggil sebelum `sync()` atau sebelum transfer dihapus, supaya pemanggil
     * bisa menghitung selisih net dengan dampak yang baru. Baris biayanya
     * diteruskan dari {@see find()} supaya tidak ada query kedua untuk hal yang
     * sama.
     *
     * @return array<int, int>
     */
    public function reversedDeltas(?Transaction $fee): array
    {
        return $fee?->reversedBalanceDeltas() ?? [];
    }

    /**
     * Baris potongan admin milik sebuah transfer, kalau ada.
     *
     * Pencarian memakai `allWorkspaces()` + filter `workspace_id` transaksi,
     * bukan global scope ambient: manager ini boleh dipanggil dari scheduled job
     * yang tidak punya `ActiveWorkspace`, dan di sana global scope justru
     * sengaja mengembalikan nol baris.
     */
    public function find(Transaction $transfer): ?Transaction
    {
        if ($transfer->relationLoaded('adminFee')) {
            /** @var Transaction|null $fee */
            $fee = $transfer->getRelation('adminFee');

            return $fee !== null && (int) $fee->workspace_id === (int) $transfer->workspace_id
                ? $fee
                : null;
        }

        return $this->query($transfer)->first();
    }

    /**
     * @return Builder<Transaction>
     */
    private function query(Transaction $transfer): Builder
    {
        return Transaction::allWorkspaces()
            ->where('workspace_id', $transfer->workspace_id)
            ->where('parent_transaction_id', $transfer->id);
    }

    /**
     * Kategori untuk baris potongan admin: kategori yang dipilih user kalau ada,
     * selain itu kategori "Biaya Admin" yang dibuat otomatis.
     *
     * Kategori yang dipilih user diverifikasi ulang di sini (bukan cuma di
     * request) supaya manager ini tetap aman kalau someday dipanggil dari
     * scheduled job atau importer yang tidak lewat validasi FormRequest.
     * Kategori yang tidak ada di workspace transaksi diabaikan dan jatuh ke
     * kategori otomatis — baris biaya tidak boleh gagal ditulis hanya karena
     * kategori alien.
     */
    private function resolveCategory(int $workspaceId, ?int $categoryId): Category
    {
        if ($categoryId === null) {
            return $this->categoryFor($workspaceId);
        }

        /** @var Category|null $category */
        $category = Category::allWorkspaces()
            ->where('workspace_id', $workspaceId)
            ->whereKey($categoryId)
            ->first();

        return $category ?? $this->categoryFor($workspaceId);
    }

    /**
     * Kategori "Biaya Admin" milik workspace, dibuat bila belum ada.
     *
     * Pencarian sengaja hanya ke kategori utama (`parent_id IS NULL`) supaya nama
     * yang sama tidak bentrok dengan sub-kategori milik user. Pembuatan memakai
     * `lockForUpdate` supaya dua request bersamaan tidak menduplikasi kategori.
     */
    public function categoryFor(int $workspaceId): Category
    {
        /** @var Category|null $category */
        $category = Category::allWorkspaces()
            ->where('workspace_id', $workspaceId)
            ->whereNull('parent_id')
            ->where('name', self::CATEGORY_NAME)
            ->lockForUpdate()
            ->first();

        if ($category !== null) {
            return $category;
        }

        $category = new Category([
            'name' => self::CATEGORY_NAME,
            'icon' => CategoryIcon::Other,
            'color' => self::CATEGORY_COLOR,
        ]);
        $category->workspace_id = $workspaceId;
        $category->save();

        return $category;
    }
}
