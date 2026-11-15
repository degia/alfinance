<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Menulis `users.last_seen_at` untuk indikator "online" di panel admin.
 *
 * Penulisan di-throttle: satu UPDATE per user per {@see self::THROTTLE_SECONDS}
 * detik, bukan satu UPDATE per request. Tanpa itu setiap navigasi (dan setiap
 * request parsial Inertia) akan menambah satu write ke tabel `users`.
 *
 * `forceFill` dipakai karena `last_seen_at` sengaja di luar daftar fillable;
 * `withoutTimestamps` + `saveQuietly` menjaga `updated_at` dan event model
 * tetap utuh — aktivitas bukan perubahan profil.
 */
class TrackUserActivity
{
    private const int THROTTLE_SECONDS = 60;

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User && $this->isStale($user)) {
            User::withoutTimestamps(
                static fn () => $user->forceFill(['last_seen_at' => now()])->saveQuietly(),
            );
        }

        return $next($request);
    }

    private function isStale(User $user): bool
    {
        return $user->last_seen_at === null
            || $user->last_seen_at->lessThan(now()->subSeconds(self::THROTTLE_SECONDS));
    }
}
