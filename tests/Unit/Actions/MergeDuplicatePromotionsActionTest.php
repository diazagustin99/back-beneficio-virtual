<?php

namespace Tests\Unit\Actions;

use App\Actions\Promotions\MergeDuplicatePromotionsAction;
use App\Models\Merchant;
use App\Models\Promotion;
use App\Models\PromotionCategory;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class MergeDuplicatePromotionsActionTest extends TestCase
{
    use LazilyRefreshDatabase;

    /**
     * @return array<string, mixed>
     */
    private function baseOffer(Wallet $wallet, Merchant $merchant, ?PromotionCategory $category = null): array
    {
        return [
            'wallet_id' => $wallet->id,
            'merchant_id' => $merchant->id,
            'promotion_category_id' => $category?->id,
            'discount_percentage' => null,
            'cashback_percentage' => 20,
            'fixed_amount' => null,
            'reimbursement_cap' => 10000,
            'installments' => null,
            'description' => null,
            'terms' => null,
            'valid_days' => [],
        ];
    }

    /**
     * Regression test for a real incident: BNA registers Shell under more
     * than one internal brand record. Two of the resulting rows are the
     * literal same brand+offer (same url, same fetched terms) and should
     * collapse into one; a third shares the same cashback/cap numbers but is
     * a distinct campaign (its own, different terms text) and must stay
     * separate — same merchant, same numbers, still not the same offer.
     */
    public function test_merges_the_real_shell_incident(): void
    {
        $wallet = Wallet::factory()->create();
        $merchant = Merchant::factory()->create(['name' => 'Shell']);
        $category = PromotionCategory::factory()->create(['name' => 'Combustibles']);
        $offer = $this->baseOffer($wallet, $merchant, $category);

        $sameUrl = Promotion::factory()->create([
            ...$offer,
            'title' => 'Shell',
            'url' => 'https://semananacion.com.ar/semananacion/shell',
            'terms' => 'Bases y condiciones de Shell.',
            'starts_at' => '2026-03-06',
            'ends_at' => '2026-08-31',
        ]);

        $duplicateOfSameUrl = Promotion::factory()->create([
            ...$offer,
            'title' => 'SHELL',
            'url' => 'https://semananacion.com.ar/semananacion/shell',
            'terms' => 'Bases y condiciones de Shell.',
            'starts_at' => '2025-10-01',
            'ends_at' => '2026-12-31',
        ]);

        $distinctCampaign = Promotion::factory()->create([
            ...$offer,
            'title' => 'Combustible',
            'url' => 'https://semananacion.com.ar/semananacion/combustibles-sn//',
            'terms' => 'Bases y condiciones de la campaña combustibles-sn, distinta de la de Shell.',
            'starts_at' => '2026-03-01',
            'ends_at' => '2026-12-31',
        ]);

        $merges = app(MergeDuplicatePromotionsAction::class)->handle();

        $this->assertCount(1, $merges);
        $this->assertSame([$duplicateOfSameUrl->id], $merges[0]['merged_ids']);
        $this->assertSame(2, Promotion::query()->count());

        $survivor = $sameUrl->fresh();
        $this->assertSame('Bases y condiciones de Shell.', $survivor->terms);
        // Widened to the union of both matching rows' ranges.
        $this->assertSame('2025-10-01', $survivor->starts_at->toDateString());
        $this->assertSame('2026-12-31', $survivor->ends_at->toDateString());

        $this->assertModelMissing($duplicateOfSameUrl);
        $this->assertModelExists($distinctCampaign);
    }

    /**
     * Regression test for a real incident found live: the first version of
     * this action matched only on the numeric fields (discount/cashback/
     * cap/installments) plus category and overlapping dates. MODO runs the
     * same "cuotas en Frávega" template once per issuing bank — every
     * numeric field is `null` on all of them (no % to extract from the
     * title) and their dates overlap, but each `description` names a
     * different, mutually exclusive bank. Matching on the numbers alone
     * merged three genuinely different offers into one; they must stay apart.
     */
    public function test_never_merges_different_bank_specific_offers_that_share_no_numeric_terms(): void
    {
        $wallet = Wallet::factory()->create();
        $merchant = Merchant::factory()->create(['name' => 'Frávega']);
        $category = PromotionCategory::factory()->create(['name' => 'Electro y Tecnología']);

        $common = [
            'wallet_id' => $wallet->id,
            'merchant_id' => $merchant->id,
            'promotion_category_id' => $category->id,
            'discount_percentage' => null,
            'cashback_percentage' => null,
            'fixed_amount' => null,
            'reimbursement_cap' => null,
            'installments' => null,
            'terms' => null,
            'valid_days' => [],
            'starts_at' => '2026-01-01',
            'ends_at' => '2026-09-01',
        ];

        Promotion::factory()->create([
            ...$common,
            'description' => 'Realizando una compra con tarjetas del Banco Macro a través de MODO.',
        ]);

        Promotion::factory()->create([
            ...$common,
            'description' => 'Realizando una compra con tarjetas de Cabal Credicoop a través de MODO.',
        ]);

        Promotion::factory()->create([
            ...$common,
            'description' => 'Realizando una compra con tarjetas de Ciudad o Buepp a través de MODO.',
        ]);

        $merges = app(MergeDuplicatePromotionsAction::class)->handle();

        $this->assertSame([], $merges);
        $this->assertSame(3, Promotion::query()->count());
    }

    public function test_does_not_merge_promotions_with_different_amounts(): void
    {
        $wallet = Wallet::factory()->create();
        $merchant = Merchant::factory()->create();
        $offer = $this->baseOffer($wallet, $merchant);

        Promotion::factory()->create([...$offer, 'cashback_percentage' => 20, 'starts_at' => '2026-01-01', 'ends_at' => '2026-12-31']);
        Promotion::factory()->create([...$offer, 'cashback_percentage' => 30, 'starts_at' => '2026-01-01', 'ends_at' => '2026-12-31']);

        $merges = app(MergeDuplicatePromotionsAction::class)->handle();

        $this->assertSame([], $merges);
        $this->assertSame(2, Promotion::query()->count());
    }

    public function test_does_not_merge_promotions_from_different_merchants(): void
    {
        $wallet = Wallet::factory()->create();

        Promotion::factory()->create([
            ...$this->baseOffer($wallet, Merchant::factory()->create()),
            'starts_at' => '2026-01-01',
            'ends_at' => '2026-12-31',
        ]);

        Promotion::factory()->create([
            ...$this->baseOffer($wallet, Merchant::factory()->create()),
            'starts_at' => '2026-01-01',
            'ends_at' => '2026-12-31',
        ]);

        $merges = app(MergeDuplicatePromotionsAction::class)->handle();

        $this->assertSame([], $merges);
        $this->assertSame(2, Promotion::query()->count());
    }

    public function test_does_not_merge_the_same_offer_in_different_categories(): void
    {
        $wallet = Wallet::factory()->create();
        $merchant = Merchant::factory()->create();

        Promotion::factory()->create([
            ...$this->baseOffer($wallet, $merchant, PromotionCategory::factory()->create()),
            'starts_at' => '2026-01-01',
            'ends_at' => '2026-12-31',
        ]);

        Promotion::factory()->create([
            ...$this->baseOffer($wallet, $merchant, PromotionCategory::factory()->create()),
            'starts_at' => '2026-01-01',
            'ends_at' => '2026-12-31',
        ]);

        $merges = app(MergeDuplicatePromotionsAction::class)->handle();

        $this->assertSame([], $merges);
        $this->assertSame(2, Promotion::query()->count());
    }

    public function test_does_not_merge_the_same_offer_with_different_valid_days(): void
    {
        $wallet = Wallet::factory()->create();
        $merchant = Merchant::factory()->create();
        $offer = $this->baseOffer($wallet, $merchant);

        Promotion::factory()->create([...$offer, 'valid_days' => ['Viernes'], 'starts_at' => '2026-01-01', 'ends_at' => '2026-12-31']);
        Promotion::factory()->create([...$offer, 'valid_days' => ['Lunes', 'Martes'], 'starts_at' => '2026-01-01', 'ends_at' => '2026-12-31']);

        $merges = app(MergeDuplicatePromotionsAction::class)->handle();

        $this->assertSame([], $merges);
        $this->assertSame(2, Promotion::query()->count());
    }

    public function test_does_not_merge_the_same_offer_on_non_overlapping_dates(): void
    {
        $wallet = Wallet::factory()->create();
        $merchant = Merchant::factory()->create();
        $offer = $this->baseOffer($wallet, $merchant);

        Promotion::factory()->create([...$offer, 'starts_at' => '2025-01-01', 'ends_at' => '2025-06-30']);
        Promotion::factory()->create([...$offer, 'starts_at' => '2026-01-01', 'ends_at' => '2026-06-30']);

        $merges = app(MergeDuplicatePromotionsAction::class)->handle();

        $this->assertSame([], $merges);
        $this->assertSame(2, Promotion::query()->count());
    }

    public function test_unions_payment_methods_from_the_merged_promotions(): void
    {
        $wallet = Wallet::factory()->create();
        $merchant = Merchant::factory()->create();
        $offer = $this->baseOffer($wallet, $merchant);

        $survivor = Promotion::factory()->create([...$offer, 'starts_at' => '2026-01-01', 'ends_at' => '2026-12-31']);
        $survivor->paymentMethods()->create(['name' => 'Visa']);

        $loser = Promotion::factory()->create([...$offer, 'starts_at' => '2026-01-01', 'ends_at' => '2026-12-31']);
        $loser->paymentMethods()->create(['name' => 'Mastercard']);

        app(MergeDuplicatePromotionsAction::class)->handle();

        // hasMany() carries no explicit ordering, so only the *set* of
        // payment methods is guaranteed, not the row order they come back in.
        $this->assertEqualsCanonicalizing(
            ['Visa', 'Mastercard'],
            $survivor->fresh()->paymentMethods->pluck('name')->all(),
        );
    }

    public function test_prefers_a_real_url_over_a_broken_placeholder_as_the_survivor(): void
    {
        $wallet = Wallet::factory()->create();
        $merchant = Merchant::factory()->create();
        $offer = $this->baseOffer($wallet, $merchant);

        $broken = Promotion::factory()->create([...$offer, 'url' => '###', 'starts_at' => '2026-01-01', 'ends_at' => '2026-12-31']);
        $usable = Promotion::factory()->create([...$offer, 'url' => 'https://example.com/promo', 'starts_at' => '2026-01-01', 'ends_at' => '2026-12-31']);

        $merges = app(MergeDuplicatePromotionsAction::class)->handle();

        $this->assertSame($usable->id, $merges[0]['survivor_id']);
        $this->assertModelMissing($broken);
        $this->assertModelExists($usable);
    }

    public function test_running_it_twice_is_a_no_op_the_second_time(): void
    {
        $wallet = Wallet::factory()->create();
        $merchant = Merchant::factory()->create();
        $offer = $this->baseOffer($wallet, $merchant);

        Promotion::factory()->count(2)->create([...$offer, 'starts_at' => '2026-01-01', 'ends_at' => '2026-12-31']);

        $action = app(MergeDuplicatePromotionsAction::class);
        $first = $action->handle();
        $second = $action->handle();

        $this->assertCount(1, $first);
        $this->assertSame([], $second);
        $this->assertSame(1, Promotion::query()->count());
    }
}
