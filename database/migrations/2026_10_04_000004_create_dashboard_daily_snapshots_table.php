<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * Rekap arus kas harian (ARCHITECTURE.md §2.3 butir 3, PRD.md §3.1).
         *
         * Saudara harian dari `dashboard_snapshots`: satu baris per workspace per
         * tanggal, ditulis dari antrean oleh job yang sama
         * (`RecomputeDashboardSnapshotJob`) supaya line chart harian di dashboard
         * tidak perlu `GROUP BY DATE(occurred_at)` ke `transactions` di jalur
         * request.
         *
         * Yang membedakan tabel ini dari bulanan: hanya baris untuk tanggal yang
         * punya transaksi yang disimpan. Hari tanpa transaksi tidak perlu baris —
         * pembaca ({@see \App\Services\Dashboard\DashboardService::dailyCashFlow()})
         * sudah mengisi nol untuk setiap hari agar sumbu waktu tidak berlubang.
         * Itu membuat tabel ikut menyusut setiap kali transaksi di tanggal itu
         * dihapus.
         *
         * `date` memakai kolom DATE (bukan DATETIME) karena barisnya di-key per
         * hari; konsekuensinya Strtotime/whereDate tidak perlu, dan perbandingan
         * rentang tetap bisa memakai index `(workspace_id, date)`.
         *
         * `total_transfer` sengaja tidak ada: grafik harian hanya menampilkan
         * pemasukan vs pengeluaran. Transfer internal bukan arus kas riil, dan
         * menyertakannya akan membuat garis harian bergerak tanpa ada kas yang
         * benar-benar masuk atau keluar.
         */
        Schema::create('dashboard_daily_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();

            $table->date('date');
            $table->decimal('total_income', 15, 2)->default('0.00');
            $table->decimal('total_expense', 15, 2)->default('0.00');
            $table->decimal('net_cash_flow', 15, 2)->default('0.00');
            $table->unsignedInteger('transaction_count')->default(0);

            $table->timestamp('generated_at')->nullable();
            $table->timestamps();

            $table->unique(['workspace_id', 'date']);
            $table->index(['workspace_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dashboard_daily_snapshots');
    }
};
