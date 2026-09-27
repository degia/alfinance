<?php

namespace Database\Factories;

use App\Models\NetWorthSnapshot;
use App\Models\Workspace;
use App\Support\Money;
use App\Support\MonthPeriod;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NetWorthSnapshot>
 */
class NetWorthSnapshotFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'month' => MonthPeriod::from(now()->format('Y-m'))->toDateString(),
            'total_assets' => '25000000.00',
            'total_liabilities' => '5000000.00',
            'net_worth' => '20000000.00',
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
     * Set tiga angka sekaligus; `net_worth`default dihitung dari assets -
     * liabilities kalau tidak diberikan.
     */
    public function totals(string $assets, string $liabilities, ?string $netWorth = null): static
    {
        return $this->state(fn (): array => [
            'total_assets' => $assets,
            'total_liabilities' => $liabilities,
            'net_worth' => $netWorth ?? Money::subtract($assets, $liabilities),
        ]);
    }
}
