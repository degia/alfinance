<?php

namespace App\Http\Controllers;

use App\Enums\BudgetStatus;
use App\Http\Controllers\Concerns\AuthorizesWorkspaceData;
use App\Http\Requests\BudgetRequest;
use App\Jobs\RecomputeBudgetProgressJob;
use App\Models\Budget;
use App\Models\BudgetProgress;
use App\Models\Category;
use App\Models\Transaction;
use App\Services\Budgets\BudgetService;
use App\Support\Money;
use App\Support\MonthPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
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

        // Baris dihitung sekali lalu dipakai untuk matriks + ringkasan supaya
        // agregasi cabang tidak dijalankan dua kali per request.
        $rows = $this->categoryRows($year);

        return Inertia::render('budgets/Index', [
            'month' => MonthPeriod::key($month),
            'month_label' => MonthPeriod::label(MonthPeriod::key($month)),
            'year' => $year,
            'categories' => $rows,
            'months' => $this->monthColumns($year),
            'summary' => $this->summary($rows, $month),
            'options' => $this->formOptions(),
        ]);
    }

    public function store(BudgetRequest $request): RedirectResponse
    {
        $this->authorizeEditData();

        $attributes = $request->budgetAttributes();
        $category = $request->category();
        $actorId = $request->user()->id;

        $budget = $this->budgets->upsert([
            'month' => $attributes['month'],
            'limit_amount' => $attributes['limit_amount'],
        ], $category, $actorId);

        // Limit sub-kategori opsional: masing-masing jadi baris budget sendiri
        // di bulan yang sama, sehingga baris induk menampilkan cabang lengkap.
        $subLimits = $request->subLimits();

        if ($subLimits !== []) {
            $children = Category::query()
                ->whereIn('id', array_keys($subLimits))
                ->get()
                ->keyBy(static fn (Category $child): int => (int) $child->id);

            foreach ($subLimits as $categoryId => $limit) {
                $child = $children->get($categoryId);

                if (! $child instanceof Category) {
                    continue;
                }

                $this->budgets->upsert([
                    'month' => $attributes['month'],
                    'limit_amount' => $limit,
                ], $child, $actorId);
            }
        }

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

    /**
     * "Cursor view" satu limit: ringkasan + transaksi yang memotong limitnya.
     *
     * Angka pemakaian tetap dibaca dari `budget_progress_cache` (sumber
     * kebenaran halaman anggaran), sedangkan daftar transaksi adalah detail
     * mentah yang dimuat bertahap dengan cursor pagination — bukan agregasi
     * ulang di jalur request (ARCHITECTURE.md §2.1 butir 5). Kategori induk
     * ikut menampilkan transaksi sub-kategorinya, sama seperti cache.
     */
    public function show(Budget $budget): JsonResponse
    {
        $this->authorizeViewData();

        $workspaceId = (int) $budget->workspace_id;
        $categoryId = (int) $budget->category_id;
        $month = CarbonImmutable::parse($budget->month)->startOfMonth();
        $categoryIds = $this->budgets->rollupFor($workspaceId, $categoryId);

        $transactions = $this->budgets
            ->spentQuery($workspaceId, $categoryIds, $month)
            ->with([
                'account:id,name',
                'category:id,parent_id,name',
                'category.parent:id,parent_id,name',
            ])
            // Baris terbaru di atas; id sebagai tie-breaker supaya urutan cursor
            // stabil saat dua transaksi punya tanggal yang sama.
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->cursorPaginate(20);

        $category = Category::query()
            ->with('parent:id,name')
            ->findOrFail($categoryId);

        // Baris cache dipasang eksplisit (bukan lewat lazy-load relasi
        // `progress`) supaya pencocokan bulan tetap benar di semua driver:
        // kolom `month` bertipe DATE, jadi perbandingan exact string bisa gagal
        // di SQLite yang menyimpan komponen waktu. Polanya sama dengan
        // {@see self::categoryRows()}.
        $budget->setRelation(
            'progress',
            BudgetProgress::query()
                ->where('category_id', $categoryId)
                ->whereYear('month', $month->year)
                ->whereMonth('month', $month->month)
                ->first(),
        );

        return response()->json([
            'budget' => $this->presentBudgetDetail(
                $budget,
                $month,
                $category,
                count($categoryIds) > 1,
            ),
            'transactions' => array_map(
                fn (Transaction $transaction): array => $this->presentSpentTransaction($transaction),
                $transactions->items(),
            ),
            'next_cursor' => $transactions->nextCursor()?->encode(),
            'has_more' => $transactions->hasMorePages(),
        ]);
    }

    /**
     * Ringkasan limit untuk modal detail.
     *
     * @return array<string, mixed>
     */
    private function presentBudgetDetail(
        Budget $budget,
        CarbonImmutable $month,
        Category $category,
        bool $includesSubcategories,
    ): array {
        $key = MonthPeriod::key($month);

        return [
            'id' => (int) $budget->id,
            'month' => $key,
            'month_label' => MonthPeriod::label($key),
            'category' => [
                'id' => (int) $category->id,
                'name' => $category->name,
                'color' => $category->color,
                'parent_id' => $category->parent_id === null ? null : (int) $category->parent_id,
                'parent_name' => $category->parent?->name,
            ],
            'limit' => $budget->limit_amount,
            'used' => $budget->usedAmount(),
            'remaining' => $budget->remaining(),
            'percent' => $budget->usedPercent(),
            'status' => $budget->status()->value,
            'includes_subcategories' => $includesSubcategories,
        ];
    }

    /**
     * Satu baris transaksi yang memotong limit — cukup untuk modal detail.
     *
     * @return array<string, mixed>
     */
    private function presentSpentTransaction(Transaction $transaction): array
    {
        return [
            'id' => (int) $transaction->id,
            'occurred_at' => $transaction->occurred_at->toDateString(),
            'amount' => $transaction->amount,
            'note' => $transaction->note,
            'account_name' => $transaction->account?->name,
            'category_name' => $transaction->category?->name,
            'category_parent_name' => $transaction->category?->parent?->name,
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Payload Inertia
    |--------------------------------------------------------------------------
    */

    /**
     * Baris matriks: satu kategori induk (beserta sub-kategorinya) per tahun.
     *
     * Baris induk adalah ringkasan satu cabang: limit = limit sendiri + limit
     * semua sub-kategorinya, dan `used` = rollup sub-kategori (dari cache).
     * Sub-kategori tetap punya barisnya sendiri saat dibuka. Jadi angka cabang
     * dan angka per kategori sama-sama tersedia tanpa menghitung dua kali di
     * ringkasan (lihat {@see self::summary()}).
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

        // Satu query cache untuk seluruh matriks. `used` dibaca dari sini
        // (bukan lewat relasi budget) karena kategori tanpa limit pun punya
        // angka pemakaian, dan baris induk menampilkan rollup anaknya.
        $progress = BudgetProgress::query()
            ->whereYear('month', $year)
            ->get()
            ->keyBy(static fn (BudgetProgress $row): string => $row->category_id.'-'.$row->monthKey());

        $rows = [];

        // Hanya kategori induk yang jadi baris utama. Tiap sub-kategori
        // muncul SEKALI sebagai baris bersarang di bawah induknya; `children`
        // sudah eager-load di query di atas.
        foreach ($categories->whereNull('parent_id') as $category) {
            $children = $category->children->sortBy('name')->values();

            $rows[] = $this->row($category, $year, $budgets, $progress, $children);

            foreach ($children as $child) {
                $rows[] = $this->row($child, $year, $budgets, $progress, nested: true);
            }
        }

        return $rows;
    }

    /**
     * @param  Collection<string, Budget>  $budgets
     * @param  Collection<string, BudgetProgress>  $progress
     * @param  iterable<int, Category>  $children
     * @return array<string, mixed>
     */
    private function row(
        Category $category,
        int $year,
        $budgets,
        $progress,
        iterable $children = [],
        bool $nested = false,
    ): array {
        $months = [];

        foreach (MonthPeriod::monthsOfYear($year) as $month) {
            $key = MonthPeriod::key($month);
            /** @var Budget|null $budget */
            $budget = $budgets->get($category->id.'-'.$key);
            $ownLimit = $budget?->limit_amount;

            // Baris induk merangkum cabangnya: limit sendiri + limit semua
            // sub-kategori. Untuk baris daun, `$children` kosong sehingga
            // `limit` tetap limit kategorinya sendiri.
            $childLimits = [];

            foreach ($children as $child) {
                $childLimits[] = $budgets->get($child->id.'-'.$key)?->limit_amount;
            }

            $childTotal = Money::sum($childLimits);
            $hasLimit = $ownLimit !== null || Money::toCents($childTotal) !== 0;
            $limit = $hasLimit ? Money::add($ownLimit, $childTotal) : null;

            $progressRow = $progress->get($category->id.'-'.$key);
            $used = $progressRow === null ? '0.00' : (string) $progressRow->used_amount;
            $used = Money::fromCents(Money::toCents($used));
            $percent = $limit === null ? null : Money::ratioOf($used, $limit);

            $months[] = [
                'month' => $key,
                'budget_id' => $budget?->id,
                'limit' => $limit,
                // Limit kategori ini sendiri, tanpa sub-kategori. Dipakai form
                // ubah limit supaya tidak menimpa nilai gabungan cabang.
                'own_limit' => $ownLimit,
                'used' => $used,
                'remaining' => $limit === null ? null : Money::subtract($limit, $used),
                'percent' => $percent,
                'status' => BudgetStatus::fromPercent($percent)->value,
            ];
        }

        return [
            'id' => (int) $category->id,
            'name' => $category->name,
            'color' => $category->color,
            'parent_id' => $category->parent_id === null ? null : (int) $category->parent_id,
            'has_children' => $category->children->isNotEmpty(),
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
     * Total hanya menjumlah baris INDUK. Baris induk sudah menggabungkan limit
     * & pemakaian seluruh sub-kategorinya (lihat {@see self::categoryRows()}),
     * jadi sub-kategori tidak ikut dijumlah lagi — tidak ada hitung ganda.
     * `by_status` tetap menghitung tiap kategori sekali supaya tetap terlihat
     * kategori mana yang sedang waspada/lewat.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function summary(array $rows, CarbonImmutable $month): array
    {
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
                if ($cell['month'] === $monthKey) {
                    $byStatus[$cell['status']] = $byStatus[$cell['status']] + 1;
                }
            }

            // Angka cabang sudah ada di baris induknya.
            if ($row['parent_id'] !== null) {
                continue;
            }

            foreach ($row['months'] as $cell) {
                if ($cell['limit'] === null) {
                    continue;
                }

                $totalLimit = Money::add($totalLimit, $cell['limit']);
                $totalUsed = Money::add($totalUsed, $cell['used']);
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
