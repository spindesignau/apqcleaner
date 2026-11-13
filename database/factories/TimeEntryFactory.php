<?php

namespace Database\Factories;

use App\Enums\TimeEntryStatus;
use App\Models\Appointment;
use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TimeEntry>
 */
class TimeEntryFactory extends Factory
{
    public function definition(): array
    {
        return [
            'appointment_id' => Appointment::factory(),
            'user_id' => User::factory(),
            'client_id' => fn (array $a) => Appointment::find($a['appointment_id'])->client_id,
            'status' => TimeEntryStatus::Submitted,
            'started_at' => now()->subHours(3),
            'ended_at' => now(),
            'duration_seconds' => 10800,
            'duration_minutes' => 180,
            'billable_minutes' => 180,
            'submitted_at' => now(),
        ];
    }
}
