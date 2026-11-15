<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Admin\AdminStatsService;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Dashboard panel admin: ringkasan pengguna, aktivitas, dan status sistem.
 *
 * Tidak memakai cache: halaman ini justru dibuka untuk melihat kondisi
 * sekarang (siapa online, apakah antrean hidup), sehingga angka basi lebih
 * merugikan daripada query agregat sesekali oleh segelintir admin.
 */
class DashboardController extends Controller
{
    public function __invoke(AdminStatsService $stats): Response
    {
        return Inertia::render('admin/Dashboard', [
            'summary' => $stats->summary(),
            'registrationTrend' => $stats->registrationTrend(),
            'recentlyActive' => $stats->recentlyActive(),
            'recentRegistrations' => $stats->recentRegistrations(),
            'systemStatus' => $stats->systemStatus(),
        ]);
    }
}
