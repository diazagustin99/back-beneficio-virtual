<?php

namespace Database\Factories;

use App\Models\Preference;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Preference>
 */
class PreferenceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'token' => Str::random(40),
            'user_id' => null,
            'wants_notifications' => false,
        ];
    }

    /**
     * Indicate that push subscriptions were saved during onboarding.
     */
    public function wantsNotifications(): static
    {
        return $this->state(fn (array $attributes) => [
            'wants_notifications' => true,
        ]);
    }
}
