<?php

use App\Enums\DebtDirection;
use App\Enums\DebtStatus;
use App\Enums\NetWorthItemType;
use App\Enums\NetWorthSubtype;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * Anggaran (PRD.md §3.5): limit per kategori per bulan.
         *
         * `month` disimpan sebagai tanggal pertama bulan (mis. 2026-09-01) agar
         * perbandingan antar bulan tetap berupa rentang tanggal yang bisa
         * memakai index, bukan string "2026-09" yang tidak bisa di-range.
         */
        Schema::create('budgets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->constrained()->cascadeOnDelete();

            $table->date('month');
            $table->decimal('limit_amount', 15, 2);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            // Satu limit per kategori per bulan; join cepat untuk matriks.
            $table->unique(['workspace_id', 'category_id', 'month']);
            $table->index(['workspace_id', 'month']);
        });

        /*
         * Tabel agregat `used_amount` per kategori per bulan
         * (ARCHITECTURE.md §2.3). Halaman anggaran membaca dari sini, bukan
         * menjumlahkan `transactions` mentah saat request.
         */
        Schema::create('budget_progress_cache', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->constrained()->cascadeOnDelete();

            $table->date('month');
            $table->decimal('used_amount', 15, 2)->default('0.00');

            $table->timestamps();

            $table->unique(['workspace_id', 'category_id', 'month']);
            $table->index(['workspace_id', 'month']);
        });

        /*
         * Net worth (PRD.md §3.6): aset & kewajiban yang diinput manual.
         *
         * Kewajiban otomatis (saldo kartu kredit) sengaja TIDAK disimpan
         * sebagai item — ia disinkronkan dari `accounts.cached_balance` saat
         * snapshot, supaya tidak bisa terhitung dua kali.
         *
         * `annual_rate` adalah persentase tahunan (5.00 = 5%/tahun) untuk
         * apresiasi aset maupun penyusutan kewajiban; NULL berarti nilai
         * tetap mengikuti input user apa adanya.
         */
        Schema::create('net_worth_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();

            $table->enum('type', NetWorthItemType::values());
            $table->enum('subtype', NetWorthSubtype::values());
            $table->string('name', 120);
            $table->decimal('value', 15, 2);
            $table->decimal('annual_rate', 5, 2)->nullable();
            $table->text('note')->nullable();
            $table->date('valued_at');

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['workspace_id', 'type']);
            $table->index(['workspace_id', 'valued_at']);
        });

        /*
         * Snapshot bulanan net worth (ARCHITECTURE.md §2.3) — sumber grafik
         * tren. Satu baris per workspace per bulan, ditulis job terjadwal.
         */
        Schema::create('net_worth_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();

            $table->date('month');
            $table->decimal('total_assets', 15, 2)->default('0.00');
            $table->decimal('total_liabilities', 15, 2)->default('0.00');
            $table->decimal('net_worth', 15, 2)->default('0.00');

            $table->timestamps();

            $table->unique(['workspace_id', 'month']);
        });

        /*
         * Utang & piutang (PRD.md §3.7).
         *
         * `remaining` disimpan di tabel (bukan dihitung) karena jadi sumber
         * angka pada daftar & laporan; `DebtService` yang menjaga
         * konsistensinya setiap kali pembayaran dicatat.
         *
         * `include_in_net_worth` adalah opt-in per utang: kalau menyala,
         * sisa utang ikut terhitung di net worth sebagai kewajiban (utang)
         * atau aset (piutang).
         */
        Schema::create('debts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();

            // Akun yang dipakai untuk mencatat pembayaran (opsional).
            $table->foreignId('account_id')->nullable()->constrained('accounts')->nullOnDelete();

            $table->enum('direction', DebtDirection::values());
            $table->string('counterparty', 120);
            $table->decimal('principal', 15, 2);
            $table->decimal('remaining', 15, 2);
            $table->decimal('interest_rate', 5, 2)->nullable();

            $table->date('start_date')->nullable();
            $table->date('due_date')->nullable();

            // Jumlah cicilan yang direncanakan; NULL = tanpa jadwal tetap.
            $table->unsignedSmallInteger('term_count')->nullable();

            $table->enum('status', DebtStatus::values())->default(DebtStatus::Ongoing->value);
            $table->boolean('include_in_net_worth')->default(false);
            $table->text('note')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['workspace_id', 'status']);
            $table->index(['workspace_id', 'direction']);
            $table->index(['workspace_id', 'due_date']);
        });

        /*
         * Pembayaran per cicilan (PRD.md §3.7).
         *
         * `transaction_id` adalah tautan opsional ke modul Transaksi: saat
         * pembayaran dicatat dengan centang "buat transaksi", cicilan ini juga
         * menjadi expense (utang) atau income (piutang) di akun terkait.
         */
        Schema::create('debt_payments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('debt_id')->constrained()->cascadeOnDelete();
            $table->foreignId('transaction_id')->nullable()->constrained('transactions')->nullOnDelete();

            $table->decimal('amount', 15, 2);
            $table->date('paid_at');
            $table->text('note')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['workspace_id', 'paid_at']);
            $table->index(['debt_id', 'paid_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('debt_payments');
        Schema::dropIfExists('debts');
        Schema::dropIfExists('net_worth_snapshots');
        Schema::dropIfExists('net_worth_items');
        Schema::dropIfExists('budget_progress_cache');
        Schema::dropIfExists('budgets');
    }
};
