<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Potongan admin dicatat sebagai baris `expense` tersendiri yang menunjuk
 * transfer induknya lewat `parent_transaction_id`.
 *
 * Kolom ini yang membuat baris biaya itu bisa ikut diperbarui / dihapus
 * bersama transfernya — tanpa itu, satu delete transfer akan meninggalkan
 * pengeluaran yatim yang tidak pernah bisa dibersihkan dari form.
 *
 * `cascadeOnDelete` hanya jaring pengaman untuk delete di luar
 * `TransactionManager`; pembalikan saldo tetap dihitung aplikasi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table): void {
            $table->foreignId('parent_transaction_id')
                ->nullable()
                ->after('category_id')
                ->constrained('transactions')
                ->cascadeOnDelete();

            $table->index(['workspace_id', 'parent_transaction_id']);
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('parent_transaction_id');
            $table->dropIndex(['workspace_id', 'parent_transaction_id']);
        });
    }
};
