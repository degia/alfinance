<?php

namespace App\Enums;

/**
 * Status kesehatan anggaran per kategori per bulan (PRD.md §3.5).
 *
 * Ambang batasnya persis seperti di PRD: hijau di bawah 80%, kuning pada
 * 80–100%, merah di atas 100%. `Unset` dipakai untuk sel yang belum punya
 * limit, sehingga UI tidak salah menampilkan progress bar.
 *
 * Status ini TIDAK disimpan di database: ia diturunkan dari
 * `budget_progress_cache.used_amount` terhadap `budgets.limit_amount` saat
 * baca, jadi tidak pernah basi.
 */
enum BudgetStatus: string
{
    case Unset = 'unset';
    case Healthy = 'healthy';
    case Warning = 'warning';
    case Over = 'over';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Turunkan status dari persentase pemakaian. Persentase null berarti belum
     * ada limit, dan negatif berarti belum ada pemakaian.
     */
    public static function fromPercent(?float $percent): self
    {
        return match (true) {
            $percent === null => self::Unset,
            $percent > 100 => self::Over,
            $percent >= 80 => self::Warning,
            default => self::Healthy,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Unset => 'Belum ada limit',
            self::Healthy => 'Aman',
            self::Warning => 'Waspada',
            self::Over => 'Lewat',
        };
    }

    /**
     * Kelas warna Tailwind untuk progress bar (design token, bukan hex hardcode).
     */
    public function tone(): string
    {
        return match ($this) {
            self::Unset => 'bg-muted',
            self::Healthy => 'bg-income',
            self::Warning => 'bg-warning',
            self::Over => 'bg-expense',
        };
    }
}
