<?php

namespace Database\Factories;

use App\Enums\RecurringFrequency;
use App\Enums\TransactionType;
use App\Models\Account;
use App\Models\RecurringRule;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RecurringRule>
 */
class RecurringRuleFactory extends Factory
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
            'type' => TransactionType::Expense->value,
            'amount' => '150000.00',
            'tag_ids' => null,
            'note' => $this->faker->sentence(4),
            'frequency' => RecurringFrequency::Monthly->value,
            'next_run_at' => now()->addDay(),
            'requires_confirmation' => true,
            'is_active' => true,
            'end_date' => null,
            'last_generated_at' => null,
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

    public function frequency(RecurringFrequency $frequency): static
    {
        return $this->state(fn (): array => [
            'frequency' => $frequency->value,
        ]);
    }

    /**
     * Rule yang butuh konfirmasi sebelum posting final.
     */
    public function needsConfirmation(bool $requires = true): static
    {
        return $this->state(fn (): array => [
            'requires_confirmation' => $requires,
        ]);
    }

    public function nextRunAt(string $moment): static
    {
        return $this->state(fn (): array => [
            'next_run_at' => $moment,
        ]);
    }

    public function ended(): static
    {
        return $this->state(fn (): array => [
            'is_active' => false,
            'end_date' => now()->subDay(),
        ]);
    }
}
