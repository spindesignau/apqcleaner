<?php

namespace Tests\Feature;

use App\Enums\AppointmentStatus;
use App\Enums\TimeEntryStatus;
use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\TimeEntry;
use App\Models\User;
use App\Support\PayPeriod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class TimesheetAdminTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-23 10:00', 'Australia/Sydney'));   // Monday
        $this->admin = User::factory()->admin()->create();
    }

    private function entry(string $localStart, array $attrs = [], array $userAttrs = []): TimeEntry
    {
        $start = Carbon::parse($localStart, 'Australia/Sydney');
        $user = User::factory()->create($userAttrs + ['hourly_rate' => 35]);
        $appointment = Appointment::factory()->at($localStart)->for(Client::factory()->state(['fixed_price' => 300]))->create(['price' => 300]);
        $appointment->staff()->attach($user);

        return TimeEntry::factory()->for($appointment)->for($user)->create($attrs + [
            'client_id' => $appointment->client_id, 'started_at' => $start, 'ended_at' => $start->copy()->addHours(3),
        ]);
    }

    public function test_grid_lists_entries_with_totals(): void
    {
        $e = $this->entry('2026-11-20 09:00');

        $this->actingAs($this->admin)->get(route('admin.timesheets.index', ['from' => '2026-11-16', 'to' => '2026-11-29']))
            ->assertOk()->assertSee($e->user->name)->assertSee('$105.00')   // 3 h × $35
            ->assertSee(trans_choice('timesheets.awaiting', 1, ['count' => 1]));
    }

    public function test_bulk_approve_snapshots_weekend_pay_and_visit_price(): void
    {
        $weekday = $this->entry('2026-11-20 09:00');
        $saturday = $this->entry('2026-11-21 09:00', userAttrs: ['saturday_rate' => 42]);

        $this->actingAs($this->admin)->post(route('admin.timesheets.approve'), ['ids' => [$weekday->id, $saturday->id]])
            ->assertSessionHas('status', trans_choice('timesheets.approved_many', 2, ['count' => 2]));

        $this->assertSame(TimeEntryStatus::Approved, $weekday->fresh()->status);
        $this->assertEquals(35, $weekday->fresh()->pay_rate);
        $this->assertEquals(42, $saturday->fresh()->pay_rate);
        $this->assertEquals(300, $saturday->fresh()->charge_rate);
        $this->assertSame($this->admin->id, $saturday->fresh()->approved_by);
    }

    public function test_running_entries_are_not_approved(): void
    {
        $running = $this->entry('2026-11-23 09:00', ['status' => TimeEntryStatus::Running, 'ended_at' => null, 'running_lock' => null]);

        $this->actingAs($this->admin)->post(route('admin.timesheets.approve'), ['ids' => [$running->id]]);

        $this->assertSame(TimeEntryStatus::Running, $running->fresh()->status);
    }

    public function test_admin_edits_times_and_billable_with_audit(): void
    {
        $e = $this->entry('2026-11-20 09:00');

        $this->actingAs($this->admin)->put(route('admin.timesheets.update', $e), [
            'date' => '2026-11-20', 'start_time' => '09:00', 'end_time' => '11:30', 'billable_minutes' => 150,
            'reason' => 'Client confirmed finish time', 'approve' => 1,
        ])->assertRedirect();

        $e->refresh();
        $this->assertSame(150, $e->duration_minutes);
        $this->assertSame(150, $e->billable_minutes);
        $this->assertTrue($e->admin_adjusted);
        $this->assertSame(TimeEntryStatus::Approved, $e->status);
        $this->assertTrue(AuditLog::where('action', 'timer.adjusted')->where('reason', 'Client confirmed finish time')->exists());
        $this->get(route('admin.timesheets.edit', $e))->assertOk()->assertSee('Client confirmed finish time');
    }

    public function test_reject_sends_back_and_staff_can_resubmit(): void
    {
        $e = $this->entry('2026-11-23 06:00');
        $e->appointment->update(['status' => AppointmentStatus::Completed]);

        $this->actingAs($this->admin)->post(route('admin.timesheets.reject', $e), ['rejected_reason' => 'Check end time'])->assertRedirect();
        $this->assertSame(TimeEntryStatus::Rejected, $e->fresh()->status);
        $this->assertSame(AppointmentStatus::InProgress, $e->appointment->fresh()->status);

        $this->actingAs($e->user)->get(route('jobs.show', $e->appointment))->assertSee('Check end time');
        $this->patch(route('jobs.adjust', $e->appointment), ['start_time' => '06:00', 'end_time' => '08:30'])->assertRedirect();
        $this->post(route('jobs.submit', $e->appointment))->assertRedirect();

        $this->assertSame(TimeEntryStatus::Submitted, $e->fresh()->status);
        $this->assertSame(150, $e->fresh()->duration_minutes);
        $this->assertNull($e->fresh()->rejected_reason);
    }

    public function test_missing_timesheets_and_admin_adds_time(): void
    {
        $cleaner = User::factory()->create(['name' => 'Forgetful Example']);
        $visit = Appointment::factory()->at('2026-11-20 09:00', 180)->create();
        $visit->staff()->attach($cleaner);

        $this->actingAs($this->admin)->get(route('admin.timesheets.index', ['from' => '2026-11-16', 'to' => '2026-11-29']))
            ->assertSee(__('timesheets.missing_title'))->assertSee('Forgetful Example');

        $this->get(route('admin.timesheets.create', ['appointment' => $visit->id, 'user' => $cleaner->id]))->assertOk();
        $this->post(route('admin.timesheets.store'), [
            'appointment_id' => $visit->id, 'user_id' => $cleaner->id, 'date' => '2026-11-20',
            'start_time' => '09:10', 'end_time' => '12:00', 'reason' => 'Phone died', 'approve' => 1,
        ])->assertRedirect();

        $entry = TimeEntry::where('user_id', $cleaner->id)->sole();
        $this->assertSame(170, $entry->duration_minutes);
        $this->assertSame(TimeEntryStatus::Approved, $entry->status);
        $this->assertSame(AppointmentStatus::Completed, $visit->fresh()->status);
    }

    public function test_admin_can_stop_a_running_timer(): void
    {
        $e = $this->entry('2026-11-23 08:00', ['status' => TimeEntryStatus::Running, 'ended_at' => null]);

        $this->actingAs($this->admin)->post(route('admin.timesheets.stop', $e))->assertRedirect();

        $this->assertSame(TimeEntryStatus::Stopped, $e->fresh()->status);
        $this->assertSame(120, $e->fresh()->duration_minutes);
    }

    public function test_invoiced_entries_are_frozen(): void
    {
        $e = $this->entry('2026-11-20 09:00', ['status' => TimeEntryStatus::Invoiced, 'invoice_line_id' => 1]);

        $this->actingAs($this->admin)->put(route('admin.timesheets.update', $e), [
            'date' => '2026-11-20', 'start_time' => '09:00', 'end_time' => '10:00',
        ])->assertForbidden();
        $this->expectException(\LogicException::class);
        $e->update(['billable_minutes' => 1]);
    }

    public function test_staff_cannot_reach_admin_timesheets(): void
    {
        $e = $this->entry('2026-11-20 09:00');

        $this->actingAs($e->user)->get(route('admin.timesheets.index'))->assertForbidden();
        $this->actingAs($e->user)->post(route('admin.timesheets.approve'), ['ids' => [$e->id]])->assertForbidden();
    }

    public function test_fortnightly_pay_period_from_anchor(): void
    {
        $p = PayPeriod::containing(Carbon::parse('2026-11-23', 'Australia/Sydney'));
        // Anchor Mon 5 Jan 2026 → fortnights start …, 9 Nov, 23 Nov.
        $this->assertSame(['2026-11-23', '2026-12-06'], [$p->start->toDateString(), $p->end->toDateString()]);
        $p2 = PayPeriod::containing(Carbon::parse('2026-11-22', 'Australia/Sydney'));
        $this->assertSame('2026-11-09', $p2->start->toDateString());
    }

    public function test_dashboard_shows_live_timers(): void
    {
        $e = $this->entry('2026-11-23 08:00', ['status' => TimeEntryStatus::Running, 'ended_at' => null]);

        $this->actingAs($this->admin)->get(route('admin.dashboard'))->assertOk()->assertSee($e->user->name)->assertSee(__('admin.dashboard.live_timers'));
    }
}
