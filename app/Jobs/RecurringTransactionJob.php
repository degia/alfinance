<?php

namespace App\Jobs;

use App\Models\RecurringRule;
use App\Services\Transactions\TransactionManager;
use App\Support\Workspace\ActiveWorkspace;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Membuat instance transaksi untuk semua rule berulang yang jatuh tempo
 * (PRD.md §3.3 "Transaksi Berulang").
 *
 * Dijadwalkan harian lewat `routes/console.php`. Job berjalan tanpa request,
 * jadi tidak ada `ActiveWorkspace` dari middleware: setiap rule diproses
 * dengan workspace-nya dikunci sementara, dan aturan global scope tetap
 * berlaku (fail-closed) selama pemrosesan.
 *
 * Idempoten: satu rule hanya boleh menghasilkan satu instance per tanggal
 * `next_run_at`, sehingga job yang dijalankan ulang di hari yang sama tidak
 * membuat duplikat.
 */
class RecurringTransactionJob implements ShouldQueue
{
    use Queueable;

    /**
     * Berapa banyak rule yang diproses per batch. Mencegah satu eksekusi
     * berlangsung terlalu lama kalau workspace besar.
     */
    private const BATCH_SIZE = 100;

    public function handle(TransactionManager $transactions): void
    {
        $due = ActiveWorkspace::withoutScope(
            fn () => RecurringRule::query()
                ->due()
                ->orderBy('next_run_at')
                ->limit(self::BATCH_SIZE)
                ->get(),
        );

        foreach ($due as $rule) {
            $this->generate($rule, $transactions);
        }
    }

    private function generate(RecurringRule $rule, TransactionManager $transactions): void
    {
        $workspace = $rule->workspace;

        if ($workspace === null) {
            Log::warning('Transaksi berulang dilewati: workspace tidak ditemukan.', [
                'rule_id' => $rule->id,
            ]);

            return;
        }

        $occurredAt = $rule->next_run_at;
        $previousScope = ActiveWorkspace::workspace();

        ActiveWorkspace::set($workspace);

        try {
            // Kalau tanggal ini sudah pernah diproses (job dijalankan ulang,
            // atau jadwal berkurang), jangan buat instance kedua.
            $exists = $rule->transactions()
                ->whereDate('occurred_at', $occurredAt->toDateString())
                ->exists();

            if (! $exists) {
                $transactions->createFromRule($rule);
            }

            $rule->advanceNextRun();
            $rule->save();
        } finally {
            ActiveWorkspace::set($previousScope);
        }
    }
}
