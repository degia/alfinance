<?php

namespace App\Services\Workspaces;

use App\Models\Attachment;
use App\Models\Workspace;
use App\Services\Backups\BackupService;
use App\Services\Cache\WorkspaceCache;
use App\Services\Exports\ExportService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Siklus hidup penghapusan workspace.
 *
 * Dipisah dari controller karena satu workspace menyentuh tiga tempat yang
 * tidak saling tahu: baris database (18+ tabel tenant), berkas di tiga disk
 * berbeda, dan cache agregat di Redis. Menaruhnya di satu kelas membuat
 * urutan dan cakupan pembersihan punya satu sumber kebenaran.
 */
class WorkspaceDeleter
{
    public function __construct(private readonly WorkspaceCache $cache) {}

    /**
     * Pindahkan ke "Sampah". Data dan berkas tetap utuh supaya bisa dipulihkan.
     *
     * Cache agregat tetap dibuang: selama workspace di Sampah tidak ada yang
     * membacanya, dan membiarkan key hidup berisiko menyajikan angka basi
     * kalau nanti dipulihkan.
     */
    public function softDelete(Workspace $workspace): void
    {
        $workspace->delete();

        $this->cache->forgetAll($workspace->id);
    }

    public function restore(Workspace $workspace): void
    {
        $workspace->restore();

        $this->cache->forgetAll($workspace->id);
    }

    /**
     * Hapus permanen: baris `workspaces` dibuang sehingga seluruh tabel tenant
     * ikut terhapus lewat FK `cascadeOnDelete`, lalu berkas dan cache dibersihkan.
     *
     * Berkas dihapus SETELAH transaksi database sukses — menghapus file lebih
     * dulu berarti kehilangan lampiran tanpa bisa dibatalkan kalau DB gagal.
     */
    public function forceDelete(Workspace $workspace): void
    {
        $workspaceId = $workspace->id;

        DB::transaction(static function () use ($workspace): void {
            $workspace->users()->detach();
            $workspace->forceDelete();
        });

        $this->purgeFiles($workspaceId);
        $this->cache->forgetAll($workspaceId);
    }

    /**
     * Berkas milik satu workspace tersebar di tiga disk dengan konvensi path
     * yang berbeda; semuanya tetap diawali segment id workspace.
     *
     * @see Attachment::storagePathFor()
     * @see ExportService
     * @see BackupService
     */
    private function purgeFiles(int $workspaceId): void
    {
        $directories = [
            'local' => 'workspaces/'.$workspaceId,
            'exports' => 'exports/'.$workspaceId,
            'backups' => (string) $workspaceId,
        ];

        foreach ($directories as $disk => $directory) {
            try {
                Storage::disk($disk)->deleteDirectory($directory);
            } catch (\Throwable $exception) {
                // Berkas yatim tidak boleh membatalkan penghapusan yang sudah
                // ter-commit di database; cukup dicatat untuk dibersihkan manual.
                Log::warning('Gagal membersihkan berkas workspace.', [
                    'workspace_id' => $workspaceId,
                    'disk' => $disk,
                    'directory' => $directory,
                    'exception' => $exception->getMessage(),
                ]);
            }
        }
    }
}
