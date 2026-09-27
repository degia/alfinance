<?php

namespace App\Models;

use App\Jobs\GenerateNetWorthSnapshotJob;
use App\Support\Money;
use App\Support\MonthPeriod;
use Carbon\CarbonImmutable;
use Database\Factories\NetWorthSnapshotFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Rekap net worth bulanan (PRD.md §3.6, ARCHITECTURE.md §2.3).
 *
 * Satu baris per workspace per bulan, ditulis job terjadwal
 * ({@see GenerateNetWorthSnapshotJob}). Snapshot bulan yang sedang
 * berjalan sengaja ikut ditulis setiap kali job jalan supaya grafik tren
 * tidak menunggu sampai bulan berganti.
 *
 * @property int $id
 * @property int $workspace_id
 * @property CarbonImmutable $month
 * @property string $total_assets
 * @property string $total_liabilities
 * @property string $net_worth
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
#[Fillable(['month', 'total_assets', 'total_liabilities', 'net_worth'])]
class NetWorthSnapshot extends WorkspaceScopedModel
{
    /** @use HasFactory<NetWorthSnapshotFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'month' => 'date',
            'total_assets' => 'decimal:2',
            'total_liabilities' => 'decimal:2',
            'net_worth' => 'decimal:2',
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

    public function monthKey(): string
    {
        return MonthPeriod::key(CarbonImmutable::parse($this->month));
    }

    /**
     * Perubahan net worth dibanding snapshot sebelumnya.
     *
     * @return array{direction: string, amount: string}
     */
    public function change(NetWorthSnapshot $previous): array
    {
        $delta = Money::subtract($this->net_worth, $previous->net_worth);

        return [
            'direction' => Money::isNegative($delta) ? 'down' : 'up',
            'amount' => Money::absolute($delta),
        ];
    }
}
