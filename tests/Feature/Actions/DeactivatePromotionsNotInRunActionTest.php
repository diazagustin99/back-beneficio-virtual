<?php

namespace Tests\Feature\Actions;

use App\Actions\Scraping\DeactivatePromotionsNotInRunAction;
use App\Models\Promotion;
use App\Models\ScrapeRun;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

class DeactivatePromotionsNotInRunActionTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_deactivates_active_promotions_not_present_in_the_seen_set(): void
    {
        $wallet = Wallet::factory()->create();
        $run = ScrapeRun::factory()->for($wallet)->create();
        $seen = Promotion::factory()->for($wallet)->create(['last_scrape_run_id' => $run->id]);
        $unseen = Promotion::factory()->for($wallet)->create(['last_scrape_run_id' => $run->id]);

        $count = app(DeactivatePromotionsNotInRunAction::class)
            ->handle($wallet, $wallet, new Collection([$seen->id]));

        $this->assertSame(1, $count);
        $this->assertTrue($seen->fresh()->is_active);
        $this->assertFalse($unseen->fresh()->is_active);
        $this->assertNotNull($unseen->fresh()->deactivated_at);
    }

    public function test_already_inactive_promotions_are_not_recounted(): void
    {
        $wallet = Wallet::factory()->create();
        $run = ScrapeRun::factory()->for($wallet)->create();
        Promotion::factory()->for($wallet)->inactive()->create(['last_scrape_run_id' => $run->id]);

        $count = app(DeactivatePromotionsNotInRunAction::class)
            ->handle($wallet, $wallet, new Collection);

        $this->assertSame(0, $count);
    }

    public function test_does_not_deactivate_another_wallets_promotions(): void
    {
        $walletA = Wallet::factory()->create();
        $walletB = Wallet::factory()->create();
        $runB = ScrapeRun::factory()->for($walletB)->create();
        $promotionB = Promotion::factory()->for($walletB)->create(['last_scrape_run_id' => $runB->id]);

        app(DeactivatePromotionsNotInRunAction::class)->handle($walletA, $walletA, new Collection);

        $this->assertTrue($promotionB->fresh()->is_active);
    }

    /**
     * The whole reason `$sourceWallet` exists: MODO can attribute a
     * bank-exclusive promo directly to that bank's own wallet (see
     * `ModoScraper`), so a wallet can now hold promotions from more than one
     * scraper. Deactivating for one source must never touch what the
     * *other* source put there.
     */
    public function test_never_deactivates_a_promotion_sourced_from_a_different_scraper_under_the_same_wallet(): void
    {
        $macro = Wallet::factory()->create(['slug' => 'macro']);
        $modo = Wallet::factory()->create(['slug' => 'modo']);
        $macroRun = ScrapeRun::factory()->for($macro)->create();
        $modoRun = ScrapeRun::factory()->for($modo)->create();

        $macroNative = Promotion::factory()->for($macro)->create(['last_scrape_run_id' => $macroRun->id]);
        $modoAttributed = Promotion::factory()->for($macro)->create(['last_scrape_run_id' => $modoRun->id]);

        // Macro's own scrape run comes back with neither promotion "seen" —
        // only the one Macro itself sourced may be deactivated.
        $count = app(DeactivatePromotionsNotInRunAction::class)
            ->handle($macro, $macro, new Collection);

        $this->assertSame(1, $count);
        $this->assertFalse($macroNative->fresh()->is_active);
        $this->assertTrue($modoAttributed->fresh()->is_active);
    }
}
