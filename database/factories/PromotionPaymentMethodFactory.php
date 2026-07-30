<?php

namespace Database\Factories;

use App\Models\Promotion;
use App\Models\PromotionPaymentMethod;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PromotionPaymentMethod>
 */
class PromotionPaymentMethodFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'promotion_id' => Promotion::factory(),
            'name' => $this->faker->randomElement(['Tarjeta de crédito', 'Tarjeta de débito', 'QR']),
        ];
    }
}
