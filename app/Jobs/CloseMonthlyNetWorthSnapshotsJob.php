<?php

namespace App\Jobs;

use App\Listeners\RefreshNetWorthSnapshot;
use App\Models\Workspace;
use App\Services\NetWorth\NetWorthService;
use App\Support\MonthPeriod;
use App\Support\Workspace\ActiveWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Menutup bulan yang baru saja berakhir: menulis snapshot net worth untuk
 * seluruh workspace pada bulan itu (PRD.md §3.6, ARCHITECTURE.md §2.3).
 *
 * Dijadwalkan tanggal 1 pukul 00:20 lewat `routes/console.php`. Job ini lintas
 * workspace, jadi global scope dimatikan secara eksplisit lalu tiap workspace
 * diproses dengan `ActiveWorkspace` miliknya sendiri. Satu workspace yang gagal
 * dicatat ke log lalu dilewati, supaya workspace lain tetap ketutup.
 *
 * Idempoten: snapshot ditulis dengan upsert oleh
 * {@see NetWorthService::snapshot()}, jadi eksekusi
 * ulang di tanggal yang sama hanya memperbarui baris yang sama.
 *
 * Snapshot bulan yang sedang berjalan TIDAK digagas di sini — itu tugas
 * listener {@see RefreshNetWorthSnapshot} yang merespons
 * perubahan item & cicilan.
 */
class CloseMonthlyNetWorthSnapshotsJob implements ShouldQueue
{
    use Queueable;

    /**
     * Jumlah workspace per chunk query, supaya satu hari yang berat (banyak
     * workspace sekaligus) tidak menahan semua baris di memori.
     */
    private const BATCH_SIZE = 100;

    public function handle(NetWorthService $netWorth): void
    {
        $closedMonth = CarbonImmutable::now()->startOfMonth()->subMonthNoOverflow();
        $monthKey = MonthPeriod::key($closedMonth);

        ActiveWorkspace::withoutScope(function () use ($netWorth, $closedMonth, $monthKey): void {
            // `chunkById` supaya SEMUA workspace ikut tertutup: `limit` polos
            // akan selalu mengembalikan 100 workspace pertama saja.
            Workspace::query()
                ->orderBy('id')
                ->chunkById(self::BATCH_SIZE, function ($workspaces) use ($netWorth, $closedMonth, $monthKey): void {
                    foreach ($workspaces as $workspace) {
                        $previous = ActiveWorkspace::workspace();

                        ActiveWorkspace::set($workspace);

                        try {
                            $netWorth->snapshot(
                                (int) $workspace->id,
                                $closedMonth,
                                $closedMonth->endOfDay(),
                            );
                        } catch (Throwable $exception) {
                            Log::error('Gagal menutup snapshot net worth workspace.', [
                                'workspace_id' => $workspace->id,
                                'month' => $monthKey,
                                'exception' => $exception->getMessage(),
                            ]);
                        } finally {
                            ActiveWorkspace::set($previous);
                        }
                    }
                });
        });
    }
}
