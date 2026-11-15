<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Jalur pemulihan untuk role admin.
 *
 * Panel admin hanya bisa dibuka oleh admin, jadi harus selalu ada cara
 * menunjuk admin pertama (atau mengembalikan akses setelah salah demote)
 * tanpa melalui antarmuka web.
 */
class MakeUserAdminCommand extends Command
{
    protected $signature = 'user:make-admin
                            {email : Email akun yang akan diubah}
                            {--revoke : Turunkan kembali menjadi pengguna biasa}';

    protected $description = 'Jadikan sebuah akun sebagai Admin global (atau cabut dengan --revoke)';

    public function handle(): int
    {
        $email = (string) $this->argument('email');
        $user = User::query()->where('email', $email)->first();

        if (! $user instanceof User) {
            $this->components->error("Tidak ada akun dengan email {$email}.");

            return self::FAILURE;
        }

        $role = $this->option('revoke') ? UserRole::User : UserRole::Admin;

        if ($user->role === $role) {
            $this->components->info("{$user->name} sudah berperan sebagai {$role->label()}.");

            return self::SUCCESS;
        }

        if ($role === UserRole::User && User::query()->admins()->count() <= 1) {
            $this->components->error('Minimal harus ada satu admin. Tunjuk admin lain dulu.');

            return self::FAILURE;
        }

        $user->role = $role;
        $user->save();

        $this->components->info("{$user->name} <{$user->email}> sekarang berperan sebagai {$role->label()}.");

        return self::SUCCESS;
    }
}
