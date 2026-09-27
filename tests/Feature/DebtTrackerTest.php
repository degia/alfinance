<?php

namespace Tests\Feature;

use App\Enums\DebtDirection;
use App\Enums\DebtStatus;
use App\Enums\TransactionType;
use App\Enums\WorkspaceRole;
use App\Models\Account;
use App\Models\Category;
use App\Models\Debt;
use App\Models\DebtPayment;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Debt\DebtService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Utang & piutang (PRD.md §3.7).
 *
 * Invariant yang dijaga di sini:
 * 1. `remaining` SELALU = pokok − total `debt_payments`, berapa pun cara
 *    pembayarannya dicatat (dengan atau tanpa transaksi ikutan);
 * 2. cicilan yang melebihi sisa ditolak dan `remaining` tidak pernah negatif;
 * 3. utang yang sudah punya riwayat pembayaran tidak bisa dihapus;
 * 4. viewer boleh baca, tidak boleh tulis; data workspace lain tak terlihat.
 */
class DebtTrackerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get(route('debts.index'))->assertRedirect(route('login'));
    }

    public function test_users_see_an_empty_list(): void
    {
        $this->signedIn();

        $this->get(route('debts.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('debts/Index')
                ->where('debts', [])
                ->where('status', DebtStatus::Ongoing->value)
                ->where('summary.count', 0)
                ->where('summary.total_remaining', '0.00'));
    }

    public function test_members_can_create_a_debt(): void
    {
        $this->signedIn();

        $this->get(route('debts.create'))->assertOk()
            ->assertInertia(fn ($page) => $page->component('debts/Create'));

        $this->post(route('debts.store'), [
            'direction' => DebtDirection::Payable->value,
            'counterparty' => 'Koperasi Simpan Pinjam',
            'principal' => '12000000.00',
            'interest_rate' => '1.50',
            'start_date' => '2026-09-01',
            'due_date' => '2027-09-30',
            'term_count' => 12,
            'include_in_net_worth' => true,
            'note' => 'Pinjamanmodal',
        ])->assertRedirect(route('debts.index'));

        $debt = Debt::allWorkspaces()->sole();

        $this->assertSame('Koperasi Simpan Pinjam', $debt->counterparty);
        $this->assertSame('12000000.00', $debt->principal);
        // Sisa selalu sama dengan pokok saat baru dibuat.
        $this->assertSame('12000000.00', $debt->remaining);
        $this->assertSame(DebtStatus::Ongoing, $debt->status);
        $this->assertSame('1000000.00', $debt->installmentAmount());
        $this->assertTrue($debt->include_in_net_worth);
    }

    public function test_a_receivable_is_always_part_of_net_worth(): void
    {
        $this->signedIn();

        $this->post(route('debts.store'), [
            'direction' => DebtDirection::Receivable->value,
            'counterparty' => 'Kak Rina',
            'principal' => '750000.00',
            'start_date' => '2026-09-01',
            'term_count' => 3,
        ])->assertRedirect(route('debts.index'));

        // Opt-in hanya bermakna untuk utang; piutang selalu menambah aset.
        $this->assertTrue(Debt::allWorkspaces()->sole()->include_in_net_worth);
    }

    public function test_principal_and_due_date_are_validated(): void
    {
        $this->signedIn();

        $this->post(route('debts.store'), [
            'direction' => DebtDirection::Payable->value,
            'counterparty' => 'Tempo',
            'principal' => '0.00',
            'interest_rate' => '-1.00',
            'start_date' => '2026-09-30',
            'due_date' => '2026-09-01',
            'term_count' => 0,
        ])->assertSessionHasErrors(['principal', 'interest_rate', 'due_date', 'term_count']);

        $this->assertSame(0, Debt::allWorkspaces()->count());
    }

    public function test_an_account_from_another_workspace_is_rejected(): void
    {
        $workspace = $this->signedIn()->workspaces()->sole();
        $foreign = Workspace::factory()->create();
        $foreignAccount = Account::factory()->forWorkspace($foreign)->cash()->create();

        $this->post(route('debts.store'), [
            'direction' => DebtDirection::Payable->value,
            'counterparty' => 'Tempo',
            'principal' => '1000000.00',
            'account_id' => $foreignAccount->id,
        ])->assertSessionHasErrors('account_id');

        $this->assertSame(0, Debt::allWorkspaces()->count());
        $this->assertNotNull($workspace);
    }

    public function test_the_index_lists_debts_with_derived_numbers(): void
    {
        $workspace = $this->signedIn()->workspaces()->sole();

        $debt = Debt::factory()->forWorkspace($workspace)->payable()
            ->principal('1200000.00')->dueOn(CarbonImmutable::today()->addDays(10)->toDateString())
            ->terms(12)->create(['counterparty' => 'KPR']);

        $this->post(route('debts.payments.store', $debt), [
            'amount' => '300000.00',
            'paid_at' => '2026-09-05',
        ])->assertRedirect(route('debts.show', $debt));

        $this->get(route('debts.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('debts', 1)
                ->where('debts.0.counterparty', 'KPR')
                ->where('debts.0.principal', '1200000.00')
                ->where('debts.0.remaining', '900000.00')
                ->where('debts.0.paid', '300000.00')
                ->where('debts.0.paid_percent', 25)
                ->where('debts.0.paid_term_count', 1)
                ->where('debts.0.installment_amount', '100000.00')
                ->where('summary.count', 1)
                ->where('summary.total_remaining', '900000.00')
                ->where('summary.payable', '900000.00'));
    }

    public function test_debts_can_be_filtered_by_status(): void
    {
        $workspace = $this->signedIn()->workspaces()->sole();
        $today = CarbonImmutable::today();

        Debt::factory()->forWorkspace($workspace)->payable()
            ->principal('1000000.00')->dueOn($today->addMonth()->toDateString())
            ->create(['counterparty' => 'Akan datang']);
        $overdue = Debt::factory()->forWorkspace($workspace)->payable()
            ->principal('2000000.00')->dueOn($today->subDays(3)->toDateString())
            ->create(['counterparty' => 'Telat']);
        $settled = Debt::factory()->forWorkspace($workspace)->payable()
            ->principal('500000.00')->create(['counterparty' => 'Lunas']);

        app(DebtService::class)->recordPayment($settled, [
            'amount' => '500000.00',
            'paid_at' => $today->toDateString(),
        ]);

        $this->get(route('debts.index', ['status' => DebtStatus::Overdue->value]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('status', DebtStatus::Overdue->value)
                ->has('debts', 1)
                ->where('debts.0.counterparty', 'Telat')
                ->where('debts.0.days_until_due', -3)
                ->where('summary.count', 1)
                ->where('summary.total_overdue', '2000000.00'));

        $this->get(route('debts.index', ['status' => DebtStatus::Settled->value]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('debts.0.counterparty', 'Lunas'));

        $this->get(route('debts.index', ['status' => DebtStatus::Ongoing->value]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('debts', 1)
                ->where('debts.0.counterparty', 'Akan datang'));

        $this->assertNotNull($overdue);
    }

    public function test_an_unknown_status_filter_falls_back_to_ongoing(): void
    {
        $workspace = $this->signedIn()->workspaces()->sole();

        Debt::factory()->forWorkspace($workspace)->payable()
            ->principal('1000000.00')->dueOn(CarbonImmutable::today()->addMonth()->toDateString())
            ->create();

        $this->get(route('debts.index', ['status' => 'entah']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('status', DebtStatus::Ongoing->value)
                ->has('debts', 1));
    }

    public function test_the_detail_page_lists_installments_newest_first(): void
    {
        $workspace = $this->signedIn()->workspaces()->sole();

        $debt = Debt::factory()->forWorkspace($workspace)->payable()
            ->principal('3000000.00')->create(['counterparty' => 'Koperasi']);

        foreach (['2026-09-03' => '1000000.00', '2026-09-08' => '500000.00'] as $date => $amount) {
            $this->post(route('debts.payments.store', $debt), [
                'amount' => $amount,
                'paid_at' => $date,
            ]);
        }

        $this->get(route('debts.show', $debt))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('debts/Show')
                ->where('debt.counterparty', 'Koperasi')
                ->where('debt.remaining', '1500000.00')
                ->where('debt.paid_percent', 50)
                ->where('debt.status', DebtStatus::Ongoing->value)
                ->has('payments', 2)
                ->where('payments.0.paid_at', '2026-09-08')
                ->where('payments.0.amount', '500000.00')
                ->where('payments.1.paid_at', '2026-09-03'));
    }

    public function test_payments_reduce_the_remaining_amount_exactly(): void
    {
        $workspace = $this->signedIn()->workspaces()->sole();

        $debt = Debt::factory()->forWorkspace($workspace)->payable()
            ->principal('1000000.00')->create();

        // Nominal ber-desimal tetap: tidak boleh ada pembulatan tersembunyi.
        $this->post(route('debts.payments.store', $debt), [
            'amount' => '333333.33',
            'paid_at' => '2026-09-04',
        ])->assertRedirect();

        $this->post(route('debts.payments.store', $debt), [
            'amount' => '666666.67',
            'paid_at' => '2026-09-05',
        ])->assertRedirect();

        $debt->refresh();

        $this->assertSame('0.00', $debt->remaining);
        $this->assertSame(DebtStatus::Settled, $debt->status);
        $this->assertSame(2, DebtPayment::allWorkspaces()->count());
    }

    public function test_a_payment_beyond_the_remaining_amount_is_rejected(): void
    {
        $workspace = $this->signedIn()->workspaces()->sole();

        $debt = Debt::factory()->forWorkspace($workspace)->payable()
            ->principal('1000000.00')->create();

        $this->post(route('debts.payments.store', $debt), [
            'amount' => '600000.00',
            'paid_at' => '2026-09-04',
        ])->assertRedirect();

        $this->post(route('debts.payments.store', $debt), [
            'amount' => '500000.00',
            'paid_at' => '2026-09-06',
        ])->assertStatus(422);

        // Sisa tidak boleh berubah dan tidak boleh negatif.
        $this->assertSame('400000.00', $debt->fresh()->remaining);
        $this->assertSame(1, DebtPayment::allWorkspaces()->count());
    }

    public function test_a_future_payment_date_is_rejected(): void
    {
        $workspace = $this->signedIn()->workspaces()->sole();
        $debt = Debt::factory()->forWorkspace($workspace)->payable()->create();

        $this->post(route('debts.payments.store', $debt), [
            'amount' => '100000.00',
            'paid_at' => CarbonImmutable::today()->addDay()->toDateString(),
        ])->assertSessionHasErrors('paid_at');

        $this->assertSame(0, DebtPayment::allWorkspaces()->count());
    }

    public function test_a_payment_can_also_create_an_expense_transaction(): void
    {
        $workspace = $this->signedIn()->workspaces()->sole();
        $account = Account::factory()->forWorkspace($workspace)->cash('Dompet', '5000000.00')->create();
        $category = Category::factory()->forWorkspace($workspace)->create();

        $debt = Debt::factory()->forWorkspace($workspace)->payable()->fromAccount($account)
            ->principal('2000000.00')->create();

        $this->post(route('debts.payments.store', $debt), [
            'amount' => '500000.00',
            'paid_at' => '2026-09-04',
            'note' => 'Cicilan bulan ini',
            'create_transaction' => 1,
            'transaction' => [
                'account_id' => $account->id,
                'category_id' => $category->id,
                'note' => 'Bayar cicilan',
            ],
        ])->assertRedirect(route('debts.show', $debt));

        $transaction = Transaction::allWorkspaces()->sole();
        $payment = DebtPayment::allWorkspaces()->sole();

        // Utang = expense, jadi saldo akun berkurang lewat TransactionManager.
        $this->assertSame(TransactionType::Expense, $transaction->type);
        $this->assertSame('4500000.00', $account->fresh()->cached_balance);
        $this->assertSame('500000.00', $transaction->amount);
        $this->assertSame($transaction->id, $payment->transaction_id);
        $this->assertSame('1500000.00', $debt->fresh()->remaining);
    }

    public function test_a_receivable_payment_becomes_an_income_transaction(): void
    {
        $workspace = $this->signedIn()->workspaces()->sole();
        $account = Account::factory()->forWorkspace($workspace)->cash('Dompet', '100000.00')->create();

        $debt = Debt::factory()->forWorkspace($workspace)->receivable()->fromAccount($account)
            ->principal('750000.00')->create();

        $this->post(route('debts.payments.store', $debt), [
            'amount' => '250000.00',
            'paid_at' => '2026-09-04',
            'create_transaction' => 1,
            'transaction' => ['account_id' => $account->id],
        ])->assertRedirect();

        $transaction = Transaction::allWorkspaces()->sole();

        // Piutang = uang masuk, bukan pengeluaran.
        $this->assertSame(TransactionType::Income, $transaction->type);
        $this->assertSame('350000.00', $account->fresh()->cached_balance);
    }

    public function test_a_payment_without_a_transaction_leaves_balances_alone(): void
    {
        $workspace = $this->signedIn()->workspaces()->sole();
        $account = Account::factory()->forWorkspace($workspace)->cash('Dompet', '5000000.00')->create();
        $debt = Debt::factory()->forWorkspace($workspace)->payable()->fromAccount($account)
            ->principal('2000000.00')->create();

        $this->post(route('debts.payments.store', $debt), [
            'amount' => '500000.00',
            'paid_at' => '2026-09-04',
        ])->assertRedirect();

        $this->assertSame(0, Transaction::allWorkspaces()->count());
        $this->assertSame('5000000.00', $account->fresh()->cached_balance);
        $this->assertNull(DebtPayment::allWorkspaces()->sole()->transaction_id);
    }

    public function test_a_payment_account_from_another_workspace_is_rejected(): void
    {
        $workspace = $this->signedIn()->workspaces()->sole();
        $foreign = Workspace::factory()->create();
        $foreignAccount = Account::factory()->forWorkspace($foreign)->cash()->create();

        $debt = Debt::factory()->forWorkspace($workspace)->payable()->principal('1000000.00')->create();

        $this->post(route('debts.payments.store', $debt), [
            'amount' => '100000.00',
            'paid_at' => '2026-09-04',
            'create_transaction' => 1,
            'transaction' => ['account_id' => $foreignAccount->id],
        ])->assertSessionHasErrors('transaction.account_id');

        $this->assertSame(0, DebtPayment::allWorkspaces()->count());
        $this->assertSame('1000000.00', $debt->fresh()->remaining);
    }

    public function test_members_can_edit_a_debt_without_payments(): void
    {
        $workspace = $this->signedIn()->workspaces()->sole();
        $debt = Debt::factory()->forWorkspace($workspace)->payable()
            ->principal('1000000.00')->create(['counterparty' => 'Lama']);

        $this->get(route('debts.edit', $debt))->assertOk()
            ->assertInertia(fn ($page) => $page->component('debts/Edit')
                ->where('debt.counterparty', 'Lama'));

        $this->put(route('debts.update', $debt), [
            'direction' => DebtDirection::Payable->value,
            'counterparty' => 'Baru',
            'principal' => '1500000.00',
            'due_date' => '2026-12-31',
        ])->assertRedirect(route('debts.show', $debt));

        $debt->refresh();

        $this->assertSame('Baru', $debt->counterparty);
        $this->assertSame('1500000.00', $debt->principal);
        // Sisa mengikuti pokok karena belum ada cicilan.
        $this->assertSame('1500000.00', $debt->remaining);
    }

    public function test_the_principal_is_frozen_once_a_payment_exists(): void
    {
        $workspace = $this->signedIn()->workspaces()->sole();
        $debt = Debt::factory()->forWorkspace($workspace)->payable()
            ->principal('1000000.00')->create();

        $this->post(route('debts.payments.store', $debt), [
            'amount' => '250000.00',
            'paid_at' => '2026-09-04',
        ]);

        // Mengubah pokok berarti mengoreksi sejarah, jadi ditolak.
        $this->put(route('debts.update', $debt), [
            'direction' => DebtDirection::Payable->value,
            'counterparty' => 'Koperasi',
            'principal' => '2000000.00',
        ])->assertStatus(422);

        $this->put(route('debts.update', $debt), [
            'direction' => DebtDirection::Receivable->value,
            'counterparty' => 'Koperasi',
            'principal' => '1000000.00',
        ])->assertStatus(422);

        $debt->refresh();

        $this->assertSame('1000000.00', $debt->principal);
        $this->assertSame('750000.00', $debt->remaining);
        $this->assertSame(DebtDirection::Payable, $debt->direction);
    }

    public function test_metadata_can_still_be_edited_after_a_payment(): void
    {
        $workspace = $this->signedIn()->workspaces()->sole();
        $debt = Debt::factory()->forWorkspace($workspace)->payable()
            ->principal('1000000.00')->create(['counterparty' => 'Koperasi']);

        $this->post(route('debts.payments.store', $debt), [
            'amount' => '250000.00',
            'paid_at' => '2026-09-04',
        ]);

        $this->put(route('debts.update', $debt), [
            'direction' => DebtDirection::Payable->value,
            'counterparty' => 'Koperasi Sejahtera',
            'principal' => '1000000.00',
            'due_date' => '2026-12-31',
            'note' => 'Sudah daftar restrukturisasi',
        ])->assertRedirect(route('debts.show', $debt));

        $debt->refresh();

        $this->assertSame('Koperasi Sejahtera', $debt->counterparty);
        $this->assertSame('750000.00', $debt->remaining);
    }

    public function test_a_debt_without_payments_can_be_deleted(): void
    {
        $workspace = $this->signedIn()->workspaces()->sole();
        $debt = Debt::factory()->forWorkspace($workspace)->payable()->create();

        $this->delete(route('debts.destroy', $debt))
            ->assertRedirect(route('debts.index'));

        $this->assertSame(0, Debt::allWorkspaces()->count());
    }

    public function test_a_debt_with_a_payment_history_cannot_be_deleted(): void
    {
        $workspace = $this->signedIn()->workspaces()->sole();
        $debt = Debt::factory()->forWorkspace($workspace)->payable()
            ->principal('1000000.00')->create();

        $this->post(route('debts.payments.store', $debt), [
            'amount' => '100000.00',
            'paid_at' => '2026-09-04',
        ]);

        // Yang menandai akhir adalah `settled`, bukan menghapus baris.
        $this->delete(route('debts.destroy', $debt))->assertStatus(422);

        $this->assertSame(1, Debt::allWorkspaces()->count());
    }

    public function test_viewers_can_read_but_not_write_debts(): void
    {
        $viewer = $this->signedInAs(WorkspaceRole::Viewer);
        $workspace = $viewer->workspaces()->sole();
        $debt = Debt::factory()->forWorkspace($workspace)->payable()->create();

        $this->get(route('debts.index'))->assertOk();
        $this->get(route('debts.show', $debt))->assertOk();

        $this->get(route('debts.create'))->assertForbidden();
        $this->get(route('debts.edit', $debt))->assertForbidden();

        $this->post(route('debts.store'), [
            'direction' => DebtDirection::Payable->value,
            'counterparty' => 'Dilarang',
            'principal' => '1000000.00',
        ])->assertForbidden();

        $this->put(route('debts.update', $debt), [
            'direction' => DebtDirection::Payable->value,
            'counterparty' => 'Dilarang',
            'principal' => '1000000.00',
        ])->assertForbidden();

        $this->post(route('debts.payments.store', $debt), [
            'amount' => '100000.00',
            'paid_at' => '2026-09-04',
        ])->assertForbidden();

        $this->delete(route('debts.destroy', $debt))->assertForbidden();

        $this->assertSame(0, DebtPayment::allWorkspaces()->count());
    }

    public function test_debts_from_another_workspace_are_not_reachable(): void
    {
        $this->signedIn();

        $foreign = Workspace::factory()->create();
        $foreignDebt = Debt::factory()->forWorkspace($foreign)->payable()
            ->principal('9000000.00')->create(['counterparty' => 'Asing']);

        $this->get(route('debts.show', $foreignDebt))->assertNotFound();
        $this->get(route('debts.edit', $foreignDebt))->assertNotFound();
        $this->put(route('debts.update', $foreignDebt), [
            'direction' => DebtDirection::Payable->value,
            'counterparty' => 'Dibajak',
            'principal' => '9000000.00',
        ])->assertNotFound();
        $this->post(route('debts.payments.store', $foreignDebt), [
            'amount' => '100000.00',
            'paid_at' => '2026-09-04',
        ])->assertNotFound();
        $this->delete(route('debts.destroy', $foreignDebt))->assertNotFound();

        $this->get(route('debts.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('debts', []));

        $this->assertSame('Asing', $foreignDebt->fresh()->counterparty);
        $this->assertSame('9000000.00', $foreignDebt->fresh()->remaining);
        $this->assertSame(0, DebtPayment::allWorkspaces()->count());
    }

    private function signedIn(): User
    {
        return $this->signedInAs(WorkspaceRole::Owner);
    }

    private function signedInAs(WorkspaceRole $role): User
    {
        $user = User::factory()->withWorkspace('Rumah Tangga', $role)->create();

        $this->actingAs($user)
            ->withSession(['workspace_id' => $user->workspaces()->sole()->id]);

        return $user;
    }
}
