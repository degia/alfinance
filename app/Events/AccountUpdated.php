<?php

namespace App\Events;

use App\Models\Account;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Dipanggil setiap kali akun berubah: dibuat, diperbarui, diarsipkan, atau
 * dipulihkan (PRD.md §3.2).
 *
 * Event ini menutup satu celah invalidasi: `accounts.cached_balance` adalah
 * sumber angka "Total Saldo" di dashboard, jadi perubahan saldo awal maupun
 * arsip harus ikut membangun ulang agregat — bukan hanya transaksi yang
 * menggerakkan saldo (ARCHITECTURE.md §2.1 butir 2).
 *
 * Bulannya tidak ada di sini: sebuah akun tidak terikat satu bulan. Listener
 * memakai bulan berjalan, karena itulah rekap yang diproses ulang.
 */
class AccountUpdated
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly Account $account,
        public readonly string $action = 'updated',
        public readonly ?int $actorId = null,
    ) {}

    public function workspaceId(): int
    {
        return (int) $this->account->workspace_id;
    }
}
