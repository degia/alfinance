<?php

namespace Tests\Feature;

use App\Enums\DebtStatus;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Enums\WorkspaceRole;
use App\Models\Account;
use App\Models\Debt;
use App\Models\DebtPayment;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "Bayar utang" dari form Catat transaksi (PRD.md §3.7).
 *
 * Expense yang dipilih sebagai pembayaran utang harus:
 * - tercatat di riwayat cicilan (`debt_payments`) dengan transaksi yang
 *   sama, sehingga `debts.remaining` ikut turun persis sebesar nominalnya;
 * - menolak nominal yang melebihi sisa utang, dan sisa tidak pernah negatif;
 * - melepas tautannya (sisa utang kembali penuh) saat expense diubah jadi
 *   income, tautannya diganti, atau transaksinya dihapus.
 *
 * Arah sebaliknya (piutang yang diterima dari modul Transaksi) belum ada:
 * form hanya menawarkan utang yang harus dibayar.
 */
class TransactionDebtPaymentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_the_expense_form_offers_the_debts_that_must_be_paid(): void
    {
        $user = $this->signedIn();
        $workspace = $user->workspaces()->sole();

        // Jatuh tempo dihitung relatif terhadap hari ini supaya urutan
        // "terlambat didahulukan" tetap benar kapan pun test dijalankan.
        $today = CarbonImmutable::today();

        Debt::factory()->forWorkspace($workspace)->payable()
            ->principal('2000000.00')
            ->dueOn($today->subDay()->toDateString())
            ->create(['counterparty' => 'Budi']);

        Debt::factory()->forWorkspace($workspace)->payable()
            ->principal('500000.00')
            ->dueOn($today->addMonth()->toDateString())
            ->create(['counterparty' => 'Siti']);

        // Utang lunas tidak boleh ditawarkan — tidak ada yang perlu dibayar.
        Debt::factory()->forWorkspace($workspace)->payable()
            ->principal('100000.00')
            ->remaining('0.00')
            ->create(['counterparty' => 'Lunas']);

        // Piutang bukan hutang kita.
        Debt::factory()->forWorkspace($workspace)->receivable()
            ->principal('700000.00')
            ->create(['counterparty' => 'Tagihan']);

        $this->get(route('transactions.create'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('transactions/Create')
                // Terlambat didahulukan, lalu yang jatuh tempo terdekat.
                ->where('options.debts.0.counterparty', 'Budi')
                ->where('options.debts.0.remaining', '2000000.00')
                ->where('options.debts.0.due_date', $today->subDay()->toDateString())
                ->where('options.debts.0.status_label', DebtStatus::Overdue->label())
                ->where('options.debts.1.counterparty', 'Siti')
                ->where('options.debts.1.status_label', DebtStatus::Ongoing->label())
                ->has('options.debts', 2),
            );
    }

    public function test_a_debt_payment_reduces_the_remaining_amount_exactly(): void
    {
        $user = $this->signedIn();
        $workspace = $user->workspaces()->sole();

        $account = Account::factory()->forWorkspace($workspace)->cash('Dompet', '5000000.00')->create();
        $debt = Debt::factory()->forWorkspace($workspace)->payable()
            ->principal('1000000.00')
            ->create(['counterparty' => 'Budi']);

        $this->post(route('transactions.store'), [
            'account_id' => $account->id,
            'type' => TransactionType::Expense->value,
            'amount' => '333333.33',
            'debt_id' => $debt->id,
            'occurred_at' => '2026-09-27',
        ])->assertRedirect(route('transactions.index'));

        // Sisa utang ikut turun, dan sisa melingkar: tidak ada pembulatan.
        $this->assertSame('666666.67', $debt->fresh()->remaining);

        $transaction = Transaction::allWorkspaces()->where('type', TransactionType::Expense->value)->sole();
        $payment = DebtPayment::allWorkspaces()->sole();

        $this->assertSame($transaction->id, $payment->transaction_id);
        $this->assertSame($debt->id, $payment->debt_id);
        $this->assertSame('333333.33', $payment->amount);
        $this->assertSame('2026-09-27', $payment->paid_at->toDateString());

        // Saldo akun tetap keluar sebesar penuh seperti expense biasa.
        $this->assertSame('4666666.67', $account->fresh()->cached_balance);
    }

    public function test_paying_a_debt_off_settles_it(): void
    {
        $user = $this->signedIn();
        $workspace = $user->workspaces()->sole();

        $account = Account::factory()->forWorkspace($workspace)->cash('Dompet', '5000000.00')->create();
        $debt = Debt::factory()->forWorkspace($workspace)->payable()
            ->principal('1000000.00')
            ->create(['counterparty' => 'Budi']);

        $this->post(route('transactions.store'), [
            'account_id' => $account->id,
            'type' => TransactionType::Expense->value,
            'amount' => '1000000.00',
            'debt_id' => $debt->id,
            'occurred_at' => '2026-09-27',
        ])->assertRedirect(route('transactions.index'));

        $this->assertSame('0.00', $debt->fresh()->remaining);
        $this->assertSame(DebtStatus::Settled, $debt->fresh()->status);

        // Utang yang sudah lunas tidak boleh muncul sebagai pilihan lagi.
        $this->get(route('transactions.create'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('options.debts', 0));
    }

    public function test_a_payment_beyond_the_remaining_amount_is_rejected(): void
    {
        $user = $this->signedIn();
        $workspace = $user->workspaces()->sole();

        $account = Account::factory()->forWorkspace($workspace)->cash('Dompet', '5000000.00')->create();
        $debt = Debt::factory()->forWorkspace($workspace)->payable()
            ->principal('1000000.00')
            ->create(['counterparty' => 'Budi']);

        $this->from(route('transactions.create'))->post(route('transactions.store'), [
            'account_id' => $account->id,
            'type' => TransactionType::Expense->value,
            'amount' => '1000000.01',
            'debt_id' => $debt->id,
            'occurred_at' => '2026-09-27',
        ])->assertSessionHasErrors('amount');

        $this->assertSame('1000000.00', $debt->fresh()->remaining);
        $this->assertSame(0, Transaction::allWorkspaces()->count());
        $this->assertSame('5000000.00', $account->fresh()->cached_balance);
    }

    public function test_a_settled_debt_cannot_be_paid_again(): void
    {
        $user = $this->signedIn();
        $workspace = $user->workspaces()->sole();

        $account = Account::factory()->forWorkspace($workspace)->cash('Dompet', '5000000.00')->create();
        $debt = Debt::factory()->forWorkspace($workspace)->payable()
            ->principal('1000000.00')
            ->remaining('0.00')
            ->create(['counterparty' => 'Budi']);

        $this->from(route('transactions.create'))->post(route('transactions.store'), [
            'account_id' => $account->id,
            'type' => TransactionType::Expense->value,
            'amount' => '100000.00',
            'debt_id' => $debt->id,
            'occurred_at' => '2026-09-27',
        ])->assertSessionHasErrors('debt_id');

        $this->assertSame('0.00', $debt->fresh()->remaining);
        $this->assertSame(0, Transaction::allWorkspaces()->count());
    }

    public function test_a_receivable_cannot_be_paid_from_the_expense_form(): void
    {
        $user = $this->signedIn();
        $workspace = $user->workspaces()->sole();

        $account = Account::factory()->forWorkspace($workspace)->cash('Dompet', '5000000.00')->create();
        $debt = Debt::factory()->forWorkspace($workspace)->receivable()
            ->principal('1000000.00')
            ->create(['counterparty' => 'Budi']);

        $this->from(route('transactions.create'))->post(route('transactions.store'), [
            'account_id' => $account->id,
            'type' => TransactionType::Expense->value,
            'amount' => '100000.00',
            'debt_id' => $debt->id,
            'occurred_at' => '2026-09-27',
        ])->assertSessionHasErrors('debt_id');

        $this->assertSame('1000000.00', $debt->fresh()->remaining);
        $this->assertSame(0, Transaction::allWorkspaces()->count());
    }

    public function test_a_debt_from_another_workspace_cannot_be_paid(): void
    {
        $this->signedIn();

        $foreignDebt = Debt::factory()->payable()->principal('1000000.00')->create();

        $account = $this->account();

        $this->from(route('transactions.create'))->post(route('transactions.store'), [
            'account_id' => $account->id,
            'type' => TransactionType::Expense->value,
            'amount' => '100000.00',
            'debt_id' => $foreignDebt->id,
            'occurred_at' => '2026-09-27',
        ])->assertSessionHasErrors('debt_id');

        $this->assertSame('1000000.00', $foreignDebt->fresh()->remaining);
        $this->assertSame(0, Transaction::allWorkspaces()->count());
    }

    public function test_only_expenses_can_carry_a_debt(): void
    {
        [$account, $other] = $this->twoAccounts();

        $debt = Debt::factory()->forWorkspace($this->signedIn()->workspaces()->sole())
            ->payable()
            ->principal('1000000.00')
            ->create(['counterparty' => 'Budi']);

        $this->from(route('transactions.create'))->post(route('transactions.store'), [
            'account_id' => $account->id,
            'type' => TransactionType::Income->value,
            'amount' => '100000.00',
            'debt_id' => $debt->id,
            'occurred_at' => '2026-09-27',
        ])->assertSessionHasErrors('debt_id');

        $this->from(route('transactions.create'))->post(route('transactions.store'), [
            'account_id' => $account->id,
            'transfer_to_account_id' => $other->id,
            'type' => TransactionType::Transfer->value,
            'amount' => '100000.00',
            'debt_id' => $debt->id,
            'occurred_at' => '2026-09-27',
        ])->assertSessionHasErrors('debt_id');

        $this->assertSame('1000000.00', $debt->fresh()->remaining);
        $this->assertSame(0, Transaction::allWorkspaces()->count());
    }

    public function test_the_edit_form_is_prefilled_with_the_linked_debt(): void
    {
        [$account] = $this->twoAccounts();

        $debt = Debt::factory()->forWorkspace($this->signedIn()->workspaces()->sole())
            ->payable()
            ->principal('1000000.00')
            ->create(['counterparty' => 'Budi']);

        $transaction = $this->payDebt($account, $debt, '250000.00');

        $this->get(route('transactions.edit', $transaction))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('transactions/Edit')
                ->where('transaction.debt_id', $debt->id)
                ->where('transaction.debt.counterparty', 'Budi')
                ->where('transaction.is_debt_payment', true)
                // Utang yang sudah lunas boleh hilang dari select, tapi
                // tautannya yang sudah tersimpan tetap harus tampil supaya user
                // bisa membatalkannya.
                ->where('transaction.amount', '250000.00'),
            );
    }

    public function test_the_edit_form_still_lists_a_settled_linked_debt(): void
    {
        [$account] = $this->twoAccounts();

        $debt = Debt::factory()->forWorkspace($this->signedIn()->workspaces()->sole())
            ->payable()
            ->principal('1000000.00')
            ->create(['counterparty' => 'Budi']);

        $transaction = $this->payDebt($account, $debt, '1000000.00');

        $this->assertSame('0.00', $debt->fresh()->remaining);

        // Utang lunas tidak lagi masuk daftar "yang harus dibayar", tapi form
        // edit tetap harus menampilkannya supaya tautannya terlihat dan bisa
        // dilepas.
        $this->get(route('transactions.edit', $transaction))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('transaction.debt_id', $debt->id)
                ->where('options.debts.0.counterparty', 'Budi')
                ->where('options.debts.0.remaining', '0.00')
                ->where('options.debts.0.status_label', DebtStatus::Settled->label())
                ->has('options.debts', 1),
            );
    }

    public function test_editing_the_amount_of_a_debt_payment_moves_the_remaining_amount(): void
    {
        [$account] = $this->twoAccounts();

        $debt = Debt::factory()->forWorkspace($this->signedIn()->workspaces()->sole())
            ->payable()
            ->principal('1000000.00')
            ->create(['counterparty' => 'Budi']);

        $transaction = $this->payDebt($account, $debt, '250000.00');

        $this->assertSame('750000.00', $debt->fresh()->remaining);

        $this->put(route('transactions.update', $transaction), [
            'account_id' => $account->id,
            'type' => TransactionType::Expense->value,
            'amount' => '400000.00',
            'debt_id' => $debt->id,
            'occurred_at' => '2026-09-28',
        ])->assertRedirect(route('transactions.index'));

        $this->assertSame('600000.00', $debt->fresh()->remaining);
        $this->assertSame('4600000.00', $account->fresh()->cached_balance);

        // Satu baris cicilan saja — nominalnya yang dikoreksi, bukan
        // ditumpuki baris baru.
        $payment = DebtPayment::allWorkspaces()->sole();

        $this->assertSame('400000.00', $payment->amount);
        $this->assertSame('2026-09-28', $payment->paid_at->toDateString());
    }

    public function test_raising_the_amount_to_the_full_principal_settles_the_debt(): void
    {
        [$account] = $this->twoAccounts();

        $debt = Debt::factory()->forWorkspace($this->signedIn()->workspaces()->sole())
            ->payable()
            ->principal('1000000.00')
            ->create(['counterparty' => 'Budi']);

        $transaction = $this->payDebt($account, $debt, '600000.00');

        // Cicilan yang sedang diedit tidak ikut menghitung diri sendiri, jadi
        // nominal boleh dinaikkan sampai seluruh pokok — di sini jadi lunas.
        $this->from(route('transactions.edit', $transaction))->put(route('transactions.update', $transaction), [
            'account_id' => $account->id,
            'type' => TransactionType::Expense->value,
            'amount' => '1000000.00',
            'debt_id' => $debt->id,
            'occurred_at' => '2026-09-27',
        ])->assertRedirect(route('transactions.index'));

        $this->assertSame('0.00', $debt->fresh()->remaining);
        $this->assertSame(DebtStatus::Settled, $debt->fresh()->currentStatus());
        $this->assertSame('4000000.00', $account->fresh()->cached_balance);
        $this->assertSame('1000000.00', DebtPayment::allWorkspaces()->sole()->amount);
    }

    public function test_raising_the_amount_beyond_the_principal_is_rejected(): void
    {
        [$account] = $this->twoAccounts();

        $debt = Debt::factory()->forWorkspace($this->signedIn()->workspaces()->sole())
            ->payable()
            ->principal('1000000.00')
            ->create(['counterparty' => 'Budi']);

        $transaction = $this->payDebt($account, $debt, '600000.00');

        $this->from(route('transactions.edit', $transaction))->put(route('transactions.update', $transaction), [
            'account_id' => $account->id,
            'type' => TransactionType::Expense->value,
            'amount' => '1000000.01',
            'debt_id' => $debt->id,
            'occurred_at' => '2026-09-27',
        ])->assertSessionHasErrors('amount');

        // Nominal yang gagal disimpan tidak boleh mengubah apa pun.
        $this->assertSame('400000.00', $debt->fresh()->remaining);
        $this->assertSame('4400000.00', $account->fresh()->cached_balance);
        $this->assertSame('600000.00', $transaction->fresh()->amount);
    }

    public function test_clearing_the_debt_returns_the_remaining_amount(): void
    {
        [$account] = $this->twoAccounts();

        $debt = Debt::factory()->forWorkspace($this->signedIn()->workspaces()->sole())
            ->payable()
            ->principal('1000000.00')
            ->create(['counterparty' => 'Budi']);

        $transaction = $this->payDebt($account, $debt, '250000.00');

        $this->put(route('transactions.update', $transaction), [
            'account_id' => $account->id,
            'type' => TransactionType::Expense->value,
            'amount' => '250000.00',
            'debt_id' => '',
            'occurred_at' => '2026-09-27',
        ])->assertRedirect(route('transactions.index'));

        $this->assertSame('1000000.00', $debt->fresh()->remaining);
        $this->assertSame(0, DebtPayment::allWorkspaces()->count());
        $this->assertSame('4750000.00', $account->fresh()->cached_balance);
    }

    public function test_switching_to_another_debt_moves_the_installment(): void
    {
        [$account] = $this->twoAccounts();

        $workspace = $this->signedIn()->workspaces()->sole();
        $first = Debt::factory()->forWorkspace($workspace)->payable()
            ->principal('1000000.00')
            ->create(['counterparty' => 'Budi']);
        $second = Debt::factory()->forWorkspace($workspace)->payable()
            ->principal('500000.00')
            ->create(['counterparty' => 'Siti']);

        $transaction = $this->payDebt($account, $first, '250000.00');

        $this->put(route('transactions.update', $transaction), [
            'account_id' => $account->id,
            'type' => TransactionType::Expense->value,
            'amount' => '250000.00',
            'debt_id' => $second->id,
            'occurred_at' => '2026-09-27',
        ])->assertRedirect(route('transactions.index'));

        // Utang lama utuh kembali, yang baru ikut berkurang.
        $this->assertSame('1000000.00', $first->fresh()->remaining);
        $this->assertSame('250000.00', $second->fresh()->remaining);

        $payment = DebtPayment::allWorkspaces()->sole();

        $this->assertSame($second->id, $payment->debt_id);
        $this->assertSame($transaction->id, $payment->transaction_id);
    }

    public function test_changing_a_debt_payment_into_an_income_releases_the_debt(): void
    {
        [$account] = $this->twoAccounts();

        $debt = Debt::factory()->forWorkspace($this->signedIn()->workspaces()->sole())
            ->payable()
            ->principal('1000000.00')
            ->create(['counterparty' => 'Budi']);

        $transaction = $this->payDebt($account, $debt, '250000.00');

        $this->put(route('transactions.update', $transaction), [
            'account_id' => $account->id,
            'type' => TransactionType::Income->value,
            'amount' => '250000.00',
            'debt_id' => '',
            'occurred_at' => '2026-09-27',
        ])->assertRedirect(route('transactions.index'));

        $this->assertSame('1000000.00', $debt->fresh()->remaining);
        $this->assertSame(0, DebtPayment::allWorkspaces()->count());
        $this->assertSame('5250000.00', $account->fresh()->cached_balance);
    }

    public function test_deleting_a_debt_payment_restores_the_remaining_amount(): void
    {
        [$account] = $this->twoAccounts();

        $debt = Debt::factory()->forWorkspace($this->signedIn()->workspaces()->sole())
            ->payable()
            ->principal('1000000.00')
            ->create(['counterparty' => 'Budi']);

        $transaction = $this->payDebt($account, $debt, '250000.00');

        $this->delete(route('transactions.destroy', $transaction))
            ->assertRedirect(route('transactions.index'));

        $this->assertSame(0, Transaction::allWorkspaces()->count());
        // Baris cicilan harus ikut hilang, bukan menggantung dengan
        // `transaction_id` null yang tetap memotong sisa utang.
        $this->assertSame(0, DebtPayment::allWorkspaces()->count());
        $this->assertSame('1000000.00', $debt->fresh()->remaining);
        $this->assertSame('5000000.00', $account->fresh()->cached_balance);
    }

    public function test_a_debt_payment_and_a_debt_module_installment_share_one_history(): void
    {
        [$account] = $this->twoAccounts();

        $debt = Debt::factory()->forWorkspace($this->signedIn()->workspaces()->sole())
            ->payable()
            ->principal('1000000.00')
            ->create(['counterparty' => 'Budi']);

        $this->payDebt($account, $debt, '300000.00');

        // Cicilan kedua dicatat dari modul Utang, tanpa transaksi.
        $this->post(route('debts.payments.store', $debt), [
            'amount' => '200000.00',
            'paid_at' => '2026-09-28',
        ])->assertRedirect();

        $this->assertSame('500000.00', $debt->fresh()->remaining);
        $this->assertSame(2, DebtPayment::allWorkspaces()->count());
    }

    public function test_the_debt_detail_page_shows_the_payment_from_the_transaction(): void
    {
        [$account] = $this->twoAccounts();

        $debt = Debt::factory()->forWorkspace($this->signedIn()->workspaces()->sole())
            ->payable()
            ->principal('1000000.00')
            ->create(['counterparty' => 'Budi']);

        $transaction = $this->payDebt($account, $debt, '250000.00');

        $this->get(route('debts.show', $debt))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('debts/Show')
                ->where('debt.remaining', '750000.00')
                ->has('payments', 1)
                ->where('payments.0.transaction_id', $transaction->id),
            );
    }

    public function test_viewers_cannot_pay_a_debt(): void
    {
        $owner = User::factory()->create();
        $workspace = Workspace::factory()->create(['owner_id' => $owner->id]);
        $workspace->addUser($owner, WorkspaceRole::Owner);

        $viewer = User::factory()->create();
        $workspace->addUser($viewer, WorkspaceRole::Viewer);

        $account = Account::factory()->forWorkspace($workspace)->cash('Dompet', '5000000.00')->create();
        $debt = Debt::factory()->forWorkspace($workspace)->payable()
            ->principal('1000000.00')
            ->create(['counterparty' => 'Budi']);

        $this->actingAs($viewer)
            ->withSession(['workspace_id' => $workspace->id])
            ->post(route('transactions.store'), [
                'account_id' => $account->id,
                'type' => TransactionType::Expense->value,
                'amount' => '250000.00',
                'debt_id' => $debt->id,
                'occurred_at' => '2026-09-27',
            ])
            ->assertForbidden();

        $this->assertSame(0, Transaction::allWorkspaces()->count());
        $this->assertSame('1000000.00', $debt->fresh()->remaining);
    }

    public function test_a_pending_debt_payment_is_released_when_the_instance_is_discarded(): void
    {
        [$account, $other] = $this->twoAccounts();

        $debt = Debt::factory()->forWorkspace($this->signedIn()->workspaces()->sole())
            ->payable()
            ->principal('1000000.00')
            ->create(['counterparty' => 'Budi']);

        $pending = Transaction::factory()
            ->from($account)
            ->expense('250000.00')
            ->pending()
            ->on('2026-09-27')
            ->create();

        // Tautan dibuat lewat write path yang sama seperti instance posted.
        $this->put(route('transactions.update', $pending), [
            'account_id' => $account->id,
            'type' => TransactionType::Expense->value,
            'amount' => '250000.00',
            'debt_id' => $debt->id,
            'occurred_at' => '2026-09-27',
        ])->assertRedirect(route('transactions.index'));

        $this->assertSame(TransactionStatus::Pending, $pending->fresh()->status);
        $this->assertSame('750000.00', $debt->fresh()->remaining);
        // Instance pending tidak boleh menyentuh saldo.
        $this->assertSame('5000000.00', $account->fresh()->cached_balance);

        $this->post(route('transactions.discard', $pending->fresh()))
            ->assertRedirect(route('transactions.index'));

        $this->assertSame('1000000.00', $debt->fresh()->remaining);
        $this->assertSame(0, DebtPayment::allWorkspaces()->count());
        $this->assertSame('5000000.00', $other->fresh()->cached_balance);
    }

    /*
    |--------------------------------------------------------------------------
    | Helper
    |--------------------------------------------------------------------------
    */

    private ?User $signedIn = null;

    /**
     * Akun tunai dengan saldo awal 5 juta di workspace user yang sedang masuk.
     */
    private function account(): Account
    {
        return Account::factory()
            ->forWorkspace($this->signedIn()->workspaces()->sole())
            ->cash('Dompet', '5000000.00')
            ->create();
    }

    /**
     * @return array{0: Account, 1: Account}
     */
    private function twoAccounts(): array
    {
        $workspace = $this->signedIn()->workspaces()->sole();

        return [
            Account::factory()->forWorkspace($workspace)->cash('Dompet', '5000000.00')->create(),
            Account::factory()->forWorkspace($workspace)->bank('BCA', '5000000.00')->create(),
        ];
    }

    /**
     * Catat expense yang melunasi utang lewat HTTP, lalu kembalikan barisnya.
     */
    private function payDebt(Account $account, Debt $debt, string $amount): Transaction
    {
        $this->post(route('transactions.store'), [
            'account_id' => $account->id,
            'type' => TransactionType::Expense->value,
            'amount' => $amount,
            'debt_id' => $debt->id,
            'occurred_at' => '2026-09-27',
        ])->assertRedirect(route('transactions.index'));

        $transaction = Transaction::allWorkspaces()
            ->where('type', TransactionType::Expense->value)
            ->orderByDesc('id')
            ->first();

        $this->assertInstanceOf(Transaction::class, $transaction);

        return $transaction;
    }

    /**
     * User yang sudah masuk dengan satu workspace aktif. Memoize supaya
     * beberapa helper dalam satu test tidak membuat workspace berbeda.
     */
    private function signedIn(): User
    {
        if ($this->signedIn instanceof User) {
            return $this->signedIn;
        }

        $user = User::factory()->withWorkspace('Rumah Tangga', WorkspaceRole::Owner)->create();

        $this->actingAs($user)
            ->withSession(['workspace_id' => $user->workspaces()->sole()->id]);

        return $this->signedIn = $user;
    }
}
