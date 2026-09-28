<?php

namespace App\Models;

use App\Enums\FinancialHealthLabel;
use App\Jobs\RecomputeFinancialHealthJob;
use App\Support\MonthPeriod;
use Carbon\CarbonImmutable;
use Database\Factories\FinancialHealthScoreFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Skor kesehatan finansial bulanan (PRD.md §3.9).
 *
 * Ditulis {@see RecomputeFinancialHealthJob} dari snapshot bulanan
 * lalu dibaca lewat cache Redis. Satu baris per workspace per bulan, jadi skor
 * bulan yang sudah lewat tidak ikut berubah ketika transaksi bulan ini masih
 * berjalan.
 *
 * Tiga metrik komponennya:
 * - `savings_rate` — (income − expense) / income, ideal ≥ 20%.
 * - `dti` — total cicilan bulanan / income, ideal ≤ 30%.
 * - `emergency_fund_months` — kas likuid / rata-rata expense, ideal 3–6 bulan.
 *
 * @property int $id
 * @property int $workspace_id
 * @property CarbonImmutable $month
 * @property string|null $savings_rate
 * @property string|null $dti
 * @property string|null $emergency_fund_months
 * @property int $score
 * @property FinancialHealthLabel $label
 * @property array<int, array{metric: string, message: string}> $recommendations
 * @property CarbonImmutable|null $generated_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
#[Fillable([
    'month',
    'savings_rate',
    'dti',
    'emergency_fund_months',
    'score',
    'label',
    'recommendations',
    'generated_at',
])]
class FinancialHealthScore extends WorkspaceScopedModel
{
    /** @use HasFactory<FinancialHealthScoreFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'month' => 'date',
            'savings_rate' => 'decimal:2',
            'dti' => 'decimal:2',
            'emergency_fund_months' => 'decimal:2',
            'score' => 'integer',
            'label' => FinancialHealthLabel::class,
            'recommendations' => 'array',
            'generated_at' => 'datetime',
        ];
    }

    /**
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeOfMonth(Builder $query, CarbonImmutable $month): Builder
    {
        return $query->whereYear('month', $month->year)->whereMonth('month', $month->month);
    }

    public function monthKey(): string
    {
        return MonthPeriod::key(CarbonImmutable::parse($this->month));
    }

    /**
     * Skor 0–100 sudah pasti; yang perlu dicek hanya apakah ada metrik yang
     * belum bisa dihitung (mis. belum ada income bulan ini).
     */
    public function isComplete(): bool
    {
        return $this->savings_rate !== null
            && $this->dti !== null
            && $this->emergency_fund_months !== null;
    }
}
