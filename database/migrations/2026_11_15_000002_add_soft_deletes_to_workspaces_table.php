<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Menghapus workspace berarti menghapus satu buku keuangan utuh, jadi aksi itu
 * dibuat dua langkah: soft delete dulu (masuk "Sampah", bisa dipulihkan owner
 * atau admin), baru force delete untuk membuang datanya permanen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspaces', function (Blueprint $table): void {
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('workspaces', function (Blueprint $table): void {
            $table->dropSoftDeletes();
        });
    }
};
