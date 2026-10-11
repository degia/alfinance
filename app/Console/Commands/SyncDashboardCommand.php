<?php

namespace App\Console\Commands;

use App\Models\DashboardDailySnapshot;
use App\Models\DashboardSnapshot;
use App\Models\Transaction;
use App\Models\Workspace;
use App\Services\Budgets\BudgetService;
use App\Services\Cache\WorkspaceCache;
use App\Services\Dashboard\DashboardAggregator;
use App\Services\FinancialHealth\FinancialHealthService;
use App\Support\MonthPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Membangun ulang seluruh agregat yang dibaca halaman Dashboard dari data
 * `transactions` (PRD.md §3.1, ARCHITECTURE.md §2.3 butir 3).
 *
 * Jalur normal menyegarkan agregat lewat job antrean ({@see ScheduleAggregateRecompute}).
 * Perintah ini adalah jaring pengaman yang bisa dijalankan manual saat antrean
 * tidak sempat berjalan — mis. worker mati beberapa hari — sehingga snapshot
 * bulanan/harian, progress anggaran, dan skor kesehatan kembali sinkron dengan
 * transaksi.
 *
 * Perintah berjalan tanpa request, jadi tidak ada `ActiveWorkspace` dari
 * middleware: `workspace_id` selalu eksplisit dan semua service yang dipanggil
 * membaca `transactions` lewat `allWorkspaces()` dengan filter workspace manual.
 *
 * Urutan build penting: skor kesehatan membaca snapshot dashboard
 * ({@see FinancialHealthService::recompute()}), jadi dashboard dihitung lebih
 * dulu, lalu progress anggaran (untuk donut komposisi pengeluaran), baru skor.
 */
class SyncDashboardCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'dashboard:sync
        {--workspace=* : ID workspace yang disinkronkan (boleh diulang). Kosong berarti semua workspace}
        {--month=* : Bulan format YYYY-MM (boleh diulang). Kosong berarti semua bulan yang punya jejak data}';

    /**
     * @var string
     */
    protected $description = 'Bangun ulang snapshot dashboard, progress anggaran, dan skor kesehatan dari transaksi';

    public function handle(
        DashboardAggregator $dashboard,
        BudgetService $budgets,
        FinancialHealthService $health,
        WorkspaceCache $cache,
    ): int {
        $workspaces = $this->targetWorkspaces();

        if ($workspaces === []) {
            $this->components->warn('Tidak ada workspace yang cocok untuk disinkronkan.');

            return self::SUCCESS;
        }

        $explicitMonths = $this->requestedMonths();
        $totalMonths = 0;

        foreach ($workspaces as $workspaceId) {
            $months = $explicitMonths ?? $this->monthsFor($workspaceId);

            if ($months === []) {
                $this->components->info(sprintf('Workspace %d: tidak ada bulan dengan data.', $workspaceId));

                continue;
            }

            foreach ($months as $month) {
                // Urutan mengikuti dependensi read path dashboard: snapshot ->
                // progress anggaran -> skor kesehatan.
                $dashboard->recomputeForMonth($workspaceId, $month);
                $budgets->recomputeForMonth($workspaceId, $month);
                $health->recompute($workspaceId, $month);
            }

            // Snapshot sudah final; buang cache supaya request berikutnya
            // membaca angka baru, bukan payload lama ber-TTL 10 menit.
            $cache->forgetAll($workspaceId);

            $totalMonths += count($months);

            $this->components->info(sprintf(
                'Workspace %d: %d bulan disinkronkan (%s).',
                $workspaceId,
                count($months),
                implode(', ', $months),
            ));
        }

        $this->newLine();
        $this->components->info(sprintf(
            'Selesai: %d workspace, %d bulan.',
            count($workspaces),
            $totalMonths,
        ));

        return self::SUCCESS;
    }

    /**
     * Daftar workspace target.
     *
     * Tanpa `--workspace`, menyasar semua workspace yang ada. ID yang tidak
     * dikenal diabaikan dengan peringatan supaya salah ketik tidak menghasilkan
     * sukses palsu.
     *
     * @return array<int, int>
     */
    private function targetWorkspaces(): array
    {
        $requested = array_values(array_unique(array_map(
            static fn (mixed $id): int => (int) $id,
            (array) $this->option('workspace'),
        )));

        $all = Workspace::query()
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        if ($requested === []) {
            return $all;
        }

        $unknown = array_diff($requested, $all);

        if ($unknown !== []) {
            $this->components->warn(sprintf(
                'Workspace tidak dikenal diabaikan: %s.',
                implode(', ', $unknown),
            ));
        }

        return array_values(array_intersect($all, $requested));
    }

    /**
     * Bulan eksplisit dari `--month`, sudah dinormalkan ke `YYYY-MM`.
     *
     * Null berarti pemanggil tidak menentukan bulan dan tiap workspace harus
     * disinkronkan untuk semua bulan yang punya jejak data.
     *
     * @return array<int, string>|null
     */
    private function requestedMonths(): ?array
    {
        $months = [];

        foreach ((array) $this->option('month') as $value) {
            $value = trim((string) $value);

            if ($value === '') {
                continue;
            }

            if (! MonthPeriod::isValid($value)) {
                $this->components->warn(sprintf('Bulan tidak valid diabaikan: %s.', $value));

                continue;
            }

            $months[] = MonthPeriod::key(MonthPeriod::from($value));
        }

        if ($months === []) {
            return null;
        }

        $months = array_values(array_unique($months));
        sort($months);

        return $months;
    }

    /**
     * Semua bulan yang perlu dihitung ulang untuk satu workspace.
     *
     * Gabungan dari tiga sumber supaya tidak ada bulan yang terlewat:
     * - rentang transaksi `posted` (min s.d. max `occurred_at`) — mencakup
     *   bulan yang datanya baru saja bertambah;
     * - bulan yang sudah punya snapshot bulanan — supaya baris basi (mis.
     *   transaksinya sudah dihapus semua) ikut dinolkan;
     * - bulan yang masih punya baris agregat harian — supaya sisa baris harian
     *   "yatim" ikut dibersihkan.
     *
     * Bulan berjalan selalu ikut supaya dashboard bulan ini langsung terisi.
     *
     * @return array<int, string>
     */
    private function monthsFor(int $workspaceId): array
    {
        $months = [];

        $range = Transaction::allWorkspaces()
            ->where('workspace_id', $workspaceId)
            ->posted()
            ->selectRaw('MIN(occurred_at) as min_at, MAX(occurred_at) as max_at')
            ->first();

        if ($range !== null && $range->min_at !== null && $range->max_at !== null) {
            $cursor = CarbonImmutable::parse((string) $range->min_at)->startOfMonth();
            $last = CarbonImmutable::parse((string) $range->max_at)->startOfMonth();

            while ($cursor->lessThanOrEqualTo($last)) {
                $months[] = MonthPeriod::key($cursor);
                $cursor = $cursor->addMonthNoOverflow();
            }
        }

        $months = [
            ...$months,
            ...DashboardSnapshot::allWorkspaces()
                ->where('workspace_id', $workspaceId)
                ->pluck('month')
                ->map(static fn (mixed $month): string => MonthPeriod::key(CarbonImmutable::parse((string) $month)))
                ->all(),
            ...DashboardDailySnapshot::allWorkspaces()
                ->where('workspace_id', $workspaceId)
                ->pluck('date')
                ->map(static fn (mixed $date): string => MonthPeriod::key(CarbonImmutable::parse((string) $date)))
                ->all(),
            MonthPeriod::key(CarbonImmutable::now()),
        ];

        $months = array_values(array_unique(array_filter(
            $months,
            static fn (string $month): bool => MonthPeriod::isValid($month),
        )));
        sort($months);

        return $months;
    }
}
