<?php

namespace Tests\Feature\Http;

use App\Models\Preference;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class PushSubscriptionControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_store_saves_the_browsers_push_subscription(): void
    {
        $preference = Preference::factory()->create();

        $this->postJson("/api/v1/preferences/{$preference->token}/push-subscriptions", [
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/abc123',
            'keys' => [
                'p256dh' => 'public-key-value',
                'auth' => 'auth-token-value',
            ],
        ])->assertStatus(201);

        $this->assertDatabaseHas('push_subscriptions', [
            'subscribable_id' => $preference->id,
            'subscribable_type' => Preference::class,
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/abc123',
        ]);
    }

    public function test_store_validates_the_subscription_shape(): void
    {
        $preference = Preference::factory()->create();

        $this->postJson("/api/v1/preferences/{$preference->token}/push-subscriptions", [
            'endpoint' => 'not-a-url',
        ])->assertStatus(422);
    }
}
