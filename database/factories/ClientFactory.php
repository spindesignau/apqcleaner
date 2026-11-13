<?php

namespace Database\Factories;

use App\Enums\PaymentMethod;
use App\Enums\PricingModel;
use App\Models\Client;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Client>
 */
class ClientFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->lastName();

        return [
            'display_name' => $name.' – Manly',
            'billing_name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'mobile' => '+614'.fake()->unique()->numerify('########'),
            'address_line1' => fake()->buildingNumber().' Ocean Rd',
            'suburb' => 'Manly',
            'state' => 'NSW',
            'postcode' => '2095',
            'pricing_model' => PricingModel::Fixed,
            'fixed_price' => 220,
            'payment_method' => PaymentMethod::Cash,
            'preferred_language' => 'en',
            'is_active' => true,
        ];
    }
}
