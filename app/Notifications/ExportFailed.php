<?php

namespace App\Notifications;

use App\Models\ExportJob;
use Illuminate\Notifications\Notification;

/**
 * Ekspor gagal diproses (PRD.md §3.10).
 */
class ExportFailed extends Notification
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
            'title' => 'Ekspor gagal',
            'message' => 'Ekspor "'.$this->job->type->label().'" gagal diproses. Silakan coba lagi.',
            'export_id' => $this->job->id,
            'type' => $this->job->type->value,
        ];
    }
}
