<?php

namespace App\Providers;

use App\Actions\Fortify\CreateNewUser;
use App\Actions\Fortify\ResetUserPassword;
use App\Http\Responses\GenericPasswordResetLinkRequestResponse;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Laravel\Fortify\Contracts\FailedPasswordResetLinkRequestResponse;
use Laravel\Fortify\Features;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Jangan bocorkan email mana yang punya akun lewat halaman lupa sandi.
        $this->app->singleton(
            FailedPasswordResetLinkRequestResponse::class,
            GenericPasswordResetLinkRequestResponse::class,
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureActions();
        $this->configureViews();
        $this->configureRateLimiting();
        $this->throttlePasswordResetRequests();
    }

    /**
     * Configure Fortify actions.
     */
    private function configureActions(): void
    {
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
        Fortify::createUsersUsing(CreateNewUser::class);
    }

    /**
     * Configure Fortify views.
     */
    private function configureViews(): void
    {
        Fortify::loginView(fn (Request $request) => Inertia::render('auth/Login', [
            'canResetPassword' => Features::enabled(Features::resetPasswords()),
            'status' => $request->session()->get('status'),
        ]));

        Fortify::resetPasswordView(fn (Request $request) => Inertia::render('auth/ResetPassword', [
            'email' => $request->email,
            'token' => $request->route('token'),
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
        ]));

        Fortify::requestPasswordResetLinkView(fn (Request $request) => Inertia::render('auth/ForgotPassword', [
            'status' => $request->session()->get('status'),
        ]));

        Fortify::verifyEmailView(fn (Request $request) => Inertia::render('auth/VerifyEmail', [
            'status' => $request->session()->get('status'),
        ]));

        Fortify::registerView(fn () => Inertia::render('auth/Register', [
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
        ]));

        Fortify::twoFactorChallengeView(fn () => Inertia::render('auth/TwoFactorChallenge'));

        Fortify::confirmPasswordView(fn () => Inertia::render('auth/ConfirmPassword'));
    }

    /**
     * Configure rate limiting.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('two-factor', function (Request $request) {
            return Limit::perMinute(5)->by($request->session()->get('login.id'));
        });

        RateLimiter::for('login', function (Request $request) {
            $throttleKey = Str::transliterate(Str::lower($request->input(Fortify::username())).'|'.$request->ip());

            return Limit::perMinute(5)->by($throttleKey);
        });

        RateLimiter::for('passkeys', function (Request $request) {
            return Limit::perMinute(10)->by(
                ($request->input('credential.id') ?: $request->session()->getId()).'|'.$request->ip(),
            );
        });

        RateLimiter::for('password-reset', function (Request $request) {
            $email = Str::transliterate(Str::lower((string) $request->input(Fortify::email())));

            return [
                // Satu alamat tidak bisa dibanjiri tautan atur ulang...
                Limit::perMinute(3)->by($email.'|'.$request->ip()),
                // ...dan satu IP tidak bisa menyapu banyak alamat sekaligus.
                Limit::perMinute(10)->by((string) $request->ip()),
            ];
        });
    }

    /**
     * Pasang throttle pada `POST /forgot-password`.
     *
     * Fortify membaca `fortify.limiters` hanya untuk login, two-factor,
     * passkeys, dan verifikasi — permintaan tautan atur ulang kata sandi
     * tidak punya titik konfigurasi serupa. Padahal endpoint itu terbuka
     * untuk tamu dan setiap permintaan yang berhasil mengirim satu email,
     * sehingga tanpa batas laju ia bisa dipakai membanjiri inbox orang lain.
     *
     * Middleware ditambahkan ke objek Route setelah seluruh provider boot
     * (rute Fortify baru ada saat provider paketnya boot). Laravel
     * mengumpulkan middleware saat dispatch, jadi penambahan di sini tetap
     * berlaku.
     *
     * Pencariannya menelusuri koleksi rute alih-alih memakai `getByName()`:
     * indeks nama baru disegarkan oleh RouteServiceProvider lewat callback
     * `booted`-nya sendiri, dan urutan antar-callback tidak dijamin.
     */
    private function throttlePasswordResetRequests(): void
    {
        $this->app->booted(function (): void {
            foreach (Route::getRoutes()->getRoutes() as $route) {
                if ($route->getName() === 'password.email') {
                    $route->middleware('throttle:password-reset');

                    return;
                }
            }
        });
    }
}
