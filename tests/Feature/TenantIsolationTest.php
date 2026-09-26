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
 * Verifikasi aturan tenant isolation yang berlaku di semua konteks.
 */
class TenantIsolationTest extends TestCase
{
    use CreatesScopedNoteTable, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpScopedNoteTable();
    }

    public function test_queries_return_nothing_without_an_active_workspace(): void
    {
        ActiveWorkspace::set(null);

        $this->assertSame(0, ScopedNote::query()->count());
    }

    public function test_without_scope_bypasses_the_filter_entirely(): void
    {
        $workspace = Workspace::factory()->create();
        ActiveWorkspace::set($workspace);
        ScopedNote::query()->create(['name' => 'Satu']);

        $total = ActiveWorkspace::withoutScope(fn (): int => ScopedNote::query()->count());

        $this->assertSame(1, $total);
        $this->assertFalse(ActiveWorkspace::isUnscoped());
    }

    public function test_queries_are_limited_to_the_active_workspace(): void
    {
        $user = User::factory()->withWorkspace('Rumah')->create();
        $other = Workspace::factory()->create(['owner_id' => $user->id]);

        ActiveWorkspace::set($user->workspaces()->sole());
        $mine = ScopedNote::query()->create(['name' => 'Catatan saya']);

        ActiveWorkspace::set($other);
        ScopedNote::query()->create(['name' => 'Catatan lain']);

        $this->assertSame(1, ScopedNote::query()->count());
        $this->assertSame('Catatan lain', ScopedNote::query()->sole()->name);

        ActiveWorkspace::set($user->workspaces()->sole());
        $this->assertSame(1, ScopedNote::query()->count());
        $this->assertSame('Catatan saya', ScopedNote::query()->sole()->name);
    }

    public function test_all_workspaces_bypasses_the_scope_for_jobs(): void
    {
        $mine = $user = User::factory()->withWorkspace('Rumah')->create()->workspaces()->sole();
        $other = Workspace::factory()->create();
        ActiveWorkspace::set($mine);

        ScopedNote::query()->create(['name' => 'Satu']);
        ActiveWorkspace::withoutScope(fn () => ScopedNote::query()->create([
            'workspace_id' => $other->id,
            'name' => 'Dua',
        ]));

        $this->assertSame(2, ScopedNote::allWorkspaces()->count());
        $this->assertSame(1, ScopedNote::query()->count());
    }

    public function test_workspace_id_is_filled_from_the_active_workspace(): void
    {
        $workspace = Workspace::factory()->create();
        ActiveWorkspace::set($workspace);

        $note = ScopedNote::query()->create(['name' => 'Otomatis']);

        $this->assertSame($workspace->id, $note->workspace_id);
    }

    public function test_web_requests_do_not_leak_rows_between_workspaces(): void
    {
        $user = User::factory()->withWorkspace('Rumah')->create();
        $other = Workspace::factory()->create(['owner_id' => $user->id]);
        $other->addUser($user, WorkspaceRole::Admin);

        $first = $user->workspaces()->orderBy('id')->first();

        // Dua workspace tanpa session: user diarahkan ke halaman pemilihan.
        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertRedirect(route('workspaces.index'));

        $this->actingAs($user)
            ->withSession(['workspace_id' => $first->id])
            ->get(route('dashboard'))
            ->assertOk();

        $this->post(route('workspaces.switch', $other))
            ->assertRedirect(route('dashboard'));

        $this->assertSame($other->id, session('workspace_id'));

        // Switching kembali ke workspace pertama mengembalikan session awal.
        $this->post(route('workspaces.switch', $first))
            ->assertRedirect(route('dashboard'));

        $this->assertSame($first->id, session('workspace_id'));
    }

    public function test_a_workspace_removed_from_membership_falls_back_to_selection(): void
    {
        $user = User::factory()->withWorkspace('Rumah')->create();
        $workspace = $user->workspaces()->sole();

        $this->actingAs($user)
            ->withSession(['workspace_id' => $workspace->id])
            ->get(route('dashboard'))
            ->assertOk();

        $workspace->removeUser($user);

        $this->get(route('dashboard'))->assertRedirect(route('workspaces.index'));
    }
}
