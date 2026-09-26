<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Workspace;
use App\Policies\WorkspacePolicy;
use App\Support\Workspace\ActiveWorkspace;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Otorisasi data domain terhadap workspace yang sedang aktif.
 *
 * Semua tabel Fase 2+ (accounts, categories, tags, transactions, budgets, …)
 * di-scope ke workspace aktif, jadi policy-nya tidak per-model: cukup
 * panggil `view` / `editData` dari {@see WorkspacePolicy}
 * memakai workspace aktif tersebut.
 *
 * Viewer lolos untuk `view`, ditolak untuk `editData`.
 */
trait AuthorizesWorkspaceData
{
    protected function authorizeViewData(): void
    {
        $this->authorizeAgainstWorkspace('view');
    }

    protected function authorizeEditData(): void
    {
        $this->authorizeAgainstWorkspace('editData');
    }

    private function authorizeAgainstWorkspace(string $ability): void
    {
        $workspace = ActiveWorkspace::workspace();

        if (! $workspace instanceof Workspace) {
            throw new AccessDeniedHttpException('Tidak ada workspace aktif.');
        }

        Gate::authorize($ability, $workspace);
    }
}
