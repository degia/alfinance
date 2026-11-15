<?php

use App\Enums\UserRole;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kolom pendukung panel admin.
 *
 * - `role`: role global (user/admin), terpisah dari role per workspace.
 * - `suspended_at`/`suspended_reason`: penangguhan akun oleh admin.
 * - `last_login_*`: diisi listener saat event Login.
 * - `last_seen_at`: diperbarui middleware, dipakai untuk status "online".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('role', 20)->default(UserRole::User->value)->after('password');
            $table->timestamp('suspended_at')->nullable()->after('role');
            $table->string('suspended_reason')->nullable()->after('suspended_at');
            $table->timestamp('last_login_at')->nullable()->after('suspended_reason');
            $table->string('last_login_ip', 45)->nullable()->after('last_login_at');
            $table->timestamp('last_seen_at')->nullable()->after('last_login_ip');

            $table->index('role');
            $table->index('last_seen_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex(['role']);
            $table->dropIndex(['last_seen_at']);

            $table->dropColumn([
                'role',
                'suspended_at',
                'suspended_reason',
                'last_login_at',
                'last_login_ip',
                'last_seen_at',
            ]);
        });
    }
};
