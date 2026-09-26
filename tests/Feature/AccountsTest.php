<?php

namespace Tests\Feature;

use App\Enums\AccountType;
use App\Enums\WorkspaceRole;
use App\Models\Account;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccountsTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get(route('accounts.index'))->assertRedirect(route('login'));
    }

    public function test_users_see_an_empty_account_list(): void
    {
        $user = $this->signedIn();

        $this->get(route('accounts.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('accounts/Index')
                ->where('accounts', [])
                ->where('filters.archived', false));
    }

    public function test_archived_accounts_are_hidden_by_default(): void
    {
        $user = $this->signedIn();
        $workspace = $user->workspaces()->sole();

        Account::factory()->forWorkspace($workspace)->cash('Dompet')->create();
        Account::factory()->forWorkspace($workspace)->cash('Lama')->archived()->create();

        $this->get(route('accounts.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('accounts', 1)
                ->where('accounts.0.name', 'Dompet'));
    }

    public function test_archived_accounts_can_be_listed_and_restored(): void
    {
        $user = $this->signedIn();
        $workspace = $user->workspaces()->sole();
        $account = Account::factory()->forWorkspace($workspace)->cash('Lama')->archived()->create();

        $this->get(route('accounts.index', ['archived' => 1]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('accounts', 1)
                ->where('accounts.0.is_archived', true));

        $this->post(route('accounts.restore', $account))
            ->assertRedirect(route('accounts.index'));

        $this->assertFalse($account->fresh()->isArchived());
    }

    public function test_members_can_create_an_account(): void
    {
        $user = $this->signedIn();

        $this->post(route('accounts.store'), [
            'name' => 'BCA Tabungan',
            'type' => AccountType::Bank->value,
            'initial_balance' => '1500000.50',
        ])->assertRedirect(route('accounts.index'));

        $account = Account::allWorkspaces()->sole();

        $this->assertSame('BCA Tabungan', $account->name);
        $this->assertSame(AccountType::Bank, $account->type);
        // Saldo awal langsung disalin ke cached_balance.
        $this->assertSame('1500000.50', $account->cached_balance);
        $this->assertSame($user->workspaces()->sole()->id, $account->workspace_id);
    }

    public function test_credit_cards_require_limit_and_billing_schedule(): void
    {
        $this->signedIn();

        $this->from(route('accounts.index'))
            ->post(route('accounts.store'), [
                'name' => 'BCA Credit Card',
                'type' => AccountType::CreditCard->value,
                'initial_balance' => '0',
            ])
            ->assertSessionHasErrors(['credit_limit', 'billing_day', 'due_day']);

        $this->assertSame(0, Account::allWorkspaces()->count());
    }

    public function test_credit_card_fields_are_ignored_for_other_types(): void
    {
        $this->signedIn();

        $this->post(route('accounts.store'), [
            'name' => 'Dompet Tunai',
            'type' => AccountType::Cash->value,
            'initial_balance' => '500000',
            'credit_limit' => '5000000',
            'billing_day' => '5',
            'due_day' => '25',
        ])->assertRedirect(route('accounts.index'));

        $account = Account::allWorkspaces()->sole();

        $this->assertNull($account->credit_limit);
        $this->assertNull($account->billing_day);
        $this->assertNull($account->due_day);
        $this->assertNull($account->availableCredit());
    }

    public function test_available_credit_is_derived_from_outstanding_balance(): void
    {
        $user = $this->signedIn();
        $workspace = $user->workspaces()->sole();

        $card = Account::factory()
            ->forWorkspace($workspace)
            ->creditCard('Kartu Kredit', '10000000.00')
            ->create();

        $this->assertSame('10000000.00', $card->availableCredit());
        $this->assertSame(0.0, $card->creditUsagePercent());

        $card->applyBalanceDelta('-2500000.00');

        $this->assertSame('7500000.00', $card->fresh()->availableCredit());
        $this->assertSame(25.0, $card->fresh()->creditUsagePercent());
    }

    public function test_credit_usage_never_exceeds_one_hundred_percent(): void
    {
        $user = $this->signedIn();
        $workspace = $user->workspaces()->sole();

        $card = Account::factory()
            ->forWorkspace($workspace)
            ->creditCard('Kartu Kredit', '1000000.00')
            ->create();

        $card->applyBalanceDelta('-1500000.00');

        $this->assertSame(100.0, $card->fresh()->creditUsagePercent());
        $this->assertSame('0.00', $card->fresh()->availableCredit());
    }

    public function test_balance_deltas_are_calculated_without_float_rounding(): void
    {
        $user = $this->signedIn();
        $workspace = $user->workspaces()->sole();

        $account = Account::factory()
            ->forWorkspace($workspace)
            ->cash('Dompet', '0.10')
            ->create();

        // 0.10 + 0.20 harus 0.30, bukan 0.30000000000000004 seperti float.
        $account->applyBalanceDelta('0.20');

        $this->assertSame('0.30', $account->fresh()->cached_balance);
    }

    public function test_next_due_date_rolls_to_next_month_when_the_day_has_passed(): void
    {
        $user = $this->signedIn();
        $workspace = $user->workspaces()->sole();

        $card = Account::factory()
            ->forWorkspace($workspace)
            ->creditCard('Kartu Kredit', '10000000.00', 5, 25)
            ->create();

        $this->travelTo(now()->startOfMonth()->addDays(27));

        $due = $card->nextDueDate();

        $this->assertNotNull($due);
        $this->assertSame(25, $due->day);
        // Hari ke-27 sudah lewat tanggal 25, jadi jatuh tempo bergeser ke bulan depan.
        $this->assertTrue($due->greaterThan(now()), 'Jatuh tempo harus di masa depan.');
    }

    public function test_non_credit_accounts_have_no_due_date(): void
    {
        $user = $this->signedIn();
        $workspace = $user->workspaces()->sole();

        $cash = Account::factory()->forWorkspace($workspace)->cash()->create();

        $this->assertNull($cash->nextDueDate());
        $this->assertNull($cash->nextBillingDate());
    }

    public function test_account_names_must_be_unique_per_workspace(): void
    {
        $user = $this->signedIn();
        $workspace = $user->workspaces()->sole();

        Account::factory()->forWorkspace($workspace)->cash('Dompet')->create();

        $this->from(route('accounts.index'))
            ->post(route('accounts.store'), [
                'name' => 'Dompet',
                'type' => AccountType::Cash->value,
                'initial_balance' => '0',
            ])
            ->assertSessionHasErrors('name');

        $this->assertSame(1, Account::allWorkspaces()->count());
    }

    public function test_the_same_account_name_is_allowed_in_another_workspace(): void
    {
        $user = $this->signedIn();
        $ownWorkspace = $user->workspaces()->sole();

        $otherWorkspace = Workspace::factory()->create(['name' => 'Warung']);
        $otherWorkspace->addUser($user, WorkspaceRole::Admin);
        Account::factory()->forWorkspace($otherWorkspace)->cash('Dompet')->create();

        $this->actingAs($user)
            ->withSession(['workspace_id' => $ownWorkspace->id])
            ->post(route('accounts.store'), [
                'name' => 'Dompet',
                'type' => AccountType::Cash->value,
                'initial_balance' => '0',
            ])
            ->assertRedirect(route('accounts.index'));

        $this->assertSame(2, Account::allWorkspaces()->count());
    }

    public function test_updating_the_initial_balance_shifts_the_cached_balance(): void
    {
        $user = $this->signedIn();
        $workspace = $user->workspaces()->sole();

        $account = Account::factory()
            ->forWorkspace($workspace)
            ->cash('Dompet', '1000000.00')
            ->create(['cached_balance' => '750000.00']);

        $this->put(route('accounts.update', $account), [
            'name' => 'Dompet',
            'type' => AccountType::Cash->value,
            'initial_balance' => '1500000.00',
        ])->assertRedirect(route('accounts.index'));

        // Selisih +500rb digeser ke saldo berjalan: 750rb -> 1.250.000.
        $this->assertSame('1250000.00', $account->fresh()->cached_balance);
    }

    public function test_archiving_hides_an_account_without_deleting_it(): void
    {
        $user = $this->signedIn();
        $workspace = $user->workspaces()->sole();
        $account = Account::factory()->forWorkspace($workspace)->cash('Dompet')->create();

        $this->post(route('accounts.archive', $account))
            ->assertRedirect(route('accounts.index'));

        $this->assertNotNull($account->fresh()->archived_at);
        $this->assertSame(1, Account::allWorkspaces()->count());
    }

    public function test_archiving_twice_keeps_the_original_timestamp(): void
    {
        $user = $this->signedIn();
        $workspace = $user->workspaces()->sole();
        $account = Account::factory()->forWorkspace($workspace)->cash('Dompet')->archived()->create();

        $original = $account->archived_at;

        $this->travel(1)->days();

        $account->archive();

        $this->assertTrue($original->equalTo($account->fresh()->archived_at));
    }

    public function test_accounts_from_another_workspace_are_not_reachable(): void
    {
        $user = $this->signedIn();
        $foreignWorkspace = Workspace::factory()->create();
        $foreign = Account::factory()->forWorkspace($foreignWorkspace)->cash('Kas Orang')->create();

        $this->put(route('accounts.update', $foreign), [
            'name' => 'Diretas',
            'type' => AccountType::Cash->value,
            'initial_balance' => '0',
        ])->assertNotFound();

        $this->post(route('accounts.archive', $foreign))->assertNotFound();

        $this->assertSame('Kas Orang', $foreign->fresh()->name);
        $this->assertFalse($foreign->fresh()->isArchived());
    }

    public function test_viewers_can_read_but_not_write_accounts(): void
    {
        $owner = User::factory()->create();
        $workspace = Workspace::factory()->create(['owner_id' => $owner->id]);
        $workspace->addUser($owner, WorkspaceRole::Owner);

        $viewer = User::factory()->create();
        $workspace->addUser($viewer, WorkspaceRole::Viewer);

        $account = Account::factory()->forWorkspace($workspace)->cash('Kas Bersama')->create();

        $this->actingAs($viewer)
            ->withSession(['workspace_id' => $workspace->id])
            ->get(route('accounts.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('accounts', 1));

        $this->post(route('accounts.store'), [
            'name' => 'Kas Baru',
            'type' => AccountType::Cash->value,
            'initial_balance' => '0',
        ])->assertForbidden();

        $this->put(route('accounts.update', $account), [
            'name' => 'Kas Baru',
            'type' => AccountType::Cash->value,
            'initial_balance' => '0',
        ])->assertForbidden();

        $this->post(route('accounts.archive', $account))->assertForbidden();

        $this->assertSame(1, Account::allWorkspaces()->count());
    }

    public function test_accounts_require_an_active_workspace(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('accounts.index'))
            ->assertRedirect(route('workspaces.index'));
    }

    public function test_total_balance_excludes_credit_cards_and_archived_accounts(): void
    {
        $user = $this->signedIn();
        $workspace = $user->workspaces()->sole();

        Account::factory()->forWorkspace($workspace)->cash('Dompet', '1000000.00')->create();
        Account::factory()->forWorkspace($workspace)->bank('BCA', '2000000.00')->create();
        Account::factory()->forWorkspace($workspace)->creditCard('Kartu', '5000000.00')->create();
        Account::factory()->forWorkspace($workspace)->cash('Lama', '999999.00')->archived()->create();

        $this->get(route('accounts.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('totalBalance', '3000000.00'));
    }

    private function signedIn(): User
    {
        $user = User::factory()->withWorkspace('Rumah Tangga', WorkspaceRole::Owner)->create();

        $this->actingAs($user)
            ->withSession(['workspace_id' => $user->workspaces()->sole()->id]);

        return $user;
    }
}
