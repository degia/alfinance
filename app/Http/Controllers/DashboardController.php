<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AuthorizesWorkspaceData;
use App\Services\Dashboard\DashboardService;
use App\Support\MonthPeriod;
use App\Support\Workspace\ActiveWorkspace;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Halaman dashboard (PRD.md §3.1).
 *
 * Controller ini tidak menyentuh `transactions` sama sekali: seluruh payload
 * diambil dari {@see DashboardService}, yang hanya membaca cache Redis dan
 * `dashboard_snapshots` (ARCHITECTURE.md §2.1).
 *
 * Query opsional `?month=` dipakai user saat menelusuri bulan lampau. Nilainya
 * divalidasi sebagai `YYYY-MM`/`YYYY-MM-DD`, jadi input aneh seperti "2026-13"
 * berakhir jadi 422 dari validasi, bukan exception runtime.
 */
class DashboardController extends Controller
{
    use AuthorizesWorkspaceData;

    public function __construct(private readonly DashboardService $dashboard) {}

    public function index(Request $request): Response
    {
        $this->authorizeViewData();

        $validated = $request->validate([
            'month' => ['nullable', 'string', 'regex:/^\d{4}-(0[1-9]|1[0-2])(-\d{2})?$/'],
            'trend' => ['nullable', 'integer', 'in:6,12'],
        ]);

        $month = isset($validated['month'])
            ? MonthPeriod::from((string) $validated['month'])
            : null;

        $overview = $this->dashboard->overview(
            ActiveWorkspace::idOrFail(),
            $month?->format('Y-m'),
            (int) ($validated['trend'] ?? 6),
        );

        return Inertia::render('Dashboard', [
            ...$overview,
            'trend_options' => DashboardService::TREND_OPTIONS,
        ]);
    }
}
