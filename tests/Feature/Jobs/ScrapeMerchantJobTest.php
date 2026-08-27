<?php

namespace Tests\Feature\Jobs;

use App\Actions\Scraping\FilterDuplicateBankDiscountsAction;
use App\Actions\Scraping\SyncPromotionsFromScraperAction;
use App\DTOs\PromotionDTO;
use App\Enums\ScrapeRunStatus;
use App\Exceptions\Scraping\UnregisteredMerchantScraperException;
use App\Jobs\ScrapeMerchantJob;
use App\Models\Merchant;
use App\Models\Promotion;
use App\Models\ScrapeRun;
use App\Models\Wallet;
use App\Services\Scraping\MerchantScraperRegistry;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Mockery;
use RuntimeException;
use Tests\Support\FakeMerchantScraper;
use Tests\TestCase;

class ScrapeMerchantJobTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_successful_scrape_marks_the_run_as_successful_and_persists_promotions(): void
    {
        $merchant = Merchant::factory()->create(['name' => 'Carrefour', 'slug' => 'carrefour']);
        Wallet::factory()->create(['slug' => 'galicia']);
        $scrapeRun = ScrapeRun::factory()->for($merchant, 'scrapeable')->create();

        $dto = new PromotionDTO(
            walletSlug: 'galicia',
            merchantName: 'Carrefour',
            title: '20% con Galicia',
            externalId: 'ext-1',
        );

        $registry = Mockery::mock(MerchantScraperRegistry::class);
        $registry->shouldReceive('for')->once()->andReturn(new FakeMerchantScraper('Carrefour', [$dto]));

        $job = new ScrapeMerchantJob($merchant, $scrapeRun);
        $job->handle($registry, app(FilterDuplicateBankDiscountsAction::class), app(SyncPromotionsFromScraperAction::class));

        $scrapeRun->refresh();
        $this->assertSame(ScrapeRunStatus::Success, $scrapeRun->status);
        $this->assertNotNull($scrapeRun->started_at);
        $this->assertSame(1, Promotion::count());
    }

    public function test_a_scraper_failure_marks_the_run_as_failed_once_the_queue_worker_calls_failed(): void
    {
        $merchant = Merchant::factory()->create(['name' => 'Carrefour', 'slug' => 'carrefour']);
        $scrapeRun = ScrapeRun::factory()->for($merchant, 'scrapeable')->create();

        $registry = Mockery::mock(MerchantScraperRegistry::class);
        $registry->shouldReceive('for')->once()->andReturn(
            new FakeMerchantScraper('Carrefour', [], new RuntimeException('site is down')),
        );

        $job = new ScrapeMerchantJob($merchant, $scrapeRun);

        try {
            $job->handle($registry, app(FilterDuplicateBankDiscountsAction::class), app(SyncPromotionsFromScraperAction::class));
            $this->fail('Expected the scraper failure to propagate, as it would to a real queue worker.');
        } catch (RuntimeException $e) {
            // A real queue worker calls failed() when handle() throws and tries are exhausted.
            $job->failed($e);
        }

        $scrapeRun->refresh();
        $this->assertSame(ScrapeRunStatus::Failed, $scrapeRun->status);
        $this->assertSame('site is down', $scrapeRun->error_message);
    }

    public function test_an_unregistered_merchant_scraper_marks_the_run_as_failed_without_throwing(): void
    {
        $merchant = Merchant::factory()->create(['name' => 'Carrefour', 'slug' => 'carrefour']);
        $scrapeRun = ScrapeRun::factory()->for($merchant, 'scrapeable')->create();

        $registry = Mockery::mock(MerchantScraperRegistry::class);
        $registry->shouldReceive('for')->once()->andThrow(
            UnregisteredMerchantScraperException::forMerchant($merchant),
        );

        $job = new ScrapeMerchantJob($merchant, $scrapeRun);
        $job->handle($registry, app(FilterDuplicateBankDiscountsAction::class), app(SyncPromotionsFromScraperAction::class));

        $scrapeRun->refresh();
        $this->assertSame(ScrapeRunStatus::Failed, $scrapeRun->status);
        $this->assertStringContainsString('No scraper is registered', $scrapeRun->error_message);
    }
}
