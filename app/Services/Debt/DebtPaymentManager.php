<?php

namespace App\Services\Debt;

use App\Enums\DebtDirection;
use App\Events\DebtPaymentRecorded;
use App\Events\DebtPaymentRemoved;
use App\Models\Debt;
use App\Models\DebtPayment;
use App\Models\Transaction;
use App\Services\Transactions\TransactionManager;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Validation\ValidationException;

/**
 * Menautkan expense "Bayar utang" di modul Transaksi ke riwayat cicilan
 * modul Utang & Piutang (PRD.md §3.7).
 *
 * Arah integrasi ini berlawanan dengan `DebtService::recordPayment()`, yang
 * membuat transaksinya sendiri: di sini expense dicatat seperti biasa lewat
 * {@see TransactionManager}, lalu baris
 * `debt_payments`-nya dibuat/diperbarui agar `debts.remaining` ikut turun.
 * Tautan tetap disimpan di `debt_payments.transaction_id` — bukan kolom baru
 * di tabel `transactions` — supaya riwayat cicilan yang sudah ada dan yang
 * dibuat lewat modul Utang memakai satu sumber kebenaran yang sama.
 *
 * Aturan yang dijaga di sini:
 * - hanya expense yang boleh melunasi utang; income/transfer mengabaikan
 *   tautan dan melempar tautan lama supaya sisa utang tidak pernah tertahan
 *   oleh transaksi yang sudah bukan pembayaran;
 * - nominal tidak boleh melebihi sisa utang yang tersedia;
 * - setiap perubahan selalu berakhir dengan
 *   {@see DebtBalanceCalculator::recalculate()}, jadi `remaining` tidak bisa
 *   berbeda dari riwayat pembayarannya.
 *
 * Service ini tidak menyuntik `TransactionManager` (yang sudah menyuntik service
 * ini) maupun `DebtService` (yang menyuntik `TransactionManager`), jadi tidak
 * ada dependency melingkar: kalkulasi sisa utangnya dipinjam dari
 * {@see DebtBalanceCalculator}.
 */
class DebtPaymentManager
{
    public function __construct(private readonly DebtBalanceCalculator $balances) {}

    /**
     * Samakan pembayaran utang milik sebuah transaksi dengan pilihan user.
     *
     * `$debtId` null berarti transaksi ini bukan pembayaran utang: baris
     * cicilan yang sudah ada ikut dihapus dan sisa utang dikembalikan.
     *
     * Event TIDAK dikirim dari sini: method ini dipanggil di dalam DB
     * transaction milik {@see TransactionManager},
     * dan listener agregat harusnya membaca data yang sudah committed. Pemanggil
     * membandingkan baris sebelum & sesudah lalu memanggil
     * {@see self::announce()} setelah transaction selesai.
     *
     * @return DebtPayment|null baris cicilan setelah sinkron, null kalau tidak ada
     *
     * @throws ValidationException
     */
    public function sync(Transaction $transaction, ?int $debtId, ?int $actorId = null): ?DebtPayment
    {
        if ($debtId === null || ! $transaction->isExpense()) {
            $this->unlink($transaction);

            return null;
        }

        $debt = $this->findPayableDebt($transaction, $debtId);
        $existing = $this->find($transaction);

        // Sisa yang boleh dibayar dihitung tanpa baris yang sedang diedit,
        // supaya revise nominal cicilan yang sama bukan selalu dianggap melebihi
        // sisa.
        $available = $this->balances->availableToPay($debt, $existing);

        if (Money::toCents($transaction->amount) > Money::toCents($available)) {
            throw ValidationException::withMessages([
                'amount' => __('Nominal melebihi sisa utang yang harus dibayar (sisa: :sisa).', [
                    'sisa' => Money::format($available),
                ]),
            ]);
        }

        // Pindah utang: cicilan lama diutus supaya sisa utang sebelumnya
        // dikembalikan sebelum baris baru dibuat.
        if ($existing !== null && (int) $existing->debt_id !== (int) $debt->id) {
            $this->remove($existing);
            $existing = null;
        }

        $attributes = [
            'amount' => $transaction->amount,
            'paid_at' => CarbonImmutable::parse((string) $transaction->occurred_at)->startOfDay(),
            'note' => null,
        ];

        if ($existing !== null) {
            $existing->fill($attributes);
            $existing->save();
            $payment = $existing;
        } else {
            $payment = new DebtPayment;
            $payment->workspace_id = (int) $transaction->workspace_id;
            $payment->debt_id = (int) $debt->id;
            $payment->transaction_id = (int) $transaction->id;
            $payment->created_by = $actorId;
            $payment->fill($attributes);
            $payment->save();
        }

        $this->balances->recalculate($debt);

        return $payment;
    }

    /**
     * Hapus cicilan milik sebuah transaksi dan kembalikan sisa utangnya.
     *
     * Baris `debt_payments` tidak boleh dibiarkan menggantung: kolom
     * `transaction_id` memakai `nullOnDelete`, jadi transaksi yang dihapus
     * hanya akan membuat baris yatim yang tetap memotong sisa utang.
     *
     * Seperti {@see self::sync()}, event dikirim pemanggil lewat
     * {@see self::announceRemoved()} setelah DB transaction selesai.
     *
     * @return DebtPayment|null baris cicilan yang dihapus, kalau ada
     */
    public function unlink(Transaction $transaction): ?DebtPayment
    {
        $payment = $this->find($transaction);

        if ($payment === null) {
            return null;
        }

        $this->remove($payment);

        return $payment;
    }

    /**
     * Cicilan yang terhubung ke sebuah transaksi, kalau ada.
     *
     * Pencarian memakai `allWorkspaces()` + filter `workspace_id` transaksi,
     * bukan global scope ambient, karena manager ini juga dipanggil dari job
     * yang berjalan tanpa `ActiveWorkspace`.
     */
    public function find(Transaction $transaction): ?DebtPayment
    {
        /** @var DebtPayment|null $payment */
        $payment = DebtPayment::allWorkspaces()
            ->where('workspace_id', $transaction->workspace_id)
            ->where('transaction_id', $transaction->id)
            ->first();

        return $payment;
    }

    /**
     * Utang yang harus dibayar, untuk select "Bayar utang" di form transaksi.
     *
     * Hanya utang milik workspace aktif dengan sisa di atas nol; diurutkan
     * yang paling mendesak dulu (terlambat, lalu jatuh tempo terdekat) supaya
     * daftar yang offered sama dengan urutan di halaman Utang & Piutang.
     *
     * @return Collection<int, Debt>
     */
    public function payable(int $workspaceId): Collection
    {
        return Debt::allWorkspaces()
            ->where('workspace_id', $workspaceId)
            ->where('direction', DebtDirection::Payable->value)
            ->get()
            ->filter(fn (Debt $debt): bool => ! $debt->isSettled())
            // Comparator penuh, bukan `sortBy([fn...])`: pada bentuk itu
            // Laravel memanggil closure-nya sebagai perbandingan dua baris,
            // bukan sebagai kunci urut, jadi urutannya jadi tidak deterministik.
            ->sort(function (Debt $a, Debt $b): int {
                // Utang terlambat didahulukan, lalu jatuh tempo terdekat,
                // lalu nama. Utang tanpa jatuh tempo tetap di akhir.
                $overdue = (int) $b->isOverdue() <=> (int) $a->isOverdue();

                if ($overdue !== 0) {
                    return $overdue;
                }

                $due = ($a->due_date?->toDateString() ?? '9999-12-31')
                    <=> ($b->due_date?->toDateString() ?? '9999-12-31');

                return $due !== 0 ? $due : strcasecmp($a->counterparty, $b->counterparty);
            })
            ->values();
    }

    /**
     * Opsi select "Bayar utang" — sudah dipetakan jadi array supaya select di
     * frontend tidak perlu tahu bentuk model.
     *
     * `$linked` dipakai saat edit: utang yang sudah lunas tidak lagi masuk
     * daftar yang harus dibayar, tapi tetap harus tampil supaya tautannya
     * kelihatan dan bisa dilepas.
     *
     * @return SupportCollection<int, array{id: int, counterparty: string, remaining: string, due_date: string|null, status_label: string}>
     */
    public function payableOptions(int $workspaceId, ?Debt $linked = null): SupportCollection
    {
        $options = $this->payable($workspaceId)
            ->map(fn (Debt $debt): array => $this->toOption($debt));

        if ($linked !== null && ! $options->contains('id', (int) $linked->id)) {
            $options->push($this->toOption($linked));
        }

        return $options;
    }

    /**
     * @return array{id: int, counterparty: string, remaining: string, due_date: string|null, status_label: string}
     */
    private function toOption(Debt $debt): array
    {
        return [
            'id' => (int) $debt->id,
            'counterparty' => $debt->counterparty,
            'remaining' => $debt->remaining,
            'due_date' => $debt->due_date?->toDateString(),
            'status_label' => $debt->currentStatus()->label(),
        ];
    }

    /**
     * Hapus satu baris cicilan lalu segarkan sisa utangnya.
     */
    private function remove(DebtPayment $payment): void
    {
        $debt = Debt::allWorkspaces()->find($payment->debt_id);
        $payment->delete();

        if ($debt !== null) {
            $this->balances->recalculate($debt);
        }
    }

    /**
     * Kirim event untuk satu siklus sinkronisasi, dipanggil setelah commit.
     *
     * Cicilan yang diperbarui di tempat yang sama hanya mengirim
     * `DebtPaymentRecorded`; berpindah utang atau dilepas mengirim
     * `DebtPaymentRemoved` supaya listener agregat tahu sisa utangnya berubah
     * ke arah sebaliknya.
     */
    public function announce(?DebtPayment $before, ?DebtPayment $after, ?int $actorId = null): void
    {
        $updatedInPlace = $before !== null
            && $after !== null
            && (int) $before->id === (int) $after->id;

        if ($after !== null) {
            DebtPaymentRecorded::dispatch($after, $actorId);
        }

        if ($before !== null && ! $updatedInPlace) {
            DebtPaymentRemoved::dispatch($before);
        }
    }

    /**
     * Kirim event pembatalan cicilan setelah commit.
     */
    public function announceRemoved(?DebtPayment $payment): void
    {
        if ($payment === null) {
            return;
        }

        DebtPaymentRemoved::dispatch($payment);
    }

    /**
     * Utang milik workspace transaksi yang masih harus dibayar.
     *
     * @throws ValidationException
     */
    private function findPayableDebt(Transaction $transaction, int $debtId): Debt
    {
        /** @var Debt|null $debt */
        $debt = Debt::allWorkspaces()
            ->where('workspace_id', $transaction->workspace_id)
            ->whereKey($debtId)
            ->first();

        throw_if(
            $debt === null || ! $debt->isPayable(),
            ValidationException::withMessages([
                'debt_id' => __('Utang yang dipilih tidak ditemukan di workspace ini.'),
            ]),
        );

        throw_if(
            $debt->isSettled(),
            ValidationException::withMessages([
                'debt_id' => __('Utang ini sudah lunas.'),
            ]),
        );

        return $debt;
    }
}
