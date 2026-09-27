<?php

use App\Enums\FinancialHealthLabel;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * Rekap arus kas bulanan (ARCHITECTURE.md §2.3 butir 3, PRD.md §3.1).
         *
         * Tabel agregat denormalisasi: satu baris per workspace per bulan,
         * ditulis `RecomputeDashboardSnapshotJob` dari antrean — bukan
         * dihitung on-the-fly dari `transactions` setiap kali dashboard dibuka.
         * Halaman dashboard & laporan arus kas membaca dari sini.
         *
         * `month` disimpan sebagai tanggal pertama bulan (2026-09-01) supaya
         * tren 6/12 bulan tetap berupa rentang tanggal yang bisa memakai index.
         *
         * `total_transfer` & `transaction_count` melebihi minimum di
         * ARCHITECTURE §4 karena laporan arus kas (PRD.md §3.8) butuh menunjukkan
         * transfer masuk/keluar dan jumlah catatan, sementara menentukan ulang
         * keduanya per request justru mengembalikan pola agregasi yang dilarang.
         */
        Schema::create('dashboard_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();

            $table->date('month');
            $table->decimal('total_income', 15, 2)->default('0.00');
            $table->decimal('total_expense', 15, 2)->default('0.00');
            $table->decimal('total_transfer', 15, 2)->default('0.00');
            $table->decimal('net_cash_flow', 15, 2)->default('0.00');
            $table->unsignedInteger('transaction_count')->default(0);

            $table->timestamp('generated_at')->nullable();
            $table->timestamps();

            $table->unique(['workspace_id', 'month']);
            $table->index(['workspace_id', 'month']);
        });

        /*
         * Skor kesehatan finansial bulanan (PRD.md §3.9).
         *
         * Ditulis `RecomputeFinancialHealthJob` dari snapshot bulanan
         * (`dashboard_snapshots` + `net_worth_snapshots` + akun kas), lalu dibaca
         * lewat cache Redis. Barisnya historis per bulan: skor bulan lalu tidak
         * boleh berubah ketika transaksi bulan ini masih berjalan.
         *
         * `recommendations` disimpan sebagai JSON karena daftar rekomendasi
         * rule-based dibuat bersamaan dengan skornya; menghitungnya ulang saat
         * render berarti menjalankan ulang aturan yang sama di jalur request.
         *
         * `savings_rate` & `dti` adalah persentase 0–100 (2 desimal), sedangkan
         * `emergency_fund_months` adalah jumlah bulan (1 desimal) — keduanya
         * rasio, bukan nominal uang, jadi tidak memakai DECIMAL(15,2).
         */
        Schema::create('financial_health_scores', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();

            $table->date('month');
            $table->decimal('savings_rate', 5, 2)->nullable();
            $table->decimal('dti', 5, 2)->nullable();
            $table->decimal('emergency_fund_months', 5, 2)->nullable();
            $table->unsignedTinyInteger('score')->default(0);
            $table->enum('label', FinancialHealthLabel::values());
            $table->json('recommendations');

            $table->timestamp('generated_at')->nullable();
            $table->timestamps();

            $table->unique(['workspace_id', 'month']);
            $table->index(['workspace_id', 'month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('financial_health_scores');
        Schema::dropIfExists('dashboard_snapshots');
    }
};
