<?php

namespace App\Services\NetWorth;

use App\Enums\AccountType;
use App\Enums\DebtStatus;
use App\Enums\NetWorthItemType;
use App\Models\Account;
use App\Models\Debt;
use App\Models\NetWorthItem;
use App\Models\NetWorthSnapshot;
use App\Support\Money;
use App\Support\MonthPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Perhitungan net worth (PRD.md §3.6).
 *
 * Empat sumber, dijumlahkan dalam satu angka:
 * 1. item manual di `net_worth_items` (aset & kewajiban), memakai
 *    `NetWorthItem::valueAt()` sehingga `annual_rate`-nya ikut dikompounding;
 * 2. saldo akun non-kartu-kredit (kas, bank, e-wallet, tabungan) yang positif
 *    dihitung sebagai aset. Tabungan memang harus masuk Kekayaan Bersih
 *    (PRD.md §3.2), dan `accounts.cached_balance` adalah sumber kebenaran
 *    tunggalnya (ARCHITECTURE.md §2.2) — jadi angkanya dibaca apa adanya,
 *    bukan dihitung ulang dari transaksi;
 * 3. outstanding kartu kredit dari `accounts.cached_balance`: saldo negatif
 *    jadi kewajiban, saldo positif (bayar lebih) jadi aset;
 * 4. utang/piutang yang mencentang `include_in_net_worth`.
 *
 * Snapshot bulanan ditulis ke `net_worth_snapshots` supaya grafik tren cukup
 * satu `SELECT` dan tidak pernah menghitung ulang sejarah dari ribuan baris
 * transaksi.
 */
class NetWorthService
{
    /**
     * Ringkasan net worth hari ini, dengan rincian per sumber.
     *
     * @return array<string, mixed>
     */
    public function current(int $workspaceId, ?CarbonImmutable $asOf = null): array
    {
        $asOf ??= CarbonImmutable::today();

        $items = $this->items($workspaceId, $asOf);
        $credit = $this->creditCardOutstanding($workspaceId);
        $debts = $this->debtPositions($workspaceId, $asOf);
        $accounts = $this->accountPositions($workspaceId);

        $itemAssets = Money::sum($items->where('type', NetWorthItemType::Asset->value)->pluck('value'));
        $itemLiabilities = Money::sum($items->where('type', NetWorthItemType::Liability->value)->pluck('value'));

        // Saldo akun — termasuk kartu kredit yang bayar lebih — menambah aset.
        $accountAssets = Money::add($accounts['total'], $credit['overpay']);
        $totalAssets = Money::add($itemAssets, $accountAssets);

        $totalLiabilities = Money::add(
            Money::add($itemLiabilities, $credit['outstanding']),
            $debts['payable'],
        );

        // Piutang menambah aset, jadi bukan hanya memotong kewajiban.
        $totalAssets = Money::add($totalAssets, $debts['receivable']);
        $netWorth = Money::subtract($totalAssets, $totalLiabilities);

        return [
            'total_assets' => $totalAssets,
            'total_liabilities' => $totalLiabilities,
            'net_worth' => $netWorth,
            'items' => $items,
            'accounts' => $accounts['rows'],
            'credit_cards' => $credit['accounts'],
            'debts' => $debts['rows'],
            'debt_payable' => $debts['payable'],
            'debt_receivable' => $debts['receivable'],
            'item_assets' => $itemAssets,
            'item_liabilities' => $itemLiabilities,
            'account_assets' => $accountAssets,
        ];
    }

    /**
     * Tulis (atau segarkan) snapshot satu bulan.
     *
     * Upsert manual supaya menjalankan ulang job di bulan yang sama tidak
     * membuat baris ganda (`net_worth_snapshots` punya unique per
     * workspace + bulan).
     */
    public function snapshot(int $workspaceId, CarbonImmutable $month, ?CarbonImmutable $asOf = null): NetWorthSnapshot
    {
        $month = $month->startOfMonth();
        $summary = $this->current($workspaceId, $asOf);

        $snapshot = NetWorthSnapshot::allWorkspaces()
            ->where('workspace_id', $workspaceId)
            ->whereYear('month', $month->year)
            ->whereMonth('month', $month->month)
            ->lockForUpdate()
            ->first();

        if ($snapshot === null) {
            $snapshot = new NetWorthSnapshot;
            $snapshot->workspace_id = $workspaceId;
        }

        $snapshot->month = $month;
        $snapshot->total_assets = $summary['total_assets'];
        $snapshot->total_liabilities = $summary['total_liabilities'];
        $snapshot->net_worth = $summary['net_worth'];
        $snapshot->save();

        return $snapshot;
    }

    /**
     * Deret tren untuk grafik.
     *
     * @return Collection<int, NetWorthSnapshot>
     */
    public function trend(int $workspaceId, int $year): Collection
    {
        return NetWorthSnapshot::allWorkspaces()
            ->where('workspace_id', $workspaceId)
            ->whereYear('month', $year)
            ->orderBy('month')
            ->get();
    }

    /**
     * Item manual dengan nilai berjalan per tanggal acuan.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function items(int $workspaceId, ?CarbonImmutable $asOf = null): Collection
    {
        $asOf ??= CarbonImmutable::today();

        return NetWorthItem::allWorkspaces()
            ->where('workspace_id', $workspaceId)
            ->orderBy('type')
            ->orderBy('name')
            ->get()
            ->map(fn (NetWorthItem $item): array => $this->itemRow($item, $asOf));
    }

    /**
     * Bentuk payload satu item untuk UI.
     *
     * @return array<string, mixed>
     */
    private function itemRow(NetWorthItem $item, CarbonImmutable $asOf): array
    {
        return [
            'id' => (int) $item->id,
            'type' => $item->type->value,
            'subtype' => $item->subtype->value,
            'subtype_label' => $item->subtype->label(),
            'name' => $item->name,
            'value' => $item->value,
            'annual_rate' => $item->annual_rate,
            'note' => $item->note,
            'valued_at' => $item->valued_at->toDateString(),
            // Kalau ada rate, angka yang tampil adalah nilai berjalan — itulah
            // yang membuat nilai aset di halaman ini tetap jujur dari waktu ke
            // waktu. `projected_value` adalah proyeksi satu tahun ke depan.
            'current_value' => $item->valueAt($asOf),
            'projected_value' => $item->valueAt($asOf->addYear()),
        ];
    }

    /**
     * Utang kartu kredit outstanding per akun.
     *
     * Akun dengan saldo negatif berarti utang; saldo positif (bayar lebih)
     * dikembalikan sebagai aset overpay supaya tidak hilang dari perhitungan.
     *
     * @return array{outstanding: string, overpay: string, accounts: array<int, array<string, mixed>>}
     */
    public function creditCardOutstanding(int $workspaceId): array
    {
        $accounts = Account::allWorkspaces()
            ->where('workspace_id', $workspaceId)
            ->where('type', AccountType::CreditCard->value)
            ->orderBy('name')
            ->get();

        $outstanding = '0.00';
        $overpay = '0.00';
        $rows = [];

        foreach ($accounts as $account) {
            $balance = $account->cached_balance;
            $debt = $account->outstandingBalance();

            $outstanding = Money::add($outstanding, $debt);
            $overpay = Money::add($overpay, Money::isNegative($balance) ? '0.00' : $balance);

            $rows[] = [
                'id' => (int) $account->id,
                'name' => $account->name,
                'outstanding' => $debt,
                'credit_limit' => $account->credit_limit,
                'available_credit' => $account->availableCredit(),
                'usage_percent' => $account->creditUsagePercent(),
            ];
        }

        return [
            'outstanding' => $outstanding,
            'overpay' => $overpay,
            'accounts' => $rows,
        ];
    }

    /**
     * Saldo akun non-kartu-kredit sebagai aset net worth.
     *
     * Tabungan ikut dihitung (PRD.md §3.2) meski di luar "saldo total":
     * menabung bukan mengurangi harta. Saldo negatif diabaikan karena akun
     * kas/bank/e-wallet tidak dirancang untuk defisit — transfer keluar yang
     * melebihi saldo (mis. saat melunasi kartu kredit) tidak boleh berubah
     * jadi kewajiban baru.
     *
     * @return array{total: string, rows: array<int, array<string, mixed>>}
     */
    public function accountPositions(int $workspaceId): array
    {
        $accounts = Account::allWorkspaces()
            ->where('workspace_id', $workspaceId)
            ->where('type', '!=', AccountType::CreditCard->value)
            ->active()
            ->orderBy('name')
            ->get();

        $total = '0.00';
        $rows = [];

        foreach ($accounts as $account) {
            $balance = Money::atLeastZero($account->cached_balance);

            if (Money::toCents($balance) <= 0) {
                continue;
            }

            $total = Money::add($total, $balance);

            $rows[] = [
                'id' => (int) $account->id,
                'name' => $account->name,
                'type' => $account->type->value,
                'type_label' => $account->type->label(),
                'is_savings' => $account->isSavings(),
                'balance' => $balance,
            ];
        }

        return ['total' => $total, 'rows' => $rows];
    }

    /**
     * Posisi utang & piutang yang opts-in ke net worth.
     *
     * Hanya yang `include_in_net_worth` dan belum `settled`; `overdue` tetap
     * dihitung (justru itu yang paling penting dilihat). `rows` dikembalikan
     * supaya halaman net worth bisa menampilkan sumber angkanya, bukan cuma
     * totalnya.
     *
     * @return array{payable: string, receivable: string, rows: array<int, array<string, mixed>>}
     */
    public function debtPositions(int $workspaceId, ?CarbonImmutable $asOf = null): array
    {
        $asOf ??= CarbonImmutable::today();

        $debts = Debt::allWorkspaces()
            ->where('workspace_id', $workspaceId)
            ->where('include_in_net_worth', true)
            ->where('status', '!=', DebtStatus::Settled->value)
            ->orderBy('counterparty')
            ->get();

        $payable = '0.00';
        $receivable = '0.00';
        $rows = [];

        foreach ($debts as $debt) {
            $amount = Money::atLeastZero($debt->remaining);
            $isPayable = $debt->isPayable();

            if ($isPayable) {
                $payable = Money::add($payable, $amount);
            } else {
                $receivable = Money::add($receivable, $amount);
            }

            $rows[] = [
                'id' => (int) $debt->id,
                'counterparty' => $debt->counterparty,
                'side' => $isPayable ? 'liability' : 'asset',
                'remaining' => $amount,
                'due_date' => $debt->due_date?->toDateString(),
                'is_overdue' => $debt->isOverdue($asOf),
            ];
        }

        return ['payable' => $payable, 'receivable' => $receivable, 'rows' => $rows];
    }

    /**
     * Ringkasan tren per bulan untuk chart, lengkap dengan delta antar bulan.
     *
     * @return array<int, array<string, mixed>>
     */
    public function trendSeries(int $workspaceId, int $year): array
    {
        $months = MonthPeriod::monthsOfYear($year);
        $snapshots = $this->trend($workspaceId, $year)->keyBy(
            static fn (NetWorthSnapshot $snapshot): string => $snapshot->monthKey(),
        );

        $series = [];
        $previous = null;

        foreach ($months as $month) {
            $key = MonthPeriod::key($month);
            $snapshot = $snapshots->get($key);
            $netWorth = $snapshot?->net_worth;

            $series[] = [
                'month' => $key,
                'label' => MonthPeriod::shortLabel($key),
                'net_worth' => $netWorth,
                'total_assets' => $snapshot?->total_assets,
                'total_liabilities' => $snapshot?->total_liabilities,
                'change' => $snapshot !== null && $previous !== null
                    ? $snapshot->change($previous)
                    : null,
                'has_snapshot' => $snapshot !== null,
            ];

            if ($snapshot !== null) {
                $previous = $snapshot;
            }
        }

        return $series;
    }
}
