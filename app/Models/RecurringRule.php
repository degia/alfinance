<?php

namespace App\Models;

use App\Enums\RecurringFrequency;
use App\Enums\TransactionType;
use App\Jobs\RecurringTransactionJob;
use Carbon\CarbonImmutable;
use Database\Factories\RecurringRuleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Aturan transaksi berulang (PRD.md §3.3).
 *
 * Rule ini adalah template, bukan transaksi: ia tidak pernah menyentuh saldo.
 * Instance dibuat oleh {@see RecurringTransactionJob} — berstatus
 * `posted` (langsung memengaruhi saldo) atau `pending` (menunggu konfirmasi,
 * tergantung `requires_confirmation`).
 *
 * @property int $id
 * @property int $workspace_id
 * @property int $account_id
 * @property int|null $transfer_to_account_id
 * @property int|null $category_id
 * @property TransactionType $type
 * @property string $amount
 * @property array<int, int>|null $tag_ids
 * @property string|null $note
 * @property RecurringFrequency $frequency
 * @property CarbonImmutable $next_run_at
 * @property bool $requires_confirmation
 * @property bool $is_active
 * @property CarbonImmutable|null $end_date
 * @property CarbonImmutable|null $last_generated_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
#[Fillable([
    'account_id',
    'transfer_to_account_id',
    'category_id',
    'type',
    'amount',
    'tag_ids',
    'note',
    'frequency',
    'next_run_at',
    'requires_confirmation',
    'is_active',
    'end_date',
    'last_generated_at',
])]
class RecurringRule extends WorkspaceScopedModel
{
    /** @use HasFactory<RecurringRuleFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => TransactionType::class,
            'frequency' => RecurringFrequency::class,
            'amount' => 'decimal:2',
            'tag_ids' => 'array',
            'next_run_at' => 'datetime',
            'end_date' => 'date',
            'last_generated_at' => 'datetime',
            'requires_confirmation' => 'boolean',
            'is_active' => 'boolean',
            'account_id' => 'integer',
            'transfer_to_account_id' => 'integer',
            'category_id' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'account_id');
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function transferToAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'transfer_to_account_id');
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * Instance yang sudah dihasilkan rule ini.
     *
     * Dipakai {@see RecurringTransactionJob} untuk idempotensi: satu
     * rule tidak boleh punya dua instance pada tanggal yang sama.
     *
     * @return HasMany<Transaction, $this>
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    /**
     * Rule aktif yang belum melewati `end_date`.
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true)
            ->where(fn (Builder $query) => $query->whereNull('end_date')->orWhereDate('end_date', '>=', today()));
    }

    /**
     * Rule yang sudah jatuh tempo untuk dijalankan job.
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeDue(Builder $query, ?CarbonImmutable $moment = null): Builder
    {
        $moment ??= CarbonImmutable::now();

        return $query->active()->where('next_run_at', '<=', $moment);
    }

    /**
     * Koccurensi berikutnya, dibatasi `end_date` kalau ada: kalau occurrence
     * berikutnya melewati tanggal akhir, rule di-nonaktifkan.
     */
    public function advanceNextRun(): void
    {
        $next = $this->frequency->nextOccurrence($this->next_run_at);

        if ($this->end_date !== null && $next->greaterThan(CarbonImmutable::parse($this->end_date)->endOfDay())) {
            $this->is_active = false;
        }

        $this->next_run_at = $next;
        $this->last_generated_at = CarbonImmutable::now();
    }
}
