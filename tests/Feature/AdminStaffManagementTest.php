<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminStaffManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_creates_staff_with_temporary_password(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->post(route('admin.staff.store'), [
            'name' => 'Lucía Gómez',
            'email' => 'lucia@example.com',
            'mobile' => '0412 345 678',
            'role' => 'staff',
            'employment_type' => 'casual',
            'hourly_rate' => '35',
            'locale' => 'es',
            'password' => 'TempPass2026',
        ])->assertRedirect();

        $user = User::where('email', 'lucia@example.com')->firstOrFail();
        $this->assertSame('+61412345678', $user->mobile);
        $this->assertEquals(35, $user->hourly_rate);
        $this->assertTrue($user->must_change_password);
        $this->assertSame(Role::Staff, $user->role);
    }

    public function test_admin_cannot_demote_themselves(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->put(route('admin.staff.update', $admin), [
            'name' => $admin->name, 'email' => $admin->email, 'role' => 'staff',
            'employment_type' => 'casual', 'locale' => 'en',
        ])->assertSessionHasErrors('role');

        $this->assertTrue($admin->fresh()->isAdmin());
    }

    public function test_staff_list_and_form_render(): void
    {
        $admin = User::factory()->admin()->create();
        $staff = User::factory()->create(['name' => 'Rosa Example']);

        $this->actingAs($admin)->get(route('admin.staff.index'))->assertOk()->assertSee('Rosa Example');
        $this->actingAs($admin)->get(route('admin.staff.edit', $staff))->assertOk()->assertSee(__('admin.staff.lock'));
        $this->actingAs($admin)->get(route('admin.dashboard'))->assertOk();
    }

    public function test_invalid_mobile_is_rejected(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->post(route('admin.staff.store'), [
            'name' => 'X', 'email' => 'x@example.com', 'mobile' => '02 9999 1234',
            'role' => 'staff', 'employment_type' => 'casual', 'locale' => 'en', 'password' => 'TempPass2026',
        ])->assertSessionHasErrors('mobile');
    }
}
