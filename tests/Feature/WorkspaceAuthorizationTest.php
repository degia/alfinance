<?php

namespace Tests\Feature;

use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Workspace\ActiveWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesScopedNoteTable;
use Tests\Fixtures\ScopedNote;
use Tests\TestCase;

/**
 * Otorisasi role workspace + isolasi data antar workspace (ARCHITECTURE.md §5).
 */
class WorkspaceAuthorizationTest extends TestCase
{
    use CreatesScopedNoteTable, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpScopedNoteTable();
    }

    public function test_role_capabilities_follow_the_workspace_pivot(): void
    {
        $user = User::factory()->create();
        $owner = Workspace::factory()->create(['owner_id' => $user->id]);
        $admin = Workspace::factory()->create(['owner_id' => $user->id]);
        $member = Workspace::factory()->create(['owner_id' => $user->id]);
        $viewer = Workspace::factory()->create(['owner_id' => $user->id]);

        $owner->addUser($user, WorkspaceRole::Owner);
        $admin->addUser($user, WorkspaceRole::Admin);
        $member->addUser($user, WorkspaceRole::Member);
        $viewer->addUser($user, WorkspaceRole::Viewer);

        $this->assertTrue($user->can('editData', $owner));
        $this->assertTrue($user->can('editData', $admin));
        $this->assertTrue($user->can('editData', $member));
        $this->assertFalse($user->can('editData', $viewer));

        $this->assertTrue($user->can('manageMembers', $admin));
        $this->assertFalse($user->can('manageMembers', $member));

        $this->assertTrue($user->can('delete', $owner));
        $this->assertFalse($user->can('delete', $admin));
    }

    public function test_non_members_have_no_access_at_all(): void
    {
        $user = User::factory()->create();
        $foreign = Workspace::factory()->create();

        $this->assertFalse($user->can('view', $foreign));
        $this->assertFalse($user->can('editData', $foreign));
        $this->assertFalse($user->can('manageMembers', $foreign));
        $this->assertFalse($user->can('update', $foreign));
    }

    public function test_the_role_comes_from_the_workspace_pivot_not_a_global_attribute(): void
    {
        $user = User::factory()->create();
        $first = Workspace::factory()->create(['owner_id' => $user->id]);
        $second = Workspace::factory()->create(['owner_id' => $user->id]);

        $first->addUser($user, WorkspaceRole::Owner);
        $second->addUser($user, WorkspaceRole::Viewer);

        $this->assertSame(WorkspaceRole::Owner, $user->roleIn($first));
        $this->assertSame(WorkspaceRole::Viewer, $user->roleIn($second));
        $this->assertTrue($user->hasWorkspaceRole($first, WorkspaceRole::Owner));
        $this->assertTrue($user->hasWorkspaceRole($second, WorkspaceRole::Viewer));
        $this->assertFalse($user->hasWorkspaceRole($second, WorkspaceRole::Admin));
    }

    public function test_data_of_other_workspaces_is_never_returned(): void
    {
        $mine = Workspace::factory()->create();
        $theirs = Workspace::factory()->create();

        ActiveWorkspace::set($mine);
        ScopedNote::query()->create(['name' => 'Rahasia saya']);
        ScopedNote::query()->create(['name' => 'Rahasia lain']);

        // Data "mereka" sengaja ditulis tanpa global scope.
        ActiveWorkspace::withoutScope(fn () => ScopedNote::query()->create([
            'workspace_id' => $theirs->id,
            'name' => 'Data workspace lain',
        ]));

        $names = ScopedNote::query()->pluck('name')->all();

        $this->assertCount(2, $names);
        $this->assertNotContains('Data workspace lain', $names);
    }

    public function test_deleting_an_account_hands_over_owned_workspaces_to_an_admin(): void
    {
        $owner = User::factory()->create();
        $workspace = Workspace::factory()->create(['owner_id' => $owner->id]);
        $workspace->addUser($owner, WorkspaceRole::Owner);

        $admin = User::factory()->create();
        $workspace->addUser($admin, WorkspaceRole::Admin);

        $member = User::factory()->create();
        $workspace->addUser($member, WorkspaceRole::Member);

        $owner->delete();

        $workspace->refresh();

        $this->assertTrue($workspace->exists());
        $this->assertSame($admin->id, $workspace->owner_id);
        $this->assertSame(WorkspaceRole::Owner, $workspace->roleFor($admin));
        $this->assertTrue($workspace->isMember($member));
    }

    public function test_deleting_an_account_removes_workspaces_without_other_members(): void
    {
        $owner = User::factory()->create();
        $workspace = Workspace::factory()->create(['owner_id' => $owner->id]);
        $workspace->addUser($owner, WorkspaceRole::Owner);

        $owner->delete();

        $this->assertDatabaseMissing('workspaces', ['id' => $workspace->id]);
        $this->assertDatabaseMissing('workspace_user', ['workspace_id' => $workspace->id]);
    }
}
