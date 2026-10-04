<?php

namespace App\Enums;

/**
 * Tipe akun keuangan (PRD.md §3.2).
 *
 * `Cash`, `Bank`, `EWallet`, dan `Saving` behave sebagai kontainer saldo
 * positif. `CreditCard` behave sebagai liabilitas: saldo negatif berarti utang
 * yang harus dibayar, dan wajib punya limit + jadwal tagihan bulanan.
 *
 * `Saving` (tabungan) dicatat dan boleh bertransaksi seperti akun biasa, tapi
 * sengaja tidak ikut "saldo total" maupun dana darurat: uangnya sudah
 * disisihkan, bukan saldo yang bisa dibelanjakan. Kekayaan bersih tetap
 * menghitungnya sebagai aset — menabung bukan mengurangi harta.
 */
enum AccountType: string
{
    case Cash = 'cash';
    case Bank = 'bank';
    case EWallet = 'ewallet';
    case Saving = 'saving';
    case CreditCard = 'credit_card';

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
            self::Cash => 'Kas / Tunai',
            self::Bank => 'Rekening Bank',
            self::EWallet => 'E-Wallet',
            self::Saving => 'Tabungan',
            self::CreditCard => 'Kartu Kredit',
        };
    }

    /**
     * Nama ikon lucide untuk dipakai di layer UI.
     */
    public function icon(): string
    {
        return match ($this) {
            self::Cash => 'banknote',
            self::Bank => 'landmark',
            self::EWallet => 'smartphone',
            self::Saving => 'piggy-bank',
            self::CreditCard => 'credit-card',
        };
    }

    /**
     * Akun ini menyimpan liabilitas (utang), bukan aset likuid?
     */
    public function isLiability(): bool
    {
        return $this === self::CreditCard;
    }

    /**
     * Akun tabungan: aset, tapi tidak termasuk saldo yang bisa dibelanjakan.
     */
    public function isSavings(): bool
    {
        return $this === self::Saving;
    }

    /**
     * Akun ini wajib punya limit dan jadwal tagihan?
     */
    public function requiresCreditLimit(): bool
    {
        return $this->isLiability();
    }

    /**
     * Tipe non-liabilitas boleh punya saldo negatif? Hanya relevan untuk
     * overdraft, jadi default-nya tidak: saldo kas/bank/ewallet tidak
     * boleh minus (validasi di AccountRequest).
     */
    public function allowsNegativeBalance(): bool
    {
        return $this->isLiability();
    }

    /**
     * Ikut atau tidaknya akun ini dalam "saldo total" (dashboard, halaman
     * Akun, panel Transaksi) dan dana darurat.
     */
    public function countsInTotalBalance(): bool
    {
        return ! $this->isLiability() && ! $this->isSavings();
    }
}
