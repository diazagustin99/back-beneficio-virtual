<?php

namespace Tests\Feature\Http;

use App\Models\Merchant;
use App\Models\Promotion;
use App\Models\PromotionCategory;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class MerchantControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_index_lists_merchants_paginated(): void
    {
        Merchant::factory()->count(3)->create();

        $this->getJson('/api/v1/merchants')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonStructure(['data', 'message', 'current_page', 'total_pages', 'total_registros']);
    }

    public function test_index_with_discounts_only_includes_merchants_with_an_active_promotion(): void
    {
        $withActivePromo = Merchant::factory()->create();
        Promotion::factory()->create(['merchant_id' => $withActivePromo->id]);

        $withoutAnyPromo = Merchant::factory()->create();

        $withOnlyInactivePromo = Merchant::factory()->create();
        Promotion::factory()->inactive()->create(['merchant_id' => $withOnlyInactivePromo->id]);

        $this->getJson('/api/v1/merchants?with_discounts=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $withActivePromo->id);
    }

    public function test_index_with_discounts_includes_promotions_count_and_wallets(): void
    {
        $merchant = Merchant::factory()->create();
        $walletOne = Wallet::factory()->create();
        $walletTwo = Wallet::factory()->create();

        Promotion::factory()->create(['merchant_id' => $merchant->id, 'wallet_id' => $walletOne->id]);
        Promotion::factory()->create(['merchant_id' => $merchant->id, 'wallet_id' => $walletTwo->id]);

        $response = $this->getJson('/api/v1/merchants?with_discounts=1')->assertOk();

        $response->assertJsonPath('data.0.promotions_count', 2);
        $this->assertCount(2, $response->json('data.0.wallets'));
    }

    public function test_index_filters_by_promotion_category_id(): void
    {
        $category = PromotionCategory::factory()->create();
        $otherCategory = PromotionCategory::factory()->create();

        $matching = Merchant::factory()->create();
        Promotion::factory()->create(['merchant_id' => $matching->id, 'promotion_category_id' => $category->id]);

        $other = Merchant::factory()->create();
        Promotion::factory()->create(['merchant_id' => $other->id, 'promotion_category_id' => $otherCategory->id]);

        $this->getJson("/api/v1/merchants?promotion_category_id={$category->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $matching->id);
    }

    public function test_index_filters_by_merchant_ids(): void
    {
        $matching = Merchant::factory()->create();
        Merchant::factory()->create();
        Merchant::factory()->create();

        $this->getJson('/api/v1/merchants?'.http_build_query(['merchant_ids' => [$matching->id]]))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $matching->id);
    }

    public function test_index_with_logo_first_sorts_merchants_with_a_logo_before_those_without(): void
    {
        $withoutLogo = Merchant::factory()->create(['name' => 'Aaa Sin Logo', 'logo_url' => null]);
        $withLogo = Merchant::factory()->create(['name' => 'Zzz Con Logo', 'logo_url' => 'https://example.com/logo.png']);

        $response = $this->getJson('/api/v1/merchants?with_logo_first=1')->assertOk();

        $this->assertSame([$withLogo->id, $withoutLogo->id], $response->json('data.*.id'));
    }

    public function test_index_without_with_logo_first_keeps_alphabetical_order(): void
    {
        Merchant::factory()->create(['name' => 'Aaa Sin Logo', 'logo_url' => null]);
        Merchant::factory()->create(['name' => 'Zzz Con Logo', 'logo_url' => 'https://example.com/logo.png']);

        $response = $this->getJson('/api/v1/merchants')->assertOk();

        $this->assertSame(['Aaa Sin Logo', 'Zzz Con Logo'], $response->json('data.*.name'));
    }

    public function test_index_filters_by_search(): void
    {
        Merchant::factory()->create(['name' => 'Carrefour']);
        Merchant::factory()->create(['name' => 'Coto']);

        $this->getJson('/api/v1/merchants?search=Carre')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Carrefour');
    }

    public function test_show_returns_a_single_merchant(): void
    {
        $merchant = Merchant::factory()->create();

        $this->getJson("/api/v1/merchants/{$merchant->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $merchant->id);
    }

    public function test_show_returns_404_for_a_missing_merchant(): void
    {
        $this->getJson('/api/v1/merchants/999')
            ->assertNotFound();
    }
}
