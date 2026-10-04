<?php

namespace Tests\Feature;

use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Enums\WorkspaceRole;
use App\Models\Account;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Transactions\AdminFeeManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Potongan admin hanya berlaku untuk transfer, dan dicatat sebagai baris
 * `expense` tersendiri pada akun sumber dengan kategori "Biaya Admin".
 *
 * Tiga jaminan yang diuji di sini:
 * - saldo akun sumber berkurang sebesar nominal transfer PLUS potongan admin,
 *   sedangkan akun tujuan hanya bertambah sebesar nominal transfer;
 * - baris biaya admin terikat pada transfer induknya, jadi edit/hapus transfer
 *   ikut mengubah dan membersihkan baris tersebut;
 * - kategori "Biaya Admin" dibuat otomatis sekali per workspace.
 *
 * Semua angka di bawah mengikuti saldo awal 5.000.000 per akun.
 */
class TransactionAdminFeeTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_transfer_with_an_admin_fee_records_an_extra_expense(): void
    {
        [$source, $target] = $this->twoAccounts();

        $this->post(route('transactions.store'), [
            'account_id' => $source->id,
            'transfer_to_account_id' => $target->id,
            'type' => TransactionType::Transfer->value,
            'amount' => '1000000.00',
            'admin_fee' => '25000.50',
            'occurred_at' => '2026-09-27',
            'note' => 'Top up e-wallet',
        ])->assertRedirect(route('transactions.index'));

        // Sumber keluar nominal transfer + potongan admin, tujuan hanya dapat
        // nominal transfer.
        $this->assertSame('3974999.50', $source->fresh()->cached_balance);
        $this->assertSame('6000000.00', $target->fresh()->cached_balance);

        $transfer = Transaction::allWorkspaces()
            ->where('type', TransactionType::Transfer->value)
            ->sole();

        $fee = Transaction::allWorkspaces()
            ->whereNotNull('parent_transaction_id')
            ->sole();

        $this->assertSame($transfer->id, $fee->parent_transaction_id);
        $this->assertSame(TransactionType::Expense, $fee->type);
        $this->assertSame(TransactionStatus::Posted, $fee->status);
        $this->assertSame('25000.50', $fee->amount);
        // Biaya admin keluar dari akun sumber, bukan dari akun tujuan.
        $this->assertSame($source->id, $fee->account_id);
        $this->assertNull($fee->transfer_to_account_id);
        $this->assertSame('2026-09-27', $fee->occurred_at->toDateString());

        // Kategori dibaca lewat `allWorkspaces()` karena `ActiveWorkspace`
        // sudah dibongkar setelah request selesai.
        $category = Category::allWorkspaces()->find($fee->category_id);

        $this->assertNotNull($category);
        $this->assertSame(AdminFeeManager::CATEGORY_NAME, $category->name);
    }

    public function test_the_admin_fee_category_is_created_once_and_reused(): void
    {
        [$source, $target] = $this->twoAccounts();

        $this->createTransfer($source, $target, '100000.00', '5000.00');
        $this->createTransfer($source, $target, '100000.00', '7500.00');

        $workspace = $this->signedInUser()->workspaces()->sole();

        $categories = Category::allWorkspaces()
            ->where('workspace_id', $workspace->id)
            ->where('name', AdminFeeManager::CATEGORY_NAME)
            ->get();

        $this->assertCount(1, $categories);

        $fees = Transaction::allWorkspaces()->whereNotNull('parent_transaction_id')->get();

        $this->assertCount(2, $fees);
        $this->assertSame(
            [$categories->first()->id, $categories->first()->id],
            $fees->pluck('category_id')->all(),
        );
    }

    public function test_an_income_cannot_carry_an_admin_fee(): void
    {
        $account = Account::factory()
            ->forWorkspace($this->signedInUser()->workspaces()->sole())
            ->cash('Dompet', '5000000.00')
            ->create();

        $this->from(route('transactions.create'))->post(route('transactions.store'), [
            'account_id' => $account->id,
            'type' => TransactionType::Income->value,
            'amount' => '250000.00',
            'admin_fee' => '5000.00',
            'occurred_at' => '2026-09-27',
        ])->assertSessionHasErrors('admin_fee');

        $this->assertSame(0, Transaction::allWorkspaces()->count());
        $this->assertSame('5000000.00', $account->fresh()->cached_balance);
    }

    public function test_an_expense_cannot_carry_an_admin_fee(): void
    {
        $account = Account::factory()
            ->forWorkspace($this->signedInUser()->workspaces()->sole())
            ->cash('Dompet', '5000000.00')
            ->create();

        $this->from(route('transactions.create'))->post(route('transactions.store'), [
            'account_id' => $account->id,
            'type' => TransactionType::Expense->value,
            'amount' => '25000.00',
            'admin_fee' => '5000.00',
            'occurred_at' => '2026-09-27',
        ])->assertSessionHasErrors('admin_fee');
    }

    public function test_the_admin_fee_must_be_a_positive_two_decimal_value(): void
    {
        [$source, $target] = $this->twoAccounts();

        $this->from(route('transactions.create'))->post(route('transactions.store'), [
            'account_id' => $source->id,
            'transfer_to_account_id' => $target->id,
            'type' => TransactionType::Transfer->value,
            'amount' => '100000.00',
            'admin_fee' => '0',
            'occurred_at' => '2026-09-27',
        ])->assertSessionHasErrors('admin_fee');

        $this->from(route('transactions.create'))->post(route('transactions.store'), [
            'account_id' => $source->id,
            'transfer_to_account_id' => $target->id,
            'type' => TransactionType::Transfer->value,
            'amount' => '100000.00',
            'admin_fee' => '1.234',
            'occurred_at' => '2026-09-27',
        ])->assertSessionHasErrors('admin_fee');

        $this->assertSame(0, Transaction::allWorkspaces()->count());
        $this->assertSame('5000000.00', $source->fresh()->cached_balance);
        $this->assertSame('5000000.00', $target->fresh()->cached_balance);
    }

    public function test_editing_the_admin_fee_moves_the_balance_by_the_difference(): void
    {
        [$source, $target] = $this->twoAccounts();

        $transfer = $this->createTransfer($source, $target, '100000.00', '5000.00');

        $this->assertSame('4895000.00', $source->fresh()->cached_balance);

        $this->put(route('transactions.update', $transfer), [
            'account_id' => $source->id,
            'transfer_to_account_id' => $target->id,
            'type' => TransactionType::Transfer->value,
            'amount' => '100000.00',
            'admin_fee' => '8000.00',
            'occurred_at' => '2026-09-27',
        ])->assertRedirect(route('transactions.index'));

        $this->assertSame('4892000.00', $source->fresh()->cached_balance);
        $this->assertSame('5100000.00', $target->fresh()->cached_balance);
        $this->assertSame(
            1,
            Transaction::allWorkspaces()->whereNotNull('parent_transaction_id')->count(),
        );

        $this->assertSame(
            '8000.00',
            Transaction::allWorkspaces()->whereNotNull('parent_transaction_id')->sole()->amount,
        );
    }

    public function test_clearing_the_admin_fee_removes_the_expense_row(): void
    {
        [$source, $target] = $this->twoAccounts();

        $transfer = $this->createTransfer($source, $target, '100000.00', '5000.00');

        $this->put(route('transactions.update', $transfer), [
            'account_id' => $source->id,
            'transfer_to_account_id' => $target->id,
            'type' => TransactionType::Transfer->value,
            'amount' => '100000.00',
            'admin_fee' => '',
            'occurred_at' => '2026-09-27',
        ])->assertRedirect(route('transactions.index'));

        $this->assertSame(
            0,
            Transaction::allWorkspaces()->whereNotNull('parent_transaction_id')->count(),
        );
        $this->assertSame('4900000.00', $source->fresh()->cached_balance);
        $this->assertSame('5100000.00', $target->fresh()->cached_balance);
    }

    public function test_changing_a_transfer_into_an_expense_removes_the_admin_fee_row(): void
    {
        [$source, $target] = $this->twoAccounts();

        $transfer = $this->createTransfer($source, $target, '100000.00', '5000.00');

        $this->put(route('transactions.update', $transfer), [
            'account_id' => $source->id,
            'transfer_to_account_id' => '',
            'type' => TransactionType::Expense->value,
            'amount' => '100000.00',
            'admin_fee' => '',
            'occurred_at' => '2026-09-27',
        ])->assertRedirect(route('transactions.index'));

        $this->assertSame(
            0,
            Transaction::allWorkspaces()->whereNotNull('parent_transaction_id')->count(),
        );
        // Nominal 100rb tetap keluar dari akun sumber (transfer -> expense),
        // sedangkan 5rb potongan admin dikembalikan karena baris biayanya
        // ikut terhapus.
        $this->assertSame('4900000.00', $source->fresh()->cached_balance);
        $this->assertSame('5000000.00', $target->fresh()->cached_balance);
    }

    public function test_changing_the_source_account_moves_the_admin_fee_too(): void
    {
        [$source, $target] = $this->twoAccounts();

        $other = Account::factory()
            ->forWorkspace($this->signedInUser()->workspaces()->sole())
            ->cash('Dompet cadangan', '5000000.00')
            ->create();

        $transfer = $this->createTransfer($source, $target, '100000.00', '5000.00');

        $this->put(route('transactions.update', $transfer), [
            'account_id' => $other->id,
            'transfer_to_account_id' => $target->id,
            'type' => TransactionType::Transfer->value,
            'amount' => '100000.00',
            'admin_fee' => '5000.00',
            'occurred_at' => '2026-09-27',
        ])->assertRedirect(route('transactions.index'));

        $this->assertSame('5000000.00', $source->fresh()->cached_balance);
        $this->assertSame('4895000.00', $other->fresh()->cached_balance);
        $this->assertSame('5100000.00', $target->fresh()->cached_balance);

        $this->assertSame(
            $other->id,
            Transaction::allWorkspaces()->whereNotNull('parent_transaction_id')->sole()->account_id,
        );
    }

    public function test_deleting_a_transfer_also_removes_its_admin_fee_row(): void
    {
        [$source, $target] = $this->twoAccounts();

        $transfer = $this->createTransfer($source, $target, '100000.00', '5000.00');

        $this->delete(route('transactions.destroy', $transfer))
            ->assertRedirect(route('transactions.index'));

        $this->assertSame(0, Transaction::allWorkspaces()->count());
        $this->assertSame('5000000.00', $source->fresh()->cached_balance);
        $this->assertSame('5000000.00', $target->fresh()->cached_balance);
    }

    public function test_a_pending_transfer_keeps_the_admin_fee_pending_until_confirmed(): void
    {
        [$source, $target] = $this->twoAccounts();

        $transfer = $this->pendingTransfer($source, $target);

        $this->put(route('transactions.update', $transfer), [
            'account_id' => $source->id,
            'transfer_to_account_id' => $target->id,
            'type' => TransactionType::Transfer->value,
            'amount' => '100000.00',
            'admin_fee' => '5000.00',
            'occurred_at' => '2026-09-27',
        ])->assertRedirect(route('transactions.index'));

        // Instance pending belum boleh menyentuh saldo, termasuk biayanya.
        $this->assertSame('5000000.00', $source->fresh()->cached_balance);
        $this->assertSame(
            TransactionStatus::Pending,
            Transaction::allWorkspaces()->whereNotNull('parent_transaction_id')->sole()->status,
        );

        $this->post(route('transactions.confirm', $transfer))
            ->assertRedirect(route('transactions.index'));

        $this->assertSame('4895000.00', $source->fresh()->cached_balance);
        $this->assertSame('5100000.00', $target->fresh()->cached_balance);
        $this->assertSame(
            TransactionStatus::Posted,
            Transaction::allWorkspaces()->whereNotNull('parent_transaction_id')->sole()->status,
        );
    }

    public function test_discarding_a_pending_transfer_removes_its_admin_fee_row(): void
    {
        [$source, $target] = $this->twoAccounts();

        $transfer = $this->pendingTransfer($source, $target);

        $this->put(route('transactions.update', $transfer), [
            'account_id' => $source->id,
            'transfer_to_account_id' => $target->id,
            'type' => TransactionType::Transfer->value,
            'amount' => '100000.00',
            'admin_fee' => '5000.00',
            'occurred_at' => '2026-09-27',
        ]);

        $this->post(route('transactions.discard', $transfer))
            ->assertRedirect(route('transactions.index'));

        $this->assertSame(0, Transaction::allWorkspaces()->count());
        $this->assertSame('5000000.00', $source->fresh()->cached_balance);
    }

    public function test_viewers_cannot_record_an_admin_fee(): void
    {
        $owner = User::factory()->create();
        $workspace = Workspace::factory()->create(['owner_id' => $owner->id]);
        $workspace->addUser($owner, WorkspaceRole::Owner);

        $viewer = User::factory()->create();
        $workspace->addUser($viewer, WorkspaceRole::Viewer);

        $source = Account::factory()->forWorkspace($workspace)->cash('Dompet', '5000000.00')->create();
        $target = Account::factory()->forWorkspace($workspace)->bank('BCA', '5000000.00')->create();

        $this->actingAs($viewer)
            ->withSession(['workspace_id' => $workspace->id])
            ->post(route('transactions.store'), [
                'account_id' => $source->id,
                'transfer_to_account_id' => $target->id,
                'type' => TransactionType::Transfer->value,
                'amount' => '100000.00',
                'admin_fee' => '5000.00',
                'occurred_at' => '2026-09-27',
            ])
            ->assertForbidden();

        $this->assertSame(0, Transaction::allWorkspaces()->count());
        $this->assertSame('5000000.00', $source->fresh()->cached_balance);
    }

    /*
    |--------------------------------------------------------------------------
    | Helper
    |--------------------------------------------------------------------------
    */

    private ?User $signedIn = null;

    /**
     * Dua akun dengan saldo awal 5 juta di workspace user yang sedang masuk.
     *
     * @return array{0: Account, 1: Account}
     */
    private function twoAccounts(): array
    {
        $workspace = $this->signedInUser()->workspaces()->sole();

        return [
            Account::factory()->forWorkspace($workspace)->cash('Dompet', '5000000.00')->create(),
            Account::factory()->forWorkspace($workspace)->bank('BCA', '5000000.00')->create(),
        ];
    }

    /**
     * Simpan sebuah transfer lewat HTTP dan kembalikan baris transfernya.
     */
    private function createTransfer(Account $source, Account $target, string $amount, ?string $adminFee): Transaction
    {
        $this->post(route('transactions.store'), [
            'account_id' => $source->id,
            'transfer_to_account_id' => $target->id,
            'type' => TransactionType::Transfer->value,
            'amount' => $amount,
            'admin_fee' => $adminFee,
            'occurred_at' => '2026-09-27',
        ])->assertRedirect(route('transactions.index'));

        $transfer = Transaction::allWorkspaces()
            ->where('type', TransactionType::Transfer->value)
            ->orderByDesc('id')
            ->first();

        $this->assertInstanceOf(Transaction::class, $transfer);

        return $transfer;
    }

    /**
     * Transfer pending — bentuk instance transaksi berulang yang menunggu
     * konfirmasi, jadi pengujiannya tidak perlu menyiapkan rule berulang.
     */
    private function pendingTransfer(Account $source, Account $target): Transaction
    {
        return Transaction::factory()
            ->from($source)
            ->transfer($target, '100000.00')
            ->pending()
            ->on('2026-09-27')
            ->create();
    }

    /**
     * User yang sudah masuk dengan satu workspace aktif. Memoized supaya
     * beberapa helper dalam satu test tidak membuat workspace berbeda.
     */
    private function signedInUser(): User
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
