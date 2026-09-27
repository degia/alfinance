<?php

namespace App\Models;

use App\Enums\BudgetStatus;
use App\Support\Money;
use App\Support\MonthPeriod;
use Carbon\CarbonImmutable;
use Database\Factories\BudgetFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Limit anggaran per kategori per bulan (PRD.md §3.5).
 *
 * Baris ini hanya menyimpan "limit". Angka yang sudah terpakai lived di
 * {@see BudgetProgress} (tabel agregat) supaya halaman anggaran tidak
 * menjumlahkan `transactions` mentah setiap kali dibuka
 * (ARCHITECTURE.md §2.3).
 *
 * @property int $id
 * @property int $workspace_id
 * @property int $category_id
 * @property CarbonImmutable $month
 * @property string $limit_amount
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read BudgetProgress|null $progress
 */
#[Fillable(['category_id', 'month', 'limit_amount'])]
class Budget extends WorkspaceScopedModel
{
    /** @use HasFactory<BudgetFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'month' => 'date',
            'limit_amount' => 'decimal:2',
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
     * Baris cache pemakaian untuk kategori + bulan yang sama.
     *
     * Pasangan key-nya majemuk (`category_id` + `month`), jadi `whereColumn`
     * tidak bisa dipakai: query `hasOne` tidak pernah join tabel induknya dan
     * SQL-nya akan gagal. Karena itu relasi ini hanya untuk pemuatan malas,
     * dengan constrain per-instance. Untuk pemuatan massal, ambil
     * {@see BudgetProgress} satu query lalu pasang dengan `setRelation()`.
     *
     * @return HasOne<BudgetProgress, $this>
     */
    public function progress(): HasOne
    {
        return $this->hasOne(BudgetProgress::class, 'category_id', 'category_id')
            ->where('month', CarbonImmutable::parse($this->month)->toDateString());
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

    /**
     * Kunci bulan "YYYY-MM" — dipakai untuk payload Inertia.
     */
    public function monthKey(): string
    {
        return MonthPeriod::key(CarbonImmutable::parse($this->month));
    }

    /**
     * Nominal terpakai pada bulan ini (dari tabel agregat).
     */
    public function usedAmount(): string
    {
        $used = $this->progress?->used_amount;

        return $used === null ? Money::fromCents(0) : $used;
    }

    /**
     * Persentase pemakaian. Null berarti belum ada limit sehingga progress bar
     * tidak boleh ditampilkan. Tidak dibatasi 100% supaya anggaran yang lewat
     * tetap terlihat (> 100% = merah).
     */
    public function usedPercent(): ?float
    {
        return Money::ratioOf($this->usedAmount(), $this->limit_amount);
    }

    /**
     * Status instance (PRD.md §3.5): hijau < 80%, kuning 80–100%, merah > 100%.
     */
    public function status(): BudgetStatus
    {
        return BudgetStatus::fromPercent($this->usedPercent());
    }

    /**
     * Sisa anggaran; negatif berarti sudah lewat.
     */
    public function remaining(): string
    {
        return Money::subtract($this->limit_amount, $this->usedAmount());
    }
}
