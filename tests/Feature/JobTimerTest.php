<?php

namespace Tests\Feature;

use App\Enums\AppointmentStatus;
use App\Enums\TimeEntryStatus;
use App\Models\Appointment;
use App\Models\Client;
use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class JobTimerTest extends TestCase
{
    use RefreshDatabase;

    private User $cleaner;

    private Appointment $job;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-17 08:45', 'Australia/Sydney'));
        $this->cleaner = User::factory()->create();
        $this->job = Appointment::factory()->at('2026-11-17 09:00', 180)
            ->for(Client::factory()->state(['billing_increment_minutes' => 15, 'minimum_billable_minutes' => 120]))->create();
        $this->job->staff()->attach($this->cleaner);
    }

    private function goTo(string $local): void
    {
        Carbon::setTestNow(Carbon::parse($local, 'Australia/Sydney'));
    }

    public function test_full_start_stop_submit_flow(): void
    {
        $this->actingAs($this->cleaner)->get(route('jobs.today'))->assertSee(__('timer.start_job'));

        $this->post(route('jobs.start', $this->job), ['lat' => '-33.797', 'lng' => '151.288'])->assertRedirect(route('jobs.show', $this->job));
        $entry = TimeEntry::sole();
        $this->assertSame(TimeEntryStatus::Running, $entry->status);
        $this->assertSame($this->cleaner->id, $entry->running_lock);
        $this->assertEquals(-33.797, (float) $entry->start_lat);
        $this->assertSame(AppointmentStatus::InProgress, $this->job->fresh()->status);

        // Reopening the app later shows the same running timer (server-side start time).
        $this->goTo('2026-11-17 10:30');
        $this->get(route('jobs.show', $this->job))->assertSee(__('timer.stop_job'))->assertSee($entry->started_at->toIso8601String(), false);
        $this->get(route('jobs.today'))->assertSee(__('timer.banner', ['client' => $this->job->client->display_name]));

        $this->goTo('2026-11-17 11:32');
        $this->post(route('jobs.stop', $this->job))->assertRedirect();
        $entry->refresh();
        $this->assertSame(TimeEntryStatus::Stopped, $entry->status);
        $this->assertNull($entry->running_lock);
        $this->assertSame(167, $entry->duration_minutes);
        $this->assertSame(180, $entry->billable_minutes);   // rounded up to 15-minute increment

        $this->get(route('jobs.show', $this->job))->assertSee('167 min')->assertSee(__('timer.submit_timesheet'));
        $this->post(route('jobs.submit', $this->job), ['staff_notes' => 'Oven done'])->assertRedirect(route('jobs.today'));

        $entry->refresh();
        $this->assertSame(TimeEntryStatus::Submitted, $entry->status);
        $this->assertSame('Oven done', $entry->staff_notes);
        $this->assertSame(AppointmentStatus::Completed, $this->job->fresh()->status);

        // Locked after submission.
        $this->patch(route('jobs.adjust', $this->job), ['start_time' => '09:00', 'end_time' => '12:00'])->assertForbidden();
    }

    public function test_double_start_is_idempotent(): void
    {
        $this->actingAs($this->cleaner)->post(route('jobs.start', $this->job));
        $this->post(route('jobs.start', $this->job));

        $this->assertSame(1, TimeEntry::count());
    }

    public function test_cannot_run_two_jobs_at_once(): void
    {
        $second = Appointment::factory()->at('2026-11-17 09:30', 60)->create();
        $second->staff()->attach($this->cleaner);

        $this->actingAs($this->cleaner)->post(route('jobs.start', $this->job));
        $this->post(route('jobs.start', $second))->assertSessionHasErrors(['timer' => __('timer.stop_current_first')]);

        $this->assertSame(1, TimeEntry::count());
    }

    public function test_start_window_enforced(): void
    {
        $this->goTo('2026-11-17 07:30');   // more than 60 min early
        $this->actingAs($this->cleaner)->post(route('jobs.start', $this->job))->assertForbidden();
        $this->get(route('jobs.show', $this->job))->assertSee(__('timer.too_early', ['time' => '8:00am']));

        $this->goTo('2026-11-18 08:00');   // next day
        $this->post(route('jobs.start', $this->job))->assertForbidden();
    }

    public function test_non_assignee_gets_404(): void
    {
        $other = User::factory()->create();

        $this->actingAs($other)->post(route('jobs.start', $this->job))->assertNotFound();
        $this->actingAs($other)->post(route('jobs.stop', $this->job))->assertNotFound();
    }

    public function test_cannot_start_cancelled_job(): void
    {
        $this->job->update(['status' => AppointmentStatus::Cancelled]);

        $this->actingAs($this->cleaner)->post(route('jobs.start', $this->job))->assertForbidden();
    }

    public function test_staff_can_adjust_forgotten_times_and_originals_are_kept(): void
    {
        $this->actingAs($this->cleaner)->post(route('jobs.start', $this->job));
        $this->goTo('2026-11-17 18:00');   // forgot to stop
        $this->post(route('jobs.stop', $this->job));

        $this->patch(route('jobs.adjust', $this->job), ['start_time' => '09:00', 'end_time' => '12:15'])->assertRedirect();

        $entry = TimeEntry::sole();
        $this->assertSame(195, $entry->duration_minutes);
        $this->assertTrue($entry->staff_adjusted);
        $this->assertSame('08:45', $entry->original_started_at->tz('Australia/Sydney')->format('H:i'));
        $this->assertSame('18:00', $entry->original_ended_at->tz('Australia/Sydney')->format('H:i'));
    }

    public function test_adjusted_end_cannot_be_in_future(): void
    {
        $this->actingAs($this->cleaner)->post(route('jobs.start', $this->job));
        $this->goTo('2026-11-17 10:00');
        $this->post(route('jobs.stop', $this->job));

        $this->patch(route('jobs.adjust', $this->job), ['start_time' => '09:00', 'end_time' => '15:00'])
            ->assertSessionHasErrors('end_time');
    }

    public function test_minimum_billable_applies(): void
    {
        $this->actingAs($this->cleaner)->post(route('jobs.start', $this->job));
        $this->goTo('2026-11-17 09:30');
        $this->post(route('jobs.stop', $this->job));

        $this->assertSame(120, TimeEntry::sole()->billable_minutes);
    }

    public function test_appointment_completes_only_when_every_cleaner_submits(): void
    {
        $partner = User::factory()->create();
        $this->job->staff()->attach($partner);

        foreach ([$this->cleaner, $partner] as $i => $u) {
            $this->actingAs($u)->post(route('jobs.start', $this->job));
            $this->post(route('jobs.stop', $this->job));
            $this->post(route('jobs.submit', $this->job));
            $this->assertSame($i === 0 ? AppointmentStatus::InProgress : AppointmentStatus::Completed, $this->job->fresh()->status);
        }
    }

    public function test_watchdog_auto_stops_overnight_timer_at_scheduled_end(): void
    {
        $this->actingAs($this->cleaner)->post(route('jobs.start', $this->job));
        $this->goTo('2026-11-18 00:10');

        $this->artisan('apq:timers:watchdog')->expectsOutput('Auto-stopped 1 timer(s).');

        $entry = TimeEntry::sole();
        $this->assertSame(TimeEntryStatus::Stopped, $entry->status);
        $this->assertTrue($entry->auto_stopped);
        $this->assertSame('12:00', $entry->localEnd()->format('H:i'));
        $this->actingAs($this->cleaner)->get(route('jobs.show', $this->job))->assertSee(__('timer.auto_stopped_note'));
    }

    public function test_watchdog_leaves_todays_timers_alone(): void
    {
        $this->actingAs($this->cleaner)->post(route('jobs.start', $this->job));
        $this->goTo('2026-11-17 22:00');

        $this->artisan('apq:timers:watchdog')->expectsOutput('Auto-stopped 0 timer(s).');
    }

    public function test_locking_cleaner_stops_running_timer(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($this->cleaner)->post(route('jobs.start', $this->job));

        $this->actingAs($admin)->post(route('admin.staff.lock', $this->cleaner));

        $entry = TimeEntry::sole();
        $this->assertSame(TimeEntryStatus::Stopped, $entry->status);
        $this->assertTrue($entry->auto_stopped);
        $this->assertNull($entry->running_lock);
    }

    public function test_job_page_renders_in_spanish(): void
    {
        $this->cleaner->update(['locale' => 'es']);

        $this->actingAs($this->cleaner)->get(route('jobs.show', $this->job))->assertSee('Iniciar trabajo');
    }
}
