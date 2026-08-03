<?php

namespace Tests\Feature\Http;

use App\Models\Merchant;
use App\Models\Preference;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class PreferenceControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_store_completes_onboarding_and_returns_the_token(): void
    {
        $merchant = Merchant::factory()->create();
        $wallet = Wallet::factory()->create();

        $response = $this->postJson('/api/v1/preferences', [
            'email' => 'nueva@example.com',
            'merchant_ids' => [$merchant->id],
            'wallet_ids' => [$wallet->id],
            'wants_notifications' => true,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.email', 'nueva@example.com')
            ->assertJsonPath('data.wants_notifications', true)
            ->assertJsonPath('data.email_taken', false)
            ->assertJsonCount(1, 'data.merchants')
            ->assertJsonCount(1, 'data.wallets');

        $this->assertNotEmpty($response->json('data.token'));
    }

    public function test_store_completes_onboarding_without_an_email(): void
    {
        $response = $this->postJson('/api/v1/preferences', []);

        $response->assertStatus(201)
            ->assertJsonPath('data.email', null)
            ->assertJsonPath('data.email_taken', false);

        $this->assertNotEmpty($response->json('data.token'));
        $this->assertSame(0, User::count());
    }

    public function test_store_still_creates_the_preference_when_the_email_is_already_taken(): void
    {
        User::factory()->create(['email' => 'ya@example.com']);

        $response = $this->postJson('/api/v1/preferences', ['email' => 'ya@example.com']);

        $response->assertStatus(201)
            ->assertJsonPath('data.email', null)
            ->assertJsonPath('data.email_taken', true);

        $this->assertNotEmpty($response->json('data.token'));
        $this->assertSame(1, User::count());
    }

    public function test_store_rejects_unknown_merchant_and_wallet_ids(): void
    {
        $this->postJson('/api/v1/preferences', [
            'merchant_ids' => [999],
            'wallet_ids' => [999],
        ])->assertStatus(422);
    }

    public function test_store_rejects_an_invalid_email_format(): void
    {
        $this->postJson('/api/v1/preferences', ['email' => 'not-an-email'])
            ->assertStatus(422);
    }

    public function test_show_returns_the_preference_for_an_unregistered_session(): void
    {
        $merchant = Merchant::factory()->create();
        $preference = Preference::factory()->create();
        $preference->merchants()->attach($merchant);

        $this->getJson("/api/v1/preferences/{$preference->token}")
            ->assertOk()
            ->assertJsonPath('data.email', null)
            ->assertJsonCount(1, 'data.merchants');
    }

    public function test_update_enables_notifications_for_a_session_without_an_email(): void
    {
        $preference = Preference::factory()->create(['wants_notifications' => false]);

        $this->patchJson("/api/v1/preferences/{$preference->token}", ['wants_notifications' => true])
            ->assertOk()
            ->assertJsonPath('data.wants_notifications', true);

        $this->assertTrue($preference->fresh()->wants_notifications);
    }

    public function test_update_enables_notifications_for_a_session_with_an_email(): void
    {
        $user = User::factory()->create();
        $preference = Preference::factory()->create(['user_id' => $user->id, 'wants_notifications' => false]);

        $this->patchJson("/api/v1/preferences/{$preference->token}", ['wants_notifications' => true])
            ->assertOk()
            ->assertJsonPath('data.email', $user->email)
            ->assertJsonPath('data.wants_notifications', true);
    }

    public function test_update_can_disable_notifications(): void
    {
        $preference = Preference::factory()->wantsNotifications()->create();

        $this->patchJson("/api/v1/preferences/{$preference->token}", ['wants_notifications' => false])
            ->assertOk()
            ->assertJsonPath('data.wants_notifications', false);
    }

    public function test_update_rejects_a_missing_wants_notifications_field(): void
    {
        $preference = Preference::factory()->create();

        $this->patchJson("/api/v1/preferences/{$preference->token}", [])
            ->assertStatus(422);
    }
}
