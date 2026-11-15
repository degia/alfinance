<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Fortify\Features;
use Tests\TestCase;

/**
 * Perilaku `POST /forgot-password`: anti-enumerasi akun, batas laju, dan
 * pesan berbahasa Indonesia.
 */
class PasswordResetRequestTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->skipUnlessFortifyHas(Features::resetPasswords());

        RateLimiter::clear('password-reset');
    }

    public function test_an_unknown_email_gets_the_same_answer_as_a_known_one(): void
    {
        Notification::fake();

        $user = User::factory()->create(['email' => 'ada@example.test']);

        $known = $this->from(route('password.request'))
            ->post(route('password.email'), ['email' => $user->email]);

        // Dibaca segera: sesi dipakai bersama antar request dalam satu test,
        // jadi permintaan berikutnya akan menimpa nilainya.
        $knownStatus = session('status');

        $unknown = $this->from(route('password.request'))
            ->post(route('password.email'), ['email' => 'tidak-ada@example.test']);

        $unknownStatus = session('status');

        // Status HTTP, tujuan redirect, dan pesan harus identik — kalau salah
        // satunya berbeda, halaman ini bisa dipakai memetakan email terdaftar.
        $this->assertSame($known->getStatusCode(), $unknown->getStatusCode());
        $this->assertSame($known->headers->get('Location'), $unknown->headers->get('Location'));
        $this->assertNotNull($knownStatus);
        $this->assertSame($knownStatus, $unknownStatus);

        $unknown->assertSessionHasNoErrors();
    }

    public function test_an_unknown_email_never_reveals_itself_through_an_error(): void
    {
        Notification::fake();

        $this->post(route('password.email'), ['email' => 'tidak-ada@example.test'])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status');

        Notification::assertNothingSent();
    }

    public function test_a_known_email_still_receives_the_reset_link(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->post(route('password.email'), ['email' => $user->email])
            ->assertSessionHas('status');

        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_the_confirmation_message_is_in_indonesian(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->post(route('password.email'), ['email' => $user->email]);

        $this->assertSame(
            'Kalau email tersebut terdaftar, kami sudah mengirim tautan untuk mengatur ulang kata sandi.',
            session('status'),
        );
    }

    public function test_a_missing_email_is_rejected_in_indonesian(): void
    {
        $this->post(route('password.email'), ['email' => ''])
            ->assertSessionHasErrors(['email' => 'email wajib diisi.']);
    }

    public function test_requests_for_one_address_are_rate_limited(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        for ($attempt = 0; $attempt < 3; $attempt++) {
            $this->post(route('password.email'), ['email' => $user->email])
                ->assertSessionHasNoErrors();
        }

        // Tanpa batas laju, endpoint ini bisa dipakai membanjiri inbox orang.
        $this->post(route('password.email'), ['email' => $user->email])
            ->assertTooManyRequests();
    }

    public function test_one_ip_cannot_sweep_many_addresses(): void
    {
        Notification::fake();

        for ($attempt = 0; $attempt < 10; $attempt++) {
            $this->post(route('password.email'), ['email' => "orang{$attempt}@example.test"])
                ->assertSessionHasNoErrors();
        }

        $this->post(route('password.email'), ['email' => 'orang-lain@example.test'])
            ->assertTooManyRequests();
    }
}
