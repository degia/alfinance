<?php

namespace App\Models;

use App\Enums\WorkspaceRole;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property bool $sidebar_collapsed
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read WorkspaceUser|null $pivot Pivot aktif saat dimuat lewat relasi `workspaces()`
 */
#[Fillable(['name', 'email', 'password', 'sidebar_collapsed'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmail, PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    protected static function booted(): void
    {
        // `workspaces.owner_id` memakai FK restrict, jadi ownership harus
        // dialihkan sebelum akun dihapus agar data workspace tidak hilang.
        static::deleting(function (self $user): void {
            $user->handOverOwnedWorkspaces();
        });
    }

    /**
     * Alihkan ownership workspace milik user ini sebelum akun dihapus.
     *
     * Workspace dengan anggota lain diwariskan ke admin (atau anggota
     * terlama bila tidak ada admin). Workspace tanpa anggota lain dihapus
     * karena tidak ada pihak lain yang berhak atas datanya.
     */
    public function handOverOwnedWorkspaces(): void
    {
        Workspace::query()
            ->where('owner_id', $this->getKey())
            ->get()
            ->each(function (Workspace $workspace): void {
                $successor = $workspace->users()
                    ->where('users.id', '!=', $this->getKey())
                    ->get()
                    ->sortBy(fn (User $member): array => [
                        // Admin lebih diprioritaskan daripada member/viewer.
                        $member->pivot?->role === WorkspaceRole::Admin ? 0 : 1,
                        $member->getKey(),
                    ])
                    ->first();

                if (! $successor instanceof User) {
                    $workspace->delete();

                    return;
                }

                $workspace->update(['owner_id' => $successor->getKey()]);
                $workspace->users()->updateExistingPivot($successor->getKey(), [
                    'role' => WorkspaceRole::Owner->value,
                ]);
            });
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'sidebar_collapsed' => 'boolean',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }

    /**
     * Semua workspace yang dielek user ini.
     *
     * @return BelongsToMany<Workspace, $this, WorkspaceUser>
     */
    public function workspaces(): BelongsToMany
    {
        return $this->belongsToMany(Workspace::class, Workspace::PIVOT_TABLE, 'user_id', 'workspace_id')
            ->using(WorkspaceUser::class)
            ->withPivot('role')
            ->withTimestamps();
    }

    public function belongsToWorkspace(Workspace|int $workspace): bool
    {
        return $this->workspaces()
            ->where('workspaces.id', $workspace instanceof Workspace ? $workspace->id : $workspace)
            ->exists();
    }

    /**
     * Role user pada workspace tertentu, null bila bukan anggota.
     */
    public function roleIn(Workspace|int $workspace): ?WorkspaceRole
    {
        if ($workspace instanceof Workspace) {
            return $workspace->roleFor($this);
        }

        $pivot = $this->workspaces()
            ->where('workspaces.id', $workspace)
            ->first()?->pivot;

        return $pivot instanceof WorkspaceUser ? $pivot->role : null;
    }

    public function hasWorkspaceRole(Workspace|int $workspace, WorkspaceRole|int ...$roles): bool
    {
        $role = $this->roleIn($workspace);

        if ($role === null) {
            return false;
        }

        $wanted = array_map(
            fn (WorkspaceRole|int $item): WorkspaceRole => $item instanceof WorkspaceRole
                ? $item
                : WorkspaceRole::from($item),
            $roles,
        );

        return ! empty($wanted) && in_array($role, $wanted, true);
    }
}
