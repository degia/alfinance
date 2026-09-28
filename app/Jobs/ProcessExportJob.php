<?php

namespace App\Jobs;

use App\Models\ExportJob;
use App\Notifications\ExportFailed;
use App\Notifications\ExportReady;
use App\Services\Exports\ExportService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;

/**
 * Proses satu ekspor dari antrean (PRD.md §3.10, ARCHITECTURE.md §2.1
 * butir 4). Dijadwalkan dari {`ExportController::store`} dengan delay kecil
 * supaya status `queued` sempat terlihat di halaman sebelum berubah jadi
 * `processing`.
 *
 * Job berjalan tanpa konteks request, jadi tidak memakai `ActiveWorkspace`;
 * semua data workspace diambil dari {@see ExportJob}.
 */
class ProcessExportJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $exportJobId,
    ) {}

    public function handle(ExportService $exports): void
    {
        $job = ExportJob::find($this->exportJobId);

        if ($job === null) {
            return;
        }

        // Job diminta dua kali (mis. worker di-restart) tidak boleh menulis
        // ulang ekspor yang sudah final.
        if ($job->status->isFinal()) {
            return;
        }

        $job->markStart();

        try {
            $file = $exports->build($job);

            $job->markDone($file['file_path'], $file['file_name'], $file['size_bytes']);
        } catch (\Throwable $exception) {
            $job->markFailed($exception->getMessage());

            $this->notify($job, new ExportFailed($job));

            Log::error('Ekspor gagal diproses.', [
                'export_job_id' => $this->exportJobId,
                'exception' => $exception->getMessage(),
            ]);

            return;
        }

        $this->notify($job, new ExportReady($job));
    }

    /**
     * Notifikasi tidak boleh mengubah status proses: kanal `database` bisa
     * gagal (mis. tabel belum dimigrasi) sementara berkas sudah jadi, jadi
     * kegagalan di sini hanya dicatat, bukan dilempar ke pemanggil job.
     */
    private function notify(ExportJob $job, Notification $notification): void
    {
        $user = $job->user;

        if ($user === null) {
            return;
        }

        try {
            $user->notify($notification);
        } catch (\Throwable $exception) {
            Log::warning('Notifikasi ekspor gagal dikirim.', [
                'export_job_id' => $this->exportJobId,
                'notification' => $notification::class,
                'exception' => $exception->getMessage(),
            ]);
        }
    }
}
