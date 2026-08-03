<?php

namespace Tests\Feature\Http;

use App\Models\Merchant;
use App\Models\Preference;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class PreferenceMerchantControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_store_follows_a_new_merchant(): void
    {
        $preference = Preference::factory()->create();
        $merchant = Merchant::factory()->create();

        $this->postJson("/api/v1/preferences/{$preference->token}/merchants/{$merchant->id}")
            ->assertStatus(201)
            ->assertJsonCount(1, 'data.merchants')
            ->assertJsonPath('data.merchants.0.id', $merchant->id);
    }

    public function test_store_following_an_already_followed_merchant_is_idempotent(): void
    {
        $preference = Preference::factory()->create();
        $merchant = Merchant::factory()->create();
        $preference->merchants()->attach($merchant);

        $this->postJson("/api/v1/preferences/{$preference->token}/merchants/{$merchant->id}")
            ->assertStatus(201)
            ->assertJsonCount(1, 'data.merchants');
    }

    public function test_destroy_unfollows_a_merchant(): void
    {
        $preference = Preference::factory()->create();
        $merchant = Merchant::factory()->create();
        $preference->merchants()->attach($merchant);

        $this->deleteJson("/api/v1/preferences/{$preference->token}/merchants/{$merchant->id}")
            ->assertOk()
            ->assertJsonCount(0, 'data.merchants');
    }

    public function test_store_returns_404_for_an_unknown_token(): void
    {
        $merchant = Merchant::factory()->create();

        $this->postJson("/api/v1/preferences/not-a-real-token/merchants/{$merchant->id}")
            ->assertNotFound();
    }

    public function test_store_returns_404_for_an_unknown_merchant(): void
    {
        $preference = Preference::factory()->create();

        $this->postJson("/api/v1/preferences/{$preference->token}/merchants/999")
            ->assertNotFound();
    }
}
