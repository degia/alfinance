<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\BudgetProgress;
use App\Models\Category;
use App\Models\DashboardDailySnapshot;
use App\Models\DashboardSnapshot;
use App\Models\FinancialHealthScore;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SyncDashboardCommandTest extends TestCase
{
    use RefreshDatabase;

    /*
    |--------------------------------------------------------------------------
    | dashboard:sync — jaring pengaman agregat dashboard (backfill manual)
    |--------------------------------------------------------------------------
    | Jalur normal membangun ulang snapshot lewat job antrean; perintah ini
    | dipakai saat antrean tidak sempat berjalan sehingga snapshot basi.
    | Ketiganya diuji di sini: snapshot bulanan + harian ikut dibangun ulang,
    | progress anggaran (sumber donut) ikut disegarkan, dan skor kesehatan
    | ikut dihitung.
    */

    public function test_the_command_rebuilds_stale_snapshots_from_transactions(): void
    {
        [$workspace, $account] = $this->workspaceWithCash();
        $month = CarbonImmutable::parse('2026-09-01');

        Transaction::factory()->from($account)->income('3000000.00')->on('2026-09-02')->create();
        Transaction::factory()->from($account)->expense('650000.00')->on('2026-09-02')->create();
        Transaction::factory()->from($account)->expense('75000.00')->on('2026-09-05')->create();

        // Snapshot basi dengan angka yang sengaja salah — seolah-olah worker
        // mati sebelum transaksi terakhir masuk.
        DashboardSnapshot::factory()
            ->forWorkspace($workspace)
            ->forMonth('2026-09')
            ->totals('1000000.00', '500000.00')
            ->create(['transaction_count' => 1]);

        $this->artisan('dashboard:sync')
            ->assertExitCode(0);

        $snapshot = DashboardSnapshot::allWorkspaces()
            ->where('workspace_id', $workspace->id)
            ->ofMonth($month)
            ->firstOrFail();

        $this->assertSame('3000000.00', $snapshot->total_income);
        $this->assertSame('725000.00', $snapshot->total_expense);
        $this->assertSame('2275000.00', $snapshot->net_cash_flow);
        $this->assertSame(3, $snapshot->transaction_count);

        // Baris harian dibangun ulang dari transaksi, bukan dari baris lama.
        $days = DashboardDailySnapshot::allWorkspaces()
            ->where('workspace_id', $workspace->id)
            ->orderBy('date')
            ->get();

        $this->assertCount(2, $days);
        $this->assertSame('2026-09-02', $days[0]->dateKey());
        $this->assertSame(2, $days[0]->transaction_count);
        $this->assertSame('3000000.00', $days[0]->total_income);
        $this->assertSame('650000.00', $days[0]->total_expense);
        $this->assertSame('2026-09-05', $days[1]->dateKey());
        $this->assertSame('75000.00', $days[1]->total_expense);
    }

    public function test_the_command_refreshes_budget_progress_and_health_score(): void
    {
        [$workspace, $account] = $this->workspaceWithCash();
        $month = CarbonImmutable::now()->startOfMonth();

        $category = Category::factory()->forWorkspace($workspace)->create();

        Transaction::factory()->from($account)->income('2000000.00')->on($month->toDateString())->create();
        Transaction::factory()
            ->from($account)
            ->expense('400000.00')
            ->on($month->toDateString())
            ->state(['category_id' => $category->id])
            ->create();

        // Progress anggaran yang basi divisualisasikan sebagai baris nol dari
        // snapshot lama, lalu harus diganti angka transaksi sebenarnya.
        DashboardSnapshot::factory()
            ->forWorkspace($workspace)
            ->forMonth($month->format('Y-m'))
            ->totals('0.00', '0.00')
            ->create(['transaction_count' => 0]);

        $this->artisan('dashboard:sync')->assertExitCode(0);

        $snapshot = DashboardSnapshot::allWorkspaces()
            ->where('workspace_id', $workspace->id)
            ->ofMonth($month)
            ->firstOrFail();

        $this->assertSame('2000000.00', $snapshot->total_income);
        $this->assertSame('400000.00', $snapshot->total_expense);

        // Donut komposisi pengeluaran membaca budget_progress_cache: baris
        // pemakaian kategori ini harus terisi nomimal transaksi sebenarnya.
        $progress = BudgetProgress::allWorkspaces()
            ->where('workspace_id', $workspace->id)
            ->where('category_id', $category->id)
            ->whereYear('month', $month->year)
            ->whereMonth('month', $month->month)
            ->firstOrFail();

        $this->assertSame('400000.00', $progress->used_amount);

        // Skor kesehatan ikut tertulis, tidak lagi kosong.
        $this->assertTrue(
            FinancialHealthScore::allWorkspaces()
                ->where('workspace_id', $workspace->id)
                ->ofMonth($month)
                ->exists(),
        );
    }

    public function test_the_command_honours_workspace_and_month_filters(): void
    {
        [$workspaceA, $accountA] = $this->workspaceWithCash();
        [$workspaceB, $accountB] = $this->workspaceWithCash();

        $september = CarbonImmutable::parse('2026-09-01');
        $october = CarbonImmutable::parse('2026-10-01');

        Transaction::factory()->from($accountA)->income('1000000.00')->on('2026-09-10')->create();
        Transaction::factory()->from($accountA)->income('500000.00')->on('2026-10-10')->create();
        Transaction::factory()->from($accountB)->income('3000000.00')->on('2026-09-20')->create();

        $this->artisan('dashboard:sync')
            ->assertExitCode(0);

        // Tanpa filter: dua workspace ikut tersinkronkan.
        $this->assertNotNull(
            DashboardSnapshot::allWorkspaces()->where('workspace_id', $workspaceA->id)->ofMonth($september)->first(),
        );
        $this->assertNotNull(
            DashboardSnapshot::allWorkspaces()->where('workspace_id', $workspaceA->id)->ofMonth($october)->first(),
        );
        $this->assertNotNull(
            DashboardSnapshot::allWorkspaces()->where('workspace_id', $workspaceB->id)->ofMonth($september)->first(),
        );

        // Filter `--workspace` membatasi cakupan, `--month` membatasi bulan.
        $this->artisan('dashboard:sync', [
            '--workspace' => [$workspaceA->id],
            '--month' => ['2026-09'],
        ])->assertExitCode(0);

        $this->assertSame('1000000.00', DashboardSnapshot::allWorkspaces()
            ->where('workspace_id', $workspaceA->id)
            ->ofMonth($september)
            ->firstOrFail()->total_income);

        // Workspace B tidak tersentuh oleh perintah terakhir: snapshotnya sama
        // seperti hasil run pertama.
        $this->assertSame('3000000.00', DashboardSnapshot::allWorkspaces()
            ->where('workspace_id', $workspaceB->id)
            ->ofMonth($september)
            ->firstOrFail()->total_income);
    }

    /**
     * @return array{0: Workspace, 1: Account}
     */
    private function workspaceWithCash(): array
    {
        $user = User::factory()->withWorkspace()->create();
        $workspace = $user->workspaces()->sole();

        $account = Account::factory()->forWorkspace($workspace)->cash('Dompet Tunai')->create();

        return [$workspace, $account];
    }
}
