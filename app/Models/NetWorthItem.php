<?php

namespace App\Models;

use App\Enums\NetWorthItemType;
use App\Enums\NetWorthSubtype;
use App\Services\NetWorth\NetWorthService;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Database\Factories\NetWorthItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Aset / kewajiban manual untuk menghitung net worth (PRD.md §3.6).
 *
 * Yang TIDAK disimpan di sini: saldo kartu kredit. Kewajiban itu sudah
 * hidup di `accounts.cached_balance` dan disinkronkan ulang setiap snapshot
 * (lihat {@see NetWorthService}), jadi tidak bisa
 * terhitung dua kali.
 *
 * @property int $id
 * @property int $workspace_id
 * @property NetWorthItemType $type
 * @property NetWorthSubtype $subtype
 * @property string $name
 * @property string $value
 * @property string|null $annual_rate
 * @property string|null $note
 * @property CarbonImmutable $valued_at
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
#[Fillable(['type', 'subtype', 'name', 'value', 'annual_rate', 'note', 'valued_at'])]
class NetWorthItem extends WorkspaceScopedModel
{
    /** @use HasFactory<NetWorthItemFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => NetWorthItemType::class,
            'subtype' => NetWorthSubtype::class,
            'value' => 'decimal:2',
            'annual_rate' => 'decimal:2',
            'valued_at' => 'date',
        ];
    }

    /**
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeOfType(Builder $query, NetWorthItemType $type): Builder
    {
        return $query->where('type', $type->value);
    }

    /**
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeAssets(Builder $query): Builder
    {
        return $query->where('type', NetWorthItemType::Asset->value);
    }

    /**
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeLiabilities(Builder $query): Builder
    {
        return $query->where('type', NetWorthItemType::Liability->value);
    }

    public function isAsset(): bool
    {
        return $this->type === NetWorthItemType::Asset;
    }

    /**
     * Nilai berjalan pada tanggal tertentu.
     *
     * `annual_rate` (persen per tahun) dik compounding dari `valued_at` ke
     * tanggal acuan. Rate NULL berarti nilai tetap seperti yang diinput —
     * itu kasus paling umum, jadi tidak perlu aritmetika apa pun.
     */
    public function valueAt(CarbonImmutable $asOf): string
    {
        $rate = $this->annual_rate;

        if ($rate === null || Money::isNegative($rate)) {
            return $this->value;
        }

        $rateCents = Money::toCents($rate);

        if ($rateCents === 0) {
            return $this->value;
        }

        // Selisih hari dihitung dari timestamp supaya tidak bergantung pada
        // tanda balik default `diffInDays()` yang beda antar versi Carbon.
        $start = CarbonImmutable::parse($this->valued_at)->startOfDay()->getTimestamp();
        $elapsedDays = (int) intdiv($asOf->startOfDay()->getTimestamp() - $start, 86400);

        if ($elapsedDays <= 0) {
            return $this->value;
        }

        // Compounding per tahun penuh; sisa hari diabaikan karena pengaruhnya
        // di bawah satu sen untuk rate dan durasi yang wajar.
        $years = intdiv($elapsedDays, 365);
        $factor = (1 + ($rateCents / 10000)) ** $years;

        return Money::fromCents((int) round(Money::toCents($this->value) * $factor));
    }

    public function valueNow(): string
    {
        return $this->valueAt(CarbonImmutable::today());
    }
}
