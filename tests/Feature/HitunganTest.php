<?php

namespace Tests\Feature;

use App\Enums\WorkspaceRole;
use App\Models\Account;
use App\Models\DashboardDailySnapshot;
use App\Models\HitunganPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HitunganTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get(route('hitungan.index'))->assertRedirect(route('login'));
    }

    public function test_users_without_a_workspace_are_redirected(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('hitungan.index'))
            ->assertRedirect(route('workspaces.index'));
    }

    public function test_users_see_the_page(): void
    {
        $this->signedIn();

        $this->get(route('hitungan.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('hitungan/Index')
                ->where('balance', '0.00')
                ->where('expenses_today', '0.00')
                ->where('today', now()->toDateString())
                ->where('plan', null),
            );
    }

    public function test_balance_comes_from_the_total_account_balance(): void
    {
        $user = $this->signedIn();
        $workspace = $user->workspaces()->sole();

        Account::factory()->forWorkspace($workspace)->cash('Dompet Tunai', '450000.00')->create();
        Account::factory()->forWorkspace($workspace)->bank('BCA', '1250000.00')->create();
        // Disisihkan, jadi tidak menambah "saldo total".
        Account::factory()->forWorkspace($workspace)->saving('Tabungan', '9000000.00')->create();
        // Kartu kredit adalah liabilitas meski saldonya tidak nol.
        Account::factory()->forWorkspace($workspace)->creditCard('Kartu')->create([
            'cached_balance' => '-5000000.00',
        ]);
        // Akun arsip juga tidak ikut.
        Account::factory()->forWorkspace($workspace)->cash('Lama', '999999.00')->archived()->create();

        $this->get(route('hitungan.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('balance', '1700000.00'));
    }

    public function test_expenses_today_comes_from_the_daily_expense_total(): void
    {
        $user = $this->signedIn();
        $workspace = $user->workspaces()->sole();

        DashboardDailySnapshot::factory()
            ->forWorkspace($workspace)
            ->on(now()->toDateString())
            ->totals('300000.00', '175000.00')
            ->create();

        // Baris hari lain tidak boleh ikut terhitung.
        DashboardDailySnapshot::factory()
            ->forWorkspace($workspace)
            ->on(now()->subDay()->toDateString())
            ->totals('0.00', '900000.00')
            ->create();

        $this->get(route('hitungan.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('expenses_today', '175000.00'),
            );
    }

    public function test_users_can_save_a_plan(): void
    {
        $user = $this->signedIn();
        $workspace = $user->workspaces()->sole();
        $endDate = now()->addDays(10)->toDateString();

        $this->post(route('hitungan.save'), [
            'daily_limit' => '150000',
            'end_date' => $endDate,
            'rows' => [
                ['name' => 'Sewa', 'plan' => '2000000', 'actual' => '2000000'],
                ['name' => 'Belanja', 'plan' => '1500000', 'actual' => '1350000'],
            ],
        ])->assertRedirect(route('hitungan.index'));

        $plan = HitunganPlan::allWorkspaces()->sole();

        $this->assertSame($workspace->id, $plan->workspace_id);
        $this->assertSame('150000.00', $plan->daily_limit);
        $this->assertSame($endDate, $plan->end_date?->toDateString());
        $this->assertSame([
            ['name' => 'Sewa', 'plan' => '2000000.00', 'actual' => '2000000.00'],
            ['name' => 'Belanja', 'plan' => '1500000.00', 'actual' => '1350000.00'],
        ], $plan->rows);
        $this->assertSame($user->id, $plan->created_by);
    }

    public function test_saving_again_replaces_the_existing_plan(): void
    {
        $user = $this->signedIn();
        $workspace = $user->workspaces()->sole();

        HitunganPlan::factory()->forWorkspace($workspace)->create([
            'daily_limit' => '100000.00',
            'end_date' => now()->addDays(5)->toDateString(),
        ]);

        $this->post(route('hitungan.save'), [
            'daily_limit' => '250000',
            'end_date' => '',
            'rows' => [['name' => 'Sewa', 'plan' => '2000000', 'actual' => '0']],
        ])->assertRedirect(route('hitungan.index'));

        $this->assertSame(1, HitunganPlan::allWorkspaces()->count());

        $plan = HitunganPlan::allWorkspaces()->sole();
        $this->assertSame('250000.00', $plan->daily_limit);
        $this->assertNull($plan->end_date);
    }

    public function test_the_saved_plan_is_returned_to_the_page(): void
    {
        $user = $this->signedIn();
        $workspace = $user->workspaces()->sole();

        $endDate = now()->addDays(20)->toDateString();

        HitunganPlan::factory()->forWorkspace($workspace)->create([
            'daily_limit' => '150000.00',
            'end_date' => $endDate,
            'rows' => [
                ['name' => 'Sewa', 'plan' => '2000000.00', 'actual' => '2000000.00'],
            ],
        ]);

        $this->get(route('hitungan.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('hitungan/Index')
                ->where('plan.daily_limit', '150000.00')
                ->where('plan.end_date', $endDate)
                ->where('plan.rows.0.name', 'Sewa')
                ->where('plan.rows.0.plan', '2000000.00'),
            );
    }

    public function test_saving_rejects_invalid_amounts(): void
    {
        $this->signedIn();

        $this->post(route('hitungan.save'), [
            'daily_limit' => 'bukan angka',
            'end_date' => '',
            'rows' => [],
        ])->assertSessionHasErrors('daily_limit');

        $this->assertSame(0, HitunganPlan::allWorkspaces()->count());
    }

    public function test_end_date_cannot_be_before_today(): void
    {
        $this->signedIn();

        $this->post(route('hitungan.save'), [
            'end_date' => now()->subDay()->toDateString(),
            'rows' => [],
        ])->assertSessionHasErrors('end_date');
    }

    public function test_viewers_cannot_save_a_plan(): void
    {
        $this->signedIn(WorkspaceRole::Viewer);

        $this->get(route('hitungan.index'))->assertOk();

        $this->post(route('hitungan.save'), ['rows' => []])->assertForbidden();

        $this->assertSame(0, HitunganPlan::allWorkspaces()->count());
    }

    private function signedIn(WorkspaceRole $role = WorkspaceRole::Owner): User
    {
        $user = User::factory()->withWorkspace('Rumah Tangga', $role)->create();

        $this->actingAs($user)
            ->withSession(['workspace_id' => $user->workspaces()->sole()->id]);

        return $user;
    }
}
