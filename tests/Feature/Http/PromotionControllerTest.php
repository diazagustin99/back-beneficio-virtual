<?php

namespace Tests\Feature\Http;

use App\Models\Merchant;
use App\Models\Promotion;
use App\Models\PromotionCategory;
use App\Models\PromotionLocation;
use App\Models\PromotionPaymentMethod;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class PromotionControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_index_lists_promotions_paginated(): void
    {
        Promotion::factory()->count(3)->create();

        $this->getJson('/api/v1/promotions')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonStructure(['data', 'message', 'current_page', 'total_pages', 'total_registros']);
    }

    public function test_index_filters_by_wallet_slug(): void
    {
        $walletA = Wallet::factory()->create(['slug' => 'mercado_pago']);
        $walletB = Wallet::factory()->create(['slug' => 'uala']);
        Promotion::factory()->for($walletA)->create();
        Promotion::factory()->for($walletB)->create();

        $this->getJson('/api/v1/promotions?'.http_build_query(['wallet' => ['mercado_pago']]))
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_index_filters_by_more_than_one_wallet_at_once(): void
    {
        $walletA = Wallet::factory()->create(['slug' => 'mercado_pago']);
        $walletB = Wallet::factory()->create(['slug' => 'uala']);
        $walletC = Wallet::factory()->create(['slug' => 'modo']);
        Promotion::factory()->for($walletA)->create();
        Promotion::factory()->for($walletB)->create();
        Promotion::factory()->for($walletC)->create();

        $this->getJson('/api/v1/promotions?'.http_build_query(['wallet' => ['mercado_pago', 'uala']]))
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_index_filters_by_more_than_one_category_at_once(): void
    {
        $categoryA = PromotionCategory::factory()->create();
        $categoryB = PromotionCategory::factory()->create();
        $categoryC = PromotionCategory::factory()->create();
        Promotion::factory()->create(['promotion_category_id' => $categoryA->id]);
        Promotion::factory()->create(['promotion_category_id' => $categoryB->id]);
        Promotion::factory()->create(['promotion_category_id' => $categoryC->id]);

        $this->getJson('/api/v1/promotions?'.http_build_query(['promotion_category_id' => [$categoryA->id, $categoryB->id]]))
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_index_filters_by_more_than_one_merchant_at_once(): void
    {
        $merchantA = Merchant::factory()->create();
        $merchantB = Merchant::factory()->create();
        $merchantC = Merchant::factory()->create();
        Promotion::factory()->create(['merchant_id' => $merchantA->id]);
        Promotion::factory()->create(['merchant_id' => $merchantB->id]);
        Promotion::factory()->create(['merchant_id' => $merchantC->id]);

        $this->getJson('/api/v1/promotions?'.http_build_query(['merchant_id' => [$merchantA->id, $merchantB->id]]))
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_index_filters_by_valid_days(): void
    {
        Promotion::factory()->create(['valid_days' => ['Lunes']]);
        Promotion::factory()->create(['valid_days' => ['Miércoles', 'Jueves']]);
        Promotion::factory()->create(['valid_days' => ['Sábado']]);

        $this->getJson('/api/v1/promotions?'.http_build_query(['valid_days' => ['Lunes', 'Jueves']]))
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_index_valid_days_filter_always_matches_a_promotion_valid_every_day(): void
    {
        Promotion::factory()->create(['valid_days' => ['Todos los días']]);
        Promotion::factory()->create(['valid_days' => ['Martes']]);

        $this->getJson('/api/v1/promotions?'.http_build_query(['valid_days' => ['Lunes']]))
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_index_filters_by_is_active(): void
    {
        Promotion::factory()->create();
        Promotion::factory()->inactive()->create();

        $this->getJson('/api/v1/promotions?is_active=1')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_index_filters_by_search(): void
    {
        Promotion::factory()->create(['title' => '20% en supermercados']);
        Promotion::factory()->create(['title' => 'Cuotas sin interés']);

        $this->getJson('/api/v1/promotions?search=supermercados')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_show_returns_nested_locations_and_payment_methods(): void
    {
        $promotion = Promotion::factory()->create();
        PromotionLocation::factory()->for($promotion)->create();
        PromotionPaymentMethod::factory()->for($promotion)->create(['name' => 'QR']);

        $this->getJson("/api/v1/promotions/{$promotion->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $promotion->id)
            ->assertJsonCount(1, 'data.locations')
            ->assertJsonPath('data.payment_methods.0.name', 'QR');
    }

    public function test_show_returns_404_for_a_missing_promotion(): void
    {
        $this->getJson('/api/v1/promotions/999')
            ->assertNotFound();
    }
}
