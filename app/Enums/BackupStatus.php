<?php

namespace App\Enums;

/**
 * Status pembuatan backup ke storage (PRD.md §3.11).
 */
enum BackupStatus: string
{
    case Queued = 'queued';
    case Done = 'done';
    case Failed = 'failed';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function label(): string
    {
        return match ($this) {
            self::Queued => 'Antrean',
            self::Done => 'Selesai',
            self::Failed => 'Gagal',
        };
    }

    public function isQueued(): bool
    {
        return $this === self::Queued;
    }

    public function isDone(): bool
    {
        return $this === self::Done;
    }
}
