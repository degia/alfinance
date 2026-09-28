<?php

namespace App\Models;

use App\Enums\DebtDirection;
use App\Enums\DebtStatus;
use App\Services\Debt\DebtService;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Database\Factories\DebtFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Utang (saya berutang) & piutang (orang berutang) — PRD.md §3.7.
 *
 * `remaining` disimpan di tabel, bukan dihitung on-the-fly, karena daftar
 * dan laporan membacanya berulang kali. Konsistensinya dijaga
 * {@see DebtService}: setiap pembayaran yang dicatat
 * langsung mengurangi `remaining` lalu menyegarkan `status`.
 *
 * Utang TIDAK pernah dihapus kalau sudah punya riwayat pembayaran: status
 * `settled` yang menandai akhir, bukan penghapusan baris.
 *
 * @property int $id
 * @property int $workspace_id
 * @property int|null $account_id
 * @property DebtDirection $direction
 * @property string $counterparty
 * @property string $principal
 * @property string $remaining
 * @property string|null $interest_rate
 * @property CarbonImmutable|null $start_date
 * @property CarbonImmutable|null $due_date
 * @property int|null $term_count
 * @property DebtStatus $status
 * @property bool $include_in_net_worth
 * @property string|null $note
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
#[Fillable([
    'account_id',
    'direction',
    'counterparty',
    'principal',
    'remaining',
    'interest_rate',
    'start_date',
    'due_date',
    'term_count',
    'status',
    'include_in_net_worth',
    'note',
])]
class Debt extends WorkspaceScopedModel
{
    /** @use HasFactory<DebtFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'direction' => DebtDirection::class,
            'status' => DebtStatus::class,
            'principal' => 'decimal:2',
            'remaining' => 'decimal:2',
            'interest_rate' => 'decimal:2',
            'start_date' => 'date',
            'due_date' => 'date',
            'term_count' => 'integer',
            'include_in_net_worth' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * @return HasMany<DebtPayment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(DebtPayment::class);
    }

    /**
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopePayable(Builder $query): Builder
    {
        return $query->where('direction', DebtDirection::Payable->value);
    }

    /**
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeReceivable(Builder $query): Builder
    {
        return $query->where('direction', DebtDirection::Receivable->value);
    }

    /**
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeWithStatus(Builder $query, DebtStatus $status): Builder
    {
        return $query->where('status', $status->value);
    }

    public function isPayable(): bool
    {
        return $this->direction->isPayable();
    }

    public function isSettled(): bool
    {
        return Money::toCents($this->remaining) === 0;
    }

    /**
     * Status menurut data terkini (sisa + jatuh tempo), bukan kolom tersimpan.
     *
     * Kolom `status` selalu disegarkan setiap kali data berubah, tapi
     * tanggal yang lewat tanpa ada pembayaran membuat kolomnya basi — jadi
     * tampilan memakai hasil hitung ulang ini.
     */
    public function currentStatus(?CarbonImmutable $today = null): DebtStatus
    {
        if (Money::toCents($this->remaining) <= 0) {
            return DebtStatus::Settled;
        }

        $today ??= CarbonImmutable::today();

        return $this->due_date !== null && CarbonImmutable::parse($this->due_date)->lessThan($today)
            ? DebtStatus::Overdue
            : DebtStatus::Ongoing;
    }

    public function isOverdue(?CarbonImmutable $today = null): bool
    {
        return $this->currentStatus($today) === DebtStatus::Overdue;
    }

    /**
     * Nominal yang sudah dibayar = pokok − sisa.
     */
    public function paidAmount(): string
    {
        return Money::fromCents(max(0, Money::toCents($this->principal) - Money::toCents($this->remaining)));
    }

    /**
     * Persentase pelunasan (0–100). Null bila pokok nol.
     */
    public function paidPercent(): ?float
    {
        return Money::ratioOf($this->paidAmount(), $this->principal);
    }

    /**
     * Sisa hari menuju jatuh tempo; negatif berarti terlambat.
     * Null bila `due_date` kosong.
     */
    public function daysUntilDue(?CarbonImmutable $today = null): ?int
    {
        if ($this->due_date === null) {
            return null;
        }

        $today ??= CarbonImmutable::today();

        return (int) intdiv(
            CarbonImmutable::parse($this->due_date)->startOfDay()->getTimestamp()
                - $today->startOfDay()->getTimestamp(),
            86400
        );
    }

    /**
     * Nominal per cicilan bila jadwalnya ditentukan (`term_count`).
     * Pembulatan sisa pokok ditambahkan ke cicilan terakhir.
     */
    public function installmentAmount(): ?string
    {
        if ($this->term_count === null || $this->term_count < 1) {
            return null;
        }

        return Money::fromCents(intdiv(Money::toCents($this->principal), $this->term_count));
    }

    /**
     * Jumlah cicilan yang sudah tercatat.
     *
     * Kalau relasi sudah dimuat dengan `withCount('payments')` — seperti di
     * daftar utang, yang memetakan banyak baris sekaligus — angka itu dipakai
     * langsung supaya satu halaman tidak memicu satu query per utang. Kalau
     * belum, jatuh ke query eksplisit per workspace (bukan lewat relasi) supaya
     * hitungannya sama dengan `DebtService::payments()` di konteks apa pun,
     * termasuk saat dipanggil dari job atau console tanpa `ActiveWorkspace`.
     */
    public function paidTermCount(): int
    {
        if (array_key_exists('payments_count', $this->attributes)) {
            return (int) $this->attributes['payments_count'];
        }

        return DebtPayment::allWorkspaces()
            ->where('workspace_id', $this->workspace_id)
            ->where('debt_id', $this->id)
            ->count();
    }

    /**
     * Jatuh tempo berikutnya: tanggal jatuh tempo digeser satu bulan untuk
     * setiap cicilan yang sudah dibayar. Null bila tidak ada jadwal.
     */
    public function nextDueDate(?CarbonImmutable $today = null): ?CarbonImmutable
    {
        if ($this->due_date === null) {
            return null;
        }

        $today ??= CarbonImmutable::today();
        $base = CarbonImmutable::parse($this->due_date);
        $next = $base->addMonthsNoOverflow($this->paidTermCount());

        return $next->lessThan($today) ? $today : $next;
    }
}
