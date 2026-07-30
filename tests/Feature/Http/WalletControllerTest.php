<?php

namespace Tests\Feature\Http;

use App\Models\Wallet;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class WalletControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_index_lists_wallets(): void
    {
        Wallet::factory()->count(3)->create();

        $this->getJson('/api/v1/wallets')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonStructure(['data', 'message', 'current_page', 'total_pages', 'total_registros']);
    }

    public function test_index_filters_by_is_active(): void
    {
        Wallet::factory()->count(2)->create();
        Wallet::factory()->inactive()->create();

        $this->getJson('/api/v1/wallets?is_active=1')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_show_returns_a_single_wallet(): void
    {
        $wallet = Wallet::factory()->create();

        $this->getJson("/api/v1/wallets/{$wallet->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $wallet->id)
            ->assertJsonPath('data.slug', $wallet->slug);
    }

    public function test_show_returns_404_for_a_missing_wallet(): void
    {
        $this->getJson('/api/v1/wallets/999')
            ->assertNotFound();
    }
}
