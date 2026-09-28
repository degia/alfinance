<?php

namespace App\Models;

use App\Jobs\RecomputeDashboardSnapshotJob;
use App\Support\Money;
use App\Support\MonthPeriod;
use Carbon\CarbonImmutable;
use Database\Factories\DashboardSnapshotFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Rekap arus kas bulanan (ARCHITECTURE.md §2.3 butir 3, PRD.md §3.1).
 *
 * Satu baris per workspace per bulan, ditulis
 * {@see RecomputeDashboardSnapshotJob} dari antrean. Dashboard dan
 * laporan arus kas membaca tabel ini — tidak pernah menjumlahkan `transactions`
 * di jalur request.
 *
 * Baris bulan yang sedang berjalan ikut ditulis ulang setiap kali job jalan,
 * karena angkanya masih berubah sampai bulan berganti (sama seperti
 * {@see NetWorthSnapshot}).
 *
 * @property int $id
 * @property int $workspace_id
 * @property CarbonImmutable $month
 * @property string $total_income
 * @property string $total_expense
 * @property string $total_transfer
 * @property string $net_cash_flow
 * @property int $transaction_count
 * @property CarbonImmutable|null $generated_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
#[Fillable([
    'month',
    'total_income',
    'total_expense',
    'total_transfer',
    'net_cash_flow',
    'transaction_count',
    'generated_at',
])]
class DashboardSnapshot extends WorkspaceScopedModel
{
    /** @use HasFactory<DashboardSnapshotFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'month' => 'date',
            'total_income' => 'decimal:2',
            'total_expense' => 'decimal:2',
            'total_transfer' => 'decimal:2',
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
        return $query->whereYear('month', $month->year)->whereMonth('month', $month->month);
    }

    /**
     * Rentang bulan inklusif untuk tren 6/12 bulan.
     *
     * `whereYear`/`whereMonth` per sisi bisa memakai index
     * `(workspace_id, month)`; batas bulan pertama/last sudah dinormalkan.
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeBetweenMonths(Builder $query, CarbonImmutable $from, CarbonImmutable $to): Builder
    {
        return $query
            ->where('month', '>=', $from->startOfMonth()->toDateString())
            ->where('month', '<=', $to->startOfMonth()->toDateString());
    }

    public function monthKey(): string
    {
        return MonthPeriod::key(CarbonImmutable::parse($this->month));
    }

    public function hasSurplus(): bool
    {
        return Money::toCents($this->net_cash_flow) > 0;
    }
}
