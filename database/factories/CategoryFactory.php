<?php

namespace Database\Factories;

use App\Enums\CategoryIcon;
use App\Models\Category;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Category>
 */
class CategoryFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'parent_id' => null,
            'name' => $this->faker->unique()->word(),
            'icon' => $this->faker->randomElement(CategoryIcon::values()),
            'color' => $this->faker->randomElement([
                '#2563eb',
                '#7c3aed',
                '#059669',
                '#d97706',
                '#dc2626',
            ]),
        ];
    }

    public function forWorkspace(Workspace $workspace): static
    {
        return $this->state(fn (): array => [
            'workspace_id' => $workspace->id,
        ]);
    }

    /**
     * Sub-kategori dari kategori induk.
     */
    public function childOf(Category $parent): static
    {
        return $this->state(fn (): array => [
            'workspace_id' => $parent->workspace_id,
            'parent_id' => $parent->id,
        ]);
    }
}
