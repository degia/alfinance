<?php

namespace App\Enums;

/**
 * Status pemrosesan satu ekspor (PRD.md §3.10, ARCHITECTURE.md §4).
 *
 * `queued` → `processing` → `done`/`failed`. Status final disengaja: job
 * antrean tidak pernah mengubah ekspor yang sudah `done` (lihat
 * `ProcessExportJob`).
 */
enum ExportStatus: string
{
    case Queued = 'queued';
    case Processing = 'processing';
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
            self::Processing => 'Diproses',
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

    public function isFinal(): bool
    {
        return $this === self::Done || $this === self::Failed;
    }
}
