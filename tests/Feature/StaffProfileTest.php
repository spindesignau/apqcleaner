<?php

namespace Tests\Feature;

use App\Enums\EmploymentType;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class StaffProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_page_hides_admin_only_fields(): void
    {
        $user = User::factory()->create(['hourly_rate' => 37.25, 'notes' => 'Secret admin note']);

        $this->actingAs($user)->get('/profile')
            ->assertOk()
            ->assertDontSee('37.25')
            ->assertDontSee('Secret admin note')
            ->assertDontSee('hourly_rate');
    }

    public function test_staff_cannot_mass_assign_protected_fields(): void
    {
        $user = User::factory()->create(['hourly_rate' => 32, 'employment_type' => EmploymentType::Casual]);

        $this->actingAs($user)->patch('/profile', [
            'name' => 'New Name',
            'email' => $user->email,
            'locale' => 'es',
            'role' => 'admin',
            'hourly_rate' => 99,
            'is_active' => 0,
            'employment_type' => 'full_time',
            'notes' => 'hacked',
            'must_change_password' => 0,
        ])->assertRedirect(route('profile.edit'));

        $user->refresh();
        $this->assertSame('New Name', $user->name);
        $this->assertSame('es', $user->locale);
        $this->assertSame(Role::Staff, $user->role);
        $this->assertEquals(32, $user->hourly_rate);
        $this->assertTrue($user->is_active);
        $this->assertSame(EmploymentType::Casual, $user->employment_type);
        $this->assertNull($user->notes);
    }

    public function test_avatar_upload_is_stored_privately_and_served_signed(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();

        $this->actingAs($user)->patch('/profile', [
            'name' => $user->name, 'email' => $user->email, 'locale' => 'en',
            'avatar' => UploadedFile::fake()->image('me.jpg', 1200, 800),
        ])->assertSessionHasNoErrors();

        $path = $user->fresh()->avatar_path;
        Storage::disk('local')->assertExists($path);
        [$w, $h] = getimagesizefromstring(Storage::disk('local')->get($path));
        $this->assertSame([512, 512], [$w, $h]);

        $this->get($user->fresh()->avatarUrl())->assertOk();
        $this->get(route('avatar', $user))->assertForbidden();   // unsigned
    }

    public function test_changing_email_resets_verification(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->patch('/profile', ['name' => $user->name, 'email' => 'new@example.com', 'locale' => 'en']);

        $this->assertNull($user->fresh()->email_verified_at);
    }

    public function test_password_change_requires_current_password(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->put('/profile/password', [
            'current_password' => 'wrong',
            'password' => 'NewSecret2026',
            'password_confirmation' => 'NewSecret2026',
        ])->assertSessionHasErrorsIn('password', 'current_password');

        $this->put('/profile/password', [
            'current_password' => 'password123',
            'password' => 'NewSecret2026',
            'password_confirmation' => 'NewSecret2026',
        ])->assertSessionHasNoErrors();

        $this->assertTrue(Hash::check('NewSecret2026', $user->fresh()->password));
    }

    public function test_staff_cannot_open_admin_area(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/admin')->assertForbidden();
        $this->actingAs($user)->get('/admin/staff')->assertForbidden();
    }

    public function test_staff_pages_render_in_spanish(): void
    {
        $user = User::factory()->create(['locale' => 'es']);

        $this->actingAs($user)->get('/jobs/today')->assertOk()->assertSee('Hoy')->assertSee('Semana')->assertSee('Perfil');
        $this->actingAs($user)->get('/jobs/week')->assertOk()->assertSee('Horario semanal');
    }

    public function test_week_navigation_is_bounded(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/jobs/week/2020-W01')->assertOk()->assertSee(__('staff.weekly_schedule'));
    }
}
