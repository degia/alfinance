<?php

namespace App\Http\Middleware;

use App\Support\Workspace\ActiveWorkspace;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Menjamin setiap halaman aplikasi berjalan di dalam sebuah workspace.
 *
 * Semua route yang menyentuh data domain wajib memakai middleware ini
 * (`workspace.selected`) setelah `SetActiveWorkspaceScope`.
 */
class EnsureWorkspaceSelected
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()) {
            return redirect()->guest(route('login'));
        }

        if (! ActiveWorkspace::has()) {
            return redirect()
                ->route('workspaces.index')
                ->with('toast', [
                    'type' => 'info',
                    'message' => __('Pilih workspace untuk melanjutkan.'),
                ]);
        }

        return $next($request);
    }
}
