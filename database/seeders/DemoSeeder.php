<?php

namespace Database\Seeders;

use App\Enums\AppointmentStatus;
use App\Enums\Frequency;
use App\Enums\TimeEntryStatus;
use App\Models\Appointment;
use App\Models\Client;
use App\Models\RecurringSchedule;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\Scheduling\AppointmentGenerator;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Fictional demo data for local development. Never run in production.
 * Every demo account uses the password "password123".
 */
class DemoSeeder extends Seeder
{
    public function run(AppointmentGenerator $generator): void
    {
        if (app()->isProduction()) {
            $this->command?->error('DemoSeeder refuses to run in production.');

            return;
        }

        User::factory()->admin()->create(['name' => 'Demo Admin', 'email' => 'admin@demo.test', 'locale' => 'en']);
        $ana = User::factory()->create(['name' => 'Demo Cleaner', 'email' => 'cleaner@demo.test', 'locale' => 'es', 'hourly_rate' => 35, 'saturday_rate' => 40]);
        $ben = User::factory()->create(['name' => 'Demo English Cleaner', 'email' => 'cleaner.en@demo.test', 'hourly_rate' => 32]);
        User::factory()->locked()->create(['name' => 'Demo Locked', 'email' => 'locked@demo.test', 'lock_reason' => 'Demo']);

        $tz = config('apq.display_timezone');
        $monday = Carbon::now($tz)->startOfWeek();
        $clients = [
            ['Harbour – Manly', '12 Example Pde', 'Manly', '2095', 'Lockbox 1234 by the side gate', 'Oven every visit', 280],
            ['Seaview – Fairlight', '5 Sample St', 'Fairlight', '2094', 'Client home — knock', null, 220],
            ['Palms – Dee Why', '3/40 Demo Ave', 'Dee Why', '2099', 'Key under the blue pot', 'Eco products only (allergy)', 250],
            ['Ridge – Freshwater', '88 Test Rd', 'Freshwater', '2096', 'Code 2075#', null, 350],
            ['Lagoon – Narrabeen', '1 Fictional Cl', 'Narrabeen', '2101', null, 'Change bed linen in main bedroom', 200],
        ];

        foreach ($clients as $i => [$name, $street, $suburb, $pc, $access, $focus, $price]) {
            $client = Client::factory()->create([
                'display_name' => $name, 'billing_name' => $name, 'address_line1' => $street, 'suburb' => $suburb,
                'postcode' => $pc, 'access_notes' => $access, 'default_job_notes' => $focus, 'fixed_price' => $price,
            ]);
            $schedule = RecurringSchedule::factory()->for($client)->create([
                'frequency' => $i % 2 ? Frequency::Fortnightly : Frequency::Weekly,
                'weekday' => [1, 2, 3, 4, 5][$i],
                'start_time' => ['08:30', '09:00', '12:30', '09:00', '13:00'][$i],
                'duration_minutes' => [240, 180, 180, 300, 150][$i],
                'anchor_date' => $monday->toDateString(),
                'default_job_notes' => $focus,
            ]);
            $schedule->staff()->sync($i === 3 ? [$ana->id, $ben->id] : [$i % 2 ? $ben->id : $ana->id]);
            $generator->generate($schedule, Carbon::now($tz)->startOfWeek()->toImmutable());
        }

        // A one-off visit today for the Spanish demo cleaner, plus a deliberate double booking for the board.
        $today = Appointment::factory()->for(Client::first())->at(Carbon::now($tz)->setTime(14, 0)->toDateTimeString(), 120)
            ->create(['job_notes' => 'Visita extra: ventanas']);
        $today->staff()->attach($ana);

        // Timer demo states: a running job for the Spanish cleaner, submitted time from earlier in the week.
        $tzNow = Carbon::now($tz);
        $running = Appointment::factory()->for(Client::skip(1)->first())->at($tzNow->copy()->subMinutes(75)->toDateTimeString(), 180)->create();
        $running->staff()->attach($ana);
        $running->update(['status' => AppointmentStatus::InProgress]);
        TimeEntry::create([
            'appointment_id' => $running->id, 'user_id' => $ana->id, 'client_id' => $running->client_id,
            'status' => TimeEntryStatus::Running, 'running_lock' => $ana->id, 'started_at' => $tzNow->copy()->subMinutes(72),
        ]);

        Appointment::query()->where('scheduled_end', '<', now())->whereNull('deleted_at')->with(['staff', 'client'])->get()
            ->each(function (Appointment $visit) {
                foreach ($visit->staff as $i => $person) {
                    $start = $visit->scheduled_start->copy()->addMinutes(5 + $i * 3);
                    $end = $visit->scheduled_end->copy()->addMinutes([-10, 12, 0, 25][($visit->id + $i) % 4]);
                    $minutes = (int) round($start->diffInSeconds($end) / 60);
                    TimeEntry::create([
                        'appointment_id' => $visit->id, 'user_id' => $person->id, 'client_id' => $visit->client_id,
                        'status' => TimeEntryStatus::Submitted, 'started_at' => $start, 'ended_at' => $end,
                        'duration_seconds' => $minutes * 60, 'duration_minutes' => $minutes,
                        'billable_minutes' => (int) ceil($minutes / 15) * 15, 'submitted_at' => $end,
                        'staff_notes' => ['Todo bien', 'Oven cleaned', null, 'Out of glass cleaner'][$visit->id % 4],
                        'staff_adjusted' => $visit->id % 5 === 0,
                    ]);
                }
                $visit->update(['status' => AppointmentStatus::Completed]);
            });
    }
}
