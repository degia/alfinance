<?php

namespace Tests\Feature;

use App\Enums\WorkspaceRole;
use App\Models\Account;
use App\Models\Category;
use App\Models\Tag;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Filter, pencarian, pagination, dan ringkasan pada daftar transaksi.
 */
class TransactionFiltersTest extends TestCase
{
    use RefreshDatabase;

    public function test_transactions_are_listed_newest_first(): void
    {
        $user = $this->signedIn();
        $account = Account::factory()->forWorkspace($user->workspaces()->sole())->cash()->create();

        Transaction::factory()->from($account)->expense('10000.00', 'Yang lama')->on('2026-09-01')->create();
        Transaction::factory()->from($account)->expense('20000.00', 'Yang baru')->on('2026-09-27')->create();
        Transaction::factory()->from($account)->expense('30000.00', 'Tengah')->on('2026-09-15')->create();

        $this->get(route('transactions.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('transactions', 3)
                ->where('transactions.0.note', 'Yang baru')
                ->where('transactions.1.note', 'Tengah')
                ->where('transactions.2.note', 'Yang lama'));
    }

    public function test_filter_by_date_range_is_inclusive_on_both_ends(): void
    {
        $user = $this->signedIn();
        $account = Account::factory()->forWorkspace($user->workspaces()->sole())->cash()->create();

        Transaction::factory()->from($account)->expense('10000.00', 'Sebelum')->on('2026-08-31')->create();
        Transaction::factory()->from($account)->expense('10000.00', 'Awal')->on('2026-09-01')->create();
        Transaction::factory()->from($account)->expense('10000.00', 'Akhir')->on('2026-09-30')->create();
        Transaction::factory()->from($account)->expense('10000.00', 'Sesudah')->on('2026-10-01')->create();

        $this->get(route('transactions.index', ['from' => '2026-09-01', 'to' => '2026-09-30']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('transactions', 2)
                ->where('filters.from', '2026-09-01')
                ->where('filters.to', '2026-09-30'));
    }

    public function test_filter_by_account_includes_incoming_and_outgoing_transfers(): void
    {
        $user = $this->signedIn();
        $workspace = $user->workspaces()->sole();

        $source = Account::factory()->forWorkspace($workspace)->cash('Dompet')->create();
        $target = Account::factory()->forWorkspace($workspace)->bank('BCA')->create();
        $other = Account::factory()->forWorkspace($workspace)->ewallet('GoPay')->create();

        Transaction::factory()->from($source)->transfer($target, '100000.00')->create();
        Transaction::factory()->from($other)->expense('25000.00')->create();

        // Akun target ikut involved walau bukan akun sumber.
        $this->get(route('transactions.index', ['account_id' => $target->id]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('transactions', 1)
                ->where('transactions.0.is_transfer', true));

        $this->get(route('transactions.index', ['account_id' => $other->id]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('transactions', 1));
    }

    public function test_filter_by_type_status_and_category(): void
    {
        $user = $this->signedIn();
        $workspace = $user->workspaces()->sole();

        $account = Account::factory()->forWorkspace($workspace)->cash()->create();
        $makan = Category::factory()->forWorkspace($workspace)->create(['name' => 'Makan']);
        $transport = Category::factory()->forWorkspace($workspace)->create(['name' => 'Transport']);

        Transaction::factory()->from($account)->income('5000000.00')->create();
        Transaction::factory()->from($account)->expense('50000.00')->on('2026-09-27')->create();

        $makanTransaction = Transaction::factory()->from($account)->expense('75000.00')->create();
        $makanTransaction->category_id = $makan->id;
        $makanTransaction->save();

        $transportTransaction = Transaction::factory()->from($account)->expense('25000.00')->create();
        $transportTransaction->category_id = $transport->id;
        $transportTransaction->save();

        $this->get(route('transactions.index', ['type' => 'income']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('transactions', 1));

        $this->get(route('transactions.index', ['category_id' => $makan->id]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('transactions', 1));

        $this->get(route('transactions.index', ['status' => 'pending']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('transactions', 0)
                ->where('summary.pending_count', 0));
    }

    public function test_filter_by_tag(): void
    {
        $user = $this->signedIn();
        $workspace = $user->workspaces()->sole();

        $account = Account::factory()->forWorkspace($workspace)->cash()->create();
        $tag = Tag::factory()->forWorkspace($workspace)->create();

        $tagged = Transaction::factory()->from($account)->expense('10000.00')->create();
        $tagged->tags()->attach($tag->id);

        Transaction::factory()->from($account)->expense('20000.00')->create();

        $this->get(route('transactions.index', ['tag_id' => $tag->id]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('transactions', 1)
                ->where('transactions.0.tags.0.name', $tag->name));
    }

    public function test_filter_by_amount_range(): void
    {
        $user = $this->signedIn();
        $account = Account::factory()->forWorkspace($user->workspaces()->sole())->cash()->create();

        Transaction::factory()->from($account)->expense('50000.00')->create();
        Transaction::factory()->from($account)->expense('150000.00')->create();
        Transaction::factory()->from($account)->expense('250000.00')->create();

        $this->get(route('transactions.index', [
            'amount_min' => '100000',
            'amount_max' => '200000',
        ]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('transactions', 1)
                ->where('transactions.0.amount', '150000.00'));
    }

    public function test_search_matches_the_note(): void
    {
        $user = $this->signedIn();
        $account = Account::factory()->forWorkspace($user->workspaces()->sole())->cash()->create();

        Transaction::factory()->from($account)->expense('10000.00', 'Belanja di Indomaret')->create();
        Transaction::factory()->from($account)->expense('20000.00', 'BensinPertamina')->create();

        $this->get(route('transactions.index', ['search' => 'indomaret']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('transactions', 1)
                ->where('transactions.0.note', 'Belanja di Indomaret'));
    }

    public function test_summary_counts_income_and_expense_but_not_transfers(): void
    {
        $user = $this->signedIn();
        $workspace = $user->workspaces()->sole();

        $source = Account::factory()->forWorkspace($workspace)->cash()->create();
        $target = Account::factory()->forWorkspace($workspace)->bank()->create();

        Transaction::factory()->from($source)->income('1000000.00')->create();
        Transaction::factory()->from($source)->expense('250000.00')->create();
        Transaction::factory()->from($source)->transfer($target, '400000.00')->create();
        Transaction::factory()->from($source)->expense('50000.00')->pending()->create();

        $this->get(route('transactions.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('summary.count', 3)
                ->where('summary.income', '1000000.00')
                ->where('summary.expense', '250000.00')
                ->where('summary.net', '750000.00')
                ->where('summary.pending_count', 1));
    }

    public function test_list_is_paginated(): void
    {
        $user = $this->signedIn();
        $account = Account::factory()->forWorkspace($user->workspaces()->sole())->cash()->create();

        foreach (range(1, 25) as $index) {
            Transaction::factory()->from($account)
                ->expense('10000.00', 'Transaksi '.$index)
                ->on(sprintf('2026-09-%02d', $index))
                ->create();
        }

        $this->get(route('transactions.index', ['per_page' => 10]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('transactions', 10)
                ->where('pagination.total', 25)
                ->where('pagination.last_page', 3)
                ->where('pagination.current_page', 1)
                ->where('pagination.next_page_url', route('transactions.index', ['per_page' => 10, 'page' => 2])));
    }

    public function test_invalid_filters_are_rejected(): void
    {
        $this->signedIn();

        $this->get(route('transactions.index', ['to' => '2026-01-01', 'from' => '2026-09-01']))
            ->assertSessionHasErrors('to');

        $this->get(route('transactions.index', ['amount_min' => '5000', 'amount_max' => '1000']))
            ->assertSessionHasErrors('amount_max');

        $this->get(route('transactions.index', ['type' => 'nope']))
            ->assertSessionHasErrors('type');
    }

    public function test_filters_from_another_workspace_are_rejected(): void
    {
        $this->signedIn();

        $foreignOwner = User::factory()->create();
        $foreignWorkspace = Workspace::factory()->create(['owner_id' => $foreignOwner->id]);
        $foreignAccount = Account::factory()->forWorkspace($foreignWorkspace)->cash()->create();

        $this->get(route('transactions.index', ['account_id' => $foreignAccount->id]))
            ->assertSessionHasErrors('account_id');
    }

    private function signedIn(): User
    {
        $user = User::factory()->withWorkspace('Rumah Tangga', WorkspaceRole::Owner)->create();

        $this->actingAs($user)
            ->withSession(['workspace_id' => $user->workspaces()->sole()->id]);

        return $user;
    }
}
