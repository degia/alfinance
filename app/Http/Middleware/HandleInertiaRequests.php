<?php

namespace App\Http\Middleware;

use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use App\Support\Workspace\ActiveWorkspace;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $user,
            ],
            'workspace' => $this->workspacePayload($user, $this->activeWorkspace()),
            'workspaces' => $this->workspacesPayload($user, $this->activeWorkspace()),
            'sidebarCollapsed' => (bool) ($user->sidebar_collapsed ?? false),
            'backup_diff' => fn () => $request->session()->pull('backup_diff'),
            'backup_result' => fn () => $request->session()->pull('backup_result'),
        ];
    }

    protected function activeWorkspace(): ?Workspace
    {
        return ActiveWorkspace::workspace();
    }

    /**
     * @return array{id: int, name: string, role: string, is_owner: bool}|null
     */
    protected function workspacePayload(?User $user, ?Workspace $workspace): ?array
    {
        if (! $user || ! $workspace) {
            return null;
        }

        return [
            'id' => $workspace->id,
            'name' => $workspace->name,
            'role' => $user->roleIn($workspace)->value ?? WorkspaceRole::Member->value,
            'is_owner' => $workspace->owner_id === $user->id,
        ];
    }

    /**
     * Role diambil dari pivot yang sudah dimuat agar tidak ada query per
     * workspace (N+1) pada setiap navigasi.
     *
     * @return array<int, array{id: int, name: string, role: string, is_owner: bool, is_active: bool}>
     */
    protected function workspacesPayload(?User $user, ?Workspace $active): array
    {
        if (! $user) {
            return [];
        }

        return $user->workspaces()
            ->orderBy('name')
            ->get()
            ->map(function (Workspace $workspace) use ($user, $active): array {
                $pivot = $workspace->pivot;

                return [
                    'id' => $workspace->id,
                    'name' => $workspace->name,
                    'role' => $pivot instanceof WorkspaceUser
                        ? $pivot->role->value
                        : $user->roleIn($workspace)->value ?? WorkspaceRole::Member->value,
                    'is_owner' => $workspace->owner_id === $user->id,
                    'is_active' => $active?->id === $workspace->id,
                ];
            })
            ->values()
            ->all();
    }
}
