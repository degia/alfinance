<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\RecurringRuleController;
use App\Http\Controllers\Settings\SidebarPreferenceController;
use App\Http\Controllers\TagController;
use App\Http\Controllers\TransactionController;
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

    /*
    |----------------------------------------------------------------------
    | Transaksi (Fase 3)
    |----------------------------------------------------------------------
    | `store`/`update`/`destroy` menyentuh `accounts.cached_balance`, jadi
    | keduanya hanya lewat TransactionManager (DB transaction) dan butuh
    | ability `editData` — viewer melihat daftar, tapi tidak bisa menulis.
    */
    Route::get('transactions', [TransactionController::class, 'index'])->name('transactions.index');
    Route::get('transactions/create', [TransactionController::class, 'create'])->name('transactions.create');
    Route::post('transactions', [TransactionController::class, 'store'])->name('transactions.store');
    Route::get('transactions/{transaction}/edit', [TransactionController::class, 'edit'])->name('transactions.edit');
    Route::put('transactions/{transaction}', [TransactionController::class, 'update'])->name('transactions.update');
    Route::delete('transactions/{transaction}', [TransactionController::class, 'destroy'])->name('transactions.destroy');

    // Instance transaksi berulang yang menunggu konfirmasi.
    Route::post('transactions/{transaction}/confirm', [TransactionController::class, 'confirm'])->name('transactions.confirm');
    Route::post('transactions/{transaction}/discard', [TransactionController::class, 'discard'])->name('transactions.discard');

    // Lampiran bukti: unduh lewat controller supaya tenant scope ikut berlaku.
    Route::get('attachments/{attachment}', [TransactionController::class, 'download'])->name('transactions.attachments.download');

    /*
    |----------------------------------------------------------------------
    | Transaksi berulang (Fase 3)
    |----------------------------------------------------------------------
    | Rule tidak pernah dihapus: nonaktif berarti `is_active = false` supaya
    | histori instance tetap punya induk yang jelas.
    */
    Route::get('recurring-rules', [RecurringRuleController::class, 'index'])->name('recurring-rules.index');
    Route::post('recurring-rules', [RecurringRuleController::class, 'store'])->name('recurring-rules.store');
    Route::put('recurring-rules/{recurring_rule}', [RecurringRuleController::class, 'update'])->name('recurring-rules.update');
    Route::post('recurring-rules/{recurring_rule}/toggle', [RecurringRuleController::class, 'toggle'])->name('recurring-rules.toggle');
});

require __DIR__.'/settings.php';
