<?php

namespace Database\Factories;

use App\Enums\EmploymentType;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected static ?string $password;

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password123'),
            'remember_token' => Str::random(10),
            'locale' => 'en',
            'role' => Role::Staff,
            'employment_type' => EmploymentType::Casual,
            'hourly_rate' => fake()->randomElement([32, 35, 38]),
            'is_active' => true,
        ];
    }

    public function admin(): static
    {
        return $this->state(fn () => ['role' => Role::Admin, 'hourly_rate' => null]);
    }

    public function locked(): static
    {
        return $this->state(fn () => ['is_active' => false, 'locked_at' => now()]);
    }

    public function unverified(): static
    {
        return $this->state(fn () => ['email_verified_at' => null]);
    }
}
