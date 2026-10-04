<?php

namespace App\Models;

use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Services\Transactions\AdminFeeManager;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Database\Factories\TransactionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Collection;

/**
 * Satu catatan arus kas (PRD.md §3.3).
 *
 * Tiga tipe:
 * - `income`  → `cached_balance` akun sumber bertambah.
 * - `expense` → `cached_balance` akun sumber berkurang.
 * - `transfer`→ dua akun tersentuh sekaligus: sumber berkurang, tujuan bertambah.
 *
 * `amount` selalu magnitude positif; arahnya datang dari {@see TransactionType}.
 * Transaksi berstatus `pending` (transaksi berulang yang perlu konfirmasi)
 * belum boleh memengaruhi saldo — lihat {@see balanceDeltas()}.
 *
 * `parent_transaction_id` hanya terisi pada baris turunan: potongan admin
 * sebuah transfer dicatat sebagai `expense` biasa yang menunjuk transfer
 * induknya, supaya biayanya bisa ikut diperbarui/dihapus bersamanya.
 *
 * @property int $id
 * @property int $workspace_id
 * @property int $account_id
 * @property int|null $transfer_to_account_id
 * @property int|null $category_id
 * @property int|null $parent_transaction_id
 * @property int|null $recurring_rule_id
 * @property TransactionType $type
 * @property string $amount
 * @property string|null $note
 * @property CarbonImmutable $occurred_at
 * @property TransactionStatus $status
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
#[Fillable([
    'account_id',
    'transfer_to_account_id',
    'category_id',
    'parent_transaction_id',
    'recurring_rule_id',
    'type',
    'amount',
    'note',
    'occurred_at',
    'status',
])]
class Transaction extends WorkspaceScopedModel
{
    /** @use HasFactory<TransactionFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => TransactionType::class,
            'status' => TransactionStatus::class,
            'amount' => 'decimal:2',
            'occurred_at' => 'datetime',
            'account_id' => 'integer',
            'transfer_to_account_id' => 'integer',
            'category_id' => 'integer',
            'parent_transaction_id' => 'integer',
            'recurring_rule_id' => 'integer',
            'created_by' => 'integer',
            'updated_by' => 'integer',
        ];
    }

    /**
     * Transaksi yang dibuat manual selalu `posted` — hanya instance dari rule
     * berulang yang perlu konfirmasi dan itu sudah menentukan statusnya
     * sebelum disimpan. Default kolom di migration tidak cukup untuk model
     * in-memory, jadi harus di-set di sini.
     *
     * `parent::booted()` wajib dipanggil: base model mendaftarkan global scope
     * workspace sekaligus pengisi otomatis `workspace_id` dari situ. Override
     * tanpa memanggilnya akan mematikan tenant isolation model ini.
     */
    protected static function booted(): void
    {
        parent::booted();

        static::creating(function (self $transaction): void {
            $transaction->status ??= TransactionStatus::Posted;
        });
    }

    /*
     |--------------------------------------------------------------------------
     | Relasi
     |--------------------------------------------------------------------------
     */

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'account_id');
    }

    /**
     * Akun tujuan transfer. Null untuk income/expense.
     *
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
     * @return BelongsToMany<Tag, $this>
     */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'transaction_tag');
    }

    /**
     * @return HasMany<Attachment, $this>
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(Attachment::class);
    }

    /**
     * @return BelongsTo<RecurringRule, $this>
     */
    public function recurringRule(): BelongsTo
    {
        return $this->belongsTo(RecurringRule::class, 'recurring_rule_id');
    }

    /**
     * Transaksi induk untuk baris turunan (potongan admin sebuah transfer).
     *
     * @return BelongsTo<Transaction, $this>
     */
    public function parentTransaction(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_transaction_id');
    }

    /**
     * Baris pengeluaran "Biaya Admin" milik transfer ini, kalau ada.
     *
     * Satu transfer maksimum punya satu potongan admin, jadi relasinya
     * `hasOne`. Dipakai {@see AdminFeeManager} untuk
     * menyinkronkan baris tersebut setiap kali transfer berubah.
     *
     * @return HasOne<Transaction, $this>
     */
    public function adminFee(): HasOne
    {
        return $this->hasOne(self::class, 'parent_transaction_id');
    }

    /*
     |--------------------------------------------------------------------------
     | Scope filter (ARCHITECTURE.md §2.1.5)
     |--------------------------------------------------------------------------
     */

    /**
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeOfType(Builder $query, TransactionType $type): Builder
    {
        return $query->where('type', $type->value);
    }

    /**
     * Hanya yang benar-benar memengaruhi saldo (bukan instance pending).
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopePosted(Builder $query): Builder
    {
        return $query->where('status', TransactionStatus::Posted->value);
    }

    /**
     * Instance transaksi berulang yang menunggu konfirmasi.
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', TransactionStatus::Pending->value);
    }

    /**
     * Rentang tanggal inklusif. Tanggal hanya (bukan datetime) sudah dinormalisasi
     * ke 00:00:00 / 23:59:59 supaya filter "satu hari" tidak memotong.
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeOccurredBetween(Builder $query, ?string $from, ?string $to): Builder
    {
        return $query
            ->when($from, fn (Builder $query) => $query->where('occurred_at', '>=', CarbonImmutable::parse($from)->startOfDay()))
            ->when($to, fn (Builder $query) => $query->where('occurred_at', '<=', CarbonImmutable::parse($to)->endOfDay()));
    }

    /**
     * Akun yang "terlibat" di transaksi: sebagai sumber, atau sebagai tujuan
     * transfer. Dipakai supaya filter per akun tetap menampilkan transfer
     * masuk maupun transfer keluar.
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeInvolvingAccount(Builder $query, int $accountId): Builder
    {
        return $query->where(function (Builder $query) use ($accountId): void {
            $query->where('account_id', $accountId)
                ->orWhere('transfer_to_account_id', $accountId);
        });
    }

    /**
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeOfCategory(Builder $query, ?int $categoryId): Builder
    {
        return $query->when($categoryId, fn (Builder $query) => $query->where('category_id', $categoryId));
    }

    /**
     * Filter tag. Kalau satu tag yang diminta, transaksi cukup punya tag itu.
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeTaggedWith(Builder $query, ?int $tagId): Builder
    {
        return $query->when($tagId, fn (Builder $query) => $query->whereHas(
            'tags',
            fn (Builder $query) => $query->where('tags.id', $tagId),
        ));
    }

    /**
     * Filter rentang nominal.
     *
     * Kolom `amount` bertipe DECIMAL(15,2), jadi pembanding harus berupa
     * string desimal — BUKAN sen. `Money::fromCents()` dipakai untuk
     * menormalkan input filter (mis. "1000000" atau "1.000,50") menjadi
     * "1000000.00" supaya skala perbandingan sama dengan kolom.
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeAmountBetween(Builder $query, ?string $min, ?string $max): Builder
    {
        return $query
            ->when($min, fn (Builder $query) => $query->where('amount', '>=', self::normalizeAmount($min)))
            ->when($max, fn (Builder $query) => $query->where('amount', '<=', self::normalizeAmount($max)));
    }

    /**
     * Pencarian bebas pada catatan transaksi.
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeSearching(Builder $query, ?string $term): Builder
    {
        $term = trim((string) $term);

        return $query->when($term !== '', fn (Builder $query) => $query->where('note', 'like', '%'.$term.'%'));
    }

    /*
     |--------------------------------------------------------------------------
     | Dampak terhadap saldo
     |--------------------------------------------------------------------------
     */

    public function isTransfer(): bool
    {
        return $this->type->isTransfer();
    }

    /**
     * Baris pengeluaran yang dihasilkan otomatis oleh sebuah transfer
     * (potongan admin), bukan catatan yang diketik user langsung.
     */
    public function isAdminFee(): bool
    {
        return $this->parent_transaction_id !== null;
    }

    public function isPending(): bool
    {
        return $this->status === TransactionStatus::Pending;
    }

    public function isPosted(): bool
    {
        return $this->status === TransactionStatus::Posted;
    }

    /**
     * Dampak transaksi ini terhadap `accounts.cached_balance`, dalam sen.
     *
     * Mengembalikan peta `account_id => delta`. Transaksi `pending` sengaja
     * mengembalikan peta kosong supaya tidak ada jalur kode yang bisa menyentuh
     * saldo sebelum instance dikonfirmasi.
     *
     * @return array<int, int>
     */
    public function balanceDeltas(): array
    {
        if (! $this->status->affectsBalance()) {
            return [];
        }

        $amount = Money::toCents($this->amount);
        $deltas = [$this->account_id => $amount * $this->type->sourceSign()];

        if ($this->isTransfer() && $this->transfer_to_account_id !== null) {
            // Akun tujuan bertambah sebesar nominal yang sama. Transfer ke akun
            // sendiri otomatis menjadi no-op (dicegah juga di layer validasi,
            // tapi model sendiri tidak boleh bisa salah hitung).
            if ($this->transfer_to_account_id !== $this->account_id) {
                $deltas[$this->transfer_to_account_id] = $amount;
            } else {
                unset($deltas[$this->account_id]);
            }
        }

        return $deltas;
    }

    /**
     * Kebalikan dari {@see balanceDeltas()}, dipakai saat transaksi diubah atau
     * dihapus supaya saldo kembali seperti sebelum ada transaksi ini.
     *
     * @return array<int, int>
     */
    public function reversedBalanceDeltas(): array
    {
        return array_map(
            static fn (int $delta): int => -$delta,
            $this->balanceDeltas(),
        );
    }

    /**
     * @return Collection<int, Tag>
     */
    public function tagList(): Collection
    {
        return $this->tags;
    }

    /**
     * Normalkan input nominal filter ke skala DECIMAL(15,2).
     */
    private static function normalizeAmount(?string $value): string
    {
        return Money::fromCents(Money::toCents($value));
    }
}
