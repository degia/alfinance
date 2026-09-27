<?php

use App\Enums\AccountType;
use App\Enums\CategoryIcon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->enum('type', AccountType::values())->default(AccountType::Cash->value);
            $table->string('name');

            // Saldo awal yang diinput user; cached_balance disalin dari sini
            // dan — di-maintain oleh listener transaksi (Fase 3).
            $table->decimal('initial_balance', 15, 2)->default(0);
            $table->decimal('cached_balance', 15, 2)->default(0);

            // Field khusus kartu kredit / paylater.
            $table->decimal('credit_limit', 15, 2)->nullable();
            $table->unsignedTinyInteger('billing_day')->nullable();
            $table->unsignedTinyInteger('due_day')->nullable();

            $table->text('notes')->nullable();

            // Soft-archive: histori transaksi tetap utuh (PRD.md §3.2).
            $table->timestamp('archived_at')->nullable();

            $table->timestamps();

            $table->unique(['workspace_id', 'name']);
            $table->index(['workspace_id', 'archived_at']);
        });

        Schema::create('categories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();

            // Kategori 2 level: parent_id null = kategori induk.
            $table->foreignId('parent_id')
                ->nullable()
                ->constrained('categories')
                ->nullOnDelete();

            $table->string('name');
            $table->enum('icon', CategoryIcon::values())->default(CategoryIcon::Other->value);
            $table->string('color', 7)->default('#2563eb');

            $table->timestamps();

            // Catatan: unique pada (workspace_id, parent_id, name) TIDAK bisa
            // dipakai karena MySQL menganggap dua NULL selalu berbeda, sehingga
            // kategori induk dengan nama sama tetap lolos. Unik per level
            // dijaga di level validasi (CategoryRequest) — lihat docblocknya.
            $table->index(['workspace_id', 'parent_id']);
            $table->index(['workspace_id', 'name']);
        });

        Schema::create('tags', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('name');

            $table->timestamps();

            $table->unique(['workspace_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tags');
        Schema::dropIfExists('categories');
        Schema::dropIfExists('accounts');
    }
};
