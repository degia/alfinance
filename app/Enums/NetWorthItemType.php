<?php

namespace App\Enums;

/**
 * Sisi item net worth (PRD.md §3.6): aset atau kewajiban.
 *
 * `asset` menambah net worth, `liability` menguranginya. Item manual
 * disimpan terpisah dari kewajiban otomatis (saldo kartu kredit) supaya
 * angkanya tidak pernah terhitung dua kali.
 */
enum NetWorthItemType: string
{
    case Asset = 'asset';
    case Liability = 'liability';

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
            self::Asset => 'Aset',
            self::Liability => 'Kewajiban',
        };
    }

    public function sign(): int
    {
        return $this === self::Asset ? 1 : -1;
    }
}
