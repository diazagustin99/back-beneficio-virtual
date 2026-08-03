<?php

namespace Tests\Feature\Http;

use App\Models\Merchant;
use App\Models\Preference;
use App\Notifications\MerchantDiscountsTodayNotification;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class NotificationControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function notifyWithMerchant(Preference $preference): void
    {
        $merchant = Merchant::factory()->create();

        $preference->notify(new MerchantDiscountsTodayNotification(
            [['id' => $merchant->id, 'name' => $merchant->name, 'promotions_count' => 1]],
            now()->toDateString(),
        ));
    }

    public function test_index_lists_only_the_preferences_own_notifications(): void
    {
        $preference = Preference::factory()->create();
        $otherPreference = Preference::factory()->create();

        $this->notifyWithMerchant($preference);
        $this->notifyWithMerchant($preference);
        $this->notifyWithMerchant($otherPreference);

        $this->getJson("/api/v1/preferences/{$preference->token}/notifications")
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_index_returns_404_for_an_unknown_token(): void
    {
        $this->getJson('/api/v1/preferences/not-a-real-token/notifications')
            ->assertNotFound();
    }

    public function test_mark_read_sets_read_at(): void
    {
        $preference = Preference::factory()->create();
        $this->notifyWithMerchant($preference);
        $notification = $preference->notifications()->sole();

        $this->patchJson("/api/v1/preferences/{$preference->token}/notifications/{$notification->id}/read")
            ->assertOk()
            ->assertJsonPath('data.id', $notification->id);

        $this->assertNotNull($notification->fresh()->read_at);
    }

    public function test_mark_read_returns_404_for_another_preferences_notification(): void
    {
        $preference = Preference::factory()->create();
        $otherPreference = Preference::factory()->create();
        $this->notifyWithMerchant($otherPreference);
        $notification = $otherPreference->notifications()->sole();

        $this->patchJson("/api/v1/preferences/{$preference->token}/notifications/{$notification->id}/read")
            ->assertNotFound();
    }
}
