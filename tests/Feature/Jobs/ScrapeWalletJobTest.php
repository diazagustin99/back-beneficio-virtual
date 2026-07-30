<?php

namespace Tests\Feature\Jobs;

use App\Actions\Scraping\SyncPromotionsFromScraperAction;
use App\DTOs\PromotionDTO;
use App\Enums\ScrapeRunStatus;
use App\Exceptions\Scraping\UnregisteredWalletScraperException;
use App\Jobs\ScrapeWalletJob;
use App\Models\Promotion;
use App\Models\ScrapeRun;
use App\Models\Wallet;
use App\Services\Scraping\WalletScraperRegistry;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Mockery;
use RuntimeException;
use Tests\Support\FakeWalletScraper;
use Tests\TestCase;

class ScrapeWalletJobTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_successful_scrape_marks_the_run_as_successful_and_persists_promotions(): void
    {
        $wallet = Wallet::factory()->create(['slug' => 'mercado_pago']);
        $scrapeRun = ScrapeRun::factory()->for($wallet)->create();

        $dto = new PromotionDTO(
            walletSlug: 'mercado_pago',
            merchantName: 'Carrefour',
            title: 'Promo',
            externalId: 'ext-1',
        );

        $registry = Mockery::mock(WalletScraperRegistry::class);
        $registry->shouldReceive('for')->once()->andReturn(new FakeWalletScraper('mercado_pago', [$dto]));

        $job = new ScrapeWalletJob($wallet, $scrapeRun);
        $job->handle($registry, app(SyncPromotionsFromScraperAction::class));

        $scrapeRun->refresh();
        $this->assertSame(ScrapeRunStatus::Success, $scrapeRun->status);
        $this->assertNotNull($scrapeRun->started_at);
        $this->assertSame(1, Promotion::count());
    }

    public function test_a_scraper_failure_marks_the_run_as_failed_once_the_queue_worker_calls_failed(): void
    {
        $wallet = Wallet::factory()->create(['slug' => 'mercado_pago']);
        $scrapeRun = ScrapeRun::factory()->for($wallet)->create();

        $registry = Mockery::mock(WalletScraperRegistry::class);
        $registry->shouldReceive('for')->once()->andReturn(
            new FakeWalletScraper('mercado_pago', [], new RuntimeException('site is down')),
        );

        $job = new ScrapeWalletJob($wallet, $scrapeRun);

        try {
            $job->handle($registry, app(SyncPromotionsFromScraperAction::class));
            $this->fail('Expected the scraper failure to propagate, as it would to a real queue worker.');
        } catch (RuntimeException $e) {
            // A real queue worker calls failed() when handle() throws and tries are exhausted.
            $job->failed($e);
        }

        $scrapeRun->refresh();
        $this->assertSame(ScrapeRunStatus::Failed, $scrapeRun->status);
        $this->assertSame('site is down', $scrapeRun->error_message);
    }

    public function test_an_unregistered_wallet_scraper_marks_the_run_as_failed_without_throwing(): void
    {
        $wallet = Wallet::factory()->create(['slug' => 'mercado_pago']);
        $scrapeRun = ScrapeRun::factory()->for($wallet)->create();

        $registry = Mockery::mock(WalletScraperRegistry::class);
        $registry->shouldReceive('for')->once()->andThrow(
            UnregisteredWalletScraperException::forWallet($wallet),
        );

        $job = new ScrapeWalletJob($wallet, $scrapeRun);
        $job->handle($registry, app(SyncPromotionsFromScraperAction::class));

        $scrapeRun->refresh();
        $this->assertSame(ScrapeRunStatus::Failed, $scrapeRun->status);
        $this->assertStringContainsString('No scraper is registered', $scrapeRun->error_message);
    }
}
