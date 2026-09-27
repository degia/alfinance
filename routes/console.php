<?php

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
