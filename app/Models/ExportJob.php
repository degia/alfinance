<?php

namespace App\Models;

use App\Enums\ExportStatus;
use App\Enums\ExportType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Satu permintaan ekspor (PRD.md §3.10).
 *
 * Bukan model domain (tidak memakai {`WorkspaceScopedModel`}) karena dibuat
 * dan dibaca ulang dari dalam job antrean yang belum tentu punya workspace
 * aktif — `workspace_id` selalu diisi eksplisit oleh pemanggil.
 *
 * @property int $id
 * @property int $workspace_id
 * @property int|null $user_id
 * @property ExportType $type
 * @property ExportStatus $status
 * @property array<string, mixed>|null $filters
 * @property string|null $file_path
 * @property string|null $file_name
 * @property int|null $size_bytes
 * @property string|null $error
 * @property Carbon|null $completed_at
 */
#[Fillable([
    'workspace_id',
    'user_id',
    'type',
    'status',
    'filters',
    'file_path',
    'file_name',
    'size_bytes',
    'error',
    'completed_at',
])]
class ExportJob extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => ExportType::class,
            'status' => ExportStatus::class,
            'filters' => 'array',
            'size_bytes' => 'integer',
            'workspace_id' => 'integer',
            'user_id' => 'integer',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * Pemohon ekspor, null bila dibuat tanpa konteks user.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isFinished(): bool
    {
        return $this->status->isFinal();
    }

    public function markStart(): void
    {
        $this->status = ExportStatus::Processing;
        $this->save();
    }

    public function markDone(string $filePath, string $fileName, int $sizeBytes): void
    {
        $this->status = ExportStatus::Done;
        $this->file_path = $filePath;
        $this->file_name = $fileName;
        $this->size_bytes = $sizeBytes;
        $this->error = null;
        $this->completed_at = Carbon::now();
        $this->save();
    }

    public function markFailed(string $message): void
    {
        $this->status = ExportStatus::Failed;
        $this->error = $message;
        $this->completed_at = Carbon::now();
        $this->save();
    }
}
