<?php

namespace Tests\Feature\Actions;

use App\Actions\Scraping\PurgeOldScrapeRunsAction;
use App\Models\Promotion;
use App\Models\PromotionSnapshot;
use App\Models\PromotionSource;
use App\Models\ScrapeRun;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class PurgeOldScrapeRunsActionTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_deletes_a_scrape_run_older_than_thirty_days(): void
    {
        $old = ScrapeRun::factory()->create(['created_at' => now()->subDays(31)]);

        $count = app(PurgeOldScrapeRunsAction::class)->handle();

        $this->assertSame(1, $count);
        $this->assertModelMissing($old);
    }

    public function test_keeps_a_scrape_run_within_the_thirty_day_window(): void
    {
        $recent = ScrapeRun::factory()->create(['created_at' => now()->subDays(29)]);

        $count = app(PurgeOldScrapeRunsAction::class)->handle();

        $this->assertSame(0, $count);
        $this->assertModelExists($recent);
    }

    public function test_thirty_days_old_exactly_is_not_yet_purged(): void
    {
        $exactlyThirty = ScrapeRun::factory()->create(['created_at' => now()->subDays(30)]);

        app(PurgeOldScrapeRunsAction::class)->handle();

        $this->assertModelExists($exactlyThirty);
    }

    /**
     * The biggest table this purge exists for: `promotion_sources` is a real
     * database ON DELETE CASCADE, not something this action does itself.
     */
    public function test_cascade_deletes_promotion_sources_of_a_purged_run(): void
    {
        $old = ScrapeRun::factory()->create(['created_at' => now()->subDays(31)]);
        $source = PromotionSource::factory()->for($old)->create();

        app(PurgeOldScrapeRunsAction::class)->handle();

        $this->assertModelMissing($source);
    }

    /**
     * `promotion_snapshots.scrape_run_id` is ON DELETE SET NULL, not
     * cascade — a promotion's version history must survive purging the run
     * that produced it.
     */
    public function test_nulls_the_scrape_run_link_on_a_promotion_snapshot_instead_of_deleting_it(): void
    {
        $old = ScrapeRun::factory()->create(['created_at' => now()->subDays(31)]);
        $snapshot = PromotionSnapshot::factory()->for($old)->create();

        app(PurgeOldScrapeRunsAction::class)->handle();

        $this->assertModelExists($snapshot);
        $this->assertNull($snapshot->fresh()->scrape_run_id);
    }

    /**
     * `promotions.last_scrape_run_id` is also ON DELETE SET NULL — the
     * promotion itself is never touched by purging old runs.
     */
    public function test_nulls_the_last_scrape_run_link_on_a_promotion_instead_of_deleting_it(): void
    {
        $old = ScrapeRun::factory()->create(['created_at' => now()->subDays(31)]);
        $promotion = Promotion::factory()->create(['last_scrape_run_id' => $old->id]);

        app(PurgeOldScrapeRunsAction::class)->handle();

        $this->assertModelExists($promotion);
        $this->assertNull($promotion->fresh()->last_scrape_run_id);
    }

    public function test_returns_the_number_of_runs_deleted(): void
    {
        ScrapeRun::factory()->count(3)->create(['created_at' => now()->subDays(45)]);
        ScrapeRun::factory()->create(['created_at' => now()->subDays(1)]);

        $count = app(PurgeOldScrapeRunsAction::class)->handle();

        $this->assertSame(3, $count);
    }
}
