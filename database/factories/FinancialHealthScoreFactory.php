<?php

namespace Database\Factories;

use App\Enums\FinancialHealthLabel;
use App\Models\FinancialHealthScore;
use App\Models\Workspace;
use App\Support\MonthPeriod;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FinancialHealthScore>
 */
class FinancialHealthScoreFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'month' => MonthPeriod::from(now()->format('Y-m'))->toDateString(),
            'savings_rate' => '20.00',
            'dti' => '25.00',
            'emergency_fund_months' => '4.00',
            'score' => 80,
            'label' => FinancialHealthLabel::fromScore(80),
            'recommendations' => [],
            'generated_at' => now(),
        ];
    }

    public function forWorkspace(Workspace $workspace): static
    {
        return $this->state(fn (): array => [
            'workspace_id' => $workspace->id,
        ]);
    }

    public function forMonth(string $month): static
    {
        return $this->state(fn (): array => [
            'month' => MonthPeriod::from($month)->toDateString(),
        ]);
    }

    /**
     * Set skor; label ikut dihitung dari ambang yang sama supaya tidak mungkin
     * tersimpan tidak konsisten.
     */
    public function scoring(int $score): static
    {
        return $this->state(fn (): array => [
            'score' => $score,
            'label' => FinancialHealthLabel::fromScore($score),
        ]);
    }

    /**
     * @param  array<int, array{metric: string, message: string}>  $recommendations
     */
    public function recommending(array $recommendations): static
    {
        return $this->state(fn (): array => [
            'recommendations' => $recommendations,
        ]);
    }
}
