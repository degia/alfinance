<?php

namespace App\Services\Budgets;

use App\Enums\TransactionType;
use App\Events\BudgetUpdated;
use App\Jobs\RecomputeBudgetProgressJob;
use App\Models\Budget;
use App\Models\BudgetProgress;
use App\Models\Category;
use App\Models\Transaction;
use App\Support\Money;
use App\Support\MonthPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Baca & tulis anggaran beserta agregat pemakaiannya (PRD.md §3.5).
 *
 * Aturan main ARCHITECTURE.md §2.3: angka "sudah terpakai" hanya boleh ditulis
 * lewat {@see RecomputeBudgetProgressJob} yang memanggil
 * {@see self::recomputeForMonth()}, atau lewat service ini sendiri saat limit
 * berubah. Halaman anggaran membaca `budget_progress_cache` apa adanya —
 * tidak pernah menjumlahkan `transactions` sendiri. Semua operasi tulis
 * dibungkus DB transaction supaya limit + cache tidak terlihat setengah jadi.
 *
 * Tidak memakai `ActiveWorkspace` supaya bisa dipanggil dari scheduled job
 * (yang jalan tanpa request/session); tiap query sudah diberi `workspace_id`
 * secara eksplisit.
 */
class BudgetService
{
    /**
     * Simpan limit satu kategori untuk satu bulan (create/update).
     *
     * Kategori induk dan sub-kategori boleh punya limit sendiri; pemakaian
     * sub-kategori ikut terhitung pada limit induk (lihat
     * {@see self::rollupMapFor()}).
     *
     * @param  array{limit_amount: string, month?: string}  $attributes
     */
    public function upsert(array $attributes, Category $category, ?int $actorId = null): Budget
    {
        $month = MonthPeriod::from($attributes['month'] ?? CarbonImmutable::now()->format('Y-m'));
        $limit = Money::fromCents(Money::toCents($attributes['limit_amount']));

        $budget = DB::transaction(function () use ($category, $month, $limit, $actorId): Budget {
            $budget = Budget::query()
                ->where('category_id', $category->id)
                ->whereYear('month', $month->year)
                ->whereMonth('month', $month->month)
                ->lockForUpdate()
                ->first();

            if ($budget === null) {
                $budget = new Budget;
                $budget->category_id = (int) $category->id;
                $budget->month = $month;
                $budget->created_by = $actorId;
            }

            $budget->limit_amount = $limit;
            $budget->updated_by = $actorId;
            $budget->save();

            // Limit berubah -> cache lama tidak berlaku lagi, jadi langsung
            // disegarkan supaya UI tidak sempat membaca angka basi.
            $this->writeProgress(
                (int) $category->workspace_id,
                (int) $category->id,
                $month,
                $this->spentFor(
                    (int) $category->workspace_id,
                    $this->rollupMapFor((int) $category->workspace_id)[(int) $category->id] ?? [(int) $category->id],
                    $month,
                ),
            );

            return $budget;
        });

        BudgetUpdated::dispatch($budget, $actorId);

        return $budget;
    }

    /**
     * Ubah limit baris yang sudah ada — dipakai form edit matrix inline.
     */
    public function updateLimit(Budget $budget, string $limit, ?int $actorId = null): Budget
    {
        $workspaceId = (int) $budget->workspace_id;
        $categoryId = (int) $budget->category_id;
        $month = CarbonImmutable::parse($budget->month)->startOfMonth();
        $normalized = Money::fromCents(Money::toCents($limit));

        $updated = DB::transaction(function () use ($budget, $workspaceId, $categoryId, $month, $normalized, $actorId): Budget {
            $budget->limit_amount = $normalized;
            $budget->updated_by = $actorId;
            $budget->save();

            $this->writeProgress(
                $workspaceId,
                $categoryId,
                $month,
                $this->spentFor($workspaceId, $this->rollupFor($workspaceId, $categoryId), $month),
            );

            return $budget;
        });

        BudgetUpdated::dispatch($updated, $actorId);

        return $updated;
    }

    /**
     * Hapus limit kategori.
     *
     * Baris cache dibiarkan (dipakai nol) supaya `RecomputeBudgetProgressJob`
     * tidak perlu membuat baris hanya untuk menemukan tidak ada limit lagi.
     */
    public function delete(Budget $budget, ?int $actorId = null): void
    {
        $workspaceId = (int) $budget->workspace_id;
        $categoryId = (int) $budget->category_id;
        $month = CarbonImmutable::parse($budget->month)->startOfMonth();

        DB::transaction(function () use ($budget, $workspaceId, $categoryId, $month): void {
            $budget->delete();

            $this->writeProgress(
                $workspaceId,
                $categoryId,
                $month,
                $this->spentFor($workspaceId, $this->rollupFor($workspaceId, $categoryId), $month),
            );
        });

        BudgetUpdated::dispatch($budget, $actorId);
    }

    /**
     * Hitung ulang `budget_progress_cache` untuk seluruh kategori di satu bulan.
     *
     * Dipanggil job, bukan controller, jadi agregat bisa dibangun ulang kapan
     * saja tanpa infrastruktur web yang harus ikut berubah.
     */
    public function recomputeForMonth(int $workspaceId, string $month): void
    {
        $month = MonthPeriod::from($month);

        $categoryIds = Category::allWorkspaces()
            ->where('workspace_id', $workspaceId)
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        if ($categoryIds === []) {
            return;
        }

        // Satu query transaksi untuk semua kategori: total per kategori
        // digabung ke rollup induknya di PHP, jadi database yang melakukan
        // group-by dan aplikasi tidak menarik ribuan baris.
        $totals = $this->spentByCategory($workspaceId, $month, $categoryIds);

        DB::transaction(function () use ($workspaceId, $month, $categoryIds, $totals): void {
            foreach ($categoryIds as $categoryId) {
                $this->writeProgress(
                    $workspaceId,
                    $categoryId,
                    $month,
                    $totals[$categoryId] ?? '0.00',
                );
            }
        });
    }

    /**
     * Pemakaian bulan ini untuk satu kategori (sub-kategori ikut terhitung).
     */
    public function calculateUsed(Category $category, CarbonImmutable $month): string
    {
        $categoryId = (int) $category->id;
        $rollup = $this->rollupMapFor((int) $category->workspace_id);

        return $this->spentFor(
            (int) $category->workspace_id,
            $rollup[$categoryId] ?? [$categoryId],
            $month,
        );
    }

    /**
     * Pemakaian sekumpulan kategori pada satu bulan.
     *
     * Hanya transaksi POSTED bertipe expense yang dihitung: instance pending
     * belum menyentuh saldo (ARCHITECTURE.md §2.2) dan transfer antar akun
     * internal bukan pengeluaran. Pengembalian/refund tetap bertipe expense
     * dengan nilai lebih kecil, jadi otomatis mengurangi pemakaian.
     *
     * `workspace_id` selalu eksplisit walau `category_id` sudah unik global —
     * begini tidak ada jalur baca yang bisa lolos dari tenant lain.
     *
     * @param  array<int, int>  $categoryIds
     */
    public function spentFor(int $workspaceId, array $categoryIds, CarbonImmutable $month): string
    {
        if ($categoryIds === []) {
            return '0.00';
        }

        [$from, $to] = MonthPeriod::bounds($month);

        $total = Transaction::allWorkspaces()
            ->where('workspace_id', $workspaceId)
            ->whereIn('category_id', $categoryIds)
            ->posted()
            ->ofType(TransactionType::Expense)
            ->occurredBetween($from->toDateString(), $to->toDateString())
            ->sum('amount');

        return Money::atLeastZero(Money::fromDatabaseSum($total));
    }

    /**
     * Peta `kategori_induk => [kategori_induk, ...sub]`.
     *
     * Satu query untuk seluruh workspace, bukan satu query per kategori —
     * jumlah kategori bisa ratusan di workspace yang aktif serius.
     *
     * @return array<int, array<int, int>>
     */
    public function rollupMapFor(int $workspaceId): array
    {
        $categories = Category::allWorkspaces()
            ->where('workspace_id', $workspaceId)
            ->get(['id', 'parent_id']);

        $map = [];

        foreach ($categories as $category) {
            $id = (int) $category->id;
            $map[$id] = [$id];
        }

        foreach ($categories as $category) {
            $parentId = $category->parent_id;

            if ($parentId === null) {
                continue;
            }

            $parentId = (int) $parentId;
            $map[$parentId] ??= [$parentId];

            if (! in_array((int) $category->id, $map[$parentId], true)) {
                $map[$parentId][] = (int) $category->id;
            }
        }

        return $map;
    }

    /**
     * Satu kategori beserta sub-kategorinya untuk satu workspace.
     *
     * @return array<int, int>
     */
    public function rollupFor(int $workspaceId, int $categoryId): array
    {
        return $this->rollupMapFor($workspaceId)[$categoryId] ?? [$categoryId];
    }

    /**
     * Total terpakai per kategori (termasuk sub-kategori) untuk satu bulan.
     *
     * @param  array<int, int>  $categoryIds
     * @return array<int, string> `category_id => "1234.00"`
     */
    private function spentByCategory(int $workspaceId, CarbonImmutable $month, array $categoryIds): array
    {
        $rollup = $this->rollupMapFor($workspaceId);

        // Sub-kategori yang diminta orang tua langsung ikut tercatat di
        // cache-nya sendiri, jadi map induk tetap berisi keduanya.
        $parents = [];

        foreach ($categoryIds as $categoryId) {
            $categoryId = (int) $categoryId;
            $parents[$categoryId] = $rollup[$categoryId] ?? [$categoryId];
        }

        $members = array_values(array_unique(array_merge(...array_values($parents))));

        if ($members === []) {
            return [];
        }

        [$from, $to] = MonthPeriod::bounds($month);

        $rows = Transaction::allWorkspaces()
            ->where('workspace_id', $workspaceId)
            ->posted()
            ->ofType(TransactionType::Expense)
            ->whereIn('category_id', $members)
            ->occurredBetween($from->toDateString(), $to->toDateString())
            ->select('category_id')
            ->selectRaw('COALESCE(SUM(amount), 0) as spent_total')
            ->groupBy('category_id')
            ->pluck('spent_total', 'category_id');

        $totals = array_fill_keys(array_keys($parents), 0);

        foreach ($rows as $categoryId => $total) {
            $cents = Money::toCents((string) $total);

            foreach ($parents as $parentId => $children) {
                if (in_array((int) $categoryId, $children, true)) {
                    $totals[$parentId] += $cents;
                }
            }
        }

        return array_map(
            static fn (int $cents): string => Money::atLeastZero(Money::fromCents($cents)),
            $totals,
        );
    }

    /**
     * Tulis/segarkan satu baris cache secara idempoten (upsert manual supaya
     * `budget_progress_cache` tetap tanpa `updated_at` semu).
     */
    private function writeProgress(int $workspaceId, int $categoryId, CarbonImmutable $month, string $used): void
    {
        $progress = BudgetProgress::allWorkspaces()
            ->where('workspace_id', $workspaceId)
            ->where('category_id', $categoryId)
            ->whereYear('month', $month->year)
            ->whereMonth('month', $month->month)
            ->lockForUpdate()
            ->first();

        if ($progress === null) {
            $progress = new BudgetProgress;
            $progress->workspace_id = $workspaceId;
        }

        $progress->category_id = $categoryId;
        $progress->month = $month;
        $progress->used_amount = Money::atLeastZero(Money::fromCents(Money::toCents($used)));
        $progress->save();
    }
}
