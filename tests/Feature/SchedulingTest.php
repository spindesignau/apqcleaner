<?php

namespace Tests\Feature;

use App\Enums\AppointmentStatus;
use App\Enums\Frequency;
use App\Models\Appointment;
use App\Models\Client;
use App\Models\RecurringSchedule;
use App\Models\User;
use App\Services\Scheduling\AppointmentGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class SchedulingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-14 10:00', 'Australia/Sydney'));   // a Saturday
    }

    public function test_generator_creates_visits_with_staff_and_price_snapshot_and_is_idempotent(): void
    {
        $cleaner = User::factory()->create();
        $client = Client::factory()->create(['fixed_price' => 250]);
        $schedule = RecurringSchedule::factory()->for($client)->create(['frequency' => Frequency::Weekly, 'weekday' => 2, 'anchor_date' => '2026-11-17']);
        $schedule->staff()->attach($cleaner);

        $generator = app(AppointmentGenerator::class);
        $this->assertSame(8, $generator->generate($schedule));
        $this->assertSame(0, $generator->generate($schedule));   // second run creates nothing

        $first = Appointment::orderBy('scheduled_start')->first();
        $this->assertSame('2026-11-17 09:00', $first->localStart()->format('Y-m-d H:i'));
        $this->assertSame('2026-11-16 22:00:00', $first->getRawOriginal('scheduled_start'));   // stored in UTC (AEDT +11)
        $this->assertEquals(250, $first->price);
        $this->assertTrue($first->staff->contains($cleaner));
    }

    public function test_wall_clock_time_survives_daylight_saving_change(): void
    {
        Carbon::setTestNow(Carbon::parse('2027-03-20 10:00', 'Australia/Sydney'));   // DST ends Sun 4 Apr 2027
        $schedule = RecurringSchedule::factory()->create(['weekday' => 1, 'anchor_date' => '2027-03-22']);

        app(AppointmentGenerator::class)->generate($schedule, weeks: 4);

        $starts = Appointment::orderBy('scheduled_start')->get()->map(fn ($a) => $a->localStart()->format('D H:i'))->unique();
        $this->assertSame(['Mon 09:00'], $starts->values()->all());
        $raw = Appointment::orderBy('scheduled_start')->pluck('scheduled_start')->map(fn ($d) => $d->format('H:i'));
        $this->assertSame(['22:00', '22:00', '23:00', '23:00'], $raw->all());   // UTC offset changes, local time doesn't
    }

    public function test_editing_schedule_rebuilds_untouched_future_visits_but_keeps_edited_ones(): void
    {
        $admin = User::factory()->admin()->create();
        $schedule = RecurringSchedule::factory()->create(['weekday' => 2, 'anchor_date' => '2026-11-17']);
        app(AppointmentGenerator::class)->generate($schedule);
        $edited = Appointment::orderBy('scheduled_start')->first();
        $edited->update(['is_detached' => true, 'job_notes' => 'Moved by hand']);

        $this->actingAs($admin)->put(route('admin.schedules.update', $schedule), [
            'frequency' => 'weekly', 'weekday' => 3, 'start_time' => '10:30', 'duration_minutes' => 120,
            'anchor_date' => '2026-11-18', 'staff' => [],
        ])->assertRedirect();

        $this->assertTrue(Appointment::whereKey($edited->id)->exists());
        $others = Appointment::whereKeyNot($edited->id)->get();
        $this->assertTrue($others->every(fn ($a) => $a->localStart()->format('D H:i') === 'Wed 10:30'));
    }

    public function test_ending_schedule_removes_future_visits(): void
    {
        $admin = User::factory()->admin()->create();
        $schedule = RecurringSchedule::factory()->create();
        app(AppointmentGenerator::class)->generate($schedule);

        $this->actingAs($admin)->delete(route('admin.schedules.destroy', $schedule))->assertRedirect();

        $this->assertSame(0, Appointment::count());
        $this->assertSoftDeleted($schedule);
    }

    public function test_deleted_occurrence_is_not_recreated(): void
    {
        $schedule = RecurringSchedule::factory()->create();
        $generator = app(AppointmentGenerator::class);
        $generator->generate($schedule);
        Appointment::first()->delete();

        $this->assertSame(0, $generator->generate($schedule));
    }

    public function test_admin_creates_schedule_from_client_page(): void
    {
        $admin = User::factory()->admin()->create();
        $cleaner = User::factory()->create();
        $client = Client::factory()->create();

        $this->actingAs($admin)->post(route('admin.schedules.store', $client), [
            'frequency' => 'fortnightly', 'weekday' => 4, 'start_time' => '08:30', 'duration_minutes' => 240,
            'anchor_date' => '2026-11-19', 'staff' => [$cleaner->id],
        ])->assertRedirect(route('admin.clients.show', $client));

        $this->assertSame(4, $client->appointments()->count());   // 19 Nov, 3 Dec, 17 Dec, 31 Dec
        $this->get(route('admin.clients.show', $client))->assertOk()->assertSee('Fortnightly');
    }

    public function test_one_off_visit_with_double_booking_warning(): void
    {
        $admin = User::factory()->admin()->create();
        $cleaner = User::factory()->create(['name' => 'Rosa Example']);
        $existing = Appointment::factory()->at('2026-11-18 09:00', 180)->create();
        $existing->staff()->attach($cleaner);
        $client = Client::factory()->create();

        $this->actingAs($admin)->post(route('admin.appointments.store'), [
            'client_id' => $client->id, 'date' => '2026-11-18', 'start_time' => '11:00',
            'duration_minutes' => 120, 'staff' => [$cleaner->id],
        ])->assertRedirect(route('admin.schedule', ['week' => '2026-W47']))
            ->assertSessionHas('warning', fn ($w) => str_contains($w, 'Rosa Example'));

        $this->get(route('admin.schedule', ['week' => '2026-W47']))->assertOk()->assertSee(__('schedule.overlap'));
    }

    public function test_editing_generated_visit_detaches_it(): void
    {
        $admin = User::factory()->admin()->create();
        $schedule = RecurringSchedule::factory()->create();
        app(AppointmentGenerator::class)->generate($schedule);
        $visit = Appointment::first();

        $this->actingAs($admin)->put(route('admin.appointments.update', $visit), [
            'date' => '2026-11-17', 'start_time' => '13:00', 'duration_minutes' => 120,
        ])->assertRedirect();

        $this->assertTrue($visit->fresh()->is_detached);
        $this->assertSame('13:00', $visit->fresh()->localStart()->format('H:i'));
    }

    public function test_started_visit_cannot_be_edited(): void
    {
        $admin = User::factory()->admin()->create();
        $visit = Appointment::factory()->create(['status' => AppointmentStatus::InProgress]);

        $this->actingAs($admin)->put(route('admin.appointments.update', $visit), [
            'date' => '2026-11-17', 'start_time' => '13:00', 'duration_minutes' => 120,
        ])->assertStatus(422);
    }

    public function test_deactivating_client_removes_future_untouched_visits(): void
    {
        $admin = User::factory()->admin()->create();
        $client = Client::factory()->create();
        $schedule = RecurringSchedule::factory()->for($client)->create();
        app(AppointmentGenerator::class)->generate($schedule);

        $this->actingAs($admin)->put(route('admin.clients.update', $client), [
            'display_name' => $client->display_name, 'address_line1' => $client->address_line1, 'state' => 'NSW',
            'pricing_model' => 'fixed', 'fixed_price' => 200, 'payment_method' => 'cash', 'preferred_language' => 'en',
            'is_active' => 0,
        ])->assertRedirect();

        $this->assertSame(0, $client->appointments()->count());
    }

    public function test_generate_command(): void
    {
        RecurringSchedule::factory()->count(2)->create();

        $this->artisan('apq:appointments:generate')->expectsOutput('Created 16 appointment(s).')->assertSuccessful();
    }
}
