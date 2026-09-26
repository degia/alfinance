<?php

namespace App\Models;

use App\Enums\WorkspaceRole;
use Carbon\Carbon;
use Database\Factories\WorkspaceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Satu workspace = satu "buku keuangan" (ARCHITECTURE.md §3).
 *
 * @property int $id
 * @property string $name
 * @property int $owner_id
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read WorkspaceUser|null $pivot Pivot aktif saat dimuat lewat relasi `users()`
 */
#[Fillable(['name', 'owner_id'])]
class Workspace extends Model
{
    /**
     * Nama tabel pivot sesuai ARCHITECTURE.md §4.
     * Wajib eksplisit karena default Laravel adalah `user_workspace`.
     */
    public const string PIVOT_TABLE = 'workspace_user';

    /** @use HasFactory<WorkspaceFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'owner_id' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * @return BelongsToMany<User, $this, WorkspaceUser>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, self::PIVOT_TABLE, 'workspace_id', 'user_id')
            ->using(WorkspaceUser::class)
            ->withPivot('role')
            ->withTimestamps();
    }

    /**
     * @return BelongsToMany<User, $this, WorkspaceUser>
     */
    public function members(): BelongsToMany
    {
        return $this->users()->orderBy('name');
    }

    public function addUser(User $user, WorkspaceRole $role = WorkspaceRole::Member): void
    {
        $this->users()->syncWithoutDetaching([
            $user->id => ['role' => $role->value],
        ]);
    }

    public function removeUser(User $user): void
    {
        $this->users()->detach($user->id);
    }

    public function isMember(User|int $user): bool
    {
        return $this->users()
            ->where('users.id', $user instanceof User ? $user->id : $user)
            ->exists();
    }

    /**
     * Role user pada workspace ini, null bila bukan anggota.
     */
    public function roleFor(User|int $user): ?WorkspaceRole
    {
        $userId = $user instanceof User ? $user->id : $user;

        $pivot = $this->users()
            ->where('users.id', $userId)
            ->first()?->pivot;

        return $pivot instanceof WorkspaceUser ? $pivot->role : null;
    }
}
