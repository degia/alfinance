<?php

namespace Database\Factories;

use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Workspace>
 */
class WorkspaceFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = Str::title($this->faker->unique()->word().' '.$this->faker->word()).' Workspace';

        return [
            'name' => $name,
            'owner_id' => User::factory(),
        ];
    }

    /**
     * Workspace dengan owner beserta anggota-anggotanya.
     *
     * @param  array<int, array{user: User, role: WorkspaceRole}>  $members
     */
    public function withMembers(array $members): static
    {
        return $this->afterCreating(function (Workspace $workspace) use ($members): void {
            foreach ($members as $member) {
                $workspace->addUser($member['user'], $member['role']);
            }
        });
    }
}
