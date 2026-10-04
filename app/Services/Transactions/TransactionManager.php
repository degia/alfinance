<?php

namespace App\Services\Transactions;

use App\Enums\TransactionStatus;
use App\Events\TransactionDeleted;
use App\Events\TransactionSaved;
use App\Models\Account;
use App\Models\Attachment;
use App\Models\RecurringRule;
use App\Models\Transaction;
use App\Support\Money;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Satu-satunya pintu tulis untuk transaksi (AGENT.md Fase 3).
 *
 * Semua operasi di sini membungkus tiga hal dalam satu DB transaction:
 * 1. tulis baris `transactions`,
 * 2. geser `accounts.cached_balance` sesuai dampak transaksi,
 * 3. (sebelum commit) siapkan file lampiran.
 *
 * Setelah commit, event `TransactionSaved` / `TransactionDeleted` dikirim agar
 * listener invalidasi cache di Fase 6 punya titik trigger yang tunggal —
 * tidak ada jalur lain yang boleh menyentuh saldo.
 *
 * Catatan: manager ini tidak bergantung pada `ActiveWorkspace` supaya bisa
 * dipanggil dari scheduled job (yang jalan tanpa request/session). Semua
 * pembacaan akun memakai `allWorkspaces()` lalu diverifikasi ulang terhadap
 * `workspace_id` transaksi sebagai defence in depth.
 */
class TransactionManager
{
    public function __construct(private readonly AdminFeeManager $adminFees) {}

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<int, int>  $tagIds
     * @param  int|null  $adminFee  potongan admin dalam sen; hanya berlaku untuk transfer
     */
    public function create(
        array $attributes,
        ?int $actorId = null,
        array $tagIds = [],
        ?UploadedFile $attachment = null,
        ?int $adminFee = null,
    ): Transaction {
        $transaction = DB::transaction(function () use ($attributes, $actorId, $tagIds, $attachment, $adminFee): Transaction {
            $transaction = new Transaction($attributes);
            $transaction->created_by = $actorId;
            $transaction->updated_by = $actorId;
            $transaction->save();

            $this->syncTags($transaction, $tagIds);

            // Potongan admin dicatat sebagai baris expense terpisah, tapi
            // dampaknya digabung ke peta delta yang sama supaya akun yang sama
            // tidak ditulis dua kali.
            $fee = $this->syncAdminFee($transaction, $adminFee, $actorId);

            $this->applyDeltas(
                $this->mergeDeltas($transaction->balanceDeltas(), $fee?->balanceDeltas() ?? []),
                $transaction->workspace_id,
            );

            if ($attachment !== null) {
                $this->storeAttachment($transaction, $attachment);
            }

            return $transaction;
        });

        TransactionSaved::dispatch($transaction, created: true);
        $this->dispatchAdminFeeSaved($transaction);

        return $transaction;
    }

    /**
     * Perbarui transaksi.
     *
     * Dampak saldo dihitung sebagai selisih_net antara dampak lama (dibalik)
     * dan dampak baru, lalu diterapkan dalam satu kali tulis. Dengan begitu
     * tidak pernah ada keadaan antara yang terlihat, dan akun yang sama tidak
     * perlu ditulis dua kali.
     *
     * @param  array<string, mixed>  $attributes
     * @param  array<int, int>|null  $tagIds  null = jangan sentuh tag
     * @param  int|null  $adminFee  potongan admin dalam sen; null = tanpa potongan admin
     */
    public function update(
        Transaction $transaction,
        array $attributes,
        ?int $actorId = null,
        ?array $tagIds = null,
        ?UploadedFile $attachment = null,
        bool $removeAttachment = false,
        ?int $adminFee = null,
    ): Transaction {
        $previousFee = $this->adminFees->find($transaction);
        $previousDeltas = $this->mergeDeltas(
            $transaction->reversedBalanceDeltas(),
            $this->adminFees->reversedDeltas($previousFee),
        );
        $previousMonth = $transaction->occurred_at->format('Y-m');

        $updated = DB::transaction(function () use ($transaction, $attributes, $actorId, $tagIds, $attachment, $removeAttachment, $adminFee, $previousDeltas): Transaction {
            $transaction->fill($attributes);
            $transaction->updated_by = $actorId;
            $transaction->save();

            if ($tagIds !== null) {
                $this->syncTags($transaction, $tagIds);
            }

            $fee = $this->syncAdminFee($transaction, $adminFee, $actorId);

            $this->applyDeltas(
                $this->mergeDeltas(
                    $previousDeltas,
                    $this->mergeDeltas($transaction->balanceDeltas(), $fee?->balanceDeltas() ?? []),
                ),
                $transaction->workspace_id,
            );

            if ($removeAttachment) {
                $this->deleteAttachments($transaction);
            } elseif ($attachment !== null) {
                $this->deleteAttachments($transaction);
                $this->storeAttachment($transaction, $attachment);
            }

            return $transaction;
        });

        // Bulan lama ikut dibawa supaya agregat bulan itu juga di-invalidate
        // ketika tanggal transaksi dikoreksi ke bulan lain.
        TransactionSaved::dispatch($updated, previousMonth: $previousMonth);

        if ($fee = $this->adminFees->find($updated)) {
            // Kalau tanggal transfer dikoreksi ke bulan lain, baris biayanya
            // ikut pindah bulan — agregat bulan lamanya harus di-invalidate
            // juga, sama seperti transaksi induknya.
            $previousFeeMonth = $previousFee?->occurred_at->format('Y-m') ?? $previousMonth;

            TransactionSaved::dispatch($fee, previousMonth: $previousFeeMonth);
        } elseif ($previousFee !== null) {
            // Baris potongan admin dihapus (dikosongkan di form, atau transfer
            // diubah jadi income/expense) — listener tetap harus tahu supaya
            // agregat bulan yang terpengaruh ikut dibersihkan.
            TransactionDeleted::dispatch($previousFee);
        }

        return $updated;
    }

    /**
     * Hapus transaksi dan kembalikan saldo seperti sebelum ada transaksi ini.
     */
    public function delete(Transaction $transaction): void
    {
        $fee = $this->adminFees->find($transaction);
        $deltas = $this->mergeDeltas(
            $transaction->reversedBalanceDeltas(),
            $this->adminFees->reversedDeltas($fee),
        );

        DB::transaction(function () use ($transaction, $fee, $deltas): void {
            $this->applyDeltas($deltas, $transaction->workspace_id);
            $this->deleteAttachments($transaction);
            $transaction->tags()->detach();
            $fee?->delete();
            $transaction->delete();
        });

        TransactionDeleted::dispatch($transaction);

        if ($fee !== null) {
            TransactionDeleted::dispatch($fee);
        }
    }

    /**
     * Konfirmasi instance transaksi berulang yang berstatus pending: barisnya
     * diubah jadi posted dan baru saat inilah saldo tersentuh.
     */
    public function confirm(Transaction $transaction, ?int $actorId = null): Transaction
    {
        abort_if($transaction->isPosted(), 422, 'Transaksi ini sudah terkonfirmasi.');

        $confirmed = DB::transaction(function () use ($transaction, $actorId): Transaction {
            $transaction->status = TransactionStatus::Posted;
            $transaction->updated_by = $actorId;
            $transaction->save();

            $fee = $this->adminFees->find($transaction);

            // Baris potongan admin ikut jadi posted supaya efek saldonya ikut
            // masuk di titik yang sama dengan transfernya.
            if ($fee !== null) {
                $fee->status = TransactionStatus::Posted;
                $fee->updated_by = $actorId;
                $fee->save();
            }

            $this->applyDeltas(
                $this->mergeDeltas($transaction->balanceDeltas(), $fee?->balanceDeltas() ?? []),
                $transaction->workspace_id,
            );

            return $transaction;
        });

        TransactionSaved::dispatch($confirmed);
        $this->dispatchAdminFeeSaved($confirmed);

        return $confirmed;
    }

    /**
     * Buang instance pending tanpa menyentuh saldo.
     */
    public function discard(Transaction $transaction): void
    {
        abort_unless($transaction->isPending(), 422, 'Hanya instance menunggu konfirmasi yang bisa dibuang.');

        // Deltas transaksi pending selalu kosong, jadi tidak ada saldo yang perlu
        // dibalik; cukup hapus lampiran, baris biaya admin, dan barisnya.
        $fee = $this->adminFees->find($transaction);

        DB::transaction(function () use ($transaction, $fee): void {
            $this->deleteAttachments($transaction);
            $transaction->tags()->detach();
            $fee?->delete();
            $transaction->delete();
        });

        TransactionDeleted::dispatch($transaction);

        if ($fee !== null) {
            TransactionDeleted::dispatch($fee);
        }
    }

    /**
     * Buat satu instance transaksi dari sebuah rule berulang.
     *
     * `occurred_at` diambil dari `next_run_at` rule, dan pemanggil bertanggung
     * jawab memanggil `RecurringRule::advanceNextRun()` setelah ini.
     * Saldo hanya tersentuh kalau rule tidak meminta konfirmasi.
     */
    public function createFromRule(RecurringRule $rule, ?int $actorId = null): Transaction
    {
        $attributes = [
            'account_id' => $rule->account_id,
            'transfer_to_account_id' => $rule->transfer_to_account_id,
            'category_id' => $rule->category_id,
            'recurring_rule_id' => $rule->id,
            'type' => $rule->type,
            'amount' => $rule->amount,
            'note' => $rule->note,
            'occurred_at' => $rule->next_run_at,
            'status' => $rule->requires_confirmation
                ? TransactionStatus::Pending
                : TransactionStatus::Posted,
        ];

        return $this->create($attributes, $actorId, $rule->tag_ids ?? []);
    }

    /**
     * Sinkronkan baris potongan admin milik sebuah transfer.
     *
     * Hanya transfer yang boleh punya potongan admin; untuk tipe lain
     * `AdminFeeManager` diberi 0 supaya baris yang tadinya ada ikut terhapus
     * (mis. user mengubah transfer menjadi expense biasa).
     */
    private function syncAdminFee(Transaction $transaction, ?int $adminFee, ?int $actorId): ?Transaction
    {
        return $this->adminFees->sync(
            $transaction,
            $transaction->isTransfer() ? $adminFee : 0,
            $actorId,
        );
    }

    /**
     * Kirim `TransactionSaved` untuk baris potongan admin, kalau ada.
     *
     * Baris biaya ikut meng-invalidate cache lewat event yang sama dengan
     * transaksi induknya, karena baris itu expense yang ikut dihitung dashboard,
     * laporan, dan progress anggaran.
     */
    private function dispatchAdminFeeSaved(Transaction $transaction): void
    {
        $fee = $this->adminFees->find($transaction);

        if ($fee !== null) {
            TransactionSaved::dispatch($fee);
        }
    }

    /**
     * Gabungkan dua peta delta per akun (dibalik + baru).
     *
     * @param  array<int, int>  $first
     * @param  array<int, int>  $second
     * @return array<int, int>
     */
    private function mergeDeltas(array $first, array $second): array
    {
        $merged = $first;

        foreach ($second as $accountId => $delta) {
            $merged[$accountId] = ($merged[$accountId] ?? 0) + $delta;
        }

        // Buang akun yang total deltanya nol supaya tidak ditulis tanpa alasan.
        return array_filter($merged, static fn (int $delta): bool => $delta !== 0);
    }

    /**
     * Terapkan peta `account_id => delta` (dalam sen) ke `cached_balance`.
     *
     * @param  array<int, int>  $deltas
     */
    private function applyDeltas(array $deltas, int $workspaceId): void
    {
        if ($deltas === []) {
            return;
        }

        $accounts = Account::allWorkspaces()
            ->whereIn('id', array_keys($deltas))
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        foreach ($deltas as $accountId => $delta) {
            $account = $accounts->get($accountId);

            // Akun harus ada dan milik workspace yang sama dengan transaksi.
            if ($account === null || (int) $account->workspace_id !== $workspaceId) {
                throw new RuntimeException("Akun {$accountId} tidak ada di workspace {$workspaceId}.");
            }

            $account->cached_balance = Money::fromCents(
                Money::toCents($account->cached_balance) + $delta,
            );

            $account->save();
        }
    }

    /**
     * Sinkronkan tag transaksi.
     *
     * Array kosong berarti "hapus semua tag" — form edit selalu mengirim
     * `tag_ids`, jadi pilihan kosong dari user harus benar-benar diterapkan,
     * bukan diabaikan.
     *
     * @param  array<int, int>  $tagIds
     */
    private function syncTags(Transaction $transaction, array $tagIds): void
    {
        $transaction->tags()->sync($tagIds);
    }

    private function storeAttachment(Transaction $transaction, UploadedFile $file): Attachment
    {
        // Nama file di disk dibuat dari UUID, bukan dari nama kiriman client,
        // supaya tidak bisa dipakai menyelinap lewat path traversal.
        $extension = strtolower($file->getClientOriginalExtension() ?: 'bin');
        $filename = Str::uuid()->toString().'.'.$extension;
        $path = Attachment::storagePathFor(
            (int) $transaction->workspace_id,
            $transaction->id,
            $filename,
        );

        $stored = Storage::disk('local')->putFileAs(dirname($path), $file, $filename);

        if ($stored === false) {
            throw new RuntimeException("Gagal menyimpan lampiran untuk transaksi {$transaction->id}.");
        }

        $attachment = new Attachment([
            'file_path' => $path,
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType(),
            'size' => $file->getSize(),
        ]);

        $attachment->workspace_id = $transaction->workspace_id;
        $attachment->transaction_id = $transaction->id;
        $attachment->save();

        return $attachment;
    }

    private function deleteAttachments(Transaction $transaction): void
    {
        foreach ($transaction->attachments as $attachment) {
            Storage::disk('local')->delete($attachment->file_path);
            $attachment->delete();
        }
    }
}
