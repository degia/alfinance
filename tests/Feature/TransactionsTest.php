<?php

namespace Tests\Feature;

use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Enums\WorkspaceRole;
use App\Events\TransactionDeleted;
use App\Events\TransactionSaved;
use App\Models\Account;
use App\Models\Category;
use App\Models\Tag;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * Efek saldo adalah inti Fase 3: setiap write harus lewat TransactionManager
 * sehingga `accounts.cached_balance` bergerak persis sebesar dampak transaksi
 * dan bisa dibalik saat edit/hapus.
 */
class TransactionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get(route('transactions.index'))->assertRedirect(route('login'));
    }

    public function test_income_increases_the_account_balance(): void
    {
        $user = $this->signedIn();
        $workspace = $user->workspaces()->sole();
        $account = Account::factory()->forWorkspace($workspace)->cash('Dompet', '100000.00')->create();

        $this->post(route('transactions.store'), [
            'account_id' => $account->id,
            'type' => TransactionType::Income->value,
            'amount' => '250000.55',
            'occurred_at' => '2026-09-27',
            'note' => 'Gaji September',
        ])->assertRedirect(route('transactions.index'));

        $this->assertSame('350000.55', $account->fresh()->cached_balance);

        $transaction = Transaction::allWorkspaces()->sole();

        $this->assertSame(TransactionType::Income, $transaction->type);
        $this->assertSame(TransactionStatus::Posted, $transaction->status);
        $this->assertSame('Gaji September', $transaction->note);
        $this->assertSame($user->id, $transaction->created_by);
        $this->assertSame($workspace->id, $transaction->workspace_id);
    }

    public function test_expense_decreases_the_account_balance(): void
    {
        $user = $this->signedIn();
        $account = Account::factory()->forWorkspace($user->workspaces()->sole())->cash('Dompet', '100000.00')->create();

        $this->post(route('transactions.store'), [
            'account_id' => $account->id,
            'type' => TransactionType::Expense->value,
            'amount' => '42500.25',
            'occurred_at' => '2026-09-27',
        ]);

        $this->assertSame('57499.75', $account->fresh()->cached_balance);
    }

    public function test_transfer_moves_money_between_two_accounts(): void
    {
        $user = $this->signedIn();
        $workspace = $user->workspaces()->sole();

        $source = Account::factory()->forWorkspace($workspace)->cash('Dompet', '500000.00')->create();
        $target = Account::factory()->forWorkspace($workspace)->bank('BCA', '100000.00')->create();

        $this->post(route('transactions.store'), [
            'account_id' => $source->id,
            'transfer_to_account_id' => $target->id,
            'type' => TransactionType::Transfer->value,
            'amount' => '150000.00',
            'occurred_at' => '2026-09-27',
            'note' => 'Pindah ke bank',
        ]);

        $this->assertSame('350000.00', $source->fresh()->cached_balance);
        $this->assertSame('250000.00', $target->fresh()->cached_balance);

        $transaction = Transaction::allWorkspaces()->sole();

        $this->assertNull($transaction->category_id);
        $this->assertSame($target->id, $transaction->transfer_to_account_id);
    }

    public function test_credit_card_spending_may_go_negative(): void
    {
        $user = $this->signedIn();
        $card = Account::factory()->forWorkspace($user->workspaces()->sole())->creditCard('Kartu', '5000000.00')->create();

        $this->post(route('transactions.store'), [
            'account_id' => $card->id,
            'type' => TransactionType::Expense->value,
            'amount' => '750000.00',
            'occurred_at' => '2026-09-27',
        ]);

        $this->assertSame('-750000.00', $card->fresh()->cached_balance);
    }

    public function test_editing_a_transaction_reverses_the_old_effect(): void
    {
        $user = $this->signedIn();
        $account = Account::factory()->forWorkspace($user->workspaces()->sole())->cash('Dompet', '100000.00')->create();

        $this->post(route('transactions.store'), [
            'account_id' => $account->id,
            'type' => TransactionType::Expense->value,
            'amount' => '30000.00',
            'occurred_at' => '2026-09-27',
        ]);

        $transaction = Transaction::allWorkspaces()->sole();

        // Nominal dikecilkan: saldo harus naik sebesar selisihnya, bukan
        // ditimpa oleh nominal baru.
        $this->put(route('transactions.update', $transaction), [
            'account_id' => $account->id,
            'type' => TransactionType::Expense->value,
            'amount' => '10000.00',
            'occurred_at' => '2026-09-27',
        ])->assertRedirect(route('transactions.index'));

        $this->assertSame('90000.00', $account->fresh()->cached_balance);
    }

    public function test_changing_the_account_moves_the_effect_to_the_new_account(): void
    {
        $user = $this->signedIn();
        $workspace = $user->workspaces()->sole();

        $first = Account::factory()->forWorkspace($workspace)->cash('Dompet', '100000.00')->create();
        $second = Account::factory()->forWorkspace($workspace)->bank('BCA', '100000.00')->create();

        $this->post(route('transactions.store'), [
            'account_id' => $first->id,
            'type' => TransactionType::Expense->value,
            'amount' => '25000.00',
            'occurred_at' => '2026-09-27',
        ]);

        $transaction = Transaction::allWorkspaces()->sole();

        $this->put(route('transactions.update', $transaction), [
            'account_id' => $second->id,
            'type' => TransactionType::Expense->value,
            'amount' => '25000.00',
            'occurred_at' => '2026-09-27',
        ]);

        // Dampak lama di akun pertama harus dikembalikan penuh.
        $this->assertSame('100000.00', $first->fresh()->cached_balance);
        $this->assertSame('75000.00', $second->fresh()->cached_balance);
    }

    public function test_changing_expense_into_income_flips_the_sign(): void
    {
        $user = $this->signedIn();
        $account = Account::factory()->forWorkspace($user->workspaces()->sole())->cash('Dompet', '100000.00')->create();

        $this->post(route('transactions.store'), [
            'account_id' => $account->id,
            'type' => TransactionType::Expense->value,
            'amount' => '40000.00',
            'occurred_at' => '2026-09-27',
        ]);

        $transaction = Transaction::allWorkspaces()->sole();

        $this->put(route('transactions.update', $transaction), [
            'account_id' => $account->id,
            'type' => TransactionType::Income->value,
            'amount' => '40000.00',
            'occurred_at' => '2026-09-27',
        ]);

        // 100.000 - 40.000 (dibalik) + 40.000 (income baru) = 140.000
        $this->assertSame('140000.00', $account->fresh()->cached_balance);
    }

    public function test_turning_a_transfer_into_an_expense_undoes_both_legs(): void
    {
        $user = $this->signedIn();
        $workspace = $user->workspaces()->sole();

        $source = Account::factory()->forWorkspace($workspace)->cash('Dompet', '500000.00')->create();
        $target = Account::factory()->forWorkspace($workspace)->bank('BCA', '0.00')->create();

        $this->post(route('transactions.store'), [
            'account_id' => $source->id,
            'transfer_to_account_id' => $target->id,
            'type' => TransactionType::Transfer->value,
            'amount' => '200000.00',
            'occurred_at' => '2026-09-27',
        ]);

        $transaction = Transaction::allWorkspaces()->sole();

        $this->put(route('transactions.update', $transaction), [
            'account_id' => $source->id,
            'type' => TransactionType::Expense->value,
            'amount' => '200000.00',
            'occurred_at' => '2026-09-27',
        ]);

        $this->assertSame('300000.00', $source->fresh()->cached_balance);
        $this->assertSame('0.00', $target->fresh()->cached_balance);
    }

    public function test_deleting_a_transaction_restores_the_balance(): void
    {
        $user = $this->signedIn();
        $account = Account::factory()->forWorkspace($user->workspaces()->sole())->cash('Dompet', '100000.00')->create();

        $this->post(route('transactions.store'), [
            'account_id' => $account->id,
            'type' => TransactionType::Expense->value,
            'amount' => '12500.00',
            'occurred_at' => '2026-09-27',
        ]);

        $transaction = Transaction::allWorkspaces()->sole();

        $this->delete(route('transactions.destroy', $transaction))
            ->assertRedirect(route('transactions.index'));

        $this->assertSame('100000.00', $account->fresh()->cached_balance);
        $this->assertSame(0, Transaction::allWorkspaces()->count());
    }

    public function test_deleting_a_transfer_restores_both_accounts(): void
    {
        $user = $this->signedIn();
        $workspace = $user->workspaces()->sole();

        $source = Account::factory()->forWorkspace($workspace)->cash('Dompet', '500000.00')->create();
        $target = Account::factory()->forWorkspace($workspace)->bank('BCA', '0.00')->create();

        $this->post(route('transactions.store'), [
            'account_id' => $source->id,
            'transfer_to_account_id' => $target->id,
            'type' => TransactionType::Transfer->value,
            'amount' => '300000.00',
            'occurred_at' => '2026-09-27',
        ]);

        $this->delete(route('transactions.destroy', Transaction::allWorkspaces()->sole()));

        $this->assertSame('500000.00', $source->fresh()->cached_balance);
        $this->assertSame('0.00', $target->fresh()->cached_balance);
    }

    public function test_tags_are_synced_on_create_and_update(): void
    {
        $user = $this->signedIn();
        $workspace = $user->workspaces()->sole();

        $account = Account::factory()->forWorkspace($workspace)->cash('Dompet')->create();
        $makan = Tag::factory()->forWorkspace($workspace)->create(['name' => 'Makan']);
        $jalan = Tag::factory()->forWorkspace($workspace)->create(['name' => 'Jalan']);

        $this->post(route('transactions.store'), [
            'account_id' => $account->id,
            'type' => TransactionType::Expense->value,
            'amount' => '50000.00',
            'occurred_at' => '2026-09-27',
            'tag_ids' => [$makan->id, $jalan->id],
        ]);

        $transaction = Transaction::allWorkspaces()->sole();

        // Pivot dicek langsung: setelah request selesai `ActiveWorkspace`
        // sudah dibersihkan middleware, jadi relasi yang di-lazy-load di luar
        // request memang sengaja kosong (fail-closed).
        $this->assertDatabaseHas('transaction_tag', [
            'transaction_id' => $transaction->id,
            'tag_id' => $makan->id,
        ]);
        $this->assertDatabaseHas('transaction_tag', [
            'transaction_id' => $transaction->id,
            'tag_id' => $jalan->id,
        ]);

        // Sync harus bisa mengurangi tag, bukan cuma menambah.
        $this->put(route('transactions.update', $transaction), [
            'account_id' => $account->id,
            'type' => TransactionType::Expense->value,
            'amount' => '50000.00',
            'occurred_at' => '2026-09-27',
            'tag_ids' => [$jalan->id],
        ]);

        $this->assertDatabaseHas('transaction_tag', [
            'transaction_id' => $transaction->id,
            'tag_id' => $jalan->id,
        ]);
        $this->assertDatabaseMissing('transaction_tag', [
            'transaction_id' => $transaction->id,
            'tag_id' => $makan->id,
        ]);

        // Mengosongkan pilihan tag harus benar-benar melepas semua tag.
        $this->put(route('transactions.update', $transaction), [
            'account_id' => $account->id,
            'type' => TransactionType::Expense->value,
            'amount' => '50000.00',
            'occurred_at' => '2026-09-27',
            'tag_ids' => [],
        ])->assertRedirect(route('transactions.index'));

        $this->assertDatabaseMissing('transaction_tag', [
            'transaction_id' => $transaction->id,
            'tag_id' => $jalan->id,
        ]);
    }

    public function test_income_and_expense_require_a_category_from_the_same_workspace(): void
    {
        $user = $this->signedIn();
        $workspace = $user->workspaces()->sole();

        $account = Account::factory()->forWorkspace($workspace)->cash('Dompet')->create();

        $foreignOwner = User::factory()->create();
        $foreignWorkspace = Workspace::factory()->create(['owner_id' => $foreignOwner->id]);
        $foreignCategory = Category::factory()->forWorkspace($foreignWorkspace)->create();

        $this->from(route('transactions.create'))
            ->post(route('transactions.store'), [
                'account_id' => $account->id,
                'type' => TransactionType::Expense->value,
                'amount' => '50000.00',
                'occurred_at' => '2026-09-27',
                'category_id' => $foreignCategory->id,
            ])
            ->assertSessionHasErrors('category_id');

        $this->assertSame(0, Transaction::allWorkspaces()->count());
        $this->assertSame('0.00', $account->fresh()->cached_balance);
    }

    public function test_transfer_requires_a_destination_account(): void
    {
        $user = $this->signedIn();
        $workspace = $user->workspaces()->sole();
        $account = Account::factory()->forWorkspace($workspace)->cash('Dompet', '100000.00')->create();

        $this->from(route('transactions.create'))
            ->post(route('transactions.store'), [
                'account_id' => $account->id,
                'type' => TransactionType::Transfer->value,
                'amount' => '50000.00',
                'occurred_at' => '2026-09-27',
            ])
            ->assertSessionHasErrors('transfer_to_account_id');

        $this->assertSame(0, Transaction::allWorkspaces()->count());
    }

    public function test_transfer_to_the_same_account_is_rejected(): void
    {
        $user = $this->signedIn();
        $account = Account::factory()->forWorkspace($user->workspaces()->sole())->cash('Dompet', '100000.00')->create();

        $this->from(route('transactions.create'))
            ->post(route('transactions.store'), [
                'account_id' => $account->id,
                'transfer_to_account_id' => $account->id,
                'type' => TransactionType::Transfer->value,
                'amount' => '50000.00',
                'occurred_at' => '2026-09-27',
            ])
            ->assertSessionHasErrors('transfer_to_account_id');

        $this->assertSame(0, Transaction::allWorkspaces()->count());
        $this->assertSame('100000.00', $account->fresh()->cached_balance);
    }

    public function test_income_cannot_carry_a_destination_account(): void
    {
        $user = $this->signedIn();
        $workspace = $user->workspaces()->sole();

        $source = Account::factory()->forWorkspace($workspace)->cash('Dompet', '100000.00')->create();
        $other = Account::factory()->forWorkspace($workspace)->bank('BCA', '0.00')->create();

        $this->from(route('transactions.create'))
            ->post(route('transactions.store'), [
                'account_id' => $source->id,
                'transfer_to_account_id' => $other->id,
                'type' => TransactionType::Income->value,
                'amount' => '50000.00',
                'occurred_at' => '2026-09-27',
            ])
            ->assertSessionHasErrors('transfer_to_account_id');

        $this->assertSame(0, Transaction::allWorkspaces()->count());
        $this->assertSame('100000.00', $source->fresh()->cached_balance);
    }

    public function test_transfer_cannot_carry_a_category(): void
    {
        $user = $this->signedIn();
        $workspace = $user->workspaces()->sole();

        $source = Account::factory()->forWorkspace($workspace)->cash('Dompet', '100000.00')->create();
        $target = Account::factory()->forWorkspace($workspace)->bank('BCA', '0.00')->create();
        $category = Category::factory()->forWorkspace($workspace)->create();

        $this->from(route('transactions.create'))
            ->post(route('transactions.store'), [
                'account_id' => $source->id,
                'transfer_to_account_id' => $target->id,
                'category_id' => $category->id,
                'type' => TransactionType::Transfer->value,
                'amount' => '50000.00',
                'occurred_at' => '2026-09-27',
            ])
            ->assertSessionHasErrors('category_id');
    }

    public function test_amount_must_be_a_positive_two_decimal_value(): void
    {
        $user = $this->signedIn();
        $account = Account::factory()->forWorkspace($user->workspaces()->sole())->cash('Dompet')->create();

        $this->from(route('transactions.create'))
            ->post(route('transactions.store'), [
                'account_id' => $account->id,
                'type' => TransactionType::Expense->value,
                'amount' => '-5000.00',
                'occurred_at' => '2026-09-27',
            ])
            ->assertSessionHasErrors('amount');

        $this->from(route('transactions.create'))
            ->post(route('transactions.store'), [
                'account_id' => $account->id,
                'type' => TransactionType::Expense->value,
                'amount' => '5000.555',
                'occurred_at' => '2026-09-27',
            ])
            ->assertSessionHasErrors('amount');

        $this->assertSame(0, Transaction::allWorkspaces()->count());
    }

    public function test_saving_dispatches_events_after_the_balance_is_applied(): void
    {
        Event::fake([TransactionSaved::class, TransactionDeleted::class]);

        $user = $this->signedIn();
        $account = Account::factory()->forWorkspace($user->workspaces()->sole())->cash('Dompet', '0.00')->create();

        $this->post(route('transactions.store'), [
            'account_id' => $account->id,
            'type' => TransactionType::Income->value,
            'amount' => '1000.00',
            'occurred_at' => '2026-09-27',
        ]);

        $transaction = Transaction::allWorkspaces()->sole();

        Event::assertDispatched(
            TransactionSaved::class,
            fn (TransactionSaved $event): bool => $event->transaction->is($transaction) && $event->created,
        );

        $this->delete(route('transactions.destroy', $transaction));

        Event::assertDispatched(TransactionDeleted::class);
    }

    public function test_viewers_can_read_but_not_write_transactions(): void
    {
        $owner = User::factory()->create();
        $workspace = Workspace::factory()->create(['owner_id' => $owner->id]);
        $workspace->addUser($owner, WorkspaceRole::Owner);

        $viewer = User::factory()->create();
        $workspace->addUser($viewer, WorkspaceRole::Viewer);

        $account = Account::factory()->forWorkspace($workspace)->cash('Dompet', '100000.00')->create();
        $transaction = Transaction::factory()->from($account)->expense('10000.00')->create();

        $this->actingAs($viewer)
            ->withSession(['workspace_id' => $workspace->id])
            ->get(route('transactions.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('transactions', 1));

        $this->actingAs($viewer)
            ->withSession(['workspace_id' => $workspace->id])
            ->post(route('transactions.store'), [
                'account_id' => $account->id,
                'type' => TransactionType::Expense->value,
                'amount' => '10000.00',
                'occurred_at' => '2026-09-27',
            ])
            ->assertForbidden();

        $this->actingAs($viewer)
            ->withSession(['workspace_id' => $workspace->id])
            ->put(route('transactions.update', $transaction), [
                'account_id' => $account->id,
                'type' => TransactionType::Expense->value,
                'amount' => '10000.00',
                'occurred_at' => '2026-09-27',
            ])
            ->assertForbidden();

        $this->actingAs($viewer)
            ->withSession(['workspace_id' => $workspace->id])
            ->delete(route('transactions.destroy', $transaction))
            ->assertForbidden();

        $this->assertSame(1, Transaction::allWorkspaces()->count());
        $this->assertSame('100000.00', $account->fresh()->cached_balance);
    }

    public function test_transactions_from_another_workspace_are_not_reachable(): void
    {
        $user = $this->signedIn();

        $foreignOwner = User::factory()->create();
        $foreignWorkspace = Workspace::factory()->create(['owner_id' => $foreignOwner->id]);
        $foreignAccount = Account::factory()->forWorkspace($foreignWorkspace)->cash('Kas Asing', '100000.00')->create();
        $foreignTransaction = Transaction::factory()->from($foreignAccount)->expense('10000.00')->create();

        $this->get(route('transactions.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('transactions', 0));

        $this->get(route('transactions.edit', $foreignTransaction))->assertNotFound();
        $this->delete(route('transactions.destroy', $foreignTransaction))->assertNotFound();

        // Saldo workspace lain tidak boleh tersentuh: 404 terjadi sebelum
        // TransactionManager sempat menghitung pembalikan saldo.
        $this->assertSame('100000.00', $foreignAccount->fresh()->cached_balance);
    }

    /**
     * Form Vue mengirim string kosong untuk field yang tidak dipakai
     * (`emptyForm()` di TransactionForm.vue), dan `ConvertEmptyStringsToNull`
     * mengubahnya jadi `null` sebelum validasi. Aturan `exists` hanya boleh
     * dilewati kalau field ditandai `nullable`; tanpa itu expense biasa gagal
     * dengan pesan "Akun tujuan tidak ditemukan di workspace ini."
     */
    public function test_expense_accepts_empty_optional_fields_from_the_form(): void
    {
        $user = $this->signedIn();
        $account = Account::factory()->forWorkspace($user->workspaces()->sole())->cash('Dompet', '100000.00')->create();

        $this->post(route('transactions.store'), [
            'account_id' => $account->id,
            'type' => TransactionType::Expense->value,
            'amount' => '25000.00',
            'occurred_at' => '2026-09-27',
            'category_id' => '',
            'transfer_to_account_id' => '',
        ])->assertRedirect(route('transactions.index'))->assertSessionHasNoErrors();

        $transaction = Transaction::allWorkspaces()->sole();

        $this->assertNull($transaction->category_id);
        $this->assertNull($transaction->transfer_to_account_id);
        $this->assertSame('75000.00', $account->fresh()->cached_balance);
    }

    public function test_transfer_accepts_an_empty_category_from_the_form(): void
    {
        $user = $this->signedIn();
        $workspace = $user->workspaces()->sole();

        $source = Account::factory()->forWorkspace($workspace)->cash('Dompet', '500000.00')->create();
        $target = Account::factory()->forWorkspace($workspace)->bank('BCA', '0.00')->create();

        $this->post(route('transactions.store'), [
            'account_id' => $source->id,
            'transfer_to_account_id' => $target->id,
            'type' => TransactionType::Transfer->value,
            'amount' => '150000.00',
            'occurred_at' => '2026-09-27',
            'category_id' => '',
        ])->assertRedirect(route('transactions.index'))->assertSessionHasNoErrors();

        $this->assertSame('350000.00', $source->fresh()->cached_balance);
        $this->assertSame('150000.00', $target->fresh()->cached_balance);
    }

    /**`prohibited` tetap menagih nilai yang benar-benar terisi. */
    public function test_expense_still_rejects_a_filled_destination_account(): void
    {
        $user = $this->signedIn();
        $workspace = $user->workspaces()->sole();

        $source = Account::factory()->forWorkspace($workspace)->cash('Dompet', '100000.00')->create();
        $other = Account::factory()->forWorkspace($workspace)->bank('BCA', '0.00')->create();

        $this->post(route('transactions.store'), [
            'account_id' => $source->id,
            'transfer_to_account_id' => $other->id,
            'type' => TransactionType::Expense->value,
            'amount' => '25000.00',
            'occurred_at' => '2026-09-27',
            'category_id' => '',
        ])->assertSessionHasErrors('transfer_to_account_id');

        $this->assertSame('100000.00', $source->fresh()->cached_balance);
    }

    public function test_transfer_still_rejects_a_filled_category(): void
    {
        $user = $this->signedIn();
        $workspace = $user->workspaces()->sole();

        $source = Account::factory()->forWorkspace($workspace)->cash('Dompet', '100000.00')->create();
        $target = Account::factory()->forWorkspace($workspace)->bank('BCA', '0.00')->create();
        $category = Category::factory()->forWorkspace($workspace)->create();

        $this->post(route('transactions.store'), [
            'account_id' => $source->id,
            'transfer_to_account_id' => $target->id,
            'type' => TransactionType::Transfer->value,
            'amount' => '25000.00',
            'occurred_at' => '2026-09-27',
            'category_id' => $category->id,
        ])->assertSessionHasErrors('category_id');

        $this->assertSame('100000.00', $source->fresh()->cached_balance);
    }

    public function test_transactions_require_an_active_workspace(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('transactions.index'))
            ->assertRedirect(route('workspaces.index'));
    }

    private function signedIn(): User
    {
        $user = User::factory()->withWorkspace('Rumah Tangga', WorkspaceRole::Owner)->create();

        $this->actingAs($user)
            ->withSession(['workspace_id' => $user->workspaces()->sole()->id]);

        return $user;
    }
}
