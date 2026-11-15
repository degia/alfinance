<?php

namespace App\Listeners;

use App\Models\User;
use Illuminate\Auth\Events\Login;

/**
 * Mencatat jejak login terakhir untuk panel admin.
 *
 * Kolom-kolom ini sengaja di luar daftar fillable (lihat {@see User}), jadi
 * penulisannya lewat `forceFill`. `withoutTimestamps` + `saveQuietly` menjaga
 * `updated_at` dan event model tetap utuh: login bukan perubahan profil.
 */
class RecordUserLogin
{
    public function handle(Login $event): void
    {
        $user = $event->user;

        if (! $user instanceof User) {
            return;
        }

        $now = now();

        User::withoutTimestamps(static fn () => $user->forceFill([
            'last_login_at' => $now,
            'last_login_ip' => request()->ip(),
            'last_seen_at' => $now,
        ])->saveQuietly());
    }
}
