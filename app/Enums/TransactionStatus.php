<?php

namespace App\Enums;

/**
 * Status posting transaksi.
 *
 * `Pending` dipakai oleh transaksi berulang yang disetel "perlu konfirmasi":
 * instance sudah dibuat tapi BELUM boleh memengaruhi `cached_balance` sampai
 * user mengonfirmasi (PRD.md §3.3).
 */
enum TransactionStatus: string
{
    case Posted = 'posted';
    case Pending = 'pending';

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
            self::Posted => 'Terposting',
            self::Pending => 'Menunggu konfirmasi',
        };
    }

    /**
     * Transaksi berstatus ini sudah memengaruhi saldo akun?
     */
    public function affectsBalance(): bool
    {
        return $this === self::Posted;
    }
}
