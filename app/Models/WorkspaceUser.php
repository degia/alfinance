<?php

namespace App\Models;

use App\Enums\WorkspaceRole;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * Pivot `workspace_user` dengan role yang sudah ter-cast ke enum.
 *
 * @property int $workspace_id
 * @property int $user_id
 * @property WorkspaceRole $role
 */
#[Fillable(['workspace_id', 'user_id', 'role'])]
class WorkspaceUser extends Pivot
{
    /**
     * @var string
     */
    protected $table = Workspace::PIVOT_TABLE;

    /**
     * @var bool
     */
    public $incrementing = true;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => WorkspaceRole::class,
        ];
    }
}
