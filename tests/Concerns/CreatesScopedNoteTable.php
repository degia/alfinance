<?php

namespace Tests\Concerns;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\Fixtures\ScopedNote;

/**
 * Membuat tabel fixture untuk model tenant-aware {@see ScopedNote}.
 *
 * Tabel domain asli (accounts, transactions, dst.) baru ada pada Fase 2+, jadi
 * Fase 1 memverifikasi global scope lewat tabel sementara yang dibuat di
 * `setUp()` dan otomatis di-rollback oleh RefreshDatabase.
 */
trait CreatesScopedNoteTable
{
    protected function setUpScopedNoteTable(): void
    {
        Schema::create('scoped_notes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id');
            $table->string('name');
            $table->timestamps();
        });
    }
}
