<?php

namespace Database\Factories;

use App\Models\HitunganPlan;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<HitunganPlan>
 */
class HitunganPlanFactory extends Factory
{
    protected $model = HitunganPlan::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'daily_limit' => '0.00',
            'end_date' => null,
            'rows' => [],
        ];
    }

    public function forWorkspace(Workspace $workspace): static
    {
        return $this->state(fn (): array => [
            'workspace_id' => $workspace->id,
        ]);
    }
}
