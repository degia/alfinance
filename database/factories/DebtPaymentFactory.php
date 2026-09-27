<?php

namespace Database\Factories;

use App\Models\Debt;
use App\Models\DebtPayment;
use App\Models\Transaction;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DebtPayment>
 */
class DebtPaymentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'debt_id' => Debt::factory(),
            'transaction_id' => null,
            'amount' => '1000000.00',
            'paid_at' => now()->toDateString(),
            'note' => null,
        ];
    }

    public function forWorkspace(Workspace $workspace): static
    {
        return $this->state(fn (): array => [
            'workspace_id' => $workspace->id,
        ]);
    }

    public function forDebt(Debt $debt): static
    {
        return $this->state(fn (): array => [
            'workspace_id' => $debt->workspace_id,
            'debt_id' => $debt->id,
        ]);
    }

    public function amount(string $amount): static
    {
        return $this->state(fn (): array => ['amount' => $amount]);
    }

    public function paidOn(string $date): static
    {
        return $this->state(fn (): array => ['paid_at' => $date]);
    }

    public function withTransaction(Transaction $transaction): static
    {
        return $this->state(fn (): array => [
            'workspace_id' => $transaction->workspace_id,
            'transaction_id' => $transaction->id,
        ]);
    }
}
