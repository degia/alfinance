<?php

namespace App\Services\Debt;

use App\Enums\DebtStatus;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Events\DebtPaymentRecorded;
use App\Models\Account;
use App\Models\Debt;
use App\Models\DebtPayment;
use App\Models\Transaction;
use App\Services\Transactions\TransactionManager;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Tulis & baca utang/piutang beserta riwayat cicilannya (PRD.md §3.7).
 *
 * Aturan yang dijaga di satu tempat:
 * - `debts.remaining` SELALU = pokok dikurangi total `debt_payments`. Tidak ada
 *   jalur lain yang boleh menulis `remaining`; setiap pembayaran memanggil
 *   {@see self::recalculate()}, jadi riwayat dan sisa tidak bisa berbeda.
 * - Pokok dan arah utang terkunci setelah ada pembayaran: memperbarui keduanya
 *   berarti mengoreksi sejarah, bukan memperbarui data.
 * - `status` disimpan supaya daftar bisa disaring tanpa menghitung ulang, tapi
 *   tampilan selalu memakai {@see Debt::currentStatus()} supaya jatuh tempo
 *   yang lewat tanpa pembayaran ikut terlihat sebagai terlambat.
 *
 * `TransactionManager` disuntik supaya cicilan yang dicatat bareng expense
 * (utang) atau income (piutang) melewati satu-satunya write path saldo
 * (ARCHITECTURE.md §2.2).
 */
class DebtService
{
    public function __construct(
        private readonly TransactionManager $transactions,
        private readonly DebtBalanceCalculator $balances,
    ) {}

    /**
     * Buat utang/piutang baru.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes, ?int $actorId = null): Debt
    {
        return DB::transaction(function () use ($attributes, $actorId): Debt {
            $debt = new Debt($this->normalize($attributes));
            $debt->created_by = $actorId;
            $debt->updated_by = $actorId;

            // Sisa selalu mulai dari pokok: tidak ada "sisa awal" terpisah,
            // jadi `remaining` mustahil tidak konsisten dengan riwayat.
            $debt->remaining = $debt->principal;
            $debt->status = $debt->currentStatus();
            $debt->save();

            return $debt;
        });
    }

    /**
     * Perbarui metadata utang (jadwal, opt-in net worth, catatan, akun).
     *
     * @param  array<string, mixed>  $attributes
     */
    public function update(Debt $debt, array $attributes, ?int $actorId = null): Debt
    {
        $normalized = $this->normalize($attributes, $debt);

        return DB::transaction(function () use ($debt, $normalized, $actorId): Debt {
            if ($this->hasPayments($debt)) {
                $this->guardFrozenFields($debt, $normalized);
            }

            $debt->fill($normalized);
            $debt->updated_by = $actorId;
            $debt->save();

            $this->recalculate($debt);

            return $debt;
        });
    }

    /**
     * Tolak perubahan pokok/arah kalau sudah ada pembayaran, lalu buang kedua
     * field itu dari payload.
     *
     * Yang dikunci adalah *perubahannya*, bukan kemunculan field-nya: form edit
     * selalu mengirim `principal` & `direction`, jadi memblokir edit hanya
     * karena field itu terkirim akan membuat jadwal, catatan, dan akun
     * pembayaran mustahil diperbarui begitu cicilan pertama tercatat. Kalau
     * nilainya sama persis, field itu dibuang supaya tidak ditulis ulang.
     *
     * @param  array<string, mixed>  $normalized
     */
    private function guardFrozenFields(Debt $debt, array &$normalized): void
    {
        if (array_key_exists('direction', $normalized) && $normalized['direction'] !== $debt->direction) {
            abort(422, 'Arah utang tidak bisa diubah setelah ada pembayaran. Catat pembayaran pembalik bila ini keliru.');
        }

        if (array_key_exists('principal', $normalized)
            && Money::toCents($normalized['principal']) !== Money::toCents($debt->principal)) {
            abort(422, 'Pokok utang tidak bisa diubah setelah ada pembayaran. Catat pembayaran pembalik bila ini keliru.');
        }

        unset($normalized['direction'], $normalized['principal']);
    }

    /**
     * Catat satu cicilan.
     *
     * `transaction` (opsional) membuat cicilan ini sekaligus menjadi expense
     * untuk utang atau income untuk piutang lewat `TransactionManager`; sisa &
     * status baru dihitung setelah transaksi itu sukses tersimpan. Nominal yang
     * melebihi sisa ditolak supaya `remaining` tidak pernah negatif.
     *
     * @param  array{amount: string, paid_at?: string|null, note?: string|null, transaction?: array<string, mixed>|null}  $attributes
     */
    public function recordPayment(Debt $debt, array $attributes, ?int $actorId = null): DebtPayment
    {
        $amount = Money::fromCents(Money::toCents($attributes['amount']));
        $paidAt = ! empty($attributes['paid_at'])
            ? CarbonImmutable::parse((string) $attributes['paid_at'])->startOfDay()
            : CarbonImmutable::today();
        $note = $attributes['note'] ?? null;
        $transactionInput = $attributes['transaction'] ?? null;

        $payment = DB::transaction(function () use ($debt, $amount, $paidAt, $note, $transactionInput, $actorId): DebtPayment {
            abort_if(
                Money::toCents($amount) > Money::toCents(Money::atLeastZero($debt->remaining)),
                422,
                'Nominal cicilan melebihi sisa utang.',
            );

            $transactionId = null;

            if (is_array($transactionInput)) {
                $transactionId = $this->createPaymentTransaction($debt, $transactionInput, $paidAt, $actorId)->id;
            }

            $payment = new DebtPayment;
            $payment->workspace_id = (int) $debt->workspace_id;
            $payment->debt_id = (int) $debt->id;
            $payment->transaction_id = $transactionId;
            $payment->amount = $amount;
            $payment->paid_at = $paidAt;
            $payment->note = $note;
            $payment->created_by = $actorId;
            $payment->save();

            $this->recalculate($debt);

            return $payment;
        });

        DebtPaymentRecorded::dispatch($payment, $actorId);

        return $payment;
    }

    /**
     * Hitung ulang `remaining` & `status` dari riwayat pembayaran.
     *
     * Perhitungan nominalnya ada di {@see DebtBalanceCalculator} supaya write
     * path dari modul Transaksi ("Bayar utang") memakai rumus yang sama —
     * sisa utang adalah invariant yang tidak boleh ditulis dari dua tempat.
     */
    public function recalculate(Debt $debt): Debt
    {
        return $this->balances->recalculate($debt);
    }

    /**
     * Ringkasan untuk kartu ringkasan di halaman utang.
     *
     * @return array{count: int, total_remaining: string, total_overdue: string, payable: string, receivable: string}
     */
    public function summary(int $workspaceId, DebtStatus $status, ?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::today();
        $debts = $this->filtered($workspaceId, $status, $today);

        return [
            'count' => $debts->count(),
            'total_remaining' => Money::sum($debts->pluck('remaining')),
            'total_overdue' => Money::sum(
                $debts->filter(fn (Debt $debt): bool => $debt->isOverdue($today))->pluck('remaining'),
            ),
            'payable' => Money::sum($debts->filter(fn (Debt $debt): bool => $debt->isPayable())->pluck('remaining')),
            'receivable' => Money::sum($debts->filter(fn (Debt $debt): bool => ! $debt->isPayable())->pluck('remaining')),
        ];
    }

    /**
     * Daftar utang dengan status yang dihitung ulang, diurutkan yang paling
     * mendesak: terlambat dulu, lalu jatuh tempo terdekat.
     *
     * @return Collection<int, Debt>
     */
    public function filtered(int $workspaceId, DebtStatus $status, ?CarbonImmutable $today = null): Collection
    {
        $today ??= CarbonImmutable::today();

        return Debt::allWorkspaces()
            ->where('workspace_id', $workspaceId)
            ->with('account')
            // `present()` memetakan tiap baris dan memanggil
            // `Debt::paidTermCount()`; tanpa agregat ini daftar utang memicu
            // satu query COUNT per baris.
            ->withCount('payments')
            ->get()
            ->filter(fn (Debt $debt): bool => $debt->currentStatus($today) === $status)
            ->sort(function (Debt $a, Debt $b): int {
                // Utang tanpa jatuh tempo di urut paling akhir.
                $withoutDueDate = (int) ($a->due_date === null) <=> (int) ($b->due_date === null);

                if ($withoutDueDate !== 0) {
                    return $withoutDueDate;
                }

                $due = ($a->due_date?->toDateString() ?? '9999-12-31')
                    <=> ($b->due_date?->toDateString() ?? '9999-12-31');

                return $due !== 0 ? $due : strcasecmp($a->counterparty, $b->counterparty);
            })
            ->values();
    }

    /**
     * Riwayat cicilan satu utang, terbaru lebih dulu.
     *
     * @return Collection<int, DebtPayment>
     */
    public function payments(Debt $debt): Collection
    {
        return $this->paymentQuery($debt)
            ->orderByDesc('paid_at')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * Akun aktif yang boleh dipilih untuk mencatat pembayaran.
     *
     * @return Collection<int, Account>
     */
    public function paymentAccounts(int $workspaceId): Collection
    {
        return Account::allWorkspaces()
            ->where('workspace_id', $workspaceId)
            ->active()
            ->orderBy('name')
            ->get();
    }

    /**
     * Apakah utang ini sudah punya riwayat cicilan.
     *
     * Inilah syarat yang membuat penghapusan utang ditolak, jadi diletakkan di
     * service yang sama dengan penulisan pembayarannya — bukan di controller
     * dengan query sendiri.
     */
    public function hasPayments(Debt $debt): bool
    {
        return $this->paymentQuery($debt)->exists();
    }

    /**
     * Query riwayat cicilan satu utang dengan filter workspace eksplisit.
     *
     * `remaining` adalah invariant yang tidak boleh salah, jadi agregasinya
     * tidak boleh bergantung pada `ActiveWorkspace` yang hanya ada selama satu
     * request web. Dipanggil dari job atau console tanpa workspace aktif,
     * global scope akan mengembalikan nol baris — sisa utang lalu terlihat
     * seolah belum ada cicilan sama sekali. Karena itu filter `workspace_id`
     * ditulis eksplisit di sini, mengikuti gaya `NetWorthService`.
     *
     * @return Builder<DebtPayment>
     */
    private function paymentQuery(Debt $debt): Builder
    {
        return DebtPayment::allWorkspaces()
            ->where('workspace_id', $debt->workspace_id)
            ->where('debt_id', $debt->id);
    }

    /**
     * Normalisasi input form: nominal dua desimal, tanggal mulai hari, dan
     * `account_id` kosong jadi null.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function normalize(array $attributes, ?Debt $existing = null): array
    {
        $normalized = $attributes;

        if (array_key_exists('principal', $normalized)) {
            $normalized['principal'] = Money::fromCents(Money::toCents($normalized['principal']));
        }

        // Suku bunga opsional: form mengirim string kosong bila dikosongkan, dan
        // itu berarti "tanpa bunga" (NULL), bukan 0.
        if (array_key_exists('interest_rate', $normalized) && ! empty($normalized['interest_rate'])) {
            $normalized['interest_rate'] = Money::fromCents(Money::toCents($normalized['interest_rate']));
        } elseif (array_key_exists('interest_rate', $normalized)) {
            $normalized['interest_rate'] = null;
        }

        foreach (['start_date', 'due_date'] as $field) {
            if (! array_key_exists($field, $normalized)) {
                continue;
            }

            $normalized[$field] = $normalized[$field] === null || $normalized[$field] === ''
                ? null
                : CarbonImmutable::parse((string) $normalized[$field])->startOfDay();
        }

        if (array_key_exists('account_id', $normalized)) {
            $normalized['account_id'] = $normalized['account_id'] === null || $normalized['account_id'] === ''
                ? null
                : (int) $normalized['account_id'];
        }

        if ($existing === null) {
            $normalized['remaining'] = $normalized['principal'] ?? '0.00';
        }

        return $normalized;
    }

    /**
     * Ubah cicilan menjadi baris transaksi.
     *
     * Utang -> expense (uang keluar untuk melunasi utang), piutang -> income
     * (uang yang masuk dari pihak yang meminjam). Akun diverifikasi ulang di
     * sini karena form Debt tidak lewat `TransactionRequest` yang biasanya
     * menjaga aturan ini.
     *
     * @param  array<string, mixed>  $input
     */
    private function createPaymentTransaction(
        Debt $debt,
        array $input,
        CarbonImmutable $paidAt,
        ?int $actorId,
    ): Transaction {
        $accountId = (int) ($input['account_id'] ?? 0);

        if ($accountId === 0) {
            $accountId = (int) ($debt->account_id ?? 0);
        }

        $account = Account::allWorkspaces()->whereKey($accountId)->first();

        abort_if(
            $account === null || (int) $account->workspace_id !== (int) $debt->workspace_id,
            422,
            'Akun pembayaran tidak valid.',
        );

        $type = $debt->isPayable() ? TransactionType::Expense : TransactionType::Income;

        $attributes = [
            'account_id' => (int) $account->id,
            'type' => $type,
            'amount' => $input['amount'] ?? $debt->remaining,
            'occurred_at' => $paidAt->endOfDay()->toDateTimeString(),
            'note' => ($input['note'] ?? null) !== null && $input['note'] !== ''
                ? (string) $input['note']
                : sprintf('Cicilan %s - %s', $debt->isPayable() ? 'utang' : 'piutang', $debt->counterparty),
            'status' => TransactionStatus::Posted,
        ];

        // Kategori hanya relevan untuk expense; income tidak mewajibkannya.
        if ($type === TransactionType::Expense && (int) ($input['category_id'] ?? 0) > 0) {
            $attributes['category_id'] = (int) $input['category_id'];
        }

        return $this->transactions->create($attributes, $actorId);
    }
}
