<?php

namespace App\Enums;

use Carbon\CarbonImmutable;

/**
 * Frekuensi transaksi berulang (PRD.md §3.3).
 *
 * Perhitungan kemunculan berikutnya memakai `addMonthsNoOverflow()` sehingga
 * transaksi tanggal 31 tidak lompat ke bulan berikutnya, dan tanggal 30 tidak
 * melompati Februari — kasus tepi yang sering jadi bug di repeater.
 */
enum RecurringFrequency: string
{
    case Daily = 'daily';
    case Weekly = 'weekly';
    case Monthly = 'monthly';
    case Yearly = 'yearly';

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
            self::Daily => 'Harian',
            self::Weekly => 'Mingguan',
            self::Monthly => 'Bulanan',
            self::Yearly => 'Tahunan',
        };
    }

    /**
     * Kunci route/parameter untuk interval freqensi ini.
     */
    public function interval(): string
    {
        return match ($this) {
            self::Daily => '1 hari',
            self::Weekly => '1 minggu',
            self::Monthly => '1 bulan',
            self::Yearly => '1 tahun',
        };
    }

    /**
     * Koccurensi berikutnya setelah tanggal `$from`.
     *
     * Tanggal Always di-normalisasi ke awal hari supaya perbandingan
     * dengan `next_run_at` tidak meleset karena komponen jam.
     */
    public function nextOccurrence(CarbonImmutable $from): CarbonImmutable
    {
        $start = $from->startOfDay();

        return match ($this) {
            self::Daily => $start->addDay(),
            self::Weekly => $start->addWeek(),
            // No-overflow: 31 Jan -> 28/29 Feb, bukan 2/3 Mar.
            self::Monthly => $start->addMonthsNoOverflow(1),
            self::Yearly => $start->addYearsNoOverflow(1),
        };
    }
}
