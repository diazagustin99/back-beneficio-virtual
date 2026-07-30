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

    public function test_dispatches_one_job_per_active_wallet(): void
    {
        Queue::fake();

        $active = Wallet::factory()->count(2)->create();
        Wallet::factory()->inactive()->create();

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
