<?php

namespace App\Notifications;

use App\Models\Backup;
use Illuminate\Notifications\Notification;

/**
 * Backup selesai dibuat (PRD.md §3.11).
 */
class BackupReady extends Notification
{
    public function __construct(public readonly Backup $backup) {}

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
            'title' => 'Backup siap',
            'message' => 'Backup workspace berhasil dibuat.',
            'backup_id' => $this->backup->id,
            'file_name' => $this->backup->file_name,
        ];
    }
}
