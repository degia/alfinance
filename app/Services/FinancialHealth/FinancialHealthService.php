<?php

namespace App\Services\FinancialHealth;

use App\Enums\FinancialHealthLabel;
use App\Jobs\RecomputeFinancialHealthJob;
use App\Models\Account;
use App\Models\BudgetProgress;
use App\Models\DashboardSnapshot;
use App\Models\Debt;
use App\Models\FinancialHealthScore;
use App\Services\Cache\CacheContext;
use App\Services\Cache\WorkspaceCache;
use App\Support\Money;
use App\Support\MonthPeriod;
use Carbon\CarbonImmutable;

/**
 * Skor kesehatan finansial (PRD.md §3.9).
 *
 * Terpisah menjadi dua arah dengan sengaja:
 * - {@see current()} = read path, hanya membaca cache + tabel
 *   `financial_health_scores`.
 * - {@see recompute()} = write path, dipanggil dari
 *   {@see RecomputeFinancialHealthJob} dan boleh membaca tabel agregat.
 *
 * Tiga metrik dan targetnya:
 * - Savings Rate = (Income − Expense) / Income → ideal ≥ 20%.
 * - Debt-to-Income = total cicilan bulanan / Income → ideal ≤ 30%.
 * - Emergency Fund = kas likuid / rata-rata expense bulanan → ideal 3–6 bulan.
 *
 * Karena skor disimpan per bulan, angka bulan yang sudah lewat tidak ikut
 * berubah ketika transaksi bulan ini masih berjalan — dan itu juga alasan
 * perhitungan lengkapnya ada di job, bukan di dalam request.
 */
class FinancialHealthService
{
    /**
     * Target metrik (PRD.md §3.9).
     */
    public const TARGET_SAVINGS_RATE = 20.0;

    public const TARGET_DTI = 30.0;

    public const TARGET_EMERGENCY_MONTHS = 3.0;

    public const TARGET_EMERGENCY_MAX_MONTHS = 6.0;

    /**
     * Berapa bulan ke belakang rata-rata expense dihitung. Enam bulan cukup
     * untuk mendapat angka yang cukup stabil tanpa menarik seluruh riwayat.
     */
    public const EXPENSE_AVERAGE_MONTHS = 6;

    /**
     * Bobot komponen skor; totalnya 100.
     */
    private const WEIGHT_SAVINGS = 40;

    private const WEIGHT_DTI = 30;

    private const WEIGHT_EMERGENCY = 30;

    public function __construct(private readonly WorkspaceCache $cache) {}

    /**
     * Read path: skor bulan tertentu untuk ditampilkan.
     *
     * @return array<string, mixed>
     */
    public function current(int $workspaceId, CarbonImmutable $month): array
    {
        $period = MonthPeriod::key($month);

        $cached = $this->cache->get($workspaceId, CacheContext::FinancialHealth, $period);

        if (is_array($cached)) {
            return $cached;
        }

        $score = FinancialHealthScore::allWorkspaces()
            ->where('workspace_id', $workspaceId)
            ->ofMonth($month)
            ->first();

        if ($score === null) {
            // Sama seperti KPI dashboard: jadwalkan perhitungannya, jangan
            // menghitungnya di jalur request. Hasilnya tidak di-cache supaya
            // request berikutnya langsung melihat hasil job.
            RecomputeFinancialHealthJob::dispatch($workspaceId, $period);

            return $this->pending($period);
        }

        $payload = [
            'month' => $period,
            'month_label' => MonthPeriod::label($period),
            'is_pending' => false,
            'score' => $score->score,
            'label' => $score->label->value,
            'label_text' => $score->label->label(),
            'label_tone' => $score->label->tone(),
            'is_complete' => $score->isComplete(),
            'metrics' => $this->metricPayload($score),
            'recommendations' => $score->recommendations,
            'generated_at' => $score->generated_at?->toIso8601String(),
        ];

        $this->cache->put($workspaceId, CacheContext::FinancialHealth, $payload, $period);

        return $payload;
    }

    /**
     * Write path: hitung ulang & simpan skor satu bulan.
     *
     * Idempoten dan aman dijalankan ulang; tidak butuh `ActiveWorkspace`
     * karena selalu dipanggil dari job/console dengan `workspace_id` eksplisit.
     */
    public function recompute(int $workspaceId, string|CarbonImmutable $month): FinancialHealthScore
    {
        $month = $month instanceof CarbonImmutable ? $month->startOfMonth() : MonthPeriod::from($month);

        $snapshot = DashboardSnapshot::allWorkspaces()
            ->where('workspace_id', $workspaceId)
            ->ofMonth($month)
            ->first();

        $incomeCents = Money::toCents($snapshot?->total_income);
        $expenseCents = Money::toCents($snapshot?->total_expense);
        $installmentCents = $this->monthlyInstallments($workspaceId);
        $liquidCents = $this->liquidCash($workspaceId);
        $averageExpenseCents = $this->averageMonthlyExpense($workspaceId, $month)
            ?? $expenseCents;

        $savingsRate = $this->savingsRate($incomeCents, $expenseCents);
        $dti = $this->ratio($installmentCents, $incomeCents);
        $emergencyMonths = $this->emergencyMonths($liquidCents, $averageExpenseCents);

        $score = $this->savingsPoints($savingsRate)
            + $this->dtiPoints($dti)
            + $this->emergencyPoints($emergencyMonths);

        $recommendations = $this->recommendations(
            workspaceId: $workspaceId,
            month: $month,
            savingsRate: $savingsRate,
            incomeCents: $incomeCents,
            expenseCents: $expenseCents,
            dti: $dti,
            emergencyMonths: $emergencyMonths,
            liquidCents: $liquidCents,
            averageExpenseCents: $averageExpenseCents,
        );

        $existing = FinancialHealthScore::allWorkspaces()
            ->where('workspace_id', $workspaceId)
            ->ofMonth($month)
            ->first();

        $record = $existing ?? new FinancialHealthScore;

        if ($existing === null) {
            $record->workspace_id = $workspaceId;
            $record->month = $month;
        }

        // Kolom `DECIMAL(5,2)` dibaca model sebagai string lewat cast
        // `decimal:2`; menyimpan float mentah akan menambahkan presisi
        // kesepuluh yang tidak ada di kolom dan tidak pernah ditampilkan.
        $record->savings_rate = $this->decimal($savingsRate);
        $record->dti = $this->decimal($dti);
        $record->emergency_fund_months = $this->decimal($emergencyMonths);
        $record->score = $score;
        $record->label = FinancialHealthLabel::fromScore($score);
        $record->recommendations = $recommendations;
        $record->generated_at = CarbonImmutable::now();
        $record->save();

        return $record;
    }

    /*
     |--------------------------------------------------------------------------
     | Komponen metrik
     |--------------------------------------------------------------------------
     */

    /**
     * Bentuk nilai yang bisa langsung disimpan ke kolom `DECIMAL(5,2)`.
     */
    private function decimal(?float $value): ?string
    {
        return $value === null ? null : number_format($value, 2, '.', '');
    }

    /**
     * (Income − Expense) / Income dalam persen, atau null saat belum ada
     * pemasukan — rasio terhadap nol tidak punya makna, jadi metrik ini
     * dilaporkan "belum bisa dihitung" alih-alih 0.
     */
    private function savingsRate(int $incomeCents, int $expenseCents): ?float
    {
        if ($incomeCents <= 0) {
            return null;
        }

        return $this->ratio($incomeCents - $expenseCents, $incomeCents);
    }

    /**
     * Rasio dua bilangan dalam persen. Pembulatan dilakukan sekali di akhir
     * (per-mille lebih dulu) supaya tidak ada error di tengah.
     *
     * Null bila pembaginya nol atau negatif: rasio terhadap angka negatif
     * akan tampil dengan tanda yang menyesatkan di UI.
     */
    private function ratio(int $part, int $whole): ?float
    {
        if ($whole <= 0) {
            return null;
        }

        return round($part * 10000 / $whole) / 100;
    }

    /**
     * Total cicilan bulanan dari utang yang masih berjalan, dalam sen.
     *
     * Hanya utang (`payable`) yang dihitung — piutang bukan beban. Cicilan
     * diturunkan dari jadwal (`principal / term_count`) lalu dibatasi sisa
     * pokok, supaya utang yang hampir lunas tidak ikut menghitung cicilan
     * penuh. Utang tanpa `term_count` tidak punya jadwal yang bisa diturunkan,
     * jadi sisa pokoknya yang dipakai sebagai beban bulan ini.
     */
    private function monthlyInstallments(int $workspaceId): int
    {
        $debts = Debt::allWorkspaces()
            ->where('workspace_id', $workspaceId)
            ->payable()
            ->where('remaining', '>', 0)
            ->get(['principal', 'remaining', 'term_count']);

        $installments = 0;

        foreach ($debts as $debt) {
            $remainingCents = Money::toCents($debt->remaining);
            $schedule = $debt->installmentAmount();

            if ($schedule === null) {
                $installments += $remainingCents;

                continue;
            }

            $installments += min(Money::toCents($schedule), $remainingCents);
        }

        return $installments;
    }

    /**
     * Saldo kas likuid dalam sen: akun aktif non-liabilitas yang saldonya
     * tidak negatif.
     *
     * Kartu kredit adalah kewajiban, jadi tidak boleh dihitung sebagai dana
     * darurat; saldo negatif juga bukan kas.
     */
    private function liquidCash(int $workspaceId): int
    {
        $accounts = Account::allWorkspaces()
            ->where('workspace_id', $workspaceId)
            ->active()
            ->get(['type', 'cached_balance']);

        $liquid = 0;

        foreach ($accounts as $account) {
            if ($account->isCredit()) {
                continue;
            }

            $liquid += max(0, Money::toCents($account->cached_balance));
        }

        return $liquid;
    }

    /**
     * Rata-rata expense bulanan (sen) dari snapshot yang ada.
     *
     * Hanya bulan yang punya snapshot ikut dirata-ratakan: bulan tanpa
     * snapshot berarti belum ada data, bukan expense nol. Menganggapnya nol
     * membuat Emergency Fund terlihat jauh lebih baik dari kenyataannya.
     */
    private function averageMonthlyExpense(int $workspaceId, CarbonImmutable $month): ?int
    {
        $first = $month->startOfMonth()->subMonthsNoOverflow(self::EXPENSE_AVERAGE_MONTHS - 1);

        $snapshots = DashboardSnapshot::allWorkspaces()
            ->where('workspace_id', $workspaceId)
            ->betweenMonths($first, $month)
            ->get(['total_expense']);

        if ($snapshots->isEmpty()) {
            return null;
        }

        $total = 0;

        foreach ($snapshots as $snapshot) {
            $total += Money::toCents($snapshot->total_expense);
        }

        return intdiv($total, $snapshots->count());
    }

    /**
     * Kas likuid dalam satuan bulan expense.
     */
    private function emergencyMonths(int $liquidCents, int $averageExpenseCents): ?float
    {
        if ($averageExpenseCents <= 0) {
            return null;
        }

        return round($liquidCents / $averageExpenseCents, 1);
    }

    /*
     |--------------------------------------------------------------------------
     | Skor & rekomendasi
     |--------------------------------------------------------------------------
     */

    /**
     * Poin komponen savings rate (0–40).
     */
    private function savingsPoints(?float $rate): int
    {
        if ($rate === null) {
            return 0;
        }

        return match (true) {
            $rate >= self::TARGET_SAVINGS_RATE => self::WEIGHT_SAVINGS,
            $rate >= 10.0 => 30,
            $rate >= 0.0 => 15,
            default => 0,
        };
    }

    /**
     * Poin komponen DTI (0–30). Rasio kecil lebih baik, jadi band-nya dibalik.
     */
    private function dtiPoints(?float $dti): int
    {
        if ($dti === null) {
            return 0;
        }

        return match (true) {
            $dti <= self::TARGET_DTI => self::WEIGHT_DTI,
            $dti <= 40.0 => 22,
            $dti <= 50.0 => 12,
            default => 0,
        };
    }

    /**
     * Poin komponen dana darurat (0–30).
     */
    private function emergencyPoints(?float $months): int
    {
        if ($months === null) {
            return 0;
        }

        return match (true) {
            $months >= self::TARGET_EMERGENCY_MONTHS => self::WEIGHT_EMERGENCY,
            $months >= 1.0 => 22,
            $months > 0.0 => 12,
            default => 0,
        };
    }

    /**
     * Rekomendasi berbasis aturan (PRD.md §3.9).
     *
     * Setiap recommendasi menyebut metriknya dan satu tindakan konkret. Angka
     * actionable ("kurangi Rp X", "sisihkan Rp Y lagi") diturunkan dari data
     * yang sama dengan skornya, jadi pesannya bisa dipertanggungjawabkan dan
     * tidak berubah antar request.
     *
     * @return array<int, array{metric: string, message: string}>
     */
    private function recommendations(
        int $workspaceId,
        CarbonImmutable $month,
        ?float $savingsRate,
        int $incomeCents,
        int $expenseCents,
        ?float $dti,
        ?float $emergencyMonths,
        int $liquidCents,
        int $averageExpenseCents,
    ): array {
        $recommendations = [];

        if ($savingsRate === null) {
            $recommendations[] = [
                'metric' => 'savings_rate',
                'message' => 'Belum ada pemasukan bulan ini, jadi tingkat tabungan belum bisa dihitung. Catat pemasukan dulu supaya skornya bermakna.',
            ];
        } elseif ($savingsRate < self::TARGET_SAVINGS_RATE) {
            $recommendations[] = [
                'metric' => 'savings_rate',
                'message' => $this->savingsMessage($workspaceId, $month, $savingsRate, $incomeCents, $expenseCents),
            ];
        }

        if ($dti !== null && $dti > self::TARGET_DTI) {
            $recommendations[] = [
                'metric' => 'dti',
                'message' => sprintf(
                    'Rasio cicilan %.1f%% melewati batas aman %.0f%%. Prioritaskan melunasi utang bertermasuk untuk menurunkan beban bulanan.',
                    $dti,
                    self::TARGET_DTI,
                ),
            ];
        }

        if ($emergencyMonths === null) {
            $recommendations[] = [
                'metric' => 'emergency_fund',
                'message' => 'Belum ada data expense bulanan, jadi dana darurat belum bisa dihitung.',
            ];
        } elseif ($emergencyMonths < self::TARGET_EMERGENCY_MONTHS) {
            $recommendations[] = [
                'metric' => 'emergency_fund',
                'message' => $this->emergencyMessage($emergencyMonths, $liquidCents, $averageExpenseCents),
            ];
        }

        if ($recommendations === []) {
            $recommendations[] = [
                'metric' => 'overall',
                'message' => 'Semua metrik sudah di target. Pertahankan kebiasaan ini dan pantau ulang tiap bulan.',
            ];
        }

        return $recommendations;
    }

    /**
     * Pesan untuk savings rate di bawah target.
     *
     * Selisihnya dihitung dari data: supaya savings rate menyentuh 20%, total
     * expense harus turun ke 80% pemasukan. Nominal yang perlu dipotong
     * ditunjuk ke kategori pengeluaran terbesar bulan ini (dari
     * `budget_progress_cache`, agregat yang sama dengan donut dashboard),
     * sehingga sarannya menunjuk pos yang benar-benar paling besar.
     */
    private function savingsMessage(
        int $workspaceId,
        CarbonImmutable $month,
        float $rate,
        int $incomeCents,
        int $expenseCents,
    ): string {
        $headline = sprintf(
            'Tingkat tabungan %.1f%% masih di bawah target %.0f%%.',
            $rate,
            self::TARGET_SAVINGS_RATE,
        );

        if ($incomeCents <= 0) {
            return $headline.' Kurangi pengeluaran pilihanmu sampai pemasukan bulan ini tercatat.';
        }

        $targetExpenseCents = intdiv($incomeCents * (100 - (int) self::TARGET_SAVINGS_RATE), 100);
        $gapCents = $expenseCents - $targetExpenseCents;

        $top = $gapCents > 0 ? $this->topExpenseCategory($workspaceId, $month) : null;

        if ($top === null) {
            return $headline.' Kurangi pengeluaran pilihanmu agar target tercapai.';
        }

        return $headline.sprintf(
            ' Coba kurangi pos %s sebesar %s per bulan.',
            $top['name'],
            Money::format(Money::fromCents(min($gapCents, $top['cents']))),
        );
    }

    /**
     * Pesan untuk dana darurat di bawah 3 bulan.
     */
    private function emergencyMessage(float $months, int $liquidCents, int $averageExpenseCents): string
    {
        $headline = sprintf(
            'Dana darurat %.1f bulan masih di bawah %.0f bulan.',
            $months,
            self::TARGET_EMERGENCY_MONTHS,
        );

        if ($averageExpenseCents <= 0) {
            return $headline.' Sisihkan sebagian pemasukan ke akun terpisah.';
        }

        $targetCents = (int) round($averageExpenseCents * self::TARGET_EMERGENCY_MONTHS);
        $gapCents = max(0, $targetCents - $liquidCents);

        if ($gapCents === 0) {
            return $headline.' Sisihkan sebagian pemasukan ke akun terpisah.';
        }

        return $headline.sprintf(
            ' Sisihkan %s lagi agar aman.',
            Money::format(Money::fromCents($gapCents)),
        );
    }

    /**
     * Kategori pengeluaran terbesar pada satu bulan.
     *
     * Hanya kategori induk: baris `budget_progress_cache` milik induk sudah
     * mencakup sub-kategorinya, jadi menggabungkan keduanya akan menghitung
     * dua kali.
     *
     * @return array{name: string, cents: int}|null
     */
    private function topExpenseCategory(int $workspaceId, CarbonImmutable $month): ?array
    {
        // `toBase()`: nama kategori diambil langsung dari join, bukan lewat
        // relasi `category` yang akan menambah satu query lain.
        $row = BudgetProgress::allWorkspaces()
            ->toBase()
            ->select([
                'budget_progress_cache.used_amount',
                'categories.name as category_name',
            ])
            ->join('categories', 'categories.id', '=', 'budget_progress_cache.category_id')
            ->where('categories.workspace_id', $workspaceId)
            ->whereNull('categories.parent_id')
            ->where('budget_progress_cache.workspace_id', $workspaceId)
            ->where('budget_progress_cache.month', $month->startOfMonth()->toDateString())
            ->orderByDesc('budget_progress_cache.used_amount')
            ->first();

        if ($row === null) {
            return null;
        }

        return [
            'name' => (string) $row->category_name,
            'cents' => Money::toCents($row->used_amount),
        ];
    }

    /*
     |--------------------------------------------------------------------------
     | Payload
     |--------------------------------------------------------------------------
     */

    /**
     * Tiga metrik dalam bentuk siap tampil.
     *
     * @return array<int, array{key: string, label: string, value: string|null, target: float, unit: string, is_healthy: bool}>
     */
    private function metricPayload(FinancialHealthScore $score): array
    {
        $savingsRate = $score->savings_rate === null ? null : (float) $score->savings_rate;
        $dti = $score->dti === null ? null : (float) $score->dti;
        $emergency = $score->emergency_fund_months === null
            ? null
            : (float) $score->emergency_fund_months;

        return [
            [
                'key' => 'savings_rate',
                'label' => 'Tingkat tabungan',
                'value' => $savingsRate === null ? null : number_format($savingsRate, 1),
                'target' => self::TARGET_SAVINGS_RATE,
                'unit' => 'percent',
                'is_healthy' => $savingsRate !== null && $savingsRate >= self::TARGET_SAVINGS_RATE,
            ],
            [
                'key' => 'dti',
                'label' => 'Rasio utang vs pemasukan',
                'value' => $dti === null ? null : number_format($dti, 1),
                'target' => self::TARGET_DTI,
                'unit' => 'percent',
                'is_healthy' => $dti !== null && $dti <= self::TARGET_DTI,
            ],
            [
                'key' => 'emergency_fund_months',
                'label' => 'Dana darurat',
                'value' => $emergency === null ? null : number_format($emergency, 1),
                'target' => self::TARGET_EMERGENCY_MONTHS,
                'unit' => 'months',
                'is_healthy' => $emergency !== null && $emergency >= self::TARGET_EMERGENCY_MONTHS,
            ],
        ];
    }

    /**
     * Bentuk payload ketika skor belum pernah dihitung.
     *
     * @return array<string, mixed>
     */
    private function pending(string $period): array
    {
        $label = FinancialHealthLabel::NeedsAttention;

        return [
            'month' => $period,
            'month_label' => MonthPeriod::label($period),
            'is_pending' => true,
            'score' => 0,
            'label' => $label->value,
            'label_text' => $label->label(),
            'label_tone' => $label->tone(),
            'is_complete' => false,
            'metrics' => [],
            'recommendations' => [],
            'generated_at' => null,
        ];
    }
}
