<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AuthorizesWorkspaceData;
use App\Http\Requests\ReportFilterRequest;
use App\Services\Reports\ReportService;
use App\Support\Workspace\ActiveWorkspace;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Halaman laporan (PRD.md §3.8).
 *
 * Tiga laporan dalam satu halaman dengan tab: Cash Flow Statement, Budget vs
 * Actual, dan Expense Breakdown. Semuanya dilayani {@see ReportService}, yang
 * membaca tabel agregat dan mengunci hasilnya per rentang tanggal — mengganti
 * filter hanya berpindah cache key, bukan memicu hitung ulang manual.
 *
 * Halaman ini read-only, jadi cukup ability `view`: viewer juga boleh membaca
 * laporan.
 */
class ReportController extends Controller
{
    use AuthorizesWorkspaceData;

    public function __construct(private readonly ReportService $reports) {}

    public function index(ReportFilterRequest $request): Response
    {
        $this->authorizeViewData();

        $workspaceId = ActiveWorkspace::idOrFail();
        $from = $request->fromMonth();
        $to = $request->toMonth();
        $bounds = $request->bounds();

        return Inertia::render('reports/Index', [
            'filters' => [
                ...$bounds,
                'account_id' => $request->accountId(),
                'category_id' => $request->categoryId(),
            ],
            'options' => $request->options(),
            'max_months' => ReportFilterRequest::MAX_MONTHS,
            'cash_flow' => $this->reports->cashFlow(
                $workspaceId,
                $from,
                $to,
                $request->accountId(),
            ),
            'budget_vs_actual' => $this->reports->budgetVsActual(
                $workspaceId,
                $from,
                $to,
                $request->categoryId(),
            ),
            'expense_breakdown' => $this->reports->expenseBreakdown(
                $workspaceId,
                $from,
                $to,
                $request->categoryId(),
            ),
        ]);
    }
}
