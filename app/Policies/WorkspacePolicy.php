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
 *
 * Pengecualian tunggal: `restore`/`forceDelete` juga terbuka untuk Admin
 * global, karena keduanya mengurus siklus hidup workspace (memulihkan atau
 * membuang isi "Sampah") dan bukan akses baca/tulis ke data keuangannya.
 * Sengaja tidak dipasang sebagai `before()` supaya Admin global tetap tidak
 * bisa `view`/`editData` buku keuangan milik orang lain.
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

    /**
     * Pindahkan workspace ke "Sampah" (soft delete).
     */
    public function delete(User $user, Workspace $workspace): bool
    {
        return $this->role($user, $workspace)?->canDeleteWorkspace() ?? false;
    }

    /**
     * Keluarkan workspace dari "Sampah".
     */
    public function restore(User $user, Workspace $workspace): bool
    {
        return $workspace->owner_id === $user->id || $user->isAdmin();
    }

    /**
     * Buang workspace beserta seluruh datanya, permanen.
     */
    public function forceDelete(User $user, Workspace $workspace): bool
    {
        return $workspace->owner_id === $user->id || $user->isAdmin();
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
