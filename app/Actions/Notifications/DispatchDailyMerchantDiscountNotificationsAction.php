<?php

namespace App\Actions\Notifications;

use App\Enums\Weekday;
use App\Models\Preference;
use App\Models\Promotion;
use App\Notifications\MerchantDiscountsTodayNotification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class DispatchDailyMerchantDiscountNotificationsAction
{
    public function handle(): void
    {
        $today = Carbon::now('America/Argentina/Buenos_Aires');
        $todayName = Weekday::cases()[$today->dayOfWeekIso - 1]->value;

        $preferences = Preference::query()
            ->where('wants_notifications', true)
            ->with('merchants')
            ->get();

        foreach ($preferences as $preference) {
            $this->notifyPreference($preference, $today, $todayName);
        }
    }

    private function notifyPreference(Preference $preference, Carbon $today, string $todayName): void
    {
        $merchantIds = $preference->merchants->pluck('id');

        if ($merchantIds->isEmpty() || $this->alreadyNotifiedToday($preference, $today)) {
            return;
        }

        $merchants = $this->merchantsWithDiscountsToday($merchantIds, $today, $todayName);

        if ($merchants->isEmpty()) {
            return;
        }

        $preference->notify(new MerchantDiscountsTodayNotification($merchants->all(), $today->toDateString()));
    }

    /**
     * @param  Collection<int, int>  $merchantIds
     * @return Collection<int, array{id: int, name: string, promotions_count: int}>
     */
    private function merchantsWithDiscountsToday(Collection $merchantIds, Carbon $today, string $todayName): Collection
    {
        return Promotion::query()
            ->active()
            ->validOn($today)
            ->whereIn('merchant_id', $merchantIds)
            ->where(fn (Builder $query) => $query
                ->whereJsonContains('valid_days', 'Todos los días')
                ->orWhereJsonContains('valid_days', $todayName))
            ->with('merchant')
            ->get()
            ->groupBy('merchant_id')
            ->map(fn (Collection $promotions) => [
                'id' => $promotions->first()->merchant_id,
                'name' => $promotions->first()->merchant->name,
                'promotions_count' => $promotions->count(),
            ])
            ->values();
    }

    /**
     * `created_at` is stored in the app's default timezone (UTC), but `$today`
     * is in `America/Argentina/Buenos_Aires` — comparing them with a naive
     * `whereDate('created_at', $today)` breaks for roughly three hours every
     * night (21:00–23:59 ART, already past midnight UTC), where it would
     * silently never match and let a duplicate notification through. The
     * range is converted to UTC before binding so it lines up with what's
     * actually stored in the column.
     */
    private function alreadyNotifiedToday(Preference $preference, Carbon $today): bool
    {
        return $preference->notifications()
            ->where('type', MerchantDiscountsTodayNotification::class)
            ->whereBetween('created_at', [
                $today->copy()->startOfDay()->utc(),
                $today->copy()->endOfDay()->utc(),
            ])
            ->exists();
    }
}
