<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Daftar alamat email pengguna, untuk broadcast/pengumuman.
 *
 * Halaman ini hanya menampilkan email (bukan seluruh profil) supaya ada satu
 * tempat yang jelas untuk "ambil daftar penerima" tanpa perlu menyalin dari
 * tabel manajemen pengguna satu per satu.
 */
class EmailController extends Controller
{
    private const array SEGMENTS = ['all', 'verified', 'unverified', 'active'];

    public function index(Request $request): Response
    {
        $segment = $this->segment($request);

        $emails = $this->query($segment)
            ->get(['id', 'name', 'email', 'email_verified_at', 'suspended_at'])
            ->map(fn (User $user): array => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'is_verified' => $user->email_verified_at !== null,
                'is_suspended' => $user->isSuspended(),
            ])
            ->values()
            ->all();

        return Inertia::render('admin/Emails', [
            'emails' => $emails,
            'segment' => $segment,
            'segments' => self::SEGMENTS,
        ]);
    }

    /**
     * Unduh CSV. Dikirim sebagai stream agar daftar panjang tidak perlu
     * dibangun utuh di memori sebelum respons mulai mengalir.
     */
    public function export(Request $request): StreamedResponse
    {
        $segment = $this->segment($request);
        $filename = sprintf('email-%s-%s.csv', $segment, now()->format('Ymd-His'));

        $query = $this->query($segment);

        return response()->streamDownload(function () use ($query): void {
            $handle = fopen('php://output', 'wb');

            if ($handle === false) {
                return;
            }

            // BOM UTF-8 supaya Excel membaca karakter non-ASCII dengan benar.
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, ['Nama', 'Email', 'Terverifikasi', 'Status', 'Terdaftar']);

            $query->chunkById(500, function ($users) use ($handle): void {
                foreach ($users as $user) {
                    fputcsv($handle, [
                        $user->name,
                        $user->email,
                        $user->email_verified_at !== null ? 'Ya' : 'Tidak',
                        $user->isSuspended() ? 'Ditangguhkan' : 'Aktif',
                        $user->created_at?->toDateTimeString(),
                    ]);
                }
            });

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * @return Builder<User>
     */
    private function query(string $segment): Builder
    {
        return User::query()
            ->when($segment === 'verified', fn (Builder $q) => $q->whereNotNull('email_verified_at'))
            ->when($segment === 'unverified', fn (Builder $q) => $q->whereNull('email_verified_at'))
            ->when($segment === 'active', fn (Builder $q) => $q->whereNull('suspended_at'))
            ->orderBy('name');
    }

    private function segment(Request $request): string
    {
        $validated = $request->validate([
            'segment' => ['nullable', Rule::in(self::SEGMENTS)],
        ]);

        return $validated['segment'] ?? 'all';
    }
}
