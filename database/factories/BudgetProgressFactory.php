<?php

namespace Database\Factories;

use App\Models\BudgetProgress;
use App\Models\Category;
use App\Models\Workspace;
use App\Support\MonthPeriod;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BudgetProgress>
 */
class BudgetProgressFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'category_id' => Category::factory(),
            'month' => MonthPeriod::from(now()->format('Y-m'))->toDateString(),
            'used_amount' => '0.00',
        ];
    }

    public function forWorkspace(Workspace $workspace): static
    {
        return $this->state(fn (): array => [
            'workspace_id' => $workspace->id,
        ]);
    }

    public function forCategory(Category $category): static
    {
        return $this->state(fn (): array => [
            'workspace_id' => $category->workspace_id,
            'category_id' => $category->id,
        ]);
    }

    public function forMonth(string $month): static
    {
        return $this->state(fn (): array => [
            'month' => MonthPeriod::from($month)->toDateString(),
        ]);
    }

    public function used(string $amount): static
    {
        return $this->state(fn (): array => [
            'used_amount' => $amount,
        ]);
    }
}
