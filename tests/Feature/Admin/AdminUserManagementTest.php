<?php

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class AdminUserManagementTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin);

        return $admin;
    }

    public function test_the_list_shows_account_status_for_each_user(): void
    {
        $this->admin();
        User::factory()->online()->create(['name' => 'Budi', 'email' => 'budi@example.test']);

        $this->get(route('admin.users.index', ['search' => 'budi']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('admin/Users')
                ->has('users.data', 1)
                ->where('users.data.0.name', 'Budi')
                ->where('users.data.0.role', 'user')
                ->where('users.data.0.is_online', true)
                ->where('users.data.0.is_suspended', false)
                ->where('users.data.0.is_verified', true));
    }

    public function test_the_list_can_be_filtered_by_status(): void
    {
        $this->admin();
        User::factory()->suspended()->create(['name' => 'Ditangguhkan']);
        User::factory()->create(['name' => 'Normal']);

        $this->get(route('admin.users.index', ['status' => 'suspended']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('users.data', 1)
                ->where('users.data.0.name', 'Ditangguhkan'));
    }

    public function test_sorting_is_limited_to_an_allow_list(): void
    {
        $this->admin();

        // Tanpa allow-list, query string bisa mengurutkan kolom rahasia.
        $this->get(route('admin.users.index', ['sort' => 'password']))
            ->assertSessionHasErrors('sort');
    }

    public function test_an_admin_promotes_another_user(): void
    {
        $this->admin();
        $target = User::factory()->create();

        $this->put(route('admin.users.role', $target), ['role' => 'admin'])
            ->assertRedirect();

        $this->assertSame(UserRole::Admin, $target->refresh()->role);
    }

    public function test_an_admin_cannot_change_their_own_role(): void
    {
        $admin = $this->admin();

        $this->put(route('admin.users.role', $admin), ['role' => 'user'])
            ->assertForbidden();

        $this->assertSame(UserRole::Admin, $admin->refresh()->role);
    }

    public function test_the_last_admin_cannot_be_demoted(): void
    {
        $this->admin();
        $other = User::factory()->admin()->create();

        // Setelah $other turun, pemanggil tetap admin, jadi demote berhasil.
        $this->put(route('admin.users.role', $other), ['role' => 'user'])->assertRedirect();
        $this->assertSame(UserRole::User, $other->refresh()->role);

        // Kini hanya tersisa satu admin; menurunkannya akan mengunci panel.
        $promoted = User::factory()->admin()->create();
        $this->actingAs($promoted);

        $last = User::query()->admins()->where('id', '!=', $promoted->id)->first();
        $this->assertNotNull($last);

        $this->put(route('admin.users.role', $last), ['role' => 'user'])->assertRedirect();
        $this->assertSame(UserRole::User, $last->refresh()->role);

        $this->assertSame(1, User::query()->admins()->count());
    }

    public function test_suspending_an_account_records_the_reason(): void
    {
        $this->admin();
        $target = User::factory()->create();

        $this->post(route('admin.users.suspend', $target), ['reason' => 'Spam'])
            ->assertRedirect();

        $target->refresh();

        $this->assertTrue($target->isSuspended());
        $this->assertSame('Spam', $target->suspended_reason);
    }

    public function test_unsuspending_clears_the_reason(): void
    {
        $this->admin();
        $target = User::factory()->suspended('Spam')->create();

        $this->post(route('admin.users.unsuspend', $target))->assertRedirect();

        $target->refresh();

        $this->assertFalse($target->isSuspended());
        $this->assertNull($target->suspended_reason);
    }

    public function test_an_admin_cannot_suspend_themselves(): void
    {
        $admin = $this->admin();

        $this->post(route('admin.users.suspend', $admin))->assertForbidden();

        $this->assertFalse($admin->refresh()->isSuspended());
    }

    public function test_an_admin_verifies_an_email_manually(): void
    {
        $this->admin();
        $target = User::factory()->unverified()->create();

        $this->post(route('admin.users.verifyEmail', $target))->assertRedirect();

        $this->assertTrue($target->refresh()->hasVerifiedEmail());
    }

    public function test_resending_verification_sends_a_notification(): void
    {
        Notification::fake();

        $this->admin();
        $target = User::factory()->unverified()->create();

        $this->post(route('admin.users.resendVerification', $target))->assertRedirect();

        Notification::assertSentTo($target, VerifyEmail::class);
    }

    public function test_sending_a_password_reset_link(): void
    {
        Notification::fake();

        $this->admin();
        $target = User::factory()->create();

        $this->post(route('admin.users.passwordReset', $target))->assertRedirect();

        Notification::assertSentTo($target, ResetPassword::class);
    }

    public function test_an_admin_deletes_an_account(): void
    {
        $this->admin();
        $target = User::factory()->create();

        $this->delete(route('admin.users.destroy', $target))->assertRedirect();

        $this->assertDatabaseMissing('users', ['id' => $target->id]);
    }

    public function test_an_admin_cannot_delete_themselves(): void
    {
        $admin = $this->admin();

        $this->delete(route('admin.users.destroy', $admin))->assertForbidden();

        $this->assertDatabaseHas('users', ['id' => $admin->id]);
    }

    public function test_regular_users_cannot_reach_user_actions(): void
    {
        $this->actingAs(User::factory()->create());
        $target = User::factory()->create();

        $this->put(route('admin.users.role', $target), ['role' => 'admin'])->assertForbidden();
        $this->post(route('admin.users.suspend', $target))->assertForbidden();
        $this->delete(route('admin.users.destroy', $target))->assertForbidden();

        $this->assertSame(UserRole::User, $target->refresh()->role);
    }
}
