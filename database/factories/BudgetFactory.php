<?php

namespace Database\Factories;

use App\Models\Budget;
use App\Models\Category;
use App\Models\Workspace;
use App\Support\MonthPeriod;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Budget>
 */
class BudgetFactory extends Factory
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
            'limit_amount' => $this->faker->randomElement(['500000.00', '1000000.00', '1500000.00']),
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

    /**
     * Bulan budgetary "YYYY-MM" (mis. "2026-09") dinormalkan ke tanggal awal.
     */
    public function forMonth(string $month): static
    {
        return $this->state(fn (): array => [
            'month' => MonthPeriod::from($month)->toDateString(),
        ]);
    }

    public function limit(string $amount): static
    {
        return $this->state(fn (): array => [
            'limit_amount' => $amount,
        ]);
    }

    public function named(string $categoryName, string $month, string $limit = '1000000.00'): static
    {
        return $this->state(function () use ($categoryName, $month, $limit): array {
            $category = Category::query()->where('name', $categoryName)->first()
                ?? Category::factory()->create(['name' => Str::title($categoryName)]);

            return [
                'category_id' => $category->id,
                'workspace_id' => $category->workspace_id,
                'month' => MonthPeriod::from($month)->toDateString(),
                'limit_amount' => $limit,
            ];
        });
    }
}
