<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminAccessTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @var array<int, string>
     */
    private array $routes = [
        'admin.dashboard',
        'admin.users.index',
        'admin.emails.index',
    ];

    public function test_guests_are_redirected_to_login(): void
    {
        foreach ($this->routes as $name) {
            $this->get(route($name))->assertRedirect(route('login'));
        }
    }

    public function test_regular_users_are_forbidden(): void
    {
        $this->actingAs(User::factory()->create());

        foreach ($this->routes as $name) {
            $this->get(route($name))->assertForbidden();
        }
    }

    public function test_admins_can_open_every_panel_page(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        foreach ($this->routes as $name) {
            $this->get(route($name))->assertOk();
        }
    }

    public function test_admins_reach_the_panel_without_an_active_workspace(): void
    {
        // Panel admin mengurus akun, bukan isi buku keuangan, jadi tidak boleh
        // ikut terjaring middleware `workspace.selected`.
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin);

        $this->assertSame(0, $admin->workspaces()->count());
        $this->get(route('admin.dashboard'))->assertOk();
    }

    public function test_unverified_admins_must_verify_their_email_first(): void
    {
        $this->actingAs(User::factory()->admin()->unverified()->create());

        $this->get(route('admin.dashboard'))->assertRedirect(route('verification.notice'));
    }

    public function test_the_admin_flag_is_shared_with_the_frontend(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $this->get(route('admin.dashboard'))
            ->assertInertia(fn ($page) => $page->where('auth.is_admin', true));
    }

    public function test_regular_users_are_not_flagged_as_admin(): void
    {
        $this->actingAs(User::factory()->withWorkspace()->create());

        $this->get(route('workspaces.index'))
            ->assertInertia(fn ($page) => $page->where('auth.is_admin', false));
    }
}
