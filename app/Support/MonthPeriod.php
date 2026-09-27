<?php

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * Periode bulanan untuk anggaran dan snapshot (Fase 4).
 *
 * Nilai `month` di database selalu berupa tanggal PERTAMA bulan
 * (2026-09-01) supaya perbandingan antar bulan tetap rentang tanggal yang
 * bisa memakai index. Input form boleh "2026-09" maupun "2026-09-01";
 * keduanya dinormalkan ke sini.
 */
final class MonthPeriod
{
    /**
     * Regex "YYYY-MM" dengan bulan 01–12.
     */
    private const PATTERN = '/^\d{4}-(0[1-9]|1[0-2])$/';

    /**
     * Regex "YYYY-MM-DD" dengan bulan 01–12.
     */
    private const PATTERN_DATE = '/^\d{4}-(0[1-9]|1[0-2])-\d{2}$/';

    /**
     * Normalkan input "YYYY-MM" atau "YYYY-MM-DD" menjadi tanggal pertama bulan.
     *
     * Fungsi ini idempoten: menerima nilai yang sudah dinormalkan, jadi
     * FormRequest boleh memberikan tanggal pertama bulan ke service.
     *
     * @throws \InvalidArgumentException bila formatnya bukan "YYYY-MM"/"YYYY-MM-DD".
     */
    public static function from(string $month): CarbonImmutable
    {
        $value = trim($month);

        if (preg_match(self::PATTERN, $value) === 1) {
            return CarbonImmutable::createFromFormat('Y-m-d', $value.'-01')->startOfMonth();
        }

        if (preg_match(self::PATTERN_DATE, $value) === 1) {
            return CarbonImmutable::createFromFormat('Y-m-d', $value)->startOfMonth();
        }

        throw new \InvalidArgumentException("Periode bulan tidak valid: {$month}");
    }

    /**
     * Kunci teks "YYYY-MM" untuk payload Inertia & pencarian.
     */
    public static function key(CarbonImmutable $month): string
    {
        return $month->format('Y-m');
    }

    public static function isValid(string $value): bool
    {
        $value = trim($value);

        return preg_match(self::PATTERN, $value) === 1
            || preg_match(self::PATTERN_DATE, $value) === 1;
    }

    /**
     * 12 bulan pertama dalam satu tahun, kronologis.
     *
     * @return array<int, CarbonImmutable>
     */
    public static function monthsOfYear(int $year): array
    {
        $months = [];
        $month = CarbonImmutable::createFromFormat('Y-m-d', $year.'-01-01')->startOfMonth();

        for ($index = 0; $index < 12; $index++) {
            $months[] = $month;
            $month = $month->addMonthNoOverflow();
        }

        return $months;
    }

    /**
     * Rentang [awal, akhir bulan] untuk query `occurred_at`.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public static function bounds(CarbonImmutable $month): array
    {
        return [$month->startOfMonth(), $month->endOfMonth()];
    }

    /**
     * Label "Sep 2026" untuk UI (locale id-ID).
     */
    public static function label(string $month): string
    {
        if (! self::isValid($month)) {
            return $month;
        }

        return self::from($month)->translatedFormat('M Y');
    }

    /**
     * Nama bulan pendek ("Sep") untuk header matriks.
     */
    public static function shortLabel(string $month): string
    {
        if (! self::isValid($month)) {
            return $month;
        }

        return self::from($month)->translatedFormat('M');
    }
}
