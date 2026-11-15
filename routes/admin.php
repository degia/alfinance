<?php

use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\EmailController;
use App\Http\Controllers\Admin\UserController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Panel admin
|--------------------------------------------------------------------------
| Semua rute di sini dijaga middleware `admin` (role global `admin`).
|
| Grup ini sengaja BERADA DI LUAR `workspace.selected`: panel admin mengurus
| akun dan aplikasi, bukan isi satu buku keuangan, jadi admin harus tetap
| bisa membukanya meski belum/tidak punya workspace aktif.
*/
Route::middleware(['auth', 'verified', 'admin'])
    ->prefix('admin')
    ->name('admin.')
    ->group(function () {
        Route::get('/', DashboardController::class)->name('dashboard');

        Route::get('users', [UserController::class, 'index'])->name('users.index');
        Route::put('users/{user}/role', [UserController::class, 'updateRole'])->name('users.role');
        Route::post('users/{user}/suspend', [UserController::class, 'suspend'])->name('users.suspend');
        Route::post('users/{user}/unsuspend', [UserController::class, 'unsuspend'])->name('users.unsuspend');
        Route::post('users/{user}/verify-email', [UserController::class, 'verifyEmail'])->name('users.verifyEmail');
        Route::post('users/{user}/resend-verification', [UserController::class, 'resendVerification'])->name('users.resendVerification');
        Route::post('users/{user}/password-reset', [UserController::class, 'sendPasswordReset'])->name('users.passwordReset');
        Route::post('users/{user}/force-logout', [UserController::class, 'forceLogout'])->name('users.forceLogout');
        Route::delete('users/{user}', [UserController::class, 'destroy'])->name('users.destroy');

        Route::get('emails', [EmailController::class, 'index'])->name('emails.index');
        Route::get('emails/export', [EmailController::class, 'export'])->name('emails.export');
    });
