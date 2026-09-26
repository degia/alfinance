<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\UpdateSidebarPreferenceRequest;
use Illuminate\Http\JsonResponse;

/**
 * Endpoint ringan untuk menyinkronkan preferensi collapse sidebar.
 *
 * Sengaja TIDAK memakai Inertia response: dipanggil dari composable
 * `useSidebarPreference` dengan debounce agar tidak memicu render ulang halaman.
 */
class SidebarPreferenceController extends Controller
{
    public function __invoke(UpdateSidebarPreferenceRequest $request): JsonResponse
    {
        $collapsed = $request->boolean('collapsed');

        $request->user()->forceFill(['sidebar_collapsed' => $collapsed])->save();

        return response()->json(['collapsed' => $collapsed]);
    }
}
