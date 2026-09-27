<?php

use App\Jobs\CloseMonthlyNetWorthSnapshotsJob;
use App\Jobs\RecurringTransactionJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Transaksi berulang (Fase 3)
|--------------------------------------------------------------------------
| Job berjalan lintas workspace, jadi dia mematikan global scope secara
| eksplisit lalu memproses tiap rule dengan `ActiveWorkspace` milik rule itu.
| Satu kali eksekusi cukup: `next_run_at` sudah dihitung presisi per rule
| (harian/mingguan/bulanan/tahunan), jadi jam eksekusi tidak boleh
| menentukan tanggal transaksi.
*/
Schedule::job(new RecurringTransactionJob)->dailyAt('00:10')->withoutOverlapping();

/*
|--------------------------------------------------------------------------
| Net worth (Fase 4)
|--------------------------------------------------------------------------
| Tanggal 1 pukul 00:20 bulan yang baru ditutup ditulis sebagai snapshot
| final, supaya tren punya titik yang tidak berubah lagi. Snapshot bulan
| berjalan dijaga listener, bukan scheduler, karena bisa berubah kapan saja.
|
| Dijadwalkan 00:20 (bukan 00:00/00:10) supaya tidak berebut antrean dengan
| instance transaksi berulang di 00:10 — keduanya menyalakan worker.
*/
Schedule::job(new CloseMonthlyNetWorthSnapshotsJob)->dailyAt('00:20')->withoutOverlapping();
