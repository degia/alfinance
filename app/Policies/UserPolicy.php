<?php

namespace App\Policies;

use App\Models\User;

/**
 * Otorisasi panel admin.
 *
 * Gerbang "apakah boleh masuk panel" sudah dipegang middleware `admin`, jadi
 * policy ini fokus pada satu hal yang tidak bisa dijawab middleware: aksi mana
 * yang boleh dikenakan admin pada sebuah akun tertentu.
 *
 * Aturan pokoknya: admin tidak boleh mengunci dirinya sendiri keluar —
 * menangguhkan, menurunkan role, atau menghapus akun sendiri selalu ditolak.
 */
class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function view(User $user, User $target): bool
    {
        return $user->isAdmin();
    }

    /**
     * Ubah role global akun lain.
     */
    public function updateRole(User $user, User $target): bool
    {
        return $user->isAdmin() && ! $user->is($target);
    }

    public function suspend(User $user, User $target): bool
    {
        return $user->isAdmin() && ! $user->is($target);
    }

    public function unsuspend(User $user, User $target): bool
    {
        return $user->isAdmin();
    }

    /**
     * Putuskan semua sesi aktif milik akun lain.
     */
    public function forceLogout(User $user, User $target): bool
    {
        return $user->isAdmin() && ! $user->is($target);
    }

    /**
     * Verifikasi email manual / kirim ulang tautan verifikasi / reset sandi.
     */
    public function manageCredentials(User $user, User $target): bool
    {
        return $user->isAdmin();
    }

    public function delete(User $user, User $target): bool
    {
        return $user->isAdmin() && ! $user->is($target);
    }
}
