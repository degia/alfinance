<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\Workspace\ActiveWorkspace;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolusi workspace aktif untuk satu request.
 *
 * Sumber urutan prioritas:
 * 1. `workspace_id` di session (hasil switch workspace).
 * 2. Auto-select bila user hanya punya satu workspace.
 * 3. Kosong → middleware {@see EnsureWorkspaceSelected} mengarahkan user ke
 *    halaman pilih workspace.
 *
 * Query membership yang dipakai di sini dijaga seminimal mungkin (maksimal satu
 * query per request); cache membership per user masuk scope Fase 6.
 */
class SetActiveWorkspaceScope
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            ActiveWorkspace::forget();

            return $next($request);
        }

        try {
            $this->resolve($request, $user);

            return $next($request);
        } finally {
            // Hindari kebocoran state static antar request pada proses
            // jangka panjang (queue worker, test suite, Octane).
            ActiveWorkspace::forget();
        }
    }

    private function resolve(Request $request, User $user): void
    {
        $workspaceId = $request->session()->get('workspace_id');

        if ($workspaceId !== null) {
            $workspace = $user->workspaces()
                ->where('workspaces.id', $workspaceId)
                ->first();

            if ($workspace) {
                ActiveWorkspace::set($workspace);

                return;
            }

            // Workspace sudah tidak lagi dimiliki user ini.
            $request->session()->forget('workspace_id');
        }

        // Auto-select hanya bila user benar-benar punya satu workspace.
        $candidates = $user->workspaces()
            ->orderBy('workspaces.id')
            ->limit(2)
            ->get();

        if ($candidates->count() === 1) {
            $workspace = $candidates->first();

            ActiveWorkspace::set($workspace);
            $request->session()->put('workspace_id', $workspace->id);

            return;
        }

        ActiveWorkspace::set(null);
    }
}
