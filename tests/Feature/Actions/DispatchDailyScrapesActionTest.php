<?php

namespace Tests\Feature\Actions;

use App\Actions\Scraping\DispatchDailyScrapesAction;
use App\Jobs\ScrapeWalletJob;
use App\Models\ScrapeRun;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class DispatchDailyScrapesActionTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_dispatches_one_job_per_active_wallet_with_a_registered_scraper(): void
    {
        Queue::fake();

        $active = collect(['mercado_pago', 'uala'])->map(fn (string $slug) => Wallet::factory()->create(['slug' => $slug]));
        Wallet::factory()->inactive()->create(['slug' => 'personal_pay']);

        app(DispatchDailyScrapesAction::class)->handle();

        Queue::assertPushed(ScrapeWalletJob::class, 2);
        $this->assertSame(2, ScrapeRun::count());

        foreach ($active as $wallet) {
            Queue::assertPushed(
                ScrapeWalletJob::class,
                fn (ScrapeWalletJob $job) => $job->wallet->is($wallet),
            );
        }
    }

    /**
     * The scenario `WalletScraperRegistry::has()` exists for: an
     * attribution-only wallet (e.g. a bank with no scraper of its own, only
     * ever receiving what `ModoScraper` confirms is exclusive to it) would
     * otherwise get a `ScrapeRun` dispatched every single day that's
     * guaranteed to fail with `UnregisteredWalletScraperException`.
     */
    public function test_never_dispatches_a_wallet_with_no_registered_scraper(): void
    {
        Queue::fake();

        Wallet::factory()->create(['slug' => 'bbva']);

        app(DispatchDailyScrapesAction::class)->handle();

        Queue::assertNotPushed(ScrapeWalletJob::class);
        $this->assertSame(0, ScrapeRun::count());
    }

    public function test_wallet_filter_restricts_the_dispatch_to_the_given_slugs(): void
    {
        Queue::fake();

        $target = Wallet::factory()->create(['slug' => 'mercado_pago']);
        Wallet::factory()->create(['slug' => 'uala']);

        app(DispatchDailyScrapesAction::class)->handle(walletSlugs: ['mercado_pago']);

        Queue::assertPushed(ScrapeWalletJob::class, 1);
        Queue::assertPushed(
            ScrapeWalletJob::class,
            fn (ScrapeWalletJob $job) => $job->wallet->is($target),
        );
    }

    public function test_manual_trigger_is_recorded_on_the_scrape_run(): void
    {
        Queue::fake();

        Wallet::factory()->create(['slug' => 'mercado_pago']);

        app(DispatchDailyScrapesAction::class)->handle(walletSlugs: ['mercado_pago'], triggeredBy: 'manual');

        $this->assertSame('manual', ScrapeRun::sole()->triggered_by);
    }
}
