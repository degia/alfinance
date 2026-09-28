<?php

namespace App\Enums;

/**
 * Scope backup (PRD.md §3.11): seluruh data workspace atau hanya rentang
 * transaksi/bulan.
 */
enum BackupScope: string
{
    case Full = 'full';
    case Range = 'range';

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
            self::Full => 'Penuh',
            self::Range => 'Per rentang',
        };
    }

    public function needsRange(): bool
    {
        return $this === self::Range;
    }
}
