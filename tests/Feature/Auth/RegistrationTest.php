<?php

namespace Tests\Feature\Auth;

use App\Enums\WorkspaceRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Fortify\Features;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->skipUnlessFortifyHas(Features::registration());
    }

    public function test_registration_screen_can_be_rendered()
    {
        $response = $this->get(route('register'));

        $response->assertOk();
    }

    public function test_new_users_can_register()
    {
        $response = $this->post(route('register.store'), [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('dashboard', absolute: false));
    }

    public function test_new_users_get_a_default_workspace_as_owner()
    {
        $this->post(route('register.store'), [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $user = User::sole();
        $workspace = $user->workspaces()->sole();

        $this->assertSame('Buku Utama', $workspace->name);
        $this->assertTrue($workspace->owner->is($user));
        $this->assertTrue($user->hasWorkspaceRole($workspace, WorkspaceRole::Owner));
    }

    public function test_the_default_workspace_is_activated_so_the_dashboard_is_reachable()
    {
        $this->post(route('register.store'), [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        // Registrasi memverifikasi email dulu; setelah itu workspace default
        // harus langsung aktif tanpa perlu memilih workspace lagi.
        $user = User::sole();
        $user->markEmailAsVerified();

        $this->actingAs($user)->get(route('dashboard'))->assertOk();
    }
}
