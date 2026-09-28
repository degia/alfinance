<?php

namespace App\Jobs;

use App\Listeners\ScheduleAggregateRecompute;
use App\Models\Workspace;
use App\Support\MonthPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Jaring pengaman harian untuk agregat bulanan (Fase 5).
 *
 * Job di antrean sudah menutup sebagian besar kasus: setiap transaksi,
 * anggaran, akun, atau pembayaran utang memicu build ulang lewat
 * {@see ScheduleAggregateRecompute}. Job ini menutup sisanya —
 * snapshot bulan yang belum pernah dibuat sama sekali, dan data yang berubah
 * tanpa lewat event (mis. hasil migrasi atau perbaikan manual).
 *
 * Dijadwalkan setelah job lain pukul 00:10/00:20 supaya tidak berebut antrean
 * dengan generate instance berulang dan snapshot net worth.
 */
class RefreshWorkspaceAggregatesJob implements ShouldQueue
{
    use Queueable;

    /**
     * Berapa bulan ke belakang yang dijaga setiap hari. Bulan lampau hampir
     * tidak pernah berubah lagi, jadi cukup satu bulan plus bulan berjalan.
     */
    private const MONTHS = 2;

    public function handle(): void
    {
        $month = CarbonImmutable::now()->startOfMonth();

        $workspaceIds = Workspace::query()
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        foreach ($workspaceIds as $workspaceId) {
            for ($offset = 0; $offset < self::MONTHS; $offset++) {
                $key = MonthPeriod::key($month->subMonthsNoOverflow($offset));

                RecomputeDashboardSnapshotJob::dispatch($workspaceId, $key);
                RecomputeFinancialHealthJob::dispatch($workspaceId, $key);
            }
        }

        Log::info('Menjadwalkan penyegaran agregat harian.', [
            'workspaces' => count($workspaceIds),
            'months' => self::MONTHS,
        ]);
    }
}
