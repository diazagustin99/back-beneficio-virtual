<?php

namespace Database\Factories;

use App\Models\Promotion;
use App\Models\PromotionLocation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PromotionLocation>
 */
class PromotionLocationFactory extends Factory
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
            'scope' => 'nationwide',
        ];
    }
}
