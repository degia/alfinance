<?php

namespace App\Enums;

/**
 * Label skor kesehatan finansial (PRD.md §3.9).
 *
 * Label diturunkan dari skor 0–100 oleh {@see self::fromScore()} supaya
 *_threshold_-nya hanya ada di satu tempat: penyimpanan, tampilan, dan
 * rekomendasi tidak mungkin jatuh disagreed karena beda tabel ambang.
 */
enum FinancialHealthLabel: string
{
    case NeedsAttention = 'needs_attention';
    case Fair = 'fair';
    case Healthy = 'healthy';
    case VeryHealthy = 'very_healthy';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Ambang skor minimum per label (PRD.md §3.9).
     */
    public static function fromScore(int $score): self
    {
        return match (true) {
            $score >= 80 => self::VeryHealthy,
            $score >= 60 => self::Healthy,
            $score >= 40 => self::Fair,
            default => self::NeedsAttention,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::NeedsAttention => 'Perlu Perhatian',
            self::Fair => 'Cukup',
            self::Healthy => 'Sehat',
            self::VeryHealthy => 'Sangat Sehat',
        };
    }

    /**
     * Warna badge (design token Tailwind, bukan hex hardcode).
     */
    public function tone(): string
    {
        return match ($this) {
            self::NeedsAttention => 'bg-expense',
            self::Fair => 'bg-warning',
            self::Healthy => 'bg-primary',
            self::VeryHealthy => 'bg-accent',
        };
    }
}
