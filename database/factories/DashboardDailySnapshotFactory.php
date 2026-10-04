<?php

namespace Database\Factories;

use App\Models\DashboardDailySnapshot;
use App\Models\Workspace;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DashboardDailySnapshot>
 */
class DashboardDailySnapshotFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'date' => now()->startOfDay()->toDateString(),
            'total_income' => '250000.00',
            'total_expense' => '125000.00',
            'net_cash_flow' => '125000.00',
            'transaction_count' => 3,
            'generated_at' => now(),
        ];
    }

    public function forWorkspace(Workspace $workspace): static
    {
        return $this->state(fn (): array => [
            'workspace_id' => $workspace->id,
        ]);
    }

    public function on(string $date): static
    {
        return $this->state(fn (): array => [
            'date' => CarbonImmutable::parse($date)->toDateString(),
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
}
