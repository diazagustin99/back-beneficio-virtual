<?php

namespace Tests\Feature\Http;

use App\Models\Preference;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class PreferenceWalletControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_store_follows_a_new_wallet(): void
    {
        $preference = Preference::factory()->create();
        $wallet = Wallet::factory()->create();

        $this->postJson("/api/v1/preferences/{$preference->token}/wallets/{$wallet->id}")
            ->assertStatus(201)
            ->assertJsonCount(1, 'data.wallets')
            ->assertJsonPath('data.wallets.0.id', $wallet->id);
    }

    public function test_store_following_an_already_followed_wallet_is_idempotent(): void
    {
        $preference = Preference::factory()->create();
        $wallet = Wallet::factory()->create();
        $preference->wallets()->attach($wallet);

        $this->postJson("/api/v1/preferences/{$preference->token}/wallets/{$wallet->id}")
            ->assertStatus(201)
            ->assertJsonCount(1, 'data.wallets');
    }

    public function test_destroy_unfollows_a_wallet(): void
    {
        $preference = Preference::factory()->create();
        $wallet = Wallet::factory()->create();
        $preference->wallets()->attach($wallet);

        $this->deleteJson("/api/v1/preferences/{$preference->token}/wallets/{$wallet->id}")
            ->assertOk()
            ->assertJsonCount(0, 'data.wallets');
    }

    public function test_store_returns_404_for_an_unknown_token(): void
    {
        $wallet = Wallet::factory()->create();

        $this->postJson("/api/v1/preferences/not-a-real-token/wallets/{$wallet->id}")
            ->assertNotFound();
    }

    public function test_store_returns_404_for_an_unknown_wallet(): void
    {
        $preference = Preference::factory()->create();

        $this->postJson("/api/v1/preferences/{$preference->token}/wallets/999")
            ->assertNotFound();
    }
}
