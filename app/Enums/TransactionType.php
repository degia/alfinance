<?php

namespace App\Enums;

/**
 * Tipe transaksi (PRD.md §3.3, ARCHITECTURE.md §4).
 *
 * `Income` menambah saldo `account_id`, `Expense` menguranginya, dan
 * `Transfer` menyentuh dua akun sekaligus: mengurangi `account_id` (sumber)
 * dan menambah `transfer_to_account_id` (tujuan).
 *
 * `amount` selalu disimpan sebagai magnitude positif — arahnya ditentukan oleh
 * tipe, bukan tanda. Ini yang membuat `SUM(amount)` tidak bisa langsung dipakai
 * untuk net cash flow tanpa memperhitungkan tipe.
 */
enum TransactionType: string
{
    case Income = 'income';
    case Expense = 'expense';
    case Transfer = 'transfer';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function label(): string
    {
        return match ($this) {
            self::Income => 'Pemasukan',
            self::Expense => 'Pengeluaran',
            self::Transfer => 'Transfer',
        };
    }

    /**
     * Nama ikon lucide untuk layer UI.
     */
    public function icon(): string
    {
        return match ($this) {
            self::Income => 'trending-up',
            self::Expense => 'trending-down',
            self::Transfer => 'arrow-left-right',
        };
    }

    /**
     * Transaksi ini menyentuh dua akun sekaligus?
     */
    public function isTransfer(): bool
    {
        return $this === self::Transfer;
    }

    /**
     * Tipe ini wajib punya kategori? Transfer antar akun tidak berkategori
     * karena bukan pengeluaran/pemasukan.
     */
    public function requiresCategory(): bool
    {
        return ! $this->isTransfer();
    }

    /**
     * Tipe ini wajib punya akun tujuan transfer?
     */
    public function requiresTransferTarget(): bool
    {
        return $this->isTransfer();
    }

    /**
     * Dampak ke `cached_balance` akun sumber: positif menambah saldo.
     */
    public function sourceSign(): int
    {
        return $this === self::Income ? 1 : -1;
    }
}
