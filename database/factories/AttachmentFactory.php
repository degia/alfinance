<?php

namespace Database\Factories;

use App\Models\Attachment;
use App\Models\Transaction;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Http\UploadedFile;

/**
 * @extends Factory<Attachment>
 */
class AttachmentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $transaction = Transaction::factory();

        return [
            'workspace_id' => Workspace::factory(),
            'transaction_id' => $transaction,
            'file_path' => 'workspaces/0/transactions/0/contoh.jpg',
            'original_name' => 'contoh.jpg',
            'mime_type' => 'image/jpeg',
            'size' => 1024,
        ];
    }

    public function forWorkspace(Workspace $workspace): static
    {
        return $this->state(fn (): array => [
            'workspace_id' => $workspace->id,
        ]);
    }

    public function forTransaction(Transaction $transaction): static
    {
        return $this->state(fn (): array => [
            'workspace_id' => $transaction->workspace_id,
            'transaction_id' => $transaction->id,
        ]);
    }

    /**
     * Lampiran dengan file PNG sungguhan di disk privat.
     */
    public function withRealFile(?UploadedFile $file = null): static
    {
        return $this->state(function () use ($file): array {
            $file ??= UploadedFile::fake()->image('bukti.png', 40, 40);

            $path = $file->store('workspaces/testing/transactions', 'local');

            return [
                'file_path' => $path,
                'original_name' => $file->getClientOriginalName(),
                'mime_type' => $file->getMimeType(),
                'size' => $file->getSize(),
            ];
        });
    }
}
