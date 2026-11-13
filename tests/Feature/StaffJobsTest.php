<?php

namespace Tests\Feature;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class StaffJobsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-17 07:00', 'Australia/Sydney'));
    }

    public function test_today_shows_only_my_jobs_for_today(): void
    {
        $me = User::factory()->create();
        $other = User::factory()->create();
        $mine = Appointment::factory()->at('2026-11-17 09:00')->for(Client::factory()->state(['display_name' => 'Mine Today']))->create();
        $mine->staff()->attach($me);
        $theirs = Appointment::factory()->at('2026-11-17 09:00')->for(Client::factory()->state(['display_name' => 'Theirs Today']))->create();
        $theirs->staff()->attach($other);
        $tomorrow = Appointment::factory()->at('2026-11-18 09:00')->for(Client::factory()->state(['display_name' => 'Mine Tomorrow']))->create();
        $tomorrow->staff()->attach($me);
        $cancelled = Appointment::factory()->at('2026-11-17 13:00')->for(Client::factory()->state(['display_name' => 'Cancelled One']))
            ->create(['status' => AppointmentStatus::Cancelled]);
        $cancelled->staff()->attach($me);

        $this->actingAs($me)->get(route('jobs.today'))
            ->assertOk()->assertSee('Mine Today')->assertDontSee('Theirs Today')
            ->assertDontSee('Mine Tomorrow')->assertDontSee('Cancelled One');

        $this->get(route('jobs.week'))->assertOk()->assertSee('Mine Today')->assertSee('Mine Tomorrow')->assertDontSee('Theirs Today');
    }

    public function test_late_evening_utc_boundary_counts_as_local_day(): void
    {
        $me = User::factory()->create();
        // 7:00am Sydney on the 17th is 20:00 UTC on the 16th.
        $early = Appointment::factory()->at('2026-11-17 07:30', 60)->for(Client::factory()->state(['display_name' => 'Early Bird']))->create();
        $early->staff()->attach($me);

        $this->actingAs($me)->get(route('jobs.today'))->assertSee('Early Bird');
    }

    public function test_job_page_shows_access_notes_to_assignee_only(): void
    {
        $me = User::factory()->create();
        $other = User::factory()->create();
        $client = Client::factory()->create(['access_notes' => 'Code 4471', 'admin_notes' => 'Pays late', 'fixed_price' => 333]);
        $job = Appointment::factory()->for($client)->create();
        $job->staff()->attach($me);

        $this->actingAs($me)->get(route('jobs.show', $job))
            ->assertOk()->assertSee('Code 4471')->assertDontSee('Pays late')->assertDontSee('333');
        $this->actingAs($other)->get(route('jobs.show', $job))->assertNotFound();
    }

    public function test_client_phone_hidden_unless_allowed(): void
    {
        $me = User::factory()->create();
        $client = Client::factory()->create(['mobile' => '+61412999888', 'show_phone_to_staff' => false]);
        $job = Appointment::factory()->for($client)->create();
        $job->staff()->attach($me);

        $this->actingAs($me)->get(route('jobs.show', $job))->assertDontSee('+61412999888');
        $client->update(['show_phone_to_staff' => true]);
        $this->get(route('jobs.show', $job))->assertSee('tel:+61412999888', false);
    }

    public function test_weekend_pay_rates_fall_back_to_hourly(): void
    {
        $u = User::factory()->make(['hourly_rate' => 35, 'saturday_rate' => 40, 'sunday_rate' => null]);

        $this->assertEquals(35, $u->payRateFor(Carbon::parse('2026-11-16')));
        $this->assertEquals(40, $u->payRateFor(Carbon::parse('2026-11-21')));
        $this->assertEquals(35, $u->payRateFor(Carbon::parse('2026-11-22')));
    }
}
