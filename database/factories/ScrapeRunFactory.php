<?php

namespace Database\Factories;

use App\Enums\ScrapeRunStatus;
use App\Models\ScrapeRun;
use App\Models\Wallet;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ScrapeRun>
 */
class ScrapeRunFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // Defaults to a wallet source — the overwhelming majority of
            // real scrape_runs are (every wallet scraper, still). Pass
            // `->for($merchant, 'scrapeable')` to override for a
            // merchant-sourced run.
            'scrapeable_type' => 'wallet',
            'scrapeable_id' => Wallet::factory(),
            'status' => ScrapeRunStatus::Pending,
            'triggered_by' => 'schedule',
        ];
    }

    public function running(): static
    {
        return $this->state(fn () => [
            'status' => ScrapeRunStatus::Running,
            'started_at' => now(),
        ]);
    }

    public function success(): static
    {
        return $this->state(fn () => [
            'status' => ScrapeRunStatus::Success,
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn () => [
            'status' => ScrapeRunStatus::Failed,
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
            'error_message' => $this->faker->sentence(),
        ]);
    }
}
