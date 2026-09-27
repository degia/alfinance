<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\DebtPaymentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pembayaran satu cicilan (PRD.md §3.7).
 *
 * Baris ini adalah sumber kebenaran "sudah dibayar berapa": `Debt::remaining`
 * selalu diturunkan dari pokok dikurangi jumlah baris di sini, jadi riwayat
 * dan saldo tidak bisa berbeda.
 *
 * `transaction_id` terisi hanya kalau cicilan dicatat sekaligus sebagai
 * expense (utang) atau income (piutang) di modul Transaksi.
 *
 * @property int $id
 * @property int $workspace_id
 * @property int $debt_id
 * @property int|null $transaction_id
 * @property string $amount
 * @property CarbonImmutable $paid_at
 * @property string|null $note
 * @property int|null $created_by
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
#[Fillable(['debt_id', 'transaction_id', 'amount', 'paid_at', 'note'])]
class DebtPayment extends WorkspaceScopedModel
{
    /** @use HasFactory<DebtPaymentFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'paid_at' => 'date',
            'debt_id' => 'integer',
            'transaction_id' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Debt, $this>
     */
    public function debt(): BelongsTo
    {
        return $this->belongsTo(Debt::class);
    }

    /**
     * @return BelongsTo<Transaction, $this>
     */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    /**
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopePaidBetween(Builder $query, ?string $from, ?string $to): Builder
    {
        return $query
            ->when($from, fn (Builder $query) => $query->whereDate('paid_at', '>=', CarbonImmutable::parse($from)))
            ->when($to, fn (Builder $query) => $query->whereDate('paid_at', '<=', CarbonImmutable::parse($to)));
    }

    public function isLinkedToTransaction(): bool
    {
        return $this->transaction_id !== null;
    }
}
