<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SidebarPreferenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_cannot_update_the_preference(): void
    {
        $this->post(route('settings.sidebar'), ['collapsed' => true])->assertRedirect(route('login'));
    }

    public function test_the_preference_is_persisted_to_the_user(): void
    {
        $user = User::factory()->withWorkspace()->create(['sidebar_collapsed' => false]);
        $this->actingAs($user);

        $this->post(route('settings.sidebar'), ['collapsed' => true])
            ->assertOk()
            ->assertExactJson(['collapsed' => true]);

        $this->assertTrue($user->fresh()->sidebar_collapsed);
    }

    public function test_the_preference_can_be_expanded_again(): void
    {
        $user = User::factory()->withWorkspace()->create(['sidebar_collapsed' => true]);
        $this->actingAs($user);

        $this->post(route('settings.sidebar'), ['collapsed' => false])->assertOk();

        $this->assertFalse($user->fresh()->sidebar_collapsed);
    }

    public function test_the_collapsed_flag_is_required(): void
    {
        $user = User::factory()->withWorkspace()->create();
        $this->actingAs($user);

        $this->postJson(route('settings.sidebar'), [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('collapsed');
    }

    public function test_the_preference_is_shared_with_the_layout(): void
    {
        $user = User::factory()->withWorkspace()->create(['sidebar_collapsed' => true]);
        $this->actingAs($user);

        $this->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('sidebarCollapsed', true));
    }

    public function test_the_preference_is_per_user(): void
    {
        $first = User::factory()->withWorkspace()->create(['sidebar_collapsed' => true]);
        $second = User::factory()->withWorkspace()->create(['sidebar_collapsed' => false]);

        $this->actingAs($first)
            ->post(route('settings.sidebar'), ['collapsed' => false])
            ->assertOk();

        $this->assertFalse($first->fresh()->sidebar_collapsed);
        $this->assertFalse($second->fresh()->sidebar_collapsed);
    }
}
