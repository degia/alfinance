<?php

namespace App\Notifications;

use App\Models\Backup;
use Illuminate\Notifications\Notification;

/**
 * Backup gagal dibuat (PRD.md §3.11).
 */
class BackupFailed extends Notification
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
            'title' => 'Backup gagal',
            'message' => 'Backup workspace gagal dibuat. Silakan coba lagi.',
            'backup_id' => $this->backup->id,
        ];
    }
}
