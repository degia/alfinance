<?php

namespace App\Models;

use App\Enums\BackupScope;
use App\Enums\BackupStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Satu backup JSON per workspace (PRD.md §3.11).
 *
 * Sama seperti {`ExportJob`}: bukan model domain, `workspace_id` diisi
 * eksplisit. `summary` berisi jumlah baris per bagian untuk preview cepat.
 *
 * @property int $id
 * @property int $workspace_id
 * @property int|null $user_id
 * @property BackupScope $scope
 * @property CarbonImmutable|null $from
 * @property CarbonImmutable|null $to
 * @property int $schema_version
 * @property BackupStatus $status
 * @property string|null $file_path
 * @property string|null $file_name
 * @property int|null $size_bytes
 * @property string|null $error
 * @property array<string, int>|null $summary
 * @property Carbon|null $completed_at
 */
#[Fillable([
    'workspace_id',
    'user_id',
    'scope',
    'from',
    'to',
    'schema_version',
    'status',
    'file_path',
    'file_name',
    'size_bytes',
    'error',
    'summary',
    'completed_at',
])]
class Backup extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scope' => BackupScope::class,
            'status' => BackupStatus::class,
            'schema_version' => 'integer',
            'size_bytes' => 'integer',
            'workspace_id' => 'integer',
            'user_id' => 'integer',
            'summary' => 'array',
            'from' => 'date',
            'to' => 'date',
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
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isFinished(): bool
    {
        return $this->status->isDone() || $this->status === BackupStatus::Failed;
    }

    /**
     * @param  array<string, int>  $summary
     */
    public function markDone(string $filePath, string $fileName, int $sizeBytes, array $summary): void
    {
        $this->status = BackupStatus::Done;
        $this->file_path = $filePath;
        $this->file_name = $fileName;
        $this->size_bytes = $sizeBytes;
        $this->summary = $summary;
        $this->error = null;
        $this->completed_at = Carbon::now();
        $this->save();
    }

    public function markFailed(string $message): void
    {
        $this->status = BackupStatus::Failed;
        $this->error = $message;
        $this->completed_at = Carbon::now();
        $this->save();
    }
}
