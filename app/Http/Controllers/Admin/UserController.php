<?php

namespace App\Http\Controllers\Admin;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\IndexUserRequest;
use App\Http\Requests\Admin\SuspendUserRequest;
use App\Http\Requests\Admin\UpdateUserRoleRequest;
use App\Models\User;
use App\Policies\UserPolicy;
use App\Services\Admin\UserSessionTerminator;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Manajemen pengguna untuk Admin global.
 *
 * Setiap aksi yang mengubah akun melewati {@see UserPolicy},
 * bukan hanya middleware `admin` — middleware menjawab "boleh masuk panel?",
 * policy menjawab "boleh dikenakan ke akun ini?" (mis. admin tidak boleh
 * menangguhkan atau menghapus dirinya sendiri).
 */
class UserController extends Controller
{
    private const int PER_PAGE = 15;

    public function __construct(private readonly UserSessionTerminator $sessions) {}

    public function index(IndexUserRequest $request): Response
    {
        $status = $request->status();

        return Inertia::render('admin/Users', [
            'users' => $this->paginate($request),
            'filters' => [
                'search' => $request->searchTerm(),
                'status' => $status,
                'sort' => $request->sortColumn(),
                'direction' => $request->sortDirection(),
            ],
            'statusOptions' => IndexUserRequest::STATUSES,
            'sortOptions' => IndexUserRequest::SORTABLE,
            'supportsForceLogout' => config('session.driver') === 'database',
        ]);
    }

    /**
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    private function paginate(IndexUserRequest $request): LengthAwarePaginator
    {
        return User::query()
            ->withCount('workspaces')
            ->when($request->searchTerm(), $this->searchFilter(...))
            ->when($request->status() !== 'all', fn (Builder $query) => $this->statusFilter($query, $request->status()))
            ->orderBy($request->sortColumn(), $request->sortDirection())
            // Tie-breaker: tanpa ini baris dengan nilai sort identik (mis.
            // banyak `last_login_at` NULL) bisa berpindah halaman antar request.
            ->orderBy('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString()
            ->through($this->toPayload(...));
    }

    /**
     * @param  Builder<User>  $query
     */
    private function searchFilter(Builder $query, string $search): void
    {
        $term = '%'.addcslashes($search, '%_\\').'%';

        $query->where(function (Builder $inner) use ($term): void {
            $inner->where('name', 'like', $term)
                ->orWhere('email', 'like', $term);
        });
    }

    /**
     * @param  Builder<User>  $query
     */
    private function statusFilter(Builder $query, string $status): void
    {
        match ($status) {
            'online' => $query->online(),
            'admin' => $query->admins(),
            'suspended' => $query->suspended(),
            'unverified' => $query->whereNull('email_verified_at'),
            'never_logged_in' => $query->whereNull('last_login_at'),
            default => null,
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function toPayload(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role->value,
            'role_label' => $user->role->label(),
            'is_online' => $user->isOnline(),
            'is_suspended' => $user->isSuspended(),
            'suspended_reason' => $user->suspended_reason,
            'is_verified' => $user->email_verified_at !== null,
            'two_factor_enabled' => $user->two_factor_confirmed_at !== null,
            'workspaces_count' => $user->workspaces_count,
            'last_login_at_diff' => $user->last_login_at?->diffForHumans(),
            'last_seen_at_diff' => $user->last_seen_at?->diffForHumans(),
            'last_login_ip' => $user->last_login_ip,
            'created_at' => $user->created_at?->toDateString(),
            'created_at_diff' => $user->created_at?->diffForHumans(),
        ];
    }

    public function updateRole(UpdateUserRoleRequest $request, User $user): RedirectResponse
    {
        $this->authorize('updateRole', $user);

        $role = $request->role();

        // Menurunkan admin terakhir akan membuat panel ini tidak bisa diakses
        // siapa pun lagi — satu-satunya jalan pulih adalah lewat CLI.
        if ($role === UserRole::User && $user->isAdmin() && User::query()->admins()->count() <= 1) {
            return $this->error(__('Minimal harus ada satu admin. Tunjuk admin lain dulu.'));
        }

        $user->role = $role;
        $user->save();

        return $this->success(__(':name sekarang berperan sebagai :role.', [
            'name' => $user->name,
            'role' => $role->label(),
        ]));
    }

    /**
     * Tangguhkan akun. Sesi aktifnya sekalian diputus supaya penangguhan
     * berlaku seketika, bukan baru saat user logout sendiri.
     */
    public function suspend(SuspendUserRequest $request, User $user): RedirectResponse
    {
        $this->authorize('suspend', $user);

        $user->forceFill([
            'suspended_at' => now(),
            'suspended_reason' => $request->reason(),
        ])->save();

        $this->sessions->terminate($user);

        return $this->success(__('Akun :name ditangguhkan.', ['name' => $user->name]));
    }

    public function unsuspend(Request $request, User $user): RedirectResponse
    {
        $this->authorize('unsuspend', $user);

        $user->forceFill([
            'suspended_at' => null,
            'suspended_reason' => null,
        ])->save();

        return $this->success(__('Akun :name diaktifkan kembali.', ['name' => $user->name]));
    }

    /**
     * Verifikasi email secara manual, untuk kasus email verifikasi tidak
     * pernah sampai (domain memblokir, salah folder, dsb).
     */
    public function verifyEmail(Request $request, User $user): RedirectResponse
    {
        $this->authorize('manageCredentials', $user);

        if ($user->hasVerifiedEmail()) {
            return $this->error(__('Email :email sudah terverifikasi.', ['email' => $user->email]));
        }

        $user->markEmailAsVerified();

        return $this->success(__('Email :email ditandai terverifikasi.', ['email' => $user->email]));
    }

    public function resendVerification(Request $request, User $user): RedirectResponse
    {
        $this->authorize('manageCredentials', $user);

        if ($user->hasVerifiedEmail()) {
            return $this->error(__('Email :email sudah terverifikasi.', ['email' => $user->email]));
        }

        $user->sendEmailVerificationNotification();

        return $this->success(__('Tautan verifikasi dikirim ke :email.', ['email' => $user->email]));
    }

    public function sendPasswordReset(Request $request, User $user): RedirectResponse
    {
        $this->authorize('manageCredentials', $user);

        $status = Password::broker()->sendResetLink(['email' => $user->email]);

        return $status === Password::ResetLinkSent
            ? $this->success(__('Tautan reset sandi dikirim ke :email.', ['email' => $user->email]))
            : $this->error(__('Tautan reset sandi gagal dikirim.'));
    }

    public function forceLogout(Request $request, User $user): RedirectResponse
    {
        $this->authorize('forceLogout', $user);

        $terminated = $this->sessions->terminate($user);

        if ($terminated === null) {
            return $this->error(__('Force logout butuh session driver "database".'));
        }

        return $this->success(trans_choice(
            '{0}:name tidak punya sesi aktif.|[1,*]:count sesi milik :name diputus.',
            $terminated,
            ['name' => $user->name, 'count' => $terminated],
        ));
    }

    /**
     * Hapus akun beserta sesinya. Ownership workspace dialihkan otomatis oleh
     * {@see User::handOverOwnedWorkspaces()}.
     */
    public function destroy(Request $request, User $user): RedirectResponse
    {
        $this->authorize('delete', $user);

        $name = $user->name;

        $this->sessions->terminate($user);
        $user->delete();

        return $this->success(__('Akun :name dihapus.', ['name' => $name]));
    }

    private function success(string $message): RedirectResponse
    {
        return back()->with('toast', ['type' => 'success', 'message' => $message]);
    }

    private function error(string $message): RedirectResponse
    {
        return back()->with('toast', ['type' => 'error', 'message' => $message]);
    }
}
