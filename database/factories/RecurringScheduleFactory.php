<?php

namespace Database\Factories;

use App\Enums\Frequency;
use App\Models\Client;
use App\Models\RecurringSchedule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RecurringSchedule>
 */
class RecurringScheduleFactory extends Factory
{
    public function definition(): array
    {
        return [
            'client_id' => Client::factory(),
            'frequency' => Frequency::Weekly,
            'weekday' => 2,
            'start_time' => '09:00',
            'duration_minutes' => 180,
            'anchor_date' => '2026-11-17',
            'is_active' => true,
        ];
    }
}
