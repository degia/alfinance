<?php

use App\Enums\RecurringFrequency;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * `recurring_rules` dibuat lebih dulu karena `transactions` mereferensikannya.
         *
         * Catatan desain: ARCHITECTURE.md §4 menyebut `recurring_rules` dengan
         * kolom `template_json`. Di sini template disimpan sebagai kolom bertipe
         * (account, type, amount, category, note) — alasannya: JSON tidak punya
         * validasi saat write, harus di-re-decode setiap kali form dibuka, dan
         * tidak bisa dijawab lewat index. Kolom `tag_ids` tetap disimpan sebagai
         * JSON karena tag many-to-many memang tidak punya kolom tunggal.
         */
        Schema::create('recurring_rules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->constrained('accounts')->restrictOnDelete();
            $table->foreignId('transfer_to_account_id')->nullable()->constrained('accounts')->restrictOnDelete();
            $table->foreignId('category_id')->nullable()->constrained('categories')->nullOnDelete();

            $table->enum('type', TransactionType::values());
            $table->decimal('amount', 15, 2);

            // Tag template disimpan sebagai array id; dipindah ke pivot
            // `transaction_tag` saat instance transaksi dibuat.
            $table->json('tag_ids')->nullable();

            $table->text('note')->nullable();

            $table->enum('frequency', RecurringFrequency::values());
            $table->dateTime('next_run_at');

            // "Perlu konfirmasi sebelum posting final" (PRD.md §3.3): kalau
            // true, instance dibuat berstatus `pending` dan belum menyentuh
            // saldo sampai user mengonfirmasi.
            $table->boolean('requires_confirmation')->default(true);
            $table->boolean('is_active')->default(true);
            $table->date('end_date')->nullable();
            $table->dateTime('last_generated_at')->nullable();

            $table->timestamps();

            // Query utama job: cari rule aktif yang sudah jatuh tempo.
            $table->index(['is_active', 'next_run_at']);
            $table->index(['workspace_id', 'is_active']);
        });

        Schema::create('transactions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();

            // Akun sumber. Akun tidak pernah dihapus ( hanya di-arsip ), jadi
            // restrict dipakai supaya histori transaksi tidak bisa menggantung.
            $table->foreignId('account_id')->constrained('accounts')->restrictOnDelete();
            $table->foreignId('transfer_to_account_id')->nullable()->constrained('accounts')->restrictOnDelete();
            $table->foreignId('category_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->foreignId('recurring_rule_id')->nullable()->constrained('recurring_rules')->nullOnDelete();

            $table->enum('type', TransactionType::values());

            // Magnitude positif; arah (tambah/kurang) ditentukan `type`.
            $table->decimal('amount', 15, 2);

            $table->text('note')->nullable();
            $table->dateTime('occurred_at');

            $table->enum('status', TransactionStatus::values())->default(TransactionStatus::Posted->value);

            // Audit trail dasar (PRD.md §6).
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            // Index untuk jalur list: filter utama adalah (workspace, tanggal).
            $table->index(['workspace_id', 'occurred_at']);
            $table->index(['account_id', 'occurred_at']);
            $table->index(['category_id', 'occurred_at']);

            // Account tujuan transfer dipakai saat menampilkan riwayat satu akun.
            $table->index(['transfer_to_account_id', 'occurred_at']);

            // Antrian konfirmasi transaksi berulang.
            $table->index(['status', 'occurred_at']);

            $table->index(['workspace_id', 'type', 'occurred_at']);
        });

        Schema::create('transaction_tag', function (Blueprint $table): void {
            $table->foreignId('transaction_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained()->cascadeOnDelete();

            $table->primary(['transaction_id', 'tag_id']);

            // Filter "transaksi dengan tag X" memakai arah kolom ini.
            $table->index('tag_id');
        });

        Schema::create('attachments', function (Blueprint $table): void {
            $table->id();

            // Disimpan per workspace supaya path file ikut terisolasi dan
            // query tenant tidak perlu join ke transactions (ARCHITECTURE.md §5).
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('transaction_id')->constrained()->cascadeOnDelete();

            $table->string('file_path');
            $table->string('original_name');
            $table->string('mime_type', 100)->nullable();
            $table->unsignedBigInteger('size')->nullable();

            $table->timestamps();

            $table->index(['workspace_id', 'transaction_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attachments');
        Schema::dropIfExists('transaction_tag');
        Schema::dropIfExists('transactions');
        Schema::dropIfExists('recurring_rules');
    }
};
