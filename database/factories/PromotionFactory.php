<?php

namespace Database\Factories;

use App\Models\Merchant;
use App\Models\Promotion;
use App\Models\Wallet;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Promotion>
 */
class PromotionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'wallet_id' => Wallet::factory(),
            'merchant_id' => Merchant::factory(),
            'promotion_category_id' => null,
            'external_id' => $this->faker->uuid(),
            'identity_hash' => hash('sha256', Str::random(40)),
            'title' => $this->faker->sentence(4),
            'description' => $this->faker->sentence(),
            'discount_percentage' => $this->faker->randomFloat(2, 5, 50),
            'valid_days' => ['monday', 'tuesday', 'wednesday', 'thursday', 'friday'],
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addMonth(),
            'url' => $this->faker->url(),
            'version' => 1,
            'is_active' => true,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => [
            'is_active' => false,
            'deactivated_at' => now(),
        ]);
    }
}
