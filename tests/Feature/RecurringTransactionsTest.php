<?php

namespace Tests\Feature;

use App\Enums\RecurringFrequency;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Enums\WorkspaceRole;
use App\Jobs\RecurringTransactionJob;
use App\Models\Account;
use App\Models\Category;
use App\Models\RecurringRule;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Transactions\TransactionManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Transaksi berulang: rule adalah template, job menghasilkan instance, dan
 * instance yang butuh konfirmasi tidak boleh menyentuh saldo sebelum
 * disetujui user.
 */
class RecurringTransactionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_rule_is_created_without_touching_any_balance(): void
    {
        $user = $this->signedIn();
        $workspace = $user->workspaces()->sole();

        $account = Account::factory()->forWorkspace($workspace)->cash('Dompet', '500000.00')->create();
        $category = Category::factory()->forWorkspace($workspace)->create();

        $this->post(route('recurring-rules.store'), [
            'account_id' => $account->id,
            'category_id' => $category->id,
            'type' => TransactionType::Expense->value,
            'amount' => '150000.00',
            'note' => 'Sewa bulanan',
            'frequency' => RecurringFrequency::Monthly->value,
            'next_run_at' => '2026-10-01',
            'requires_confirmation' => '1',
        ])->assertRedirect(route('recurring-rules.index'));

        $rule = RecurringRule::allWorkspaces()->sole();

        $this->assertSame(RecurringFrequency::Monthly, $rule->frequency);
        $this->assertTrue($rule->is_active);
        $this->assertTrue($rule->requires_confirmation);
        $this->assertSame($workspace->id, $rule->workspace_id);

        // Rule bukan transaksi: saldo harus tetap sama.
        $this->assertSame('500000.00', $account->fresh()->cached_balance);
        $this->assertSame(0, Transaction::allWorkspaces()->count());
    }

    public function test_a_due_rule_generates_a_pending_instance_that_does_not_move_the_balance(): void
    {
        $user = $this->signedIn();
        $workspace = $user->workspaces()->sole();

        $account = Account::factory()->forWorkspace($workspace)->cash('Dompet', '500000.00')->create();

        $rule = RecurringRule::factory()
            ->from($account)
            ->frequency(RecurringFrequency::Monthly)
            ->needsConfirmation()
            ->nextRunAt('2026-09-27 00:10:00')
            ->create();

        $this->travelTo(now()->parse('2026-09-27 00:15:00'));

        (new RecurringTransactionJob)->handle(app(TransactionManager::class));

        $this->travelBack();

        $instance = Transaction::allWorkspaces()->sole();

        $this->assertSame(TransactionStatus::Pending, $instance->status);
        $this->assertSame($rule->id, $instance->recurring_rule_id);
        $this->assertSame('2026-09-27', $instance->occurred_at->toDateString());

        // Pending = belum menyentuh saldo.
        $this->assertSame('500000.00', $account->fresh()->cached_balance);

        // Jadwal sudah bergeser ke bulan berikutnya.
        $this->assertSame('2026-10-27', $rule->fresh()->next_run_at->toDateString());
    }

    public function test_a_rule_without_confirmation_posts_immediately(): void
    {
        $user = $this->signedIn();
        $workspace = $user->workspaces()->sole();

        $account = Account::factory()->forWorkspace($workspace)->cash('Dompet', '500000.00')->create();

        RecurringRule::factory()
            ->from($account)
            ->frequency(RecurringFrequency::Daily)
            ->needsConfirmation(false)
            ->nextRunAt('2026-09-27 00:00:00')
            ->create();

        $this->travelTo(now()->parse('2026-09-27 00:15:00'));

        (new RecurringTransactionJob)->handle(app(TransactionManager::class));

        $this->travelBack();

        $instance = Transaction::allWorkspaces()->sole();

        $this->assertSame(TransactionStatus::Posted, $instance->status);
        $this->assertSame('350000.00', $account->fresh()->cached_balance);
    }

    public function test_running_the_job_twice_does_not_duplicate_the_instance(): void
    {
        $user = $this->signedIn();
        $account = Account::factory()->forWorkspace($user->workspaces()->sole())->cash('Dompet', '500000.00')->create();

        RecurringRule::factory()
            ->from($account)
            ->needsConfirmation(false)
            ->nextRunAt('2026-09-27 00:00:00')
            ->create();

        $this->travelTo(now()->parse('2026-09-27 00:15:00'));

        (new RecurringTransactionJob)->handle(app(TransactionManager::class));
        (new RecurringTransactionJob)->handle(app(TransactionManager::class));

        $this->travelBack();

        // Jadwal sudah maju ke 28 Sep, jadi eksekusi kedua tidak apa-apa.
        $this->assertSame(1, Transaction::allWorkspaces()->count());
        $this->assertSame('350000.00', $account->fresh()->cached_balance);
    }

    public function test_confirming_a_pending_instance_posts_it(): void
    {
        $user = $this->signedIn();
        $account = Account::factory()->forWorkspace($user->workspaces()->sole())->cash('Dompet', '500000.00')->create();

        $instance = Transaction::factory()->from($account)->expense('75000.00')->pending()->create();

        $this->assertSame('500000.00', $account->fresh()->cached_balance);

        $this->post(route('transactions.confirm', $instance))
            ->assertRedirect(route('transactions.index'));

        $this->assertSame(TransactionStatus::Posted, $instance->fresh()->status);
        $this->assertSame('425000.00', $account->fresh()->cached_balance);
    }

    public function test_confirming_twice_is_rejected(): void
    {
        $user = $this->signedIn();
        $account = Account::factory()->forWorkspace($user->workspaces()->sole())->cash('Dompet', '500000.00')->create();

        $instance = Transaction::factory()->from($account)->expense('75000.00')->create();

        $this->post(route('transactions.confirm', $instance))->assertStatus(422);

        // Saldo tidak digeser dua kali: instance ini dibuat lewat factory,
        // jadi efek pertamanya memang belum pernah masuk saldo.
        $this->assertSame('500000.00', $account->fresh()->cached_balance);
    }

    public function test_discarding_a_pending_instance_leaves_the_balance_untouched(): void
    {
        $user = $this->signedIn();
        $account = Account::factory()->forWorkspace($user->workspaces()->sole())->cash('Dompet', '500000.00')->create();

        $instance = Transaction::factory()->from($account)->expense('75000.00')->pending()->create();

        $this->post(route('transactions.discard', $instance))
            ->assertRedirect(route('transactions.index'));

        $this->assertSame(0, Transaction::allWorkspaces()->count());
        $this->assertSame('500000.00', $account->fresh()->cached_balance);
    }

    public function test_a_posted_transaction_cannot_be_discarded(): void
    {
        $user = $this->signedIn();
        $account = Account::factory()->forWorkspace($user->workspaces()->sole())->cash('Dompet', '500000.00')->create();

        $instance = Transaction::factory()->from($account)->expense('75000.00')->create();

        $this->post(route('transactions.discard', $instance))->assertStatus(422);

        $this->assertSame(1, Transaction::allWorkspaces()->count());
    }

    public function test_a_rule_stops_after_its_end_date(): void
    {
        $user = $this->signedIn();
        $account = Account::factory()->forWorkspace($user->workspaces()->sole())->cash('Dompet', '500000.00')->create();

        $rule = RecurringRule::factory()
            ->from($account)
            ->needsConfirmation(false)
            ->nextRunAt('2026-09-27 00:00:00')
            ->create(['end_date' => '2026-09-30']);

        $this->travelTo(now()->parse('2026-09-27 00:15:00'));

        (new RecurringTransactionJob)->handle(app(TransactionManager::class));

        $this->travelBack();

        $rule->refresh();

        $this->assertFalse($rule->is_active);
        $this->assertSame(1, Transaction::allWorkspaces()->count());

        // Run berikutnya tidak menghasilkan apa pun karena rule nonaktif.
        $this->travelTo(now()->parse('2026-10-28 00:15:00'));

        (new RecurringTransactionJob)->handle(app(TransactionManager::class));

        $this->travelBack();

        $this->assertSame(1, Transaction::allWorkspaces()->count());
    }

    public function test_monthly_occurrence_clamps_to_the_last_day_of_a_short_month(): void
    {
        $user = $this->signedIn();
        $account = Account::factory()->forWorkspace($user->workspaces()->sole())->cash('Dompet')->create();

        $rule = RecurringRule::factory()
            ->from($account)
            ->frequency(RecurringFrequency::Monthly)
            ->nextRunAt('2026-01-31 00:00:00')
            ->create();

        $rule->advanceNextRun();

        // 31 Feb tidak ada, jadi occurrence berikutnya di-clamp ke 28 Feb.
        $this->assertSame('2026-02-28', $rule->next_run_at->toDateString());
    }

    public function test_inactive_rules_are_not_generated(): void
    {
        $user = $this->signedIn();
        $account = Account::factory()->forWorkspace($user->workspaces()->sole())->cash('Dompet')->create();

        RecurringRule::factory()
            ->from($account)
            ->needsConfirmation(false)
            ->nextRunAt('2026-09-01 00:00:00')
            ->ended()
            ->create();

        $this->travelTo(now()->parse('2026-09-27 00:15:00'));

        (new RecurringTransactionJob)->handle(app(TransactionManager::class));

        $this->travelBack();

        $this->assertSame(0, Transaction::allWorkspaces()->count());
    }

    public function test_the_job_handles_rules_from_every_workspace(): void
    {
        $this->signedIn();

        $otherOwner = User::factory()->create();
        $otherWorkspace = Workspace::factory()->create(['owner_id' => $otherOwner->id]);
        $otherAccount = Account::factory()->forWorkspace($otherWorkspace)->cash('Dompet', '100000.00')->create();

        RecurringRule::factory()
            ->from($otherAccount)
            ->needsConfirmation(false)
            ->nextRunAt('2026-09-27 00:00:00')
            ->create(['amount' => '15000.00']);

        $this->travelTo(now()->parse('2026-09-27 00:15:00'));

        (new RecurringTransactionJob)->handle(app(TransactionManager::class));

        $this->travelBack();

        $this->assertSame(1, Transaction::allWorkspaces()->count());
        $this->assertSame('85000.00', $otherAccount->fresh()->cached_balance);
    }

    public function test_a_rule_can_be_deactivated_and_reactivated(): void
    {
        $user = $this->signedIn();
        $account = Account::factory()->forWorkspace($user->workspaces()->sole())->cash()->create();

        $rule = RecurringRule::factory()->from($account)->create();

        $this->post(route('recurring-rules.toggle', $rule));
        $this->assertFalse($rule->fresh()->is_active);

        $this->post(route('recurring-rules.toggle', $rule));
        $this->assertTrue($rule->fresh()->is_active);
    }

    public function test_viewers_cannot_manage_rules(): void
    {
        $owner = User::factory()->create();
        $workspace = Workspace::factory()->create(['owner_id' => $owner->id]);
        $workspace->addUser($owner, WorkspaceRole::Owner);

        $viewer = User::factory()->create();
        $workspace->addUser($viewer, WorkspaceRole::Viewer);

        $account = Account::factory()->forWorkspace($workspace)->cash()->create();
        $rule = RecurringRule::factory()->from($account)->create();

        $this->actingAs($viewer)
            ->withSession(['workspace_id' => $workspace->id])
            ->get(route('recurring-rules.index'))
            ->assertOk();

        $this->actingAs($viewer)
            ->withSession(['workspace_id' => $workspace->id])
            ->post(route('recurring-rules.store'), [
                'account_id' => $account->id,
                'type' => TransactionType::Expense->value,
                'amount' => '10000.00',
                'frequency' => RecurringFrequency::Monthly->value,
                'next_run_at' => '2026-10-01',
            ])
            ->assertForbidden();

        $this->actingAs($viewer)
            ->withSession(['workspace_id' => $workspace->id])
            ->post(route('recurring-rules.toggle', $rule))
            ->assertForbidden();

        $this->assertSame(1, RecurringRule::allWorkspaces()->count());
    }

    public function test_the_rules_page_lists_rules_and_pending_instances(): void
    {
        $user = $this->signedIn();
        $account = Account::factory()->forWorkspace($user->workspaces()->sole())->cash()->create();

        $rule = RecurringRule::factory()->from($account)->nextRunAt('2026-09-27 00:10:00')->create();

        $this->travelTo(now()->parse('2026-09-27 00:15:00'));

        (new RecurringTransactionJob)->handle(app(TransactionManager::class));

        $this->travelBack();

        // Pending yang dibuat manual bukan urusan halaman ini.
        Transaction::factory()->from($account)->expense('25000.00')->pending()->create();

        $this->get(route('recurring-rules.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('recurring-rules/Index')
                ->has('rules', 1)
                ->has('pending', 1)
                ->where('pending.0.recurring_rule_id', $rule->id)
                ->has('options.frequencies', 4));
    }

    public function test_a_rule_must_start_before_it_ends(): void
    {
        $user = $this->signedIn();
        $account = Account::factory()->forWorkspace($user->workspaces()->sole())->cash()->create();

        $this->from(route('recurring-rules.index'))
            ->post(route('recurring-rules.store'), [
                'account_id' => $account->id,
                'type' => TransactionType::Expense->value,
                'amount' => '10000.00',
                'frequency' => RecurringFrequency::Monthly->value,
                'next_run_at' => '2026-10-01',
                'end_date' => '2026-09-01',
            ])
            ->assertSessionHasErrors('end_date');

        $this->assertSame(0, RecurringRule::allWorkspaces()->count());
    }

    private function signedIn(): User
    {
        $user = User::factory()->withWorkspace('Rumah Tangga', WorkspaceRole::Owner)->create();

        $this->actingAs($user)
            ->withSession(['workspace_id' => $user->workspaces()->sole()->id]);

        return $user;
    }
}
