<?php

namespace App\Enums;

/**
 * Status utang/piutang (PRD.md §3.7): Berjalan / Lunas / Terlambat.
 *
 * Status ini berasal dari dua data saja — sisa utang (`remaining`) dan
 * tanggal jatuh tempo (`due_date`) — sehingga tidak bisa "lupa" diperbarui
 * seperti field yang harus diubah manual.
 */
enum DebtStatus: string
{
    case Ongoing = 'ongoing';
    case Settled = 'settled';
    case Overdue = 'overdue';

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
            self::Ongoing => 'Berjalan',
            self::Settled => 'Lunas',
            self::Overdue => 'Terlambat',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Ongoing => 'bg-primary',
            self::Settled => 'bg-income',
            self::Overdue => 'bg-expense',
        };
    }
}
