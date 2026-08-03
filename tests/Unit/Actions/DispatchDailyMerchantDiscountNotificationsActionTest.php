<?php

namespace Tests\Unit\Actions;

use App\Actions\Notifications\DispatchDailyMerchantDiscountNotificationsAction;
use App\Enums\Weekday;
use App\Models\Merchant;
use App\Models\Preference;
use App\Models\Promotion;
use App\Notifications\MerchantDiscountsTodayNotification;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class DispatchDailyMerchantDiscountNotificationsActionTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function todayName(): string
    {
        $today = Carbon::now('America/Argentina/Buenos_Aires');

        return Weekday::cases()[$today->dayOfWeekIso - 1]->value;
    }

    /**
     * Any day other than today — used to prove the day filter actually excludes.
     */
    private function otherDayName(): string
    {
        $today = Carbon::now('America/Argentina/Buenos_Aires');

        return Weekday::cases()[$today->dayOfWeekIso % 7]->value;
    }

    public function test_notifies_preference_with_a_promotion_valid_today_in_a_followed_merchant(): void
    {
        $preference = Preference::factory()->wantsNotifications()->create();
        $merchant = Merchant::factory()->create();
        $preference->merchants()->attach($merchant);

        Promotion::factory()->create([
            'merchant_id' => $merchant->id,
            'valid_days' => [$this->todayName()],
        ]);

        (new DispatchDailyMerchantDiscountNotificationsAction)->handle();

        $this->assertDatabaseCount('notifications', 1);

        $notification = $preference->notifications()->sole();
        $this->assertSame(MerchantDiscountsTodayNotification::class, $notification->type);
        $this->assertSame($merchant->id, $notification->data['merchants'][0]['id']);
        $this->assertSame(1, $notification->data['merchants'][0]['promotions_count']);
    }

    public function test_does_not_notify_for_a_merchant_the_preference_does_not_follow(): void
    {
        $preference = Preference::factory()->wantsNotifications()->create();
        $followedMerchant = Merchant::factory()->create();
        $otherMerchant = Merchant::factory()->create();
        $preference->merchants()->attach($followedMerchant);

        Promotion::factory()->create([
            'merchant_id' => $otherMerchant->id,
            'valid_days' => [$this->todayName()],
        ]);

        (new DispatchDailyMerchantDiscountNotificationsAction)->handle();

        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_does_not_notify_for_a_promotion_valid_on_a_different_day(): void
    {
        $preference = Preference::factory()->wantsNotifications()->create();
        $merchant = Merchant::factory()->create();
        $preference->merchants()->attach($merchant);

        Promotion::factory()->create([
            'merchant_id' => $merchant->id,
            'valid_days' => [$this->otherDayName()],
        ]);

        (new DispatchDailyMerchantDiscountNotificationsAction)->handle();

        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_notifies_for_a_promotion_valid_every_day(): void
    {
        $preference = Preference::factory()->wantsNotifications()->create();
        $merchant = Merchant::factory()->create();
        $preference->merchants()->attach($merchant);

        Promotion::factory()->create([
            'merchant_id' => $merchant->id,
            'valid_days' => ['Todos los días'],
        ]);

        (new DispatchDailyMerchantDiscountNotificationsAction)->handle();

        $this->assertDatabaseCount('notifications', 1);
    }

    public function test_skips_preferences_who_opted_out_of_notifications(): void
    {
        $preference = Preference::factory()->create();
        $merchant = Merchant::factory()->create();
        $preference->merchants()->attach($merchant);

        Promotion::factory()->create([
            'merchant_id' => $merchant->id,
            'valid_days' => [$this->todayName()],
        ]);

        (new DispatchDailyMerchantDiscountNotificationsAction)->handle();

        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_skips_preferences_without_followed_merchants(): void
    {
        Preference::factory()->wantsNotifications()->create();
        $merchant = Merchant::factory()->create();

        Promotion::factory()->create([
            'merchant_id' => $merchant->id,
            'valid_days' => [$this->todayName()],
        ]);

        (new DispatchDailyMerchantDiscountNotificationsAction)->handle();

        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_does_not_duplicate_a_notification_already_sent_today(): void
    {
        $preference = Preference::factory()->wantsNotifications()->create();
        $merchant = Merchant::factory()->create();
        $preference->merchants()->attach($merchant);

        Promotion::factory()->create([
            'merchant_id' => $merchant->id,
            'valid_days' => [$this->todayName()],
        ]);

        $action = new DispatchDailyMerchantDiscountNotificationsAction;
        $action->handle();
        $action->handle();

        $this->assertDatabaseCount('notifications', 1);
    }
}
