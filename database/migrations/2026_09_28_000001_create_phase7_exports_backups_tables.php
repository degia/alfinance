<?php

use App\Enums\BackupScope;
use App\Enums\BackupStatus;
use App\Enums\ExportStatus;
use App\Enums\ExportType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * Riwayat ekspor (PRD.md §3.10, ARCHITECTURE.md §4).
         *
         * `filters` menyimpan snapshot filter yang dipakai saat ekspor diminta,
         * supaya berkas tetap bisa dipertanggungjawabkan meski data berubah
         * setelah job selesai. `file_path` relatif ke disk `exports`.
         */
        Schema::create('export_jobs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->enum('type', ExportType::values());
            $table->enum('status', ExportStatus::values())->default(ExportStatus::Queued->value);
            $table->json('filters')->nullable();

            $table->string('file_path')->nullable();
            $table->string('file_name')->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();

            $table->text('error')->nullable();
            $table->timestamp('completed_at')->nullable();

            $table->timestamps();

            $table->index(['workspace_id', 'status']);
            $table->index(['user_id']);
        });

        /*
         * Riwayat backup (PRD.md §3.11).
         *
         * `scope` + `from`/`to` menggambarkan cakupan payload; `summary`
         * menyimpan jumlah baris per bagian saat backup dibuat, dipakai untuk
         * menampilkan preview tanpa membaca ulang file.
         */
        Schema::create('backups', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->enum('scope', BackupScope::values());
            $table->date('from')->nullable();
            $table->date('to')->nullable();
            $table->unsignedTinyInteger('schema_version')->default(1);

            $table->enum('status', BackupStatus::values())->default(BackupStatus::Queued->value);
            $table->string('file_path')->nullable();
            $table->string('file_name')->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();

            $table->text('error')->nullable();
            $table->json('summary')->nullable();
            $table->timestamp('completed_at')->nullable();

            $table->timestamps();

            $table->index(['workspace_id', 'status']);
            $table->index(['user_id']);
        });

        /*
         * Notifikasi database "ekspor/backup siap" (ARCHITECTURE.md §2.1.4).
         *
         * Skema mengikuti kanal `database` bawaan Laravel: relasi morph
         * `notifiable` diisi otomatis oleh Eloquent, jadi kolom
         * `notifiable_type`/`notifiable_id` wajib ada — bukan `user_id`.
         * Halaman Ekspor juga melakukan polling status; baris ini adalah
         * kanal pasif agar user tidak perlu membuka halaman untuk tahu
         * berkasnya sudah jadi.
         */
        Schema::create('notifications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('backups');
        Schema::dropIfExists('export_jobs');
    }
};
