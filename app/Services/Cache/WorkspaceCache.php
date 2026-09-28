<?php

namespace App\Services\Cache;

use Closure;
use Illuminate\Cache\RedisStore;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Wrapper cache per workspace (ARCHITECTURE.md §2.1, Fase 6).
 *
 * Semua pembacaan agregat (dashboard, laporan, skor kesehatan) lewat kelas ini
 * supaya konvensi kunci `ws:{workspace_id}:{context}:{period}` hanya punya satu
 * implementasi — tidak adaservice yang menyusun kunci Redis sendiri-sendiri.
 *
 * Read path = read-through: cache miss menjalankan callback sekali, hasilnya
 * disimpan dengan TTL, lalu dikembalikan. Callback boleh menyentuh tabel
 * agregat (`dashboard_snapshots`, `budget_progress_cache`) atau, untuk kasus
 * yang memang tidak punya tabel agregat, satu query agregasi — asalkan hasilnya
 * ikut di-cache sehingga query itu tidak pernah terulang pada request berikutnya
 * tanpa perubahan data.
 *
 * Invalidation = write-behind: `InvalidateWorkspaceCache` menghapus kunci saat
 * transaksi berubah, sementara perhitungan ulang diserahkan ke job antrean.
 */
class WorkspaceCache
{
    /**
     * TTL agregat (dashboard, laporan, tren). Sesuai ARCHITECTURE.md §2.2
     * ("simpan ke Redis TTL 10 menit").
     */
    public const TTL_AGGREGATE = 600;

    /**
     * TTL daftar ringan (transaksi terakhir). ARCHITECTURE.md §2.1 butir 5:
     * daftar mentah boleh langsung ke DB asal dibungkus cache 30–60 detik.
     */
    public const TTL_LIST = 60;

    /**
     * Berapa banyak kunci yang dihapus per panggilan `del`, supaya invalidasi
     * tidak mengirim argumen tak terbatas ke Redis.
     */
    private const DELETE_CHUNK = 200;

    /**
     * Kunci payload. Period opsional: `ws:7:dashboard:2026-09`.
     */
    public function key(int $workspaceId, CacheContext $context, ?string $period = null): string
    {
        return $period === null || $period === ''
            ? sprintf('ws:%d:%s', $workspaceId, $context->value)
            : sprintf('ws:%d:%s:%s', $workspaceId, $context->value, $period);
    }

    public function get(int $workspaceId, CacheContext $context, ?string $period = null): mixed
    {
        return Cache::get($this->key($workspaceId, $context, $period));
    }

    public function has(int $workspaceId, CacheContext $context, ?string $period = null): bool
    {
        return Cache::has($this->key($workspaceId, $context, $period));
    }

    /**
     * @param  int|null  $ttl  Detik; null memakai TTL agregat.
     */
    public function put(
        int $workspaceId,
        CacheContext $context,
        mixed $value,
        ?string $period = null,
        ?int $ttl = null,
    ): void {
        $key = $this->key($workspaceId, $context, $period);

        Cache::put($key, $value, $ttl ?? self::TTL_AGGREGATE);

        $this->index($workspaceId, $context, $key);
    }

    /**
     * Read-through: cache miss → callback sekali → simpan → kembalikan.
     *
     * @template TValue
     *
     * @param  Closure(): TValue  $callback
     * @return TValue
     */
    public function remember(
        int $workspaceId,
        CacheContext $context,
        ?string $period,
        Closure $callback,
        ?int $ttl = null,
    ): mixed {
        $key = $this->key($workspaceId, $context, $period);
        $hit = Cache::get($key);

        // `null` berarti belum ada; nilai agregat tidak pernah bernilai null,
        // jadi tidak ada ambiguitas cache-hit dengan cache-miss di sini.
        if ($hit !== null) {
            return $hit;
        }

        $value = $callback();

        $this->put($workspaceId, $context, $value, $period, $ttl);

        return $value;
    }

    /**
     * Hapus satu kunci persis (tanpa periode).
     */
    public function forget(int $workspaceId, CacheContext $context, ?string $period = null): void
    {
        $key = $this->key($workspaceId, $context, $period);

        Cache::forget($key);

        $this->unindex($workspaceId, $context, $key);
    }

    /**
     * Hapus semua periode milik satu konteks (mis. semua rentang laporan).
     */
    public function forgetContext(int $workspaceId, CacheContext $context): void
    {
        $indexKey = $this->indexKey($workspaceId, $context);
        $redis = $this->redis();

        if ($redis === null) {
            // Tanpa index (mis. cache `array` saat test), tidak ada cara
            // menghapus "semua periode" tanpa scan. Karena cache `array`
            // sudah terisolasi per request, ini tidak mengubah hasil apa pun.
            return;
        }

        try {
            $keys = (array) $redis->smembers($indexKey);

            foreach (array_chunk($keys, self::DELETE_CHUNK) as $chunk) {
                foreach ($chunk as $key) {
                    Cache::forget($key);
                }
            }

            $redis->del($indexKey);
        } catch (\Throwable $exception) {
            Log::warning('Gagal menginvalidasi cache workspace.', [
                'workspace_id' => $workspaceId,
                'context' => $context->value,
                'exception' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * Hapus semua konteks yang namanya diawali `$prefix`.
     *
     * Dipakai listener invalidasi: satu transaksi baru mengubah dashboard,
     * laporan, dan skor kesehatan sekaligus, dan pembagiannya mengikuti prefix
     * nama konteks — bukan daftar kasus yang harus diingat sinkron manual.
     */
    public function forgetPrefix(int $workspaceId, string $prefix): void
    {
        foreach (CacheContext::cases() as $context) {
            if ($context === CacheContext::Dashboard || str_starts_with($context->value, $prefix)) {
                $this->forgetContext($workspaceId, $context);
            }
        }
    }

    /**
     * Hapus seluruh cache turunan milik satu workspace.
     */
    public function forgetAll(int $workspaceId): void
    {
        foreach (CacheContext::cases() as $context) {
            $this->forgetContext($workspaceId, $context);
        }
    }

    /*
     |--------------------------------------------------------------------------
     | Index kunci
     |--------------------------------------------------------------------------
     |
     | Invalidation per prefix butuh tahu key mana saja yang hidup. Redis
     | `SCAN`/`KEYS` dihindari karena memblokir server dan, pada predis,
     | pattern `MATCH` tidak ikut di-prefix sehingga pemanggilannya rawan
     | salah. Sebagai gantinya setiap payload didaftarkan di sebuah Redis set
     | per konteks, persis seperti yang dilakukan tag-cache bawaan Laravel —
     | hanya dengan kunci payload tetap persis seperti konvensi di
     | ARCHITECTURE.md (`ws:{id}:{context}:{period}`).
     |
     * Set index memakai awalan `_keys` supaya tidak mungkin bertabrakan dengan
     | nilai enum konteks.
     */

    private function indexKey(int $workspaceId, CacheContext $context): string
    {
        return sprintf('ws:%d:_keys:%s', $workspaceId, $context->value);
    }

    private function index(int $workspaceId, CacheContext $context, string $key): void
    {
        $redis = $this->redis();

        if ($redis === null) {
            return;
        }

        try {
            $redis->sadd($this->indexKey($workspaceId, $context), [$key]);
        } catch (\Throwable $exception) {
            Log::warning('Gagal mendaftarkan kunci cache workspace.', [
                'workspace_id' => $workspaceId,
                'context' => $context->value,
                'exception' => $exception->getMessage(),
            ]);
        }
    }

    private function unindex(int $workspaceId, CacheContext $context, string $key): void
    {
        $redis = $this->redis();

        if ($redis === null) {
            return;
        }

        try {
            $redis->srem($this->indexKey($workspaceId, $context), $key);
        } catch (\Throwable $exception) {
            Log::warning('Gagal melepas kunci cache workspace.', [
                'workspace_id' => $workspaceId,
                'context' => $context->value,
                'exception' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * Koneksi Redis milik cache store yang aktif, atau null kalau store bukan
     * Redis (mis. `array` saat test) sehingga tidak ada index yang bisa ditulis.
     */
    private function redis(): ?Connection
    {
        $store = Cache::getStore();

        return $store instanceof RedisStore ? $store->connection() : null;
    }
}
