<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Menyiapkan satu Admin global.
 *
 * Idempoten: bila akunnya sudah ada, seeder hanya menaikkan rolenya dan tidak
 * menyentuh nama maupun kata sandi yang sudah dipakai. Akun baru dibuat dengan
 * sandi acak — sandi awal tidak pernah ditaruh di repositori; pemiliknya
 * memakai alur "lupa kata sandi" untuk menetapkannya sendiri.
 */
class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $email = (string) config('auth.admin_email');

        $user = User::query()->where('email', $email)->first();

        if (! $user instanceof User) {
            $user = new User;
            $user->name = 'Admin';
            $user->email = $email;
            $user->password = Hash::make(Str::password(24));
        }

        $user->role = UserRole::Admin;
        $user->save();

        if (! $user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();
        }

        $this->command->info("Admin global: {$user->email}");
    }
}
