<?php

namespace Tests;

use Illuminate\Cache\RateLimiter;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Hash;
use Laravel\Fortify\Features;

abstract class TestCase extends BaseTestCase
{
    /**
     * Paksa environment test yang benar meskipun `config:cache` aktif.
     *
     * Masalah: `bootstrap/cache/config.php` (hasil `php artisan config:cache`
     * dari scripts/deploy.sh) membekukan nilai-nilai `.env` produksi, sehingga
     * override env di phpunit.xml (`DB_CONNECTION=sqlite`, `APP_ENV=testing`,
     * `CACHE_STORE=array`, dst) diabaikan saat aplikasi test di-boot. Tanpa
     * override di bawah ini:
     *
     *   1. `RefreshDatabase` menjalankan `migrate:fresh` ke database MySQL
     *      produksi dan menghapus seluruh data;
     *   2. koneksi migration Telescope (dari `telescope.storage.database.*`)
     *      juga ikut menunjuk ke MySQL;
     *   3. `app.env` membeku di `local`, sehingga `runningUnitTests()` bernilai
     *      false → middleware CSRF aktif → seluruh POST test dapat 419;
     *   4. cache/session/queue ikut memakai driver produksi (redis/database).
     *
     * Override di sini berjalan setelah aplikasi dibuat (parent) tetapi SEBELUM
     * `setUpTraits()` memanggil `refreshDatabase()`, jadi aman untuk
     * RefreshDatabase, DatabaseTruncation, maupun DatabaseMigrations.
     */
    protected function refreshApplication(): void
    {
        parent::refreshApplication();

        // Environment harus 'testing' agar `runningUnitTests()` benar dan CSRF
        // dibypass. `detectEnvironment()` menulis ulang binding 'env' di container.
        $this->app->detectEnvironment(fn () => 'testing');
        $this->app['config']->set('app.env', 'testing');

        // Database test: wajib SQLite :memory:, jangan pernah menyentuh MySQL.
        $this->app['config']->set('database.default', 'sqlite');
        $this->app['config']->set('database.connections.sqlite.database', ':memory:');

        // Telescope: koneksi migration & penyimpanannya ikut diarahkan ke sqlite,
        // dan dinonaktifkan seperti yang dimaksudkan phpunit.xml.
        $this->app['config']->set('telescope.storage.database.connection', 'sqlite');
        $this->app['config']->set('telescope.enabled', false);

        // Driver lain yang ingin dipaksa tetap berjalan di memori / in-memory
        // sesuai phpunit.xml, karena nilai produksi ikut membeku di config cache.
        $this->app['config']->set('cache.default', 'array');
        $this->app['config']->set('session.driver', 'array');
        $this->app['config']->set('queue.default', 'sync');
        $this->app['config']->set('mail.default', 'array');
        $this->app['config']->set('broadcasting.default', 'null');

        // Hashing: phpunit.xml meminta BCRYPT_ROUNDS=4, tetapi config:cache
        // membekukannya ke nilai .env (12). Jika bootstrap/cache/config.php
        // sempat terhapus (scripts/deploy.sh menjalankan `optimize:clear` saat
        // suite berjalan), cost hashing berubah di tengah proses. Karena
        // `UserFactory::$password` di-memoize sekali per proses, hash lama
        // gagal `Hash::verifyConfiguration`. Samakan cost ke nilai test dan
        // buang driver hash yang mungkin sudah terlanjur dibekukan saat boot.
        $this->app['config']->set('hashing.bcrypt.rounds', (int) env('BCRYPT_ROUNDS', 4));
        Hash::forgetDrivers();

        /*
         * RateLimiter dilarutkan saat boot aplikasi (AppServiceProvider dan
         * FortifyServiceProvider memanggil RateLimiter::for() di boot), yaitu
         * SEBELUM override di atas berlaku. Dengan config:cache aktif,
         * config('cache.default') masih 'redis' pada saat itu, sehingga
         * singleton RateLimiter mengunci store redis — bukan array — untuk
         * semua throttle middleware (mis. `throttle:6,1` di settings/password
         * dan `throttle:login`). Akibatnya hit counter throttle tersimpan di
         * Redis dan bocor antar-class/antar-run test (semua test memakai user
         * id yang sama setelah rollback), memicu 429 flaky.
         *
         * Jangan forgetInstance(RateLimiter::class): instance yang sama juga
         * menyimpan named limiter (login, two-factor, passkeys, exports, ...)
         * yang didaftarkan provider saat boot. Cukup ganti store cache-nya
         * ke repositori array agar hit counter tidak persist.
         */
        $cacheStore = new \ReflectionProperty(RateLimiter::class, 'cache');
        $cacheStore->setAccessible(true);
        $cacheStore->setValue(
            $this->app->make(RateLimiter::class),
            $this->app['cache']->store('array'),
        );
        $this->app->forgetInstance('cache.store');
    }

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }
}
