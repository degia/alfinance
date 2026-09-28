<?php

namespace App\Notifications;

use App\Models\ExportJob;
use Illuminate\Notifications\Notification;

/**
 * Ekspor selesai diproses dan siap diunduh (PRD.md §3.10).
 *
 * Notifikasi database disimpan di tabel `notifications`; polling halaman
 * Ekspor tetap jalur utama, notifikasi ini pelengkap.
 */
class ExportReady extends Notification
{
    public function __construct(public readonly ExportJob $job) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'title' => 'Ekspor siap diunduh',
            'message' => 'Ekspor "'.$this->job->type->label().'" selesai dan siap diunduh.',
            'export_id' => $this->job->id,
            'type' => $this->job->type->value,
            'file_name' => $this->job->file_name,
        ];
    }
}
