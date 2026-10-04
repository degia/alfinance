<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\DashboardDailySnapshot;
use App\Models\DashboardSnapshot;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Dashboard\DashboardAggregator;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_page()
    {
        $response = $this->get(route('dashboard'));
        $response->assertRedirect(route('login'));
    }

    public function test_authenticated_users_can_visit_the_dashboard()
    {
        $user = User::factory()->withWorkspace()->create();
        $this->actingAs($user);

        $response = $this->get(route('dashboard'));
        $response->assertOk();
    }

    public function test_the_total_balance_card_excludes_savings_accounts()
    {
        $user = User::factory()->withWorkspace()->create();
        $workspace = $user->workspaces()->sole();

        $this->actingAs($user)->withSession(['workspace_id' => $workspace->id]);

        Account::factory()->forWorkspace($workspace)->cash('Dompet Tunai', '450000.00')->create();
        Account::factory()->forWorkspace($workspace)->bank('BCA', '1250000.00')->create();
        Account::factory()->forWorkspace($workspace)->saving('Tabungan', '9000000.00')->create();

        // Angka di kartu "Saldo total" = kas + bank saja. Akun tabungan tetap
        // tampil di halaman Akun, tapi uangnya sudah disisihkan.
        $this->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('kpi.total_balance', '1700000.00'),
            );
    }

    public function test_users_without_a_workspace_are_redirected_to_workspace_selection()
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $response = $this->get(route('dashboard'));
        $response->assertRedirect(route('workspaces.index'));
    }

    /*
    |--------------------------------------------------------------------------
    | Rekap harian (line chart dashboard)
    |--------------------------------------------------------------------------
    | Diuji lewat `DashboardAggregator` secara langsung: di produksi jalur ini
    | ada di dalam job, sedangkan yang relevan di sini adalah aturan pengelompokan
    | per tanggal dan bentuk payload yang sampai ke halaman.
    */

    public function test_daily_rows_group_transactions_by_date(): void
    {
        [$workspace, $account] = $this->workspaceWithCash();
        $month = CarbonImmutable::parse('2026-09-01');

        Transaction::factory()->from($account)->income('3000000.00')->on('2026-09-02')->create();
        // Dua pengeluaran di tanggal sama harus dijumlahkan, bukan menimpa.
        Transaction::factory()->from($account)->expense('150000.00')->on('2026-09-02')->create();
        Transaction::factory()->from($account)->expense('500000.00')->on('2026-09-02')->create();
        Transaction::factory()->from($account)->expense('75000.00')->on('2026-09-05')->create();

        app(DashboardAggregator::class)->recomputeForMonth($workspace->id, $month);

        $days = DashboardDailySnapshot::allWorkspaces()
            ->where('workspace_id', $workspace->id)
            ->orderBy('date')
            ->get();

        $this->assertCount(2, $days);

        $this->assertSame('2026-09-02', $days[0]->dateKey());
        $this->assertSame('3000000.00', $days[0]->total_income);
        $this->assertSame('650000.00', $days[0]->total_expense);
        $this->assertSame('2350000.00', $days[0]->net_cash_flow);
        $this->assertSame(3, $days[0]->transaction_count);

        $this->assertSame('2026-09-05', $days[1]->dateKey());
        $this->assertSame('0.00', $days[1]->total_income);
        $this->assertSame('75000.00', $days[1]->total_expense);
    }

    public function test_monthly_totals_stay_identical_after_the_grouping_change(): void
    {
        [$workspace, $account] = $this->workspaceWithCash();
        $month = CarbonImmutable::parse('2026-09-01');
        $target = Account::factory()->forWorkspace($workspace)->bank('BCA')->create();

        Transaction::factory()->from($account)->income('3000000.00')->on('2026-09-02')->create();
        Transaction::factory()->from($account)->expense('650000.00')->on('2026-09-02')->create();
        Transaction::factory()->from($account)->transfer($target, '400000.00')->on('2026-09-03')->create();
        // Pending tidak boleh masuk agregat, sama seperti sebelumnya.
        Transaction::factory()->from($account)->expense('99000.00')->on('2026-09-03')->pending()->create();

        $snapshot = app(DashboardAggregator::class)->recomputeForMonth($workspace->id, $month);

        // Snapshot bulanan kini dijumlahkan dari baris harian, jadi ini sekaligus
        // jadi regression check bahwa total tidak bergeser.
        $this->assertSame('3000000.00', $snapshot->total_income);
        $this->assertSame('650000.00', $snapshot->total_expense);
        $this->assertSame('400000.00', $snapshot->total_transfer);
        $this->assertSame('2350000.00', $snapshot->net_cash_flow);
        $this->assertSame(3, $snapshot->transaction_count);

        // Total harian harus sama dengan total bulanan — kalau tidak, salah satu
        // dari keduanya menghitung ulang dari sumber yang berbeda.
        $daily = DashboardDailySnapshot::allWorkspaces()
            ->where('workspace_id', $workspace->id)
            ->get();

        $this->assertSame(
            (float) $snapshot->total_income,
            (float) $daily->sum('total_income'),
        );
        $this->assertSame(
            (float) $snapshot->total_expense,
            (float) $daily->sum('total_expense'),
        );
    }

    public function test_recomputing_removes_daily_rows_whose_transactions_are_gone(): void
    {
        [$workspace, $account] = $this->workspaceWithCash();
        $month = CarbonImmutable::parse('2026-09-01');

        $transaction = Transaction::factory()
            ->from($account)
            ->expense('75000.00')
            ->on('2026-09-05')
            ->create();

        $aggregator = app(DashboardAggregator::class);
        $aggregator->recomputeForMonth($workspace->id, $month);

        $this->assertSame(1, DashboardDailySnapshot::allWorkspaces()->where('workspace_id', $workspace->id)->count());

        $transaction->delete();
        $aggregator->recomputeForMonth($workspace->id, $month);

        // Upsert saja akan meninggalkan baris lama; tanggal tanpa transaksi harus
        // hilang supaya tabel tidak menumpuk baris yang tidak pernah dihitung ulang.
        $this->assertSame(0, DashboardDailySnapshot::allWorkspaces()->where('workspace_id', $workspace->id)->count());
    }

    public function test_the_daily_chart_has_one_point_per_day_of_the_month(): void
    {
        [$workspace, $account] = $this->workspaceWithCash();
        $month = CarbonImmutable::parse('2026-09-01');

        // Baris harian sengaja dibangun dari transaksi + agregator, bukan di-seed
        // langsung ke tabel agregat: agregator adalah satu-satunya penulisnya, dan
        // di sini `QUEUE_CONNECTION` = sync sehingga job KPI ikut berjalan inline.
        Transaction::factory()->from($account)->income('3000000.00')->on('2026-09-02')->create();
        Transaction::factory()->from($account)->expense('650000.00')->on('2026-09-02')->create();

        app(DashboardAggregator::class)->recomputeForMonth($workspace->id, $month);

        $this->get(route('dashboard', ['month' => '2026-09']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('daily_cash_flow.month', '2026-09')
                ->where('daily_cash_flow.days', 30)
                ->where('daily_cash_flow.is_pending', false)
                // September: 30 titik, dan hanya tanggal 2 yang punya data.
                ->has('daily_cash_flow.points', 30)
                ->where('daily_cash_flow.points.1.income', '3000000.00')
                ->where('daily_cash_flow.points.1.expense', '650000.00')
                ->where('daily_cash_flow.points.1.has_data', true)
                // Hari tanpa transaksi ikut ada supaya garis tidak berlubang.
                ->where('daily_cash_flow.points.0.income', '0.00')
                ->where('daily_cash_flow.points.0.has_data', false)
                ->where('daily_cash_flow.total_income', '3000000.00')
                ->where('daily_cash_flow.total_expense', '650000.00'),
            );
    }

    public function test_the_daily_chart_is_pending_until_the_aggregate_exists(): void
    {
        $user = User::factory()->withWorkspace()->create();
        $workspace = $user->workspaces()->sole();

        // Aggregate bulanan sudah ada (workspace lama), tapi agregat harian belum.
        DashboardSnapshot::factory()
            ->forWorkspace($workspace)
            ->forMonth(CarbonImmutable::now()->format('Y-m'))
            ->create();

        $this->actingAs($user)->withSession(['workspace_id' => $workspace->id])
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                // Nol, bukan null: chart harus punya sumbu waktu penuh walau data
                // belum dihitung.
                ->where('daily_cash_flow.is_pending', true)
                ->where('daily_cash_flow.total_income', '0.00')
                ->has('daily_cash_flow.points', CarbonImmutable::now()->daysInMonth),
            );
    }

    /**
     * @return array{0: Workspace, 1: Account}
     */
    private function workspaceWithCash(): array
    {
        $user = User::factory()->withWorkspace()->create();
        $workspace = $user->workspaces()->sole();

        $this->actingAs($user)->withSession(['workspace_id' => $workspace->id]);

        $account = Account::factory()->forWorkspace($workspace)->cash('Dompet Tunai')->create();

        return [$workspace, $account];
    }
}
