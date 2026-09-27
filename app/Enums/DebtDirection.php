<?php

namespace App\Enums;

/**
 * Arah utang/piutang (PRD.md §3.7).
 *
 * `Payable` = kita yang berutang (kewajiban, pembayaran jadi expense).
 * `Receivable` = orang yang berutang ke kita (aset, pembayaran jadi income).
 */
enum DebtDirection: string
{
    case Payable = 'payable';
    case Receivable = 'receivable';

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
            self::Payable => 'Utang saya',
            self::Receivable => 'Piutang',
        };
    }

    public function isPayable(): bool
    {
        return $this === self::Payable;
    }
}
