<?php

use App\Enums\AccountType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Menambah tipe akun `saving` (Tabungan).
 *
 * Kolom `accounts.type` adalah enum di MySQL, jadi daftar nilainya harus ikut
 * diubah — tanpa ini akun tabungan tidak bisa disimpan. `->change()` menulis
 * ulang definisi kolom dengan daftar terbaru dari enum.
 *
 * Akun tabungan tetap berperilaku seperti aset biasa: saldonya bergerak karena
 * transaksi dan tetap dihitung dalam Kekayaan Bersih. Yang berubah hanya
 * "saldo total" dan dana darurat, yang keduanya membaca tipe akun lewat
 * {@see AccountType::countsInTotalBalance()}.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table): void {
            $table->enum('type', AccountType::values())
                ->default(AccountType::Cash->value)
                ->change();
        });
    }

    public function down(): void
    {
        // Baris `saving` tidak bisa ditampung enum yang lebih kecil, jadi
        // rollback menghapus datanya dulu sebelum kolom diperkecil.
        DB::table('accounts')->where('type', AccountType::Saving->value)->delete();

        Schema::table('accounts', function (Blueprint $table): void {
            $types = array_values(array_diff(AccountType::values(), [AccountType::Saving->value]));

            $table->enum('type', $types)
                ->default(AccountType::Cash->value)
                ->change();
        });
    }
};
