<?php

namespace App\Policies;

use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;

/**
 * Otorisasi berbasis role workspace (ARCHITECTURE.md §5).
 *
 * Semua keputusan diambil dari pivot role user pada workspace yangdicek —
 * bukan dari atribut global user.
 */
class WorkspacePolicy
{
    /**
     * Setiap user terotorisasi boleh membuat workspace baru untuk dirinya sendiri.
     */
    public function create(User $user): bool
    {
        return true;
    }

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Workspace $workspace): bool
    {
        return $user->belongsToWorkspace($workspace);
    }

    public function update(User $user, Workspace $workspace): bool
    {
        return $this->role($user, $workspace)?->canUpdateWorkspace() ?? false;
    }

    public function delete(User $user, Workspace $workspace): bool
    {
        return $this->role($user, $workspace)?->canDeleteWorkspace() ?? false;
    }

    public function manageMembers(User $user, Workspace $workspace): bool
    {
        return $this->role($user, $workspace)?->canManageMembers() ?? false;
    }

    /**
     * Boleh menulis data keuangan di workspace ini?
     */
    public function editData(User $user, Workspace $workspace): bool
    {
        return $this->role($user, $workspace)?->canEditData() ?? false;
    }

    protected function role(User $user, Workspace $workspace): ?WorkspaceRole
    {
        return $user->roleIn($workspace);
    }
}
