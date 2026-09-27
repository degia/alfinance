<?php

namespace Tests\Feature;

use App\Enums\BudgetStatus;
use App\Enums\WorkspaceRole;
use App\Jobs\RecomputeBudgetProgressJob;
use App\Models\Account;
use App\Models\Budget;
use App\Models\BudgetProgress;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Budgets\BudgetService;
use App\Support\Money;
use App\Support\MonthPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Anggaran (PRD.md §3.5).
 *
 * Dua hal yang dijaga di sini:
 * 1. angka "sudah terpakai" HANYA boleh berasal dari `budget_progress_cache`,
 *    bukan dari agregasi `transactions` saat request;
 * 2. cache itu harus benar: hanya posted expense yang dihitung, sub-kategori
 *    rollup ke induk, dan tiap perubahan transaksi memicu penghitungan ulang
 *    lewat antrean.
 */
class BudgetsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Halaman Inertia dirender lewat Vite, tapi test ini tidak perlu
        // bundle frontend yang belum dibangun.
        $this->withoutVite();
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get(route('budgets.index'))->assertRedirect(route('login'));
    }

    public function test_users_see_an_empty_matrix(): void
    {
        $this->signedIn();

        $this->get(route('budgets.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('budgets/Index')
                ->where('categories', [])
                ->has('months', 12)
                ->where('summary.total_limit', '0.00')
                ->where('summary.percent', null));
    }

    public function test_members_can_set_a_limit_for_a_category_and_month(): void
    {
        $user = $this->signedIn();
        $workspace = $user->workspaces()->sole();
        $category = Category::factory()->forWorkspace($workspace)->create(['name' => 'Makan']);

        $this->post(route('budgets.store'), [
            'category_id' => $category->id,
            'month' => '2026-09',
            'limit_amount' => '1500000.00',
        ])->assertRedirect(route('budgets.index', ['month' => '2026-09', 'year' => 2026]));

        $budget = Budget::allWorkspaces()->sole();

        $this->assertSame('1500000.00', $budget->limit_amount);
        // Bulan disimpan sebagai tanggal PERTAMA bulan supaya bisa di-range.
        $this->assertSame('2026-09-01', $budget->month->toDateString());
        $this->assertSame('2026-09', $budget->monthKey());
    }

    public function test_setting_the_same_limit_twice_updates_instead_of_duplicating(): void
    {
        $user = $this->signedIn();
        $workspace = $user->workspaces()->sole();
        $category = Category::factory()->forWorkspace($workspace)->create();

        $payload = [
            'category_id' => $category->id,
            'month' => '2026-09',
            'limit_amount' => '1000000.00',
        ];

        $this->post(route('budgets.store'), $payload);
        $this->post(route('budgets.store'), [...$payload, 'limit_amount' => '2000000.00']);

        $this->assertSame(1, Budget::allWorkspaces()->count());
        $this->assertSame('2000000.00', Budget::allWorkspaces()->sole()->limit_amount);
    }

    public function test_members_can_edit_a_limit_inline(): void
    {
        $user = $this->signedIn();
        $workspace = $user->workspaces()->sole();
        $budget = Budget::factory()
            ->forWorkspace($workspace)
            ->forCategory(Category::factory()->forWorkspace($workspace)->create())
            ->forMonth('2026-09')
            ->limit('1000000.00')
            ->create();

        $this->put(route('budgets.update', $budget), ['limit_amount' => '1750000.50'])
            ->assertRedirect();

        $this->assertSame('1750000.50', $budget->fresh()->limit_amount);
    }

    public function test_members_can_delete_a_limit(): void
    {
        $user = $this->signedIn();
        $workspace = $user->workspaces()->sole();
        $budget = Budget::factory()
            ->forWorkspace($workspace)
            ->forCategory(Category::factory()->forWorkspace($workspace)->create())
            ->forMonth('2026-09')
            ->create();

        $this->delete(route('budgets.destroy', $budget))->assertRedirect();

        $this->assertSame(0, Budget::allWorkspaces()->count());
    }

    public function test_a_category_cannot_borrow_another_workspaces_category(): void
    {
        $this->signedIn();

        $foreign = Category::factory()->create(['name' => 'Kategori Asing']);

        $this->post(route('budgets.store'), [
            'category_id' => $foreign->id,
            'month' => '2026-09',
            'limit_amount' => '100000.00',
        ])->assertSessionHasErrors('category_id');

        $this->assertSame(0, Budget::allWorkspaces()->count());
    }

    public function test_viewers_can_read_but_not_write_budgets(): void
    {
        $viewer = $this->signedInAs(WorkspaceRole::Viewer);
        $workspace = $viewer->workspaces()->sole();
        $category = Category::factory()->forWorkspace($workspace)->create();

        $this->get(route('budgets.index'))->assertOk();

        $this->post(route('budgets.store'), [
            'category_id' => $category->id,
            'month' => '2026-09',
            'limit_amount' => '100000.00',
        ])->assertForbidden();

        $this->assertSame(0, Budget::allWorkspaces()->count());
    }

    public function test_only_posted_expenses_count_towards_the_progress(): void
    {
        $user = $this->signedIn();
        $workspace = $user->workspaces()->sole();
        $account = Account::factory()->forWorkspace($workspace)->cash('Dompet')->create();
        $target = Account::factory()->forWorkspace($workspace)->cash('Lain')->create();
        $category = Category::factory()->forWorkspace($workspace)->create();
        $other = Category::factory()->forWorkspace($workspace)->create();

        $this->budgetFor($workspace, $category, '2026-09', '1000000.00');

        // Dipotong: posted expense ber-kategori yang sama.
        Transaction::factory()->forWorkspace($workspace)->from($account)
            ->expense('250000.00')->for($category)->on('2026-09-05')->create();

        // Tidak dipotong: instance pending belum menyentuh saldo.
        Transaction::factory()->forWorkspace($workspace)->from($account)
            ->expense('900000.00')->for($category)->on('2026-09-06')->pending()->create();

        // Tidak dipotong: income bukan pengeluaran.
        Transaction::factory()->forWorkspace($workspace)->from($account)
            ->income('5000000.00')->for($category)->on('2026-09-07')->create();

        // Tidak dipotong: kategori lain.
        Transaction::factory()->forWorkspace($workspace)->from($account)
            ->expense('300000.00')->for($other)->on('2026-09-08')->create();

        // Tidak dipotong: transfer antar akun internal.
        Transaction::factory()->forWorkspace($workspace)->from($account)
            ->transfer($target, '400000.00')->on('2026-09-09')->create();

        // Tidak dipotong: bulan lain.
        Transaction::factory()->forWorkspace($workspace)->from($account)
            ->expense('700000.00')->for($category)->on('2026-10-01')->create();

        $this->refreshProgress($workspace, '2026-09');

        $this->assertSame('250000.00', $this->usedFor($category, '2026-09'));
    }

    public function test_spending_in_a_sub_category_rolls_up_to_the_parent_limit(): void
    {
        $user = $this->signedIn();
        $workspace = $user->workspaces()->sole();
        $account = Account::factory()->forWorkspace($workspace)->cash()->create();

        $parent = Category::factory()->forWorkspace($workspace)->create(['name' => 'Kebutuhan Pokok']);
        $child = Category::factory()->forWorkspace($workspace)->childOf($parent)->create(['name' => 'Sayur']);
        $sibling = Category::factory()->forWorkspace($workspace)->childOf($parent)->create(['name' => 'Daging']);

        $this->budgetFor($workspace, $parent, '2026-09', '1000000.00');
        $this->budgetFor($workspace, $child, '2026-09', '200000.00');

        Transaction::factory()->forWorkspace($workspace)->from($account)
            ->expense('150000.00')->for($child)->on('2026-09-03')->create();
        Transaction::factory()->forWorkspace($workspace)->from($account)
            ->expense('100000.00')->for($sibling)->on('2026-09-04')->create();

        $this->refreshProgress($workspace, '2026-09');

        // Induk melihat seluruh subtree-nya.
        $this->assertSame('250000.00', $this->usedFor($parent, '2026-09'));
        // Sub-kategori hanya melihat transaksinya sendiri.
        $this->assertSame('150000.00', $this->usedFor($child, '2026-09'));
    }

    public function test_status_thresholds_follow_the_prd(): void
    {
        $workspace = $this->signedIn()->workspaces()->sole();
        $category = Category::factory()->forWorkspace($workspace)->create();

        $budget = $this->budgetFor($workspace, $category, '2026-09', '1000000.00');

        $this->assertSame(BudgetStatus::Healthy, $budget->status(), 'Limit terisi tanpa pemakaian tetap hijau.');

        // Satu baris cache per kategori+bulan, jadi diganti isinya — bukan
        // menambah baris baru.
        $progress = BudgetProgress::factory()->forWorkspace($workspace)
            ->forCategory($category)->forMonth('2026-09')->used('790000.00')->create();
        $budget->setRelation('progress', $progress);

        $this->assertSame(BudgetStatus::Healthy, $budget->status());
        $this->assertSame(79.0, $budget->usedPercent());

        $this->useAmount($progress, '800000.00');

        $this->assertSame(BudgetStatus::Warning, $budget->status(), 'Tepat 80% sudah kuning.');

        $this->useAmount($progress, '1000000.00');

        $this->assertSame(BudgetStatus::Warning, $budget->status(), 'Tepat 100% masih kuning.');

        $this->useAmount($progress, '1200000.00');

        $this->assertSame(BudgetStatus::Over, $budget->status());
        $this->assertSame(120.0, $budget->usedPercent(), 'Persentase tidak boleh di-cap 100.');
        $this->assertSame('-200000.00', $budget->remaining());
    }

    public function test_saving_a_transaction_refreshes_the_cached_progress(): void
    {
        $user = $this->signedIn();
        $workspace = $user->workspaces()->sole();
        $account = Account::factory()->forWorkspace($workspace)->cash('Dompet', '5000000.00')->create();
        $category = Category::factory()->forWorkspace($workspace)->create(['name' => 'Belanja']);

        $this->budgetFor($workspace, $category, '2026-09', '1000000.00');

        $this->post(route('transactions.store'), [
            'account_id' => $account->id,
            'type' => 'expense',
            'amount' => '325000.00',
            'category_id' => $category->id,
            'occurred_at' => '2026-09-12',
            'note' => 'Belanja bulanan',
        ])->assertRedirect();

        // Listener -> job -> cache, semua inline karena queue `sync` di test.
        $this->assertSame('325000.00', $this->usedFor($category, '2026-09'));
    }

    public function test_confirming_a_pending_transaction_moves_the_budget(): void
    {
        $user = $this->signedIn();
        $workspace = $user->workspaces()->sole();
        $account = Account::factory()->forWorkspace($workspace)->cash()->create();
        $category = Category::factory()->forWorkspace($workspace)->create();

        $this->budgetFor($workspace, $category, '2026-09', '1000000.00');

        $pending = Transaction::factory()->forWorkspace($workspace)->from($account)
            ->expense('100000.00')->for($category)->on('2026-09-02')->pending()->create();

        $this->assertSame('0.00', $this->usedFor($category, '2026-09'), 'Pending tidak boleh dihitung.');

        $this->post(route('transactions.confirm', $pending))->assertRedirect();

        $this->assertSame('100000.00', $this->usedFor($category, '2026-09'));
    }

    public function test_deleting_a_transaction_lowers_the_cached_progress(): void
    {
        $user = $this->signedIn();
        $workspace = $user->workspaces()->sole();
        $account = Account::factory()->forWorkspace($workspace)->cash()->create();
        $category = Category::factory()->forWorkspace($workspace)->create();

        $this->budgetFor($workspace, $category, '2026-09', '1000000.00');

        $transaction = Transaction::factory()->forWorkspace($workspace)->from($account)
            ->expense('450000.00')->for($category)->on('2026-09-02')->create();

        $this->refreshProgress($workspace, '2026-09');

        $this->assertSame('450000.00', $this->usedFor($category, '2026-09'));

        $this->delete(route('transactions.destroy', $transaction))->assertRedirect();

        $this->assertSame('0.00', $this->usedFor($category, '2026-09'));
    }

    public function test_moving_a_transaction_to_another_category_updates_both_budgets(): void
    {
        $user = $this->signedIn();
        $workspace = $user->workspaces()->sole();
        $account = Account::factory()->forWorkspace($workspace)->cash()->create();
        $from = Category::factory()->forWorkspace($workspace)->create();
        $to = Category::factory()->forWorkspace($workspace)->create();

        $this->budgetFor($workspace, $from, '2026-09', '1000000.00');
        $this->budgetFor($workspace, $to, '2026-09', '1000000.00');

        $transaction = Transaction::factory()->forWorkspace($workspace)->from($account)
            ->expense('120000.00')->for($from)->on('2026-09-04')->create();

        $this->refreshProgress($workspace, '2026-09');

        $this->assertSame('120000.00', $this->usedFor($from, '2026-09'));

        $this->put(route('transactions.update', $transaction), [
            'account_id' => $account->id,
            'type' => 'expense',
            'amount' => '120000.00',
            'category_id' => $to->id,
            'occurred_at' => '2026-09-04',
            'note' => 'Pindah kategori',
        ])->assertRedirect();

        $this->assertSame('0.00', $this->usedFor($from, '2026-09'));
        $this->assertSame('120000.00', $this->usedFor($to, '2026-09'));
    }

    public function test_the_matrix_page_reads_the_cache_instead_of_aggregating(): void
    {
        $user = $this->signedIn();
        $workspace = $user->workspaces()->sole();
        $account = Account::factory()->forWorkspace($workspace)->cash()->create();
        $category = Category::factory()->forWorkspace($workspace)->create(['name' => 'Makan']);

        $this->budgetFor($workspace, $category, '2026-09', '1000000.00');

        Transaction::factory()->forWorkspace($workspace)->from($account)
            ->expense('900000.00')->for($category)->on('2026-09-09')->create();

        $this->refreshProgress($workspace, '2026-09');

        $this->get(route('budgets.index', ['year' => 2026]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('categories', 1)
                ->where('categories.0.name', 'Makan')
                ->has('categories.0.months', 12)
                ->where('categories.0.months.8.month', '2026-09')
                ->where('categories.0.months.8.limit', '1000000.00')
                ->where('categories.0.months.8.used', '900000.00')
                // Lewat JSON, 90.0 jadi int 90.
                ->where('categories.0.months.8.percent', 90)
                ->where('categories.0.months.8.status', BudgetStatus::Warning->value)
                // Bulan tanpa limit tampil `null`, bukan 0.
                ->where('categories.0.months.0.limit', null));
    }

    public function test_the_recompute_job_is_unique_per_workspace_and_month(): void
    {
        Queue::fake();

        $this->signedIn();

        RecomputeBudgetProgressJob::dispatch(1, '2026-09');
        RecomputeBudgetProgressJob::dispatch(1, '2026-09');
        RecomputeBudgetProgressJob::dispatch(1, '2026-10');
        RecomputeBudgetProgressJob::dispatch(2, '2026-09');

        Queue::assertPushed(RecomputeBudgetProgressJob::class, 3);
    }

    public function test_progress_of_another_workspace_is_never_mixed_in(): void
    {
        $user = $this->signedIn();
        $workspace = $user->workspaces()->sole();
        $category = Category::factory()->forWorkspace($workspace)->create();

        $this->budgetFor($workspace, $category, '2026-09', '1000000.00');

        $otherWorkspace = Workspace::factory()->create();
        $foreignAccount = Account::factory()->forWorkspace($otherWorkspace)->cash()->create();
        $foreignCategory = Category::factory()->forWorkspace($otherWorkspace)->create();

        Transaction::factory()->forWorkspace($otherWorkspace)->from($foreignAccount)
            ->expense('800000.00')->for($foreignCategory)->on('2026-09-09')->create();

        $this->refreshProgress($workspace, '2026-09');

        $this->assertSame('0.00', $this->usedFor($category, '2026-09'));
    }

    public function test_recompute_touches_every_category_even_without_a_limit(): void
    {
        $user = $this->signedIn();
        $workspace = $user->workspaces()->sole();
        $account = Account::factory()->forWorkspace($workspace)->cash()->create();
        $category = Category::factory()->forWorkspace($workspace)->create();

        Transaction::factory()->forWorkspace($workspace)->from($account)
            ->expense('60000.00')->for($category)->on('2026-09-11')->create();

        $this->refreshProgress($workspace, '2026-09');

        $progress = BudgetProgress::allWorkspaces()->sole();

        $this->assertSame($category->id, $progress->category_id);
        $this->assertSame('60000.00', $progress->used_amount);
        $this->assertSame('2026-09-01', $progress->month->toDateString());
    }

    public function test_the_progress_job_does_not_roll_back_the_transaction_when_it_fails(): void
    {
        $user = $this->signedIn();
        $workspace = $user->workspaces()->sole();
        $account = Account::factory()->forWorkspace($workspace)->cash('Dompet', '1000000.00')->create();

        // Job dipanggil tanpa handler aggregate: agregasi gagal, transaksi
        // harus tetap jadi dan saldo tetap bergerak.
        RecomputeBudgetProgressJob::dispatch((int) $workspace->id, '2026-09')
            ->handle(app(BudgetService::class));

        $this->post(route('transactions.store'), [
            'account_id' => $account->id,
            'type' => 'expense',
            'amount' => '10000.00',
            'occurred_at' => '2026-09-14',
        ])->assertRedirect();

        $this->assertSame(1, Transaction::allWorkspaces()->count());
        $this->assertSame('990000.00', $account->fresh()->cached_balance);
    }

    public function test_spending_is_measured_against_the_first_day_of_the_month(): void
    {
        $workspace = $this->signedIn()->workspaces()->sole();

        $this->assertSame(
            '2026-09-01',
            MonthPeriod::from('2026-09')->toDateString(),
            'Bulan anggaran harus dinormalkan ke tanggal pertama.',
        );

        $this->assertTrue(MonthPeriod::isValid('2026-09'));
        $this->assertFalse(MonthPeriod::isValid('2026-13'), 'Bulan 13 tidak valid.');
        $this->assertFalse(MonthPeriod::isValid('Sep 2026'));

        [$from, $to] = MonthPeriod::bounds(CarbonImmutable::parse('2026-09-01'));

        $this->assertSame('2026-09-01', $from->toDateString());
        $this->assertSame('2026-09-30', $to->toDateString());
    }

    public function test_the_index_accepts_a_month_query_parameter(): void
    {
        $this->signedIn();

        $this->get(route('budgets.index', ['month' => '2026-03', 'year' => 2026]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('month', '2026-03'));
    }

    /*
    |--------------------------------------------------------------------------
    | Helper
    |--------------------------------------------------------------------------
    */

    /** Ubah pemakaian cache di tempat, lalu segarkan relasi di model budget. */
    private function useAmount(BudgetProgress $progress, string $amount): void
    {
        $progress->used_amount = $amount;
        $progress->save();
    }

    private function budgetFor(
        Workspace $workspace,
        Category $category,
        string $month,
        string $limit,
    ): Budget {
        $budget = Budget::factory()
            ->forWorkspace($workspace)
            ->forCategory($category)
            ->forMonth($month)
            ->limit($limit)
            ->create();

        $budget->setRelation('progress', null);

        return $budget;
    }

    private function refreshProgress(Workspace $workspace, string $month): void
    {
        app(BudgetService::class)->recomputeForMonth((int) $workspace->id, $month);
    }

    /**
     * Nilai cache yang tersimpan untuk satu kategori, "0.00" bila belum ada.
     *
     * Baris yang belum ada berarti agregat memang nol, jadi default-nya
     * mengikuti {@see Budget::usedAmount()}.
     */
    private function usedFor(Category $category, string $month): string
    {
        $value = BudgetProgress::allWorkspaces()
            ->where('category_id', $category->id)
            ->whereYear('month', MonthPeriod::from($month)->year)
            ->whereMonth('month', MonthPeriod::from($month)->month)
            ->value('used_amount');

        return $value === null ? Money::fromCents(0) : (string) $value;
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
