<?php

namespace App\Services\Admin;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Memutus seluruh sesi login milik satu user ("force logout").
 *
 * Hanya mungkin dilakukan bila sesi disimpan di database — driver `file`,
 * `cookie`, atau `array` tidak menyimpan relasi sesi→user yang bisa
 * ditelusuri. Pada driver itu metode ini mengembalikan `null` supaya
 * pemanggil bisa memberi tahu admin bahwa aksinya tidak berlaku, bukan
 * diam-diam melaporkan sukses palsu.
 */
class UserSessionTerminator
{
    /**
     * @return int|null Jumlah sesi yang diputus, atau null bila tidak didukung.
     */
    public function terminate(User $user): ?int
    {
        if (config('session.driver') !== 'database') {
            return null;
        }

        return DB::table(config('session.table', 'sessions'))
            ->where('user_id', $user->getKey())
            ->delete();
    }

    /**
     * Jumlah sesi aktif, untuk ditampilkan di daftar pengguna.
     */
    public function countFor(User $user): ?int
    {
        if (config('session.driver') !== 'database') {
            return null;
        }

        return DB::table(config('session.table', 'sessions'))
            ->where('user_id', $user->getKey())
            ->count();
    }
}
