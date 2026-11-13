<?php

namespace Database\Seeders;

use App\Enums\EmploymentType;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    /**
     * Creates the first administrator from APQ_ADMIN_* env values.
     * Staff are imported from the Weekly Planner: php artisan apq:import:staff <file> --commit
     * Demo staff (local only): php artisan db:seed --class=DemoSeeder
     */
    public function run(): void
    {
        $email = env('APQ_ADMIN_EMAIL');
        if (! $email) {
            $this->command?->warn('APQ_ADMIN_EMAIL is not set — no admin created.');

            return;
        }

        $password = env('APQ_ADMIN_PASSWORD') ?: Str::password(14, symbols: false);
        $admin = User::firstOrNew(['email' => $email]);
        $created = ! $admin->exists;

        $admin->forceFill([
            'name' => env('APQ_ADMIN_NAME', 'Adriana Palacio'),
            'role' => Role::Admin,
            'employment_type' => EmploymentType::FullTime,
            'locale' => env('APQ_ADMIN_LOCALE', 'es'),
            'is_active' => true,
            'email_verified_at' => now(),
        ]);
        if ($created) {
            $admin->forceFill(['password' => $password, 'must_change_password' => ! env('APQ_ADMIN_PASSWORD')]);
        }
        $admin->save();

        if ($created && ! env('APQ_ADMIN_PASSWORD')) {
            $this->command?->info("Admin {$email} created. Temporary password: {$password}");
        }
    }
}
