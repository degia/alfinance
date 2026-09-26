<?php

namespace Tests\Feature;

use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkspaceSelectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get(route('workspaces.index'))->assertRedirect(route('login'));
    }

    public function test_unverified_users_cannot_manage_workspaces(): void
    {
        $this->actingAs(User::factory()->unverified()->create());

        $this->get(route('workspaces.index'))->assertRedirect(route('verification.notice'));
    }

    public function test_users_without_workspace_see_an_empty_selection_page(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get(route('workspaces.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('workspaces/Select')
                ->where('workspaces', []));
    }

    public function test_users_see_their_workspaces_with_role_and_membership_count(): void
    {
        $user = User::factory()->withWorkspace('Rumah Tangga', WorkspaceRole::Owner)->create();
        $other = Workspace::factory()->create(['name' => 'Warung']);
        $other->addUser($user, WorkspaceRole::Member);

        $this->actingAs($user);

        $this->get(route('workspaces.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('workspaces/Select')
                ->has('workspaces', 2)
                ->where('workspaces.0.name', 'Rumah Tangga')
                ->where('workspaces.0.role', 'owner')
                ->where('workspaces.0.is_owner', true)
                ->where('workspaces.0.members_count', 1)
                ->where('workspaces.1.name', 'Warung')
                ->where('workspaces.1.role', 'member')
                ->where('workspaces.1.is_owner', false));
    }

    public function test_creating_a_workspace_makes_the_user_the_owner_and_activates_it(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $this->post(route('workspaces.store'), ['name' => 'Usaha Kopi'])
            ->assertRedirect(route('dashboard'));

        $workspace = Workspace::sole();

        $this->assertTrue($workspace->owner->is($user));
        $this->assertTrue($user->hasWorkspaceRole($workspace, WorkspaceRole::Owner));
        $this->assertSame($workspace->id, session('workspace_id'));
    }

    public function test_workspace_name_must_be_unique_per_user(): void
    {
        $user = User::factory()->withWorkspace('Rumah Tangga')->create();
        $this->actingAs($user);

        $this->from(route('workspaces.index'))
            ->post(route('workspaces.store'), ['name' => 'Rumah Tangga'])
            ->assertRedirect(route('workspaces.index'))
            ->assertSessionHasErrors('name');

        $this->assertSame(1, $user->workspaces()->count());
    }

    public function test_the_same_workspace_name_may_be_used_by_another_user(): void
    {
        User::factory()->withWorkspace('Rumah Tangga')->create();
        $other = User::factory()->create();
        $this->actingAs($other);

        $this->post(route('workspaces.store'), ['name' => 'Rumah Tangga'])
            ->assertRedirect(route('dashboard'));

        $this->assertSame(2, Workspace::count());
    }

    public function test_users_can_switch_to_a_workspace_they_belong_to(): void
    {
        $user = User::factory()->withWorkspace('Rumah Tangga')->create();
        $second = Workspace::factory()->create(['name' => 'Warung', 'owner_id' => $user->id]);
        $second->addUser($user, WorkspaceRole::Admin);

        $this->actingAs($user);

        $this->post(route('workspaces.switch', $second))
            ->assertRedirect(route('dashboard'));

        $this->assertSame($second->id, session('workspace_id'));
    }

    public function test_users_cannot_switch_to_a_workspace_they_do_not_belong_to(): void
    {
        $user = User::factory()->withWorkspace('Rumah Tangga')->create();
        $foreign = Workspace::factory()->create();

        $this->actingAs($user);

        $this->post(route('workspaces.switch', $foreign))->assertForbidden();
        $this->assertNotSame($foreign->id, session('workspace_id'));
    }

    public function test_members_can_leave_workspace(): void
    {
        $owner = User::factory()->create();
        $workspace = Workspace::factory()->create(['owner_id' => $owner->id]);
        $workspace->addUser($owner, WorkspaceRole::Owner);

        $member = User::factory()->create();
        $workspace->addUser($member, WorkspaceRole::Member);

        $this->actingAs($member);

        $this->delete(route('workspaces.leave', $workspace))
            ->assertRedirect(route('workspaces.index'));

        $this->assertFalse($workspace->isMember($member));
    }

    public function test_owners_cannot_leave_their_own_workspace(): void
    {
        $owner = User::factory()->withWorkspace()->create();
        $workspace = $owner->workspaces()->sole();

        $this->actingAs($owner);

        $this->delete(route('workspaces.leave', $workspace))->assertRedirect();

        $this->assertTrue($workspace->isMember($owner));
    }

    public function test_leaving_workspace_clears_the_active_workspace(): void
    {
        $owner = User::factory()->create();
        $workspace = Workspace::factory()->create(['owner_id' => $owner->id]);
        $workspace->addUser($owner, WorkspaceRole::Owner);

        $member = User::factory()->create();
        $workspace->addUser($member, WorkspaceRole::Member);

        $this->actingAs($member)
            ->withSession(['workspace_id' => $workspace->id])
            ->delete(route('workspaces.leave', $workspace))
            ->assertRedirect(route('workspaces.index'));

        $this->assertNull(session('workspace_id'));
    }
}
