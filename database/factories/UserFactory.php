<?php

namespace Database\Factories;

use App\Enums\UserRole;
use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'role' => UserRole::User,
            'remember_token' => Str::random(10),
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * Admin global — punya akses ke panel admin.
     */
    public function admin(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => UserRole::Admin,
        ]);
    }

    /**
     * Akun yang sedang ditangguhkan admin.
     */
    public function suspended(?string $reason = null): static
    {
        return $this->state(fn (array $attributes) => [
            'suspended_at' => now(),
            'suspended_reason' => $reason,
        ]);
    }

    /**
     * Akun yang baru saja aktif, sehingga terhitung "online".
     */
    public function online(): static
    {
        return $this->state(fn (array $attributes) => [
            'last_seen_at' => now(),
            'last_login_at' => now()->subMinutes(2),
            'last_login_ip' => '127.0.0.1',
        ]);
    }

    /**
     * Indicate that the model has two-factor authentication configured.
     */
    public function withTwoFactor(): static
    {
        return $this->state(fn (array $attributes) => [
            'two_factor_secret' => encrypt('secret'),
            'two_factor_recovery_codes' => encrypt(json_encode(['recovery-code-1'])),
            'two_factor_confirmed_at' => now(),
        ]);
    }

    /**
     * Sertakan satu workspace yang dimiliki user ini.
     *
     * Sengaja opt-in: user tanpa workspace harus tetap bisa diuji agar guard
     * `workspace.selected` dan tenant isolation ikut terverifikasi.
     */
    public function withWorkspace(
        ?string $name = null,
        WorkspaceRole $role = WorkspaceRole::Owner,
    ): static {
        return $this->afterCreating(function (User $user) use ($name, $role): void {
            $workspace = Workspace::factory()->create([
                'name' => $name ?? 'Buku Utama',
                'owner_id' => $user->getKey(),
            ]);

            $workspace->addUser($user, $role);
        });
    }
}
