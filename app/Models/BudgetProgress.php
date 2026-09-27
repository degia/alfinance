<?php

namespace App\Models;

use App\Jobs\RecomputeBudgetProgressJob;
use App\Support\MonthPeriod;
use Carbon\CarbonImmutable;
use Database\Factories\BudgetProgressFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Agregat "sudah terpakai" per kategori per bulan (ARCHITECTURE.md §2.3).
 *
 * Tabel ini adalah sumber angka progress yang dibaca halaman anggaran.
 * Nilainya ditulis {@see RecomputeBudgetProgressJob}, bukan dari
 * request — jadi halaman hanya melakukan `SELECT`, tidak pernah
 * `SUM()` ke tabel `transactions`.
 *
 * `used_amount` sengaja pocong nol: kategori tanpa transaksi apa pun belum punya
 * baris, dan itu sama artinya dengan nol terpakai.
 *
 * @property int $id
 * @property int $workspace_id
 * @property int $category_id
 * @property CarbonImmutable $month
 * @property string $used_amount
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
#[Fillable(['category_id', 'month', 'used_amount'])]
class BudgetProgress extends WorkspaceScopedModel
{
    /** @use HasFactory<BudgetProgressFactory> */
    use HasFactory;

    protected $table = 'budget_progress_cache';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'month' => 'date',
            'used_amount' => 'decimal:2',
            'category_id' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeOfMonth(Builder $query, CarbonImmutable $month): Builder
    {
        return $query->whereYear('month', $month->year)->whereMonth('month', $month->month);
    }

    /**
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeOfYear(Builder $query, int $year): Builder
    {
        return $query->whereYear('month', $year);
    }

    public function monthKey(): string
    {
        return MonthPeriod::key(CarbonImmutable::parse($this->month));
    }
}
