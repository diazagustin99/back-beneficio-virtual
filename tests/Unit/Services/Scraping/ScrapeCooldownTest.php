<?php

namespace Tests\Unit\Services\Scraping;

use App\Models\Merchant;
use App\Models\ScrapeRun;
use App\Models\Wallet;
use App\Services\Scraping\ScrapeCooldown;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class ScrapeCooldownTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_a_wallet_with_no_scrape_runs_yet_is_due(): void
    {
        $wallet = Wallet::factory()->create();

        $this->assertTrue(app(ScrapeCooldown::class)->isDue($wallet));
    }

    public function test_a_successful_run_less_than_five_days_old_is_not_due(): void
    {
        $wallet = Wallet::factory()->create();
        ScrapeRun::factory()->for($wallet, 'scrapeable')->success()->create(['finished_at' => now()->subDays(4)]);

        $this->assertFalse(app(ScrapeCooldown::class)->isDue($wallet));
    }

    public function test_a_successful_run_five_or_more_days_old_is_due(): void
    {
        $wallet = Wallet::factory()->create();
        ScrapeRun::factory()->for($wallet, 'scrapeable')->success()->create(['finished_at' => now()->subDays(5)]);

        $this->assertTrue(app(ScrapeCooldown::class)->isDue($wallet));
    }

    public function test_a_failed_run_from_earlier_today_is_not_due(): void
    {
        $wallet = Wallet::factory()->create();
        ScrapeRun::factory()->for($wallet, 'scrapeable')->failed()->create(['finished_at' => now()->subHours(2)]);

        $this->assertFalse(app(ScrapeCooldown::class)->isDue($wallet));
    }

    public function test_a_failed_run_one_or_more_days_old_is_due_for_a_retry(): void
    {
        $wallet = Wallet::factory()->create();
        ScrapeRun::factory()->for($wallet, 'scrapeable')->failed()->create(['finished_at' => now()->subDay()]);

        $this->assertTrue(app(ScrapeCooldown::class)->isDue($wallet));
    }

    /**
     * `Partial` did produce real data, but failed to process some of it —
     * treated the same as a full failure for scheduling purposes (daily
     * retry), not given the 5-day cooldown a clean run gets. See
     * plans/0026-scraping-cada-5-dias.md.
     */
    public function test_a_partial_run_from_earlier_today_is_not_due(): void
    {
        $wallet = Wallet::factory()->create();
        ScrapeRun::factory()->for($wallet, 'scrapeable')->partial()->create(['finished_at' => now()->subHours(2)]);

        $this->assertFalse(app(ScrapeCooldown::class)->isDue($wallet));
    }

    /**
     * Confirms `Partial` gets the 1-day retry, not the 5-day cooldown a
     * clean run gets: 4 days old is well past due for a `Failed`/`Partial`
     * run, but would still be within cooldown for a `Success` one (see
     * `test_a_successful_run_less_than_five_days_old_is_not_due`).
     */
    public function test_a_partial_run_is_treated_like_a_failure_not_a_success(): void
    {
        $wallet = Wallet::factory()->create();
        ScrapeRun::factory()->for($wallet, 'scrapeable')->partial()->create(['finished_at' => now()->subDays(4)]);

        $this->assertTrue(app(ScrapeCooldown::class)->isDue($wallet));
    }

    public function test_a_pending_run_is_never_due(): void
    {
        $wallet = Wallet::factory()->create();
        ScrapeRun::factory()->for($wallet, 'scrapeable')->create(['created_at' => now()->subDays(30)]);

        $this->assertFalse(app(ScrapeCooldown::class)->isDue($wallet));
    }

    public function test_a_running_run_is_never_due(): void
    {
        $wallet = Wallet::factory()->create();
        ScrapeRun::factory()->for($wallet, 'scrapeable')->running()->create(['created_at' => now()->subDays(30)]);

        $this->assertFalse(app(ScrapeCooldown::class)->isDue($wallet));
    }

    public function test_only_the_most_recent_run_is_considered(): void
    {
        $wallet = Wallet::factory()->create();
        ScrapeRun::factory()->for($wallet, 'scrapeable')->failed()->create(['created_at' => now()->subDays(10), 'finished_at' => now()->subDays(10)]);
        ScrapeRun::factory()->for($wallet, 'scrapeable')->success()->create(['created_at' => now(), 'finished_at' => now()]);

        $this->assertFalse(app(ScrapeCooldown::class)->isDue($wallet));
    }

    public function test_works_the_same_way_for_a_merchant_source(): void
    {
        $merchant = Merchant::factory()->create();
        ScrapeRun::factory()->for($merchant, 'scrapeable')->success()->create(['finished_at' => now()->subDays(5)]);

        $this->assertTrue(app(ScrapeCooldown::class)->isDue($merchant));
    }
}
