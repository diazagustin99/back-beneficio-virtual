<?php

namespace Tests\Feature\Http;

use App\Models\Promotion;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class WelcomeCarouselControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_index_returns_one_promotion_per_active_wallet(): void
    {
        $walletA = Wallet::factory()->create();
        $walletB = Wallet::factory()->create();
        Promotion::factory()->for($walletA)->create(['discount_percentage' => 10]);
        Promotion::factory()->for($walletA)->create(['discount_percentage' => 50]);
        Promotion::factory()->for($walletB)->create(['cashback_percentage' => 20]);

        $response = $this->getJson('/api/v1/welcome-carousel')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $walletIds = collect($response->json('data'))->pluck('wallet.id')->sort()->values();
        $this->assertSame([$walletA->id, $walletB->id], $walletIds->sort()->values()->all());
    }

    public function test_index_picks_the_best_discount_for_each_wallet(): void
    {
        $wallet = Wallet::factory()->create();
        Promotion::factory()->for($wallet)->create(['title' => 'Worse deal', 'discount_percentage' => 10]);
        $best = Promotion::factory()->for($wallet)->create(['title' => 'Best deal', 'discount_percentage' => 80]);

        $this->getJson('/api/v1/welcome-carousel')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $best->id);
    }

    public function test_index_ignores_inactive_promotions_and_wallets(): void
    {
        $activeWallet = Wallet::factory()->create();
        $inactiveWallet = Wallet::factory()->create(['is_active' => false]);
        Promotion::factory()->for($activeWallet)->inactive()->create();
        Promotion::factory()->for($inactiveWallet)->create();

        $this->getJson('/api/v1/welcome-carousel')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }
}
