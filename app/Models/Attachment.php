<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\AttachmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * Lampiran bukti transaksi — struk atau PDF (PRD.md §3.3).
 *
 * Berkas disimpan di disk privat (bukan `public`) dan diberi path per workspace
 * supaya file satu workspace tidak bisa dibaca lewat jalur another's
 * (ARCHITECTURE.md §5). Unduh hanya lewat route yang sudah melewati
 * middleware auth + tenant scope.
 *
 * @property int $id
 * @property int $workspace_id
 * @property int $transaction_id
 * @property string $file_path
 * @property string $original_name
 * @property string|null $mime_type
 * @property int|null $size
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
#[Fillable(['transaction_id', 'file_path', 'original_name', 'mime_type', 'size'])]
class Attachment extends WorkspaceScopedModel
{
    /** @use HasFactory<AttachmentFactory> */
    use HasFactory;

    /**
     * Path relatif lampiran di dalam disk privat.
     *
     * Diawali dengan segment workspace supaya file pisah secara fisik antar
     * tenant, bukan cuma secara query.
     */
    public static function storagePathFor(int $workspaceId, int $transactionId, string $filename): string
    {
        return sprintf('workspaces/%d/transactions/%d/%s', $workspaceId, $transactionId, $filename);
    }

    /**
     * @return BelongsTo<Transaction, $this>
     */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    public function existsOnDisk(): bool
    {
        return Storage::disk('local')->exists($this->file_path);
    }

    /**
     * @return resource|null
     */
    public function readStream()
    {
        if (! $this->existsOnDisk()) {
            return null;
        }

        return Storage::disk('local')->readStream($this->file_path);
    }

    public function humanSize(): ?string
    {
        return $this->size === null ? null : $this->sizeToHuman($this->size);
    }

    private function sizeToHuman(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $power = $bytes > 0 ? (int) floor(log($bytes, 1024)) : 0;
        $power = min($power, count($units) - 1);

        return round($bytes / (1024 ** $power), 1).' '.$units[$power];
    }
}
