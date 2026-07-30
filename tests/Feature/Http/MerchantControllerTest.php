<?php

namespace Tests\Feature\Http;

use App\Models\Merchant;
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
