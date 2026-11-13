<?php

namespace App\Console\Commands;

use App\Models\RecurringSchedule;
use App\Services\Scheduling\AppointmentGenerator;
use Illuminate\Console\Command;

class GenerateAppointments extends Command
{
    protected $signature = 'apq:appointments:generate {--weeks=8 : How many weeks ahead to generate}';

    protected $description = 'Create upcoming visits from active recurring schedules (idempotent)';

    public function handle(AppointmentGenerator $generator): int
    {
        $total = 0;
        RecurringSchedule::query()->where('is_active', true)->with('client')->each(function ($schedule) use ($generator, &$total) {
            $total += $generator->generate($schedule, weeks: (int) $this->option('weeks'));
        });

        $this->info("Created {$total} appointment(s).");

        return self::SUCCESS;
    }
}
