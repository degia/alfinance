<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Events\Login;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

/**
 * Perilaku middleware penangguhan akun dan pencatatan aktivitas.
 */
class UserAccountControlTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_suspended_user_is_logged_out_on_the_next_request(): void
    {
        $user = User::factory()->suspended('Spam')->withWorkspace()->create();

        $this->actingAs($user);

        $this->get(route('dashboard'))
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_the_suspension_reason_is_shown_on_the_login_page(): void
    {
        $user = User::factory()->suspended('Aktivitas mencurigakan')->create();

        $this->actingAs($user);

        $this->get(route('workspaces.index'))
            ->assertSessionHasErrors([
                'email' => 'Akun kamu ditangguhkan: Aktivitas mencurigakan',
            ]);
    }

    public function test_an_active_user_passes_through_untouched(): void
    {
        $this->actingAs(User::factory()->withWorkspace()->create());

        $this->get(route('dashboard'))->assertOk();
    }

    public function test_logging_in_records_the_last_login_timestamp_and_ip(): void
    {
        $user = User::factory()->create();

        $this->assertNull($user->last_login_at);

        event(new Login('web', $user, false));

        $user->refresh();

        $this->assertNotNull($user->last_login_at);
        $this->assertNotNull($user->last_seen_at);
        $this->assertNotNull($user->last_login_ip);
    }

    public function test_browsing_refreshes_the_last_seen_timestamp(): void
    {
        $user = User::factory()->withWorkspace()->create(['last_seen_at' => null]);

        $this->actingAs($user);
        $this->get(route('dashboard'))->assertOk();

        $this->assertNotNull($user->refresh()->last_seen_at);
        $this->assertTrue($user->isOnline());
    }

    public function test_activity_tracking_does_not_touch_the_updated_at_column(): void
    {
        $user = User::factory()->withWorkspace()->create([
            'last_seen_at' => null,
            'updated_at' => now()->subWeek(),
        ]);
        $before = $user->updated_at;

        $this->actingAs($user);
        $this->get(route('dashboard'))->assertOk();

        // Aktivitas bukan perubahan profil, jadi `updated_at` harus tetap.
        $this->assertTrue($before->equalTo($user->refresh()->updated_at));
    }

    public function test_a_user_seen_long_ago_is_not_counted_as_online(): void
    {
        $user = User::factory()->create([
            'last_seen_at' => now()->subMinutes(User::ONLINE_WINDOW_MINUTES + 1),
        ]);

        $this->assertFalse($user->isOnline());
        $this->assertSame(0, User::query()->online()->count());
    }

    public function test_the_make_admin_command_promotes_and_revokes(): void
    {
        $user = User::factory()->create(['email' => 'calon@example.test']);
        User::factory()->admin()->create();

        $this->artisan('user:make-admin', ['email' => 'calon@example.test'])
            ->assertSuccessful();

        $this->assertTrue($user->refresh()->isAdmin());

        $this->artisan('user:make-admin', ['email' => 'calon@example.test', '--revoke' => true])
            ->assertSuccessful();

        $this->assertFalse($user->refresh()->isAdmin());
    }

    public function test_the_make_admin_command_reports_unknown_emails(): void
    {
        $this->artisan('user:make-admin', ['email' => 'tidak-ada@example.test'])
            ->assertFailed();
    }

    public function test_roles_cannot_be_escalated_through_mass_assignment(): void
    {
        $user = User::factory()->create();

        $user->fill(['role' => 'admin'])->save();

        // `role` sengaja di luar daftar fillable; satu-satunya jalur naik role
        // adalah panel admin atau perintah artisan.
        $this->assertFalse($user->refresh()->isAdmin());
    }

    public function test_registering_creates_a_regular_user(): void
    {
        $this->post(route('register'), [
            'name' => 'Pengguna Baru',
            'email' => 'baru@example.test',
            'password' => 'password-yang-panjang',
            'password_confirmation' => 'password-yang-panjang',
        ]);

        $user = User::query()->where('email', 'baru@example.test')->sole();

        $this->assertFalse($user->isAdmin());
    }

    public function test_the_authenticated_guard_still_resolves_for_admins(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin);

        $this->assertTrue(Auth::user()->isAdmin());
    }
}
