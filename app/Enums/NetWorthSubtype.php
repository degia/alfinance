<?php

namespace App\Enums;

/**
 * Jenis item net worth (PRD.md §3.6).
 *
 * Setiap subtype sudah menentukan sisi-nya (aset atau kewajiban), jadi form
 * tidak bisa menyimpan kombinasi yang tidak masuk akal seperti
 * "properti" sebagai kewajiban. Nilai dibuat berbeda per sisi supaya
 * `Other` pun tidak ambigu.
 */
enum NetWorthSubtype: string
{
    // Aset
    case Investment = 'investment';
    case Property = 'property';
    case Vehicle = 'vehicle';
    case Cash = 'cash';
    case OtherAsset = 'other_asset';

    // Kewajiban
    case Mortgage = 'mortgage';
    case VehicleLoan = 'vehicle_loan';
    case PersonalLoan = 'personal_loan';
    case OtherLiability = 'other_liability';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function type(): NetWorthItemType
    {
        return match ($this) {
            self::Investment, self::Property, self::Vehicle, self::Cash, self::OtherAsset => NetWorthItemType::Asset,
            self::Mortgage, self::VehicleLoan, self::PersonalLoan, self::OtherLiability => NetWorthItemType::Liability,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Investment => 'Investasi',
            self::Property => 'Properti',
            self::Vehicle => 'Kendaraan',
            self::Cash => 'Kas / Buku Besar',
            self::OtherAsset => 'Aset lainnya',
            self::Mortgage => 'KPR',
            self::VehicleLoan => 'Kredit kendaraan',
            self::PersonalLoan => 'Pinjaman pribadi',
            self::OtherLiability => 'Kewajiban lainnya',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Investment => 'trending-up',
            self::Property => 'house',
            self::Vehicle => 'car',
            self::Cash => 'wallet',
            self::OtherAsset => 'shapes',
            self::Mortgage, self::VehicleLoan, self::PersonalLoan, self::OtherLiability => 'landmark',
        };
    }

    /**
     * @return array<int, self>
     */
    public static function forType(NetWorthItemType $type): array
    {
        return array_values(array_filter(
            self::cases(),
            static fn (self $subtype): bool => $subtype->type() === $type,
        ));
    }
}
