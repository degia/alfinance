<?php

use App\Http\Controllers\Settings\SidebarPreferenceController;
use App\Http\Controllers\WorkspaceController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

/*
|--------------------------------------------------------------------------
| Workspace (multi-tenant)
|--------------------------------------------------------------------------
| Rute ini berada DI LUAR middleware `workspace.selected` supaya user tetap
| bisa memilih / membuat workspace ketika belum ada workspace aktif.
*/
Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('workspaces', [WorkspaceController::class, 'index'])->name('workspaces.index');
    Route::post('workspaces', [WorkspaceController::class, 'store'])->name('workspaces.store');
    Route::post('workspaces/{workspace}/switch', [WorkspaceController::class, 'switch'])->name('workspaces.switch');
    Route::delete('workspaces/{workspace}', [WorkspaceController::class, 'leave'])->name('workspaces.leave');

    // Preferensi UI ringan (tidak melalui Inertia, dipanggil dengan debounce).
    Route::post('settings/sidebar', SidebarPreferenceController::class)->name('settings.sidebar');
});

Route::middleware(['auth', 'verified', 'workspace.selected'])->group(function () {
    Route::inertia('dashboard', 'Dashboard')->name('dashboard');
});

require __DIR__.'/settings.php';
