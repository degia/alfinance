<?php

namespace App\Models;

use App\Enums\AccountType;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Database\Factories\AccountFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Akun kas / bank / e-wallet / kartu kredit (PRD.md §3.2).
 *
 * `initial_balance` adalah angka yang diketik user saat membuat akun.
 * `cached_balance` adalah saldo terkini yang di-maintain di jalur tulis
 * (ARCHITECTURE.md §2.1 butir 2) — bukan hasil agregasi on-the-fly.
 * Saat ini (Fase 2) keduanya sama; pembaruan `cached_balance` oleh
 * transaksi arrive di Fase 3.
 *
 * Akun TIDAK PERNAH dihapus: hanya di-archive lewat {@see archive()} supaya
 * histori transaksi tetap referensial.
 *
 * @property int $id
 * @property int $workspace_id
 * @property AccountType $type
 * @property string $name
 * @property string $initial_balance
 * @property string $cached_balance
 * @property string|null $credit_limit
 * @property int|null $billing_day
 * @property int|null $due_day
 * @property string|null $notes
 * @property CarbonImmutable|null $archived_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
#[Fillable([
    'type',
    'name',
    'initial_balance',
    'credit_limit',
    'billing_day',
    'due_day',
    'notes',
])]
class Account extends WorkspaceScopedModel
{
    /** @use HasFactory<AccountFactory> */
    use HasFactory;

    /**
     * Akun yang belum di-archive.
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
    }

    /**
     * Akun yang sudah di-archive.
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeArchived(Builder $query): Builder
    {
        return $query->whereNotNull('archived_at');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => AccountType::class,
            'initial_balance' => 'decimal:2',
            'cached_balance' => 'decimal:2',
            'credit_limit' => 'decimal:2',
            'billing_day' => 'integer',
            'due_day' => 'integer',
            'archived_at' => 'datetime',
        ];
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    public function isCredit(): bool
    {
        return $this->type->isLiability();
    }

    /**
     * Arsipkan akun (soft-archive, PRD.md §3.2).
     */
    public function archive(): void
    {
        if ($this->isArchived()) {
            return;
        }

        $this->forceFill(['archived_at' => now()])->save();
    }

    public function restore(): void
    {
        if (! $this->isArchived()) {
            return;
        }

        $this->forceFill(['archived_at' => null])->save();
    }

    /**
     * Geser `cached_balance` sebesar delta tertentu.
     *
     * `cached_balance` sengaja tidak fillable supaya tidak bisa diubah lewat
     * mass assignment dari form. Satu-satunya jalur tulis yang sah adalah
     * method ini (dipakai controller saat koreksi saldo awal, dan oleh
     * transaksi di Fase 3).
     */
    public function applyBalanceDelta(string|int|null $delta): void
    {
        $this->cached_balance = Money::add($this->cached_balance, $delta);
        $this->save();
    }

    /**
     * Sisa limit yang masih bisa dipakai.
     *
     * Hanya relevan untuk kartu kredit: `credit_limit` dikurangi saldo
     * negatif (utang outstanding). Akun non-kartu kredit mengembalikan null
     * supaya UI tidak menampilkan apa pun.
     */
    public function availableCredit(): ?string
    {
        if (! $this->isCredit() || $this->credit_limit === null) {
            return null;
        }

        return Money::atLeastZero(
            Money::subtract($this->credit_limit, $this->outstandingBalance())
        );
    }

    /**
     * Utang outstanding pada kartu kredit (saldo negatif sebagai nilai positif).
     */
    public function outstandingBalance(): string
    {
        return Money::isNegative($this->cached_balance)
            ? Money::absolute($this->cached_balance)
            : Money::fromCents(0);
    }

    /**
     * Persentase limit yang sudah terpakai (0–100), null bila tidak relevan.
     */
    public function creditUsagePercent(): ?float
    {
        if (! $this->isCredit() || $this->credit_limit === null) {
            return null;
        }

        return Money::percentageOf($this->outstandingBalance(), $this->credit_limit);
    }

    /**
     * Tanggal jatuh tempo berikutnya untuk kartu kredit, dihitung dari
     * `due_day`. Bila hari ini sudah lewat tanggal tersebut, hasilnya bulan
     * depan. Mengembalikan null untuk akun non-kartu kredit.
     */
    public function nextDueDate(): ?CarbonImmutable
    {
        if (! $this->isCredit() || $this->due_day === null) {
            return null;
        }

        $today = CarbonImmutable::today();

        $due = $today->day(min((int) $this->due_day, $today->daysInMonth));

        if ($due->lessThan($today)) {
            $due = $due->addMonthNoOverflow();
        }

        return $due;
    }

    /**
     * Tanggal cetak tagihan berikutnya.
     */
    public function nextBillingDate(): ?CarbonImmutable
    {
        if (! $this->isCredit() || $this->billing_day === null) {
            return null;
        }

        $today = CarbonImmutable::today();

        $billing = $today->day(min((int) $this->billing_day, $today->daysInMonth));

        if ($billing->lessThan($today)) {
            $billing = $billing->addMonthNoOverflow();
        }

        return $billing;
    }
}
