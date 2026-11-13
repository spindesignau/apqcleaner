<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AccountLockTest extends TestCase
{
    use RefreshDatabase;

    public function test_locking_deletes_sessions_rotates_remember_token_and_audits(): void
    {
        $admin = User::factory()->admin()->create();
        $staff = User::factory()->create(['remember_token' => 'old-token']);
        DB::table('sessions')->insert([
            ['id' => 'phone', 'user_id' => $staff->id, 'payload' => '', 'last_activity' => time()],
            ['id' => 'tablet', 'user_id' => $staff->id, 'payload' => '', 'last_activity' => time()],
            ['id' => 'other', 'user_id' => $admin->id, 'payload' => '', 'last_activity' => time()],
        ]);

        $this->actingAs($admin)
            ->post(route('admin.staff.lock', $staff), ['lock_reason' => 'Left the company'])
            ->assertRedirect();

        $staff->refresh();
        $this->assertFalse($staff->is_active);
        $this->assertSame($admin->id, $staff->locked_by);
        $this->assertNotSame('old-token', $staff->remember_token);
        $this->assertSame(0, DB::table('sessions')->where('user_id', $staff->id)->count());
        $this->assertSame(1, DB::table('sessions')->where('user_id', $admin->id)->count());
        $this->assertTrue(AuditLog::where('action', 'user.locked')->where('auditable_id', $staff->id)->exists());
    }

    public function test_locked_user_with_live_session_is_logged_out_on_next_request(): void
    {
        $staff = User::factory()->locked()->create(['locale' => 'es']);

        $this->actingAs($staff)->get('/jobs/today')
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors(['email' => 'Tu cuenta ha sido desactivada. Por favor contacta a APQ Cleaning Service.']);
        $this->assertGuest();
    }

    public function test_locked_user_cannot_sign_in(): void
    {
        $staff = User::factory()->locked()->create();

        $this->post('/login', ['email' => $staff->email, 'password' => 'password123'])
            ->assertSessionHasErrors(['email' => __('auth.locked')]);
        $this->assertGuest();
    }

    public function test_unlock_restores_access(): void
    {
        $admin = User::factory()->admin()->create();
        $staff = User::factory()->locked()->create();

        $this->actingAs($admin)->post(route('admin.staff.unlock', $staff))->assertRedirect();

        $this->assertTrue($staff->fresh()->is_active);
        $this->assertNull($staff->fresh()->locked_at);
    }

    public function test_admin_cannot_lock_themselves(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->post(route('admin.staff.lock', $admin))->assertStatus(422);
        $this->assertTrue($admin->fresh()->is_active);
    }

    public function test_staff_cannot_lock_anyone(): void
    {
        $staff = User::factory()->create();
        $other = User::factory()->create();

        $this->actingAs($staff)->post(route('admin.staff.lock', $other))->assertForbidden();
        $this->assertTrue($other->fresh()->is_active);
    }
}
