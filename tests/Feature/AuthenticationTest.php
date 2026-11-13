<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_screen_renders_with_language_toggle(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertSee('Welcome back')
            ->assertSee(route('locale.switch', 'es'));
    }

    public function test_login_screen_renders_in_spanish_from_cookie(): void
    {
        $this->withUnencryptedCookie('locale', 'es')
            ->get('/login')
            ->assertOk()
            ->assertSee('Iniciar sesión')
            ->assertSee('lang="es"', false);
    }

    public function test_staff_signs_in_and_lands_on_today(): void
    {
        $user = User::factory()->create();

        $this->post('/login', ['email' => $user->email, 'password' => 'password123'])
            ->assertRedirect(route('home'));
        $this->assertAuthenticatedAs($user);
        $this->get('/')->assertRedirect(route('jobs.today'));
        $this->assertNotNull($user->fresh()->last_login_at);
    }

    public function test_admin_lands_on_dashboard(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get('/')->assertRedirect(route('admin.dashboard'));
    }

    public function test_wrong_password_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->post('/login', ['email' => $user->email, 'password' => 'wrong-password'])
            ->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_login_is_throttled_after_five_failures(): void
    {
        $user = User::factory()->create();

        foreach (range(1, 5) as $i) {
            $this->post('/login', ['email' => $user->email, 'password' => 'nope']);
        }

        // Even the correct password is refused while throttled.
        $this->post('/login', ['email' => $user->email, 'password' => 'password123'])
            ->assertSessionHasErrors('email');
        $this->assertStringStartsWith('Too many login attempts', session('errors')->first('email'));
        $this->assertGuest();
    }

    public function test_language_chosen_before_login_is_saved_to_account(): void
    {
        $user = User::factory()->create(['locale' => 'en']);

        $this->get('/locale/es')->assertCookie('locale', 'es', encrypted: false);
        $this->post('/login', ['email' => $user->email, 'password' => 'password123']);

        $this->assertSame('es', $user->fresh()->locale);
    }

    public function test_temporary_password_must_be_changed_first(): void
    {
        $user = User::factory()->create(['must_change_password' => true]);

        $this->actingAs($user)->get('/jobs/today')->assertRedirect(route('password.change'));

        $this->put('/password/change', [
            'current_password' => 'password123',
            'password' => 'NewSecret2026',
            'password_confirmation' => 'NewSecret2026',
        ])->assertRedirect(route('home'));

        $this->assertFalse($user->fresh()->must_change_password);
        $this->get('/jobs/today')->assertOk();
    }

    public function test_logout_keeps_language(): void
    {
        $user = User::factory()->create(['locale' => 'es']);

        $this->actingAs($user)->post('/logout')->assertRedirect(route('login'));
        $this->get('/login')->assertSee('Iniciar sesión');
    }
}
