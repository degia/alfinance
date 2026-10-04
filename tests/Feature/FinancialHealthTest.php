<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\DashboardSnapshot;
use App\Models\Debt;
use App\Models\User;
use App\Services\FinancialHealth\FinancialHealthService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Skor kesehatan keuangan dihitung dari snapshot dashboard ditambah saldo
 * akun, jadi test di sini memanggil `recompute()` langsung: di produksi
 * jalurnya lewat job, sedangkan yang diuji di sini adalah aturan hitungnya.
 */
class FinancialHealthTest extends TestCase
{
    use RefreshDatabase;

    public function test_savings_accounts_are_not_counted_as_emergency_fund(): void
    {
        $user = User::factory()->withWorkspace()->create();
        $workspace = $user->workspaces()->sole();
        $month = CarbonImmutable::now()->startOfMonth();

        Account::factory()->forWorkspace($workspace)->cash('Dompet Tunai', '3000000.00')->create();
        Account::factory()->forWorkspace($workspace)->saving('Tabungan', '9000000.00')->create();

        DashboardSnapshot::factory()
            ->forWorkspace($workspace)
            ->forMonth($month->format('Y-m'))
            ->totals('6000000.00', '1000000.00')
            ->create();

        $score = app(FinancialHealthService::class)->recompute($workspace->id, $month);

        // Dana darurat hanya menghitung kas yang siap dibelanjakan:
        // 3 juta / rata-rata expense 1 juta = 3 bulan. Kalau akun tabungan ikut
        // dihitung, hasilnya 12 bulan.
        $this->assertSame('3.00', $score->emergency_fund_months);
    }

    public function test_monthly_installments_follow_the_installment_amount(): void
    {
        $user = User::factory()->withWorkspace()->create();
        $workspace = $user->workspaces()->sole();
        $month = CarbonImmutable::now()->startOfMonth();

        Account::factory()->forWorkspace($workspace)->cash('Dompet Tunai', '2000000.00')->create();

        DashboardSnapshot::factory()
            ->forWorkspace($workspace)
            ->forMonth($month->format('Y-m'))
            ->totals('6000000.00', '1000000.00')
            ->create();

        // Pokok 12 juta, tenor 12 bulan, tapi angsuran yang disepakati 500
        // ribu. Rasio harus memakai 500 ribu (8,33%), bukan hasil bagi yang
        // 1 juta (16,67%).
        Debt::factory()
            ->forWorkspace($workspace)
            ->payable()
            ->principal('12000000.00')
            ->terms(12)
            ->installment('500000.00')
            ->create();

        $score = app(FinancialHealthService::class)->recompute($workspace->id, $month);

        $this->assertSame('8.33', $score->dti);
    }

    public function test_credit_card_debt_is_not_counted_as_emergency_fund(): void
    {
        $user = User::factory()->withWorkspace()->create();
        $workspace = $user->workspaces()->sole();
        $month = CarbonImmutable::now()->startOfMonth();

        Account::factory()->forWorkspace($workspace)->cash('Dompet Tunai', '3000000.00')->create();
        $card = Account::factory()->forWorkspace($workspace)->creditCard('Kartu Utama', '5000000.00')->create();

        // Saldo negatif = utang dari belanja kartu kredit. Diberi langsung ke
        // kolomnya supaya test ini tetap murni soal aturan liquid cash;
        // perpindahan saldo karena transaksi sudah punya testnya sendiri.
        $card->cached_balance = '-1000000.00';
        $card->save();

        DashboardSnapshot::factory()
            ->forWorkspace($workspace)
            ->forMonth($month->format('Y-m'))
            ->totals('6000000.00', '1000000.00')
            ->create();

        $score = app(FinancialHealthService::class)->recompute($workspace->id, $month);

        // Utang kartu kredit bukan dana darurat: 3 juta / 1 juta = 3 bulan.
        $this->assertSame('3.00', $score->emergency_fund_months);
    }
}
