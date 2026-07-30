<?php

namespace Database\Factories;

use App\Models\Promotion;
use App\Models\PromotionSnapshot;
use App\Models\ScrapeRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PromotionSnapshot>
 */
class PromotionSnapshotFactory extends Factory
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
            'scrape_run_id' => ScrapeRun::factory(),
            'version' => 1,
            'data' => [
                'title' => $this->faker->sentence(4),
                'discount_percentage' => $this->faker->randomFloat(2, 5, 50),
            ],
        ];
    }
}
