<?php

namespace App\Events;

use App\Models\NetWorthItem;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Dipanggil setelah item net worth tersimpan, diubah, atau dihapus.
 *
 * Dipakai listener yang menyegarkan snapshot bulan berjalan supaya grafik tren
 * tidak menampilkan angka yang sudah basi setelah user menambah aset.
 */
class NetWorthItemSaved
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly NetWorthItem $item,
        public readonly ?int $actorId = null,
        public readonly bool $deleted = false,
    ) {}

    public function workspaceId(): int
    {
        return (int) $this->item->workspace_id;
    }
}
