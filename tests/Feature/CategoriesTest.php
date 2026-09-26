<?php

namespace Tests\Feature;

use App\Enums\CategoryIcon;
use App\Enums\WorkspaceRole;
use App\Models\Category;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CategoriesTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get(route('categories.index'))->assertRedirect(route('login'));
    }

    public function test_users_see_an_empty_category_tree(): void
    {
        $this->signedIn();

        $this->get(route('categories.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('categories/Index')
                ->where('categories', [])
                ->where('parents', [])
                ->has('icons'));
    }

    public function test_the_tree_nests_children_under_their_parent(): void
    {
        $user = $this->signedIn();
        $workspace = $user->workspaces()->sole();

        $parent = Category::factory()->forWorkspace($workspace)->create(['name' => 'Kebutuhan Pokok']);
        Category::factory()->forWorkspace($workspace)->childOf($parent)->create(['name' => 'Sayur']);
        $other = Category::factory()->forWorkspace($workspace)->create(['name' => 'Penghasilan']);

        $this->get(route('categories.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('categories', 2)
                ->where('categories.0.name', 'Kebutuhan Pokok')
                ->has('categories.0.children', 1)
                ->where('categories.0.children.0.name', 'Sayur')
                ->where('categories.0.children.0.parent_id', $parent->id)
                ->where('categories.1.name', 'Penghasilan')
                ->has('categories.1.children', 0));
    }

    public function test_members_can_create_a_root_category(): void
    {
        $this->signedIn();

        $this->post(route('categories.store'), [
            'name' => 'Kebutuhan Pokok',
            'parent_id' => '',
            'icon' => CategoryIcon::Shopping->value,
            'color' => '#059669',
        ])->assertRedirect(route('categories.index'));

        $category = Category::allWorkspaces()->sole();

        $this->assertSame('Kebutuhan Pokok', $category->name);
        $this->assertNull($category->parent_id);
        $this->assertSame(CategoryIcon::Shopping, $category->icon);
        // Warna dinormalisasi ke huruf kecil supaya badge konsisten.
        $this->assertSame('#059669', $category->color);
    }

    public function test_members_can_create_a_sub_category(): void
    {
        $user = $this->signedIn();
        $workspace = $user->workspaces()->sole();
        $parent = Category::factory()->forWorkspace($workspace)->create();

        $this->post(route('categories.store'), [
            'name' => 'Sayur Mayur',
            'parent_id' => $parent->id,
            'icon' => CategoryIcon::Food->value,
            'color' => '#22c55e',
        ])->assertRedirect(route('categories.index'));

        $child = Category::allWorkspaces()->whereNotNull('parent_id')->sole();

        $this->assertTrue($child->isChild());
        $this->assertSame($parent->id, $child->parent_id);
    }

    public function test_categories_cannot_be_nested_deeper_than_two_levels(): void
    {
        $user = $this->signedIn();
        $workspace = $user->workspaces()->sole();
        $parent = Category::factory()->forWorkspace($workspace)->create();
        $child = Category::factory()->forWorkspace($workspace)->childOf($parent)->create();

        $this->from(route('categories.index'))
            ->post(route('categories.store'), [
                'name' => 'Cucu',
                'parent_id' => $child->id,
                'icon' => CategoryIcon::Other->value,
                'color' => '#2563eb',
            ])
            ->assertSessionHasErrors('parent_id');

        $this->assertSame(2, Category::allWorkspaces()->count());
    }

    public function test_a_category_cannot_be_its_own_parent(): void
    {
        $user = $this->signedIn();
        $workspace = $user->workspaces()->sole();
        $category = Category::factory()->forWorkspace($workspace)->create();

        $this->from(route('categories.index'))
            ->put(route('categories.update', $category), [
                'name' => $category->name,
                'parent_id' => $category->id,
                'icon' => $category->icon->value,
                'color' => $category->color,
            ])
            ->assertSessionHasErrors('parent_id');

        $this->assertNull($category->fresh()->parent_id);
    }

    public function test_parents_from_another_workspace_are_rejected(): void
    {
        $user = $this->signedIn();
        $workspace = $user->workspaces()->sole();
        $foreignParent = Category::factory()->create();

        $this->from(route('categories.index'))
            ->post(route('categories.store'), [
                'name' => 'Anak Asing',
                'parent_id' => $foreignParent->id,
                'icon' => CategoryIcon::Other->value,
                'color' => '#2563eb',
            ])
            ->assertSessionHasErrors('parent_id');

        $this->assertSame(
            0,
            Category::allWorkspaces()->where('workspace_id', $workspace->id)->count(),
        );
        $this->assertSame(1, Category::allWorkspaces()->count());
    }

    public function test_names_are_unique_per_level(): void
    {
        $user = $this->signedIn();
        $workspace = $user->workspaces()->sole();
        $parent = Category::factory()->forWorkspace($workspace)->create(['name' => 'Transportasi']);

        Category::factory()->forWorkspace($workspace)->create(['name' => 'Bensin']);
        Category::factory()->forWorkspace($workspace)->childOf($parent)->create(['name' => 'Ojek']);

        // Nama yang sama di level berbeda tetap boleh dipakai.
        Category::factory()->forWorkspace($workspace)->childOf($parent)->create(['name' => 'Bensin']);

        $this->from(route('categories.index'))
            ->post(route('categories.store'), [
                'name' => 'Bensin',
                'parent_id' => '',
                'icon' => CategoryIcon::Transport->value,
                'color' => '#2563eb',
            ])
            ->assertSessionHasErrors('name');

        $this->from(route('categories.index'))
            ->post(route('categories.store'), [
                'name' => 'Ojek',
                'parent_id' => $parent->id,
                'icon' => CategoryIcon::Transport->value,
                'color' => '#2563eb',
            ])
            ->assertSessionHasErrors('name');

        $this->assertSame(4, Category::allWorkspaces()->count());
    }

    public function test_the_same_name_is_allowed_in_another_workspace(): void
    {
        $user = $this->signedIn();
        $other = Workspace::factory()->create();
        $other->addUser($user, WorkspaceRole::Admin);
        Category::factory()->forWorkspace($other)->create(['name' => 'Bensin']);

        $this->post(route('categories.store'), [
            'name' => 'Bensin',
            'parent_id' => '',
            'icon' => CategoryIcon::Transport->value,
            'color' => '#2563eb',
        ])->assertRedirect(route('categories.index'));

        $this->assertSame(2, Category::allWorkspaces()->count());
    }

    public function test_unknown_icons_and_malformed_colors_are_rejected(): void
    {
        $this->signedIn();

        $this->from(route('categories.index'))
            ->post(route('categories.store'), [
                'name' => 'Makanan',
                'parent_id' => '',
                'icon' => 'emoji-rocket',
                'color' => 'hijau',
            ])
            ->assertSessionHasErrors(['icon', 'color']);

        $this->assertSame(0, Category::allWorkspaces()->count());
    }

    public function test_updating_a_category_moves_it_under_another_parent(): void
    {
        $user = $this->signedIn();
        $workspace = $user->workspaces()->sole();
        $category = Category::factory()->forWorkspace($workspace)->create(['name' => 'Lama']);
        $newParent = Category::factory()->forWorkspace($workspace)->create(['name' => 'Baru']);

        $this->put(route('categories.update', $category), [
            'name' => 'Lama',
            'parent_id' => $newParent->id,
            'icon' => $category->icon->value,
            'color' => '#7C3AED',
        ])->assertRedirect(route('categories.index'));

        $this->assertSame($newParent->id, $category->fresh()->parent_id);
        $this->assertSame('#7c3aed', $category->fresh()->color);
    }

    public function test_a_parent_with_children_cannot_be_deleted(): void
    {
        $user = $this->signedIn();
        $workspace = $user->workspaces()->sole();
        $parent = Category::factory()->forWorkspace($workspace)->create();
        Category::factory()->forWorkspace($workspace)->childOf($parent)->create();

        $this->from(route('categories.index'))
            ->delete(route('categories.destroy', $parent))
            ->assertRedirect(route('categories.index'))
            ->assertSessionHas('inertia.flash_data.toast', fn ($toast): bool => $toast['type'] === 'error');

        $this->assertSame(2, Category::allWorkspaces()->count());
        $this->assertTrue(
            Category::allWorkspaces()->where('parent_id', $parent->id)->exists(),
            'Sub-kategori harus tetap ada karena induk tidak boleh dihapus.',
        );
    }

    public function test_a_child_category_can_be_deleted(): void
    {
        $user = $this->signedIn();
        $workspace = $user->workspaces()->sole();
        $parent = Category::factory()->forWorkspace($workspace)->create();
        $child = Category::factory()->forWorkspace($workspace)->childOf($parent)->create();

        $this->delete(route('categories.destroy', $child))
            ->assertRedirect(route('categories.index'));

        $this->assertSame(1, Category::allWorkspaces()->count());
    }

    public function test_categories_from_another_workspace_are_not_reachable(): void
    {
        $this->signedIn();
        $foreign = Category::factory()->create(['name' => 'Milik Orang']);

        $this->put(route('categories.update', $foreign), [
            'name' => 'Diretas',
            'parent_id' => '',
            'icon' => $foreign->icon->value,
            'color' => '#2563eb',
        ])->assertNotFound();

        $this->delete(route('categories.destroy', $foreign))->assertNotFound();

        $this->assertSame('Milik Orang', $foreign->fresh()->name);
    }

    public function test_viewers_can_read_but_not_write_categories(): void
    {
        $owner = User::factory()->create();
        $workspace = Workspace::factory()->create(['owner_id' => $owner->id]);
        $workspace->addUser($owner, WorkspaceRole::Owner);

        $viewer = User::factory()->create();
        $workspace->addUser($viewer, WorkspaceRole::Viewer);

        $category = Category::factory()->forWorkspace($workspace)->create();

        $this->actingAs($viewer)
            ->withSession(['workspace_id' => $workspace->id])
            ->get(route('categories.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('categories', 1));

        $this->post(route('categories.store'), [
            'name' => 'Baru',
            'parent_id' => '',
            'icon' => CategoryIcon::Other->value,
            'color' => '#2563eb',
        ])->assertForbidden();

        $this->put(route('categories.update', $category), [
            'name' => 'Baru',
            'parent_id' => '',
            'icon' => CategoryIcon::Other->value,
            'color' => '#2563eb',
        ])->assertForbidden();

        $this->delete(route('categories.destroy', $category))->assertForbidden();

        $this->assertSame(1, Category::allWorkspaces()->count());
    }

    public function test_categories_require_an_active_workspace(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('categories.index'))
            ->assertRedirect(route('workspaces.index'));
    }

    public function test_parents_payload_only_lists_root_categories(): void
    {
        $user = $this->signedIn();
        $workspace = $user->workspaces()->sole();
        $root = Category::factory()->forWorkspace($workspace)->create(['name' => 'Akar']);
        Category::factory()->forWorkspace($workspace)->childOf($root)->create(['name' => 'Anak']);

        $this->get(route('categories.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('parents', 1)
                ->where('parents.0.name', 'Akar'));
    }

    private function signedIn(): User
    {
        $user = User::factory()->withWorkspace('Rumah Tangga', WorkspaceRole::Owner)->create();

        $this->actingAs($user)
            ->withSession(['workspace_id' => $user->workspaces()->sole()->id]);

        return $user;
    }
}
