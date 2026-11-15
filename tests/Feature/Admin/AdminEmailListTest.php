<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminEmailListTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $admin = User::factory()->admin()->create(['name' => 'Zulu', 'email' => 'zulu@example.test']);

        $this->actingAs($admin);

        return $admin;
    }

    public function test_the_list_contains_every_user(): void
    {
        $this->admin();
        User::factory()->create(['name' => 'Andi', 'email' => 'andi@example.test']);

        $this->get(route('admin.emails.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('admin/Emails')
                ->has('emails', 2)
                ->where('emails.0.email', 'andi@example.test')
                ->where('segment', 'all'));
    }

    public function test_the_list_can_be_narrowed_to_unverified_addresses(): void
    {
        $this->admin();
        User::factory()->unverified()->create(['email' => 'baru@example.test']);

        $this->get(route('admin.emails.index', ['segment' => 'unverified']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('emails', 1)
                ->where('emails.0.email', 'baru@example.test')
                ->where('emails.0.is_verified', false));
    }

    public function test_an_unknown_segment_is_rejected(): void
    {
        $this->admin();

        $this->get(route('admin.emails.index', ['segment' => 'semua-orang']))
            ->assertSessionHasErrors('segment');
    }

    public function test_the_csv_export_streams_every_address(): void
    {
        $this->admin();
        User::factory()->create(['name' => 'Andi', 'email' => 'andi@example.test']);

        $response = $this->get(route('admin.emails.export'));

        $response->assertOk();
        $response->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $csv = $response->streamedContent();

        $this->assertStringContainsString('andi@example.test', $csv);
        $this->assertStringContainsString('zulu@example.test', $csv);
    }

    public function test_regular_users_cannot_export_the_address_list(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get(route('admin.emails.export'))->assertForbidden();
    }
}
