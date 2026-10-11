<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\HitunganPlanFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Rencana anggaran bulanan halaman "Hitungan".
 *
 * Satu baris per workspace (`workspace_id` unik). `balance`, `expenses_today`,
 * tanggal mulai, dan saldo minimum tidak disimpan: semuanya dihitung ulang dari
 * data akun, transaksi, dan tanggal server saat halaman dibuka. Yang tersimpan
 * hanya input yang memang diketik user.
 *
 * @property int $id
 * @property int $workspace_id
 * @property string $daily_limit
 * @property CarbonImmutable|null $end_date
 * @property array<int, array{name: string, plan: string, actual: string}> $rows
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
#[Fillable(['daily_limit', 'end_date', 'rows'])]
class HitunganPlan extends WorkspaceScopedModel
{
    /** @use HasFactory<HitunganPlanFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'daily_limit' => 'decimal:2',
            'end_date' => 'date',
            'rows' => 'array',
        ];
    }
}
