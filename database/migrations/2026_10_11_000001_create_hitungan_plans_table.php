<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * Rencana "Hitungan" (kalkulator anggaran bulanan).
         *
         * Satu baris per workspace: halaman Hitungan hanya menyimpan satu
         * rencana aktif, jadi `workspace_id` dibuat unik dan tombol Simpan
         * cukup menimpa baris yang ada (upsert), bukan menumpuk riwayat.
         *
         * `balance` sengaja tidak disimpan — selalu dihitung ulang dari total
         * saldo akun saat halaman dibuka, supaya rencana tidak menyimpan angka
         * yang sudah basi. Begitu juga tanggal mulai (selalu hari ini) dan
         * saldo minimum (limit harian × jumlah hari).
         *
         * `rows` menampung daftar rencana (nama + rencana + aktual) apa adanya
         * sebagai JSON; isinya hanya dipakai untuk mengisi ulang form, tidak
         * pernah diagregasi di SQL.
         */
        Schema::create('hitungan_plans', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();

            $table->decimal('daily_limit', 15, 2)->default('0.00');
            $table->date('end_date')->nullable();
            $table->json('rows');

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->unique('workspace_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hitungan_plans');
    }
};
