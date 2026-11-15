<?php

namespace App\Http\Controllers;

use App\Enums\WorkspaceRole;
use App\Http\Requests\StoreWorkspaceRequest;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use App\Services\Workspaces\WorkspaceDeleter;
use App\Support\Workspace\ActiveWorkspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class WorkspaceController extends Controller
{
    public function __construct(private readonly WorkspaceDeleter $deleter) {}

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
            'trashed' => $this->trashedPayload($user),
        ]);
    }

    /**
     * Isi "Sampah": hanya workspace yang dimiliki user ini.
     *
     * Anggota biasa tidak perlu melihatnya — bagi mereka workspace yang dihapus
     * owner memang sudah tidak ada, dan hanya owner yang berhak memulihkannya.
     *
     * @return array<int, array{id: int, name: string, members_count: int, deleted_at: string, deleted_at_diff: string}>
     */
    private function trashedPayload(User $user): array
    {
        return $user->workspaces()
            ->onlyTrashed()
            ->where('workspaces.owner_id', $user->id)
            ->withCount('users')
            ->orderByDesc('workspaces.deleted_at')
            ->get()
            ->map(fn (Workspace $workspace): array => [
                'id' => $workspace->id,
                'name' => $workspace->name,
                'members_count' => $workspace->users_count,
                'deleted_at' => $workspace->deleted_at->toIso8601String(),
                'deleted_at_diff' => $workspace->deleted_at->diffForHumans(),
            ])
            ->values()
            ->all();
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

        $this->releaseIfActive($request, $workspace);

        return to_route('workspaces.index');
    }

    /**
     * Pindahkan workspace ke "Sampah" (soft delete, owner saja).
     *
     * Dibuat dua langkah secara sengaja: satu workspace berisi seluruh buku
     * keuangan, jadi aksi ini harus bisa dibatalkan sebelum datanya hilang.
     */
    public function destroy(Request $request, Workspace $workspace): RedirectResponse
    {
        $this->authorize('delete', $workspace);

        $this->deleter->softDelete($workspace);

        $this->releaseIfActive($request, $workspace);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Workspace :name dipindahkan ke Sampah.', ['name' => $workspace->name]),
        ]);

        return to_route('workspaces.index');
    }

    /**
     * Keluarkan workspace dari "Sampah".
     */
    public function restore(Request $request, Workspace $workspace): RedirectResponse
    {
        $this->authorize('restore', $workspace);

        $this->deleter->restore($workspace);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Workspace :name dipulihkan.', ['name' => $workspace->name]),
        ]);

        return to_route('workspaces.index');
    }

    /**
     * Hapus permanen beserta seluruh data dan berkasnya.
     *
     * Hanya berlaku untuk workspace yang sudah berada di "Sampah" — supaya
     * tidak ada jalur satu-klik dari daftar aktif ke penghapusan permanen.
     */
    public function forceDestroy(Request $request, Workspace $workspace): RedirectResponse
    {
        $this->authorize('forceDelete', $workspace);

        if (! $workspace->trashed()) {
            return back()->with('toast', [
                'type' => 'error',
                'message' => __('Pindahkan workspace ke Sampah dulu sebelum menghapusnya permanen.'),
            ]);
        }

        $name = $workspace->name;

        $this->deleter->forceDelete($workspace);

        $this->releaseIfActive($request, $workspace);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Workspace :name dihapus permanen.', ['name' => $name]),
        ]);

        return to_route('workspaces.index');
    }

    /**
     * Lepas workspace dari sesi bila yang baru saja ditinggalkan/dihapus adalah
     * workspace aktif, supaya request berikutnya tidak menunjuk tenant hantu.
     */
    private function releaseIfActive(Request $request, Workspace $workspace): void
    {
        if (ActiveWorkspace::id() !== $workspace->id) {
            return;
        }

        $request->session()->forget('workspace_id');
        ActiveWorkspace::forget();
    }
}
