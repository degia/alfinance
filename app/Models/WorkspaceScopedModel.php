<?php

namespace App\Models;

use App\Models\Scopes\WorkspaceScope;
use App\Support\Workspace\ActiveWorkspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Base model untuk seluruh tabel data domain (accounts, transactions,
 * budgets, debts, net worth items, dst).
 *
 * Aturan:
 * - Migration wajib punya kolom `workspace_id` (ARCHITECTURE.md §3).
 * - `workspace_id` terisi otomatis dari workspace aktif saat model di-create.
 * - Query di jalur web otomatis tersaring oleh {@see WorkspaceScope}.
 */
abstract class WorkspaceScopedModel extends Model
{
    protected static function booted(): void
    {
        static::addGlobalScope(new WorkspaceScope);

        static::creating(function (Model $model): void {
            if ($model->getAttribute('workspace_id') === null) {
                $model->setAttribute('workspace_id', ActiveWorkspace::id());
            }
        });
    }

    /**
     * Query lintas workspace — hanya untuk job agregat, export, dan backup.
     * Wajib diisi filter workspace secara manual oleh pemanggil.
     *
     * @return Builder<static>
     */
    public static function allWorkspaces(): Builder
    {
        return static::query()->withoutGlobalScope(WorkspaceScope::class);
    }

    /**
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
