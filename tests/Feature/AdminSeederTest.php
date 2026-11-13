<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_reads_admin_from_config_so_it_works_with_cached_config(): void
    {
        config(['apq.admin' => ['email' => 'owner@example.com', 'name' => 'Owner Example', 'password' => null, 'locale' => 'es']]);

        $this->seed(DatabaseSeeder::class);

        $admin = User::where('email', 'owner@example.com')->sole();
        $this->assertTrue($admin->isAdmin());
        $this->assertTrue($admin->must_change_password);   // generated temporary password
        $this->assertSame('es', $admin->locale);
    }

    public function test_admin_defaults_to_english(): void
    {
        config(['apq.admin' => ['email' => 'owner@example.com', 'name' => 'Owner', 'password' => 'Secret12345', 'locale' => null]]);

        $this->seed(DatabaseSeeder::class);

        $this->assertSame('en', User::where('email', 'owner@example.com')->sole()->locale);
    }

    public function test_seeder_is_idempotent(): void
    {
        config(['apq.admin' => ['email' => 'owner@example.com', 'name' => 'Owner Example', 'password' => 'Secret12345', 'locale' => 'en']]);

        $this->seed(DatabaseSeeder::class);
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(1, User::count());
    }
}
