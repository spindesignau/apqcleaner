<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Fictional demo accounts for local development. Never run in production.
 * Every demo account uses the password "password123".
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command?->error('DemoSeeder refuses to run in production.');

            return;
        }

        User::factory()->admin()->create(['name' => 'Demo Admin', 'email' => 'admin@demo.test', 'locale' => 'en']);
        User::factory()->create(['name' => 'Demo Cleaner', 'email' => 'cleaner@demo.test', 'locale' => 'es', 'hourly_rate' => 35]);
        User::factory()->create(['name' => 'Demo English Cleaner', 'email' => 'cleaner.en@demo.test', 'hourly_rate' => 32]);
        User::factory()->locked()->create(['name' => 'Demo Locked', 'email' => 'locked@demo.test', 'lock_reason' => 'Demo']);
    }
}
