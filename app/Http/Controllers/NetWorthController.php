<?php

namespace App\Http\Controllers;

use App\Enums\NetWorthItemType;
use App\Enums\NetWorthSubtype;
use App\Events\NetWorthItemSaved;
use App\Http\Controllers\Concerns\AuthorizesWorkspaceData;
use App\Http\Requests\NetWorthItemRequest;
use App\Models\NetWorthItem;
use App\Services\NetWorth\NetWorthService;
use App\Support\Money;
use App\Support\MonthPeriod;
use App\Support\Workspace\ActiveWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Halaman net worth (PRD.md §3.6).
 *
 * Angka hari ini dihitung {@see NetWorthService} (murah: beberapa SUM + daftar
 * item), sedangkan tren dibaca dari `net_worth_snapshots` yang ditulis job
 * terjadwal. Jadi controller ini tidak pernah menghitung sejarah dari tabel
 * transaksi.
 */
class NetWorthController extends Controller
{
    use AuthorizesWorkspaceData;

    public function __construct(
        private readonly NetWorthService $netWorth,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorizeViewData();

        $year = (int) $request->query('year', CarbonImmutable::now()->year);
        $summary = $this->netWorth->current($this->workspaceId());
        $series = $this->netWorth->trendSeries($this->workspaceId(), $year);

        return Inertia::render('net-worth/Index', [
            'summary' => [
                'total_assets' => $summary['total_assets'],
                'total_liabilities' => $summary['total_liabilities'],
                'net_worth' => $summary['net_worth'],
                'item_assets' => $summary['item_assets'],
                'item_liabilities' => $summary['item_liabilities'],
                // Dihitung dari baris kartu kredit yang sudah diambil
                // `NetWorthService::current()`; memanggil
                // `creditCardOutstanding()` lagi di sini berarti satu query
                // tambahan untuk angka yang sudah ada di memori.
                'credit_card_outstanding' => Money::sum(
                    array_column($summary['credit_cards'], 'outstanding'),
                ),
                'debt_payable' => $summary['debt_payable'],
                'debt_receivable' => $summary['debt_receivable'],
                'change' => $this->change(),
            ],
            'items' => $summary['items']->values()->all(),
            'credit_cards' => $summary['credit_cards'],
            'series' => $series,
            'year' => $year,
            'years' => $this->yearOptions(),
            'options' => $this->formOptions(),
        ]);
    }

    public function store(NetWorthItemRequest $request): RedirectResponse
    {
        $this->authorizeEditData();

        $item = new NetWorthItem($request->itemAttributes());
        $item->created_by = $request->user()->id;
        $item->updated_by = $request->user()->id;
        $item->save();

        NetWorthItemSaved::dispatch($item, $request->user()->id);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Item net worth berhasil ditambahkan.'),
        ]);

        return to_route('net-worth.index');
    }

    public function update(NetWorthItemRequest $request, NetWorthItem $net_worth_item): RedirectResponse
    {
        $this->authorizeEditData();

        $net_worth_item->fill($request->itemAttributes());
        $net_worth_item->updated_by = $request->user()->id;
        $net_worth_item->save();

        NetWorthItemSaved::dispatch($net_worth_item, $request->user()->id);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Item net worth berhasil diperbarui.'),
        ]);

        return to_route('net-worth.index');
    }

    public function destroy(Request $request, NetWorthItem $net_worth_item): RedirectResponse
    {
        $this->authorizeEditData();

        $net_worth_item->delete();

        NetWorthItemSaved::dispatch($net_worth_item, $request->user()?->id, deleted: true);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Item net worth dihapus.'),
        ]);

        return to_route('net-worth.index');
    }

    /*
    |--------------------------------------------------------------------------
    | Payload Inertia
    |--------------------------------------------------------------------------
    */

    /**
     * Perubahan net worth dibanding snapshot bulan lalu.
     *
     * @return array{direction: string, amount: string}|null
     */
    private function change(): ?array
    {
        $workspaceId = $this->workspaceId();
        $currentMonth = CarbonImmutable::now()->startOfMonth();

        $snapshots = $this->netWorth->trend($workspaceId, (int) $currentMonth->year);

        $previous = $snapshots->filter(
            static fn ($snapshot): bool => $snapshot->month->lessThan($currentMonth),
        )->last();

        $current = $snapshots->firstWhere(
            static fn ($snapshot): bool => $snapshot->month->equalTo($currentMonth),
        );

        if ($previous === null || $current === null) {
            return null;
        }

        return $current->change($previous);
    }

    /**
     * @return array<int, int>
     */
    private function yearOptions(): array
    {
        $year = (int) CarbonImmutable::now()->year;

        return range($year - 2, $year);
    }

    /**
     * @return array<string, mixed>
     */
    private function formOptions(): array
    {
        return [
            'types' => array_map(
                static fn (NetWorthItemType $type): array => [
                    'value' => $type->value,
                    'label' => $type->label(),
                    'subtypes' => array_map(
                        static fn (NetWorthSubtype $subtype): array => [
                            'value' => $subtype->value,
                            'label' => $subtype->label(),
                            'icon' => $subtype->icon(),
                        ],
                        NetWorthSubtype::forType($type),
                    ),
                ],
                NetWorthItemType::cases(),
            ),
            'months' => array_map(
                static fn (CarbonImmutable $month): array => [
                    'month' => MonthPeriod::key($month),
                    'label' => MonthPeriod::label(MonthPeriod::key($month)),
                ],
                MonthPeriod::monthsOfYear((int) CarbonImmutable::now()->year),
            ),
        ];
    }

    private function workspaceId(): int
    {
        return ActiveWorkspace::idOrFail();
    }
}
