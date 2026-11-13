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
     * Creates the first administrator from apq.admin config (APQ_ADMIN_* in .env).
     * Staff are imported from the Weekly Planner: php artisan apq:import:staff <file> --commit
     * Demo staff (local only): php artisan db:seed --class=DemoSeeder
     */
    public function run(): void
    {
        $email = config('apq.admin.email');
        if (! $email) {
            $this->command?->warn('APQ_ADMIN_EMAIL is not set — no admin created.');

            return;
        }

        $password = config('apq.admin.password') ?: Str::password(14, symbols: false);
        $admin = User::firstOrNew(['email' => $email]);
        $created = ! $admin->exists;

        $admin->forceFill([
            'name' => config('apq.admin.name'),
            'role' => Role::Admin,
            'employment_type' => EmploymentType::FullTime,
            'locale' => config('apq.admin.locale'),
            'is_active' => true,
            'email_verified_at' => now(),
        ]);
        if ($created) {
            $admin->forceFill(['password' => $password, 'must_change_password' => ! config('apq.admin.password')]);
        }
        $admin->save();

        if ($created && ! config('apq.admin.password')) {
            $this->command?->info("Admin {$email} created. Temporary password: {$password}");
        }
    }
}
