<?php

namespace App\Http\Controllers;

use App\Enums\BudgetStatus;
use App\Http\Controllers\Concerns\AuthorizesWorkspaceData;
use App\Http\Requests\BudgetRequest;
use App\Jobs\RecomputeBudgetProgressJob;
use App\Models\Budget;
use App\Models\BudgetProgress;
use App\Models\Category;
use App\Services\Budgets\BudgetService;
use App\Support\Money;
use App\Support\MonthPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Halaman anggaran (PRD.md §3.5).
 *
 * Controller ini SENGAJA tidak menjumlahkan `transactions`: angka pemakaian
 * dibaca dari `budget_progress_cache`, yang ditulis
 * {@see RecomputeBudgetProgressJob}. Matrix inline (12 bulan x
 * kategori) karena itu murah: satu `SELECT` cache, tanpa agregasi per sel.
 */
class BudgetController extends Controller
{
    use AuthorizesWorkspaceData;

    public function __construct(
        private readonly BudgetService $budgets,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorizeViewData();

        $month = $this->resolveMonth($request->query('month'));
        $year = (int) $request->query('year', $month->year);

        return Inertia::render('budgets/Index', [
            'month' => MonthPeriod::key($month),
            'month_label' => MonthPeriod::label(MonthPeriod::key($month)),
            'year' => $year,
            'categories' => $this->categoryRows($year),
            'months' => $this->monthColumns($year),
            'summary' => $this->summary($year, $month),
            'options' => $this->formOptions(),
        ]);
    }

    public function store(BudgetRequest $request): RedirectResponse
    {
        $this->authorizeEditData();

        $attributes = $request->budgetAttributes();
        $category = $request->category();

        $budget = $this->budgets->upsert([
            'month' => $attributes['month'],
            'limit_amount' => $attributes['limit_amount'],
        ], $category, $request->user()->id);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Limit anggaran berhasil disimpan.'),
        ]);

        return to_route('budgets.index', [
            'month' => $budget->monthKey(),
            'year' => (int) CarbonImmutable::parse($budget->month)->year,
        ]);
    }

    public function update(Request $request, Budget $budget): RedirectResponse
    {
        $this->authorizeEditData();

        $validated = $request->validate([
            'limit_amount' => ['required', 'decimal:0,2', 'min:0.01', 'max:9999999999999.99'],
        ], [
            'limit_amount.min' => __('Limit harus lebih besar dari nol.'),
            'limit_amount.max' => __('Limit melebihi batas maksimum yang disimpan.'),
        ], [
            'limit_amount' => __('limit'),
        ]);

        $this->budgets->updateLimit($budget, (string) $validated['limit_amount'], $request->user()->id);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Limit anggaran berhasil diperbarui.'),
        ]);

        return back();
    }

    public function destroy(Budget $budget): RedirectResponse
    {
        $this->authorizeEditData();

        $this->budgets->delete($budget, request()->user()?->id);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Limit anggaran dihapus.'),
        ]);

        return back();
    }

    /*
    |--------------------------------------------------------------------------
    | Payload Inertia
    |--------------------------------------------------------------------------
    */

    /**
     * Baris matriks: satu kategori (dengan sub-kategorinya) per tahun terpilih.
     *
     * `used` dibaca per bulan dari cache, jadi controller ini tidak pernah
     * menyentuh tabel `transactions`.
     *
     * @return array<int, array<string, mixed>>
     */
    private function categoryRows(int $year): array
    {
        $categories = Category::query()
            ->with('children')
            ->orderBy('name')
            ->get();

        $budgets = Budget::query()
            ->ofYear($year)
            ->get()
            ->keyBy(static fn (Budget $budget): string => $budget->category_id.'-'.$budget->monthKey());

        // Satu query cache untuk seluruh matriks, lalu dipasang manual:
        // pasangan key `progress` majemuk sehingga tidak bisa di-eager-load.
        $progress = BudgetProgress::query()
            ->whereYear('month', $year)
            ->get()
            ->keyBy(static fn (BudgetProgress $row): string => $row->category_id.'-'.$row->monthKey());

        foreach ($budgets as $budget) {
            $budget->setRelation('progress', $progress->get($budget->category_id.'-'.$budget->monthKey()));
        }

        $rows = [];

        foreach ($categories as $category) {
            $rows[] = $this->row($category, $year, $budgets);

            // `children` sudah eager-load di query kategori di atas. Memakai
            // relasi yang ter-load (diurutkan di PHP) supaya matriks anggaran
            // tidak memicu satu query anak per kategori induk.
            foreach ($category->children->sortBy('name') as $child) {
                $rows[] = $this->row($child, $year, $budgets, nested: true);
            }
        }

        return $rows;
    }

    /**
     * @param  Collection<string, Budget>  $budgets
     * @return array<string, mixed>
     */
    private function row(Category $category, int $year, $budgets, bool $nested = false): array
    {
        $months = [];

        foreach (MonthPeriod::monthsOfYear($year) as $month) {
            $key = MonthPeriod::key($month);
            /** @var Budget|null $budget */
            $budget = $budgets->get($category->id.'-'.$key);

            $limit = $budget?->limit_amount;
            $used = $budget?->usedAmount() ?? '0.00';

            $months[] = [
                'month' => $key,
                'budget_id' => $budget?->id,
                'limit' => $limit,
                'used' => $used,
                'remaining' => $budget === null ? null : $budget->remaining(),
                'percent' => $budget?->usedPercent(),
                'status' => ($budget?->status() ?? BudgetStatus::Unset)->value,
            ];
        }

        return [
            'id' => (int) $category->id,
            'name' => $category->name,
            'color' => $category->color,
            'is_nested' => $nested,
            'is_child' => $category->isChild(),
            'months' => $months,
        ];
    }

    /**
     * Kolom matriks: 12 bulan dengan label pendek.
     *
     * @return array<int, array<string, string>>
     */
    private function monthColumns(int $year): array
    {
        return array_map(
            static fn (CarbonImmutable $month): array => [
                'month' => MonthPeriod::key($month),
                'label' => MonthPeriod::shortLabel(MonthPeriod::key($month)),
            ],
            MonthPeriod::monthsOfYear($year),
        );
    }

    /**
     * Ringkasan tahun berjalan: total limit, total terpakai, dan jumlah
     * kategori per status.
     *
     * @return array<string, mixed>
     */
    private function summary(int $year, CarbonImmutable $month): array
    {
        $rows = $this->categoryRows($year);

        $totalLimit = '0.00';
        $totalUsed = '0.00';
        $byStatus = [
            BudgetStatus::Healthy->value => 0,
            BudgetStatus::Warning->value => 0,
            BudgetStatus::Over->value => 0,
            BudgetStatus::Unset->value => 0,
        ];

        $monthKey = MonthPeriod::key($month);

        foreach ($rows as $row) {
            foreach ($row['months'] as $cell) {
                if ($cell['month'] !== $monthKey) {
                    continue;
                }

                $byStatus[$cell['status']] = $byStatus[$cell['status']] + 1;
            }

            // Total tahunan memakai semua sel limit yang terisi.
            foreach ($row['months'] as $cell) {
                if ($cell['limit'] !== null) {
                    $totalLimit = Money::add($totalLimit, $cell['limit']);
                    $totalUsed = Money::add($totalUsed, $cell['used']);
                }
            }
        }

        $percent = Money::ratioOf($totalUsed, $totalLimit);

        return [
            'total_limit' => $totalLimit,
            'total_used' => $totalUsed,
            'total_remaining' => Money::subtract($totalLimit, $totalUsed),
            'percent' => $percent,
            'status' => BudgetStatus::fromPercent($percent)->value,
            'by_status' => $byStatus,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function formOptions(): array
    {
        return [
            'categories' => Category::query()
                ->orderBy('name')
                ->get(['id', 'name', 'parent_id', 'color'])
                ->map(fn (Category $category): array => [
                    'id' => (int) $category->id,
                    'name' => $category->name,
                    'parent_id' => $category->parent_id === null ? null : (int) $category->parent_id,
                    'color' => $category->color,
                ])
                ->values()
                ->all(),
            'months' => $this->monthColumns((int) now()->year),
        ];
    }

    /**
     * Bulanan terpilih, default ke bulan berjalan. Input "YYYY-MM" dari query
     * string dipakai apa adanya supaya tidak pernah lompat ke bulan lain.
     */
    private function resolveMonth(mixed $value): CarbonImmutable
    {
        if (is_string($value) && MonthPeriod::isValid($value)) {
            return MonthPeriod::from($value);
        }

        return MonthPeriod::from(CarbonImmutable::now()->format('Y-m'));
    }
}
