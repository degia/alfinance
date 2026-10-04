<?php

namespace App\Models;

use App\Jobs\RecomputeDashboardSnapshotJob;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Database\Factories\DashboardDailySnapshotFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Rekap arus kas harian (ARCHITECTURE.md §2.3 butir 3, PRD.md §3.1).
 *
 * Granularitas harian dari {@see DashboardSnapshot}, ditulis oleh job yang sama
 * ({@see RecomputeDashboardSnapshotJob}) dalam satu agregasi. Line chart harian
 * di dashboard membaca tabel ini — bukan menjumlahkan `transactions` per tanggal
 * di jalur request.
 *
 * Hanya tanggal yang punya transaksi yang punya baris; hari kosong diisi nol
 * oleh pembaca supaya garis grafik tidak terputus.
 *
 * @property int $id
 * @property int $workspace_id
 * @property CarbonImmutable $date
 * @property string $total_income
 * @property string $total_expense
 * @property string $net_cash_flow
 * @property int $transaction_count
 * @property CarbonImmutable|null $generated_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
#[Fillable([
    'date',
    'total_income',
    'total_expense',
    'net_cash_flow',
    'transaction_count',
    'generated_at',
])]
class DashboardDailySnapshot extends WorkspaceScopedModel
{
    /** @use HasFactory<DashboardDailySnapshotFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'total_income' => 'decimal:2',
            'total_expense' => 'decimal:2',
            'net_cash_flow' => 'decimal:2',
            'transaction_count' => 'integer',
            'generated_at' => 'datetime',
        ];
    }

    /**
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeOfMonth(Builder $query, CarbonImmutable $month): Builder
    {
        return $query->whereBetween('date', [
            $month->startOfMonth()->toDateString(),
            $month->endOfMonth()->toDateString(),
        ]);
    }

    /**
     * Kunci teks `Y-m-d` untuk payload Inertia & indeks array.
     */
    public function dateKey(): string
    {
        return CarbonImmutable::parse($this->date)->toDateString();
    }

    public function hasSurplus(): bool
    {
        return Money::toCents($this->net_cash_flow) > 0;
    }
}
