<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Gerbang panel admin (alias `admin`).
 *
 * Dipasang di seluruh grup rute `/admin` sehingga tidak ada satu pun endpoint
 * admin yang bergantung pada pengecekan manual di controller.
 */
class EnsureUserIsAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user?->isAdmin()) {
            throw new AccessDeniedHttpException('Halaman ini hanya untuk admin.');
        }

        return $next($request);
    }
}
