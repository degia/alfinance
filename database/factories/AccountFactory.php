<?php

namespace Database\Factories;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Account>
 */
class AccountFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = Str::title($this->faker->unique()->word().' '.$this->faker->word());

        return [
            'workspace_id' => Workspace::factory(),
            'type' => $this->faker->randomElement(AccountType::values()),
            'name' => $name,
            'initial_balance' => '0.00',
            'cached_balance' => '0.00',
            'credit_limit' => null,
            'billing_day' => null,
            'due_day' => null,
            'notes' => null,
            'archived_at' => null,
        ];
    }

    public function forWorkspace(Workspace $workspace): static
    {
        return $this->state(fn (): array => [
            'workspace_id' => $workspace->id,
        ]);
    }

    public function cash(string $name = 'Dompet Tunai', string $balance = '0.00'): static
    {
        return $this->state(fn (): array => [
            'type' => AccountType::Cash->value,
            'name' => $name,
            'initial_balance' => $balance,
            'cached_balance' => $balance,
        ]);
    }

    public function bank(string $name = 'Rekening Bank', string $balance = '0.00'): static
    {
        return $this->state(fn (): array => [
            'type' => AccountType::Bank->value,
            'name' => $name,
            'initial_balance' => $balance,
            'cached_balance' => $balance,
        ]);
    }

    public function ewallet(string $name = 'E-Wallet', string $balance = '0.00'): static
    {
        return $this->state(fn (): array => [
            'type' => AccountType::EWallet->value,
            'name' => $name,
            'initial_balance' => $balance,
            'cached_balance' => $balance,
        ]);
    }

    /**
     * Kartu kredit: limit, hari cetak tagihan, dan hari jatuh tempo terisi.
     */
    public function saving(string $name = 'Tabungan', string $balance = '0.00'): static
    {
        return $this->state(fn (): array => [
            'type' => AccountType::Saving->value,
            'name' => $name,
            'initial_balance' => $balance,
            'cached_balance' => $balance,
        ]);
    }

    public function creditCard(
        string $name = 'Kartu Kredit',
        string $limit = '10000000.00',
        int $billingDay = 5,
        int $dueDay = 25,
    ): static {
        return $this->state(fn (): array => [
            'type' => AccountType::CreditCard->value,
            'name' => $name,
            'initial_balance' => '0.00',
            'cached_balance' => '0.00',
            'credit_limit' => $limit,
            'billing_day' => $billingDay,
            'due_day' => $dueDay,
        ]);
    }

    public function archived(): static
    {
        return $this->state(fn (): array => [
            'archived_at' => now(),
        ]);
    }
}
