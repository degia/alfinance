<?php

namespace Database\Factories;

use App\Enums\DebtDirection;
use App\Enums\DebtStatus;
use App\Models\Account;
use App\Models\Debt;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Debt>
 */
class DebtFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'account_id' => null,
            'direction' => DebtDirection::Payable,
            'counterparty' => $this->faker->randomElement(['Koperasi', 'Bank BCA', 'Tempo', 'Kak Rina']),
            'principal' => '12000000.00',
            'remaining' => '12000000.00',
            'interest_rate' => '0.00',
            'start_date' => now()->startOfMonth()->toDateString(),
            'due_date' => now()->addMonthsNoOverflow(2)->endOfMonth()->toDateString(),
            'term_count' => 12,
            'status' => DebtStatus::Ongoing,
            'include_in_net_worth' => true,
            'note' => null,
        ];
    }

    public function forWorkspace(Workspace $workspace): static
    {
        return $this->state(fn (): array => [
            'workspace_id' => $workspace->id,
        ]);
    }

    public function payable(): static
    {
        return $this->state(fn (): array => ['direction' => DebtDirection::Payable]);
    }

    public function receivable(): static
    {
        return $this->state(fn (): array => ['direction' => DebtDirection::Receivable]);
    }

    public function fromAccount(Account $account): static
    {
        return $this->state(fn (): array => [
            'account_id' => $account->id,
            'workspace_id' => $account->workspace_id,
        ]);
    }

    public function principal(string $amount): static
    {
        return $this->state(fn (): array => [
            'principal' => $amount,
            'remaining' => $amount,
        ]);
    }

    public function remaining(string $amount): static
    {
        return $this->state(fn (): array => ['remaining' => $amount]);
    }

    public function dueOn(string $date): static
    {
        return $this->state(fn (): array => ['due_date' => $date]);
    }

    public function status(DebtStatus $status): static
    {
        return $this->state(fn (): array => ['status' => $status]);
    }

    public function terms(int $count): static
    {
        return $this->state(fn (): array => ['term_count' => $count]);
    }

    public function excludedFromNetWorth(): static
    {
        return $this->state(fn (): array => ['include_in_net_worth' => false]);
    }
}
