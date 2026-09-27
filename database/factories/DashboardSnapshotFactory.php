<?php

namespace Database\Factories;

use App\Models\DashboardSnapshot;
use App\Models\Workspace;
use App\Support\Money;
use App\Support\MonthPeriod;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DashboardSnapshot>
 */
class DashboardSnapshotFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'month' => MonthPeriod::from(now()->format('Y-m'))->toDateString(),
            'total_income' => '7500000.00',
            'total_expense' => '5000000.00',
            'total_transfer' => '1000000.00',
            'net_cash_flow' => '2500000.00',
            'transaction_count' => 42,
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
     * Set income & expense; `net_cash_flow` dihitung dari keduanya kecuali
     * diberikan eksplisit (mis. oleh skenario yang memang defisit).
     */
    public function totals(string $income, string $expense, ?string $netCashFlow = null): static
    {
        return $this->state(fn (): array => [
            'total_income' => $income,
            'total_expense' => $expense,
            'net_cash_flow' => $netCashFlow ?? Money::subtract($income, $expense),
        ]);
    }

    public function generatedAt(?\Carbon\CarbonImmutable $at = null): static
    {
        return $this->state(fn (): array => [
            'generated_at' => $at ?? now(),
        ]);
    }
}
