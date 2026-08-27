<?php

namespace Tests\Unit\Actions;

use App\Actions\Scraping\FilterDuplicateBankDiscountsAction;
use App\DTOs\PromotionDTO;
use App\Models\Merchant;
use App\Models\Promotion;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class FilterDuplicateBankDiscountsActionTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function dto(string $walletSlug, array $overrides = []): PromotionDTO
    {
        return new PromotionDTO(
            walletSlug: $walletSlug,
            merchantName: $overrides['merchantName'] ?? 'Carrefour',
            title: $overrides['title'] ?? '20% con Galicia',
            discountPercentage: array_key_exists('discountPercentage', $overrides) ? $overrides['discountPercentage'] : 20.0,
            validDays: $overrides['validDays'] ?? ['Martes'],
            externalId: $overrides['externalId'] ?? 'ext-1',
        );
    }

    public function test_filters_out_a_dto_matching_an_existing_active_promotion(): void
    {
        $merchant = Merchant::factory()->create();
        $wallet = Wallet::factory()->create(['slug' => 'galicia']);
        Promotion::factory()->for($merchant)->for($wallet)->create([
            'discount_percentage' => 20.0,
            'cashback_percentage' => null,
            'fixed_amount' => null,
            'installments' => null,
            'valid_days' => ['Martes'],
            'is_active' => true,
        ]);

        $result = iterator_to_array(app(FilterDuplicateBankDiscountsAction::class)->handle($merchant, [
            $this->dto('galicia'),
        ]));

        $this->assertSame([], $result);
    }

    public function test_keeps_a_dto_when_the_wallet_differs(): void
    {
        $merchant = Merchant::factory()->create();
        $galicia = Wallet::factory()->create(['slug' => 'galicia']);
        $macro = Wallet::factory()->create(['slug' => 'macro']);
        Promotion::factory()->for($merchant)->for($galicia)->create([
            'discount_percentage' => 20.0,
            'valid_days' => ['Martes'],
            'is_active' => true,
        ]);

        $result = iterator_to_array(app(FilterDuplicateBankDiscountsAction::class)->handle($merchant, [
            $this->dto('macro'),
        ]));

        $this->assertCount(1, $result);
    }

    public function test_keeps_a_dto_when_the_day_differs(): void
    {
        $merchant = Merchant::factory()->create();
        $wallet = Wallet::factory()->create(['slug' => 'galicia']);
        Promotion::factory()->for($merchant)->for($wallet)->create([
            'discount_percentage' => 20.0,
            'valid_days' => ['Lunes'],
            'is_active' => true,
        ]);

        $result = iterator_to_array(app(FilterDuplicateBankDiscountsAction::class)->handle($merchant, [
            $this->dto('galicia', ['validDays' => ['Martes']]),
        ]));

        $this->assertCount(1, $result);
    }

    public function test_keeps_a_dto_when_the_discount_differs(): void
    {
        $merchant = Merchant::factory()->create();
        $wallet = Wallet::factory()->create(['slug' => 'galicia']);
        Promotion::factory()->for($merchant)->for($wallet)->create([
            'discount_percentage' => 20.0,
            'valid_days' => ['Martes'],
            'is_active' => true,
        ]);

        $result = iterator_to_array(app(FilterDuplicateBankDiscountsAction::class)->handle($merchant, [
            $this->dto('galicia', ['discountPercentage' => 30.0]),
        ]));

        $this->assertCount(1, $result);
    }

    public function test_keeps_a_dto_when_the_existing_promotion_is_inactive(): void
    {
        $merchant = Merchant::factory()->create();
        $wallet = Wallet::factory()->create(['slug' => 'galicia']);
        Promotion::factory()->for($merchant)->for($wallet)->create([
            'discount_percentage' => 20.0,
            'valid_days' => ['Martes'],
            'is_active' => false,
        ]);

        $result = iterator_to_array(app(FilterDuplicateBankDiscountsAction::class)->handle($merchant, [
            $this->dto('galicia'),
        ]));

        $this->assertCount(1, $result);
    }

    /**
     * "Todos los días" (from a wallet's own scrape) matches any day a
     * supermarket names — same rule `ListPromotionsAction` already uses for
     * filtering.
     */
    public function test_an_existing_promotion_valid_every_day_matches_any_day_named(): void
    {
        $merchant = Merchant::factory()->create();
        $wallet = Wallet::factory()->create(['slug' => 'galicia']);
        Promotion::factory()->for($merchant)->for($wallet)->create([
            'discount_percentage' => 20.0,
            'valid_days' => ['Todos los días'],
            'is_active' => true,
        ]);

        $result = iterator_to_array(app(FilterDuplicateBankDiscountsAction::class)->handle($merchant, [
            $this->dto('galicia', ['validDays' => ['Martes']]),
        ]));

        $this->assertSame([], $result);
    }

    public function test_keeps_a_dto_whose_wallet_slug_does_not_resolve_to_any_wallet(): void
    {
        $merchant = Merchant::factory()->create();

        $result = iterator_to_array(app(FilterDuplicateBankDiscountsAction::class)->handle($merchant, [
            $this->dto('no-such-wallet'),
        ]));

        $this->assertCount(1, $result);
    }
}
