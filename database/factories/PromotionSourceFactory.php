<?php

namespace Database\Factories;

use App\Models\Promotion;
use App\Models\PromotionSource;
use App\Models\ScrapeRun;
use App\Models\Wallet;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PromotionSource>
 */
class PromotionSourceFactory extends Factory
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
            'wallet_id' => Wallet::factory(),
            'external_id' => $this->faker->uuid(),
            'raw_payload' => ['title' => $this->faker->sentence(4)],
        ];
    }
}
