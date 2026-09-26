<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\Settings\SidebarPreferenceController;
use App\Http\Controllers\TagController;
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

    /*
    |----------------------------------------------------------------------
    | Master data (Fase 2)
    |----------------------------------------------------------------------
    | Semua data di-scope ke workspace aktif, jadi route wajib memakai
    | middleware `workspace.selected` — tanpa itu tidak ada konteks tenant.
    */
    Route::get('accounts', [AccountController::class, 'index'])->name('accounts.index');
    Route::post('accounts', [AccountController::class, 'store'])->name('accounts.store');
    Route::put('accounts/{account}', [AccountController::class, 'update'])->name('accounts.update');
    Route::post('accounts/{account}/archive', [AccountController::class, 'archive'])->name('accounts.archive');
    Route::post('accounts/{account}/restore', [AccountController::class, 'restore'])->name('accounts.restore');

    Route::get('categories', [CategoryController::class, 'index'])->name('categories.index');
    Route::post('categories', [CategoryController::class, 'store'])->name('categories.store');
    Route::put('categories/{category}', [CategoryController::class, 'update'])->name('categories.update');
    Route::delete('categories/{category}', [CategoryController::class, 'destroy'])->name('categories.destroy');

    Route::get('tags', [TagController::class, 'index'])->name('tags.index');
    Route::post('tags', [TagController::class, 'store'])->name('tags.store');
    Route::put('tags/{tag}', [TagController::class, 'update'])->name('tags.update');
    Route::delete('tags/{tag}', [TagController::class, 'destroy'])->name('tags.destroy');
});

require __DIR__.'/settings.php';
