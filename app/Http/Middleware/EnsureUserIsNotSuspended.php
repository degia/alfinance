<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Akun yang ditangguhkan admin langsung dikeluarkan dari sesinya.
 *
 * Pengecekan ditaruh di middleware global (bukan di pipeline login Fortify)
 * supaya penangguhan juga berlaku untuk sesi yang sudah terlanjur aktif —
 * termasuk sesi "remember me" dan login lewat passkey.
 */
class EnsureUserIsNotSuspended
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user?->isSuspended()) {
            return $next($request);
        }

        $reason = $user->suspended_reason;

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->withErrors([
            'email' => $reason
                ? __('Akun kamu ditangguhkan: :reason', ['reason' => $reason])
                : __('Akun kamu ditangguhkan. Hubungi admin untuk mengaktifkannya kembali.'),
        ]);
    }
}
