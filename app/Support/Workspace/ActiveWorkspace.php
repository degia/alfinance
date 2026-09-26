<?php

namespace App\Support\Workspace;

use App\Http\Middleware\SetActiveWorkspaceScope;
use App\Models\Scopes\WorkspaceScope;
use App\Models\Workspace;
use RuntimeException;

/**
 * Menyimpan workspace yang aktif untuk satu request (atau satu job).
 *
 * Diisi oleh middleware {@see SetActiveWorkspaceScope} dan
 * dibaca oleh {@see WorkspaceScope}. Jangan pernah menyimpan
 * state ini di container singleton yang bertahan antar-request.
 *
 * Bila tidak ada workspace aktif, global scope mengembalikan nol baris
 * (fail-closed). Job wajib memanggil {@see set()} untuk workspace-nya, atau
 * {@see withoutScope()} bila memang bekerja lintas workspace.
 */
final class ActiveWorkspace
{
    protected static ?Workspace $workspace = null;

    /**
     * Nonaktifkan enforce global scope selama callback berjalan.
     *
     * Dipakai job/command (queue, scheduler, export, backup) yang memang
     * bekerja lintas workspace dan sudah melakukan filter manual.
     */
    protected static bool $unscoped = false;

    public static function set(?Workspace $workspace): void
    {
        self::$workspace = $workspace;
    }

    public static function forget(): void
    {
        self::$workspace = null;
        self::$unscoped = false;
    }

    public static function workspace(): ?Workspace
    {
        return self::$workspace;
    }

    public static function id(): ?int
    {
        return self::$workspace?->id;
    }

    public static function has(): bool
    {
        return self::$workspace !== null;
    }

    public static function idOrFail(): int
    {
        return self::$workspace->id
            ?? throw new RuntimeException('Tidak ada workspace aktif pada konteks ini.');
    }

    public static function isUnscoped(): bool
    {
        return self::$unscoped;
    }

    /**
     * Jalankan callback tanpa global scope workspace.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public static function withoutScope(callable $callback): mixed
    {
        $previous = self::$unscoped;
        self::$unscoped = true;

        try {
            return $callback();
        } finally {
            self::$unscoped = $previous;
        }
    }
}
