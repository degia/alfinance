<?php

namespace Tests\Feature;

use App\Enums\WorkspaceRole;
use App\Models\Tag;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TagsTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get(route('tags.index'))->assertRedirect(route('login'));
    }

    public function test_users_see_an_empty_tag_list(): void
    {
        $this->signedIn();

        $this->get(route('tags.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('tags/Index')->where('tags', []));
    }

    public function test_tags_are_listed_alphabetically(): void
    {
        $user = $this->signedIn();
        $workspace = $user->workspaces()->sole();

        Tag::factory()->forWorkspace($workspace)->create(['name' => 'Liburan']);
        Tag::factory()->forWorkspace($workspace)->create(['name' => 'Anak']);

        $this->get(route('tags.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('tags', 2)
                ->where('tags.0.name', 'Anak')
                ->where('tags.1.name', 'Liburan'));
    }

    public function test_members_can_create_and_rename_tags(): void
    {
        $this->signedIn();

        $this->post(route('tags.store'), ['name' => 'liburan'])
            ->assertRedirect(route('tags.index'));

        $tag = Tag::allWorkspaces()->sole();
        $this->assertSame('liburan', $tag->name);

        $this->put(route('tags.update', $tag), ['name' => 'Liburan Keluarga'])
            ->assertRedirect(route('tags.index'));

        $this->assertSame('Liburan Keluarga', $tag->fresh()->name);
    }

    public function test_tags_can_be_deleted(): void
    {
        $user = $this->signedIn();
        $workspace = $user->workspaces()->sole();
        $tag = Tag::factory()->forWorkspace($workspace)->create();

        $this->delete(route('tags.destroy', $tag))
            ->assertRedirect(route('tags.index'));

        $this->assertSame(0, Tag::allWorkspaces()->count());
    }

    public function test_names_are_unique_per_workspace(): void
    {
        $user = $this->signedIn();
        $workspace = $user->workspaces()->sole();
        Tag::factory()->forWorkspace($workspace)->create(['name' => 'Liburan']);

        $this->from(route('tags.index'))
            ->post(route('tags.store'), ['name' => 'Liburan'])
            ->assertSessionHasErrors('name');

        $this->assertSame(1, Tag::allWorkspaces()->count());
    }

    public function test_a_tag_can_keep_its_own_name_when_updated(): void
    {
        $user = $this->signedIn();
        $workspace = $user->workspaces()->sole();
        $tag = Tag::factory()->forWorkspace($workspace)->create(['name' => 'Liburan']);

        $this->from(route('tags.index'))
            ->put(route('tags.update', $tag), ['name' => 'Liburan'])
            ->assertRedirect(route('tags.index'))
            ->assertSessionHasNoErrors();

        $this->assertSame(1, Tag::allWorkspaces()->count());
    }

    public function test_the_same_name_is_allowed_in_another_workspace(): void
    {
        $user = $this->signedIn();
        $other = Workspace::factory()->create();
        $other->addUser($user, WorkspaceRole::Admin);
        Tag::factory()->forWorkspace($other)->create(['name' => 'Liburan']);

        $this->post(route('tags.store'), ['name' => 'Liburan'])
            ->assertRedirect(route('tags.index'));

        $this->assertSame(2, Tag::allWorkspaces()->count());
    }

    public function test_names_are_length_limited(): void
    {
        $this->signedIn();

        $this->from(route('tags.index'))
            ->post(route('tags.store'), ['name' => 'a'])
            ->assertSessionHasErrors('name');

        $this->from(route('tags.index'))
            ->post(route('tags.store'), ['name' => str_repeat('a', 31)])
            ->assertSessionHasErrors('name');

        $this->assertSame(0, Tag::allWorkspaces()->count());
    }

    public function test_tags_from_another_workspace_are_not_reachable(): void
    {
        $this->signedIn();
        $foreign = Tag::factory()->create(['name' => 'Milik Orang']);

        $this->put(route('tags.update', $foreign), ['name' => 'Diretas'])->assertNotFound();
        $this->delete(route('tags.destroy', $foreign))->assertNotFound();

        $this->assertSame('Milik Orang', $foreign->fresh()->name);
        $this->assertSame(1, Tag::allWorkspaces()->count());
    }

    public function test_viewers_can_read_but_not_write_tags(): void
    {
        $owner = User::factory()->create();
        $workspace = Workspace::factory()->create(['owner_id' => $owner->id]);
        $workspace->addUser($owner, WorkspaceRole::Owner);

        $viewer = User::factory()->create();
        $workspace->addUser($viewer, WorkspaceRole::Viewer);

        $tag = Tag::factory()->forWorkspace($workspace)->create();

        $this->actingAs($viewer)
            ->withSession(['workspace_id' => $workspace->id])
            ->get(route('tags.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('tags', 1));

        $this->post(route('tags.store'), ['name' => 'Baru'])->assertForbidden();
        $this->put(route('tags.update', $tag), ['name' => 'Baru'])->assertForbidden();
        $this->delete(route('tags.destroy', $tag))->assertForbidden();

        $this->assertSame(1, Tag::allWorkspaces()->count());
    }

    public function test_tags_require_an_active_workspace(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('tags.index'))
            ->assertRedirect(route('workspaces.index'));
    }

    private function signedIn(): User
    {
        $user = User::factory()->withWorkspace('Rumah Tangga', WorkspaceRole::Owner)->create();

        $this->actingAs($user)
            ->withSession(['workspace_id' => $user->workspaces()->sole()->id]);

        return $user;
    }
}
