<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_page()
    {
        $response = $this->get(route('dashboard'));
        $response->assertRedirect(route('login'));
    }

    public function test_authenticated_users_can_visit_the_dashboard()
    {
        $user = User::factory()->withWorkspace()->create();
        $this->actingAs($user);

        $response = $this->get(route('dashboard'));
        $response->assertOk();
    }

    public function test_the_total_balance_card_excludes_savings_accounts()
    {
        $user = User::factory()->withWorkspace()->create();
        $workspace = $user->workspaces()->sole();

        $this->actingAs($user)->withSession(['workspace_id' => $workspace->id]);

        Account::factory()->forWorkspace($workspace)->cash('Dompet Tunai', '450000.00')->create();
        Account::factory()->forWorkspace($workspace)->bank('BCA', '1250000.00')->create();
        Account::factory()->forWorkspace($workspace)->saving('Tabungan', '9000000.00')->create();

        // Angka di kartu "Saldo total" = kas + bank saja. Akun tabungan tetap
        // tampil di halaman Akun, tapi uangnya sudah disisihkan.
        $this->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('kpi.total_balance', '1700000.00'),
            );
    }

    public function test_users_without_a_workspace_are_redirected_to_workspace_selection()
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $response = $this->get(route('dashboard'));
        $response->assertRedirect(route('workspaces.index'));
    }
}
