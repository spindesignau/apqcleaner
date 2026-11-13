<?php

namespace Database\Factories;

use App\Enums\AppointmentStatus;
use App\Enums\PricingModel;
use App\Models\Appointment;
use App\Models\Client;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<Appointment>
 */
class AppointmentFactory extends Factory
{
    public function definition(): array
    {
        $start = Carbon::parse('tomorrow 09:00', 'Australia/Sydney');

        return [
            'client_id' => Client::factory(),
            'scheduled_start' => $start,
            'scheduled_end' => $start->copy()->addHours(3),
            'status' => AppointmentStatus::Scheduled,
            'pricing_model' => PricingModel::Fixed,
            'price' => 220,
        ];
    }

    public function at(string $localStart, int $minutes = 180): static
    {
        $start = Carbon::parse($localStart, 'Australia/Sydney');

        return $this->state(fn () => ['scheduled_start' => $start, 'scheduled_end' => $start->copy()->addMinutes($minutes)]);
    }
}
