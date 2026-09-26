<?php

namespace App\Enums;

/**
 * Role user di dalam sebuah workspace.
 *
 * Owner > Admin > Member > Viewer. Otorisasi selalu dievaluasi terhadap role
 * milik user pada workspace aktif, bukan role global.
 */
enum WorkspaceRole: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case Member = 'member';
    case Viewer = 'viewer';

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
            self::Owner => 'Owner',
            self::Admin => 'Admin',
            self::Member => 'Member',
            self::Viewer => 'Viewer',
        };
    }

    /**
     * Bobot role, dipakai untuk perbandingan hak akses.
     */
    public function rank(): int
    {
        return match ($this) {
            self::Owner => 4,
            self::Admin => 3,
            self::Member => 2,
            self::Viewer => 1,
        };
    }

    /**
     * Boleh menambah/menghapus anggota workspace?
     */
    public function canManageMembers(): bool
    {
        return $this === self::Owner || $this === self::Admin;
    }

    /**
     * Boleh mengubah data keuangan (akun, transaksi, budget, dsb)?
     */
    public function canEditData(): bool
    {
        return $this->rank() >= self::Member->rank();
    }

    /**
     * Boleh mengubah metadata workspace (nama, dll)?
     */
    public function canUpdateWorkspace(): bool
    {
        return $this === self::Owner || $this === self::Admin;
    }

    /**
     * Boleh menghapus workspace secara permanen?
     */
    public function canDeleteWorkspace(): bool
    {
        return $this === self::Owner;
    }
}
