<?php

namespace Tests\Feature\Actions;

use App\Actions\Scraping\DispatchDailySupermarketScrapesAction;
use App\Contracts\Scrapers\MerchantScraperInterface;
use App\DTOs\PromotionDTO;
use App\Enums\ScrapeRunStatus;
use App\Jobs\ScrapeMerchantJob;
use App\Models\Merchant;
use App\Models\ScrapeRun;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class DispatchDailySupermarketScrapesActionTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_dispatches_one_job_per_configured_merchant_scraper(): void
    {
        Queue::fake();
        config(['merchant_scrapers.merchants' => [
            'carrefour' => AlwaysCarrefourScraperStub::class,
        ]]);
        $carrefour = Merchant::factory()->create(['name' => 'Carrefour', 'slug' => 'carrefour']);

        $dispatched = app(DispatchDailySupermarketScrapesAction::class)->handle();

        $this->assertTrue($dispatched);
        Queue::assertPushed(ScrapeMerchantJob::class, 1);
        Queue::assertPushed(
            ScrapeMerchantJob::class,
            fn (ScrapeMerchantJob $job) => $job->merchant->is($carrefour),
        );
        $this->assertSame(1, ScrapeRun::where('scrapeable_type', 'merchant')->count());
    }

    public function test_creates_the_merchant_when_it_does_not_exist_yet(): void
    {
        Queue::fake();
        config(['merchant_scrapers.merchants' => [
            'carrefour' => AlwaysCarrefourScraperStub::class,
        ]]);

        app(DispatchDailySupermarketScrapesAction::class)->handle();

        $this->assertSame(1, Merchant::where('name', 'Carrefour')->count());
    }

    /**
     * The whole reason this ordering check exists: dispatching supermarket
     * scrapes while a wallet's own scrape hasn't finished today would let
     * `FilterDuplicateBankDiscountsAction` miss a bank's own promotion that
     * simply hasn't been created yet.
     */
    public function test_skips_dispatching_when_a_wallet_scrape_is_still_pending_today(): void
    {
        Queue::fake();
        config(['merchant_scrapers.merchants' => [
            'carrefour' => AlwaysCarrefourScraperStub::class,
        ]]);
        $wallet = Wallet::factory()->create();
        ScrapeRun::factory()->for($wallet, 'scrapeable')->create(['status' => ScrapeRunStatus::Pending]);

        $dispatched = app(DispatchDailySupermarketScrapesAction::class)->handle();

        $this->assertFalse($dispatched);
        Queue::assertNotPushed(ScrapeMerchantJob::class);
    }

    public function test_skips_dispatching_when_a_wallet_scrape_is_still_running_today(): void
    {
        Queue::fake();
        config(['merchant_scrapers.merchants' => [
            'carrefour' => AlwaysCarrefourScraperStub::class,
        ]]);
        $wallet = Wallet::factory()->create();
        ScrapeRun::factory()->running()->for($wallet, 'scrapeable')->create();

        $dispatched = app(DispatchDailySupermarketScrapesAction::class)->handle();

        $this->assertFalse($dispatched);
        Queue::assertNotPushed(ScrapeMerchantJob::class);
    }

    public function test_dispatches_when_every_wallet_scrape_today_already_finished(): void
    {
        Queue::fake();
        config(['merchant_scrapers.merchants' => [
            'carrefour' => AlwaysCarrefourScraperStub::class,
        ]]);
        $wallet = Wallet::factory()->create();
        ScrapeRun::factory()->success()->for($wallet, 'scrapeable')->create();
        ScrapeRun::factory()->failed()->for($wallet, 'scrapeable')->create();

        $dispatched = app(DispatchDailySupermarketScrapesAction::class)->handle();

        $this->assertTrue($dispatched);
        Queue::assertPushed(ScrapeMerchantJob::class, 1);
    }
}

class AlwaysCarrefourScraperStub implements MerchantScraperInterface
{
    public function merchantName(): string
    {
        return 'Carrefour';
    }

    /**
     * @return iterable<int, PromotionDTO>
     */
    public function scrape(): iterable
    {
        return [];
    }
}
