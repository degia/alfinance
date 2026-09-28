<?php

namespace App\Providers;

use App\Models\Workspace;
use App\Policies\WorkspacePolicy;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configurePolicies();
        $this->configureRateLimiters();
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }

    /**
     * Register policies explicitly so tenant checks never depend on discovery.
     */
    protected function configurePolicies(): void
    {
        Gate::policy(Workspace::class, WorkspacePolicy::class);
    }

    /**
     * Rate limit untuk modul Ekspor & Backup (ARCHITECTURE.md §2.1 butir 4):
     * membatasi pembuatan ekspor/backup dan terutama restore, yang menulis
     * ulang data workspace.
     */
    protected function configureRateLimiters(): void
    {
        RateLimiter::for('exports', fn (): Limit => $this->authLimit(20));
        RateLimiter::for('backups', fn (): Limit => $this->authLimit(10));
        RateLimiter::for('backups.restore', fn (): Limit => $this->authLimit(5));
    }

    /**
     * Limit per pengguna terautentikasi; pengunjung dinonaktifkan (throttling
     * tidak bermakna untuk request yang tidak perlu autentikasi).
     */
    private function authLimit(int $perMinute): Limit
    {
        return $this->app->make('request')->user()
            ? Limit::perMinute($perMinute)->by((string) $this->app->make('request')->user()->id)
            : Limit::none();
    }
}
