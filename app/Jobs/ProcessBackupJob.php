<?php

namespace App\Jobs;

use App\Models\Backup;
use App\Notifications\BackupFailed;
use App\Notifications\BackupReady;
use App\Services\Backups\BackupService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;

/**
 * Proses satu backup JSON dari antrean (PRD.md §3.11).
 *
 * Dibuat dari {`BackupController::store`} dan hanya menulis berkas + baris
 * `backups.status`; tidak pernah menekan jalur request dengan payload besar.
 */
class ProcessBackupJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $backupId,
    ) {}

    public function handle(BackupService $backups): void
    {
        $backup = Backup::find($this->backupId);

        if ($backup === null) {
            return;
        }

        // Backup yang sudah selesai/gagal tidak boleh ditulis ulang oleh
        // retry worker.
        if ($backup->isFinished()) {
            return;
        }

        try {
            $backups->generate($backup);
        } catch (\Throwable $exception) {
            $backup->markFailed($exception->getMessage());

            $this->notify($backup, new BackupFailed($backup));

            Log::error('Backup gagal diproses.', [
                'backup_id' => $this->backupId,
                'exception' => $exception->getMessage(),
            ]);

            return;
        }

        $this->notify($backup, new BackupReady($backup));
    }

    /**
     * Notifikasi tidak boleh mengubah status proses: kanal `database` bisa
     * gagal (mis. tabel belum dimigrasi) sementara berkas JSON sudah ditulis,
     * jadi kegagalan di sini hanya dicatat.
     */
    private function notify(Backup $backup, Notification $notification): void
    {
        $user = $backup->user;

        if ($user === null) {
            return;
        }

        try {
            $user->notify($notification);
        } catch (\Throwable $exception) {
            Log::warning('Notifikasi backup gagal dikirim.', [
                'backup_id' => $this->backupId,
                'notification' => $notification::class,
                'exception' => $exception->getMessage(),
            ]);
        }
    }
}
