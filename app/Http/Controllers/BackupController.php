<?php

namespace App\Http\Controllers;

use App\Enums\BackupScope;
use App\Enums\BackupStatus;
use App\Http\Controllers\Concerns\AuthorizesWorkspaceData;
use App\Http\Requests\RestoreBackupRequest;
use App\Http\Requests\StoreBackupRequest;
use App\Jobs\ProcessBackupJob;
use App\Models\Backup;
use App\Services\Backups\BackupService;
use App\Support\Workspace\ActiveWorkspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Pembuatan backup, preview restore (dry-run diff), dan eksekusi restore
 * (PRD.md §3.11). Semua proses berat (menulis payload JSON) lewat
 * {@see ProcessBackupJob}; preview & restore dipakai dari halaman Ekspor.
 */
class BackupController extends Controller
{
    use AuthorizesWorkspaceData;

    public function __construct(private readonly BackupService $backups) {}

    public function store(StoreBackupRequest $request): RedirectResponse
    {
        $this->authorizeEditData();

        $validated = $request->validated();
        $scope = BackupScope::from($validated['scope']);

        $backup = new Backup([
            'workspace_id' => ActiveWorkspace::idOrFail(),
            'user_id' => $request->user()?->id,
            'scope' => $scope,
            'status' => BackupStatus::Queued,
            'schema_version' => BackupService::SCHEMA_VERSION,
            'from' => $scope->needsRange() ? $validated['from'] : null,
            'to' => $scope->needsRange() ? $validated['to'] : null,
        ]);

        $backup->save();

        ProcessBackupJob::dispatch($backup->id)->delay(now()->addSeconds(1));

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Backup :scope sedang diproses di antrean.', ['scope' => $scope->label()]),
        ]);

        return back();
    }

    /**
     * Preview restore (dry-run diff): validasi skema + rencana baris
     * bertambah/ditimpa. Hasilnya di-flash ke halaman, tidak mengubah data.
     */
    public function preview(Backup $backup): RedirectResponse
    {
        $this->authorizeViewData();

        $this->assertSameWorkspace($backup);

        try {
            $diff = $this->backups->preview($backup);
        } catch (RuntimeException $exception) {
            $this->flashError($exception->getMessage());

            return back();
        }

        $request = request();

        $request->session()->flash('backup_diff', $diff);

        return back();
    }

    /**
     * Eksekusi restore setelah user mengonfirmasi dari hasil preview.
     */
    public function restore(RestoreBackupRequest $request, Backup $backup): RedirectResponse
    {
        $this->authorizeEditData();

        $this->assertSameWorkspace($backup);

        try {
            $result = $this->backups->restore($backup);
        } catch (RuntimeException $exception) {
            $this->flashError($exception->getMessage());

            return back();
        }

        $request->session()->flash('backup_result', $result);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Backup berhasil dipulihkan ke workspace ini.'),
        ]);

        return back();
    }

    /**
     * Unduh file JSON backup.
     */
    public function download(Backup $backup): StreamedResponse
    {
        $this->authorizeViewData();

        $this->assertSameWorkspace($backup);

        if ($backup->status !== BackupStatus::Done || $backup->file_path === null) {
            throw new NotFoundHttpException('Backup belum siap diunduh.');
        }

        return Storage::disk('backups')->download(
            $backup->file_path,
            $backup->file_name ?? basename((string) $backup->file_path),
        );
    }

    private function assertSameWorkspace(Backup $backup): void
    {
        abort_unless($backup->workspace_id === ActiveWorkspace::idOrFail(), 404);
    }

    private function flashError(string $message): void
    {
        Inertia::flash('toast', [
            'type' => 'error',
            'message' => $message,
        ]);
    }
}
