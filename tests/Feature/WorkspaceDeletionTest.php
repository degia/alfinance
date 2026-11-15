<?php

namespace Tests\Feature;

use App\Enums\WorkspaceRole;
use App\Models\Account;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkspaceDeletionTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_moves_a_workspace_to_trash(): void
    {
        $user = User::factory()->withWorkspace('Rumah Tangga')->create();
        $workspace = $user->workspaces()->sole();

        $this->actingAs($user);

        $this->delete(route('workspaces.destroy', $workspace))
            ->assertRedirect(route('workspaces.index'));

        $this->assertSoftDeleted($workspace);
    }

    public function test_trashed_workspaces_disappear_from_the_active_list(): void
    {
        $user = User::factory()->withWorkspace('Rumah Tangga')->create();
        $workspace = $user->workspaces()->sole();

        $this->actingAs($user);
        $this->delete(route('workspaces.destroy', $workspace));

        $this->get(route('workspaces.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('workspaces/Select')
                ->where('workspaces', [])
                ->has('trashed', 1)
                ->where('trashed.0.name', 'Rumah Tangga'));
    }

    public function test_deleting_the_active_workspace_clears_the_session(): void
    {
        $user = User::factory()->withWorkspace('Rumah Tangga')->create();
        $workspace = $user->workspaces()->sole();

        $this->actingAs($user)->withSession(['workspace_id' => $workspace->id]);

        $this->delete(route('workspaces.destroy', $workspace));

        $this->assertNull(session('workspace_id'));
    }

    public function test_non_owner_members_cannot_delete_a_workspace(): void
    {
        $workspace = Workspace::factory()->create();
        $member = User::factory()->create();
        $workspace->addUser($member, WorkspaceRole::Admin);

        $this->actingAs($member);

        $this->delete(route('workspaces.destroy', $workspace))->assertForbidden();

        $this->assertNotSoftDeleted($workspace);
    }

    public function test_owner_restores_a_workspace_from_trash(): void
    {
        $user = User::factory()->withWorkspace('Rumah Tangga')->create();
        $workspace = $user->workspaces()->sole();

        $this->actingAs($user);
        $this->delete(route('workspaces.destroy', $workspace));

        $this->post(route('workspaces.restore', $workspace))
            ->assertRedirect(route('workspaces.index'));

        $this->assertNotSoftDeleted($workspace);
    }

    public function test_global_admins_can_restore_a_workspace_they_do_not_own(): void
    {
        $owner = User::factory()->withWorkspace('Rumah Tangga')->create();
        $workspace = $owner->workspaces()->sole();
        $workspace->delete();

        $this->actingAs(User::factory()->admin()->create());

        $this->post(route('workspaces.restore', $workspace))->assertRedirect();

        $this->assertNotSoftDeleted($workspace);
    }

    public function test_force_delete_removes_the_workspace_and_its_tenant_data(): void
    {
        $user = User::factory()->withWorkspace('Rumah Tangga')->create();
        $workspace = $user->workspaces()->sole();

        Account::factory()->create(['workspace_id' => $workspace->id]);

        $this->actingAs($user);
        $this->delete(route('workspaces.destroy', $workspace));

        $this->delete(route('workspaces.forceDestroy', $workspace))
            ->assertRedirect(route('workspaces.index'));

        $this->assertDatabaseMissing('workspaces', ['id' => $workspace->id]);
        $this->assertDatabaseMissing('accounts', ['workspace_id' => $workspace->id]);
        $this->assertDatabaseMissing('workspace_user', ['workspace_id' => $workspace->id]);
    }

    public function test_force_delete_requires_the_workspace_to_be_in_trash_first(): void
    {
        $user = User::factory()->withWorkspace('Rumah Tangga')->create();
        $workspace = $user->workspaces()->sole();

        $this->actingAs($user);

        $this->delete(route('workspaces.forceDestroy', $workspace))->assertRedirect();

        $this->assertDatabaseHas('workspaces', ['id' => $workspace->id]);
    }

    public function test_outsiders_cannot_delete_a_workspace(): void
    {
        $workspace = Workspace::factory()->create();

        $this->actingAs(User::factory()->create());

        $this->delete(route('workspaces.destroy', $workspace))->assertForbidden();
        $this->post(route('workspaces.restore', $workspace))->assertForbidden();
    }

    public function test_a_trashed_workspace_is_never_selected_as_the_active_one(): void
    {
        $user = User::factory()->withWorkspace('Rumah Tangga')->create();
        $workspace = $user->workspaces()->sole();
        $workspace->delete();

        $this->actingAs($user)->withSession(['workspace_id' => $workspace->id]);

        // Tanpa workspace aktif, halaman bertenant harus mengarahkan kembali
        // ke pemilihan workspace, bukan menampilkan data workspace terhapus.
        $this->get(route('dashboard'))->assertRedirect(route('workspaces.index'));
    }

    public function test_deleting_an_account_force_deletes_its_sole_owned_workspace(): void
    {
        $user = User::factory()->withWorkspace('Rumah Tangga')->create();
        $workspace = $user->workspaces()->sole();
        $workspace->delete();

        $user->delete();

        // Soft delete tidak melepas FK `workspaces.owner_id`, jadi workspace di
        // Sampah pun harus ikut dibuang agar akun benar-benar bisa dihapus.
        $this->assertDatabaseMissing('workspaces', ['id' => $workspace->id]);
        $this->assertDatabaseMissing('users', ['id' => $user->id]);
    }
}
