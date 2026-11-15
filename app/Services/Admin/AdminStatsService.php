<?php

namespace App\Services\Admin;

use App\Enums\UserRole;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Angka-angka untuk dashboard admin.
 *
 * Semua ringkasan user diambil lewat SATU query agregat ber-`CASE WHEN`, bukan
 * sederet `count()` terpisah — tabel `users` hanya perlu dipindai sekali, dan
 * angkanya dijamin konsisten satu sama lain (tidak ada baris yang terhitung di
 * satu metrik tapi tidak di metrik lain karena ada registrasi di sela query).
 */
class AdminStatsService
{
    /**
     * Panjang grafik registrasi di dashboard.
     */
    private const int CHART_DAYS = 30;

    /**
     * @return array{
     *     users: array<string, int>,
     *     workspaces: array<string, int>,
     *     transactions: array<string, int>,
     * }
     */
    public function summary(): array
    {
        return [
            'users' => $this->userSummary(),
            'workspaces' => $this->workspaceSummary(),
            'transactions' => $this->transactionSummary(),
        ];
    }

    /**
     * Memakai query builder (bukan Eloquent) karena hasilnya satu baris
     * agregat, bukan sebuah User — menghidrasinya jadi model hanya akan
     * menyamarkan bahwa kolom-kolom ini tidak ada di tabel.
     *
     * @return array<string, int>
     */
    private function userSummary(): array
    {
        $now = now();
        $online = $now->copy()->subMinutes(User::ONLINE_WINDOW_MINUTES);
        $today = $now->copy()->startOfDay();

        $row = DB::table('users')
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('SUM(CASE WHEN role = ? THEN 1 ELSE 0 END) as admins', [UserRole::Admin->value])
            ->selectRaw('SUM(CASE WHEN email_verified_at IS NOT NULL THEN 1 ELSE 0 END) as verified')
            ->selectRaw('SUM(CASE WHEN suspended_at IS NOT NULL THEN 1 ELSE 0 END) as suspended')
            ->selectRaw('SUM(CASE WHEN two_factor_confirmed_at IS NOT NULL THEN 1 ELSE 0 END) as two_factor')
            ->selectRaw('SUM(CASE WHEN last_seen_at >= ? THEN 1 ELSE 0 END) as online', [$online])
            ->selectRaw('SUM(CASE WHEN last_seen_at >= ? THEN 1 ELSE 0 END) as active_today', [$today])
            ->selectRaw('SUM(CASE WHEN last_login_at IS NULL THEN 1 ELSE 0 END) as never_logged_in')
            ->selectRaw('SUM(CASE WHEN created_at >= ? THEN 1 ELSE 0 END) as new_today', [$today])
            ->selectRaw('SUM(CASE WHEN created_at >= ? THEN 1 ELSE 0 END) as new_this_week', [$now->copy()->subDays(7)])
            ->selectRaw('SUM(CASE WHEN created_at >= ? THEN 1 ELSE 0 END) as new_this_month', [$now->copy()->subDays(30)])
            ->first();

        $total = (int) $row->total;
        $verified = (int) $row->verified;

        return [
            'total' => $total,
            'admins' => (int) $row->admins,
            'verified' => $verified,
            'unverified' => $total - $verified,
            'suspended' => (int) $row->suspended,
            'two_factor' => (int) $row->two_factor,
            'online' => (int) $row->online,
            'active_today' => (int) $row->active_today,
            'never_logged_in' => (int) $row->never_logged_in,
            'new_today' => (int) $row->new_today,
            'new_this_week' => (int) $row->new_this_week,
            'new_this_month' => (int) $row->new_this_month,
        ];
    }

    /**
     * @return array<string, int>
     */
    private function workspaceSummary(): array
    {
        // `withTrashed()` tidak relevan di query builder: tanpa Eloquent tidak
        // ada scope soft delete yang menyaring, jadi semua baris ikut terhitung.
        $row = DB::table('workspaces')
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('SUM(CASE WHEN deleted_at IS NOT NULL THEN 1 ELSE 0 END) as trashed')
            ->first();

        $total = (int) $row->total;
        $trashed = (int) $row->trashed;

        return [
            'total' => $total - $trashed,
            'trashed' => $trashed,
        ];
    }

    /**
     * @return array<string, int>
     */
    private function transactionSummary(): array
    {
        return [
            'total' => Transaction::allWorkspaces()->count(),
        ];
    }

    /**
     * Registrasi harian untuk grafik batang.
     *
     * Hari tanpa registrasi tidak muncul di hasil GROUP BY, jadi deret tanggal
     * dibangun lebih dulu lalu diisi — grafik tidak boleh "melompati" hari
     * kosong karena itu memampatkan sumbu waktu dan menyesatkan.
     *
     * @return array<int, array{date: string, label: string, count: int}>
     */
    public function registrationTrend(): array
    {
        $start = now()->startOfDay()->subDays(self::CHART_DAYS - 1);

        /** @var Collection<string, int> $counts */
        $counts = User::query()
            ->where('created_at', '>=', $start)
            ->selectRaw('DATE(created_at) as day, COUNT(*) as total')
            ->groupBy('day')
            ->pluck('total', 'day')
            ->mapWithKeys(fn (int|string $total, string $day): array => [
                Carbon::parse($day)->toDateString() => (int) $total,
            ]);

        return collect(range(0, self::CHART_DAYS - 1))
            ->map(function (int $offset) use ($start, $counts): array {
                $date = $start->copy()->addDays($offset);
                $key = $date->toDateString();

                return [
                    'date' => $key,
                    'label' => $date->translatedFormat('d M'),
                    'count' => $counts->get($key, 0),
                ];
            })
            ->all();
    }

    /**
     * Siapa saja yang sedang / baru saja aktif.
     *
     * @return array<int, array<string, mixed>>
     */
    public function recentlyActive(int $limit = 8): array
    {
        return User::query()
            ->whereNotNull('last_seen_at')
            ->orderByDesc('last_seen_at')
            ->limit($limit)
            ->get()
            ->map(fn (User $user): array => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'is_online' => $user->isOnline(),
                'last_seen_at_diff' => $user->last_seen_at?->diffForHumans(),
                'last_login_at_diff' => $user->last_login_at?->diffForHumans(),
                'last_login_ip' => $user->last_login_ip,
            ])
            ->all();
    }

    /**
     * Pendaftar terbaru, untuk memantau masuknya akun baru.
     *
     * @return array<int, array<string, mixed>>
     */
    public function recentRegistrations(int $limit = 8): array
    {
        return User::query()
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get()
            ->map(fn (User $user): array => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'is_verified' => $user->email_verified_at !== null,
                'created_at_diff' => $user->created_at?->diffForHumans(),
            ])
            ->all();
    }

    /**
     * Status infrastruktur yang paling sering jadi penyebab insiden.
     *
     * Tiap pemeriksaan dibungkus try/catch agar dashboard tetap bisa dibuka
     * justru pada saat salah satu layanan sedang mati — kondisi ketika halaman
     * ini paling dibutuhkan.
     *
     * @return array<int, array{name: string, status: string, detail: string}>
     */
    public function systemStatus(): array
    {
        return [
            $this->check('Database', fn (): string => DB::connection()->getDatabaseName()),
            $this->check('Cache', function (): string {
                $store = config('cache.default');
                cache()->store($store)->get('admin:health');

                return (string) $store;
            }),
            $this->check('Antrean', fn (): string => (string) config('queue.default')),
            $this->check('Penyimpanan', function (): string {
                $free = disk_free_space(storage_path());

                return $free === false
                    ? 'tersedia'
                    : $this->humanBytes((int) $free).' bebas';
            }),
        ];
    }

    /**
     * @param  callable(): string  $probe
     * @return array{name: string, status: string, detail: string}
     */
    private function check(string $name, callable $probe): array
    {
        try {
            return ['name' => $name, 'status' => 'ok', 'detail' => $probe()];
        } catch (\Throwable $exception) {
            return ['name' => $name, 'status' => 'down', 'detail' => $exception->getMessage()];
        }
    }

    private function humanBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $index = 0;

        while ($bytes >= 1024 && $index < count($units) - 1) {
            $bytes /= 1024;
            $index++;
        }

        return round($bytes, 1).' '.$units[$index];
    }
}
