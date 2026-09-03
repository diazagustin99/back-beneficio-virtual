<?php

namespace Tests\Unit\Actions;

use App\Actions\Scraping\UpsertPromotionFromDtoAction;
use App\DTOs\PromotionDTO;
use App\Models\Merchant;
use App\Models\ScrapeRun;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class UpsertPromotionFromDtoActionTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function dto(string $merchantName, ?string $category = null, ?string $iconUrl = null): PromotionDTO
    {
        return new PromotionDTO(
            walletSlug: 'macro',
            merchantName: $merchantName,
            title: 'Un descuento',
            merchantIconUrl: $iconUrl,
            category: $category,
            externalId: 'ext-1',
        );
    }

    /**
     * Both Macro's and MODO's own feeds confirmed live to use this exact
     * wording for "any adhered business in this category" instead of one
     * specific merchant — see plans/0023-macro-comercios-genericos.md.
     */
    public function test_a_generic_adhered_merchants_name_resolves_to_the_promotions_own_category_instead(): void
    {
        $wallet = Wallet::factory()->create(['name' => 'Macro']);
        $scrapeRun = ScrapeRun::factory()->for($wallet, 'scrapeable')->create();
        $action = app(UpsertPromotionFromDtoAction::class);

        $result = $action->handle($wallet, $this->dto('Comercios de gastronomía adheridos', 'Gastronomía'), $scrapeRun);

        $this->assertSame('Gastronomía', $result['promotion']->merchant->name);
        $this->assertSame(1, Merchant::count());
    }

    public function test_a_generic_adhered_merchants_name_without_a_category_falls_back_to_the_wallet_name(): void
    {
        $wallet = Wallet::factory()->create(['name' => 'Macro']);
        $scrapeRun = ScrapeRun::factory()->for($wallet, 'scrapeable')->create();
        $action = app(UpsertPromotionFromDtoAction::class);

        $result = $action->handle($wallet, $this->dto('Comercios adheridos'), $scrapeRun);

        $this->assertSame('Macro', $result['promotion']->merchant->name);
    }

    /**
     * Two different generic promos, same category, must converge on the
     * same real merchant instead of creating a near-duplicate each time —
     * the whole point of redirecting to the category.
     */
    public function test_two_generic_promos_with_the_same_category_share_the_same_merchant(): void
    {
        $wallet = Wallet::factory()->create();
        $scrapeRun = ScrapeRun::factory()->for($wallet, 'scrapeable')->create();
        $action = app(UpsertPromotionFromDtoAction::class);

        $first = $action->handle($wallet, $this->dto('Comercios de gastronomía adheridos', 'Gastronomía'), $scrapeRun);
        $second = $action->handle($wallet, $this->dto('Consultá los locales adheridos', 'Gastronomía'), $scrapeRun);

        $this->assertSame($first['promotion']->merchant_id, $second['promotion']->merchant_id);
        $this->assertSame(1, Merchant::count());
    }

    /**
     * The DTO's own icon belongs to whichever specific business the feed
     * happened to attach it to that day — passing it through would make
     * this category-wide merchant's logo flip-flop between unrelated
     * photos as different generic promos get processed.
     */
    public function test_a_generic_adhered_merchants_name_never_passes_through_its_own_icon(): void
    {
        $wallet = Wallet::factory()->create();
        $scrapeRun = ScrapeRun::factory()->for($wallet, 'scrapeable')->create();
        $action = app(UpsertPromotionFromDtoAction::class);

        $result = $action->handle(
            $wallet,
            $this->dto('Comercios de gastronomía adheridos', 'Gastronomía', 'https://example.com/some-random-store-logo.png'),
            $scrapeRun,
        );

        $this->assertNull($result['promotion']->merchant->logo_url);
    }

    /**
     * A real merchant name starting with a similar word ("Tienda") is left
     * untouched — confirmed live, no real business in either feed starts
     * with "Comercios" or contains "adherid[oa]".
     */
    public function test_a_real_merchant_name_is_not_treated_as_generic(): void
    {
        $wallet = Wallet::factory()->create();
        $scrapeRun = ScrapeRun::factory()->for($wallet, 'scrapeable')->create();
        $action = app(UpsertPromotionFromDtoAction::class);

        $result = $action->handle($wallet, $this->dto('Tienda Newsan', 'Electro y Tecnología'), $scrapeRun);

        $this->assertSame('Tienda Newsan', $result['promotion']->merchant->name);
    }
}
