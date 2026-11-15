<?php

namespace App\Enums;

/**
 * Role global (tingkat aplikasi), terpisah dari {@see WorkspaceRole}.
 *
 * `WorkspaceRole` menjawab "apa yang boleh dilakukan user di dalam satu buku
 * keuangan", sedangkan enum ini menjawab "siapa yang boleh mengelola aplikasi
 * dan penggunanya". Keduanya sengaja tidak saling mewarisi: Admin global tidak
 * otomatis bisa membaca data keuangan workspace milik orang lain.
 */
enum UserRole: string
{
    case User = 'user';
    case Admin = 'admin';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function label(): string
    {
        return match ($this) {
            self::User => 'Pengguna',
            self::Admin => 'Admin',
        };
    }

    /**
     * Boleh membuka panel admin (dashboard, manajemen pengguna, daftar email)?
     */
    public function canAccessAdminPanel(): bool
    {
        return $this === self::Admin;
    }
}
