<?php

namespace Database\Factories;

use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Models\Account;
use App\Models\Transaction;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Transaction>
 */
class TransactionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'account_id' => Account::factory(),
            'transfer_to_account_id' => null,
            'category_id' => null,
            'recurring_rule_id' => null,
            'type' => TransactionType::Expense->value,
            'amount' => '10000.00',
            'note' => $this->faker->sentence(4),
            'occurred_at' => now(),
            'status' => TransactionStatus::Posted->value,
            'created_by' => null,
            'updated_by' => null,
        ];
    }

    public function forWorkspace(Workspace $workspace): static
    {
        return $this->state(fn (): array => [
            'workspace_id' => $workspace->id,
        ]);
    }

    public function from(Account $account): static
    {
        return $this->state(fn (): array => [
            'workspace_id' => $account->workspace_id,
            'account_id' => $account->id,
        ]);
    }

    public function income(string $amount = '500000.00', ?string $note = null): static
    {
        return $this->state(fn (): array => [
            'type' => TransactionType::Income->value,
            'amount' => $amount,
            'note' => $note ?? $this->faker->sentence(4),
        ]);
    }

    public function expense(string $amount = '50000.00', ?string $note = null): static
    {
        return $this->state(fn (): array => [
            'type' => TransactionType::Expense->value,
            'amount' => $amount,
            'note' => $note ?? $this->faker->sentence(4),
        ]);
    }

    public function transfer(Account $to, string $amount = '250000.00', ?string $note = null): static
    {
        return $this->state(fn (): array => [
            'type' => TransactionType::Transfer->value,
            'amount' => $amount,
            'transfer_to_account_id' => $to->id,
            'note' => $note ?? $this->faker->sentence(4),
        ]);
    }

    public function pending(): static
    {
        return $this->state(fn (): array => [
            'status' => TransactionStatus::Pending->value,
        ]);
    }

    public function on(string $date): static
    {
        return $this->state(fn (): array => [
            'occurred_at' => $date,
        ]);
    }
}
