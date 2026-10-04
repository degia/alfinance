<?php

use App\Models\Debt;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Angsuran per bulan untuk Utang & Piutang.
 *
 * `term_count` sudah menyimpan jumlah cicilan, tapi nominalnya selalu diturunkan
 * dari `principal / term_count` — hasil pembagian bulat yang tidak bisa
 * mengikuti aturan cicilan yang nyata, seperti biaya admin atau bunga tetap
 * yang ikut dibayar tiap bulan.
 *
 * Kolom ini menyimpan nominal cicilan yang benar-benar disepakati, jadi angka
 * yang dilihat user di daftar, di detail, dan yang dipakai Debt-to-Income
 * Financial Health semuanya sama. Kosong berarti "tidak diisi manual" dan
 * {@see Debt::installmentAmount()} kembali menurunkannya dari
 * `principal / term_count` supaya utang lama tidak ikut berubah.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('debts', function (Blueprint $table): void {
            $table->decimal('installment_amount', 15, 2)->nullable()->after('term_count');
        });
    }

    public function down(): void
    {
        Schema::table('debts', function (Blueprint $table): void {
            $table->dropColumn('installment_amount');
        });
    }
};
