<?php

namespace App\Http\Controllers;

use App\Enums\WorkspaceRole;
use App\Http\Requests\StoreWorkspaceRequest;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use App\Support\Workspace\ActiveWorkspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class WorkspaceController extends Controller
{
    /**
     * Halaman pilih workspace (setelah login, atau saat belum ada workspace aktif).
     */
    public function index(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('workspaces/Select', [
            'workspaces' => $user->workspaces()
                ->withCount('users')
                ->orderBy('name')
                ->get()
                ->map(fn (Workspace $workspace): array => [
                    'id' => $workspace->id,
                    'name' => $workspace->name,
                    'role' => $this->roleValue($workspace, $user),
                    'is_owner' => $workspace->owner_id === $user->id,
                    'members_count' => $workspace->users_count,
                    'is_active' => ActiveWorkspace::id() === $workspace->id,
                ])
                ->values()
                ->all(),
        ]);
    }

    /**
     * Role dari pivot yang sudah dimuat, tanpa query tambahan per workspace.
     */
    private function roleValue(Workspace $workspace, User $user): string
    {
        $pivot = $workspace->pivot;

        return $pivot instanceof WorkspaceUser
            ? $pivot->role->value
            : $user->roleIn($workspace)->value ?? WorkspaceRole::Member->value;
    }

    /**
     * Buat workspace baru; user menjadi owner dan langsung aktif.
     */
    public function store(StoreWorkspaceRequest $request): RedirectResponse
    {
        $user = $request->user();

        $workspace = DB::transaction(function () use ($request, $user): Workspace {
            $workspace = Workspace::create([
                'name' => $request->validated('name'),
                'owner_id' => $user->id,
            ]);

            $workspace->addUser($user, WorkspaceRole::Owner);

            return $workspace;
        });

        $request->session()->put('workspace_id', $workspace->id);
        ActiveWorkspace::set($workspace);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Workspace :name berhasil dibuat.', ['name' => $workspace->name]),
        ]);

        return to_route('dashboard');
    }

    /**
     * Pindah workspace aktif.
     */
    public function switch(Request $request, Workspace $workspace): RedirectResponse
    {
        $this->authorize('view', $workspace);

        $request->session()->put('workspace_id', $workspace->id);
        ActiveWorkspace::set($workspace);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Berpindah ke workspace :name.', ['name' => $workspace->name]),
        ]);

        return to_route('dashboard');
    }

    /**
     * Keluar dari workspace (hanya untuk anggota non-owner).
     */
    public function leave(Request $request, Workspace $workspace): RedirectResponse
    {
        $this->authorize('view', $workspace);

        if ($workspace->owner_id === $request->user()->id) {
            return back()->with('toast', [
                'type' => 'error',
                'message' => __('Owner tidak dapat keluar dari workspace miliknya sendiri.'),
            ]);
        }

        $workspace->removeUser($request->user());

        if (ActiveWorkspace::id() === $workspace->id) {
            $request->session()->forget('workspace_id');
            ActiveWorkspace::forget();
        }

        return to_route('workspaces.index');
    }
}
