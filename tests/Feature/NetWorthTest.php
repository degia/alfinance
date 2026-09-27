<?php

namespace Tests\Feature;

use App\Enums\NetWorthItemType;
use App\Enums\NetWorthSubtype;
use App\Enums\TransactionType;
use App\Enums\WorkspaceRole;
use App\Jobs\CloseMonthlyNetWorthSnapshotsJob;
use App\Jobs\GenerateNetWorthSnapshotJob;
use App\Models\Account;
use App\Models\Debt;
use App\Models\NetWorthItem;
use App\Models\NetWorthSnapshot;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Debt\DebtService;
use App\Services\NetWorth\NetWorthService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Net worth (PRD.md §3.6).
 *
 * Tiga hal yang dijaga di sini:
 * 1. sumber kebenaran tidak boleh dobel: saldo kartu kredit dan utang/piutang
 *    dihitung dari modulnya masing-masing, item manual adalah sisanya;
 * 2. tren dibaca dari `net_worth_snapshots`, bukan dari agregasi ulang;
 * 3. satu workspace tidak boleh pernah melihat angka workspace lain.
 */
class NetWorthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Halaman Inertia dirender lewat Vite, tapi test ini tidak butuh bundle
        // frontend yang belum dibangun.
        $this->withoutVite();
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get(route('net-worth.index'))->assertRedirect(route('login'));
    }

    public function test_users_see_an_empty_page(): void
    {
        $this->signedIn();

        $this->get(route('net-worth.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('net-worth/Index')
                ->where('summary.total_assets', '0.00')
                ->where('summary.total_liabilities', '0.00')
                ->where('summary.net_worth', '0.00')
                ->where('items', [])
                ->has('series', 12)
                ->where('series.0.has_snapshot', false));
    }

    public function test_items_split_assets_and_liabilities(): void
    {
        $workspace = $this->signedIn()->workspaces()->sole();

        NetWorthItem::factory()->forWorkspace($workspace)
            ->asset(NetWorthSubtype::Property)->value('450000000.00')->create(['name' => 'Rumah']);
        NetWorthItem::factory()->forWorkspace($workspace)
            ->asset(NetWorthSubtype::Investment)->value('50000000.00')->create(['name' => 'Reksa dana']);
        NetWorthItem::factory()->forWorkspace($workspace)
            ->liability(NetWorthSubtype::Mortgage)->value('120000000.00')->create(['name' => 'KPR']);

        $this->get(route('net-worth.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('summary.item_assets', '500000000.00')
                ->where('summary.item_liabilities', '120000000.00')
                ->where('summary.total_assets', '500000000.00')
                ->where('summary.total_liabilities', '120000000.00')
                ->where('summary.net_worth', '380000000.00')
                ->has('items', 3));
    }

    public function test_credit_card_outstanding_counts_as_a_liability(): void
    {
        $workspace = $this->signedIn()->workspaces()->sole();
        $card = Account::factory()->forWorkspace($workspace)
            ->creditCard('BCA Skyrizor', '10000000.00')->create();

        $this->spend($card, '2750000.00');

        $this->assertSame('-2750000.00', $card->fresh()->cached_balance);

        $this->get(route('net-worth.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('summary.credit_card_outstanding', '2750000.00')
                ->where('summary.total_liabilities', '2750000.00')
                ->where('summary.net_worth', '-2750000.00')
                ->has('credit_cards', 1)
                ->where('credit_cards.0.name', 'BCA Skyrizor')
                ->where('credit_cards.0.outstanding', '2750000.00'));
    }

    public function test_credit_card_overpayment_counts_as_an_asset(): void
    {
        $workspace = $this->signedIn()->workspaces()->sole();
        $card = Account::factory()->forWorkspace($workspace)->creditCard()->create();
        $cash = Account::factory()->forWorkspace($workspace)->cash()->create();

        // Transfer masuk ke kartu kredit -> saldo kartu positif, jadi aset
        // (bayar lebih), bukan utang.
        $this->post(route('transactions.store'), [
            'account_id' => $cash->id,
            'transfer_to_account_id' => $card->id,
            'type' => TransactionType::Transfer->value,
            'amount' => '500000.00',
            'occurred_at' => '2026-09-04',
        ])->assertRedirect();

        $this->assertSame('500000.00', $card->fresh()->cached_balance);

        $this->get(route('net-worth.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('summary.credit_card_outstanding', '0.00')
                ->where('summary.total_assets', '500000.00')
                ->where('summary.net_worth', '500000.00'));
    }

    public function test_debts_only_count_when_opted_in(): void
    {
        $workspace = $this->signedIn()->workspaces()->sole();

        Debt::factory()->forWorkspace($workspace)->payable()
            ->principal('3000000.00')->create(['counterparty' => 'KPR']);
        Debt::factory()->forWorkspace($workspace)->payable()
            ->principal('500000.00')
            ->excludedFromNetWorth()->create(['counterparty' => 'Pinjaman pribadi']);
        // Piutang selalu dihitung sebagai aset.
        Debt::factory()->forWorkspace($workspace)->receivable()
            ->principal('750000.00')->create(['counterparty' => 'Kasbon']);

        $this->get(route('net-worth.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('summary.debt_payable', '3000000.00')
                ->where('summary.total_liabilities', '3000000.00')
                ->where('summary.debt_receivable', '750000.00')
                ->where('summary.total_assets', '750000.00')
                ->where('summary.net_worth', '-2250000.00'));
    }

    public function test_settled_debts_are_not_counted_anymore(): void
    {
        $workspace = $this->signedIn()->workspaces()->sole();

        $debt = Debt::factory()->forWorkspace($workspace)->payable()
            ->principal('1000000.00')->create();

        app(DebtService::class)->recordPayment($debt, [
            'amount' => '1000000.00',
            'paid_at' => '2026-09-05',
        ]);

        $this->get(route('net-worth.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('summary.debt_payable', '0.00')
                ->where('summary.net_worth', '0.00'));
    }

    public function test_annual_rate_moves_the_current_value(): void
    {
        $workspace = $this->signedIn()->workspaces()->sole();

        $item = NetWorthItem::factory()->forWorkspace($workspace)
            ->asset(NetWorthSubtype::Investment)
            ->value('100000000.00')
            ->compounding('5.00')
            ->valuedAt('2025-09-27')
            ->create(['name' => 'Reksa dana']);

        // Tepat satu tahun lalu -> tepat satu kali compounding 5%.
        $this->assertSame('105000000.00', $item->valueAt(CarbonImmutable::parse('2026-09-27')));

        $this->get(route('net-worth.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('items', 1)
                ->where('items.0.annual_rate', '5.00')
                ->where('items.0.current_value', '105000000.00'));
    }

    public function test_members_can_create_an_item(): void
    {
        $this->signedIn();

        $this->post(route('net-worth.store'), [
            'type' => NetWorthItemType::Asset->value,
            'subtype' => NetWorthSubtype::Vehicle->value,
            'name' => 'Mobil',
            'value' => '65000000.00',
            'annual_rate' => '',
            'valued_at' => '2026-09-01',
            'note' => 'Biaris',
        ])->assertRedirect(route('net-worth.index'));

        $item = NetWorthItem::allWorkspaces()->sole();

        $this->assertSame('Mobil', $item->name);
        $this->assertSame(NetWorthItemType::Asset, $item->type);
        $this->assertNull($item->annual_rate, 'Rate kosong berarti nilai tetap, bukan 0%.');
    }

    public function test_subtype_must_match_the_type(): void
    {
        $this->signedIn();

        $this->post(route('net-worth.store'), [
            // Properti adalah aset, bukan kewajiban.
            'type' => NetWorthItemType::Liability->value,
            'subtype' => NetWorthSubtype::Property->value,
            'name' => 'Rumah',
            'value' => '450000000.00',
            'valued_at' => '2026-09-01',
        ])->assertSessionHasErrors('subtype');

        $this->assertSame(0, NetWorthItem::allWorkspaces()->count());
    }

    public function test_value_date_cannot_be_in_the_future(): void
    {
        $this->signedIn();

        $this->post(route('net-worth.store'), [
            'type' => NetWorthItemType::Asset->value,
            'subtype' => NetWorthSubtype::OtherAsset->value,
            'name' => 'OMAIN',
            'value' => '1000000.00',
            'valued_at' => CarbonImmutable::today()->addDay()->toDateString(),
        ])->assertSessionHasErrors('valued_at');
    }

    public function test_members_can_update_and_delete_an_item(): void
    {
        $workspace = $this->signedIn()->workspaces()->sole();

        $item = NetWorthItem::factory()->forWorkspace($workspace)
            ->asset()->value('1000000.00')->create(['name' => 'Lama']);

        $this->put(route('net-worth.update', $item), [
            'type' => NetWorthItemType::Asset->value,
            'subtype' => NetWorthSubtype::OtherAsset->value,
            'name' => 'Baru',
            'value' => '2500000.00',
            'valued_at' => '2026-09-02',
        ])->assertRedirect(route('net-worth.index'));

        $this->assertSame('Baru', $item->fresh()->name);
        $this->assertSame('2500000.00', $item->fresh()->value);

        $this->delete(route('net-worth.destroy', $item))->assertRedirect(route('net-worth.index'));

        $this->assertSame(0, NetWorthItem::allWorkspaces()->count());
    }

    public function test_viewers_can_read_but_not_write_items(): void
    {
        $this->signedInAs(WorkspaceRole::Viewer);

        $this->get(route('net-worth.index'))->assertOk();

        $this->post(route('net-worth.store'), [
            'type' => NetWorthItemType::Asset->value,
            'subtype' => NetWorthSubtype::OtherAsset->value,
            'name' => 'Dilarang',
            'value' => '1000000.00',
            'valued_at' => '2026-09-01',
        ])->assertForbidden();

        $this->assertSame(0, NetWorthItem::allWorkspaces()->count());
    }

    public function test_another_workspaces_numbers_are_invisible(): void
    {
        $mine = $this->signedIn()->workspaces()->sole();

        $foreign = Workspace::factory()->create();

        NetWorthItem::factory()->forWorkspace($foreign)
            ->asset()->value('900000000.00')->create(['name' => 'Asing']);
        Account::factory()->forWorkspace($foreign)->creditCard()->create([
            'cached_balance' => '-4000000.00',
        ]);
        Debt::factory()->forWorkspace($foreign)->payable()
            ->principal('6000000.00')->create();

        $this->get(route('net-worth.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('summary.total_assets', '0.00')
                ->where('summary.total_liabilities', '0.00')
                ->where('summary.credit_card_outstanding', '0.00')
                ->where('summary.debt_payable', '0.00')
                ->where('summary.net_worth', '0.00')
                ->where('items', [])
                ->where('credit_cards', []));

        $this->assertNotNull($mine);
    }

    public function test_saving_an_item_writes_the_current_month_snapshot(): void
    {
        $this->signedIn();

        $this->post(route('net-worth.store'), [
            'type' => NetWorthItemType::Asset->value,
            'subtype' => NetWorthSubtype::OtherAsset->value,
            'name' => 'Tabungan',
            'value' => '300000000.00',
            'valued_at' => '2026-09-01',
        ])->assertRedirect(route('net-worth.index'));

        $snapshot = NetWorthSnapshot::allWorkspaces()->sole();

        $this->assertSame(
            CarbonImmutable::now()->startOfMonth()->toDateString(),
            $snapshot->month->toDateString(),
        );
        $this->assertSame('300000000.00', $snapshot->net_worth);
    }

    public function test_saving_an_item_twice_updates_one_snapshot_row(): void
    {
        $this->signedIn();

        foreach (['100000000.00', '50000000.00'] as $index => $value) {
            $this->post(route('net-worth.store'), [
                'type' => NetWorthItemType::Asset->value,
                'subtype' => NetWorthSubtype::OtherAsset->value,
                'name' => 'Aset '.$index,
                'value' => $value,
                'valued_at' => '2026-09-01',
            ]);
        }

        $this->assertSame(1, NetWorthSnapshot::allWorkspaces()->count());
        $this->assertSame('150000000.00', NetWorthSnapshot::allWorkspaces()->sole()->net_worth);
    }

    public function test_deleting_an_item_refreshes_the_snapshot(): void
    {
        $workspace = $this->signedIn()->workspaces()->sole();

        NetWorthItem::factory()->forWorkspace($workspace)
            ->asset()->value('100000000.00')->create();
        $removed = NetWorthItem::factory()->forWorkspace($workspace)
            ->asset()->value('70000000.00')->create();

        $this->delete(route('net-worth.destroy', $removed))->assertRedirect();

        $this->assertSame('100000000.00', NetWorthSnapshot::allWorkspaces()->sole()->net_worth);
    }

    public function test_a_debt_payment_refreshes_the_snapshot(): void
    {
        $workspace = $this->signedIn()->workspaces()->sole();

        $debt = Debt::factory()->forWorkspace($workspace)->payable()
            ->principal('2000000.00')->create();

        // Membuat utang tidak mengubah apa pun yang sudah tercatat, jadi belum
        // ada snapshot sama sekali.
        $this->assertSame(0, NetWorthSnapshot::allWorkspaces()->count());

        app(DebtService::class)->recordPayment($debt, [
            'amount' => '500000.00',
            'paid_at' => '2026-09-06',
        ]);

        $this->assertSame('1500000.00', $debt->fresh()->remaining);
        $this->assertSame('-1500000.00', NetWorthSnapshot::allWorkspaces()->sole()->net_worth);
    }

    public function test_saving_a_debt_payment_does_not_touch_other_workspaces(): void
    {
        $mine = $this->signedIn()->workspaces()->sole();

        $foreign = Workspace::factory()->create();
        $foreignDebt = Debt::factory()->forWorkspace($foreign)->payable()
            ->principal('4000000.00')->create();

        app(DebtService::class)->recordPayment($foreignDebt, [
            'amount' => '1000000.00',
            'paid_at' => '2026-09-06',
        ]);

        // Cicilan milik workspace lain hanya menyegarkan snapshot workspace itu.
        $this->assertSame(
            0,
            NetWorthSnapshot::allWorkspaces()->where('workspace_id', $mine->id)->count(),
        );
        $this->assertSame(
            '-3000000.00',
            NetWorthSnapshot::allWorkspaces()->where('workspace_id', $foreign->id)->sole()->net_worth,
        );
    }

    public function test_the_trend_series_comes_from_snapshots(): void
    {
        $workspace = $this->signedIn()->workspaces()->sole();
        $year = (int) CarbonImmutable::now()->year;

        NetWorthSnapshot::factory()->forWorkspace($workspace)
            ->forMonth(sprintf('%d-01', $year))->totals('100000000.00', '20000000.00')->create();
        NetWorthSnapshot::factory()->forWorkspace($workspace)
            ->forMonth(sprintf('%d-02', $year))->totals('120000000.00', '20000000.00')->create();

        $this->get(route('net-worth.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('year', $year)
                ->where('series.0.month', sprintf('%d-01', $year))
                ->where('series.0.net_worth', '80000000.00')
                ->where('series.0.has_snapshot', true)
                ->where('series.0.total_assets', '100000000.00')
                ->where('series.1.net_worth', '100000000.00')
                ->where('series.1.change.direction', 'up')
                ->where('series.2.has_snapshot', false)
                ->where('series.2.net_worth', null));
    }

    public function test_the_snapshot_job_writes_the_requested_month(): void
    {
        $workspace = $this->signedIn()->workspaces()->sole();

        NetWorthItem::factory()->forWorkspace($workspace)
            ->asset()->value('250000000.00')->create();

        GenerateNetWorthSnapshotJob::dispatchSync((int) $workspace->id, '2026-08');

        $snapshot = NetWorthSnapshot::allWorkspaces()
            ->whereYear('month', 2026)
            ->whereMonth('month', 8)
            ->sole();

        $this->assertSame('250000000.00', $snapshot->net_worth);
    }

    public function test_closing_the_month_covers_every_workspace(): void
    {
        $first = $this->signedIn()->workspaces()->sole();

        $others = collect(range(1, 105))->map(
            static fn (int $index): Workspace => Workspace::factory()->create(),
        );

        NetWorthItem::factory()->forWorkspace($first)
            ->asset()->value('1000000.00')->create();

        foreach ($others as $workspace) {
            NetWorthItem::factory()->forWorkspace($workspace)
                ->asset()->value('2000000.00')->create();
        }

        // Job menutup bulan lalu, jadi snapshot bulan berjalan dibersihkan dulu
        // supaya tidak tertukar dengan hasil perhitungan yang diuji.
        NetWorthSnapshot::allWorkspaces()->delete();

        app(CloseMonthlyNetWorthSnapshotsJob::class)->handle(app(NetWorthService::class));

        $previousMonth = CarbonImmutable::now()->startOfMonth()->subMonthNoOverflow();

        $snapshots = NetWorthSnapshot::allWorkspaces()
            ->whereYear('month', $previousMonth->year)
            ->whereMonth('month', $previousMonth->month)
            ->get();

        // 1 workspace milik user + 105 workspace tambahan: semuanya harus ikut.
        // `chunkById` yang membuat ini mungkin; `limit` polos hanya menutup 100.
        $this->assertCount(106, $snapshots);
        $this->assertSame('1000000.00', $snapshots->firstWhere('workspace_id', $first->id)?->net_worth);
    }

    public function test_closing_the_month_is_idempotent(): void
    {
        $workspace = $this->signedIn()->workspaces()->sole();

        NetWorthItem::factory()->forWorkspace($workspace)
            ->asset()->value('5000000.00')->create();

        NetWorthSnapshot::allWorkspaces()->delete();

        $job = app(CloseMonthlyNetWorthSnapshotsJob::class);
        $job->handle(app(NetWorthService::class));
        $job->handle(app(NetWorthService::class));

        $this->assertSame(1, NetWorthSnapshot::allWorkspaces()->count());
    }

    /**
     * Belanja nyata lewat TransactionManager supaya `cached_balance` — sumber
     * angka kartu kredit di halaman ini — benar-benar bergerak.
     */
    private function spend(Account $account, string $amount): void
    {
        $this->post(route('transactions.store'), [
            'account_id' => $account->id,
            'type' => TransactionType::Expense->value,
            'amount' => $amount,
            'occurred_at' => '2026-09-03',
        ])->assertRedirect();
    }

    private function signedIn(): User
    {
        return $this->signedInAs(WorkspaceRole::Owner);
    }

    private function signedInAs(WorkspaceRole $role): User
    {
        $user = User::factory()->withWorkspace('Rumah Tangga', $role)->create();

        $this->actingAs($user)
            ->withSession(['workspace_id' => $user->workspaces()->sole()->id]);

        return $user;
    }
}
