<?php

namespace Tests\Feature\Actions;

use App\Actions\Scraping\SyncPromotionsFromScraperAction;
use App\Actions\Scraping\UpsertPromotionFromDtoAction;
use App\DTOs\PromotionDTO;
use App\Enums\ScrapeRunStatus;
use App\Models\Merchant;
use App\Models\Promotion;
use App\Models\PromotionSnapshot;
use App\Models\PromotionSource;
use App\Models\ScrapeRun;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class SyncPromotionsFromScraperActionTest extends TestCase
{
    use LazilyRefreshDatabase;

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function dto(Wallet $wallet, array $overrides = []): PromotionDTO
    {
        return new PromotionDTO(
            walletSlug: $wallet->slug,
            merchantName: $overrides['merchantName'] ?? 'Carrefour',
            title: $overrides['title'] ?? 'Promo',
            discountPercentage: array_key_exists('discountPercentage', $overrides) ? $overrides['discountPercentage'] : 10.0,
            externalId: array_key_exists('externalId', $overrides) ? $overrides['externalId'] : 'ext-1',
            url: $overrides['url'] ?? 'https://example.com/promo',
            rawPayload: $overrides['rawPayload'] ?? ['raw' => true],
        );
    }

    public function test_creates_new_promotions(): void
    {
        $wallet = Wallet::factory()->create();
        $scrapeRun = ScrapeRun::factory()->for($wallet, 'scrapeable')->create();

        app(SyncPromotionsFromScraperAction::class)->handle(
            $wallet,
            $scrapeRun,
            [$this->dto($wallet, ['title' => 'Promo 1'])],
        );

        $this->assertSame(1, Promotion::count());

        $promotion = Promotion::sole();
        $this->assertSame(1, $promotion->version);
        $this->assertTrue($promotion->is_active);
        $this->assertSame(0, PromotionSnapshot::count());
        $this->assertSame(1, PromotionSource::count());

        $scrapeRun->refresh();
        $this->assertSame(ScrapeRunStatus::Success, $scrapeRun->status);
        $this->assertSame(1, $scrapeRun->promotions_total);
        $this->assertSame(1, $scrapeRun->promotions_created);
    }

    public function test_a_zero_discount_cashback_or_fixed_amount_is_stored_as_null(): void
    {
        $wallet = Wallet::factory()->create();
        $scrapeRun = ScrapeRun::factory()->for($wallet, 'scrapeable')->create();

        app(SyncPromotionsFromScraperAction::class)->handle(
            $wallet,
            $scrapeRun,
            [new PromotionDTO(
                walletSlug: $wallet->slug,
                merchantName: 'Almundo',
                title: '6 CSI en Almundo',
                discountPercentage: 0.0,
                cashbackPercentage: 0.0,
                fixedAmount: 0.0,
                externalId: 'ext-1',
            )],
        );

        $promotion = Promotion::sole();
        $this->assertNull($promotion->discount_percentage);
        $this->assertNull($promotion->cashback_percentage);
        $this->assertNull($promotion->fixed_amount);
    }

    public function test_an_exclamatory_banner_sentence_as_merchant_name_resolves_to_the_wallet_name(): void
    {
        $wallet = Wallet::factory()->create(['name' => 'Cuenta DNI']);
        $scrapeRun = ScrapeRun::factory()->for($wallet, 'scrapeable')->create();

        app(SyncPromotionsFromScraperAction::class)->handle(
            $wallet,
            $scrapeRun,
            [$this->dto($wallet, ['merchantName' => '¡Al super con Cuenta DNI!'])],
        );

        $promotion = Promotion::sole();
        $this->assertSame('Cuenta DNI', $promotion->merchant->name);
    }

    public function test_an_interrogative_banner_sentence_as_merchant_name_resolves_to_the_wallet_name(): void
    {
        $wallet = Wallet::factory()->create(['name' => 'Cuenta DNI']);
        $scrapeRun = ScrapeRun::factory()->for($wallet, 'scrapeable')->create();

        app(SyncPromotionsFromScraperAction::class)->handle(
            $wallet,
            $scrapeRun,
            [$this->dto($wallet, ['merchantName' => '¿Ya probaste tu beneficio de hoy?'])],
        );

        $promotion = Promotion::sole();
        $this->assertSame('Cuenta DNI', $promotion->merchant->name);
    }

    public function test_a_merchant_name_that_merely_contains_punctuation_is_left_alone(): void
    {
        $wallet = Wallet::factory()->create(['name' => 'Cuenta DNI']);
        $scrapeRun = ScrapeRun::factory()->for($wallet, 'scrapeable')->create();

        app(SyncPromotionsFromScraperAction::class)->handle(
            $wallet,
            $scrapeRun,
            [$this->dto($wallet, ['merchantName' => 'Plop! Hogar'])],
        );

        $promotion = Promotion::sole();
        $this->assertSame('Plop! Hogar', $promotion->merchant->name);
    }

    public function test_change_detected_writes_snapshot_of_old_state_and_bumps_version(): void
    {
        $wallet = Wallet::factory()->create();
        $sync = app(SyncPromotionsFromScraperAction::class);

        $run1 = ScrapeRun::factory()->for($wallet, 'scrapeable')->create();
        $sync->handle($wallet, $run1, [$this->dto($wallet, ['title' => 'Promo v1', 'discountPercentage' => 10.0])]);

        $run2 = ScrapeRun::factory()->for($wallet, 'scrapeable')->create();
        $sync->handle($wallet, $run2, [$this->dto($wallet, ['title' => 'Promo v2', 'discountPercentage' => 20.0])]);

        $promotion = Promotion::sole();
        $this->assertSame(2, $promotion->version);
        $this->assertSame('Promo v2', $promotion->title);

        $snapshot = PromotionSnapshot::sole();
        $this->assertSame(1, $snapshot->version);
        $this->assertSame('Promo v1', $snapshot->data['title']);

        $run2->refresh();
        $this->assertSame(1, $run2->promotions_updated);
    }

    public function test_no_change_writes_no_snapshot_but_still_records_a_source_row(): void
    {
        $wallet = Wallet::factory()->create();
        $sync = app(SyncPromotionsFromScraperAction::class);

        $run1 = ScrapeRun::factory()->for($wallet, 'scrapeable')->create();
        $sync->handle($wallet, $run1, [$this->dto($wallet)]);

        $run2 = ScrapeRun::factory()->for($wallet, 'scrapeable')->create();
        $sync->handle($wallet, $run2, [$this->dto($wallet)]);

        $promotion = Promotion::sole();
        $this->assertSame(1, $promotion->version);
        $this->assertSame(0, PromotionSnapshot::count());
        $this->assertSame(2, PromotionSource::count());

        $run2->refresh();
        $this->assertSame(1, $run2->promotions_unchanged);
        $this->assertSame(0, $run2->promotions_updated);
    }

    public function test_promotion_absent_from_a_run_is_deactivated_not_deleted(): void
    {
        $wallet = Wallet::factory()->create();
        $sync = app(SyncPromotionsFromScraperAction::class);

        $run1 = ScrapeRun::factory()->for($wallet, 'scrapeable')->create();
        $sync->handle($wallet, $run1, [$this->dto($wallet)]);

        $run2 = ScrapeRun::factory()->for($wallet, 'scrapeable')->create();
        $sync->handle($wallet, $run2, []);

        $promotion = Promotion::sole();
        $this->assertModelExists($promotion);
        $this->assertFalse($promotion->is_active);
        $this->assertNotNull($promotion->deactivated_at);

        $run2->refresh();
        $this->assertSame(1, $run2->promotions_deactivated);
    }

    public function test_reappearing_promotion_without_changes_is_reactivated_without_version_bump(): void
    {
        $wallet = Wallet::factory()->create();
        $sync = app(SyncPromotionsFromScraperAction::class);

        $sync->handle($wallet, ScrapeRun::factory()->for($wallet, 'scrapeable')->create(), [$this->dto($wallet)]);
        $sync->handle($wallet, ScrapeRun::factory()->for($wallet, 'scrapeable')->create(), []);
        $sync->handle($wallet, ScrapeRun::factory()->for($wallet, 'scrapeable')->create(), [$this->dto($wallet)]);

        $promotion = Promotion::sole();
        $this->assertTrue($promotion->is_active);
        $this->assertNull($promotion->deactivated_at);
        $this->assertSame(1, $promotion->version);
    }

    public function test_reappearing_promotion_with_changes_is_reactivated_with_version_bump(): void
    {
        $wallet = Wallet::factory()->create();
        $sync = app(SyncPromotionsFromScraperAction::class);

        $sync->handle($wallet, ScrapeRun::factory()->for($wallet, 'scrapeable')->create(), [$this->dto($wallet, ['discountPercentage' => 10.0])]);
        $sync->handle($wallet, ScrapeRun::factory()->for($wallet, 'scrapeable')->create(), []);
        $sync->handle($wallet, ScrapeRun::factory()->for($wallet, 'scrapeable')->create(), [$this->dto($wallet, ['discountPercentage' => 30.0])]);

        $promotion = Promotion::sole();
        $this->assertTrue($promotion->is_active);
        $this->assertSame(2, $promotion->version);
        $this->assertSame(1, PromotionSnapshot::count());
    }

    public function test_fallback_hash_without_external_id_matches_the_same_promotion_across_runs(): void
    {
        $wallet = Wallet::factory()->create();
        $sync = app(SyncPromotionsFromScraperAction::class);

        $sync->handle($wallet, ScrapeRun::factory()->for($wallet, 'scrapeable')->create(), [
            $this->dto($wallet, ['externalId' => null, 'title' => 'Promo sin id']),
        ]);
        $sync->handle($wallet, ScrapeRun::factory()->for($wallet, 'scrapeable')->create(), [
            $this->dto($wallet, ['externalId' => null, 'title' => 'Promo sin id']),
        ]);

        $this->assertSame(1, Promotion::count());
    }

    public function test_syncing_one_wallet_never_touches_another_wallets_promotions(): void
    {
        $walletA = Wallet::factory()->create();
        $walletB = Wallet::factory()->create();
        $sync = app(SyncPromotionsFromScraperAction::class);

        $shared = ['merchantName' => 'Carrefour', 'title' => 'Same title', 'externalId' => 'ext-1'];

        $sync->handle($walletA, ScrapeRun::factory()->for($walletA, 'scrapeable')->create(), [$this->dto($walletA, $shared)]);
        $sync->handle($walletB, ScrapeRun::factory()->for($walletB, 'scrapeable')->create(), [$this->dto($walletB, $shared)]);

        $this->assertSame(2, Promotion::count());

        $sync->handle($walletA, ScrapeRun::factory()->for($walletA, 'scrapeable')->create(), []);

        $promoA = Promotion::where('wallet_id', $walletA->id)->sole();
        $promoB = Promotion::where('wallet_id', $walletB->id)->sole();

        $this->assertFalse($promoA->is_active);
        $this->assertTrue($promoB->is_active);
    }

    public function test_one_failing_dto_does_not_abort_the_rest_of_the_batch(): void
    {
        $wallet = Wallet::factory()->create();
        $scrapeRun = ScrapeRun::factory()->for($wallet, 'scrapeable')->create();

        $mock = Mockery::mock(UpsertPromotionFromDtoAction::class);
        $mock->shouldReceive('handle')
            ->andReturnUsing(function (Wallet $wallet, PromotionDTO $dto) {
                if ($dto->title === 'boom') {
                    throw new RuntimeException('boom');
                }

                return [
                    'promotion' => Promotion::factory()->for($wallet)->create(),
                    'status' => 'created',
                ];
            });
        $this->app->instance(UpsertPromotionFromDtoAction::class, $mock);

        app(SyncPromotionsFromScraperAction::class)->handle($wallet, $scrapeRun, [
            $this->dto($wallet, ['title' => 'ok-1']),
            $this->dto($wallet, ['title' => 'boom']),
            $this->dto($wallet, ['title' => 'ok-2']),
        ]);

        $scrapeRun->refresh();
        $this->assertSame(3, $scrapeRun->promotions_total);
        $this->assertSame(2, $scrapeRun->promotions_created);
        $this->assertSame(1, $scrapeRun->promotions_failed);
        $this->assertSame(ScrapeRunStatus::Partial, $scrapeRun->status);
    }

    public function test_scrape_run_counters_reflect_every_outcome_in_one_run(): void
    {
        $wallet = Wallet::factory()->create();
        $sync = app(SyncPromotionsFromScraperAction::class);

        $sync->handle($wallet, ScrapeRun::factory()->for($wallet, 'scrapeable')->create(), [
            $this->dto($wallet, ['externalId' => 'stays-unchanged']),
            $this->dto($wallet, ['externalId' => 'will-change', 'discountPercentage' => 5.0]),
            $this->dto($wallet, ['externalId' => 'will-disappear']),
        ]);

        $run2 = ScrapeRun::factory()->for($wallet, 'scrapeable')->create();
        $sync->handle($wallet, $run2, [
            $this->dto($wallet, ['externalId' => 'stays-unchanged']),
            $this->dto($wallet, ['externalId' => 'will-change', 'discountPercentage' => 50.0]),
            $this->dto($wallet, ['externalId' => 'brand-new']),
        ]);

        $run2->refresh();
        $this->assertSame(3, $run2->promotions_total);
        $this->assertSame(1, $run2->promotions_created);
        $this->assertSame(1, $run2->promotions_updated);
        $this->assertSame(1, $run2->promotions_unchanged);
        $this->assertSame(1, $run2->promotions_deactivated);
        $this->assertSame(0, $run2->promotions_failed);
        $this->assertSame(ScrapeRunStatus::Success, $run2->status);
    }

    /**
     * The scenario `ModoScraper` exists for: a DTO whose `walletSlug` names
     * a *different*, already-known wallet ends up stored there instead of
     * under the source wallet — this is how a MODO promo confirmed
     * exclusive to one bank gets attributed directly to that bank.
     */
    public function test_a_dto_naming_a_different_known_wallet_is_stored_under_that_wallet(): void
    {
        $modo = Wallet::factory()->create(['slug' => 'modo']);
        $macro = Wallet::factory()->create(['slug' => 'macro']);
        $scrapeRun = ScrapeRun::factory()->for($modo, 'scrapeable')->create();

        app(SyncPromotionsFromScraperAction::class)->handle(
            $modo,
            $scrapeRun,
            [$this->dto($macro, ['title' => 'Alba la Pérgola'])],
        );

        $promotion = Promotion::sole();
        $this->assertSame($macro->id, $promotion->wallet_id);
        $this->assertTrue($promotion->lastScrapeRun->scrapeable->is($modo));
    }

    /**
     * The whole reason deactivation is now scoped by source: MODO
     * attributing a promo to Macro's wallet must never touch what Macro's
     * *own* scraper already put there, even when this run sends Macro's
     * wallet zero DTOs.
     */
    public function test_attributing_a_promotion_to_another_wallet_never_deactivates_that_wallets_own_promotions(): void
    {
        $modo = Wallet::factory()->create(['slug' => 'modo']);
        $macro = Wallet::factory()->create(['slug' => 'macro']);
        $sync = app(SyncPromotionsFromScraperAction::class);

        $sync->handle($macro, ScrapeRun::factory()->for($macro, 'scrapeable')->create(), [
            $this->dto($macro, ['title' => 'Native Macro promo', 'externalId' => 'macro-native-1']),
        ]);
        $macroNative = Promotion::where('wallet_id', $macro->id)->sole();

        $sync->handle($modo, ScrapeRun::factory()->for($modo, 'scrapeable')->create(), [
            $this->dto($macro, ['title' => 'Alba la Pérgola', 'externalId' => 'modo-ext-1']),
        ]);

        $this->assertSame(2, Promotion::where('wallet_id', $macro->id)->count());
        $this->assertTrue($macroNative->fresh()->is_active);

        // A later MODO run with no bank-exclusive promo left for Macro must
        // deactivate only the one it itself attributed, not Macro's own.
        $sync->handle($modo, ScrapeRun::factory()->for($modo, 'scrapeable')->create(), []);

        $this->assertTrue($macroNative->fresh()->is_active);
        $modoAttributed = Promotion::where('wallet_id', $macro->id)->where('external_id', 'modo-ext-1')->sole();
        $this->assertFalse($modoAttributed->fresh()->is_active);
    }

    /**
     * The other new source type, alongside `Wallet`: a supermarket scraper
     * (see `MerchantScraperInterface`) — every DTO it produces already
     * carries a real, resolvable `walletSlug` (from
     * `ResolveWalletFromBankNameAction`, run *inside* the scraper itself),
     * so the promotion lands under that wallet exactly like a wallet
     * scraper's own DTO would.
     */
    public function test_a_merchant_source_stores_its_dtos_under_their_own_resolved_wallet(): void
    {
        $carrefour = Merchant::factory()->create(['name' => 'Carrefour', 'slug' => 'carrefour']);
        $galicia = Wallet::factory()->create(['slug' => 'galicia']);
        $scrapeRun = ScrapeRun::factory()->for($carrefour, 'scrapeable')->create();

        app(SyncPromotionsFromScraperAction::class)->handle(
            $carrefour,
            $scrapeRun,
            [$this->dto($galicia, ['merchantName' => 'Carrefour', 'title' => '20% con Galicia'])],
        );

        $promotion = Promotion::sole();
        $this->assertSame($galicia->id, $promotion->wallet_id);
        $this->assertSame($carrefour->id, $promotion->merchant_id);
        $this->assertTrue($promotion->lastScrapeRun->scrapeable->is($carrefour));
    }

    /**
     * A merchant source has no wallet of its own to fall back to (unlike a
     * wallet source's "attribute it to myself" default) — an unresolvable
     * `walletSlug` here means the scraper's own wallet resolution has a bug,
     * so the DTO fails like any other rather than silently mis-attributing
     * the promotion.
     */
    public function test_a_merchant_source_with_an_unresolvable_wallet_slug_fails_that_dto_instead_of_guessing(): void
    {
        $carrefour = Merchant::factory()->create(['name' => 'Carrefour', 'slug' => 'carrefour']);
        $scrapeRun = ScrapeRun::factory()->for($carrefour, 'scrapeable')->create();

        app(SyncPromotionsFromScraperAction::class)->handle(
            $carrefour,
            $scrapeRun,
            [new PromotionDTO(
                walletSlug: 'no-such-wallet',
                merchantName: 'Carrefour',
                title: '20% con un banco inexistente',
                externalId: 'ext-1',
            )],
        );

        $this->assertSame(0, Promotion::count());
        $scrapeRun->refresh();
        $this->assertSame(1, $scrapeRun->promotions_failed);
        $this->assertSame(ScrapeRunStatus::Failed, $scrapeRun->status);
    }
}
