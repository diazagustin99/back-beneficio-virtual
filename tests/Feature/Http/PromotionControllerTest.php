<?php

namespace Tests\Feature\Http;

use App\Models\Promotion;
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

        $this->getJson('/api/v1/promotions?wallet=mercado_pago')
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
