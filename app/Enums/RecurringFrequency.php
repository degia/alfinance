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
     * Tanggal dinormalisasi ke awal hari supaya perbandingan dengan
     * `next_run_at` tidak meleset karena komponen jam.
     *
     * `$anchorDay` adalah tanggal dalam bulan yang diminta user saat rule
     * dibuat (mis. 31). Tanpa itu, bulan yang lebih pendek akan menggeser
     * jadwal secara permanen: 31 Jan -> 28 Feb -> 28 Mar, sehingga tagihan
     * tanggal 31 diam-diam jadi tanggal 28 selamanya. Dengan anchor, hanya
     * occurrence bulan pendek yang di-clamp, lalu bulan berikutnya kembali ke
     * tanggal yang sama.
     *
     * Nilai null berarti "belum ada anchor" dan dipakai memakai tanggal
     * `$from` — perilaku yang sama seperti sebelum anchor diperhitungkan.
     */
    public function nextOccurrence(CarbonImmutable $from, ?int $anchorDay = null): CarbonImmutable
    {
        $start = $from->startOfDay();

        return match ($this) {
            self::Daily => $start->addDay(),
            self::Weekly => $start->addWeek(),
            // No-overflow: 31 Jan -> 28/29 Feb, bukan 2/3 Mar.
            self::Monthly => $this->inMonth($start->addMonthsNoOverflow(1), $anchorDay ?? $start->day),
            self::Yearly => $this->nextYearly($start, $anchorDay),
        };
    }

    /**
     * Tanggal `$day` di bulan tujuan, di-clamp ke hari terakhir kalau bulan itu
     * lebih pendek (tanggal 30 di Februari menjadi 28/29).
     */
    private function inMonth(CarbonImmutable $month, int $day): CarbonImmutable
    {
        $first = $month->startOfMonth();

        return $first->day(min($day, $first->daysInMonth));
    }

    /**
     * Occurrence tahunan. 29 Februari adalah kasus khusus: tanggal itu hanya
     * ada di tahun kabisat, jadi lompat ke tahun kabisat berikutnya, bukan
     * turun permanen ke 28 Februari.
     */
    private function nextYearly(CarbonImmutable $from, ?int $anchorDay): CarbonImmutable
    {
        $day = $anchorDay ?? $from->day;
        $year = $from->year + 1;

        if ($from->month === 2 && $day === 29) {
            while (! checkdate(2, 29, $year)) {
                $year++;
            }

            return CarbonImmutable::create($year, 2, 29)->startOfDay();
        }

        return $this->inMonth(CarbonImmutable::create($year, $from->month, 1), $day);
    }
}
